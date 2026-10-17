@extends('admin.layouts.app')

@section('title', 'Revenus ASSO — ' . $label)
@section('header', 'Revenus ASSO')

@section('content')
@php
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $isLoss = $type === \App\Services\PlatformRevenueService::LOSS;
    $color = $isLoss ? 'text-red-400' : 'text-emerald-400';
@endphp
<div class="p-6 space-y-6">
    <div>
        <a href="{{ route('admin.revenue.index', ['period' => $period['key']]) }}" class="text-sm text-primary-400 hover:text-primary-300">
            <i class="fas fa-arrow-left mr-1"></i>Revenus ASSO
        </a>
    </div>

    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <p class="text-xs uppercase tracking-wide text-gray-500">{{ $isLoss ? 'Déduction' : 'Chiffre d\'affaires' }} · {{ $group }}</p>
            <h1 class="text-3xl font-bold text-gray-100">{{ $label }}</h1>
            <p class="text-gray-400 mt-1">{{ $help }} — {{ strtolower($period['label']) }}.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @include('admin.statistics.partials.period-picker', ['route' => 'admin.revenue.show', 'routeParams' => ['source' => $source]])
            <a href="{{ route('admin.revenue.export', ['period' => $period['key'], 'source' => $source]) }}"
               class="px-3 py-2 text-sm rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white">
                <i class="fas fa-file-excel mr-1"></i>Exporter
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-sm text-gray-400">Total</p>
            <p class="text-2xl font-bold mt-1 {{ $color }}">{{ $isLoss && $total['amount'] > 0 ? '− ' : '' }}{{ $fcfa($total['amount']) }}</p>
        </div>
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-5">
            <p class="text-sm text-gray-400">Mouvements</p>
            <p class="text-2xl font-bold text-white mt-1">{{ number_format($total['count'], 0, ',', ' ') }}</p>
        </div>
    </div>

    <div class="bg-dark-100 border border-dark-200 rounded-xl">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-dark-50 text-gray-400 uppercase text-xs">
                    <tr>
                        <th class="px-4 py-3 text-left">Date</th>
                        <th class="px-4 py-3 text-left">Référence</th>
                        <th class="px-4 py-3 text-left">Détail</th>
                        <th class="px-4 py-3 text-right">Montant</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-200">
                    @forelse($movements as $m)
                        <tr class="hover:bg-dark-50/50">
                            <td class="px-4 py-3 whitespace-nowrap text-gray-400">{{ \Carbon\Carbon::parse($m->occurred_at)->displayTz()->format('d/m/Y H:i') }}</td>
                            <td class="px-4 py-3 font-mono text-xs">{{ $m->reference }}</td>
                            <td class="px-4 py-3">{{ trim((string) $m->detail) ?: '—' }}</td>
                            <td class="px-4 py-3 text-right font-semibold whitespace-nowrap {{ $color }}">{{ $isLoss ? '− ' : '' }}{{ $fcfa($m->amount) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if($m->url)
                                    <a href="{{ $m->url }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-external-link-alt mr-1"></i>Ouvrir</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucun mouvement sur cette période.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($movements->hasPages())
            <div class="p-4 border-t border-dark-200">{{ $movements->links() }}</div>
        @endif
    </div>
</div>
@endsection
