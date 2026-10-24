@extends('admin.layouts.app')
@section('content')
<div class="p-6 space-y-6 text-gray-300">
    <a class="text-primary-400" href="{{ route('admin.disputes.index') }}">Réclamations / Litiges</a>
    <h1 class="text-2xl text-white font-bold">Résolution et déblocage des dossiers</h1>
    @include('admin.wholesale_orders._flash')
    <div class="bg-dark-100 p-5 rounded-xl space-y-3">
        <h2 class="text-lg text-white">Attentes et réaffectations</h2>
        @forelse($tasks as $task)
            <p class="border-b border-dark-200 pb-2">
                @if($task->dispute)<a class="text-primary-400" href="{{ route('admin.disputes.show', $task->dispute) }}">{{ $task->dispute->number }}</a>
                @else <a class="text-primary-400" href="{{ route('admin.disputes.resolution.index', ['claim_id' => $task->order_delivery_claim_id]) }}">Commande {{ $task->claim?->order?->order_number }}</a> @endif
                · {{ $task->stageLabel() }} · {{ $task->assignee?->name ?? $task->responsible }} · {{ $task->due_at->displayTz()->format('d/m/Y H:i') }} · {{ $task->escalations }} escalade(s)<br>
                {{ $task->expected_proof }} @if($task->last_error)<span class="text-orange-300">{{ $task->last_error }}</span>@endif
            </p>
        @empty<p>Aucune attente enregistrée.</p>@endforelse
        {{ $tasks->links() }}
    </div>
    <div class="bg-dark-100 p-5 rounded-xl space-y-3">
        <h2 class="text-lg text-white">Commande payée non livrée</h2>
        <p class="text-sm">Les dépassements sont détectés automatiquement. Pour ouvrir immédiatement un dossier, indiquez l’identifiant de la commande.</p>
        <form method="POST" action="{{ route('admin.disputes.resolution.open-claim', ['order' => '__ORDER__']) }}" onsubmit="this.action = this.action.replace('__ORDER__', this.elements.order_id.value)" class="space-y-2">
            @csrf
            <input type="number" name="order_id" min="1" required placeholder="Identifiant commande" class="bg-dark-200 p-2 rounded text-white">
            <select name="reason" class="bg-dark-200 p-2 rounded text-white">
                @foreach(\App\Models\OrderDeliveryClaim::REASONS as $reason)<option value="{{ $reason }}">{{ \App\Models\OrderDeliveryClaim::REASON_LABELS[$reason] }}</option>@endforeach
            </select>
            <input name="description" required minlength="10" maxlength="2000" placeholder="Faits signalés" class="bg-dark-200 p-2 rounded text-white">
            <button class="bg-blue-700 px-4 py-2 rounded text-white">Ouvrir le dossier</button>
        </form>
        @if(request()->filled('claim_id'))<a class="block text-primary-400" href="{{ route('admin.disputes.resolution.index') }}">Afficher tous les dossiers</a>@endif
        @foreach($claims as $claim)
            <div class="border-t border-dark-200 pt-3 space-y-2">
                <p class="text-white">Commande {{ $claim->order?->order_number }} — {{ \App\Models\OrderDeliveryClaim::REASON_LABELS[$claim->reason] ?? $claim->reason }}</p>
                <p>{{ $claim->description }}</p>
                @foreach($claim->client_proofs ?? [] as $index => $path)
                    <a class="text-primary-400" href="{{ route('admin.disputes.resolution.proof', ['claim' => $claim, 'index' => $index]) }}">Justificatif client {{ $index + 1 }}</a>
                @endforeach
                <p class="text-sm">Encaissé : {{ number_format($claim->refund_amount ?? array_sum($claim->order->collectedAmounts()), 0, ',', ' ') }} FCFA. Le remboursement inclut les frais encaissés ; le recours transporteur est séparé.</p>
                @if($claim->status === 'open')
                <form method="POST" enctype="multipart/form-data" action="{{ route('admin.disputes.resolution.decide-claim', $claim) }}" class="space-y-2">
                    @csrf
                    <select name="decision" required class="bg-dark-200 p-2 rounded text-white"><option value="refund">Annuler et rembourser</option><option value="reject">Rejeter la réclamation motivément</option></select>
                    <textarea name="note" required minlength="10" maxlength="2000" placeholder="Décision et résultat de l’enquête" class="block w-full bg-dark-200 p-2 rounded text-white"></textarea>
                    <label class="block">Justificatif examiné <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required></label>
                    <button class="bg-blue-700 px-4 py-2 rounded text-white">Enregistrer la décision</button>
                </form>
                @else
                    <p>{{ $claim->status === 'refunded' ? 'Remboursé' : 'Rejeté' }} — {{ $claim->decision_note }}</p>
                    <a class="text-primary-400" href="{{ route('admin.disputes.resolution.proof', ['claim' => $claim, 'index' => 'decision']) }}">Justificatif de la décision</a>
                @endif
            </div>
        @endforeach
        {{ $claims->links() }}
    </div>
    <div class="bg-dark-100 p-5 rounded-xl space-y-3">
        <h2 class="text-lg text-white">Avances à régulariser</h2>
        @forelse($advances as $advance)
            <p>#{{ $advance->id }} · Course {{ $advance->dispute_shipment_id ?? '—' }} · Dossier avant livraison {{ $advance->order_delivery_claim_id ?? '—' }} · Débiteur {{ $advance->debtor_id ?? 'à établir' }} · {{ number_format($advance->amount, 0, ',', ' ') }} {{ $advance->currency }}<br>{{ $advance->note }}</p>
        @empty<p>Aucune avance en attente.</p>@endforelse
        {{ $advances->links() }}
    </div>
    <form method="POST" action="{{ route('admin.disputes.resolution.settings') }}" class="bg-dark-100 p-5 rounded-xl space-y-3">
        @csrf
        <h2 class="text-lg text-white">Délais et financement de secours</h2>
        @foreach(['review_hours' => 'Arbitrage ASSO', 'payment_hours' => 'Financement transport', 'receipt_contest_hours' => 'Contestation réception', 'local_transit_hours' => 'Transit local', 'international_transit_hours' => 'Transit international et douane', 'dispatch_hours' => 'Remise au transporteur', 'preparation_hours' => 'Préparation locale', 'import_preparation_hours' => 'Préparation import'] as $key => $label)
            <label class="block">{{ $label }} (heures) <input type="number" name="{{ $key }}" min="1" max="8760" required value="{{ \App\Models\Setting::get('dispute_'.$key, config('disputes.'.$key)) }}" class="bg-dark-200 p-2 rounded text-white"></label>
        @endforeach
        <label class="block">Plafond par avance automatique (FCFA ; 0 = décision manuelle)
            <input type="number" step="0.01" name="automatic_advance_limit" min="0" max="10000000" required value="{{ \App\Models\Setting::get('dispute_automatic_advance_limit', 0) }}" class="bg-dark-200 p-2 rounded text-white">
        </label>
        <button class="bg-blue-700 px-4 py-2 rounded text-white">Enregistrer les règles</button>
    </form>
</div>
@endsection
