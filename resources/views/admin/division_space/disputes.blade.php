@extends('admin.layouts.app')

@section('title', 'Litiges de ma rubrique')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Litiges'])
    <p class="text-xs text-gray-500">Réclamations portant sur un article de votre rubrique. Elles sont traitées par l'équipe ASSO ; tant qu'un litige est ouvert, la commission de l'article reste en attente.</p>

    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Dossier</th><th class="px-4 py-3">Commande</th><th class="px-4 py-3">Article</th><th class="px-4 py-3">Ouvert le</th>
                <th class="px-4 py-3">Statut</th><th class="px-4 py-3 text-right">Remboursé</th>
            </tr></thead>
            <tbody>
                @forelse($disputes as $d)
                    <tr class="border-b border-dark-200">
                        <td class="px-4 py-3 text-white">{{ $d->number }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $d->order?->order_number }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $d->item?->product_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $d->created_at->displayTz()->format('d/m/Y') }}</td>
                        <td class="px-4 py-3"><span class="px-2 py-0.5 text-xs rounded-full {{ $d->isOpen() ? 'bg-amber-500/20 text-amber-300' : 'bg-dark-50 border border-dark-200 text-gray-300' }}">{{ \App\Models\Dispute::statusLabel($d->status) ?? $d->status }}</span></td>
                        <td class="px-4 py-3 text-right text-gray-200">{{ $d->refunded_at ? $money($d->refund_amount) : '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-10 text-center text-gray-500">Aucun litige dans votre rubrique.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $disputes->links() }}</div>
    </div>
</div>
@endsection
