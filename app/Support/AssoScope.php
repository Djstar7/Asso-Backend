<?php

namespace App\Support;

use App\Models\Dispute;
use App\Models\DisputeShipment;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shop;
use App\Models\ShopLocationRequest;
use App\Models\User;
use App\Services\CommissionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Périmètre des gestionnaires du back-office : les boutiques ASSO uniquement.
 *
 * Les boutiques ASSO (ASSO Fashion, ASSO Import Douala…) appartiennent au compte
 * plateforme ; celles des particuliers à leur vendeur. Un gestionnaire ne doit
 * voir ni les produits, ni les commandes, ni les litiges des vendeurs : pendant
 * ses requêtes, les boutiques, produits, commandes, litiges et demandes de
 * déplacement ne renvoient que ce qui relève des boutiques ASSO
 * (EnsureBackofficeAccess active le filtre). L'admin garde la vue complète.
 */
class AssoScope
{
    public const NAME = 'asso_shops_only';

    private static ?int $ownerId = null;

    /** Le compte doit-il être limité aux boutiques ASSO ? */
    public static function restricts(?User $user): bool
    {
        return $user !== null && $user->isBackofficeStaff() && ! $user->isAdmin();
    }

    /** Le compte connecté est-il limité aux boutiques ASSO ? */
    public static function active(): bool
    {
        return static::restricts(Auth::user());
    }

    /** Propriétaire des boutiques ASSO (0 si aucun compte plateforme : rien ne passe). */
    public static function ownerId(): int
    {
        return static::$ownerId ??= (int) (CommissionService::platformAccount()?->id ?? 0);
    }

    /** Filtre toutes les requêtes Eloquent de la requête HTTP en cours. */
    public static function enable(): void
    {
        $owner = static::ownerId();

        Shop::addGlobalScope(self::NAME, fn (Builder $q) => $q->where($q->qualifyColumn('user_id'), $owner));

        Product::addGlobalScope(self::NAME, fn (Builder $q) => $q->whereIn(
            $q->qualifyColumn('shop_id'),
            fn ($shops) => $shops->select('id')->from('shops')->where('user_id', $owner),
        ));

        Order::addGlobalScope(self::NAME, fn (Builder $q) => static::orders($q));

        ShopLocationRequest::addGlobalScope(self::NAME, fn (Builder $q) => $q->whereHas('shop'));
        Dispute::addGlobalScope(self::NAME, fn (Builder $q) => $q->whereHas('order'));
        DisputeShipment::addGlobalScope(self::NAME, fn (Builder $q) => $q->whereHas('dispute'));
    }

    /**
     * Commandes des boutiques ASSO : tous les articles viennent d'une boutique
     * ASSO. Une commande mêlant un vendeur particulier reste réservée à l'admin.
     */
    public static function orders(Builder $query): Builder
    {
        $owner = static::ownerId();

        return $query
            ->whereHas('items', fn ($i) => $i->where('seller_id', $owner))
            ->whereDoesntHave('items', fn ($i) => $i->where(fn ($w) => $w->where('seller_id', '<>', $owner)->orWhereNull('seller_id')));
    }

    /** Commandes à traiter dans la rubrique « Boutiques ASSO » (le gros a son menu). */
    public static function shopOrders(): Builder
    {
        return static::orders(Order::query())->where('is_wholesale', false);
    }

    /**
     * Le modèle lié à la route fait-il partie du périmètre ? La liaison des
     * paramètres de route a lieu avant l'activation du filtre : on revérifie.
     */
    public static function covers(Model $model): bool
    {
        if (! $model::hasGlobalScope(self::NAME)) {
            return true;
        }

        return $model->newQuery()->whereKey($model->getKey())->exists();
    }
}
