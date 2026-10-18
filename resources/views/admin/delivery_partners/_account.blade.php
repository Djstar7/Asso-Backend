{{-- Compte partenaire : ce qu'ASSO lui doit, son IBAN (Stripe) et ses versements. --}}
@php
    use App\Models\PartnerLedgerEntry;
    $input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white focus:border-primary-500 focus:outline-none';
    $fcfa = fn ($v) => number_format((float) $v, 0, ',', ' ') . ' FCFA';
    $hasIban = !empty($partner->stripe_account_id);
    $ready = $stripeState['ready'] ?? false;
@endphp
<div id="compte" class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
    <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-file-invoice-dollar mr-2 text-primary-400"></i>Compte partenaire</h2>
    <p class="text-sm text-gray-400 mb-4">Le prix des courses et du fret encaissé reste chez ASSO. Il s'ajoute ici à ce qu'on doit à {{ $partner->name }}, à lui virer sur son IBAN ou à enregistrer s'il est payé autrement.</p>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
        <div class="bg-dark-50 rounded-lg p-4">
            <p class="text-xs uppercase text-gray-500">Gagné</p>
            <p class="text-xl font-bold text-white">{{ $fcfa($ledger['earned']) }}</p>
        </div>
        <div class="bg-dark-50 rounded-lg p-4">
            <p class="text-xs uppercase text-gray-500">Déjà versé</p>
            <p class="text-xl font-bold text-white">{{ $fcfa($ledger['paid']) }}</p>
        </div>
        <div class="bg-dark-50 rounded-lg p-4 border {{ $ledger['due'] > 0 ? 'border-yellow-500/50' : 'border-transparent' }}">
            <p class="text-xs uppercase text-gray-500">Dû</p>
            <p class="text-xl font-bold {{ $ledger['due'] > 0 ? 'text-yellow-300' : 'text-white' }}">{{ $fcfa($ledger['due']) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <!-- Virement IBAN -->
        <div class="border border-dark-200 rounded-lg p-4">
            <h3 class="text-white font-semibold mb-2"><i class="fas fa-university mr-2 text-primary-400"></i>Virement sur l'IBAN</h3>
            @if($hasIban)
                <p class="text-sm text-gray-300 mb-1">IBAN ****{{ $partner->stripe_external_last4 }} ({{ $partner->stripe_bank_country }}) — {{ $partner->stripe_account_holder_name }}</p>
                <p class="text-sm mb-3 {{ $ready ? 'text-green-300' : 'text-yellow-300' }}">
                    {{ $ready ? 'Compte activé par Stripe : virement possible.' : 'En attente d\'activation par Stripe' . (!empty($stripeState['requirements_due']) ? ' (informations demandées : ' . implode(', ', $stripeState['requirements_due']) . ')' : '') . '.' }}
                </p>
                <form action="{{ route('admin.delivery-partners.payout', $partner) }}" method="POST" class="flex gap-2 items-end"
                      onsubmit="return confirm('Virer ce montant sur l\'IBAN de {{ addslashes($partner->name) }} ?')">
                    @csrf
                    <div class="flex-1">
                        <label class="block text-sm text-gray-300 mb-1">Montant (FCFA)</label>
                        <input type="number" step="1" min="1" max="{{ max(0, $ledger['due']) }}" name="amount" value="{{ max(0, round($ledger['due'])) }}" class="{{ $input }}">
                    </div>
                    <button type="submit" @disabled(!$ready || $ledger['due'] <= 0)
                            class="px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white rounded-lg hover:shadow-lg disabled:opacity-40">
                        Virer
                    </button>
                </form>
                <p class="text-xs text-gray-500 mt-2">Converti dans la devise de la banque au taux du jour, envoyé par Stripe.</p>
            @else
                <p class="text-sm text-gray-400">Aucun IBAN synchronisé. Renseignez-le ci-dessous pour pouvoir lui virer son dû.</p>
            @endif
        </div>

        <!-- Versement hors application -->
        <div class="border border-dark-200 rounded-lg p-4">
            <h3 class="text-white font-semibold mb-2"><i class="fas fa-hand-holding-usd mr-2 text-primary-400"></i>Payé autrement</h3>
            <p class="text-xs text-gray-500 mb-3">Espèces, Mobile Money, virement fait hors ASSO : enregistrez-le pour que le dû baisse.</p>
            <form action="{{ route('admin.delivery-partners.payouts.manual', $partner) }}" method="POST" class="grid grid-cols-2 gap-2">
                @csrf
                <input type="number" step="1" min="1" name="amount" required placeholder="Montant (FCFA)" class="{{ $input }}">
                <select name="payment_method" class="{{ $input }}">
                    @foreach(PartnerLedgerEntry::PAYMENT_METHODS as $value => $label)
                        @continue($value === 'stripe_iban')
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <input type="date" name="paid_on" required value="{{ today()->toDateString() }}" class="{{ $input }}">
                <input name="reference" placeholder="Référence" class="{{ $input }}">
                <input name="note" placeholder="Note" class="{{ $input }} col-span-2">
                <button type="submit" class="col-span-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">Enregistrer le versement</button>
            </form>
        </div>
    </div>

    <!-- Synchronisation de l'IBAN -->
    <details class="border border-dark-200 rounded-lg p-4 mb-6" @if(!$hasIban || $errors->hasAny(['iban', 'birth_date', 'address_line1'])) open @endif>
        <summary class="text-white font-semibold cursor-pointer">
            <i class="fas fa-sync-alt mr-2 text-primary-400"></i>{{ $hasIban ? 'Changer l\'IBAN' : 'Synchroniser l\'IBAN' }}
        </summary>
        <p class="text-xs text-gray-500 mt-2 mb-3">Comme pour les utilisateurs, Stripe exige l'identité du titulaire (représentant du partenaire) pour activer les virements. Ces informations sont envoyées à Stripe, pas conservées chez ASSO.</p>
        <form action="{{ route('admin.delivery-partners.iban', $partner) }}" method="POST" class="grid grid-cols-1 md:grid-cols-3 gap-3">
            @csrf
            <input name="iban" required placeholder="IBAN" value="{{ old('iban') }}" class="{{ $input }} md:col-span-2 uppercase">
            <input name="country" required maxlength="2" placeholder="Pays de la banque (FR)" value="{{ old('country', $partner->stripe_bank_country) }}" class="{{ $input }} uppercase">
            <input name="account_holder_name" required placeholder="Titulaire du compte" value="{{ old('account_holder_name', $partner->stripe_account_holder_name ?? $partner->name) }}" class="{{ $input }}">
            <input name="first_name" required placeholder="Prénom du représentant" value="{{ old('first_name') }}" class="{{ $input }}">
            <input name="last_name" required placeholder="Nom du représentant" value="{{ old('last_name') }}" class="{{ $input }}">
            <div>
                <label class="block text-xs text-gray-400 mb-1">Date de naissance</label>
                <input type="date" name="birth_date" required value="{{ old('birth_date') }}" class="{{ $input }}">
            </div>
            <input name="phone" required placeholder="Téléphone" value="{{ old('phone', $partner->phone) }}" class="{{ $input }} self-end">
            <input name="email" type="email" placeholder="E-mail" value="{{ old('email', $partner->email) }}" class="{{ $input }} self-end">
            <input name="address_line1" required placeholder="Adresse" value="{{ old('address_line1') }}" class="{{ $input }}">
            <input name="address_city" required placeholder="Ville" value="{{ old('address_city') }}" class="{{ $input }}">
            <input name="address_postal_code" required placeholder="Code postal" value="{{ old('address_postal_code') }}" class="{{ $input }}">
            <button type="submit" class="md:col-span-3 px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">Envoyer à Stripe</button>
        </form>
    </details>

    <!-- Historique -->
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="text-gray-400 text-xs uppercase text-left">
                <tr><th class="py-2">Date</th><th>Libellé</th><th>Moyen / référence</th><th class="text-right">Dû</th><th class="text-right">Versé</th></tr>
            </thead>
            <tbody class="divide-y divide-dark-200 text-gray-300">
                @forelse($ledgerEntries as $entry)
                    @php $isPayout = $entry->type === PartnerLedgerEntry::TYPE_PAYOUT; @endphp
                    <tr class="{{ $entry->status === PartnerLedgerEntry::STATUS_FAILED ? 'opacity-50 line-through' : '' }}">
                        <td class="py-2 whitespace-nowrap">{{ ($entry->paid_on ?? $entry->created_at)->format('d/m/Y') }}</td>
                        <td>{{ $entry->description }}
                            @if($entry->status === PartnerLedgerEntry::STATUS_PROCESSING)<span class="ml-1 text-xs text-yellow-300">(virement en cours)</span>@endif
                            @if($entry->status === PartnerLedgerEntry::STATUS_FAILED)<span class="ml-1 text-xs text-red-300">(échoué : {{ $entry->failure_message }})</span>@endif
                        </td>
                        <td class="text-xs">
                            @if($isPayout)
                                {{ PartnerLedgerEntry::PAYMENT_METHODS[$entry->payment_method] ?? $entry->payment_method }}
                                @if($entry->payout_amount) · {{ number_format((float) $entry->payout_amount, 2, ',', ' ') }} {{ $entry->payout_currency }}@endif
                                @if($entry->reference) · {{ $entry->reference }}@endif
                                @if($entry->recorder) · {{ $entry->recorder->name }}@endif
                            @endif
                        </td>
                        <td class="text-right whitespace-nowrap">{{ $isPayout ? '' : $fcfa($entry->amount) }}</td>
                        <td class="text-right whitespace-nowrap">{{ $isPayout ? $fcfa($entry->amount) : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-4 text-center text-gray-500">Aucune course réglée pour l'instant.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
