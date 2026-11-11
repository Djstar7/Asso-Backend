@extends('admin.layouts.app')

@section('title', 'Commandes de ma rubrique')
@section('header', 'Ma rubrique')

@section('content')
@php extract(\App\Support\DivisionPresenter::helpers()) @endphp
<div class="p-6 space-y-6">
    @include('admin.division_space._header', ['title' => 'Commandes'])

    <form method="GET" class="bg-dark-100 rounded-xl p-4 grid grid-cols-1 md:grid-cols-4 gap-3">
        @foreach($query as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
        <input type="text" name="search" value="{{ request('search') }}" placeholder="N° de commande" class="md:col-span-2 px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
        <select name="status" class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg">
            <option value="">Tous les statuts</option>
            @foreach(['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'] as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ $orderStatus($s) }}</option>
            @endforeach
        </select>
        <button class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
    </form>

    <p class="text-xs text-gray-500">Seules les lignes de votre rubrique sont affichées. Une commande qui contient aussi d'autres articles n'en montre ni le détail ni le total.</p>

    <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-gray-400 border-b border-dark-200">
                <th class="px-4 py-3">Commande</th><th class="px-4 py-3">Date</th><th class="px-4 py-3">Client</th>
                <th class="px-4 py-3">Articles de la rubrique</th><th class="px-4 py-3 text-right">Montant (rubrique)</th><th class="px-4 py-3">Statut</th><th class="px-4 py-3"></th>
            </tr></thead>
            <tbody>
                @forelse($orders as $order)
                    <tr class="border-b border-dark-200 hover:bg-dark-50/50">
                        <td class="px-4 py-3 text-white font-medium">{{ $order->order_number }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $order->created_at->displayTz()->format('d/m/Y H:i') }}</td>
                        <td class="px-4 py-3 text-gray-300">{{ $clientName($order->user) }}</td>
                        <td class="px-4 py-3 text-gray-300">
                            @foreach($order->items as $item)
                                <div>{{ $item->quantity }} × {{ $item->product_name ?? 'Produit' }}</div>
                            @endforeach
                        </td>
                        <td class="px-4 py-3 text-right text-white">{{ $money($order->items->sum('total_price')) }}</td>
                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 text-xs rounded-full bg-dark-50 border border-dark-200 text-gray-200">{{ $orderStatus($order->status) }}</span>
                            @if($order->refunded_at)<span class="block text-xs text-red-300 mt-1">Remboursée</span>@endif
                        </td>
                        <td class="px-4 py-3 text-right"><a href="{{ route('admin.division.orders.show', ['order' => $order] + $query) }}" class="text-primary-400 hover:text-primary-300"><i class="fas fa-eye mr-1"></i>Détail</a></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-gray-500">Aucune commande dans votre rubrique.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="p-4">{{ $orders->links() }}</div>
    </div>
</div>
@endsection
