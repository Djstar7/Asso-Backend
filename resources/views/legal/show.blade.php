<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->title }} — ASSO</title>
    <style>
        :root { --bg: #ffffff; --text: #1f2937; --muted: #6b7280; --border: #e5e7eb; --accent: #ff5722; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #18181b; --text: #e5e7eb; --muted: #a1a1aa; --border: #3f3f46; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text);
               font: 16px/1.65 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        main { max-width: 760px; margin: 0 auto; padding: 24px 16px 48px; }
        h1 { font-size: 1.6rem; line-height: 1.25; margin: 0 0 4px; }
        .updated { color: var(--muted); font-size: .85rem; margin-bottom: 24px; }
        .content h1, .content h2, .content h3 { line-height: 1.3; margin: 1.6em 0 .5em; }
        .content h2 { font-size: 1.25rem; }
        .content h3 { font-size: 1.1rem; }
        .content a, nav a { color: var(--accent); }
        .content ul, .content ol { padding-left: 1.4em; }
        .content img { max-width: 100%; height: auto; }
        .content blockquote { margin: 1em 0; padding-left: 1em; border-left: 3px solid var(--border); color: var(--muted); }
        nav { margin-top: 40px; padding-top: 16px; border-top: 1px solid var(--border); font-size: .9rem; }
        nav ul { list-style: none; padding: 0; margin: 8px 0 0; }
        nav li { margin: 6px 0; }
        /* Ouverte depuis l'application : l'écran porte déjà le titre. */
        body.in-app h1 { display: none; }
    </style>
</head>
<body class="{{ request()->boolean('app') ? 'in-app' : '' }}">
<main>
    <h1>{{ $page->title }}</h1>
    <p class="updated">{{ __('legal.updated_on', ['date' => $page->updated_at?->format('d/m/Y')]) }}</p>

    {{-- Contenu HTML rédigé par l'administration (éditeur Quill). --}}
    <div class="content">{!! $page->content !!}</div>

    @if($others->isNotEmpty())
        <nav>
            <strong>{{ __('legal.other_documents') }}</strong>
            <ul>
                @foreach($others as $other)
                    <li><a href="{{ route('legal.show', array_filter(['slug' => $other->slug, 'lang' => app()->getLocale() === 'fr' ? null : app()->getLocale(), 'app' => request()->boolean('app') ? 1 : null])) }}">{{ $other->title }}</a></li>
                @endforeach
            </ul>
        </nav>
    @endif
</main>
</body>
</html>
