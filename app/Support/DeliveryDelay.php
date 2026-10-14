<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Délai de livraison annoncé au client, en jours ouvrables (lundi → vendredi).
 *
 * Ordre de résolution : délai du produit → délai de sa catégorie → défaut global
 * (réglé dans le Dashboard, page Partenaires de livraison). Au moins 1 jour, sans maximum
 * métier (décision ASSO du 2026-10-10) ; seul un garde-fou technique écarte les saisies aberrantes.
 * Le maximum est facultatif : vide = délai ouvert (« 3 jours minimum »), max vaut alors null.
 */
class DeliveryDelay
{
    public const MIN_DAYS = 1;

    /** Garde-fou technique (≈ 38 ans), pas une limite métier. */
    public const MAX_DAYS = 9999;

    public const DEFAULT_MIN_KEY = 'delivery_default_days_min';

    public const DEFAULT_MAX_KEY = 'delivery_default_days_max';

    /** Règles de validation communes (vendeur, admin, catégories). */
    public static function rules(): array
    {
        return [
            // min / max séparés : chacun a son message traduit (validation.custom.delivery_days_*).
            'delivery_days_min' => ['sometimes', 'nullable', 'integer', 'min:'.self::MIN_DAYS, 'max:'.self::MAX_DAYS],
            'delivery_days_max' => ['sometimes', 'nullable', 'integer', 'min:'.self::MIN_DAYS, 'max:'.self::MAX_DAYS, 'gte:delivery_days_min'],
        ];
    }

    /**
     * Défaut global ; sans maximum tant qu'ASSO n'en a pas réglé un.
     *
     * @return array{min: int, max: ?int}
     */
    public static function defaults(): array
    {
        return self::normalize(
            Setting::get(self::DEFAULT_MIN_KEY, self::MIN_DAYS),
            Setting::get(self::DEFAULT_MAX_KEY),
        );
    }

    public static function setDefaults(int $min, ?int $max): void
    {
        ['min' => $min, 'max' => $max] = self::normalize($min, $max);

        Setting::set(self::DEFAULT_MIN_KEY, $min, 'integer', 'delivery', 'Délai de livraison par défaut — minimum (jours ouvrables)');
        Setting::set(self::DEFAULT_MAX_KEY, $max, 'integer', 'delivery', 'Délai de livraison par défaut — maximum (jours ouvrables, 0 = sans maximum)');
    }

    /**
     * Délai d'une catégorie (ou le défaut global si elle n'en a pas).
     *
     * @return array{min: int, max: ?int, source: string}
     */
    public static function forCategory(?Category $category): array
    {
        if ($category && ($category->delivery_days_min || $category->delivery_days_max)) {
            return self::normalize($category->delivery_days_min, $category->delivery_days_max) + ['source' => 'category'];
        }

        return self::defaults() + ['source' => 'default'];
    }

    /** @return array{min: int, max: ?int, source: string} */
    public static function forProduct(Product $product): array
    {
        if ($product->delivery_days_min || $product->delivery_days_max) {
            return self::normalize($product->delivery_days_min, $product->delivery_days_max) + ['source' => 'product'];
        }

        return self::forCategory($product->category);
    }

    /**
     * Délai d'une commande : le plus long des articles commandés (ouvert si l'un d'eux l'est).
     *
     * @param  iterable<Product>  $products
     * @return array{min: int, max: ?int}
     */
    public static function forProducts(iterable $products): array
    {
        $min = $max = 0;
        $open = false;
        foreach ($products as $product) {
            $delay = self::forProduct($product);
            $min = max($min, $delay['min']);
            $max = max($max, $delay['max'] ?? 0);
            $open = $open || $delay['max'] === null;
        }

        if ($min === 0) {
            return self::defaults();
        }

        return ['min' => $min, 'max' => $open ? null : $max];
    }

    /**
     * Fourchette de dates estimée depuis $from (sans date de fin si le délai est ouvert).
     *
     * @return array{delivery_days_min: int, delivery_days_max: ?int, estimated_delivery_from: string, estimated_delivery_to: ?string}
     */
    public static function estimate(array $delay, ?CarbonInterface $from = null): array
    {
        $from = Carbon::instance($from ?? now());

        return [
            'delivery_days_min' => $delay['min'],
            'delivery_days_max' => $delay['max'],
            'estimated_delivery_from' => self::addBusinessDays($from, $delay['min'])->toDateString(),
            'estimated_delivery_to' => $delay['max'] === null ? null : self::addBusinessDays($from, $delay['max'])->toDateString(),
        ];
    }

    /** Ajoute $days jours ouvrables (samedi et dimanche sautés ; jours fériés non gérés). */
    public static function addBusinessDays(CarbonInterface $from, int $days): Carbon
    {
        $date = Carbon::instance($from)->startOfDay();
        while ($days > 0) {
            $date->addDay();
            if (! $date->isWeekend()) {
                $days--;
            }
        }

        return $date;
    }

    /** « 3 à 7 j », « 5 j » ou « 3 j minimum » pour un délai ouvert (Dashboard). */
    public static function label(array $delay): string
    {
        return match (true) {
            $delay['max'] === null => "{$delay['min']} j minimum",
            $delay['min'] === $delay['max'] => "{$delay['min']} j",
            default => "{$delay['min']} à {$delay['max']} j",
        };
    }

    /** Maximum vide (ou 0) = pas de maximum. @return array{min: int, max: ?int} */
    private static function normalize(mixed $min, mixed $max): array
    {
        $min = min(max((int) ($min ?: $max ?: self::MIN_DAYS), self::MIN_DAYS), self::MAX_DAYS);
        $max = $max ? min(max((int) $max, $min), self::MAX_DAYS) : null;

        return ['min' => $min, 'max' => $max];
    }
}
