@extends('admin.layouts.app')

@section('content')
@php
    use App\Models\Dispute;
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $tabs = ['open' => 'En cours'] + Dispute::STATUSES + ['all' => 'Tous'];
    $badges = [
        'new' => 'bg-red-500/20 text-red-300 border-red-500/50',
        'in_review' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50',
        'vendor_contacted' => 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50',
        'replacement' => 'bg-blue-500/20 text-blue-300 border-blue-500/50',
        'return' => 'bg-blue-500/20 text-blue-300 border-blue-500/50',
        'refunded' => 'bg-purple-500/20 text-purple-300 border-purple-500/50',
        'rejected' => 'bg-gray-500/20 text-gray-300 border-gray-500/50',
        'resolved' => 'bg-green-500/20 text-green-300 border-green-500/50',
        'closed' => 'bg-gray-500/20 text-gray-300 border-gray-500/50',
    ];
    $reasons = [
        'different' => 'Produit différent', 'damaged' => 'Endommagé', 'defective' => 'Défectueux',
        'incomplete' => 'Incomplet', 'wrong_variant' => 'Mauvaise variante', 'other' => 'Autre',
    ];
@endphp
<div class="p-6">
    <div class="mb-6">
        <h1 class="text-2xl font-bold text-white">Réclamations / Litiges</h1>
        <p class="text-gray-400">Réclamations ouvertes par les clients dans les 48 h qui suivent la livraison. La part du vendeur reste bloquée jusqu'à la décision d'ASSO.</p>
    </div>

    @include('admin.wholesale_orders._flash')

    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($tabs as $value => $label)
            @php $active = $status === $value; @endphp
            <a href="{{ route('admin.disputes.index', array_filter(['status' => $value, 'search' => request('search')])) }}"
               class="px-3 py-1.5 rounded-full text-sm border transition-all {{ $active ? 'bg-primary-500 border-primary-500 text-white' : 'bg-dark-100 border-dark-200 text-gray-300 hover:border-primary-500' }}">
                {{ $label }}
                @if($value !== 'all')
                    <span class="ml-1 {{ $active ? 'text-white' : 'text-gray-500' }}">{{ $counts[$value] ?? 0 }}</span>
                @endif
            </a>
        @endforeach
    </div>

    <form method="GET" class="flex flex-wrap gap-3 mb-4">
        <input type="hidden" name="status" value="{{ $status }}">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="N° litige, commande, client, vendeur, téléphone"
               class="flex-1 min-w-[16rem] px-3 py-2 bg-dark-100 border border-dark-200 rounded-lg text-white text-sm">
        <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm"><i class="fas fa-search"></i></button>
    </form>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-dark-200 text-gray-400 text-left">
                <tr>
                    <th class="px-4 py-3">Litige</th>
                    <th class="px-4 py-3">Produit</th>
                    <th class="px-4 py-3">Client</th>
                    <th class="px-4 py-3">Vendeur</th>
                    <th class="px-4 py-3">Motif</th>
                    <th class="px-4 py-3 text-right">Part bloquée</th>
                    <th class="px-4 py-3">Statut</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-dark-200 text-gray-300">
                @forelse($disputes as $dispute)
                    <tr class="hover:bg-dark-50 transition-colors">
                        <td class="px-4 py-3">
                            <a href="{{ route('admin.disputes.show', $dispute) }}" class="text-white font-medium hover:text-primary-400">{{ $dispute->number }}</a>
                            <div class="text-xs text-gray-500">#{{ $dispute->order?->order_number }} · {{ $dispute->created_at->format('d/m/Y H:i') }}</div>
                        </td>
                        <td class="px-4 py-3">{{ $dispute->item?->product?->name ?? 'Produit supprimé' }}</td>
                        <td class="px-4 py-3">
                            {{ $dispute->client?->name ?? '—' }}
                            <div class="text-xs text-gray-500">{{ $dispute->client?->phone }}</div>
                        </td>
                        <td class="px-4 py-3">
                            {{ $dispute->seller?->name ?? '—' }}
                            @if($dispute->order?->is_wholesale)<div class="text-xs text-primary-400">Import — ASSO revendeur</div>@endif
                        </td>
                        <td class="px-4 py-3">{{ $reasons[$dispute->reason] ?? $dispute->reason }}</td>
                        <td class="px-4 py-3 text-right text-white">{{ $fcfa($dispute->held_amount) }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-1 rounded-full text-xs border whitespace-nowrap {{ $badges[$dispute->status] ?? '' }}">{{ Dispute::STATUSES[$dispute->status] ?? $dispute->status }}</span>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <a href="{{ route('admin.disputes.show', $dispute) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i> Gérer</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">Aucune réclamation dans cette catégorie.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-4">{{ $disputes->links() }}</div>
</div>
@endsection
