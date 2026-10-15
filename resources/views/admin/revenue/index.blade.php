@extends('admin.layouts.app')

@section('title', 'Revenus ASSO')
@section('header', 'Revenus ASSO')

@section('content')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $period = $summary['period'];
    $totals = $summary['totals'];
    $previous = $summary['previous'];
    $trend = function ($current, $before) {
        if ($before === null) return null;
        if ((float) $before == 0.0) return (float) $current == 0.0 ? 0.0 : INF;
        return ((float) $current - (float) $before) / abs((float) $before) * 100;
    };
    $cards = [
        ['label' => 'Chiffre d\'affaires ASSO', 'icon' => 'fa-coins', 'color' => 'emerald', 'value' => $totals['revenue'], 'previous' => $previous['revenue'] ?? null, 'up_good' => true, 'hint' => 'Tous services confondus'],
        ['label' => 'Pertes et charges', 'icon' => 'fa-minus-circle', 'color' => 'red', 'value' => $totals['losses'], 'previous' => $previous['losses'] ?? null, 'up_good' => false, 'hint' => 'Retours, commissions versées, frais de paiement'],
        ['label' => 'Revenu net', 'icon' => 'fa-wallet', 'color' => $totals['net'] >= 0 ? 'primary' : 'red', 'value' => $totals['net'], 'previous' => $previous['net'] ?? null, 'up_good' => true, 'hint' => 'CA − pertes et charges'],
        ['label' => 'CA des produits ASSO', 'icon' => 'fa-store', 'color' => 'sky', 'value' => $summary['sources']['platform_products']['amount'], 'previous' => $summary['sources']['platform_products']['previous'], 'up_good' => true, 'hint' => 'Part vendeur des boutiques du compte plateforme'],
    ];
    $rowLink = fn ($key) => route('admin.revenue.show', ['source' => $key, 'period' => $period['key']]);
@endphp
<div class="p-6 space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Revenus ASSO</h1>
            <p class="text-gray-400 mt-1">
                Chiffre d'affaires de la plateforme par service, et tout ce qui vient en déduction
                — {{ strtolower($period['label']) }}. Montants en FCFA.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('admin.statistics.partials.period-picker', ['route' => 'admin.revenue.index'])
            @if(auth()->user()->isAdmin())
            <a href="{{ route('admin.revenue.withdrawal') }}"
               class="px-3 py-2 text-sm rounded-lg bg-primary-600 hover:bg-primary-700 text-white">
                <i class="fas fa-money-bill-transfer mr-1"></i>Retirer
            </a>
            @endif
            <a href="{{ route('admin.revenue.export', ['period' => $period['key']]) }}"
               class="px-3 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">
                <i class="fas fa-file-excel mr-1"></i>Exporter
            </a>
        </div>
    </div>

    @unless($platform)
        <div class="bg-yellow-500/10 border border-yellow-500/30 text-yellow-300 rounded-xl p-4 text-sm">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            Aucun compte plateforme trouvé (paramètre « platform_account_email ») : les commissions Diaspo,
            les acomptes conservés et les ventes des produits ASSO ne peuvent pas être calculés.
        </div>
    @endunless

    <!-- Indicateurs -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4">
        @foreach($cards as $card)
            @php $t = $trend($card['value'], $card['previous']); @endphp
            <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
                <div class="flex items-start justify-between">
                    <div class="min-w-0">
                        <p class="text-sm text-gray-400">{{ $card['label'] }}</p>
                        <p class="text-2xl font-bold text-white mt-1 truncate">{{ $fcfa($card['value']) }}</p>
                    </div>
                    <div class="p-3 rounded-lg bg-{{ $card['color'] }}-500/20 text-{{ $card['color'] }}-400">
                        <i class="fas {{ $card['icon'] }}"></i>
                    </div>
                </div>
                <div class="mt-3 text-xs flex items-center gap-2">
                    @if($t !== null && $t != 0)
                        @php $good = ($t > 0) === $card['up_good']; @endphp
                        <span class="{{ $good ? 'text-green-400' : 'text-red-400' }}">
                            <i class="fas fa-arrow-{{ $t > 0 ? 'up' : 'down' }}"></i>
                            {{ is_infinite($t) ? 'nouveau' : number_format(abs($t), 1, ',', ' ') . ' %' }}
                        </span>
                        <span class="text-gray-500">vs période précédente</span>
                    @else
                        <span class="text-gray-500">{{ $card['hint'] }}</span>
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    <!-- Évolution -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Évolution {{ $period['granularity'] === 'month' ? 'mensuelle' : 'journalière' }}</h3>
        <div class="h-72"><canvas id="revenueChart"></canvas></div>
    </div>

    @foreach([['revenue', 'Chiffre d\'affaires par service', 'D\'où vient l\'argent d\'ASSO.', $totals['revenue'], 'text-emerald-400'], ['loss', 'Pertes et charges déduites', 'Ce qui est retiré du chiffre d\'affaires, mouvement par mouvement.', $totals['losses'], 'text-red-400']] as [$type, $title, $subtitle, $sum, $color])
        <div class="bg-dark-100 border border-dark-200 rounded-xl">
            <div class="p-6 border-b border-dark-200 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
                <div>
                    <h3 class="text-lg font-semibold text-white">{{ $title }}</h3>
                    <p class="text-sm text-gray-400">{{ $subtitle }} Ouvrez une ligne pour voir chaque mouvement.</p>
                </div>
                <div class="text-xl font-bold {{ $color }}">{{ $type === 'loss' ? '− ' : '' }}{{ $fcfa($sum) }}</div>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-dark-50 text-gray-400 uppercase text-xs">
                        <tr>
                            <th class="px-4 py-3 text-left">Service</th>
                            <th class="px-4 py-3 text-right">Mouvements</th>
                            <th class="px-4 py-3 text-right">Montant</th>
                            <th class="px-4 py-3 text-right">Part</th>
                            <th class="px-4 py-3 text-right">Période précédente</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200 text-gray-200">
                        @foreach($groups[$type] ?? [] as $group => $rows)
                            <tr class="bg-dark-50/40">
                                <td colspan="2" class="px-4 py-2 text-xs uppercase tracking-wide text-gray-400">{{ $group }}</td>
                                <td class="px-4 py-2 text-right text-xs text-gray-400">{{ $fcfa($rows->sum('amount')) }}</td>
                                <td colspan="3"></td>
                            </tr>
                            @foreach($rows as $row)
                                <tr class="hover:bg-dark-50/50 cursor-pointer" onclick="window.location='{{ $rowLink($row['key']) }}'">
                                    <td class="px-4 py-3 pl-8">
                                        <div class="font-medium text-white">{{ $row['label'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $row['help'] }}</div>
                                    </td>
                                    <td class="px-4 py-3 text-right">{{ number_format($row['count'], 0, ',', ' ') }}</td>
                                    <td class="px-4 py-3 text-right font-semibold {{ $row['amount'] > 0 ? $color : 'text-gray-500' }}">
                                        {{ $type === 'loss' && $row['amount'] > 0 ? '− ' : '' }}{{ $fcfa($row['amount']) }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-400">
                                        {{ $sum > 0 ? number_format($row['amount'] / $sum * 100, 1, ',', ' ') . ' %' : '—' }}
                                    </td>
                                    <td class="px-4 py-3 text-right text-gray-400">{{ $row['previous'] === null ? '—' : $fcfa($row['previous']) }}</td>
                                    <td class="px-4 py-3 text-right whitespace-nowrap">
                                        <a href="{{ $rowLink($row['key']) }}" class="text-primary-400 hover:text-primary-300">
                                            <i class="fas fa-list mr-1"></i>Mouvements
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endforeach

    <!-- Récapitulatif -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Récapitulatif</h3>
        <dl class="max-w-md space-y-2 text-sm">
            <div class="flex justify-between"><dt class="text-gray-400">Chiffre d'affaires</dt><dd class="text-emerald-400 font-semibold">{{ $fcfa($totals['revenue']) }}</dd></div>
            <div class="flex justify-between"><dt class="text-gray-400">Pertes et charges</dt><dd class="text-red-400 font-semibold">− {{ $fcfa($totals['losses']) }}</dd></div>
            <div class="flex justify-between border-t border-dark-200 pt-2"><dt class="text-white font-semibold">Revenu net</dt><dd class="text-white font-bold">{{ $fcfa($totals['net']) }}</dd></div>
        </dl>
        <p class="text-xs text-gray-500 mt-4">
            Une recette est comptée quand elle est acquise : commande réglée, forfait payé, code Diaspo validé.
            Les frais de paiement sont ceux que le prestataire déclare avoir prélevés sur chaque encaissement
            (KPay : feeAmount, ElgioPay : amount.fees). Les frais des retraits KPay, retenus sur le montant reçu
            par le bénéficiaire, ne sont pas à la charge d'ASSO ; ElgioPay n'en communique aucun sur ses retraits.
        </p>
    </div>
</div>

<script>
(function () {
    const series = @json($summary['series']);
    const monthly = @json($period['granularity'] === 'month');
    const labels = series.map(p => {
        const [y, m, d] = p.date.split('-');
        return monthly ? `${m}/${y}` : `${d}/${m}`;
    });
    const draw = () => new Chart(document.getElementById('revenueChart'), {
        data: {
            labels,
            datasets: [
                { type: 'bar', label: 'Chiffre d\'affaires', data: series.map(p => p.revenue), backgroundColor: 'rgba(16,185,129,.45)' },
                { type: 'bar', label: 'Pertes et charges', data: series.map(p => -p.losses), backgroundColor: 'rgba(239,68,68,.45)' },
                { type: 'line', label: 'Net', data: series.map(p => p.net), borderColor: 'rgba(59,130,246,1)', tension: .35 },
            ],
        },
        options: {
            animation: { duration: 0 },
            maintainAspectRatio: false,
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { labels: { color: '#e5e7eb' } },
                tooltip: { callbacks: { label: c => `${c.dataset.label} : ${Math.abs(c.parsed.y).toLocaleString('fr-FR')} FCFA` } },
            },
            scales: {
                x: { stacked: true, ticks: { color: '#9ca3af' }, grid: { color: 'rgba(75,85,99,.2)' } },
                y: { stacked: false, ticks: { color: '#9ca3af' }, grid: { color: 'rgba(75,85,99,.2)' } },
            },
        },
    });
    if (window.Chart) { draw(); } else { window.addEventListener('load', draw); }
})();
</script>
@endsection
