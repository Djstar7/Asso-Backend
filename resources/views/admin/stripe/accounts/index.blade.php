@extends('admin.layouts.app')

@section('title', 'Comptes de virement (Stripe)')
@section('header', 'Comptes de virement (Stripe)')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="flex justify-between items-center mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Comptes de virement (Stripe)</h1>
            <p class="text-gray-400 mt-1">Validez les IBAN soumis par les vendeurs avant tout virement</p>
        </div>
    </div>

    <!-- Status Tabs -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <div class="flex gap-2 flex-wrap">
            <a href="{{ route('admin.stripe.accounts.index', ['status' => 'pending']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'pending' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-clock mr-2"></i>
                En attente
                <span class="ml-2 px-2 py-0.5 bg-yellow-500 text-dark-100 text-xs font-bold rounded-full">
                    {{ $counts['pending'] }}
                </span>
            </a>
            <a href="{{ route('admin.stripe.accounts.index', ['status' => 'approved']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'approved' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-check-circle mr-2"></i>
                Validés
                <span class="ml-2 px-2 py-0.5 bg-green-500 text-white text-xs font-bold rounded-full">
                    {{ $counts['approved'] }}
                </span>
            </a>
            <a href="{{ route('admin.stripe.accounts.index', ['status' => 'rejected']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'rejected' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-times-circle mr-2"></i>
                Rejetés
                <span class="ml-2 px-2 py-0.5 bg-red-500 text-white text-xs font-bold rounded-full">
                    {{ $counts['rejected'] }}
                </span>
            </a>
            <a href="{{ route('admin.stripe.accounts.index', ['status' => 'all']) }}"
               class="px-6 py-3 rounded-lg transition-all {{ $status === 'all' ? 'bg-gradient-to-r from-primary-500 to-primary-600 text-white shadow-md' : 'bg-dark-50 text-gray-300 hover:bg-dark-200' }}">
                <i class="fas fa-list mr-2"></i>
                Tous
            </a>
        </div>
    </div>

    <!-- Accounts List -->
    @if($accounts->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-university text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucun compte</h3>
            <p class="text-gray-400">
                @if($status === 'pending')
                    Il n'y a pas de compte de virement en attente pour le moment.
                @elseif($status === 'approved')
                    Aucun compte validé.
                @elseif($status === 'rejected')
                    Aucun compte rejeté.
                @else
                    Aucun compte de virement trouvé.
                @endif
            </p>
        </div>
    @else
        <div class="grid gap-4">
            @foreach($accounts as $account)
                <div class="bg-dark-100 rounded-xl shadow-lg hover:shadow-xl transition-all">
                    <div class="p-6">
                        <div class="flex items-start justify-between flex-wrap gap-4">
                            <!-- Infos vendeur + banque -->
                            <div class="flex items-start space-x-4 flex-1 min-w-[260px]">
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-lg shrink-0">
                                    {{ strtoupper(substr($account->first_name ?? '?', 0, 1)) }}{{ strtoupper(substr($account->last_name ?? '', 0, 1)) }}
                                </div>
                                <div class="flex-1">
                                    <h3 class="text-lg font-semibold text-white">
                                        {{ trim($account->first_name . ' ' . $account->last_name) ?: 'Vendeur #' . $account->id }}
                                    </h3>
                                    <p class="text-sm text-gray-400">{{ $account->email }}</p>
                                    @if($account->phone)
                                        <p class="text-sm text-gray-500">{{ $account->phone }}</p>
                                    @endif

                                    <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1 text-sm">
                                        <p class="text-gray-300">
                                            <span class="text-gray-500">Titulaire :</span>
                                            {{ $account->stripe_account_holder_name ?: '—' }}
                                        </p>
                                        <p class="text-gray-300">
                                            <span class="text-gray-500">IBAN :</span>
                                            •••• {{ $account->stripe_external_last4 ?: '????' }}
                                            @if($account->stripe_bank_country)
                                                <span class="text-gray-500">({{ $account->stripe_bank_country }})</span>
                                            @endif
                                        </p>
                                        <p class="text-gray-300">
                                            <span class="text-gray-500">Compte Stripe :</span>
                                            <span class="font-mono text-xs">{{ $account->stripe_account_id }}</span>
                                        </p>
                                        <p class="text-gray-300">
                                            <span class="text-gray-500">Soumis le :</span>
                                            {{ optional($account->stripe_submitted_at)->format('d/m/Y H:i') ?? '—' }}
                                        </p>
                                    </div>

                                    @if($account->stripe_account_status === 'rejected' && $account->stripe_rejection_reason)
                                        <div class="mt-3 p-3 bg-red-500/10 border border-red-500/30 rounded-lg text-sm text-red-300">
                                            <i class="fas fa-exclamation-circle mr-1"></i>
                                            <span class="text-gray-400">Motif du rejet :</span> {{ $account->stripe_rejection_reason }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Statut + actions -->
                            <div class="flex flex-col items-end gap-3">
                                @php
                                    $badge = match($account->stripe_account_status) {
                                        'approved' => ['bg-green-500/15 text-green-400 border-green-500/30', 'fa-check-circle', 'Validé'],
                                        'rejected' => ['bg-red-500/15 text-red-400 border-red-500/30', 'fa-times-circle', 'Rejeté'],
                                        default => ['bg-yellow-500/15 text-yellow-400 border-yellow-500/30', 'fa-clock', 'En attente'],
                                    };
                                @endphp
                                <span class="px-3 py-1 rounded-full text-xs font-semibold border {{ $badge[0] }}">
                                    <i class="fas {{ $badge[1] }} mr-1"></i>{{ $badge[2] }}
                                </span>

                                @if($account->stripe_account_status === 'pending')
                                    <div class="flex gap-2">
                                        <form method="POST" action="{{ route('admin.stripe.accounts.approve', $account->id) }}"
                                              onsubmit="return confirm('Valider le compte de virement de ce vendeur ?');">
                                            @csrf
                                            <button type="submit"
                                                    class="px-4 py-2 rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium transition-all">
                                                <i class="fas fa-check mr-1"></i> Approuver
                                            </button>
                                        </form>
                                        <button type="button"
                                                onclick="openStripeRejectModal({{ $account->id }}, '{{ addslashes(trim($account->first_name . ' ' . $account->last_name)) }}')"
                                                class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium transition-all">
                                            <i class="fas fa-times mr-1"></i> Rejeter
                                        </button>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6">
            {{ $accounts->links() }}
        </div>
    @endif
</div>

<!-- Reject Modal -->
<div id="stripeRejectModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/60 p-4">
    <div class="bg-dark-100 rounded-xl shadow-2xl w-full max-w-lg">
        <form id="stripeRejectForm" method="POST" action="">
            @csrf
            <div class="p-6 border-b border-dark-50">
                <h3 class="text-lg font-semibold text-white">
                    <i class="fas fa-times-circle text-red-400 mr-2"></i>
                    Rejeter le compte de virement
                </h3>
                <p class="text-sm text-gray-400 mt-1" id="stripeRejectSubtitle">Vendeur</p>
            </div>
            <div class="p-6">
                <label class="block text-sm font-medium text-gray-300 mb-2">Motif du rejet <span class="text-red-400">*</span></label>
                <textarea name="reason" rows="4" required maxlength="500"
                          class="w-full px-4 py-3 rounded-lg bg-dark-50 border border-dark-200 text-white focus:outline-none focus:border-primary-500"
                          placeholder="Ex. : l'IBAN ne correspond pas au nom du titulaire."></textarea>
                <p class="text-xs text-gray-500 mt-1">Le vendeur sera notifié avec ce motif.</p>
            </div>
            <div class="p-6 border-t border-dark-50 flex justify-end gap-3">
                <button type="button" onclick="closeStripeRejectModal()"
                        class="px-4 py-2 rounded-lg bg-dark-50 text-gray-300 hover:bg-dark-200 transition-all">
                    Annuler
                </button>
                <button type="submit"
                        class="px-4 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium transition-all">
                    Confirmer le rejet
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    function openStripeRejectModal(userId, userName) {
        var form = document.getElementById('stripeRejectForm');
        form.action = "{{ url('admin/stripe/accounts') }}/" + userId + "/reject";
        document.getElementById('stripeRejectSubtitle').textContent = userName || ('Vendeur #' + userId);
        var modal = document.getElementById('stripeRejectModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
    function closeStripeRejectModal() {
        var modal = document.getElementById('stripeRejectModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
</script>
@endpush
@endsection
