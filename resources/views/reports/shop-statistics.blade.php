@php
    /**
     * Rapport de statistiques boutique (PDF).
     *
     * Dompdf ne gère ni flexbox ni grid : la mise en page repose volontairement
     * sur des tableaux et des styles en ligne simples.
     */
    $period = $summary['period'] ?? [];
    $totals = $summary['totals'] ?? [];
    $trends = $summary['trends'] ?? null;
    $series = $summary['series'] ?? [];
    $topProducts = $summary['top_products'] ?? [];

    $fmt = fn ($value) => number_format((float) $value, 2, ',', ' ');
    $date = fn ($iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->displayTz()->format('d/m/Y') : '—';
    $isMonthly = ($period['granularity'] ?? 'day') === 'month';
@endphp
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Statistiques — {{ $shop->name }}</title>
    <style>
        @page { margin: 22mm 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #1A1A18; }
        h1 { font-size: 18px; margin: 0 0 2px; }
        h2 { font-size: 12px; margin: 22px 0 8px; padding-bottom: 4px; border-bottom: 1px solid #E6E6E3; }
        .muted { color: #5F5F5A; }
        .meta { margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 8px; text-align: left; border-bottom: 1px solid #EAEAE7; }
        th { background: #F2F2F0; font-size: 9px; text-transform: uppercase; letter-spacing: .4px; }
        td.num, th.num { text-align: right; }
        .kpi { width: 50%; vertical-align: top; padding: 0 6px 10px 0; }
        .kpi-box { border: 1px solid #E6E6E3; border-radius: 6px; padding: 10px 12px; }
        .kpi-label { font-size: 9px; color: #5F5F5A; }
        .kpi-value { font-size: 15px; font-weight: bold; padding-top: 2px; }
        .up { color: #1F7A4D; }
        .down { color: #B3261E; }
        footer { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8px; color: #85857F; }
    </style>
</head>
<body>

<footer>
    {{ $shop->name }} — rapport édité le {{ $generatedAt->displayTz()->format('d/m/Y à H:i') }} · ASSO
</footer>

<h1>Statistiques de la boutique</h1>
<div class="meta muted">{{ $shop->name }}</div>
<div class="meta muted">
    {{ $period['label'] ?? '' }} — du {{ $date($period['from'] ?? null) }} au {{ $date($period['to'] ?? null) }}
</div>

<h2>Vue d'ensemble</h2>
<table>
    @php
        // Les indicateurs les plus lus d'abord, deux par ligne.
        $highlights = [
            'revenue' => 'Chiffre d\'affaires (XAF)',
            'validated_orders' => 'Commandes validées',
            'visits' => 'Visites de la boutique',
            'unique_visitors' => 'Visiteurs uniques',
            'items_sold' => 'Articles vendus',
            'average_basket' => 'Panier moyen (XAF)',
        ];
        $chunks = array_chunk($highlights, 2, true);
    @endphp
    @foreach ($chunks as $row)
        <tr>
            @foreach ($row as $key => $label)
                <td class="kpi" style="border-bottom: none;">
                    <div class="kpi-box">
                        <div class="kpi-label">{{ $label }}</div>
                        <div class="kpi-value">
                            {{ $fmt($totals[$key] ?? 0) }}
                            @if (is_array($trends) && isset($trends[$key]) && $trends[$key] !== null && (float) $trends[$key] != 0.0)
                                <span style="font-size: 9px;"
                                      class="{{ (float) $trends[$key] > 0 ? 'up' : 'down' }}">
                                    {{ (float) $trends[$key] > 0 ? '▲' : '▼' }} {{ $fmt(abs((float) $trends[$key])) }} %
                                </span>
                            @endif
                        </div>
                    </div>
                </td>
            @endforeach
            @if (count($row) === 1)
                <td class="kpi" style="border-bottom: none;"></td>
            @endif
        </tr>
    @endforeach
</table>

<h2>Tous les indicateurs</h2>
<table>
    <thead>
        <tr><th>Indicateur</th><th class="num">Valeur</th></tr>
    </thead>
    <tbody>
        @foreach ($labels as $key => $label)
            <tr>
                <td>{{ $label }}</td>
                <td class="num">{{ $fmt($totals[$key] ?? 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

@if (!empty($topProducts))
    <h2>Produits les plus consultés</h2>
    <table>
        <thead>
            <tr>
                <th>Produit</th>
                <th class="num">Vues</th>
                <th class="num">Vendus</th>
                <th class="num">CA (XAF)</th>
                <th class="num">Conversion</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($topProducts as $product)
                <tr>
                    <td>{{ $product['name'] ?? '' }}</td>
                    <td class="num">{{ $product['views'] ?? 0 }}</td>
                    <td class="num">{{ $product['items_sold'] ?? 0 }}</td>
                    <td class="num">{{ $fmt($product['revenue'] ?? 0) }}</td>
                    <td class="num">{{ $fmt($product['conversion_rate'] ?? 0) }} %</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (!empty($series))
    <h2>Détail par {{ $isMonthly ? 'mois' : 'jour' }}</h2>
    <table>
        <thead>
            <tr>
                <th>{{ $isMonthly ? 'Mois' : 'Date' }}</th>
                <th class="num">Visites</th>
                <th class="num">Produits vus</th>
                <th class="num">Contacts</th>
                <th class="num">Commandes</th>
                <th class="num">CA (XAF)</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($series as $point)
                <tr>
                    <td>{{ $point['date'] ?? '' }}</td>
                    <td class="num">{{ $point['visits'] ?? 0 }}</td>
                    <td class="num">{{ $point['product_views'] ?? 0 }}</td>
                    <td class="num">{{ $point['contacts'] ?? 0 }}</td>
                    <td class="num">{{ $point['orders'] ?? 0 }}</td>
                    <td class="num">{{ $fmt($point['revenue'] ?? 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

</body>
</html>
