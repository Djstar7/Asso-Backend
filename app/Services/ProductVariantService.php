<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Point unique de vérité pour les variantes produit (admin, API vendeur, API catalogue).
 *
 * Une variante = une combinaison d'options (ex. Couleur: Rouge + Pointure: 42) avec son
 * propre stock. Les groupes d'options (`products.variant_options`) décrivent l'ordre
 * d'affichage et la pastille de couleur montrée au client.
 */
class ProductVariantService
{
    public const COLOR_OPTION_NAMES = ['couleur', 'couleurs', 'color', 'colour', 'coloris', 'teinte'];

    /** Pastilles par défaut quand l'équipe n'a pas choisi de couleur précise. */
    public const DEFAULT_COLOR_HEX = [
        'bleu marine' => '#1A237E', 'marine' => '#1A237E', 'navy' => '#1A237E',
        'bleu ciel' => '#81D4FA', 'turquoise' => '#1DE9B6', 'bordeaux' => '#7B1F2B',
        'rouge' => '#E53935', 'red' => '#E53935', 'bleu' => '#1E88E5', 'blue' => '#1E88E5',
        'vert' => '#43A047', 'green' => '#43A047', 'kaki' => '#6B6B3A', 'noir' => '#212121',
        'black' => '#212121', 'blanc' => '#FAFAFA', 'white' => '#FAFAFA', 'jaune' => '#FDD835',
        'yellow' => '#FDD835', 'orange' => '#FB8C00', 'rose' => '#EC407A', 'pink' => '#EC407A',
        'violet' => '#8E24AA', 'purple' => '#8E24AA', 'marron' => '#795548', 'brown' => '#795548',
        'gris' => '#9E9E9E', 'grey' => '#9E9E9E', 'gray' => '#9E9E9E', 'beige' => '#D7CCC8',
        'doré' => '#C9A227', 'dore' => '#C9A227', 'or' => '#C9A227', 'gold' => '#C9A227',
        'argent' => '#BDBDBD', 'silver' => '#BDBDBD', 'crème' => '#FFF8E1', 'creme' => '#FFF8E1',
    ];

    /** Règles de validation partagées par tous les points d'entrée. */
    public static function rules(): array
    {
        return [
            'variants' => 'nullable|array|max:200',
            'variants.*.attributes' => 'nullable',
            'variants.*.attribute_type' => 'nullable|string|max:100',
            'variants.*.attribute_value' => 'nullable|string|max:100',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.price_adjustment' => 'nullable|numeric',
            'variants.*.stock' => 'nullable|integer|min:0',
            // Poids propre à la déclinaison, en kg (null => poids du produit).
            'variants.*.weight' => 'nullable|numeric|min:0.001|max:100000',
            'variants.*.is_active' => 'nullable|boolean',
            'variant_options' => 'nullable',
        ];
    }

    public static function isColorOption(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), self::COLOR_OPTION_NAMES, true);
    }

    public static function guessHex(string $colorName): ?string
    {
        $value = mb_strtolower(trim($colorName));
        // Les libellés les plus longs d'abord : « bleu marine » avant « bleu ».
        $palette = self::DEFAULT_COLOR_HEX;
        uksort($palette, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach ($palette as $name => $hex) {
            if ($value === $name || str_contains($value, $name)) {
                return $hex;
            }
        }
        return null;
    }

    /**
     * Accepte les trois formats historiques :
     *  - tableau associatif ['Couleur' => 'Rouge', 'Taille' => 'M'] (mobile, admin)
     *  - chaîne "Couleur: Rouge; Taille: M" (ancien formulaire admin)
     *  - paire attribute_type / attribute_value (ancienne API)
     */
    public function normalizeAttributes(mixed $raw, ?string $type = null, ?string $value = null): array
    {
        $pairs = [];

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : $raw;
        }

        if (is_array($raw)) {
            foreach ($raw as $name => $val) {
                $pairs[] = [$name, $val];
            }
        } elseif (is_string($raw)) {
            foreach (preg_split('/[;\n]+/', $raw) as $item) {
                [$name, $val] = array_pad(explode(':', $item, 2), 2, null);
                $pairs[] = [$name, $val];
            }
        }

        if ($pairs === [] && filled($type) && filled($value)) {
            $pairs[] = [$type, $value];
        }

        $attributes = [];
        foreach ($pairs as [$name, $val]) {
            $name = mb_substr(trim((string) $name), 0, 100);
            $val = mb_substr(trim((string) $val), 0, 100);
            if ($name !== '' && $val !== '' && !is_numeric($name)) {
                $attributes[$this->ucfirst($name)] = $val;
            }
        }

        return $attributes;
    }

    /**
     * Remplace les variantes du produit en conservant les identifiants des combinaisons
     * déjà existantes (les commandes en cours gardent ainsi leur lien vers la variante).
     */
    public function sync(Product $product, array $variants, array|string|null $options = null): void
    {
        $rows = $this->normalizeRows($variants);

        DB::transaction(function () use ($product, $rows, $options) {
            $existing = $product->variants()->get()->keyBy(fn (ProductVariant $v) => $this->signature($v->attributes ?? []));
            // Attention : Eloquent\Collection::only() filtre par clé primaire, d'où le filter().
            $keptIds = $existing->filter(fn ($variant, $signature) => $rows->has($signature))->pluck('id');

            $product->variants()->whereNotIn('id', $keptIds)->delete();
            // Libère les SKU pour éviter une collision d'unicité quand une référence change de ligne.
            $product->variants()->whereIn('id', $keptIds)->update(['sku' => null]);

            foreach ($rows->values() as $index => $row) {
                $payload = [
                    'attributes' => $row['attributes'],
                    'sku' => $row['sku'],
                    'price_adjustment' => $row['price_adjustment'],
                    'stock' => $row['stock'],
                    'weight' => $row['weight'],
                    'is_active' => $row['is_active'],
                    'sort_order' => $index,
                ];

                $current = $existing->get($row['signature']);
                $current ? $current->update($payload) : $product->variants()->create($payload);
            }

            $updates = ['variant_options' => $this->buildOptions($rows->pluck('attributes')->all(), $options)];
            if ($rows->isNotEmpty()) {
                $updates['stock'] = $rows->sum('stock');
            }
            $product->updateQuietly($updates);
        });

        $product->unsetRelation('variants');
    }

    /**
     * Groupes d'options présentés au client, calculés à partir des variantes actives
     * (une valeur sans variante active ne doit jamais être proposée).
     */
    public function presentOptions(Product $product): array
    {
        $variants = $product->relationLoaded('variants') ? $product->variants : $product->variants()->get();
        $active = $variants->where('is_active', true);

        return $this->buildOptions(
            $active->pluck('attributes')->filter()->all(),
            $product->variant_options,
        ) ?? [];
    }

    public function buildOptions(array $attributeSets, array|string|null $provided): ?array
    {
        if ($attributeSets === []) {
            return null;
        }

        $provided = $this->decodeOptions($provided);
        $groups = [];

        // 1. Ordre défini par l'équipe (groupes et valeurs), 2. ordre d'apparition.
        foreach ($provided as $group) {
            $groups[$group['name']] ??= ['name' => $group['name'], 'type' => $group['type'], 'values' => []];
            foreach ($group['values'] as $value) {
                $groups[$group['name']]['values'][$value['value']] = $value['hex'];
            }
        }

        $used = [];
        foreach ($attributeSets as $attributes) {
            foreach ((array) $attributes as $name => $value) {
                $value = (string) $value;
                $groups[$name] ??= ['name' => $name, 'type' => self::isColorOption($name) ? 'color' : 'text', 'values' => []];
                $groups[$name]['values'][$value] ??= null;
                $used[$name][$value] = true;
            }
        }

        $result = [];
        foreach ($groups as $name => $group) {
            $isColor = $group['type'] === 'color' || self::isColorOption($name);
            $values = [];
            foreach ($group['values'] as $value => $hex) {
                if (!isset($used[$name][(string) $value])) {
                    continue;
                }
                $entry = ['value' => (string) $value];
                if ($isColor) {
                    $entry['hex'] = $hex ?? self::guessHex((string) $value);
                }
                $values[] = $entry;
            }
            if ($values !== []) {
                $result[] = ['name' => $name, 'type' => $isColor ? 'color' : 'text', 'values' => $values];
            }
        }

        return $result === [] ? null : $result;
    }

    /**
     * Représentation API d'une variante (prix dans la devise du produit + en XAF).
     *
     * $commissionRate (%) : prix PUBLICS majorés de la commission ASSO (vues acheteur).
     * null = prix vendeur bruts (espace vendeur, catalogue import ASSO).
     */
    public function presentVariant(ProductVariant $variant, Product $product, ?float $commissionRate = null): array
    {
        $adjustment = (float) $variant->price_adjustment;
        $basePrice = (float) $product->price;
        $baseXaf = $product->basePriceXaf();
        // Taux devise du produit → XAF déduit du prix de base ; null si inconnu (on
        // n'invente pas un taux de 1 entre deux devises différentes).
        $rate = $baseXaf === null ? null : ($basePrice > 0 ? $baseXaf / $basePrice : 1.0);

        $price = $basePrice + $adjustment;
        $priceXaf = $rate === null ? null : round($baseXaf + $adjustment * $rate, 2);
        if ($commissionRate !== null) {
            $currency = (string) ($product->currency ?? 'XAF');
            $publicBase = CommissionService::markup($basePrice, $commissionRate, $currency);
            $price = CommissionService::markup($basePrice + $adjustment, $commissionRate, $currency);
            $priceXaf = $priceXaf === null ? null : CommissionService::markup($priceXaf, $commissionRate, 'XAF');
            // Ajustement exprimé par rapport au prix public de base.
            $adjustment = round($price - $publicBase, 2);
        }

        return [
            'id' => $variant->id,
            'sku' => $variant->sku,
            'attributes' => $variant->attributes,
            'price_adjustment' => $adjustment,
            'price_adjustment_xaf' => $rate === null ? null : round($adjustment * $rate, 2),
            'price' => $price,
            'price_xaf' => $priceXaf,
            'stock' => $variant->stock,
            // Poids de la déclinaison ; `weight` retombe sur celui du produit
            // pour que le client n'ait pas à gérer le cas nul.
            'weight' => $variant->effectiveWeight(),
            'own_weight' => $variant->weight !== null ? (float) $variant->weight : null,
            'is_active' => (bool) $variant->is_active,
        ];
    }

    private function normalizeRows(array $variants): Collection
    {
        $rows = collect();

        foreach (array_values($variants) as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $attributes = $this->normalizeAttributes(
                $variant['attributes'] ?? null,
                $variant['attribute_type'] ?? null,
                $variant['attribute_value'] ?? null,
            );
            if ($attributes === []) {
                continue;
            }

            $signature = $this->signature($attributes);
            $sku = filled($variant['sku'] ?? null) ? trim((string) $variant['sku']) : null;
            // Un même SKU sur deux lignes violerait l'index unique : on garde la première.
            if ($sku !== null && $rows->contains(fn ($row) => $row['sku'] === $sku)) {
                $sku = null;
            }

            $rows->put($signature, [
                'signature' => $signature,
                'attributes' => $attributes,
                'sku' => $sku,
                'price_adjustment' => is_numeric($variant['price_adjustment'] ?? null) ? (float) $variant['price_adjustment'] : 0,
                'stock' => max(0, (int) ($variant['stock'] ?? 0)),
                'weight' => is_numeric($variant['weight'] ?? null) && (float) $variant['weight'] > 0
                    ? (float) $variant['weight']
                    : null,
                'is_active' => filter_var($variant['is_active'] ?? true, FILTER_VALIDATE_BOOL),
            ]);
        }

        return $rows;
    }

    private function decodeOptions(array|string|null $options): array
    {
        if (is_string($options)) {
            $options = json_decode($options, true);
        }
        if (!is_array($options)) {
            return [];
        }

        $groups = [];
        foreach ($options as $group) {
            $name = $this->ucfirst(mb_substr(trim((string) ($group['name'] ?? '')), 0, 100));
            if ($name === '' || !is_array($group['values'] ?? null)) {
                continue;
            }
            $isColor = ($group['type'] ?? null) === 'color' || self::isColorOption($name);
            $values = [];
            foreach ($group['values'] as $value) {
                $label = trim((string) (is_array($value) ? ($value['value'] ?? '') : $value));
                if ($label === '') {
                    continue;
                }
                $hex = is_array($value) ? ($value['hex'] ?? null) : null;
                $values[] = [
                    'value' => mb_substr($label, 0, 100),
                    'hex' => $isColor && is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : null,
                ];
            }
            $groups[] = ['name' => $name, 'type' => $isColor ? 'color' : 'text', 'values' => $values];
        }

        return $groups;
    }

    private function signature(array $attributes): string
    {
        $normalized = [];
        foreach ($attributes as $name => $value) {
            $normalized[mb_strtolower(trim((string) $name))] = mb_strtolower(trim((string) $value));
        }
        ksort($normalized);

        return json_encode($normalized, JSON_UNESCAPED_UNICODE);
    }

    private function ucfirst(string $value): string
    {
        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1);
    }
}
