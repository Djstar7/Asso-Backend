<?php

namespace App\Support;

use App\Models\DeliveryCityGrid;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Shop;
use App\Services\CommissionService;

/**
 * Entrepôt de réception des imports (Chine, Dubaï, Turquie) : la boutique
 * « ASSO Import Douala ». Toute commande en gros arrive d'abord ici, quel que soit
 * le pays d'origine ; SOLEX prend ensuite le relais jusqu'au client (Douala ou
 * ailleurs au Cameroun), au prix recalculé depuis l'adresse de cette boutique.
 */
class ImportHub
{
    public const SETTING = 'import_hub_shop_id';
    public const NAME = 'ASSO Import Douala';
    public const CITY = 'Douala';

    public static function shop(): ?Shop
    {
        $id = Setting::get(self::SETTING);

        return $id ? Shop::find($id) : null;
    }

    /**
     * Crée la boutique si elle n'existe pas encore (compte plateforme ASSO) et la
     * retient dans les paramètres. Null tant qu'aucun compte plateforme n'existe.
     */
    public static function ensureShop(): ?Shop
    {
        if ($shop = self::shop()) {
            return $shop;
        }

        $owner = CommissionService::platformAccount();
        if (!$owner) {
            return null;
        }

        $shop = Shop::firstOrCreate(
            ['user_id' => $owner->id, 'name' => self::NAME],
            [
                'description' => 'Réception des commandes en gros importées (Chine, Dubaï, Turquie) avant livraison SOLEX.',
                'city' => self::CITY,
                'country' => 'Cameroun',
                'address' => self::CITY,
                'status' => 'active',
                'verified_at' => now(),
            ],
        );
        Setting::set(self::SETTING, (string) $shop->id, 'string', 'import', "Boutique de réception des imports à Douala");

        return $shop;
    }

    /** Rattache tous les produits en gros à la boutique de réception. */
    public static function attachWholesaleProducts(Shop $hub): int
    {
        return Product::where('is_wholesale', true)
            ->where('shop_id', '!=', $hub->id)
            ->update(['shop_id' => $hub->id, 'user_id' => $hub->user_id]);
    }

    /**
     * Zone SOLEX de l'entrepôt, d'où part le prix SOLEX jusqu'au client. Null tant
     * que l'adresse ou la position de la boutique ne tombe dans aucune zone.
     */
    public static function deliveryZoneLabel(Shop $hub): ?string
    {
        $grid = DeliveryCityGrid::forCity($hub->city);
        $zone = $grid?->zoneOfQuarter($hub->quarter) ?? $grid?->zoneFor($hub->address);

        return $zone ? $grid->zoneLabel($zone, 0) : null;
    }
}
