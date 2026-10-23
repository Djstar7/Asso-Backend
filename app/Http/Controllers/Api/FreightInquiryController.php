<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\FreightInquiry;
use App\Models\Message;
use App\Models\User;
use App\Services\SupportService;
use App\Support\CountryCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FreightInquiryController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['success' => true, 'data' => FreightInquiry::with('departure')
            ->where('user_id', $request->user()->id)->latest('id')->limit(100)->get()->map->toApi()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'destination_country' => ['required', Rule::in(array_diff(array_keys(CountryCode::NAMES), ['CN', 'CM']))],
            'kg' => 'required|numeric|min:0.5|max:1000000',
            'volume_cbm' => 'nullable|numeric|gt:0|max:10000',
            'cargo_category' => 'required|in:general,sensitive',
            'description' => 'required|string|min:5|max:3000',
        ]);
        $platform = SupportService::user();
        abort_unless($platform && ! $platform->is($request->user()), 422, 'Le compte ASSO est indisponible.');
        $inquiry = DB::transaction(function () use ($request, $platform, $data) {
            User::whereKey($platform->id)->lockForUpdate()->firstOrFail();
            $ids = [$request->user()->id, $platform->id];
            sort($ids);
            $conversation = Conversation::where(fn ($q) => $q
                ->where(fn ($pair) => $pair->where('user1_id', $ids[0])->where('user2_id', $ids[1]))
                ->orWhere(fn ($pair) => $pair->where('user1_id', $ids[1])->where('user2_id', $ids[0])))
                ->whereNull('product_id')->whereNull('diaspo_offer_id')->whereNull('real_estate_listing_id')->first();
            $conversation ??= Conversation::create(['user1_id' => $ids[0], 'user2_id' => $ids[1]]);
            $inquiry = FreightInquiry::create($data + ['user_id' => $request->user()->id, 'conversation_id' => $conversation->id]);
            $message = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $request->user()->id,
                'message' => "Demande de devis maritime #{$inquiry->id} — Chine → {$inquiry->destination_country}, {$inquiry->kg} kg, ".($inquiry->volume_cbm ? "{$inquiry->volume_cbm} m³, " : '')."{$inquiry->cargo_category}.\n{$inquiry->description}", 'is_read' => false]);
            $conversation->update(['last_message_at' => now()]);
            \App\Services\FreightInquiryMessaging::notify($message, $platform);

            return $inquiry;
        });

        return response()->json(['success' => true, 'data' => $inquiry->toApi()], 201);
    }

    public function accept(Request $request, int $id)
    {
        $inquiry = DB::transaction(function () use ($request, $id) {
            $inquiry = FreightInquiry::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($id);
            $data = $request->validate(['expected_amount' => 'required|numeric', 'expected_currency' => 'required|string|size:3']);
            abort_unless(in_array($inquiry->status, ['quoted', 'accepted']) && $inquiry->expires_at?->isFuture(), 422, 'Ce devis est indisponible ou expiré.');
            abort_unless(round((float) $data['expected_amount'], 2) === round($inquiry->freight_amount, 2) && $data['expected_currency'] === $inquiry->currency, 422, 'Le devis a changé. Actualisez les montants avant de confirmer.');
            if ($inquiry->status === 'quoted') {
                $inquiry->update(['status' => 'accepted', 'accepted_at' => now()]);
            }

            return $inquiry;
        });

        return response()->json(['success' => true, 'data' => $inquiry->toApi()]);
    }
}
