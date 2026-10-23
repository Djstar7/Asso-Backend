<?php

namespace App\Http\Controllers\Admin\RealEstate;

use App\Http\Controllers\Controller;
use App\Models\RealEstate\Cabinet;
use App\Models\RealEstate\Listing;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Cabinets partenaires (foncier, juridique) : créés par ASSO, comme les
 * partenaires de livraison. Chaque cabinet a un compte qui porte son Wallet :
 * les acomptes des terrains y arrivent, bloqués jusqu'à la confirmation.
 */
class CabinetController extends Controller
{
    public function index()
    {
        $cabinets = Cabinet::withCount('listings')->with('user')->orderBy('name')->get();

        return view('admin.real_estate.cabinets.index', ['cabinets' => $cabinets]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $cabinet = Cabinet::create($data + ['user_id' => $this->accountFor($data)->id]);
        $this->storeImages($request, $cabinet);

        return redirect()->route('admin.real-estate.cabinets.edit', $cabinet)
            ->with('success', "Cabinet « {$cabinet->name} » créé. Ajoutez maintenant ses terrains.");
    }

    public function edit(Cabinet $cabinet)
    {
        $cabinet->load(['user', 'listings' => fn ($q) => $q->with('units')->latest()]);

        return view('admin.real_estate.cabinets.edit', ['cabinet' => $cabinet]);
    }

    public function update(Request $request, Cabinet $cabinet)
    {
        $data = $this->validated($request, $cabinet);
        $cabinet->update($data + ['is_active' => $request->boolean('is_active'), 'is_verified' => $request->boolean('is_verified')]);
        if (! $cabinet->user_id) {
            $cabinet->update(['user_id' => $this->accountFor($data)->id]);
        }
        $this->storeImages($request, $cabinet);

        return back()->with('success', 'Cabinet mis à jour.');
    }

    public function destroy(Cabinet $cabinet)
    {
        $wasDefault = DB::transaction(function () use ($cabinet) {
            $cabinet = Cabinet::whereKey($cabinet->id)->lockForUpdate()->firstOrFail();
            $wasDefault = Cabinet::defaultPartner()?->id === $cabinet->id;
            if ($wasDefault) {
                // Garder une configuration explicite invalide évite de transférer
                // automatiquement les prochaines commissions à un autre cabinet.
                Setting::set('immo_default_cabinet_id', $cabinet->id, 'integer', 'real_estate', 'Cabinet partenaire par défaut');
            }
            $cabinet->listings()->update(['status' => Listing::STATUS_ARCHIVED]);
            $cabinet->update(['is_active' => false]);
            $cabinet->delete();

            // Le compte bénéficiaire, les médias et les liens historiques sont conservés.
            return $wasDefault;
        });

        return redirect()->route('admin.real-estate.cabinets.index')->with('success',
            'Cabinet supprimé et ses annonces archivées. Les réservations et paiements sont conservés.'
            .($wasDefault ? ' Sélectionnez un nouveau cabinet par défaut dans les réglages immobiliers.' : '')
        );
    }

    private function validated(Request $request, ?Cabinet $cabinet = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'specialties' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:3000'],
            'why_choose_us' => ['nullable', 'string', 'max:3000'],
            'owners_message' => ['nullable', 'string', 'max:2000'],
            'region' => ['nullable', 'string', 'max:100'],
            'headquarters_city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'whatsapp' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'logo' => ['nullable', 'image', 'max:4096'],
            'banner' => ['nullable', 'image', 'max:8192'],
        ]);
        // Une puce par ligne dans le formulaire.
        $data['why_choose_us'] = collect(preg_split('/\r\n|\n/', (string) ($data['why_choose_us'] ?? '')))
            ->map(fn ($l) => trim($l))->filter()->values()->all();
        unset($data['logo'], $data['banner']);

        return $data;
    }

    /** Compte du cabinet : réutilise l'e-mail s'il existe déjà. */
    private function accountFor(array $data): User
    {
        $email = $data['email'] ?? null;
        if ($email && ($existing = User::where('email', $email)->first())) {
            return $existing;
        }

        $user = new User;
        $user->forceFill([
            'first_name' => Str::limit($data['name'], 60, ''),
            'last_name' => 'Cabinet',
            'email' => $email ?: 'cabinet-'.Str::lower(Str::random(10)).'@partners.asso.app',
            'phone' => null,
            'password' => Hash::make(Str::random(32)),
        ])->save();

        return $user;
    }

    private function storeImages(Request $request, Cabinet $cabinet): void
    {
        foreach (['logo' => 'logo_path', 'banner' => 'banner_path'] as $input => $column) {
            if ($request->hasFile($input)) {
                if ($cabinet->$column) {
                    Storage::disk('public')->delete($cabinet->$column);
                }
                $cabinet->update([$column => $request->file($input)->store('real_estate/cabinets', 'public')]);
            }
        }
    }
}
