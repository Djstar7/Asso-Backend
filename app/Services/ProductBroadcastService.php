<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBoost;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Log;

/**
 * Annonce à TOUS les utilisateurs les produits publiés et les produits sponsorisés.
 *
 * Un envoi par topic Firebase (`all_users_fr`, `all_users_en`, plus `all_users`
 * en français pour les anciennes versions de l'app), et non un envoi par token :
 * l'app s'abonne elle-même (connexion, inscription, accueil, mode invité). Les
 * invités, qui n'ont aucun token en base, sont donc touchés eux aussi.
 *
 * Aucune ligne n'est écrite dans `notifications` : ce serait une insertion par
 * utilisateur à chaque publication, pour une annonce qui n'est pas personnelle.
 *
 * Un échec d'envoi est journalisé sans jamais remonter : ni la publication du
 * produit ni l'activation d'une campagne payée ne doivent en dépendre.
 */
class ProductBroadcastService
{
    public const TOPIC = 'all_users';

    public function newProduct(Product $product): void
    {
        $this->broadcast('new_product', $product);
    }

    public function sponsoredProduct(ProductBoost $boost): void
    {
        $product = $boost->product;
        if (!$product || $product->status !== 'active') {
            // Produit supprimé ou masqué entre l'achat et l'activation : rien à montrer.
            return;
        }

        $this->broadcast('sponsored_product', $product, ['product_boost_id' => (string) $boost->id]);
    }

    /** Titre et texte dans chaque langue, avec les noms traduits par le vendeur s'il l'a fait. */
    private function texts(string $type, Product $product): array
    {
        $texts = [];
        foreach (SetLocale::SUPPORTED as $locale) {
            $shop = $product->shop?->getTranslation('name', $locale) ?? $product->shop?->getTranslation('name', 'fr');
            $texts[$locale] = [
                __("notifications.{$type}.title", [], $locale),
                __("notifications.{$type}.body", [
                    'shop' => $shop ?? __('notifications.default_shop_name', [], $locale),
                    'product' => $product->getTranslation('name', $locale) ?? $product->getTranslation('name', 'fr'),
                ], $locale),
            ];
        }

        return $texts;
    }

    private function broadcast(string $type, Product $product, array $extra = []): void
    {
        try {
            $data = [
                'type' => $type,
                'product_id' => (string) $product->id,
                'product_name' => $product->getTranslation('name', 'fr'),
                'shop_id' => (string) ($product->shop_id ?? ''),
                'shop_name' => $product->shop?->getTranslation('name', 'fr') ?? '',
                // Prix public (commission ASSO incluse), celui affiché dans l'app.
                'price' => (string) CommissionService::buyerPrice($product),
                'category_id' => (string) ($product->category_id ?? ''),
            ] + $extra;

            $result = app(FirebaseMessagingService::class)->sendToTopicsLocalized(
                $this->texts($type, $product),
                FirebaseMessagingService::stringifyData($data),
            );

            Log::info("[ProductBroadcast] {$type} produit {$product->id}", [
                'success' => $result['success'] ?? false,
                'message' => $result['message'] ?? null,
            ]);
        } catch (\Throwable $e) {
            Log::error("[ProductBroadcast] Échec {$type} produit {$product->id}: " . $e->getMessage());
        }
    }
}
