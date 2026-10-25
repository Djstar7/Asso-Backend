@extends('admin.layouts.app')

@section('content')
@php
    $r = $dispute->reservation;
    $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ').' FCFA';
    $isLand = $r->kind === 'land';
@endphp
<div class="p-6">
    <a href="{{ route('admin.real-estate.disputes.index') }}" class="text-sm text-gray-400 hover:text-white"><i class="fas fa-arrow-left mr-1"></i> Litiges immobiliers</a>
    <h1 class="text-2xl font-bold text-white mt-1">{{ $dispute->reason_label }}</h1>
    <p class="text-gray-400">{{ $r->reference }} · {{ $r->listing?->title }} · ouvert le {{ $dispute->created_at->format('d/m/Y H:i') }}</p>

    <div class="mt-6">@include('admin.real_estate._alerts')</div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="lg:col-span-2 space-y-6">
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
                <h2 class="text-white font-semibold mb-2">{{ $isLand ? 'Acheteur' : 'Locataire' }} : {{ $r->tenant?->name }} ({{ $r->tenant?->phone }})</h2>
                <p class="text-gray-300 whitespace-pre-line">{{ $dispute->description }}</p>
                <div class="grid grid-cols-3 md:grid-cols-5 gap-2 mt-3">
                    @foreach($dispute->attachments->where('author', 'tenant') as $a)
                        <a href="{{ media_url($a->path) }}" target="_blank"><img src="{{ media_url($a->path) }}" class="rounded-lg h-24 w-full object-cover" alt=""></a>
                    @endforeach
                </div>
            </div>
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-5">
                <h2 class="text-white font-semibold mb-2">{{ $isLand ? 'Cabinet' : 'Bailleur' }} : {{ $isLand ? $r->listing?->cabinet?->name : $r->payee?->name }} ({{ $r->payee?->phone }})</h2>
                <p class="text-gray-300 whitespace-pre-line">{{ $dispute->payee_response ?: 'Pas encore de réponse.' }}</p>
                <div class="grid grid-cols-3 md:grid-cols-5 gap-2 mt-3">
                    @foreach($dispute->attachments->where('author', 'payee') as $a)
                        <a href="{{ media_url($a->path) }}" target="_blank"><img src="{{ media_url($a->path) }}" class="rounded-lg h-24 w-full object-cover" alt=""></a>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="space-y-6">
            <div class="bg-dark-100 rounded-xl border border-dark-200 p-5 text-sm">
                <h2 class="text-white font-semibold mb-3">Montants</h2>
                <div class="flex justify-between"><span class="text-gray-400">Payé</span><span class="text-white">{{ $fmt($r->total_amount) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Part bloquée</span><span class="text-white font-semibold">{{ $fmt($r->owner_amount) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-400">Part ASSO (acquise)</span><span class="text-primary-300">{{ $fmt($r->platform_amount) }}</span></div>
                <div class="flex justify-between mt-2"><span class="text-gray-400">Fin du délai</span><span class="text-white">{{ $r->visit_deadline_at?->format('d/m/Y H:i') }}</span></div>
            </div>
            @if($dispute->status === 'open')
                <form method="POST" action="{{ route('admin.real-estate.disputes.decide', $dispute) }}" class="bg-dark-100 rounded-xl border border-primary-500/40 p-5 space-y-3">
                    @csrf
                    <h2 class="text-white font-semibold">Décision</h2>
                    <textarea name="note" rows="3" placeholder="Explication communiquée aux deux parties" class="w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white"></textarea>
                    <button name="decision" value="refund" class="w-full px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700" data-confirm="Rembourser {{ $fmt($r->owner_amount) }} à {{ $isLand ? 'l’acheteur' : 'la personne locataire' }} ?">
                        <i class="fas fa-undo mr-2"></i>Rembourser le {{ $isLand ? 'l\'acheteur' : 'locataire' }}
                    </button>
                    <button name="decision" value="release" class="w-full px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700" data-confirm="Débloquer {{ $fmt($r->owner_amount) }} au {{ $isLand ? 'cabinet' : 'bailleur' }} ?">
                        <i class="fas fa-unlock mr-2"></i>Débloquer au {{ $isLand ? 'cabinet' : 'bailleur' }}
                    </button>
                </form>
            @else
                <div class="bg-dark-100 rounded-xl border border-dark-200 p-5 text-sm">
                    <h2 class="text-white font-semibold mb-2">{{ $dispute->status === 'refunded' ? 'Remboursé' : 'Débloqué' }}</h2>
                    <p class="text-gray-400">Par {{ $dispute->decider?->name }} le {{ $dispute->decided_at?->format('d/m/Y H:i') }}</p>
                    @if($dispute->decision_note)<p class="text-gray-300 mt-2">{{ $dispute->decision_note }}</p>@endif
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
