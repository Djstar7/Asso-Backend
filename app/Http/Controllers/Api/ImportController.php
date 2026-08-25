<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PaymentMethodService;
use Illuminate\Http\Request;

/**
 * Module ASSO CHINA / DUBAÏ / TURQUIE — catalogue de commande EN GROS (import).
 *
 * Chaque pays a sa rubrique. Les produits « gros » exposent leurs paliers de prix
 * (conditionnement + cota) et les options d'expédition internationale.
 */
class ImportController extends Controller
{
    /**
     * Catalogue gros d'un pays d'import.
     * GET /v1/import/{code}/products
     */
    public function products(string $code)
    {
        $code = strtoupper($code);
        $country = ImportCountry::where('code', $code)->where('is_active', true)->firstOrFail();

        $products = Product::query()
            ->where('is_wholesale', true)
            ->where('origin_country', $code)
            ->where('status', 'active')
            ->with(['priceTiers', 'primaryImage'])
            ->latest()
            ->get()
            ->map(fn (Product $p) => $this->serializeProduct($p));

        return response()->json([
            'success' => true,
            'country' => ['code' => $country->code, 'name' => $country->name, 'flag' => $country->flag],
            'products' => $products,
            'shipping_options' => ImportShippingOption::activeForCountry($code)->get()->map->toApi(),
        ]);
    }

    /**
     * Détail d'un produit gros (avec paliers).
     * GET /v1/import/products/{id}
     */
    public function show(int $id)
    {
        $product = Product::where('is_wholesale', true)
            ->with(['priceTiers', 'images'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'product' => $this->serializeProduct($product, true),
            'shipping_options' => ImportShippingOption::activeForCountry($product->origin_country)->get()->map->toApi(),
        ]);
    }

    /**
     * Options d'expédition internationale d'un pays.
     * GET /v1/import/{code}/shipping
     */
    public function shipping(string $code)
    {
        return response()->json([
            'success' => true,
            'shipping_options' => ImportShippingOption::activeForCountry(strtoupper($code))->get()->map->toApi(),
        ]);
    }

    /**
     * Créer une commande EN GROS.
     * POST /v1/import/orders  (auth)
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.price_tier_id' => 'required|exists:product_price_tiers,id',
            'items.*.quantity' => 'required|integer|min:1',
            'shipping_option_id' => 'required|exists:import_shipping_options,id',
            'shipping_weight_kg' => 'nullable|numeric|min:0',
            'shipping_cbm' => 'nullable|numeric|min:0',
            'payment_mode' => 'nullable|in:wallet,kpay_direct,stripe_direct',
            'provider' => 'required_if:payment_mode,kpay_direct|string',
            'phone_number' => 'required_if:payment_mode,kpay_direct|string',
            'delivery_address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $paymentMode = $validated['payment_mode'] ?? 'kpay_direct';

        // Garde-fou : le rail carte (Stripe natif) n'est proposé que s'il est fonctionnel.
        if ($paymentMode === 'stripe_direct' && !PaymentMethodService::isEnabled('stripe')) {
            return response()->json([
                'success' => false,
                'message' => "Le paiement par carte (Stripe) n'est pas disponible pour le moment.",
            ], 422);
        }

        try {
            $order = app(OrderService::class)->createWholesaleOrder(
                client: $request->user(),
                items: $validated['items'],
                shippingOptionId: (int) $validated['shipping_option_id'],
                shippingWeightKg: (float) ($validated['shipping_weight_kg'] ?? 0),
                shippingCbm: (float) ($validated['shipping_cbm'] ?? 0),
                deliveryAddress: $validated['delivery_address'] ?? null,
                paymentMode: $paymentMode,
                kpayProvider: $validated['provider'] ?? null,
                kpayPhone: $validated['phone_number'] ?? null,
                notes: $validated['notes'] ?? null,
            );

            return response()->json([
                'success' => true,
                'message' => 'Commande en gros créée.',
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_reference' => $order->payment_reference,
                // Carte native (stripe_direct) : confirmation via Payment Sheet, puis polling.
                'client_secret' => $paymentMode === 'stripe_direct' ? ($order->client_secret ?? null) : null,
                'payment_intent_id' => $paymentMode === 'stripe_direct' ? ($order->payment_intent_id ?? null) : null,
                'publishable_key' => $paymentMode === 'stripe_direct' ? ($order->stripe_publishable_key ?? null) : null,
            ], 201);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    /** Sérialisation d'un produit gros avec ses paliers et sa quantité minimale. */
    private function serializeProduct(Product $p, bool $full = false): array
    {
        $tiers = $p->relationLoaded('priceTiers') ? $p->priceTiers : collect();
        // Quantité minimale « effective » = plus petit cota parmi les paliers (repli sur le champ produit).
        $minFromTiers = $tiers->min('min_quantity');

        $data = [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'origin_country' => $p->origin_country,
            'currency' => $p->currency,
            'min_order_quantity' => $minFromTiers ?? $p->min_order_quantity,
            'price_tiers' => $tiers->map->toApi()->values(),
            'image' => $p->primaryImage?->image_path,
        ];

        if ($full) {
            $data['images'] = ($p->relationLoaded('images') ? $p->images : collect())
                ->map(fn ($i) => $i->image_path)->values();
        }

        return $data;
    }
}
