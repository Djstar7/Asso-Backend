@extends('admin.layouts.app')

@section('title', 'Annonces')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Annonces'])
    <form method="GET" class="bg-dark-100 rounded-xl p-4 grid grid-cols-1 md:grid-cols-3 gap-3">
        @foreach($query as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Titre" class="md:col-span-2 px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
        <button class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
    </form>
    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Annonce</th><th class="px-4 py-3">Ville</th><th class="px-4 py-3 text-right">Prix</th><th class="px-4 py-3 text-right">Vues</th><th class="px-4 py-3">Statut</th>
            </tr></thead>
            <tbody>
                @forelse($listings as $l)
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-3 text-white">{{ $l->title }} <span class="block text-xs text-gray-500">{{ $l->reference }}</span></td>
                        <td class="px-4 py-3 text-gray-300">{{ $l->city }}</td>
                        <td class="px-4 py-3 text-right text-gray-200">{{ $money($l->net_price) }}</td>
                        <td class="px-4 py-3 text-right text-gray-300">{{ (int) $l->views_count }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ ['draft' => 'Brouillon', 'pending_review' => 'À valider', 'published' => 'Publiée', 'rejected' => 'Refusée', 'archived' => 'Archivée'][$l->status] ?? $l->status }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-10 text-center text-gray-500">Aucune annonce.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $listings->links() }}</div>
    </div>
</div>
@endsection
