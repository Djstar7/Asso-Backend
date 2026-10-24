@if(auth()->user()->isAdmin() && !auth()->user()->isTechnicalAccount())
<div class="bg-dark-100 rounded-xl border border-primary-500/30 p-6 space-y-4">
    <h2 class="text-lg text-white font-semibold">Arbitrage ASSO</h2>
    <a class="text-primary-400" href="{{ route('admin.disputes.resolution.index') }}">Échéances, dossiers avant livraison et avances</a>
    @foreach(\App\Models\DisputeResolutionTask::with('assignee')->where('dispute_id', $dispute->id)->whereNull('completed_at')->get() as $task)
        <p class="text-sm text-gray-300">Responsable : {{ $task->assignee?->name ?? $task->responsible }} · Échéance : {{ $task->due_at->displayTz()->format('d/m/Y H:i') }} · Escalades : {{ $task->escalations }}<br>{{ $task->expected_proof }}</p>
    @endforeach
    @if($dispute->isOpen() && !$dispute->refunded_at)
        <p class="text-sm text-gray-300">Vérifiez les justificatifs avant de décider. Le remboursement exceptionnel crédite le Wallet client et arrête les courses encore actives. Le recours vendeur ou transporteur reste distinct.</p>
        <form method="POST" action="{{ route('admin.disputes.resolution.refund', $dispute) }}" class="space-y-2">
            @csrf
            <label class="block text-sm text-gray-300">Justificatif examiné
                <select name="attachment_id" required class="block w-full bg-dark-200 text-white rounded p-2">
                    <option value="">Choisir une pièce du dossier</option>
                    @foreach($dispute->attachments as $piece)<option value="{{ $piece->id }}">Pièce #{{ $piece->id }} — {{ $piece->author_type }}</option>@endforeach
                </select>
            </label>
            <textarea name="note" required minlength="10" maxlength="2000" placeholder="Décision motivée : faits vérifiés, garde du produit, responsabilité et suite du recours" class="w-full bg-dark-200 text-white rounded p-2"></textarea>
            <button class="bg-red-700 text-white rounded px-4 py-2">Autoriser le remboursement de {{ $fcfa($dispute->disputedTotal()) }}</button>
        </form>
        @foreach($dispute->shipments as $mission)
            @if($mission->type !== 'replacement' && $mission->isPaid() && $mission->status !== 'confirmed' && !\App\Models\DisputeReceipt::where('dispute_shipment_id', $mission->id)->exists())
                <form method="POST" action="{{ route('admin.disputes.resolution.receipt', $mission) }}" class="space-y-2 border-t border-dark-200 pt-3">
                    @csrf
                    <p class="text-sm text-white">Réception indépendante — course #{{ $mission->id }}</p>
                    <select name="attachment_id" required class="w-full bg-dark-200 text-white rounded p-2">
                        <option value="">Justificatif vérifié</option>
                        @foreach($dispute->attachments as $piece)<option value="{{ $piece->id }}">Pièce #{{ $piece->id }} — {{ $piece->author_type }}</option>@endforeach
                    </select>
                    <input name="location" required maxlength="255" placeholder="Agence autorisée ou lieu réel de réception" class="w-full bg-dark-200 text-white rounded p-2">
                    <textarea name="note" required minlength="10" maxlength="2000" placeholder="Identification du produit et vérifications effectuées" class="w-full bg-dark-200 text-white rounded p-2"></textarea>
                    <button class="bg-blue-700 text-white rounded px-4 py-2">Enregistrer la réception avec délai de contestation</button>
                </form>
            @endif
            @if(!$mission->isPaid() && $mission->status !== 'awaiting_preparation')
                <form method="POST" action="{{ route('admin.disputes.resolution.advance', $mission) }}" class="space-y-2 border-t border-dark-200 pt-3">
                    @csrf
                    <p class="text-gray-300 text-sm">Course #{{ $mission->id }} : {{ $fcfa($mission->carrier_amount) }} à financer après sélection du partenaire.</p>
                    <textarea name="note" required minlength="10" maxlength="2000" placeholder="Motif et autorisation de prise en charge par ASSO" class="w-full bg-dark-200 text-white rounded p-2"></textarea>
                    <button class="bg-blue-700 text-white rounded px-4 py-2">Financer le transport par ASSO</button>
                </form>
            @endif
        @endforeach
        @foreach(\App\Models\DisputeReceipt::whereHas('shipment', fn ($q) => $q->where('dispute_id', $dispute->id))->whereNull('accepted_at')->get() as $receipt)
            <div class="text-sm text-gray-300 border-t border-dark-200 pt-3">
                <p>Réception #{{ $receipt->id }} — {{ $receipt->location }} · Contestation jusqu’au {{ $receipt->contest_until->displayTz()->format('d/m/Y H:i') }}</p>
                @if($receipt->contested_at)<p class="text-orange-300">Contestation : {{ $receipt->contest_note }} · Pièce #{{ $receipt->contest_attachment_id }}</p>@endif
                <form method="POST" action="{{ route('admin.disputes.resolution.accept', $receipt) }}" class="space-y-2">
                    @csrf
                    <textarea name="note" required minlength="10" maxlength="2000" placeholder="Motif de validation après examen contradictoire" class="w-full bg-dark-200 text-white rounded p-2"></textarea>
                    <button class="bg-blue-700 text-white rounded px-4 py-2">Valider la réception par arbitrage</button>
                </form>
            </div>
        @endforeach
    @endif
</div>
@endif
