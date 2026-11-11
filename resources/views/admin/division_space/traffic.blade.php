@extends('admin.layouts.app')

@section('title', 'Trafic de ma rubrique')
@section('header', 'Ma rubrique')

@section('content')
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Trafic'])

    @if($realEstateTop)
        <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
            <h2 class="text-lg font-semibold text-white px-5 pt-5 pb-3">Annonces les plus vues (depuis leur publication)</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b border-dark-200"><th class="px-4 py-2">Annonce</th><th class="px-4 py-2">Ville</th><th class="px-4 py-2 text-right">Vues</th></tr></thead>
                <tbody>
                    @forelse($realEstateTop as $l)
                        <tr class="border-b border-dark-200"><td class="px-4 py-2 text-white">{{ $l->title }}</td><td class="px-4 py-2 text-gray-300">{{ $l->city }}</td><td class="px-4 py-2 text-right text-gray-200">{{ (int) $l->views_count }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Aucune annonce.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @else
        <div class="flex flex-wrap gap-2">
            @foreach($periods as $p)
                <a href="{{ route('admin.division.traffic.index', $query + ['period' => $p]) }}"
                   class="px-3 py-1 rounded-full text-xs {{ $periodKey === $p ? 'bg-primary-600 text-white' : 'bg-dark-100 text-gray-300' }}">
                    {{ ['7d' => '7 jours', '30d' => '30 jours', '90d' => '90 jours', '365d' => '12 mois', 'all' => 'Tout'][$p] }}
                </a>
            @endforeach
        </div>

        <div class="grid sm:grid-cols-2 gap-4">
            <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">Vues des produits</p><p class="text-2xl font-bold text-white mt-1">{{ number_format($totals['views'], 0, ',', ' ') }}</p></div>
            <div class="bg-dark-100 rounded-xl p-5"><p class="text-sm text-gray-400">Visiteurs uniques</p><p class="text-2xl font-bold text-white mt-1">{{ number_format($totals['visitors'], 0, ',', ' ') }}</p></div>
        </div>
        <p class="text-xs text-gray-500">Seules les vues des fiches produit sont attribuables à une rubrique ; les visites de la page d'une boutique ne le sont pas.</p>

        <div class="grid lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 bg-dark-100 rounded-xl overflow-x-auto">
                <h2 class="text-lg font-semibold text-white px-5 pt-5 pb-3">Produits les plus consultés</h2>
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-400 border-b border-dark-200"><th class="px-4 py-2">Produit</th><th class="px-4 py-2 text-right">Vues</th><th class="px-4 py-2 text-right">Vendus</th><th class="px-4 py-2 text-right">Conversion</th></tr></thead>
                    <tbody>
                        @forelse($top as $row)
                            <tr class="border-b border-dark-200">
                                <td class="px-4 py-2 text-white">{{ $row['name'] }}</td>
                                <td class="px-4 py-2 text-right text-gray-300">{{ $row['views'] }}</td>
                                <td class="px-4 py-2 text-right text-gray-300">{{ $row['sold'] }}</td>
                                <td class="px-4 py-2 text-right text-gray-200">{{ number_format($row['conversion'], 1, ',', ' ') }} %</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-gray-500">Aucune vue sur la période.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="bg-dark-100 rounded-xl overflow-x-auto">
                <h2 class="text-lg font-semibold text-white px-5 pt-5 pb-3">Provenance</h2>
                <table class="w-full text-sm">
                    <tbody>
                        @forelse($sources as $s)
                            <tr class="border-b border-dark-200"><td class="px-4 py-2 text-gray-200">{{ $s->source }}</td><td class="px-4 py-2 text-right text-gray-300">{{ (int) $s->views }}</td></tr>
                        @empty
                            <tr><td class="px-4 py-6 text-center text-gray-500">—</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="bg-dark-100 rounded-xl overflow-x-auto">
            <h2 class="text-lg font-semibold text-white px-5 pt-5 pb-3">Par jour</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-gray-400 border-b border-dark-200"><th class="px-4 py-2">Jour</th><th class="px-4 py-2 text-right">Vues</th><th class="px-4 py-2 text-right">Visiteurs</th></tr></thead>
                <tbody>
                    @forelse($daily->reverse() as $d)
                        <tr class="border-b border-dark-200"><td class="px-4 py-2 text-gray-200">{{ \Illuminate\Support\Carbon::parse($d->day)->format('d/m/Y') }}</td><td class="px-4 py-2 text-right text-gray-300">{{ (int) $d->views }}</td><td class="px-4 py-2 text-right text-gray-300">{{ (int) $d->visitors }}</td></tr>
                    @empty
                        <tr><td colspan="3" class="px-4 py-6 text-center text-gray-500">Aucune vue sur la période.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
