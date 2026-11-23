@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Commandes clients qui contiennent des produits CJ. Validez-les pour que ASSO les passe chez CJ et les paie avec le solde CJ ; CJ livre l’entrepôt de Douala. La commande du client est confirmée seulement après le paiement CJ.</p>
    </div>
    @include('admin.suppliers._tabs')

    @if($cj->is_sandbox)
        <div class="mb-4 p-3 rounded-lg bg-yellow-900/30 border border-yellow-700 text-yellow-200 text-sm">
            <i class="fas fa-flask mr-1"></i>Bac à sable : les commandes envoyées à CJ sont des commandes de test, le paiement est simulé et rien n’est expédié. Décochez « Bac à sable » dans l’onglet Connexion pour vendre réellement.
        </div>
    @endif
    @if($cj->balanceIsLow())
        <div class="mb-4 p-3 rounded-lg bg-red-900/30 border border-red-700 text-red-200 text-sm">
            <i class="fas fa-triangle-exclamation mr-1"></i>Solde CJ bas : {{ number_format((float) $cj->balance_usd, 2, ',', ' ') }} USD. Rechargez-le sur le site CJ pour que les commandes puissent être payées.
        </div>
    @endif

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-4 mb-4">
        <form action="{{ route('admin.suppliers.cj.orders.receive') }}" method="POST" class="flex flex-wrap items-center gap-2">
            @csrf
            <label for="receive-code" class="text-sm text-white font-semibold"><i class="fas fa-barcode mr-1"></i>Réception à l’entrepôt</label>
            <input id="receive-code" name="code" value="{{ old('code') }}" autofocus autocomplete="off" placeholder="Scannez ou saisissez le n° de suivi, le n° CJ ou le n° de commande"
                   class="px-3 py-2 bg-dark-200 rounded text-white text-sm flex-1 min-w-[16rem]">
            <button class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-box-open mr-1"></i>Marquer reçu</button>
        </form>
        <p class="text-xs text-gray-500 mt-2">Le client est prévenu de l’arrivée à Douala. En livraison à domicile, la course part aussitôt vers les livreurs ; en livraison par transporteur, remettez ensuite le colis depuis Expéditions.</p>
    </div>

    <div class="flex flex-wrap gap-2 mb-4 text-xs">
        @php($todo = collect($counts)->only([...\App\Models\SupplierOrder::SUBMITTABLE, 'submitting', 'supplier_cancelled', 'delivered'])->sum())
        <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => 'todo']) }}" class="px-2 py-1 rounded {{ $status === 'todo' ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">À traiter ({{ $todo }})</a>
        <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => 'transit']) }}" class="px-2 py-1 rounded {{ $status === 'transit' ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">Attendues à Douala ({{ $transitCount }})</a>
        @if($lateCount > 0)
            <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => 'late']) }}" class="px-2 py-1 rounded {{ $status === 'late' ? 'bg-primary-600 text-white' : 'bg-red-900/50 text-red-200' }}">En retard ({{ $lateCount }})</a>
        @endif
        @foreach(\App\Models\SupplierOrder::STATUS_LABELS as $value => $label)
            @if(($counts[$value] ?? 0) > 0)
                <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => $value]) }}" class="px-2 py-1 rounded {{ $status === $value ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">{{ $label }} ({{ $counts[$value] }})</a>
            @endif
        @endforeach
        <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => 'all']) }}" class="px-2 py-1 rounded {{ $status === 'all' ? 'bg-primary-600 text-white' : 'bg-dark-200 text-gray-300' }}">Toutes ({{ array_sum($counts) }})</a>
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <form method="GET" class="flex gap-2 mb-4">
            <input type="hidden" name="status" value="{{ $status }}">
            <input name="q" value="{{ request('q') }}" placeholder="N° ASSO-…, n° CJ ou n° de suivi" class="px-3 py-2 bg-dark-200 rounded text-white text-sm w-80">
            <button class="px-3 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-magnifying-glass"></i></button>
        </form>

        @if($orders->isEmpty())
            <p class="text-sm text-gray-400">Aucune commande dans cette liste. Les commandes payées qui contiennent des produits CJ apparaissent ici automatiquement.</p>
        @else
            <form id="submit-form" action="{{ route('admin.suppliers.cj.orders.submit') }}" method="POST">@csrf</form>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-2"></th>
                            <th class="py-2 pr-4">Commande</th>
                            <th class="py-2 pr-4">Articles CJ</th>
                            <th class="py-2 pr-4">Coût CJ</th>
                            <th class="py-2 pr-4">État</th>
                            <th class="py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200">
                        @foreach($orders as $row)
                            @php($order = $row->order)
                            <tr class="align-top">
                                <td class="py-2 pr-2">
                                    @if($row->isSubmittable())
                                        <input type="checkbox" name="ids[]" value="{{ $row->id }}" form="submit-form" class="rounded bg-dark-200">
                                    @endif
                                </td>
                                <td class="py-2 pr-4">
                                    @if($order)
                                        <a href="{{ route('admin.asso-orders.show', $order) }}" class="text-primary-400 hover:text-primary-300">#{{ $order->order_number }}</a>
                                        <p class="text-xs text-gray-500">{{ $order->user?->full_name ?? trim(($order->user?->first_name ?? '').' '.($order->user?->last_name ?? '')) }} · {{ $order->created_at?->displayTz()->format('d/m H:i') }}</p>
                                        <p class="text-xs text-gray-500">{{ number_format((float) $order->total, 0, ',', ' ') }} FCFA payés</p>
                                    @endif
                                    @if($row->external_order_id)
                                        <p class="text-xs text-gray-400 mt-1">CJ {{ $row->external_order_id }}</p>
                                    @endif
                                    @if($row->is_sandbox)<span class="text-xs text-yellow-300">test</span>@endif
                                </td>
                                <td class="py-2 pr-4 text-gray-300">
                                    @foreach($row->items as $item)
                                        <p>{{ $item->quantity }} × {{ \Illuminate\Support\Str::limit($item->orderItem?->product_name, 50) }}
                                            @if($item->orderItem?->variant_attributes)<span class="text-xs text-gray-500">({{ implode(', ', (array) $item->orderItem->variant_attributes) }})</span>@endif
                                        </p>
                                    @endforeach
                                </td>
                                <td class="py-2 pr-4 text-gray-300 whitespace-nowrap">
                                    <p>prévu {{ $row->expected_cost_usd !== null ? number_format((float) $row->expected_cost_usd, 2, ',', ' ') : '-' }} USD</p>
                                    @if($row->quoted_cost_usd !== null)
                                        <p class="{{ (float) $row->quoted_cost_usd > (float) $row->expected_cost_usd ? 'text-yellow-300' : 'text-gray-400' }}">CJ {{ number_format((float) $row->quoted_cost_usd, 2, ',', ' ') }} USD</p>
                                    @endif
                                    @if($row->logistic_name)<p class="text-xs text-gray-500">{{ $row->logistic_name }}</p>@endif
                                </td>
                                <td class="py-2 pr-4 max-w-xs">
                                    <span class="text-xs px-2 py-1 rounded {{ match(true) {
                                        in_array($row->status, ['paid', 'shipped', 'received'], true) => 'bg-green-900/40 text-green-300',
                                        $row->status === 'delivered' => 'bg-blue-900/40 text-blue-300',
                                        in_array($row->status, ['failed', 'supplier_cancelled', 'awaiting_balance'], true) => 'bg-red-900/40 text-red-300',
                                        in_array($row->status, ['needs_review'], true) => 'bg-yellow-900/40 text-yellow-300',
                                        default => 'bg-dark-200 text-gray-300' } }}">{{ $row->statusLabel() }}</span>
                                    @if($row->isLate())
                                        <span class="text-xs px-2 py-1 rounded bg-red-900/40 text-red-300 ml-1">En retard</span>
                                    @endif
                                    @if($row->tracking_number)
                                        <p class="text-xs text-gray-300 mt-1">Suivi : {{ $row->tracking_number }}</p>
                                    @endif
                                    @if($row->logistics_event)
                                        <p class="text-xs text-gray-400 mt-1">{{ \Illuminate\Support\Str::limit($row->logistics_event, 150) }}</p>
                                    @endif
                                    @if($row->hub_received_at)
                                        <p class="text-xs text-green-300 mt-1">Reçue le {{ $row->hub_received_at->displayTz()->format('d/m H:i') }}{{ $row->receiver ? ' par '.$row->receiver->first_name : '' }}</p>
                                    @elseif($row->expected_hub_at && in_array($row->status, \App\Models\SupplierOrder::RECEIVABLE, true))
                                        <p class="text-xs text-gray-500 mt-1">Attendue à Douala vers le {{ $row->expected_hub_at->displayTz()->format('d/m') }}</p>
                                    @endif
                                    @foreach($row->disputes as $dispute)
                                        <p class="text-xs mt-1 {{ $dispute->isOpen() ? 'text-yellow-300' : 'text-gray-400' }}"><i class="fas fa-scale-balanced mr-1"></i>Litige : {{ $dispute->stateLabel() }}</p>
                                    @endforeach
                                    @if($row->last_error)
                                        <p class="text-xs text-red-300 mt-1 break-words">{{ \Illuminate\Support\Str::limit($row->last_error, 300) }}</p>
                                    @endif
                                    @if($row->approver)
                                        <p class="text-xs text-gray-500 mt-1">Validée par {{ $row->approver->first_name }} {{ $row->approved_at?->displayTz()->format('d/m H:i') }}</p>
                                    @endif
                                </td>
                                <td class="py-2 space-y-1 text-xs">
                                    @if($row->status === 'needs_review' && $row->pay_id)
                                        <form action="{{ route('admin.suppliers.cj.orders.submit') }}" method="POST">@csrf
                                            <input type="hidden" name="ids[]" value="{{ $row->id }}"><input type="hidden" name="force" value="1">
                                            <button class="text-yellow-300 hover:text-white"><i class="fas fa-credit-card mr-1"></i>Payer quand même</button>
                                        </form>
                                    @endif
                                    @if(in_array($row->status, ['paid', 'shipped', 'delivered'], true))
                                        <form action="{{ route('admin.suppliers.cj.orders.sync', $row) }}" method="POST">@csrf
                                            <button class="text-gray-300 hover:text-white"><i class="fas fa-rotate mr-1"></i>Relire chez CJ</button>
                                        </form>
                                        @if($row->is_sandbox)
                                            <form action="{{ route('admin.suppliers.cj.orders.sandbox', $row) }}" method="POST">@csrf
                                                <button class="text-yellow-300 hover:text-white"><i class="fas fa-forward mr-1"></i>Test : étape suivante</button>
                                            </form>
                                        @endif
                                    @endif
                                    @if(in_array($row->status, \App\Models\SupplierOrder::RECEIVABLE, true))
                                        <form action="{{ route('admin.suppliers.cj.orders.receive-one', $row) }}" method="POST">@csrf
                                            <button class="text-green-300 hover:text-white"><i class="fas fa-box-open mr-1"></i>Reçu à l’entrepôt</button>
                                        </form>
                                    @endif
                                    @if(in_array($row->status, \App\Models\SupplierOrder::DISPUTABLE, true))
                                        <a href="{{ route('admin.suppliers.cj.orders.dispute', $row) }}" class="block text-gray-300 hover:text-white"><i class="fas fa-scale-balanced mr-1"></i>Litige CJ</a>
                                    @endif
                                    @if($row->isSubmittable())
                                        <details>
                                            <summary class="cursor-pointer text-red-300 hover:text-white"><i class="fas fa-ban mr-1"></i>Annuler et rembourser</summary>
                                            <form action="{{ route('admin.suppliers.cj.orders.cancel', $row) }}" method="POST" class="mt-2 space-y-1">@csrf
                                                <input name="reason" required maxlength="500" placeholder="Motif communiqué au client" class="w-56 px-2 py-1 bg-dark-200 rounded text-white">
                                                <button class="px-2 py-1 bg-red-700 text-white rounded">Confirmer</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="flex flex-wrap items-center justify-between gap-4 mt-4">
                <button type="submit" form="submit-form" class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-paper-plane mr-1"></i>Valider et envoyer à CJ</button>
                <div>{{ $orders->links() }}</div>
            </div>
        @endif
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-warehouse mr-2"></i>Livraison des colis CJ et validation</h2>
        <p class="text-xs text-gray-500 mb-4">Champs vides : nom, adresse et téléphone de la boutique « {{ \App\Support\ImportHub::NAME }} ».</p>
        <form action="{{ route('admin.suppliers.cj.orders.settings') }}" method="POST" class="grid gap-4 md:grid-cols-3 text-sm">
            @csrf @method('PUT')
            @foreach([
                'ship_to_name' => 'Destinataire', 'ship_to_phone' => 'Téléphone', 'ship_to_address' => 'Adresse',
                'ship_to_city' => 'Ville', 'ship_to_province' => 'Région', 'ship_to_zip' => 'Code postal',
            ] as $field => $label)
                <label class="block">
                    <span class="text-gray-400">{{ $label }}</span>
                    <input name="{{ $field }}" value="{{ old($field, $cj->setting($field)) }}" class="mt-1 w-full px-3 py-2 bg-dark-200 rounded text-white">
                </label>
            @endforeach
            <label class="block">
                <span class="text-gray-400">Écart de coût toléré (%)</span>
                <input type="number" step="0.5" min="0" max="100" name="cost_tolerance_percent" value="{{ old('cost_tolerance_percent', $cj->setting('cost_tolerance_percent', 15)) }}" class="mt-1 w-full px-3 py-2 bg-dark-200 rounded text-white">
            </label>
            <label class="flex items-center gap-2 md:col-span-2 mt-6">
                <input type="hidden" name="auto_submit" value="0">
                <input type="checkbox" name="auto_submit" value="1" @checked($cj->setting('auto_submit', false)) class="rounded bg-dark-200">
                <span class="text-gray-300">Envoyer à CJ dès le paiement du client, sans validation (à activer une fois le circuit éprouvé)</span>
            </label>
            <div class="md:col-span-3">
                <button class="px-4 py-2 text-sm bg-dark-200 text-white rounded-lg"><i class="fas fa-save mr-1"></i>Enregistrer</button>
            </div>
        </form>
    </div>
</div>
@endsection
