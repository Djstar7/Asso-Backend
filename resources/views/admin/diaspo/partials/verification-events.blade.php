{{-- Historique de vérification Diaspo. Attend $events (DiaspoVerificationEvent) et $showOffer (bool). --}}
<div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6">
    <h2 class="text-xl font-bold text-white mb-4 flex items-center">
        <i class="fas fa-history text-primary-500 mr-3"></i>
        Historique de vérification
    </h2>

    @if($events->isEmpty())
        <p class="text-sm text-gray-500 italic">Aucun événement enregistré.</p>
    @else
        <ol class="relative border-l border-dark-300 ml-2 space-y-4">
            @foreach($events as $event)
                @php
                    $tone = match (true) {
                        in_array($event->event, ['identity_verified', 'offer_verified', 'offer_approved_by_admin']) => 'bg-green-500',
                        in_array($event->event, ['identity_rejected', 'offer_rejected_by_admin', 'offer_removed_deadline', 'offer_deleted_by_admin']) => 'bg-red-500',
                        default => 'bg-yellow-500',
                    };
                @endphp
                <li class="ml-4">
                    <span class="absolute -left-1.5 mt-1.5 w-3 h-3 rounded-full {{ $tone }}"></span>
                    <div class="flex flex-wrap items-baseline gap-x-3">
                        <span class="text-white font-medium">{{ $event->label }}</span>
                        <time class="text-xs text-gray-500">{{ $event->created_at->displayTz()->format('d/m/Y à H:i') }}</time>
                    </div>
                    <div class="text-sm text-gray-400 mt-0.5">
                        {{ $event->actor ? 'Par ' . $event->actor->first_name . ' ' . $event->actor->last_name : 'Automatique' }}
                        @if(($showOffer ?? false) && $event->offer)
                            · Offre
                            <a href="{{ route('admin.diaspo.offers.show', $event->diaspo_offer_id) }}" class="text-primary-500 hover:underline">
                                #{{ $event->diaspo_offer_id }} {{ $event->offer->departure_city }} → {{ $event->offer->arrival_city }}
                            </a>
                        @endif
                        @if(!empty($event->meta['deadline']))
                            · échéance {{ \Illuminate\Support\Carbon::parse($event->meta['deadline'])->displayTz()->format('d/m/Y à H:i') }}
                        @endif
                    </div>
                    @if($event->reason)
                        <p class="text-sm text-gray-300 mt-1">« {{ $event->reason }} »</p>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif
</div>
