@extends('admin.layouts.app')

@section('title', 'Retrait ASSO')
@section('header', 'Retrait ASSO')

@section('content')
@php
    use App\Http\Controllers\Admin\PlatformWithdrawalController;
    $money = fn ($v, $c) => number_format((float) $v, in_array($c, ['XAF', 'XOF']) ? 0 : 2, ',', ' ') . ' ' . $c;
    $mobile = $balances['methods']['kpay'] ?? null;
    $bank = $balances['methods']['stripe'] ?? null;
    $source = $bank['source'] ?? null;
    $gatewayName = ['kpay' => 'KPay', 'elgiopay' => 'ElgioPay'][$mobile['gateway'] ?? ''] ?? null;
    $statusColor = fn ($s) => match ($s) {
        'completed' => 'text-emerald-400', 'failed', 'cancelled' => 'text-red-400', default => 'text-amber-400',
    };
@endphp
<div class="p-6 space-y-6">
    <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Retrait ASSO</h1>
            <p class="text-gray-400 mt-1">
                Fonds du Wallet du compte plateforme
                <span class="text-gray-300">{{ $platform->email }}</span>, qui reçoit les commissions ASSO.
                Même retrait que dans l'app : un solde Mobile Money et un solde bancaire, chacun retiré par son propre moyen.
            </p>
        </div>
        <a href="{{ route('admin.revenue.index') }}" class="px-3 py-2 text-sm rounded-lg bg-dark-200 hover:bg-dark-300 text-gray-200">
            <i class="fas fa-arrow-left mr-1"></i>Revenus ASSO
        </a>
    </div>

    @if($balancesError)
        <div class="p-4 rounded-lg bg-red-500/10 border border-red-500/30 text-red-300">{{ $balancesError }}</div>
    @else
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {{-- Solde Mobile Money --}}
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-6 space-y-4">
            <div class="flex items-start justify-between">
                <div>
                    <p class="text-sm text-gray-400"><i class="fas fa-mobile-alt mr-1"></i>Solde mobile</p>
                    <p class="text-3xl font-bold text-gray-100 mt-1">{{ $money($mobile['available'] ?? 0, $mobile['currency'] ?? 'XAF') }}</p>
                    <p class="text-xs text-gray-500 mt-1">
                        Retrait vers un numéro Mobile Money{{ $gatewayName ? ' via ' . $gatewayName : '' }}.
                    </p>
                </div>
            </div>

            @if(!($mobile['configured'] ?? false))
                <p class="text-sm text-amber-300">Aucun prestataire Mobile Money actif : activez KPay ou ElgioPay dans Paramètres › Paiements.</p>
            @else
                <form method="POST" action="{{ route('admin.revenue.withdrawal.mobile') }}" class="space-y-3">
                    @csrf
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Opérateur</label>
                        <select name="provider" required class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                            @foreach($providers as $code => $label)
                                <option value="{{ $code }}" @selected(old('provider') === $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Numéro bénéficiaire</label>
                        <input type="tel" name="phone" value="{{ old('phone') }}" required placeholder="6XXXXXXXX"
                               class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Montant ({{ $mobile['currency'] ?? 'XAF' }})</label>
                        <input type="number" name="amount" min="1" max="{{ (int) ($mobile['available'] ?? 0) }}" step="1" value="{{ old('amount') }}" required
                               class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Note (facultatif)</label>
                        <input type="text" name="notes" maxlength="300" value="{{ old('notes') }}"
                               class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                    </div>
                    <button type="submit" @disabled(($mobile['available'] ?? 0) <= 0)
                            onclick="this.disabled=true; this.form.submit();"
                            class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white font-semibold">
                        Retirer vers Mobile Money
                    </button>
                </form>
            @endif
        </div>

        {{-- Solde bancaire --}}
        <div class="bg-dark-100 border border-dark-200 rounded-xl p-6 space-y-4">
            <div>
                <p class="text-sm text-gray-400"><i class="fas fa-university mr-1"></i>Solde bancaire</p>
                <p class="text-3xl font-bold text-gray-100 mt-1">{{ $money($bank['available'] ?? 0, $bank['currency'] ?? 'EUR') }}</p>
                <p class="text-xs text-gray-500 mt-1">
                    Virement vers l'IBAN{{ !empty($bank['iban_last4']) ? ' •••• ' . $bank['iban_last4'] : '' }} du compte plateforme.
                    @if($source)
                        Pris sur {{ $money($source['available'], $source['currency']) }} au taux de {{ rtrim(rtrim(number_format($source['rate'], 6, ',', ' '), '0'), ',') }}.
                    @endif
                </p>
            </div>

            @if(!($bank['configured'] ?? false))
                <p class="text-sm text-amber-300">Virement bancaire indisponible : Stripe n'est pas configuré ou ne détient pas de solde en {{ $bank['currency'] ?? 'EUR' }}.</p>
            @elseif(!($bank['eligible'] ?? false))
                <p class="text-sm text-amber-300">
                    Aucun IBAN validé pour le compte plateforme (statut : {{ $bank['status'] ?? 'non renseigné' }}).
                    Renseignez-le depuis l'app, connecté avec {{ $platform->email }}.
                </p>
            @elseif(!$source)
                <p class="text-sm text-gray-400">Aucun solde convertible vers {{ $bank['currency'] }}.</p>
            @else
                <form method="POST" action="{{ route('admin.revenue.withdrawal.bank') }}" class="space-y-3">
                    @csrf
                    <input type="hidden" name="currency" value="{{ $source['currency'] }}">
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Montant pris sur le Wallet ({{ $source['currency'] }})</label>
                        <input type="number" name="amount" min="1" max="{{ $source['available'] }}" step="{{ in_array($source['currency'], ['XAF', 'XOF']) ? 1 : '0.01' }}" value="{{ old('amount') }}" required
                               class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                        <p class="text-xs text-gray-500 mt-1">Converti en {{ $bank['currency'] }} au moment du virement.</p>
                    </div>
                    <div>
                        <label class="block text-sm text-gray-300 mb-1">Note (facultatif)</label>
                        <input type="text" name="notes" maxlength="300"
                               class="w-full bg-dark-200 border border-dark-300 rounded-lg px-3 py-2 text-gray-100">
                    </div>
                    <button type="submit" onclick="this.disabled=true; this.form.submit();"
                            class="w-full px-4 py-2 rounded-lg bg-primary-600 hover:bg-primary-700 text-white font-semibold">
                        Virer sur l'IBAN
                    </button>
                </form>
            @endif
        </div>
    </div>
    @endif

    {{-- Historique --}}
    <div class="bg-dark-100 border border-dark-200 rounded-xl overflow-hidden">
        <div class="px-6 py-4 border-b border-dark-200">
            <h2 class="text-lg font-semibold text-gray-100">Derniers retraits ASSO</h2>
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
                            <td class="px-6 py-3 whitespace-nowrap">{{ $w->created_at->format('d/m/Y H:i') }}</td>
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
                                    <form method="POST" action="{{ route('admin.revenue.withdrawal.refresh', $w) }}">
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
</div>
@endsection
