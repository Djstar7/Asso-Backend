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
        $query = DelivererCompany::with(['user', 'deliveryZones', 'syncCodes' => function($q) {
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

    public function create()
    {
        return view('admin.deliverers.create');
    }

    public function store(Request $request)
    {
        Log::info('[DELIVERER_STORE] ========== DEBUT CREATION ENTREPRISE ==========');
        Log::info('[DELIVERER_STORE] Données reçues', [
            'company_name' => $request->input('company_name'),
            'company_email' => $request->input('company_email'),
            'company_phone' => $request->input('company_phone'),
            'has_logo' => $request->hasFile('company_logo'),
            'send_code_via' => $request->input('send_code_via'),
            'delivery_zones_count' => is_array($request->input('delivery_zones')) ? count($request->input('delivery_zones')) : 'NOT_ARRAY',
            'delivery_zones_raw' => $request->input('delivery_zones'),
        ]);

        try {
            $validated = $request->validate([
                // Company info (used for user creation)
                'company_name' => 'required|string|max:255',
                'company_phone' => 'required|string|max:20',
                'company_email' => 'required|email|unique:deliverer_companies,email',
                'company_description' => 'nullable|string',
                'company_logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,ico,bmp,tiff,tif,avif|max:5120',

                // Delivery zones (JSON array)
                'delivery_zones' => 'required|array|min:1',
                'delivery_zones.*.name' => 'required|string|max:255',
                'delivery_zones.*.center_latitude' => 'required|numeric|between:-90,90',
                'delivery_zones.*.center_longitude' => 'required|numeric|between:-180,180',

                // Pricelists for each zone
                'delivery_zones.*.pricing_type' => 'required|in:fixed,weight_category,volumetric_weight',
                'delivery_zones.*.pricing_data' => 'required|array',

                // Notification preferences (only email is supported)
                'send_code_via' => 'required|in:email',
            ]);
        } catch (\Illuminate\Validation\ValidationException $ve) {
            Log::error('[DELIVERER_STORE] VALIDATION ECHOUEE', [
                'errors' => $ve->errors(),
                'input_keys' => array_keys($request->all()),
                'delivery_zones_raw' => $request->input('delivery_zones'),
            ]);
            throw $ve;
        }

        Log::info('[DELIVERER_STORE] Validation OK', ['validated_keys' => array_keys($validated)]);

        try {
            DB::beginTransaction();
            Log::info('[DELIVERER_STORE] Transaction démarrée');

            // 1. Handle company logo upload
            $logoPath = null;
            if ($request->hasFile('company_logo')) {
                $logoPath = $request->file('company_logo')->store('deliverer_companies', 'public');
                Log::info('[DELIVERER_STORE] Logo uploadé', ['path' => $logoPath]);
            }

            // 2. Create deliverer company (WITHOUT user - will be linked during sync)
            $company = DelivererCompany::create([
                'user_id' => null, // Will be set when deliverer syncs with the code
                'name' => $validated['company_name'],
                'phone' => $validated['company_phone'],
                'email' => $validated['company_email'],
                'description' => $validated['company_description'] ?? null,
                'logo' => $logoPath,
            ]);
            Log::info('[DELIVERER_STORE] Entreprise créée', ['company_id' => $company->id, 'name' => $company->name]);

            // 4. Create delivery zones and pricelists
            foreach ($validated['delivery_zones'] as $index => $zoneData) {
                Log::info("[DELIVERER_STORE] Création zone #{$index}", [
                    'name' => $zoneData['name'],
                    'lat' => $zoneData['center_latitude'],
                    'lng' => $zoneData['center_longitude'],
                    'pricing_type' => $zoneData['pricing_type'],
                    'pricing_data' => $zoneData['pricing_data'],
                ]);

                $zone = DeliveryZone::create([
                    'deliverer_company_id' => $company->id,
                    'name' => $zoneData['name'],
                    'zone_data' => null, // We only use center coordinates now
                    'center_latitude' => $zoneData['center_latitude'],
                    'center_longitude' => $zoneData['center_longitude'],
                ]);
                Log::info("[DELIVERER_STORE] Zone créée", ['zone_id' => $zone->id]);

                $pricelist = DeliveryPricelist::create([
                    'delivery_zone_id' => $zone->id,
                    'pricing_type' => $zoneData['pricing_type'],
                    'pricing_data' => $zoneData['pricing_data'],
                ]);
                Log::info("[DELIVERER_STORE] Pricelist créée", ['pricelist_id' => $pricelist->id]);
            }

            // 3. Generate sync code (without user - will be linked during sync)
            $syncCode = DelivererSyncCode::generateSyncCode();
            $expiresAt = now()->addDays(30); // Code valid for 30 days

            $delivererSyncCode = DelivererSyncCode::create([
                'user_id' => null, // Will be set when deliverer syncs
                'company_id' => $company->id, // Link to company
                'sync_code' => $syncCode,
                'sent_via' => $validated['send_code_via'],
                'sent_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            Log::info('[DELIVERER_STORE] Sync code créé', ['sync_code' => $syncCode, 'sync_id' => $delivererSyncCode->id]);

            DB::commit();
            Log::info('[DELIVERER_STORE] Transaction COMMIT OK — Entreprise ID=' . $company->id);

            // 4. Send sync code to company via email AFTER commit
            // This way, even if email fails, the data is already saved
            $emailSent = false;
            $emailError = null;
            try {
                $this->sendSyncCode($company, $syncCode, $validated['send_code_via']);
                $emailSent = true;
            } catch (\Exception $emailException) {
                $emailError = $emailException->getMessage();
                Log::error('Email sending failed (data saved): ' . $emailError);
            }

            $emailStatus = $emailSent
                ? "<div class='text-sm'>📧 Un email professionnel a été envoyé à <strong>{$validated['company_email']}</strong></div>"
                : "<div class='text-sm text-yellow-400'>⚠️ L'email n'a pas pu être envoyé ({$emailError}). Le code reste valide, vous pouvez le communiquer manuellement.</div>";

            $successMessage = "
                <div class='space-y-3'>
                    <div class='flex items-center gap-2 text-lg font-bold'>
                        <i class='fas fa-check-circle text-green-400'></i>
                        <span>Entreprise de livraison créée avec succès !</span>
                    </div>

                    <div class='bg-gray-800/50 rounded-lg p-4 space-y-2'>
                        <p class='font-semibold text-white'>📦 Entreprise : <span class='text-primary-400'>{$validated['company_name']}</span></p>

                        <div class='border-t border-gray-700 pt-2 mt-2'>
                            <p class='text-sm font-medium text-gray-300 mb-2'>🔐 Code de synchronisation généré :</p>
                            <div class='space-y-1 text-sm'>
                                <p>• Code : <code class='bg-gray-900 px-2 py-1 rounded text-yellow-400 font-mono'>{$syncCode}</code></p>
                                <p>• Email entreprise : <span class='text-blue-400'>{$validated['company_email']}</span></p>
                                <p>• Téléphone : <span class='text-blue-400'>{$validated['company_phone']}</span></p>
                                <p>• Validité : <span class='text-green-400'>30 jours</span></p>
                            </div>
                        </div>

                        <div class='border-t border-gray-700 pt-2 mt-2'>
                            <p class='text-sm font-medium text-gray-300 mb-1'>📨 Code de synchronisation :</p>
                            {$emailStatus}
                        </div>

                        <div class='bg-blue-900/30 border border-blue-500/50 rounded p-3 mt-3'>
                            <p class='text-xs text-blue-300'>
                                <i class='fas fa-info-circle mr-1'></i>
                                Le livreur doit créer son compte dans l'application mobile (s'il n'en a pas), puis scanner ce code pour synchroniser son profil avec l'entreprise.
                                Le code est valide pendant <strong>30 jours</strong>.
                            </p>
                        </div>
                    </div>
                </div>
            ";

            return redirect()->route('admin.deliverers.index')
                ->with('success', $successMessage);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[DELIVERER_STORE] EXCEPTION — ROLLBACK', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            Log::error('[DELIVERER_STORE] Stack trace: ' . $e->getTraceAsString());

            return redirect()->back()
                ->withInput()
                ->with('error', 'Erreur lors de la création du livreur: ' . $e->getMessage());
        }
    }

    /**
     * Send sync code to deliverer company via email
     */
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

    public function update(Request $request, DelivererCompany $deliverer)
    {
        $validated = $request->validate([
            // Company info
            'company_name' => 'required|string|max:255',
            'company_phone' => 'required|string|max:20',
            'company_email' => ['required', 'email', Rule::unique('deliverer_companies', 'email')->ignore($deliverer->id)],
            'company_description' => 'nullable|string',
            'company_logo' => 'nullable|file|mimes:jpeg,png,jpg,gif,svg,webp,ico,bmp,tiff,tif,avif|max:5120',
            'is_active' => 'boolean',

            // Delivery zones
            'delivery_zones' => 'required|array|min:1',
            'delivery_zones.*.id' => 'nullable|exists:delivery_zones,id',
            'delivery_zones.*.name' => 'required|string|max:255',
            'delivery_zones.*.center_latitude' => 'required|numeric|between:-90,90',
            'delivery_zones.*.center_longitude' => 'required|numeric|between:-180,180',

            // Pricelists for each zone
            'delivery_zones.*.pricing_type' => 'required|in:fixed,weight_category,volumetric_weight',
            'delivery_zones.*.pricing_data' => 'required|array',
        ]);

        try {
            DB::beginTransaction();

            // 1. Handle company logo upload
            $logoPath = $deliverer->logo;
            if ($request->hasFile('company_logo')) {
                if ($deliverer->logo) {
                    Storage::disk('public')->delete($deliverer->logo);
                }
                $logoPath = $request->file('company_logo')->store('deliverer_companies', 'public');
            }

            // 2. Update deliverer company
            $deliverer->update([
                'name' => $validated['company_name'],
                'phone' => $validated['company_phone'],
                'email' => $validated['company_email'],
                'description' => $validated['company_description'] ?? null,
                'logo' => $logoPath,
                'is_active' => $request->has('is_active') ? true : false,
            ]);

            // 3. Get existing zone IDs
            $existingZoneIds = $deliverer->deliveryZones->pluck('id')->toArray();
            $updatedZoneIds = [];

            // 4. Update or create delivery zones and pricelists
            foreach ($validated['delivery_zones'] as $zoneData) {
                if (!empty($zoneData['id']) && in_array($zoneData['id'], $existingZoneIds)) {
                    // Update existing zone
                    $zone = DeliveryZone::find($zoneData['id']);
                    $zone->update([
                        'name' => $zoneData['name'],
                        'center_latitude' => $zoneData['center_latitude'],
                        'center_longitude' => $zoneData['center_longitude'],
                    ]);

                    // Update pricelist
                    if ($zone->pricelist) {
                        $zone->pricelist->update([
                            'pricing_type' => $zoneData['pricing_type'],
                            'pricing_data' => $zoneData['pricing_data'],
                        ]);
                    } else {
                        DeliveryPricelist::create([
                            'delivery_zone_id' => $zone->id,
                            'pricing_type' => $zoneData['pricing_type'],
                            'pricing_data' => $zoneData['pricing_data'],
                        ]);
                    }

                    $updatedZoneIds[] = $zone->id;
                } else {
                    // Create new zone
                    $zone = DeliveryZone::create([
                        'deliverer_company_id' => $deliverer->id,
                        'name' => $zoneData['name'],
                        'zone_data' => null,
                        'center_latitude' => $zoneData['center_latitude'],
                        'center_longitude' => $zoneData['center_longitude'],
                    ]);

                    DeliveryPricelist::create([
                        'delivery_zone_id' => $zone->id,
                        'pricing_type' => $zoneData['pricing_type'],
                        'pricing_data' => $zoneData['pricing_data'],
                    ]);

                    $updatedZoneIds[] = $zone->id;
                }
            }

            // 5. Delete zones that were removed
            $zonesToDelete = array_diff($existingZoneIds, $updatedZoneIds);
            foreach ($zonesToDelete as $zoneId) {
                $zone = DeliveryZone::find($zoneId);
                if ($zone) {
                    $zone->pricelist()->delete();
                    $zone->delete();
                }
            }

            DB::commit();

            return redirect()->route('admin.deliverers.show', $deliverer)
                ->with('success', 'Entreprise de livraison mise à jour avec succès!');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating deliverer: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());

            return redirect()->back()
                ->withInput()
                ->with('error', 'Erreur lors de la mise à jour: ' . $e->getMessage());
        }
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
