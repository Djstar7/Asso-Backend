<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Models\Product;
use App\Models\Shop;
use App\Services\CommissionService;
use App\Support\LinkSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;

/**
 * Vitrine publique des liens partagés : la page vue par qui n'a pas l'application.
 *
 * Rendue côté serveur pour que WhatsApp et Facebook trouvent l'image, le nom et
 * le prix dans l'aperçu du lien. Elle ne compte pas dans les statistiques des
 * boutiques : ces robots passent ici à chaque partage.
 */
class PublicShareController extends Controller
{
    private const SHOP_PRODUCTS = 12;

    public function product(Request $request, string $id): Response
    {
        $this->applyLocale($request);

        $product = Product::openableInApp()
            ->with(['primaryImage', 'priceTiers', 'shop'])
            ->find($id);

        if (! $product) {
            return $this->unavailable($request, "/p/{$id}");
        }

        return $this->page($request, "/p/{$product->id}", [
            'kind' => 'product',
            'title' => (string) $product->name,
            'summary' => $this->summary($product->description),
            'description' => (string) $product->description,
            'image' => media_url($product->primaryImage?->image_path),
            'price' => $this->priceLabel($product),
            'shopName' => $product->shop?->name,
            'shopUrl' => $product->shop ? route('share.shop', $product->shop->id) : null,
            'products' => collect(),
        ]);
    }

    public function shop(Request $request, string $id): Response
    {
        $this->applyLocale($request);

        $shop = Shop::publiclyVisible()->find($id);

        if (! $shop) {
            return $this->unavailable($request, "/b/{$id}");
        }

        $products = Product::openableInApp()
            ->where('shop_id', $shop->id)
            ->with(['primaryImage', 'priceTiers', 'shop'])
            ->latest('id')
            ->limit(self::SHOP_PRODUCTS)
            ->get()
            ->map(fn (Product $product) => [
                'name' => (string) $product->name,
                'image' => media_url($product->primaryImage?->image_path),
                'price' => $this->priceLabel($product),
                'url' => route('share.product', $product->id),
            ]);

        return $this->page($request, "/b/{$shop->id}", [
            'kind' => 'shop',
            'title' => (string) $shop->name,
            'summary' => $this->summary($shop->description),
            'description' => (string) $shop->description,
            'image' => $shop->logo_url ?: $products->first()['image'] ?? null,
            'price' => null,
            'shopName' => $shop->city,
            'shopUrl' => null,
            'products' => $products,
        ]);
    }

    public function assetLinks(): JsonResponse
    {
        return response()->json(LinkSettings::assetLinks());
    }

    /** iOS refuse le fichier s'il n'est pas servi en application/json. */
    public function appleAssociation(): JsonResponse
    {
        return response()->json(LinkSettings::appleAssociation());
    }

    private function applyLocale(Request $request): void
    {
        $forced = $request->query('lang');

        App::setLocale(in_array($forced, SetLocale::SUPPORTED, true)
            ? $forced
            : ($request->getPreferredLanguage(SetLocale::SUPPORTED) ?? 'fr'));
    }

    /** Page d'un contenu retiré : 404 pour les robots, mais l'application reste proposée. */
    private function unavailable(Request $request, string $path): Response
    {
        return $this->page($request, $path, [
            'kind' => 'unavailable',
            'title' => __('share.unavailable_title'),
            'summary' => __('share.unavailable_message'),
            'description' => '',
            'image' => null,
            'price' => null,
            'shopName' => null,
            'shopUrl' => null,
            'products' => collect(),
        ], 404);
    }

    private function page(Request $request, string $path, array $content, int $status = 200): Response
    {
        return response()
            ->view('public.share', $content + $this->appLinks($request, $path), $status)
            ->header('Cache-Control', $status === 200 ? 'private, max-age=300' : 'no-store')
            ->header('Vary', 'Accept-Language')
            ->header('X-Frame-Options', 'DENY');
    }

    /** Boutons d'ouverture : un lien universel cliqué depuis son propre domaine n'ouvre pas l'application. */
    private function appLinks(Request $request, string $path): array
    {
        $agent = (string) $request->userAgent();
        $platform = match (true) {
            (bool) preg_match('/android/i', $agent) => 'android',
            (bool) preg_match('/iphone|ipad|ipod/i', $agent) => 'ios',
            default => 'desktop',
        };

        $host = $request->getHost();
        $androidStore = (string) config('app.android_store_url');
        $iosStore = (string) config('app.ios_store_url');

        $openUrl = match ($platform) {
            'android' => "intent://{$host}{$path}#Intent;scheme=https;package=".config('app.android_package')
                .';S.browser_fallback_url='.rawurlencode($androidStore).';end',
            'ios' => config('app.mobile_scheme')."://{$host}{$path}",
            default => null,
        };

        return [
            'platform' => $platform,
            'openUrl' => $openUrl,
            'storeUrl' => $platform === 'ios' ? $iosStore : $androidStore,
            'androidStoreUrl' => $androidStore,
            'iosStoreUrl' => $iosStore,
            'iosAppId' => config('app.ios_app_id'),
            'canonicalUrl' => url($path),
        ];
    }

    private function summary(?string $text): string
    {
        return Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $text))), 180);
    }

    private function priceLabel(Product $product): ?string
    {
        $tier = $product->is_wholesale
            ? $product->priceTiers->where('is_active', true)->sortBy('unit_price')->first()
            : null;

        if ($tier) {
            return __('share.price_from', ['price' => $tier->toApi()['formatted_price']]);
        }

        return CommissionService::buyerPricing($product)['formatted_price'];
    }
}
