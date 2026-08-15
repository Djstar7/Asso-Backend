@extends('admin.layouts.app')

@section('title', 'Détail de l\'offre')
@section('header', 'Détail de l\'offre DIASPO')

@section('content')
<div class="p-6">
    <!-- Back Button -->
    <div class="mb-6">
        <a href="{{ route('admin.diaspo.offers.index') }}" class="inline-flex items-center text-primary-500 hover:text-primary-600 transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour à la liste
        </a>
    </div>

    <!-- Offer Details -->
    <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
        <div class="flex items-start justify-between mb-6">
            <div class="flex items-start space-x-4 flex-1">
                <!-- User Avatar -->
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold text-xl flex-shrink-0">
                    {{ strtoupper(substr($offer->user->first_name ?? 'U', 0, 1)) }}{{ strtoupper(substr($offer->user->last_name ?? '', 0, 1)) }}
                </div>

                <!-- User Info -->
                <div class="flex-1">
                    <h2 class="text-2xl font-bold text-white mb-2">
                        {{ $offer->user->first_name }} {{ $offer->user->last_name }}
                    </h2>
                    <div class="flex items-center gap-4 text-sm text-gray-400 mb-4">
                        <span><i class="fas fa-phone mr-1"></i>{{ $offer->user->phone }}</span>
                        @if($offer->user->email)
                            <span><i class="fas fa-envelope mr-1"></i>{{ $offer->user->email }}</span>
                        @endif
                    </div>
                    <div class="text-sm text-gray-500">
                        <i class="fas fa-calendar mr-1"></i>
                        Offre créée le {{ $offer->created_at->format('d/m/Y à H:i') }}
                    </div>
                </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
                @if($offer->status === 'pending' || $offer->verification_status === 'pending')
                    <form action="{{ route('admin.diaspo.offers.approve', $offer->id) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition-all">
                            <i class="fas fa-check mr-2"></i>Approuver
                        </button>
                    </form>

                    <button onclick="document.getElementById('rejectModal').classList.remove('hidden')"
                            class="px-4 py-2 bg-yellow-500 text-white rounded-lg hover:bg-yellow-600 transition-all">
                        <i class="fas fa-times mr-2"></i>Rejeter
                    </button>
                @endif

                <form action="{{ route('admin.diaspo.offers.destroy', $offer->id) }}"
                      method="POST"
                      class="inline"
                      onsubmit="return confirm('Êtes-vous sûr de vouloir supprimer cette offre ?')">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                        <i class="fas fa-trash mr-2"></i>Supprimer
                    </button>
                </form>
            </div>
        </div>

        <!-- Status Badges -->
        <div class="flex items-center gap-3 mb-6">
            @if($offer->status === 'pending' || $offer->verification_status === 'pending')
                <span class="px-4 py-2 bg-yellow-500/20 text-yellow-400 text-sm font-semibold rounded-full">
                    <i class="fas fa-clock mr-1"></i>En attente de validation
                </span>
            @elseif($offer->status === 'approved' && $offer->verification_status === 'verified')
                <span class="px-4 py-2 bg-green-500/20 text-green-400 text-sm font-semibold rounded-full">
                    <i class="fas fa-check-circle mr-1"></i>Approuvée et vérifiée
                </span>
            @elseif($offer->status === 'rejected' || $offer->verification_status === 'rejected')
                <span class="px-4 py-2 bg-red-500/20 text-red-400 text-sm font-semibold rounded-full">
                    <i class="fas fa-times-circle mr-1"></i>Rejetée
                </span>
            @elseif($offer->status === 'expired')
                <span class="px-4 py-2 bg-gray-500/20 text-gray-400 text-sm font-semibold rounded-full">
                    <i class="fas fa-calendar-times mr-1"></i>Expirée
                </span>
            @endif

            @if($offer->is_available)
                <span class="px-4 py-2 bg-blue-500/20 text-blue-400 text-sm font-semibold rounded-full">
                    <i class="fas fa-box-open mr-1"></i>Disponible maintenant
                </span>
            @endif
        </div>

        @if(($offer->status === 'rejected' || $offer->verification_status === 'rejected') && $offer->rejection_reason)
            <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
                <p class="text-sm text-red-400">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    <strong>Raison du rejet:</strong> {{ $offer->rejection_reason }}
                </p>
            </div>
        @endif

        <!-- Route Info -->
        <div class="bg-dark-50 rounded-lg p-6 mb-6">
            <h3 class="text-lg font-semibold text-white mb-4"><i class="fas fa-route mr-2"></i>Itinéraire</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Departure -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fas fa-plane-departure text-green-500 text-xl"></i>
                        <span class="text-gray-400 text-sm font-semibold">DÉPART</span>
                    </div>
                    <p class="text-white text-lg font-bold mb-1">{{ $offer->departure_city }}</p>
                    <p class="text-gray-400 mb-2">{{ $offer->departure_country }}</p>
                    <p class="text-sm text-gray-500">
                        <i class="fas fa-calendar mr-1"></i>{{ $offer->departure_datetime->format('d/m/Y à H:i') }}
                    </p>
                </div>

                <!-- Arrival -->
                <div>
                    <div class="flex items-center gap-2 mb-3">
                        <i class="fas fa-plane-arrival text-red-500 text-xl"></i>
                        <span class="text-gray-400 text-sm font-semibold">ARRIVÉE</span>
                    </div>
                    <p class="text-white text-lg font-bold mb-1">{{ $offer->arrival_city }}</p>
                    <p class="text-gray-400 mb-2">{{ $offer->arrival_country }}</p>
                    <p class="text-sm text-gray-500">
                        <i class="fas fa-calendar mr-1"></i>{{ $offer->arrival_datetime->format('d/m/Y à H:i') }}
                    </p>
                </div>
            </div>

            @if($offer->trip_duration_hours)
                <div class="mt-4 pt-4 border-t border-dark-200">
                    <p class="text-sm text-gray-400">
                        <i class="fas fa-clock mr-2"></i>Durée du trajet: <span class="text-white font-semibold">{{ round($offer->trip_duration_hours) }} heures</span>
                    </p>
                </div>
            @endif
        </div>

        <!-- Pricing & Capacity -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Prix par kg</p>
                        <p class="text-2xl font-bold text-primary-500 mt-1">{{ $offer->formatted_price }}</p>
                    </div>
                    <i class="fas fa-euro-sign text-3xl text-primary-500/20"></i>
                </div>
            </div>

            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Kg disponibles</p>
                        <p class="text-2xl font-bold text-blue-500 mt-1">{{ $offer->available_kg }} kg</p>
                    </div>
                    <i class="fas fa-weight text-3xl text-blue-500/20"></i>
                </div>
            </div>

            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Kg restants</p>
                        <p class="text-2xl font-bold text-green-500 mt-1">{{ $offer->remaining_kg }} kg</p>
                    </div>
                    <i class="fas fa-boxes text-3xl text-green-500/20"></i>
                </div>
            </div>

            <div class="bg-dark-50 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-400 text-sm">Réservations</p>
                        <p class="text-2xl font-bold text-purple-500 mt-1">{{ $offer->bookings->count() }}</p>
                    </div>
                    <i class="fas fa-shopping-cart text-3xl text-purple-500/20"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Bookings Section -->
    @if($offer->bookings->isNotEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-6">
            <h3 class="text-xl font-bold text-white mb-4">
                <i class="fas fa-shopping-cart mr-2"></i>
                Réservations ({{ $offer->bookings->count() }})
            </h3>

            <div class="space-y-4">
                @foreach($offer->bookings as $booking)
                    <div class="bg-dark-50 rounded-lg p-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-start space-x-3 flex-1">
                                <!-- Buyer Avatar -->
                                <div class="w-12 h-12 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-bold flex-shrink-0">
                                    {{ strtoupper(substr($booking->buyer->first_name ?? 'U', 0, 1)) }}
                                </div>

                                <!-- Booking Details -->
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h4 class="font-semibold text-white">
                                            {{ $booking->buyer->first_name }} {{ $booking->buyer->last_name }}
                                        </h4>
                                        @if($booking->status === 'pending')
                                            <span class="px-2 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-full">En attente</span>
                                        @elseif($booking->status === 'paid')
                                            <span class="px-2 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full">Payée</span>
                                        @elseif($booking->status === 'completed')
                                            <span class="px-2 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">Complétée</span>
                                        @elseif($booking->status === 'cancelled')
                                            <span class="px-2 py-1 bg-red-500/20 text-red-400 text-xs rounded-full">Annulée</span>
                                        @endif
                                    </div>
                                    <div class="text-sm text-gray-400 mb-2">
                                        <i class="fas fa-phone mr-1"></i>{{ $booking->buyer->phone }}
                                    </div>
                                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                                        <div>
                                            <span class="text-gray-400">Kg réservés:</span>
                                            <p class="text-white font-semibold">{{ $booking->kg_booked }} kg</p>
                                        </div>
                                        <div>
                                            <span class="text-gray-400">Total:</span>
                                            <p class="text-white font-semibold">{{ $booking->formatted_total }}</p>
                                        </div>
                                        <div>
                                            <span class="text-gray-400">Code:</span>
                                            <p class="text-white font-semibold font-mono">{{ $booking->confirmation_code }}</p>
                                        </div>
                                        <div>
                                            <span class="text-gray-400">Créée:</span>
                                            <p class="text-white font-semibold">{{ $booking->created_at->format('d/m/Y') }}</p>
                                        </div>
                                    </div>
                                    @if($booking->notes)
                                        <div class="mt-2 p-2 bg-dark-100 rounded text-sm text-gray-300">
                                            <i class="fas fa-comment mr-1"></i>{{ $booking->notes }}
                                        </div>
                                    @endif
                                    @if($booking->cancel_reason)
                                        <div class="mt-2 p-2 bg-red-900/20 border-l-2 border-red-500 rounded text-sm text-red-400">
                                            <i class="fas fa-exclamation-triangle mr-1"></i>{{ $booking->cancel_reason }}
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <!-- Cancel Button -->
                            @if($booking->status !== 'cancelled')
                                <button onclick="showCancelModal({{ $booking->id }})"
                                        class="text-red-500 hover:text-red-600 transition-colors ml-4">
                                    <i class="fas fa-times-circle text-xl"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @else
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-shopping-cart text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucune réservation</h3>
            <p class="text-gray-400">Cette offre n'a pas encore de réservations.</p>
        </div>
    @endif
</div>

<!-- Reject Modal -->
<div id="rejectModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-dark-100 rounded-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-white mb-4">Rejeter l'offre</h3>
        <form action="{{ route('admin.diaspo.offers.reject', $offer->id) }}" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-400 text-sm mb-2">Raison du rejet *</label>
                <textarea name="reason"
                          required
                          rows="4"
                          class="w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500"
                          placeholder="Expliquez la raison du rejet..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button"
                        onclick="document.getElementById('rejectModal').classList.add('hidden')"
                        class="flex-1 px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300 transition-all">
                    Annuler
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                    Rejeter
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Cancel Booking Modal -->
<div id="cancelBookingModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center">
    <div class="bg-dark-100 rounded-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-xl font-bold text-white mb-4">Annuler la réservation</h3>
        <form id="cancelBookingForm" method="POST">
            @csrf
            <div class="mb-4">
                <label class="block text-gray-400 text-sm mb-2">Raison de l'annulation *</label>
                <textarea name="reason"
                          required
                          rows="4"
                          class="w-full px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500"
                          placeholder="Expliquez la raison de l'annulation..."></textarea>
            </div>
            <div class="flex gap-3">
                <button type="button"
                        onclick="document.getElementById('cancelBookingModal').classList.add('hidden')"
                        class="flex-1 px-4 py-2 bg-dark-200 text-white rounded-lg hover:bg-dark-300 transition-all">
                    Annuler
                </button>
                <button type="submit"
                        class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg hover:bg-red-600 transition-all">
                    Confirmer l'annulation
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showCancelModal(bookingId) {
    const form = document.getElementById('cancelBookingForm');
    form.action = `{{ route('admin.diaspo.offers.show', $offer->id) }}`.replace('/{{ $offer->id }}', `/{{ $offer->id }}/bookings/${bookingId}/cancel`);
    document.getElementById('cancelBookingModal').classList.remove('hidden');
}
</script>
@endsection
