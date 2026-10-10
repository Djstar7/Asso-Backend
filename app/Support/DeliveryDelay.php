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
 */
class DeliveryDelay
{
    public const MIN_DAYS = 1;

    /** Garde-fou technique (≈ 38 ans), pas une limite métier. */
    public const MAX_DAYS = 9999;

    /** Délai par défaut tant qu'ASSO n'en a pas réglé un. */
    public const FALLBACK_MAX_DAYS = 20;

    public const DEFAULT_MIN_KEY = 'delivery_default_days_min';

    public const DEFAULT_MAX_KEY = 'delivery_default_days_max';

    /** Règles de validation communes (vendeur, admin, catégories). */
    public static function rules(): array
    {
        return [
            'delivery_days_min' => ['sometimes', 'nullable', 'integer', 'between:'.self::MIN_DAYS.','.self::MAX_DAYS],
            'delivery_days_max' => ['sometimes', 'nullable', 'integer', 'between:'.self::MIN_DAYS.','.self::MAX_DAYS, 'gte:delivery_days_min'],
        ];
    }

    /** @return array{min: int, max: int} */
    public static function defaults(): array
    {
        return self::normalize(
            Setting::get(self::DEFAULT_MIN_KEY, self::MIN_DAYS),
            Setting::get(self::DEFAULT_MAX_KEY, self::FALLBACK_MAX_DAYS),
        );
    }

    public static function setDefaults(int $min, int $max): void
    {
        ['min' => $min, 'max' => $max] = self::normalize($min, $max);

        Setting::set(self::DEFAULT_MIN_KEY, $min, 'integer', 'delivery', 'Délai de livraison par défaut — minimum (jours ouvrables)');
        Setting::set(self::DEFAULT_MAX_KEY, $max, 'integer', 'delivery', 'Délai de livraison par défaut — maximum (jours ouvrables)');
    }

    /**
     * Délai d'une catégorie (ou le défaut global si elle n'en a pas).
     *
     * @return array{min: int, max: int, source: string}
     */
    public static function forCategory(?Category $category): array
    {
        if ($category && ($category->delivery_days_min || $category->delivery_days_max)) {
            return self::normalize($category->delivery_days_min, $category->delivery_days_max) + ['source' => 'category'];
        }

        return self::defaults() + ['source' => 'default'];
    }

    /** @return array{min: int, max: int, source: string} */
    public static function forProduct(Product $product): array
    {
        if ($product->delivery_days_min || $product->delivery_days_max) {
            return self::normalize($product->delivery_days_min, $product->delivery_days_max) + ['source' => 'product'];
        }

        return self::forCategory($product->category);
    }

    /**
     * Délai d'une commande : le plus long des articles commandés.
     *
     * @param  iterable<Product>  $products
     * @return array{min: int, max: int}
     */
    public static function forProducts(iterable $products): array
    {
        $min = $max = 0;
        foreach ($products as $product) {
            $delay = self::forProduct($product);
            $min = max($min, $delay['min']);
            $max = max($max, $delay['max']);
        }

        return $max > 0 ? ['min' => $min, 'max' => $max] : self::defaults();
    }

    /**
     * Fourchette de dates estimée depuis $from.
     *
     * @return array{delivery_days_min: int, delivery_days_max: int, estimated_delivery_from: string, estimated_delivery_to: string}
     */
    public static function estimate(array $delay, ?CarbonInterface $from = null): array
    {
        $from = Carbon::instance($from ?? now());

        return [
            'delivery_days_min' => $delay['min'],
            'delivery_days_max' => $delay['max'],
            'estimated_delivery_from' => self::addBusinessDays($from, $delay['min'])->toDateString(),
            'estimated_delivery_to' => self::addBusinessDays($from, $delay['max'])->toDateString(),
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

    /** @return array{min: int, max: int} */
    private static function normalize(mixed $min, mixed $max): array
    {
        $min = (int) ($min ?: $max ?: self::MIN_DAYS);
        $max = (int) ($max ?: $min);
        $min = min(max($min, self::MIN_DAYS), self::MAX_DAYS);
        $max = min(max($max, $min), self::MAX_DAYS);

        return ['min' => $min, 'max' => $max];
    }
}
