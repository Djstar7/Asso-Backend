@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <!-- Header -->
    <div class="mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <a href="{{ route('admin.transactions.index') }}"
                   class="text-gray-400 hover:text-primary-600 transition-colors">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Détails de la Transaction</h1>
                    <p class="text-gray-400">{{ $transaction->reference }}</p>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Main Content -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Transaction Info -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-info-circle text-primary-500 mr-2"></i>
                    Informations de la Transaction
                </h3>

                <div class="grid grid-cols-2 gap-4">
                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-1">Référence</p>
                        <p class="text-white font-medium">{{ $transaction->reference }}</p>
                    </div>

                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-1">Transaction ID</p>
                        <p class="text-white font-medium font-mono text-sm">{{ $transaction->transaction_id }}</p>
                    </div>

                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-1">Référence Externe</p>
                        <p class="text-white font-medium font-mono text-sm">{{ $transaction->external_reference ?? 'N/A' }}</p>
                    </div>

                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-1">Date</p>
                        <p class="text-white font-medium">{{ $transaction->created_at->format('d/m/Y H:i:s') }}</p>
                    </div>
                </div>
            </div>

            <!-- Amounts -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-money-bill-wave text-primary-500 mr-2"></i>
                    Montants
                </h3>

                <div class="space-y-3">
                    <div class="flex items-center justify-between p-4 bg-blue-500/10 rounded-lg border border-blue-500/30">
                        <span class="text-gray-300">Montant Total</span>
                        <span class="text-2xl font-bold text-blue-400">{{ $transaction->formatted_amount }}</span>
                    </div>

                    <div class="flex items-center justify-between p-4 bg-orange-500/10 rounded-lg border border-orange-500/30">
                        <span class="text-gray-300">Frais de Transaction</span>
                        <span class="text-xl font-bold text-orange-400">{{ $transaction->formatted_fees }}</span>
                    </div>

                    <div class="flex items-center justify-between p-4 bg-green-500/10 rounded-lg border border-green-500/30">
                        <span class="text-gray-300">Montant Net</span>
                        <span class="text-2xl font-bold text-green-400">{{ $transaction->formatted_net_amount }}</span>
                    </div>
                </div>
            </div>

            <!-- Parties -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-users text-primary-500 mr-2"></i>
                    Parties Impliquées
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-2">
                            <i class="fas fa-shopping-cart text-blue-500 mr-1"></i>
                            Acheteur
                        </p>
                        @if($transaction->buyer)
                            <p class="text-white font-medium">{{ $transaction->buyer->first_name }} {{ $transaction->buyer->last_name }}</p>
                            <p class="text-sm text-gray-400">{{ $transaction->buyer->email }}</p>
                        @else
                            <p class="text-gray-500 italic">Non disponible</p>
                        @endif
                    </div>

                    <div class="bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-2">
                            <i class="fas fa-store text-green-500 mr-1"></i>
                            Vendeur
                        </p>
                        @if($transaction->seller)
                            <p class="text-white font-medium">{{ $transaction->seller->first_name }} {{ $transaction->seller->last_name }}</p>
                            <p class="text-sm text-gray-400">{{ $transaction->seller->email }}</p>
                        @else
                            <p class="text-gray-500 italic">Non disponible</p>
                        @endif
                    </div>
                </div>

                @if($transaction->product)
                    <div class="mt-4 bg-dark-50 p-4 rounded-lg border border-dark-300">
                        <p class="text-sm text-gray-400 mb-2">
                            <i class="fas fa-box text-purple-500 mr-1"></i>
                            Produit
                        </p>
                        <p class="text-white font-medium">{{ $transaction->product->name }}</p>
                        <p class="text-sm text-gray-400">{{ number_format($transaction->product->price, 0, ',', ' ') }} XOF</p>
                    </div>
                @endif
            </div>

            @if($transaction->description)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-file-alt text-primary-500 mr-2"></i>
                        Description
                    </h3>
                    <p class="text-gray-300">{{ $transaction->description }}</p>
                </div>
            @endif
        </div>

        <!-- Sidebar -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Status -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-flag text-primary-500 mr-2"></i>
                    Statut
                </h3>

                <div class="space-y-3">
                    <div class="flex items-center justify-center p-4 bg-dark-50 rounded-lg">
                        @if($transaction->status == 'completed')
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-green-500/20 text-green-300 border border-green-500/50">
                                <i class="fas fa-check-circle mr-2"></i>
                                Complété
                            </span>
                        @elseif($transaction->status == 'pending')
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-yellow-500/20 text-yellow-300 border border-yellow-500/50">
                                <i class="fas fa-clock mr-2"></i>
                                En attente
                            </span>
                        @else
                            <span class="px-4 py-2 text-lg font-semibold rounded-full bg-red-500/20 text-red-300 border border-red-500/50">
                                <i class="fas fa-times-circle mr-2"></i>
                                Annulé
                            </span>
                        @endif
                    </div>

                    @if($transaction->completed_at)
                        <div class="p-3 bg-green-500/10 border border-green-500/30 rounded-lg">
                            <p class="text-sm text-green-300">
                                <i class="fas fa-calendar-check mr-2"></i>
                                Complété le {{ $transaction->completed_at->format('d/m/Y à H:i') }}
                            </p>
                        </div>
                    @endif
                </div>
            </div>

            <!-- Payment Method -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-credit-card text-primary-500 mr-2"></i>
                    Méthode de Paiement
                </h3>

                <div class="flex items-center justify-center p-4 bg-{{ $transaction->payment_method_color }}-500/10 border border-{{ $transaction->payment_method_color }}-500/30 rounded-lg">
                    <i class="fab {{ $transaction->payment_method_icon }} text-{{ $transaction->payment_method_color }}-500 text-3xl mr-3"></i>
                    <div>
                        <p class="text-{{ $transaction->payment_method_color }}-300 font-semibold text-lg">{{ $transaction->payment_method_label }}</p>
                        <p class="text-xs text-gray-400">Méthode utilisée</p>
                    </div>
                </div>
            </div>

            <!-- Metadata -->
            @if($transaction->metadata)
                <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                        <i class="fas fa-database text-primary-500 mr-2"></i>
                        Métadonnées
                    </h3>

                    <div class="space-y-2 text-sm">
                        @foreach($transaction->metadata as $key => $value)
                            <div class="flex items-start justify-between gap-3 p-2 bg-dark-50 rounded">
                                <span class="text-gray-400 whitespace-nowrap">{{ ucfirst(str_replace('_', ' ', $key)) }}</span>
                                <span class="text-white font-mono text-xs break-all text-right">{{ is_scalar($value) || is_null($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <!-- Type -->
            <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
                <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                    <i class="fas fa-tag text-primary-500 mr-2"></i>
                    Type
                </h3>

                <div class="text-center">
                    <span class="px-4 py-2 bg-primary-500/20 text-primary-300 rounded-lg font-medium">
                        {{ $transaction->type_label }}
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Back Button -->
    <div class="mt-6">
        <a href="{{ route('admin.transactions.index') }}"
           class="inline-flex items-center px-6 py-3 bg-dark-300 text-white rounded-lg hover:bg-dark-400 transition-all shadow-md">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>
</div>
@endsection
