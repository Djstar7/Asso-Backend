<?php

namespace App\Models\RealEstate;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cabinet partenaire (foncier, juridique) : créé par ASSO, il porte les
 * terrains à vendre. Son identité reste privée ; le contact client est communiqué
 * dans la réservation payée, sans débloquer la fiche générale du cabinet.
 */
class Cabinet extends Model
{
    use SoftDeletes;

    protected $table = 'real_estate_cabinets';

    protected $fillable = [
        'user_id', 'name', 'tagline', 'specialties', 'description', 'why_choose_us',
        'owners_message', 'logo_path', 'banner_path', 'region', 'headquarters_city',
        'address', 'phone', 'whatsapp', 'email', 'is_verified', 'is_active',
    ];

    protected $casts = [
        'why_choose_us' => 'array',
        'is_verified' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function defaultPartner(): ?self
    {
        $id = (int) Setting::get('immo_default_cabinet_id', 0);
        $query = static::where('is_active', true);

        // Une configuration explicite invalide ne doit pas détourner un paiement.
        return $id > 0 ? $query->find($id) : $query->orderBy('id')->first();
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class, 'cabinet_id');
    }

    /** Seul le gestionnaire voit ses coordonnées sur la fiche générale. */
    public function isUnlockedFor(?User $user): bool
    {
        return $user !== null && $this->user_id !== null && $user->id === $this->user_id;
    }

    public function toApi(?User $viewer = null, bool $withDetails = false): array
    {
        $unlocked = $this->isUnlockedFor($viewer);

        $data = [
            'id' => $this->id,
            'name' => $unlocked ? $this->name : null,
            'tagline' => $unlocked ? $this->tagline : null,
            'logo_url' => $unlocked ? media_url($this->logo_path) : null,
            'is_verified' => (bool) $this->is_verified,
            'headquarters_city' => $this->headquarters_city,
        ];

        if ($withDetails) {
            $data += [
                'specialties' => $unlocked ? $this->specialties : null,
                'description' => $unlocked ? $this->description : null,
                'why_choose_us' => $unlocked ? array_values(array_filter($this->why_choose_us ?? [])) : [],
                'owners_message' => $unlocked ? $this->owners_message : null,
                'banner_url' => $unlocked ? media_url($this->banner_path) : null,
                'region' => $this->region,
                'listings_count' => $this->listings()->published()->count(),
            ];
        }

        $data['contact_unlocked'] = $unlocked;
        $data['contact'] = $unlocked ? [
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'email' => $this->email,
            'address' => $this->address,
        ] : null;

        return $data;
    }
}
