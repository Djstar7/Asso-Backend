<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — ASSO</title>
    <meta name="description" content="{{ $summary }}">
    <link rel="canonical" href="{{ $canonicalUrl }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    @if($kind === 'unavailable')
        <meta name="robots" content="noindex">
    @else
        <meta property="og:site_name" content="ASSO">
        <meta property="og:type" content="{{ $kind === 'product' ? 'product' : 'website' }}">
        <meta property="og:title" content="{{ $price ? $title.' — '.$price : $title }}">
        <meta property="og:description" content="{{ $summary }}">
        <meta property="og:url" content="{{ $canonicalUrl }}">
        @if($image)
            <meta property="og:image" content="{{ $image }}">
        @endif
        <meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
    @endif
    @if($platform === 'ios' && $iosAppId)
        <meta name="apple-itunes-app" content="app-id={{ $iosAppId }}, app-argument={{ $canonicalUrl }}">
    @endif
    <style>
        :root { --bg: #f6f6f7; --card: #ffffff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --accent: #ff5722; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #18181b; --card: #27272a; --text: #f4f4f5; --muted: #a1a1aa; --border: #3f3f46; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
               font: 16px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        a { color: inherit; }
        header { display: flex; align-items: center; gap: 10px; padding: 12px 16px; background: var(--card); border-bottom: 1px solid var(--border); }
        header img { width: 32px; height: 32px; border-radius: 8px; }
        header strong { font-size: 1.05rem; }
        main { max-width: 640px; margin: 0 auto; padding: 16px 16px 132px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 16px; overflow: hidden; }
        .cover { display: block; width: 100%; max-height: 420px; object-fit: cover; background: var(--border); }
        .body { padding: 16px; }
        h1 { font-size: 1.35rem; line-height: 1.3; margin: 0 0 6px; overflow-wrap: anywhere; }
        .price { color: var(--accent); font-size: 1.25rem; font-weight: 700; margin: 0 0 8px; }
        .meta { color: var(--muted); font-size: .9rem; margin: 0 0 12px; }
        .description { margin: 12px 0 0; white-space: pre-line; overflow-wrap: anywhere; }
        h2 { font-size: 1.05rem; margin: 20px 0 10px; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .item { display: block; text-decoration: none; background: var(--card); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .item img, .item .placeholder { display: block; width: 100%; aspect-ratio: 1; object-fit: cover; background: var(--border); }
        .item div { padding: 8px 10px; }
        .item strong { display: block; font-size: .9rem; font-weight: 600; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
        .item span { color: var(--accent); font-size: .85rem; font-weight: 600; }
        .actions { position: fixed; left: 0; right: 0; bottom: 0; display: flex; justify-content: center; background: var(--card); border-top: 1px solid var(--border); padding: 12px 16px calc(12px + env(safe-area-inset-bottom)); }
        .actions div { width: 100%; max-width: 608px; display: flex; flex-wrap: wrap; gap: 8px; }
        .button { flex: 1 1 200px; display: block; padding: 12px 16px; border-radius: 12px; border: 1px solid var(--accent); font-weight: 600; text-align: center; text-decoration: none; }
        .button.primary { background: var(--accent); color: #fff; }
        .button.secondary { color: var(--accent); }
    </style>
</head>
<body>
<header>
    <img src="{{ asset('images/logo.png') }}" alt="" width="32" height="32">
    <strong>ASSO</strong>
</header>

<main>
    <div class="card">
        @if($image)
            <img class="cover" src="{{ $image }}" alt="{{ $title }}">
        @endif
        <div class="body">
            <h1>{{ $title }}</h1>
            @if($price)
                <p class="price">{{ $price }}</p>
            @endif
            @if($shopName)
                <p class="meta">
                    @if($shopUrl)
                        {{ __('share.sold_by') }} <a href="{{ $shopUrl }}">{{ $shopName }}</a>
                    @else
                        {{ $shopName }}
                    @endif
                </p>
            @endif
            @if($kind === 'unavailable')
                <p class="description">{{ $summary }}</p>
            @elseif($description !== '')
                <p class="description">{{ $description }}</p>
            @endif
        </div>
    </div>

    @if($products->isNotEmpty())
        <h2>{{ __('share.shop_products') }}</h2>
        <div class="grid">
            @foreach($products as $item)
                <a class="item" href="{{ $item['url'] }}">
                    @if($item['image'])
                        <img src="{{ $item['image'] }}" alt="" loading="lazy">
                    @else
                        <span class="placeholder"></span>
                    @endif
                    <div>
                        <strong>{{ $item['name'] }}</strong>
                        @if($item['price'])
                            <span>{{ $item['price'] }}</span>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</main>

<nav class="actions">
    <div>
        @if($platform === 'desktop')
            <a class="button primary" href="{{ $androidStoreUrl }}">{{ __('share.get_android') }}</a>
            <a class="button secondary" href="{{ $iosStoreUrl }}">{{ __('share.get_ios') }}</a>
        @else
            <a class="button primary" id="open-app" href="{{ $openUrl }}">{{ __('share.open_app') }}</a>
            <a class="button secondary" href="{{ $storeUrl }}">{{ __('share.get_app') }}</a>
        @endif
    </div>
</nav>

@if($platform === 'ios')
    {{-- Sans l'application, iOS reste sur la page : le magasin prend le relais. --}}
    <script>
        document.getElementById('open-app').addEventListener('click', function () {
            setTimeout(function () {
                if (!document.hidden) { window.location.href = @json($storeUrl); }
            }, 1500);
        });
    </script>
@endif
</body>
</html>
