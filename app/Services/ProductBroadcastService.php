<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductBoost;
use Illuminate\Support\Facades\Log;

/**
 * Annonce à TOUS les utilisateurs les produits publiés et les produits sponsorisés.
 *
 * Un seul envoi sur le topic Firebase `all_users`, et non un envoi par token :
 * les appareils y sont abonnés côté app (connexion, inscription, accueil, mode
 * invité) et côté serveur (DeviceTokenObserver). Les invités, qui n'ont aucun
 * token en base, sont donc touchés eux aussi.
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
        $shopName = $product->shop?->name ?? 'Une boutique';

        $this->broadcast(
            'Nouveau produit disponible',
            "{$shopName} a publié : {$product->name}",
            'new_product',
            $product,
        );
    }

    public function sponsoredProduct(ProductBoost $boost): void
    {
        $product = $boost->product;
        if (!$product || $product->status !== 'active') {
            // Produit supprimé ou masqué entre l'achat et l'activation : rien à montrer.
            return;
        }

        $shopName = $product->shop?->name ?? 'Une boutique';

        $this->broadcast(
            'Produit à la une',
            "{$shopName} vous présente : {$product->name}",
            'sponsored_product',
            $product,
            ['product_boost_id' => (string) $boost->id],
        );
    }

    private function broadcast(string $title, string $body, string $type, Product $product, array $extra = []): void
    {
        try {
            $data = [
                'type' => $type,
                'product_id' => (string) $product->id,
                'product_name' => $product->name,
                'shop_id' => (string) ($product->shop_id ?? ''),
                'shop_name' => $product->shop?->name ?? '',
                // Prix public (commission ASSO incluse), celui affiché dans l'app.
                'price' => (string) CommissionService::buyerPrice($product),
                'category_id' => (string) ($product->category_id ?? ''),
            ] + $extra;

            $result = app(FirebaseMessagingService::class)->sendToTopic(
                self::TOPIC,
                $title,
                $body,
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
