<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FreightInquiry;
use App\Models\MaritimeDeparture;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FreightInquiryController extends Controller
{
    public function index()
    {
        return view('admin.maritime.inquiries', [
            'inquiries' => FreightInquiry::with(['user', 'departure.company'])->latest('id')->paginate(30),
            'departures' => MaritimeDeparture::with(['originHub', 'destinationHub', 'company'])->upcoming()
                ->where('is_demo', false)->whereHas('originHub', fn ($q) => $q->where('country', 'CN')->where('is_demo', false))
                ->whereHas('destinationHub', fn ($q) => $q->whereNotIn('country', ['CN', 'CM'])->where('is_demo', false))->get(),
        ]);
    }

    public function quote(Request $request, int $id)
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $data = $request->validate([
            'maritime_departure_id' => 'required|integer|exists:maritime_departures,id',
            'freight_amount' => 'required|numeric|min:0.01|max:1000000000000',
            'expires_at' => 'required|date|after:now',
        ]);
        DB::transaction(function () use ($data, $id) {
            // Même ordre de verrouillage que la réservation : départ puis devis.
            $departure = MaritimeDeparture::with(['originHub', 'destinationHub', 'company'])->lockForUpdate()->findOrFail($data['maritime_departure_id']);
            $inquiry = FreightInquiry::lockForUpdate()->findOrFail($id);
            abort_unless(in_array($inquiry->status, ['pending', 'quoted']), 422, 'Un devis accepté ou réservé ne peut plus être modifié.');
            abort_unless(! $departure->is_demo && $departure->isBookable()
                && ! $departure->originHub->is_demo && ! $departure->destinationHub->is_demo
                && $departure->originHub->country === 'CN'
                && $departure->destinationHub->country === $inquiry->destination_country
                && ($departure->freight_rate_snapshot['transport_mode'] ?? 'sea') === 'sea'
                && ($departure->freight_rate_snapshot['cargo_category'] ?? 'general') === $inquiry->cargo_category
                && (float) $departure->remaining_kg >= $inquiry->kg,
                422, 'Choisissez un départ maritime actif correspondant au pays, à la catégorie et au poids demandés.');
            $partner = $departure->partnerFreightFor($inquiry->kg);
            abort_unless((float) $data['freight_amount'] >= $partner, 422, 'Le prix client doit couvrir le coût partenaire.');
            $inquiry->update($data + ['partner_amount' => $partner, 'currency' => $departure->currency, 'status' => 'quoted', 'departure_snapshot' => $departure->quoteIdentity()]);
            $conversation = Conversation::findOrFail($inquiry->conversation_id);
            $senderId = $conversation->user1_id === $inquiry->user_id ? $conversation->user2_id : $conversation->user1_id;
            $message = Message::create(['conversation_id' => $inquiry->conversation_id, 'sender_id' => $senderId,
                'is_system' => true, 'is_read' => false,
                'message' => "Devis maritime #{$inquiry->id} : {$inquiry->freight_amount} {$inquiry->currency} pour {$inquiry->kg} kg (fret hors assurance facultative). Valable jusqu’au {$inquiry->expires_at->toIso8601String()}. Acceptez le devis dans Diaspo Exchange avant le paiement."]);
            Conversation::whereKey($inquiry->conversation_id)->update(['last_message_at' => now()]);
            \App\Services\FreightInquiryMessaging::notify($message, $inquiry->user);
        });

        return back()->with('success', 'Devis transmis au client.');
    }
}
