@php
    $money = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $rate = fn ($v) => rtrim(rtrim(number_format((float) $v, 2, ',', ''), '0'), ',') . ' %';
    $tones = ['pending' => 'bg-amber-500/20 text-amber-300', 'available' => 'bg-emerald-500/20 text-emerald-300', 'cancelled' => 'bg-gray-500/20 text-gray-300', 'clawed_back' => 'bg-red-500/20 text-red-300'];
@endphp
<div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
    <h2 class="text-lg font-semibold text-white px-6 pt-5 pb-3">Commissions</h2>
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-400 border-b border-dark-200">
            <th class="px-4 py-2">Vente</th>
            <th class="px-4 py-2">Référence</th>
            @if($showChief ?? false)<th class="px-4 py-2">Chef</th>@endif
            <th class="px-4 py-2 text-right">Commission ASSO</th>
            <th class="px-4 py-2 text-right">Taux</th>
            <th class="px-4 py-2 text-right">Montant</th>
            <th class="px-4 py-2">Statut</th>
        </tr></thead>
        <tbody>
            @forelse($commissions as $c)
                <tr class="border-b border-dark-200">
                    <td class="px-4 py-2 text-gray-300">{{ $c->sold_at->displayTz()->format('d/m/Y') }}</td>
                    <td class="px-4 py-2 text-white">{{ $c->reference }}</td>
                    @if($showChief ?? false)<td class="px-4 py-2 text-gray-300">{{ $c->user?->name }}</td>@endif
                    <td class="px-4 py-2 text-right text-gray-300">{{ $money($c->base_amount) }}</td>
                    <td class="px-4 py-2 text-right text-gray-300">{{ $rate($c->rate) }}</td>
                    <td class="px-4 py-2 text-right text-white font-medium">{{ $money($c->amount) }}</td>
                    <td class="px-4 py-2">
                        <span class="px-2 py-0.5 text-xs rounded-full {{ $tones[$c->status] ?? '' }}">{{ $c->statusLabel() }}</span>
                        @if((float) $c->clawback_due > 0)<span class="block text-xs text-red-300 mt-1">Reste à retenir : {{ $money($c->clawback_due) }}</span>@endif
                        @if($c->cancel_reason)<span class="block text-xs text-gray-500 mt-1">{{ $c->cancel_reason }}</span>@endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Aucune commission pour le moment.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if(method_exists($commissions, 'links'))<div class="p-4">{{ $commissions->links() }}</div>@endif
</div>
