@extends('admin.layouts.app')

@section('content')
<div class="p-6">
    <div class="mb-4">
        <h1 class="text-2xl font-bold text-white">Fournisseurs</h1>
        <p class="text-gray-400">Mise à jour automatique des fiches CJ : alertes envoyées par CJ (stocks, déclinaisons, retrait de la vente) et relecture de toutes les fiches chaque nuit (prix, taux du dollar).</p>
    </div>
    @include('admin.suppliers._tabs')

    <div class="grid gap-6 lg:grid-cols-3 mb-6">
        {{-- Alertes CJ --}}
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 lg:col-span-2">
            <div class="flex items-center justify-between mb-3">
                <h2 class="text-lg font-semibold text-white"><i class="fas fa-bell mr-2"></i>Alertes CJ</h2>
                <span class="text-xs px-2 py-1 rounded {{ $enabled ? 'bg-green-900/40 text-green-300' : 'bg-dark-200 text-gray-300' }}">{{ $enabled ? 'Activées' : 'Désactivées' }}</span>
            </div>

            <p class="text-xs text-gray-500 mb-1">Adresse transmise à CJ</p>
            <p class="text-sm text-gray-200 break-all mb-1">{{ \Illuminate\Support\Str::beforeLast($callbackUrl, '/') }}/<span class="text-gray-500">{{ \Illuminate\Support\Str::mask($cj->webhook_token, '•', 4) }}</span></p>
            @if($urlProblem)
                <p class="text-xs text-yellow-300 mb-2"><i class="fas fa-triangle-exclamation mr-1"></i>{{ $urlProblem }} CJ n’accepte qu’une adresse publique en https : réglez APP_URL, ou CJ_WEBHOOK_BASE_URL avec l’adresse d’un tunnel pour tester en local.</p>
            @endif
            @if($urlChanged)
                <p class="text-xs text-yellow-300 mb-2"><i class="fas fa-triangle-exclamation mr-1"></i>L’adresse du serveur a changé depuis l’activation : cliquez de nouveau sur « Activer les alertes CJ ».</p>
            @endif
            @if(blank($cj->account_id))
                <p class="text-xs text-yellow-300 mb-2"><i class="fas fa-triangle-exclamation mr-1"></i>Identifiant de signature CJ inconnu : il sera récupéré à l’activation. Sans lui, chaque alerte est revérifiée auprès de CJ.</p>
            @endif

            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 my-4 text-sm">
                <div>
                    <dt class="text-xs text-gray-500">Dernière alerte</dt>
                    <dd class="text-gray-200">{{ $lastEvent ? $lastEvent->created_at->diffForHumans() : 'aucune' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Traitées (24 h)</dt>
                    <dd class="text-gray-200">{{ ($counts24h['processed'] ?? 0) + ($counts24h['ignored'] ?? 0) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">En erreur (24 h)</dt>
                    <dd class="{{ ($counts24h['failed'] ?? 0) > 0 ? 'text-red-300' : 'text-gray-200' }}">{{ $counts24h['failed'] ?? 0 }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500">Signatures rejetées (24 h)</dt>
                    <dd class="{{ $rejected24h > 0 ? 'text-red-300' : 'text-gray-200' }}">{{ $rejected24h }}</dd>
                </div>
            </dl>
            @if($enabled && (! $lastEvent || $lastEvent->created_at->lt(now()->subDays(2))) && $subscribed > 0)
                <p class="text-xs text-yellow-300 mb-3"><i class="fas fa-triangle-exclamation mr-1"></i>Aucune alerte depuis plus de 48 h. CJ coupe les alertes quand trop de réponses échouent : réactivez-les si besoin. La relecture de nuit continue de toute façon.</p>
            @endif

            <div class="flex flex-wrap gap-2">
                <form action="{{ route('admin.suppliers.cj.sync.enable') }}" method="POST">@csrf
                    <button class="px-4 py-2 text-sm bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg"><i class="fas fa-bell mr-1"></i>{{ $enabled ? 'Réactiver les alertes CJ' : 'Activer les alertes CJ' }}</button>
                </form>
                @if($enabled)
                    <form action="{{ route('admin.suppliers.cj.sync.disable') }}" method="POST">@csrf
                        <button class="px-4 py-2 text-sm bg-dark-200 text-gray-200 rounded-lg"><i class="fas fa-bell-slash mr-1"></i>Couper les alertes</button>
                    </form>
                @endif
                <form action="{{ route('admin.suppliers.cj.sync.rotate') }}" method="POST">@csrf
                    <button class="px-4 py-2 text-sm bg-dark-200 text-gray-200 rounded-lg" title="À faire si l’adresse a été divulguée"><i class="fas fa-key mr-1"></i>Nouvelle adresse secrète</button>
                </form>
            </div>
        </div>

        {{-- Suivi et relecture --}}
        <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 space-y-5">
            <div>
                <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-eye mr-2"></i>Produits suivis</h2>
                <p class="text-sm text-gray-300">{{ $subscribed }} / {{ $limit }} suivis par CJ, {{ $toSubscribe }} en attente. Le suivi est renouvelé automatiquement tous les 25 jours (CJ l’arrête après 30).</p>
                @if($expired > 0)
                    <p class="text-sm text-red-300"><i class="fas fa-triangle-exclamation mr-1"></i>{{ $expired }} produit(s) non renouvelé(s) depuis plus de 30 jours : CJ n’envoie plus leurs alertes. Cliquez sur « Inscrire maintenant » ou consultez l’erreur de l’onglet Connexion.</p>
                @endif
                <p class="text-xs text-gray-500 mb-2">CJ n’envoie d’alerte que pour les produits suivis. Inscription automatique toutes les 15 minutes.</p>
                <form action="{{ route('admin.suppliers.cj.sync.subscribe') }}" method="POST">@csrf
                    <button class="px-3 py-1.5 text-xs bg-dark-200 text-gray-200 rounded-lg" @disabled(! $enabled)><i class="fas fa-rotate mr-1"></i>Inscrire maintenant</button>
                </form>
            </div>
            <div>
                <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-moon mr-2"></i>Relecture de nuit</h2>
                <p class="text-sm text-gray-300">{{ $stale }} fiche(s) non relue(s) depuis {{ $hours }} h.</p>
                <p class="text-xs text-gray-500 mb-2">
                    Chaque nuit à 2 h 30 : prix, stocks et disponibilité relus chez CJ.
                    @if($cj->setting('last_resync_at'))
                        Dernière : {{ \Illuminate\Support\Carbon::parse($cj->setting('last_resync_at'))->diffForHumans() }} ({{ $cj->setting('last_resync_count', 0) }} fiche(s)).
                    @endif
                </p>
                <form action="{{ route('admin.suppliers.cj.sync.resync') }}" method="POST">@csrf
                    <button class="px-3 py-1.5 text-xs bg-dark-200 text-gray-200 rounded-lg"><i class="fas fa-arrows-rotate mr-1"></i>Tout relire maintenant</button>
                </form>
            </div>
        </div>
    </div>

    {{-- Journal --}}
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
            <h2 class="text-lg font-semibold text-white">Alertes reçues</h2>
            <form method="GET" class="flex gap-2 text-sm">
                <select name="type" class="px-2 py-1 bg-dark-200 rounded text-white">
                    <option value="">Tous les types</option>
                    @foreach(['PRODUCT' => 'Produit', 'VARIANT' => 'Déclinaison', 'STOCK' => 'Stock', 'ORDER' => 'Commande', 'LOGISTIC' => 'Suivi'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('type') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <select name="status" class="px-2 py-1 bg-dark-200 rounded text-white">
                    <option value="">Tous les états</option>
                    @foreach(\App\Models\SupplierWebhookEvent::STATUS_LABELS as $value => $label)
                        <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <button class="px-3 py-1 bg-dark-200 text-white rounded-lg"><i class="fas fa-filter"></i></button>
            </form>
        </div>

        @if($log->isEmpty())
            <p class="text-sm text-gray-400">Aucune alerte reçue pour l’instant.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-gray-500">
                        <tr>
                            <th class="py-2 pr-4">Reçue</th>
                            <th class="py-2 pr-4">Type</th>
                            <th class="py-2 pr-4">État</th>
                            <th class="py-2">Détail</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-dark-200">
                        @foreach($log as $event)
                            <tr class="align-top">
                                <td class="py-2 pr-4 text-gray-300 whitespace-nowrap">{{ $event->created_at->format('d/m H:i:s') }}</td>
                                <td class="py-2 pr-4 text-gray-200">
                                    {{ $event->type }} <span class="text-xs text-gray-500">{{ $event->message_type }}</span>
                                    @unless($event->signature_verified)<span class="text-xs text-yellow-300" title="Signature non vérifiée"><i class="fas fa-shield-halved"></i></span>@endunless
                                </td>
                                <td class="py-2 pr-4">
                                    <span class="text-xs px-2 py-1 rounded {{ match($event->status) { 'processed' => 'bg-green-900/40 text-green-300', 'failed' => 'bg-red-900/40 text-red-300', default => 'bg-dark-200 text-gray-300' } }}">{{ $event->statusLabel() }}</span>
                                </td>
                                <td class="py-2 text-xs text-gray-400 break-words">{{ $event->note }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $log->links() }}</div>
        @endif
    </div>
</div>
@endsection
