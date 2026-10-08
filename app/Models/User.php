<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Translation\LocalizedText;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'password',
        'role',
        'roles',
        'admin_permissions',
        'created_by_admin_id',
        'gender',
        'birth_date',
        'phone',
        'country',
        'locale',
        'address',
        'latitude',
        'longitude',
        'avatar',
        'company_name',
        'company_logo',
        'referral_code',
        'referred_by_id',
        'total_earnings',
        'pending_earnings',
        'withdrawn_earnings',
        'kpay_wallet_balance',
        'locked_kpay_balance',
        'otp_code',
        'otp_expires_at',
        'is_profile_complete',
        'preferences',
        'fcm_token',
        'diaspo_verification_status',
        'diaspo_id_document_id',
        'diaspo_verified_at',
        'diaspo_rejection_reason',
        'stripe_account_id',
        'stripe_account_status',
        'stripe_rejection_reason',
        'stripe_submitted_at',
        'stripe_verified_at',
        'stripe_external_last4',
        'stripe_bank_country',
        'stripe_account_holder_name',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'otp_code',
        'otp_expires_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'birth_date' => 'date',
            'password' => 'hashed',
            'preferences' => 'array',
            'roles' => 'array',
            'admin_permissions' => 'array',
            'is_profile_complete' => 'boolean',
            'otp_expires_at' => 'datetime',
            'diaspo_verified_at' => 'datetime',
            'stripe_submitted_at' => 'datetime',
            'stripe_verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Une nouvelle photo de profil remplace l'ancienne : le fichier
        // précédent ne sert plus à personne, on le retire du disque.
        static::updated(function (User $user) {
            if ($user->wasChanged('avatar')) {
                self::deleteStoredAvatar($user->getRawOriginal('avatar'));
            }
        });
    }

    /**
     * Supprime une photo déposée par l'application (disque public,
     * dossier avatars/). Les URL externes et autres chemins sont laissés.
     */
    public static function deleteStoredAvatar(?string $path): void
    {
        if (! $path || ! str_starts_with($path, 'avatars/')) {
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * Get the full name attribute
     */
    /**
     * Langue du compte : notifications Laravel et e-mails partent dans
     * cette langue.
     */
    public function preferredLocale(): string
    {
        return in_array($this->locale, ['fr', 'en'], true) ? $this->locale : 'fr';
    }

    /**
     * Traduit un texte dans la langue de cet utilisateur, et non dans celle
     * de la requête en cours : une notification push déclenchée par un
     * acheteur anglophone doit parvenir en français à un vendeur francophone.
     */
    public function translate(string $key, array $replace = []): string
    {
        return __($key, $replace, $this->preferredLocale());
    }

    /**
     * Comme translate(), mais garde la clé et les valeurs : une notification
     * enregistrée avec ce texte sera rédigée à nouveau dans la langue que
     * l'utilisateur aura choisie au moment de la relire.
     */
    public function localized(string $key, array $replace = []): LocalizedText
    {
        return new LocalizedText($key, $replace, $this->preferredLocale());
    }

    public function getNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Get all shops for this user
     */
    public function shops(): HasMany
    {
        return $this->hasMany(Shop::class);
    }

    /**
     * Boutique principale du vendeur : la plus ancienne.
     *
     * Les appels historiques faisaient `shops()->first()` sans tri. Tant qu'un
     * vendeur n'a qu'une boutique le résultat est le bon, mais dès qu'il en a
     * plusieurs le SGBD est libre de renvoyer n'importe laquelle, et d'une
     * requête à l'autre : le tableau de bord pouvait alors changer de boutique
     * en cours de session. L'ordre est donc fixé ici, en un seul endroit.
     */
    public function primaryShop(): HasOne
    {
        return $this->hasOne(Shop::class)->oldest('id');
    }

    /**
     * Get all products for this user
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * Get all orders for this user
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get favorite products for this user
     */
    public function favorites()
    {
        return $this->belongsToMany(Product::class, 'favorites')->withTimestamps();
    }

    /**
     * Get all contact clicks where this user is the seller
     */
    public function contactClicksAsSeller(): HasMany
    {
        return $this->hasMany(ContactClick::class, 'seller_id');
    }

    /**
     * Get all contact clicks made by this user
     */
    public function contactClicks(): HasMany
    {
        return $this->hasMany(ContactClick::class);
    }

    /**
     * Get conversations where this user is user1
     */
    public function conversationsAsUser1(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user1_id');
    }

    /**
     * Get conversations where this user is user2
     */
    public function conversationsAsUser2(): HasMany
    {
        return $this->hasMany(Conversation::class, 'user2_id');
    }

    /**
     * Get all messages sent by this user
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    /**
     * Get all device tokens for this user
     */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Get all notifications for this user
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class)->latest();
    }

    /**
     * Get all posts created by this user
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get all comments created by this user
     */
    public function postComments(): HasMany
    {
        return $this->hasMany(PostComment::class);
    }

    /**
     * Get all post likes/dislikes by this user
     */
    public function postLikes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /**
     * Get the user who referred this user
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referred_by_id');
    }

    /**
     * Get all users referred by this user (niveau 1)
     */
    public function referrals(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_id');
    }

    /**
     * Get all commissions earned by this user
     */
    public function commissionsEarned(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'affiliate_id');
    }

    /**
     * Get all commissions generated by this user's activity
     */
    public function commissionsGenerated(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class, 'referred_user_id');
    }

    /**
     * Get all vendor packages for this user
     */
    public function vendorPackages(): HasMany
    {
        return $this->hasMany(VendorPackage::class);
    }

    /**
     * Get the active vendor package for this user
     */
    public function activeVendorPackage(): HasOne
    {
        return $this->hasOne(VendorPackage::class)
            ->where('status', 'active')
            ->where('expires_at', '>', now())
            ->latest('purchased_at');
    }

    /**
     * Get all wallet transactions for this user
     */
    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    /**
     * Get all withdrawals for this user
     */
    public function withdrawals(): HasMany
    {
        return $this->hasMany(PlatformWithdrawal::class);
    }

    /**
     * Get the deliverer company for this user
     */
    public function delivererCompany(): HasOne
    {
        return $this->hasOne(DelivererCompany::class);
    }

    /**
     * Get all sync codes for this deliverer
     */
    public function delivererSyncCodes(): HasMany
    {
        return $this->hasMany(DelivererSyncCode::class);
    }

    /**
     * Get the latest valid sync code
     */
    public function latestValidSyncCode(): HasOne
    {
        return $this->hasOne(DelivererSyncCode::class)
            ->where('is_used', false)
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->latest();
    }

    /**
     * Get all diaspo offers created by this user
     */
    public function diaspoOffers(): HasMany
    {
        return $this->hasMany(DiaspoOffer::class);
    }

    /**
     * Get all diaspo bookings as buyer
     */
    public function diaspoBookingsAsBuyer(): HasMany
    {
        return $this->hasMany(DiaspoBooking::class, 'buyer_user_id');
    }

    /**
     * Get all diaspo bookings as seller
     */
    public function diaspoBookingsAsSeller(): HasMany
    {
        return $this->hasMany(DiaspoBooking::class, 'seller_user_id');
    }

    /**
     * Get the diaspo ID document
     */
    public function diaspoIdDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'diaspo_id_document_id');
    }

    /**
     * Check if user is diaspo verified
     */
    public function isDiaspoVerified(): bool
    {
        return $this->diaspo_verification_status === 'verified';
    }

    /**
     * Check if user can create diaspo offers
     */
    public function canCreateDiaspoOffers(): bool
    {
        return $this->isDiaspoVerified();
    }

    /**
     * Get total wallet balance (KPay)
     */
    public function getTotalWalletBalanceAttribute(): float
    {
        return $this->kpay_wallet_balance ?? 0;
    }

    /**
     * Get formatted total wallet balance
     */
    public function getFormattedWalletBalanceAttribute(): string
    {
        return number_format($this->total_wallet_balance, 0, ',', ' ').' FCFA';
    }

    /**
     * Get formatted KPay wallet balance
     */
    public function getFormattedKpayBalanceAttribute(): string
    {
        return number_format($this->kpay_wallet_balance ?? 0, 0, ',', ' ').' FCFA';
    }

    /**
     * Solde KPay disponible (total - bloqué)
     */
    public function getAvailableKpayBalanceAttribute(): float
    {
        return ($this->kpay_wallet_balance ?? 0) - ($this->locked_kpay_balance ?? 0);
    }

    /**
     * Solde total disponible (non bloqué)
     */
    public function getAvailableTotalBalanceAttribute(): float
    {
        return $this->available_kpay_balance;
    }

    /**
     * Total solde bloqué
     */
    public function getTotalLockedBalanceAttribute(): float
    {
        return $this->locked_kpay_balance ?? 0;
    }

    // ==================== Soldes wallet multi-devise (KPay) ====================

    /** Soldes KPay par devise. */
    public function walletBalances(): HasMany
    {
        return $this->hasMany(WalletBalance::class);
    }

    /** Solde total dans une devise donnée. */
    public function kpayBalanceFor(string $currency): float
    {
        $wb = $this->walletBalances()->where('currency', $currency)->first();

        return $wb ? (float) $wb->balance : 0.0;
    }

    /** Solde disponible (total - bloqué) dans une devise donnée. */
    public function kpayAvailableFor(string $currency): float
    {
        $wb = $this->walletBalances()->where('currency', $currency)->first();

        return $wb ? ((float) $wb->balance - (float) $wb->locked_balance) : 0.0;
    }

    /** Créditer une devise (crée la ligne si besoin). */
    public function creditKpay(string $currency, float $amount): WalletBalance
    {
        $wb = WalletBalance::firstOrCreate(
            ['user_id' => $this->id, 'currency' => $currency],
            ['balance' => 0, 'locked_balance' => 0]
        );
        $wb->increment('balance', $amount);

        return $wb->fresh();
    }

    /** Débiter une devise. */
    public function debitKpay(string $currency, float $amount): WalletBalance
    {
        $wb = WalletBalance::firstOrCreate(
            ['user_id' => $this->id, 'currency' => $currency],
            ['balance' => 0, 'locked_balance' => 0]
        );
        $wb->decrement('balance', $amount);

        return $wb->fresh();
    }

    /**
     * Boot method to generate referral code
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            if (empty($user->referral_code)) {
                $user->referral_code = strtoupper(substr($user->first_name, 0, 3).substr($user->last_name, 0, 3).rand(1000, 9999));
            }
        });
    }

    /**
     * Get total number of direct referrals
     */
    public function getTotalReferralsAttribute(): int
    {
        return $this->referrals()->count();
    }

    /**
     * Get all referrals recursively (niveau 1, 2, 3)
     */
    public function getAllReferralsTree()
    {
        $settings = AffiliateSetting::getSettings();
        $maxLevels = $settings->max_levels;

        return $this->getReferralsRecursive(1, $maxLevels);
    }

    private function getReferralsRecursive($currentLevel, $maxLevel)
    {
        if ($currentLevel > $maxLevel) {
            return collect();
        }

        $referrals = $this->referrals()->with('referrals')->get();

        $result = $referrals->map(function ($referral) use ($currentLevel, $maxLevel) {
            return [
                'user' => $referral,
                'level' => $currentLevel,
                'children' => $referral->getReferralsRecursive($currentLevel + 1, $maxLevel),
            ];
        });

        return $result;
    }

    // ================================
    // ROLES MANAGEMENT
    // ================================

    /**
     * Check if user has a specific role
     */
    public function hasRole(string $role): bool
    {
        if (is_array($this->roles)) {
            return in_array($role, $this->roles);
        }

        // Fallback to old role column
        return $this->role === $role;
    }

    /**
     * Check if user has any of the given roles
     */
    public function hasAnyRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Add a role to the user
     */
    public function addRole(string $role): void
    {
        $roles = is_array($this->roles) ? $this->roles : [$this->role];

        if (! in_array($role, $roles)) {
            $roles[] = $role;
            $this->roles = $roles;
            // Keep role column updated with primary role
            $this->role = $roles[0];
            $this->save();
        }
    }

    /**
     * Remove a role from the user
     */
    public function removeRole(string $role): void
    {
        $roles = is_array($this->roles) ? $this->roles : [$this->role];

        $roles = array_filter($roles, fn ($r) => $r !== $role);

        if (empty($roles)) {
            $roles = ['client']; // Default role
        }

        $this->roles = array_values($roles);
        $this->role = $roles[0];
        $this->save();
    }

    /**
     * Get all user roles
     */
    public function getRoles(): array
    {
        return is_array($this->roles) ? $this->roles : [$this->role];
    }

    // ================================
    // BACK-OFFICE ACCESS (config/admin_access.php)
    // ================================

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Compte autorisé à se connecter au back-office (admin ou gestionnaire).
     */
    public function isBackofficeStaff(): bool
    {
        return $this->isAdmin() || $this->hasAnyRole(config('admin_access.staff_roles', []));
    }

    /**
     * Permissions effectives : celles du rôle + celles accordées par l'admin.
     */
    public function adminPermissions(): array
    {
        if (! $this->isBackofficeStaff()) {
            return [];
        }

        $fromRoles = [];
        foreach ($this->getRoles() as $role) {
            $fromRoles = array_merge($fromRoles, config("admin_access.role_permissions.{$role}", []));
        }

        return array_values(array_unique(array_merge($fromRoles, $this->admin_permissions ?? [])));
    }

    public function hasAdminPermission(string $permission): bool
    {
        return $this->isAdmin() || in_array($permission, $this->adminPermissions(), true);
    }

    /**
     * Une route admin nommée est-elle accessible à ce compte ?
     * Route non déclarée dans admin_access.routes => admin uniquement.
     */
    public function canAccessAdminRoute(string $routeName): bool
    {
        if (! $this->isBackofficeStaff()) {
            return false;
        }
        if ($this->isAdmin() || in_array($routeName, config('admin_access.open_routes', []), true)) {
            return true;
        }

        foreach (config('admin_access.routes', []) as $pattern => $permissions) {
            if (\Illuminate\Support\Str::is($pattern, $routeName)) {
                foreach ((array) $permissions as $permission) {
                    if ($this->hasAdminPermission($permission)) {
                        return true;
                    }
                }

                return false;
            }
        }

        return false;
    }

    /**
     * Page d'arrivée après connexion : Dashboard pour l'admin, sinon la
     * première section ouverte au gestionnaire.
     */
    public function adminHomeRoute(): ?string
    {
        $candidates = config('admin_access.home_routes', []);
        foreach (array_keys(config('admin_access.routes', [])) as $pattern) {
            $candidates[] = str_replace('*', 'index', $pattern);
        }

        foreach ($candidates as $name) {
            if (\Illuminate\Support\Facades\Route::has($name) && $this->canAccessAdminRoute($name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Libellé affiché dans le back-office (pied du menu, liste des gestionnaires).
     */
    public function backofficeRoleLabel(): string
    {
        if ($this->isAdmin()) {
            return 'Administrateur';
        }
        foreach (config('admin_access.manager_roles', []) as $role => $label) {
            if ($this->hasRole($role)) {
                return $label;
            }
        }

        return 'Gestionnaire';
    }
}
