<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportCountry;
use App\Models\ImportShippingOption;
use App\Models\Product;
use App\Services\OrderService;
use App\Services\PaymentMethodService;
use App\Services\ExchangeRateService;
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
    public function products(Request $request, string $code)
    {
        $code = strtoupper($code);
        $targetCurrency = $this->targetCurrency($request);
        $country = ImportCountry::where('code', $code)->where('is_active', true)->firstOrFail();

        $products = Product::query()
            ->where('is_wholesale', true)
            ->where('origin_country', $code)
            ->where('status', 'active')
            ->with(['priceTiers', 'primaryImage'])
            ->latest()
            ->get()
            ->map(fn (Product $p) => $this->serializeProduct($p, false, $targetCurrency));

        $shippingOptions = ImportShippingOption::activeForCountry($code)
            ->get()
            ->map(fn (ImportShippingOption $option) => $this->serializeShippingOption($option, $targetCurrency));

        return response()->json([
            'success' => true,
            'country' => ['code' => $country->code, 'name' => $country->name, 'flag' => $country->flag],
            'products' => $products,
            'currency' => $targetCurrency,
            'shipping_options' => $shippingOptions,
        ]);
    }

    /**
     * Détail d'un produit gros (avec paliers).
     * GET /v1/import/products/{id}
     */
    public function show(Request $request, int $id)
    {
        $product = Product::where('is_wholesale', true)
            ->with(['priceTiers', 'images'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'product' => $this->serializeProduct($product, true, $this->targetCurrency($request)),
            'shipping_options' => ImportShippingOption::activeForCountry($product->origin_country)
                ->get()->map(fn (ImportShippingOption $option) => $this->serializeShippingOption($option, $this->targetCurrency($request))),
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
    private function serializeProduct(Product $p, bool $full = false, string $targetCurrency = 'XAF'): array
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
            // Le poids est renseigné par l'équipe/le vendeur, jamais par le client.
            'unit_weight_kg' => is_numeric($p->weight) ? (float) $p->weight : null,
            'price_tiers' => $tiers->map(fn ($tier) => $this->serializeTier($tier, $targetCurrency))->values(),
            'image' => $this->imageUrl($p->primaryImage?->image_path),
        ];

        if ($full) {
            $data['images'] = ($p->relationLoaded('images') ? $p->images : collect())
                ->map(fn ($i) => $this->imageUrl($i->image_path))->values();
        }

        return $data;
    }

    private function targetCurrency(Request $request): string
    {
        $currency = strtoupper(trim((string) $request->query('currency', 'XAF')));
        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'XAF';
    }

    private function convertAmount(float $amount, string $from, string $to): ?float
    {
        $result = ExchangeRateService::convert(strtoupper($from), strtoupper($to), $amount);
        return !empty($result['success']) ? (float) $result['amount'] : null;
    }

    private function serializeTier($tier, string $targetCurrency): array
    {
        $sourceCurrency = strtoupper($tier->currency ?? 'XAF');
        $sourceAmount = (float) $tier->unit_price;
        $amountXaf = $this->convertAmount($sourceAmount, $sourceCurrency, 'XAF');
        $displayAmount = $this->convertAmount($sourceAmount, $sourceCurrency, $targetCurrency);

        // Si la devise choisie est momentanément indisponible, on expose le prix
        // d'origine au lieu d'afficher un montant inventé.
        $effectiveCurrency = $displayAmount === null ? $sourceCurrency : $targetCurrency;
        $effectiveAmount = $displayAmount ?? $sourceAmount;

        return array_merge($tier->toApi(), [
            'unit_price' => $effectiveAmount,
            'unit_price_xaf' => $amountXaf ?? ($sourceCurrency === 'XAF' ? $sourceAmount : 0),
            'currency' => $effectiveCurrency,
            'formatted_price' => number_format($effectiveAmount, 2, ',', ' ') . ' ' . $effectiveCurrency,
        ]);
    }

    private function serializeShippingOption(ImportShippingOption $option, string $targetCurrency): array
    {
        $data = $option->toApi();
        $sourceCurrency = strtoupper($option->currency ?? 'XAF');
        $sourceAmount = (float) $option->rate_amount;
        $amountXaf = $this->convertAmount($sourceAmount, $sourceCurrency, 'XAF');
        $displayAmount = $this->convertAmount($sourceAmount, $sourceCurrency, $targetCurrency);
        $effectiveCurrency = $displayAmount === null ? $sourceCurrency : $targetCurrency;
        $effectiveAmount = $displayAmount ?? $sourceAmount;
        $suffix = match ($option->rate_type) { 'per_kg' => ' / kg', 'per_cbm' => ' / CBM', default => '' };

        return array_merge($data, [
            'rate_amount' => $effectiveAmount,
            'rate_amount_xaf' => $amountXaf ?? ($sourceCurrency === 'XAF' ? $sourceAmount : 0),
            'currency' => $effectiveCurrency,
            'formatted_rate' => number_format($effectiveAmount, 2, ',', ' ') . ' ' . $effectiveCurrency . $suffix,
        ]);
    }

    private function imageUrl(?string $path): ?string
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) return $path;
        return asset('storage/' . ltrim($path, '/'));
    }
}
