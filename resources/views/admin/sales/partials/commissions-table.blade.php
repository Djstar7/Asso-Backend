{{-- P6 — Tableau des commissions (liste générale et fiche commercial).
     Variables : $commissions (paginator), $showAgent (bool). --}}
@php $fmt = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' F'; @endphp

@if($commissions->isEmpty())
    <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
        <i class="fas fa-handshake text-6xl text-gray-600 mb-4"></i>
        <h3 class="text-xl font-semibold text-white mb-2">Aucune commission</h3>
        <p class="text-gray-400">Aucune vente enregistrée avec ces filtres.</p>
    </div>
@else
    <div x-data="{ selected: [], dueIds: @js($commissions->where('status', 'due')->pluck('id')->values()) }">
        <!-- Paiement groupé -->
        <form id="bulk-pay-form" method="POST" action="{{ route('admin.sales.commissions.mark-paid') }}"
              class="bg-dark-100 rounded-xl shadow-lg p-4 mb-4 flex flex-col md:flex-row md:items-center gap-3"
              x-show="dueIds.length > 0">
            @csrf
            <p class="text-sm text-gray-300 flex-1">
                <span x-text="selected.length"></span> commission(s) « à payer » sélectionnée(s)
            </p>
            <input type="text" name="payout_reference" required maxlength="255"
                   placeholder="Référence du versement (Mobile Money, virement…)"
                   class="px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500 md:w-96">
            <template x-for="id in selected" :key="id">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <button type="submit" :disabled="selected.length === 0"
                    class="px-5 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all disabled:opacity-40 disabled:cursor-not-allowed">
                <i class="fas fa-check-double mr-2"></i>Marquer payée(s)
            </button>
        </form>

        <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-dark-200">
                        <th class="px-4 py-3">
                            <input type="checkbox" class="rounded"
                                   :checked="dueIds.length > 0 && selected.length === dueIds.length"
                                   @change="selected = $event.target.checked ? [...dueIds] : []">
                        </th>
                        <th class="px-4 py-3">Date</th>
                        @if($showAgent)<th class="px-4 py-3">Commercial</th>@endif
                        <th class="px-4 py-3">Code</th>
                        <th class="px-4 py-3">Vendeur</th>
                        <th class="px-4 py-3">Forfait</th>
                        <th class="px-4 py-3 text-right">Montant payé</th>
                        <th class="px-4 py-3">Réf. transaction</th>
                        <th class="px-4 py-3 text-right">Commission</th>
                        <th class="px-4 py-3">Statut</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($commissions as $c)
                        <tr class="border-b border-dark-200 hover:bg-dark-50/50 align-top">
                            <td class="px-4 py-3">
                                @if($c->status === 'due')
                                    <input type="checkbox" class="rounded" value="{{ $c->id }}" x-model.number="selected">
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-300 whitespace-nowrap">{{ $c->sold_at->displayTz()->format('d/m/Y H:i') }}</td>
                            @if($showAgent)
                                <td class="px-4 py-3">
                                    @if($c->salesAgent)
                                        <a href="{{ route('admin.sales.agents.show', $c->sales_agent_id) }}" class="text-primary-400 hover:underline">{{ $c->salesAgent->full_name }}</a>
                                    @else
                                        <span class="text-gray-500">—</span>
                                    @endif
                                </td>
                            @endif
                            <td class="px-4 py-3 font-mono text-gray-200">{{ $c->sales_code }}</td>
                            <td class="px-4 py-3 text-gray-200">
                                {{ $c->vendor?->name ?? 'Compte supprimé' }}
                                @if($c->vendor?->phone)<div class="text-xs text-gray-500">{{ $c->vendor->phone }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-gray-200">
                                {{ $c->package_name }}
                                @if($c->package_type)<div class="text-xs text-gray-500">{{ $c->package_type === 'certification' ? 'Certification' : 'Stockage' }}</div>@endif
                            </td>
                            <td class="px-4 py-3 text-right text-white whitespace-nowrap">{{ $fmt($c->amount_paid_xaf) }}</td>
                            <td class="px-4 py-3 font-mono text-xs text-gray-400">
                                {{ $c->transaction_reference ?? '—' }}
                                <div class="text-gray-500">{{ $c->payment_method }}</div>
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <span class="text-white font-semibold">{{ $fmt($c->commission_amount) }}</span>
                                <div class="text-xs text-gray-500">{{ rtrim(rtrim(number_format((float) $c->rate, 2, ',', ''), '0'), ',') }} %</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($c->status === 'due')
                                    <span class="px-3 py-1 bg-yellow-500/20 text-yellow-400 text-xs font-semibold rounded-full">À payer</span>
                                @elseif($c->status === 'paid')
                                    <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs font-semibold rounded-full">Payée</span>
                                    <div class="text-xs text-gray-500 mt-1">{{ $c->paid_at?->displayTz()->format('d/m/Y') }} — {{ $c->payout_reference }}</div>
                                    @if($c->paidBy)<div class="text-xs text-gray-600">par {{ $c->paidBy->name }}</div>@endif
                                @else
                                    <span class="px-3 py-1 bg-red-500/20 text-red-400 text-xs font-semibold rounded-full">Annulée</span>
                                    <div class="text-xs text-gray-500 mt-1">{{ $c->cancel_reason }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3" x-data="{ open: false }">
                                @if($c->status === 'due')
                                    <button type="button" @click="open = !open" class="text-xs text-red-400 hover:text-red-300">
                                        <i class="fas fa-ban mr-1"></i>Annuler
                                    </button>
                                    <form x-show="open" x-cloak method="POST" action="{{ route('admin.sales.commissions.cancel', $c) }}" class="mt-2 flex gap-2">
                                        @csrf
                                        <input type="text" name="cancel_reason" required maxlength="255" placeholder="Motif"
                                               class="px-2 py-1 bg-dark-50 text-white text-xs border border-dark-200 rounded w-36">
                                        <button type="submit" class="px-2 py-1 bg-red-600 text-white text-xs rounded hover:bg-red-700">OK</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $commissions->links() }}</div>
    </div>
@endif
