@extends('admin.layouts.app')

@section('content')
@php($in = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none')
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Litiges immobiliers</h1>
        <p class="text-gray-400">Ouverts par le locataire ou l'acheteur avant la fin du délai de visite. Tant qu'un litige est ouvert, la part du bailleur ou du cabinet reste bloquée. La commission ASSO n'est jamais remboursée.</p>
    </div>

    @include('admin.real_estate._alerts')

    <div class="flex gap-2 mb-4">
        @foreach(['open' => 'Ouverts', 'refunded' => 'Remboursés', 'released' => 'Débloqués', 'all' => 'Tous'] as $key => $label)
            <a href="{{ route('admin.real-estate.disputes.index', ['status' => $key]) }}"
               class="px-3 py-1.5 text-sm rounded-lg {{ $status === $key ? 'bg-primary-500 text-white' : 'bg-dark-200 text-gray-300' }}">
                {{ $label }} @if($key !== 'all')({{ $counts[$key] ?? 0 }})@endif
            </a>
        @endforeach
    </div>

    <div class="bg-dark-100 rounded-xl border border-dark-200 divide-y divide-dark-200 mb-8">
        @forelse($disputes as $d)
            <a href="{{ route('admin.real-estate.disputes.show', $d) }}" class="flex items-center justify-between p-4 hover:bg-dark-50">
                <div>
                    <p class="text-white font-medium">{{ $d->reservation?->listing?->title }} <span class="text-xs text-gray-500">{{ $d->reservation?->reference }}</span></p>
                    <p class="text-sm text-gray-400">{{ $d->reason_label }} · {{ $d->reservation?->tenant?->name }} · {{ $d->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <span class="text-sm text-white">{{ number_format($d->reservation?->owner_amount ?? 0, 0, ',', ' ') }} FCFA bloqués</span>
            </a>
        @empty
            <p class="p-6 text-gray-400">Aucun litige.</p>
        @endforelse
    </div>
    <div class="mb-8">{{ $disputes->links() }}</div>

    <div class="bg-dark-100 rounded-xl border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-list mr-2 text-primary-400"></i>Motifs de litige</h2>
        <p class="text-sm text-gray-400 mb-4">Proposés au locataire (maisons), à l'acheteur (terrains) ou aux deux.</p>
        <form method="POST" action="{{ route('admin.real-estate.disputes.reasons.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 mb-6">
            @csrf
            <select name="kind" class="{{ $in }}"><option value="rent">Maisons</option><option value="land">Terrains</option><option value="all">Les deux</option></select>
            <input name="label" required placeholder="Motif (FR)" class="{{ $in }}">
            <input name="label_en" placeholder="Reason (EN)" class="{{ $in }}">
            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg"><i class="fas fa-plus mr-1"></i>Ajouter</button>
        </form>
        <div class="divide-y divide-dark-200">
            @foreach($reasons as $reason)
                <form method="POST" action="{{ route('admin.real-estate.disputes.reasons.update', $reason) }}" class="grid grid-cols-1 md:grid-cols-7 gap-3 py-2 items-center">
                    @csrf @method('PUT')
                    <select name="kind" class="{{ $in }}">
                        @foreach(['rent' => 'Maisons', 'land' => 'Terrains', 'all' => 'Les deux'] as $k => $l)<option value="{{ $k }}" @selected($reason->kind === $k)>{{ $l }}</option>@endforeach
                    </select>
                    <input name="label" value="{{ $reason->label }}" class="{{ $in }} md:col-span-2">
                    <input name="label_en" value="{{ $reason->getTranslation('label', 'en') }}" placeholder="EN" class="{{ $in }} md:col-span-2">
                    <label class="flex items-center gap-2 text-sm text-white"><input type="checkbox" name="is_active" value="1" @checked($reason->is_active) class="rounded"> Actif</label>
                    <button class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-save"></i></button>
                </form>
            @endforeach
        </div>
    </div>
</div>
@endsection
