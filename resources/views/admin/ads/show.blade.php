@extends('admin.layouts.app')

@section('title', 'Campagne sponsorisée')
@section('header', 'Campagne sponsorisée')

@section('content')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $num = fn ($v) => number_format((int) $v, 0, ',', ' ');
@endphp
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
        <div class="flex items-center gap-4">
            @if($summary['product']['image'])
                <img src="{{ $summary['product']['image'] }}" alt=""
                     class="w-16 h-16 rounded-lg object-cover border border-dark-200">
            @else
                <div class="w-16 h-16 rounded-lg bg-dark-50 border border-dark-200 flex items-center justify-center">
                    <i class="fas fa-box text-gray-600"></i>
                </div>
            @endif
            <div>
                <h1 class="text-2xl font-bold text-gray-100">{{ $summary['product']['name'] ?? 'Produit supprimé' }}</h1>
                <p class="text-gray-400 text-sm mt-0.5">
                    {{ $boost->shop->name ?? '—' }} · {{ $boost->user->name ?? '—' }}
                    · Forfait {{ $summary['package_name'] ?? '—' }}
                </p>
            </div>
        </div>
        <div class="flex items-center gap-3">
            @if($boost->status === \App\Models\ProductBoost::ACTIVE)
                <form method="POST" action="{{ route('admin.ads.cancel', $boost) }}">
                    @csrf
                    <button type="submit"
                            class="px-4 py-2 bg-red-600/90 hover:bg-red-600 text-white rounded-lg text-sm transition-colors">
                        <i class="fas fa-stop-circle mr-2"></i>
                        Arrêter la campagne
                    </button>
                </form>
            @endif
            <a href="{{ route('admin.ads.index') }}"
               class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg text-sm transition-colors">
                <i class="fas fa-arrow-left mr-2"></i>
                Retour
            </a>
        </div>
    </div>

    <!-- Progression du quota -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl p-6">
        <div class="flex flex-wrap items-end justify-between gap-4 mb-3">
            <div>
                <h3 class="text-lg font-semibold text-white">Portée délivrée</h3>
                <p class="text-gray-400 text-sm mt-1">
                    {{ $num($summary['impressions_served']) }} impressions servies sur
                    {{ $num($summary['impressions_quota']) }} achetées.
                    @if($summary['is_running'])
                        Il en reste {{ $num($summary['impressions_remaining']) }}
                        et {{ $summary['remaining_days'] }} jour(s).
                    @endif
                </p>
            </div>
            <span class="text-3xl font-bold text-white">{{ $summary['progress_percent'] }} %</span>
        </div>
        <div class="h-3 bg-dark-200 rounded-full overflow-hidden">
            <div class="h-full bg-gradient-to-r from-primary-500 to-primary-600 rounded-full"
                 style="width: {{ $summary['progress_percent'] }}%"></div>
        </div>
        <div class="flex flex-wrap gap-x-8 gap-y-2 mt-4 text-sm text-gray-400">
            <span>Du {{ \Carbon\Carbon::parse($summary['starts_at'])->displayTz()->format('d/m/Y') }}
                  au {{ \Carbon\Carbon::parse($summary['ends_at'])->displayTz()->format('d/m/Y') }}</span>
            <span>Montant : <span class="text-gray-200">{{ $fcfa($summary['amount_xaf']) }}</span></span>
            <span>Statut : <span class="text-gray-200">{{ $summary['status_label'] }}</span></span>
        </div>
    </div>

    <!-- Ce que la portée a produit -->
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Personnes touchées</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($summary['reached']) }}</p>
            <p class="text-gray-500 text-xs mt-1">affichages de l'annonce</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Ont ouvert la fiche</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($summary['clicks']) }}</p>
            <p class="text-gray-500 text-xs mt-1">{{ $summary['click_through_rate'] }} % des affichages</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Ont vu le produit</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($summary['viewers']) }}</p>
            <p class="text-gray-500 text-xs mt-1">personnes distinctes, toutes sources</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-gray-400 text-sm">Ont pris contact</p>
            <p class="text-2xl font-bold text-white mt-1">{{ $num($summary['interactions']) }}</p>
            <p class="text-gray-500 text-xs mt-1">personnes distinctes</p>
        </div>
    </div>

    <!-- Courbe -->
    <div class="bg-dark-100 border border-dark-200 rounded-xl p-6">
        <h3 class="text-lg font-semibold text-white mb-4">Diffusion jour par jour</h3>
        @if(count($summary['series']) > 0)
            <canvas id="boostChart" height="90"></canvas>
        @else
            <p class="text-gray-500 text-sm">Aucune donnée pour le moment.</p>
        @endif
    </div>
</div>

@if(count($summary['series']) > 0)
@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const series = @json($summary['series']);
        const labels = series.map(function (row) {
            const parts = row.date.split('-');
            return parts[2] + '/' + parts[1];
        });

        new Chart(document.getElementById('boostChart'), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Impressions',
                        data: series.map(function (row) { return row.impressions; }),
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99, 102, 241, 0.15)',
                        fill: true,
                        tension: 0.3,
                    },
                    {
                        label: 'Ouvertures de fiche',
                        data: series.map(function (row) { return row.clicks; }),
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34, 197, 94, 0.15)',
                        fill: true,
                        tension: 0.3,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { labels: { color: '#9ca3af' } },
                },
                scales: {
                    x: { ticks: { color: '#6b7280' }, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: { beginAtZero: true, ticks: { color: '#6b7280', precision: 0 }, grid: { color: 'rgba(255,255,255,0.05)' } },
                },
            },
        });
    })();
</script>
@endpush
@endif
@endsection
