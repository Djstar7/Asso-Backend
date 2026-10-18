{{--
    Retrait du Wallet d'un compte géré par ASSO : compte plateforme (Revenus ASSO
    › Retrait) ou compte interne d'un partenaire. Mêmes soldes par pays, retrait
    Mobile Money, IBAN Stripe (enregistrer puis valider), virement et historique.

    Variables : $account, $balances, $balancesError, $countries, $withdrawals,
    $routes (mobile, bank, iban, approve, refresh(fn $w)), $historyTitle, $tabKey.
--}}
@php
    use App\Http\Controllers\Admin\PlatformWithdrawalController;
    $money = fn ($v, $c) => number_format((float) $v, in_array($c, ['XAF', 'XOF']) ? 0 : 2, ',', ' ') . ' ' . $c;
    $mobile = $balances['methods']['kpay'] ?? null;
    $inputClass = 'w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100 focus:outline-none focus:border-primary-500';
    $bank = $balances['methods']['stripe'] ?? null;
    $source = $bank['source'] ?? null;
    $gatewayName = ['kpay' => 'KPay', 'elgiopay' => 'ElgioPay'][$mobile['gateway'] ?? ''] ?? null;
    $statusColor = fn ($s) => match ($s) {
        'completed' => 'text-emerald-400', 'failed', 'cancelled' => 'text-red-400', default => 'text-amber-400',
    };
@endphp
    @if($balancesError)
        <div class="p-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300">{{ $balancesError }}</div>
    @else
    @php
        // Onglet rouvert après une soumission bancaire / IBAN (erreur ou succès).
        $bankHint = $errors->any() || old('account_holder_name') !== null || old('currency') !== null;
    @endphp
    <div x-data="{
            tab: @js($bankHint ? 'bank' : null),
            init() {
                if (!this.tab) { try { this.tab = sessionStorage.getItem(@js($tabKey ?? 'asso.withdrawal.tab')); } catch (e) {} }
                if (!['mobile', 'bank'].includes(this.tab)) this.tab = 'mobile';
                this.$watch('tab', v => { try { sessionStorage.setItem(@js($tabKey ?? 'asso.withdrawal.tab'), v); } catch (e) {} });
            }
         }" class="space-y-6">

    {{-- Onglets : Mobile Money | Virement bancaire (comme Paramètres › Paiements) --}}
    <div class="bg-dark-100 rounded-lg shadow-lg border border-dark-200 sticky top-0 z-10">
        <nav class="flex space-x-6 px-6 overflow-x-auto" aria-label="Moyens de retrait">
            <button type="button" @click="tab = 'mobile'"
                    :class="tab === 'mobile' ? 'border-primary-500 text-primary-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors">
                <i class="fas fa-mobile-alt mr-2"></i>Mobile Money
                <span class="ml-1 text-xs text-gray-500">({{ count($countries) }} soldes par pays)</span>
            </button>
            <button type="button" @click="tab = 'bank'"
                    :class="tab === 'bank' ? 'border-emerald-500 text-emerald-500' : 'border-transparent text-gray-400 hover:text-gray-300 hover:border-gray-300'"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap transition-colors">
                <i class="fas fa-university mr-2"></i>Virement bancaire
                <span class="ml-1 text-xs text-gray-500">({{ $money($bank['available'] ?? 0, $bank['currency'] ?? 'EUR') }})</span>
            </button>
        </nav>
    </div>

    {{-- Soldes par pays --}}
    <div x-show="tab === 'mobile'" x-cloak>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold text-gray-100"><i class="fas fa-mobile-alt mr-2 text-primary-400"></i>Mobile Money par pays</h2>
            @if($gatewayName)<span class="text-xs text-gray-500">via {{ $gatewayName }}</span>@endif
        </div>

        @if(!($mobile['configured'] ?? false))
            <p class="mb-3 text-sm text-amber-300">Aucun prestataire Mobile Money actif : activez KPay ou ElgioPay dans Paramètres › Paiements.</p>
        @endif

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
            @foreach($countries as $country)
                @php $opened = old('country') === $country['key']; @endphp
                <div x-data="{ open: @js($opened) }" class="bg-dark-100 border border-dark-200 rounded-xl p-5 flex flex-col">
                    <div class="flex items-center justify-between">
                        <p class="font-semibold text-gray-100">{{ $country['name'] }}</p>
                        <span class="text-2xl leading-none">{{ $country['flag'] }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-3">Solde</p>
                    <p class="text-2xl font-bold text-gray-100">{{ $money($country['available'], $country['currency']) }}</p>
                    @if($country['shared_with'])
                        <p class="text-xs text-gray-500 mt-0.5">Solde {{ $country['currency'] }} commun avec {{ implode(', ', $country['shared_with']) }}.</p>
                    @endif

                    <p class="text-xs text-gray-500 mt-3 mb-1">Opérateurs actifs</p>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($country['providers'] as $code => $label)
                            <span class="px-2 py-0.5 rounded-full text-xs {{ isset($country['payable'][$code]) ? 'bg-primary-500/15 text-primary-300' : 'bg-dark-200 text-gray-500' }}">{{ $label }}</span>
                        @endforeach
                    </div>

                    <div class="mt-auto pt-4">
                        @if(!$country['payable'])
                            <p class="text-xs text-gray-500">
                                {{ $country['currency'] === 'USD' ? 'Les opérateurs de RD Congo versent en CDF : retrait USD indisponible.' : 'Aucun opérateur de ce pays pris en charge par le prestataire actif.' }}
                            </p>
                        @else
                            <button type="button" @click="open = !open" @disabled($country['available'] <= 0)
                                    class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold">
                                <i class="fas fa-arrow-up mr-1"></i>Retirer
                            </button>

                            <form x-show="open" x-cloak x-transition method="POST" action="{{ $routes['mobile'] }}" class="space-y-3 mt-4">
                                @csrf
                                <input type="hidden" name="country" value="{{ $country['key'] }}">
                                @php
                                    $first = array_key_first($country['payable']);
                                    $selected = $opened && isset($country['payable'][old('provider')]) ? old('provider') : $first;
                                @endphp
                                <div x-data="{ open: false, value: @js($selected), labels: @js($country['payable']) }" class="relative" @click.outside="open = false" @keydown.escape="open = false">
                                    <label class="block text-sm text-gray-300 mb-1">Opérateur</label>
                                    <input type="hidden" name="provider" :value="value">
                                    <button type="button" @click="open = !open"
                                            class="{{ $inputClass }} flex items-center justify-between text-left">
                                        <span x-text="labels[value]"></span>
                                        <i class="fas fa-chevron-down text-xs text-gray-400 transition-transform" :class="open && 'rotate-180'"></i>
                                    </button>
                                    <ul x-show="open" x-cloak x-transition.origin.top
                                        class="absolute z-20 mt-1 w-full bg-dark-100 border border-dark-300 rounded-lg shadow-xl overflow-hidden">
                                        <template x-for="(label, code) in labels" :key="code">
                                            <li @click="value = code; open = false"
                                                class="px-3 py-2 cursor-pointer flex items-center justify-between text-gray-200 hover:bg-primary-600/20"
                                                :class="value === code && 'bg-primary-600/10 text-primary-300'">
                                                <span x-text="label"></span>
                                                <i x-show="value === code" class="fas fa-check text-xs text-primary-400"></i>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-300 mb-1">Numéro bénéficiaire</label>
                                    <input type="tel" name="phone" value="{{ $opened ? old('phone') : '' }}" required class="{{ $inputClass }}">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-300 mb-1">Montant ({{ $country['currency'] }})</label>
                                    <input type="number" name="amount" min="1" max="{{ (int) $country['available'] }}" step="1" value="{{ $opened ? old('amount') : '' }}" required class="{{ $inputClass }}">
                                </div>
                                <div>
                                    <label class="block text-sm text-gray-300 mb-1">Note (facultatif)</label>
                                    <input type="text" name="notes" maxlength="300" value="{{ $opened ? old('notes') : '' }}" class="{{ $inputClass }}">
                                </div>
                                <button type="submit" onclick="this.disabled=true; this.form.submit();"
                                        class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-semibold">
                                    Confirmer le retrait
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Virement bancaire --}}
    <div x-show="tab === 'bank'" x-cloak>
        <div class="flex items-center justify-between mb-3">
            <h2 class="text-lg font-semibold text-gray-100"><i class="fas fa-university mr-2 text-emerald-400"></i>Virement bancaire</h2>
            <span class="text-xs text-gray-500">via Stripe</span>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- Solde et état de l'IBAN --}}
            <div class="bg-dark-100 border border-dark-200 rounded-xl p-5 space-y-4">
                <div>
                    <p class="text-xs text-gray-500">Solde bancaire</p>
                    <p class="text-2xl font-bold text-gray-100">{{ $money($bank['available'] ?? 0, $bank['currency'] ?? 'EUR') }}</p>
                    @if($source)
                        <p class="text-xs text-gray-500 mt-0.5">
                            Pris sur {{ $money($source['available'], $source['currency']) }} au taux de {{ rtrim(rtrim(number_format($source['rate'], 6, ',', ' '), '0'), ',') }}.
                        </p>
                    @endif
                </div>
                <div>
                    <p class="text-xs text-gray-500 mb-1">IBAN</p>
                    @php
                        $ibanStatus = $bank['status'] ?? null;
                        [$ibanLabel, $ibanClass] = match (true) {
                            ($bank['eligible'] ?? false) => ['Validé', 'bg-emerald-500/15 text-emerald-300'],
                            $ibanStatus === 'pending' => ['En attente de validation', 'bg-amber-500/15 text-amber-300'],
                            $ibanStatus === 'rejected' => ['Rejeté', 'bg-red-500/15 text-red-300'],
                            default => ['Non renseigné', 'bg-dark-200 text-gray-400'],
                        };
                    @endphp
                    <p class="text-gray-100 font-mono">{{ !empty($account->stripe_external_last4) ? '•••• ' . $account->stripe_external_last4 : '—' }}</p>
                    @if($account->stripe_account_holder_name)<p class="text-xs text-gray-400">{{ $account->stripe_account_holder_name }}</p>@endif
                    <span class="inline-block mt-2 px-2 py-0.5 rounded-full text-xs {{ $ibanClass }}">{{ $ibanLabel }}</span>
                    @if($ibanStatus === 'rejected' && $account->stripe_rejection_reason)
                        <p class="text-xs text-gray-400 mt-1">Motif : {{ $account->stripe_rejection_reason }}</p>
                    @endif
                </div>
                
            </div>

            {{-- Action : synchroniser / valider l'IBAN, ou virer --}}
            <div class="lg:col-span-2 bg-dark-100 border border-dark-200 rounded-xl p-5">
                @if(!($bank['configured'] ?? false))
                    <p class="text-sm text-amber-300">Virement bancaire indisponible : Stripe n'est pas configuré ou ne détient pas de solde en {{ $bank['currency'] ?? 'EUR' }}.</p>
                @elseif($ibanStatus === 'pending')
                    <div class="space-y-3">
                        <p class="text-sm text-amber-200">
                            IBAN •••• {{ $account->stripe_external_last4 }} enregistré. Validez-le pour activer le virement bancaire.
                        </p>
                        <form method="POST" action="{{ $routes['approve'] }}">
                            @csrf
                            <button class="px-4 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">
                                <i class="fas fa-check mr-1"></i>Valider l'IBAN
                            </button>
                        </form>
                    </div>
                @elseif(!($bank['eligible'] ?? false))
                    @include('admin.withdrawals.iban-form')
                @elseif(!$source)
                    <p class="text-sm text-gray-400">Aucun solde convertible vers {{ $bank['currency'] }}.</p>
                @else
                    <form method="POST" action="{{ $routes['bank'] }}" class="space-y-3">
                        @csrf
                        <p class="text-sm font-semibold text-gray-200"><i class="fas fa-paper-plane mr-1"></i>Virer sur l'IBAN</p>
                        <input type="hidden" name="currency" value="{{ $source['currency'] }}">
                        <div>
                            <label class="block text-sm text-gray-300 mb-1">Montant pris sur le Wallet ({{ $source['currency'] }})</label>
                            <input type="number" name="amount" min="1" max="{{ $source['available'] }}" step="{{ in_array($source['currency'], ['XAF', 'XOF']) ? 1 : '0.01' }}" value="{{ old('amount') }}" required class="{{ $inputClass }}">
                            <p class="text-xs text-gray-500 mt-1">Converti en {{ $bank['currency'] }} au moment du virement.</p>
                        </div>
                        <div>
                            <label class="block text-sm text-gray-300 mb-1">Note (facultatif)</label>
                            <input type="text" name="notes" maxlength="300" class="{{ $inputClass }}">
                        </div>
                        <button type="submit" onclick="this.disabled=true; this.form.submit();"
                                class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-semibold">
                            Virer sur l'IBAN
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
    </div>
    @endif

    {{-- Historique --}}
    <div class="bg-dark-100 border border-dark-200 rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-dark-200">
            <h2 class="text-lg font-semibold text-gray-100">{{ $historyTitle ?? 'Derniers retraits' }}</h2>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-gray-400 text-left">
                    <tr>
                        <th class="px-6 py-3">Date</th>
                        <th class="px-6 py-3">Moyen</th>
                        <th class="px-6 py-3">Bénéficiaire</th>
                        <th class="px-6 py-3 text-right">Montant</th>
                        <th class="px-6 py-3">Statut</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-dark-200 text-gray-200">
                    @forelse($withdrawals as $w)
                        <tr>
                            <td class="px-6 py-3 whitespace-nowrap">{{ $w->created_at->displayTz()->format('d/m/Y H:i') }}</td>
                            <td class="px-6 py-3">{{ $w->provider === 'stripe' ? 'Virement bancaire' : 'Mobile Money · ' . $w->payment_method }}</td>
                            <td class="px-6 py-3">{{ $w->provider === 'stripe' ? 'IBAN' : $w->payment_account }}</td>
                            <td class="px-6 py-3 text-right whitespace-nowrap">
                                {{ $money($w->amount_requested, $w->currency ?? 'XAF') }}
                                @if($w->payout_amount && $w->payout_currency && $w->payout_currency !== $w->currency)
                                    <span class="block text-xs text-gray-500">{{ $money($w->payout_amount, $w->payout_currency) }}</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 {{ $statusColor($w->status) }}">
                                {{ PlatformWithdrawalController::statusLabel($w->status) }}
                                @if($w->failure_reason)<span class="block text-xs text-gray-500">{{ $w->failure_reason }}</span>@endif
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if(in_array($w->status, ['pending', 'processing']))
                                    <form method="POST" action="{{ $routes['refresh']($w) }}">
                                        @csrf
                                        <button class="text-primary-400 hover:text-primary-300 text-xs"><i class="fas fa-sync mr-1"></i>Actualiser</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-6 py-6 text-center text-gray-500">Aucun retrait pour le moment.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
