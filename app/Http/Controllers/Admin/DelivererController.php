<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\DelivererCompany;
use App\Models\DeliveryZone;
use App\Models\DeliveryPricelist;
use App\Models\DelivererSyncCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class DelivererController extends Controller
{
    public function index(Request $request)
    {
        // Récupérer les entreprises de livraison avec leurs relations
        // Les partenaires maritimes n'ont ni coursiers ni code de synchronisation.
        $query = DelivererCompany::where('service_type', '!=', DelivererCompany::SERVICE_MARITIME)
            ->with(['user', 'deliveryZones', 'cityGrids', 'deliveryRoutes', 'syncCodes' => function($q) {
                $q->latest();
            }]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            if ($request->status === 'synced') {
                $query->whereNotNull('user_id');
            } elseif ($request->status === 'pending') {
                $query->whereNull('user_id');
            }
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $deliverers = $query->orderBy('created_at', 'desc')->paginate(15)->withQueryString();

        return view('admin.deliverers.index', compact('deliverers'));
    }

    /** Création d'un partenaire : une seule section, « Partenaires logistiques ». */
    public function create()
    {
        return redirect()->route('admin.delivery-partners.index', ['create' => 1]);
    }

    private function sendSyncCode(DelivererCompany $company, string $syncCode, string $sendVia)
    {
        try {
            // Load delivery zones for email
            $company->load('deliveryZones');

            // Get expiration date
            $expiresAt = now()->addDays(30);

            // Send email
            Mail::to($company->email)->send(new \App\Mail\DelivererSyncCodeMail(
                $syncCode,
                $company,
                $expiresAt
            ));

            Log::info("✅ Sync code sent via email to {$company->email}: Code={$syncCode}");
        } catch (\Exception $e) {
            Log::error('❌ Error sending sync code email: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            throw $e; // Re-throw to handle in the calling method
        }
    }

    /**
     * Send SMS via Nexaah API
     */
    private function sendSMS(string $phone, string $message)
    {
        // TODO: Implement Nexaah SMS API integration
        // Example:
        // Http::post('https://api.nexaah.com/sms/send', [
        //     'api_key' => config('services.nexaah.api_key'),
        //     'to' => $phone,
        //     'message' => $message
        // ]);
    }

    /**
     * Send WhatsApp message via Nexaah API
     */
    private function sendWhatsApp(string $phone, string $message)
    {
        // TODO: Implement Nexaah WhatsApp API integration
        // Example:
        // Http::post('https://api.nexaah.com/whatsapp/send', [
        //     'api_key' => config('services.nexaah.api_key'),
        //     'to' => $phone,
        //     'message' => $message
        // ]);
    }

    public function show(DelivererCompany $deliverer)
    {
        $deliverer->load([
            'user',
            'deliveryZones.pricelist',
            'cityGrids',
            'deliveryRoutes',
            'syncCodes' => function($q) {
                $q->latest();
            },
            'codeSyncs' => function($q) {
                $q->with(['user', 'syncCode'])->latest();
            }
        ]);

        return view('admin.deliverers.show', compact('deliverer'));
    }

    public function edit(DelivererCompany $deliverer)
    {
        $deliverer->load(['deliveryZones.pricelist']);

        return view('admin.deliverers.edit', compact('deliverer'));
    }

    /**
     * Identité de l'entreprise uniquement : zones, tarifs et trajets se configurent
     * dans « Partenaires logistiques » (une seule section).
     */
    public function update(Request $request, DelivererCompany $deliverer)
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'company_phone' => 'nullable|string|max:30',
            'company_email' => ['nullable', 'email', Rule::unique('deliverer_companies', 'email')->ignore($deliverer->id)],
            'company_description' => 'nullable|string',
            'company_logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,ico,bmp,tiff,tif,avif|max:5120',
        ]);

        $logoPath = $deliverer->logo;
        if ($request->hasFile('company_logo')) {
            if ($deliverer->logo) {
                Storage::disk('public')->delete($deliverer->logo);
            }
            $logoPath = $request->file('company_logo')->store('deliverer_companies', 'public');
        }

        $deliverer->update([
            'name' => $validated['company_name'],
            'phone' => $validated['company_phone'] ?? null,
            'email' => $validated['company_email'] ?? null,
            'description' => $validated['company_description'] ?? null,
            'logo' => $logoPath,
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('admin.deliverers.show', $deliverer)
            ->with('success', 'Entreprise de livraison mise à jour.');
    }

    /**
     * Nouveau code de synchronisation (partenaire créé hors formulaire comme SOLEX,
     * code expiré, ou nouveau coursier) : envoyé par email si l'entreprise en a un.
     */
    public function generateSyncCode(Request $request, DelivererCompany $deliverer)
    {
        $data = $request->validate(['delivery_agency_id' => ['nullable', 'integer',
            \Illuminate\Validation\Rule::exists('delivery_agencies', 'id')->where('deliverer_company_id', $deliverer->id)
                ->where(fn ($query) => $query->where('is_active', true)->where('is_international_reference', false)),
        ]]);
        $code = DelivererSyncCode::generateSyncCode();
        DelivererSyncCode::create([
            'user_id' => null,
            'company_id' => $deliverer->id, 'delivery_agency_id' => $data['delivery_agency_id'] ?? null,
            'sync_code' => $code,
            'sent_via' => 'email',
            'sent_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $sent = false;
        if ($deliverer->email) {
            try {
                $this->sendSyncCode($deliverer, $code, 'email');
                $sent = true;
            } catch (\Exception $e) {
                Log::error('[DELIVERER_SYNC_CODE] Envoi email échoué: ' . $e->getMessage());
            }
        }

        return back()->with('success', "Code de synchronisation : <strong>{$code}</strong> (valable 30 jours)."
            . ($sent ? " Envoyé à {$deliverer->email}." : ' À communiquer au coursier, qui le saisit dans l\'app ASSO.'));
    }

    public function destroy(DelivererCompany $deliverer)
    {
        if ($deliverer->logo) {
            Storage::disk('public')->delete($deliverer->logo);
        }

        // Delete associated delivery zones and pricelists
        foreach ($deliverer->deliveryZones as $zone) {
            $zone->pricelist()->delete();
            $zone->delete();
        }

        // Delete sync codes
        $deliverer->syncCodes()->delete();

        $deliverer->delete();

        return redirect()->route('admin.deliverers.index')
            ->with('success', 'Entreprise de livraison supprimée avec succès!');
    }
}
