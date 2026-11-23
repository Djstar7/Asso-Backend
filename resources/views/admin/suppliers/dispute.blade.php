@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Litige CJ</h1>
        <p class="text-gray-400">
            Commande #{{ $supplierOrder->order?->order_number }} · CJ {{ $supplierOrder->external_order_id }} · {{ $supplierOrder->statusLabel() }}.
            Le litige demande à CJ un remboursement sur le solde CJ ou le renvoi du produit. Il ne rembourse pas le client : traitez-le par une réclamation si besoin.
        </p>
    </div>
    @include('admin.suppliers._tabs')

    <a href="{{ route('admin.suppliers.cj.orders.index', ['status' => 'all']) }}" class="inline-block mb-4 text-sm text-gray-300 hover:text-white"><i class="fas fa-arrow-left mr-1"></i>Commandes CJ</a>

    @if($supplierOrder->disputes->isNotEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
            <h2 class="text-lg font-semibold text-white mb-3">Litiges de cette commande</h2>
            <div class="space-y-3 text-sm">
                @foreach($supplierOrder->disputes as $dispute)
                    <div class="border-b border-dark-200 pb-3">
                        <p class="text-gray-200">
                            {{ $dispute->reason_name }} · {{ \App\Models\SupplierDispute::EXPECT_LABELS[$dispute->expect_type] ?? '' }}
                            <span class="text-xs px-2 py-1 rounded ml-2 {{ $dispute->isOpen() ? 'bg-yellow-900/40 text-yellow-300' : 'bg-dark-200 text-gray-300' }}">{{ $dispute->stateLabel() }}</span>
                        </p>
                        <p class="text-xs text-gray-500">
                            {{ $dispute->external_dispute_id ? 'N° CJ '.$dispute->external_dispute_id : 'N° CJ en attente' }}
                            · ouvert le {{ $dispute->created_at->displayTz()->format('d/m/Y H:i') }}{{ $dispute->opener ? ' par '.$dispute->opener->first_name : '' }}
                            · demandé {{ number_format((float) $dispute->amount_usd, 2, ',', ' ') }} USD
                            @if($dispute->refund_usd !== null) · remboursé {{ number_format((float) $dispute->refund_usd, 2, ',', ' ') }} USD @endif
                            @if($dispute->resend_order_id) · renvoi {{ $dispute->resend_order_id }} @endif
                        </p>
                        @foreach((array) $dispute->lines as $line)
                            <p class="text-xs text-gray-400">{{ $line['quantity'] }} × {{ $line['name'] ?? $line['lineItemId'] }}</p>
                        @endforeach
                        <p class="text-xs text-gray-400 mt-1">« {{ $dispute->message }} »</p>
                        @if($dispute->last_error)<p class="text-xs text-red-300">{{ $dispute->last_error }}</p>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <h2 class="text-lg font-semibold text-white mb-3">Ouvrir un litige</h2>
        @if($error)
            <p class="text-sm text-red-300">{{ $error }}</p>
        @elseif(! $form || $form['products'] === [])
            <p class="text-sm text-gray-400">CJ ne propose aucun article ouvrable en litige pour cette commande pour l’instant (litige déjà ouvert, ou délai dépassé).</p>
        @else
            <form action="{{ route('admin.suppliers.cj.orders.dispute.open', $supplierOrder) }}" method="POST" class="space-y-4 text-sm">
                @csrf
                <div>
                    <p class="text-gray-400 mb-2">Articles concernés (quantité)</p>
                    @foreach($form['products'] as $product)
                        <label class="flex items-center gap-3 mb-2">
                            <input type="number" min="0" max="{{ (int) ($product['quantity'] ?? 1) }}" name="quantities[{{ $product['lineItemId'] }}]"
                                   value="{{ old('quantities.'.$product['lineItemId'], (int) ($product['quantity'] ?? 1)) }}" class="w-20 px-2 py-1 bg-dark-200 rounded text-white">
                            <span class="text-gray-200">{{ \Illuminate\Support\Str::limit($product['cjProductName'] ?? $product['sku'] ?? '', 80) }}</span>
                            <span class="text-xs text-gray-500">{{ $product['sku'] ?? '' }} · {{ number_format((float) ($product['price'] ?? 0), 2, ',', ' ') }} USD × {{ (int) ($product['quantity'] ?? 1) }}</span>
                        </label>
                    @endforeach
                    @if($form['max_amount'] !== null)
                        <p class="text-xs text-gray-500">Montant maximal remboursable par CJ : {{ number_format($form['max_amount'], 2, ',', ' ') }} USD.</p>
                    @endif
                </div>
                <label class="block">
                    <span class="text-gray-400">Motif</span>
                    <select name="reason_id" required class="mt-1 w-full md:w-1/2 px-3 py-2 bg-dark-200 rounded text-white">
                        @foreach($form['reasons'] as $reason)
                            <option value="{{ $reason['id'] }}" @selected((int) old('reason_id') === $reason['id'])>{{ $reason['name'] }}</option>
                        @endforeach
                    </select>
                </label>
                <div>
                    <span class="text-gray-400">Résultat demandé</span>
                    <div class="flex gap-4 mt-1">
                        @foreach($form['expect'] ?: [1] as $expect)
                            <label class="flex items-center gap-2 text-gray-200">
                                <input type="radio" name="expect_type" value="{{ $expect }}" @checked((int) old('expect_type', ($form['expect'] ?: [1])[0]) === $expect)>
                                {{ \App\Models\SupplierDispute::EXPECT_LABELS[$expect] ?? $expect }}
                            </label>
                        @endforeach
                    </div>
                </div>
                <label class="block">
                    <span class="text-gray-400">Message à CJ (en anglais de préférence)</span>
                    <textarea name="message" required minlength="10" maxlength="500" rows="4" class="mt-1 w-full px-3 py-2 bg-dark-200 rounded text-white"
                              placeholder="Parcel received damaged at our Douala warehouse: ...">{{ old('message') }}</textarea>
                </label>
                <button class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-paper-plane mr-1"></i>Envoyer le litige à CJ</button>
            </form>
        @endif
    </div>
</div>
@endsection
