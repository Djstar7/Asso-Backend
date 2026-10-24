    <!-- Paiements : Wallet du compte interne du partenaire, versé comme le retrait ASSO -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6 flex flex-wrap items-center justify-between gap-4">
        <div>
            <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-wallet mr-2 text-primary-400"></i>Paiements du partenaire</h2>
            <p class="text-sm text-gray-400">Ses courses sont créditées sur son Wallet à chaque règlement. Solde à verser : <span class="text-white font-semibold">{{ number_format($walletBalance, 0, ',', ' ') }} FCFA</span></p>
        </div>
        @if(auth()->user()->isAdmin())
            <a href="{{ route('admin.partner-payouts.show', $partner) }}" class="px-4 py-2 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                <i class="fas fa-paper-plane mr-1"></i>Payer (Mobile Money / IBAN)
            </a>
        @endif
    </div>

    <!-- Coursiers : l'app ASSO reçoit les livraisons à domicile du partenaire -->
    @php
        $activeSyncs = $partner->activeCodeSyncs()->with('user')->get();
        $latestCode = $partner->syncCodes()->latest()->first();
    @endphp
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-white mb-1"><i class="fas fa-motorcycle mr-2 text-primary-400"></i>Coursiers dans l'app ASSO</h2>
                <p class="text-sm text-gray-400">Ils reçoivent les livraisons à domicile de {{ $partner->name }} et les clôturent avec le code à 6 chiffres de l'acheteur.</p>
                <div class="mt-3 text-sm">
                    @forelse($activeSyncs as $sync)
                        <span class="inline-block mr-2 mb-1 px-2 py-1 rounded bg-green-900/30 text-green-300 border border-green-500/40">
                            <i class="fas fa-user-check mr-1"></i>{{ $sync->user?->name }} {{ $sync->user?->phone ? '· ' . $sync->user->phone : '' }}
                        </span>
                    @empty
                        <span class="text-yellow-400"><i class="fas fa-exclamation-triangle mr-1"></i>Aucun coursier synchronisé : les commandes à domicile ne seront prises en charge par personne.</span>
                    @endforelse
                </div>
                @if($latestCode && !$latestCode->is_used && !$latestCode->isExpired())
                    <p class="mt-2 text-sm text-gray-400">Code en cours : <code class="text-primary-400 font-bold">{{ $latestCode->sync_code }}</code> (jusqu'au {{ $latestCode->expires_at->displayTz()->format('d/m/Y') }})</p>
                @endif
            </div>
            <div class="flex flex-col gap-2">
                <form action="{{ route('admin.deliverers.sync-code', $partner) }}" method="POST">
                    @csrf
                    <label class="block text-sm text-gray-300 mb-2">Agence du code
                        <select name="delivery_agency_id" class="w-full bg-dark-50 border border-dark-300 rounded px-3 py-2 text-white">
                            <option value="">Partenaire uniquement (sans agence)</option>
                            @foreach(\App\Models\DeliveryAgency::where('deliverer_company_id', $partner->id)->where('is_active', true)->where('is_international_reference', false)->orderBy('city')->get() as $codeAgency)
                                <option value="{{ $codeAgency->id }}">{{ $codeAgency->name }} · {{ $codeAgency->city }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm w-full">
                        <i class="fas fa-key mr-1"></i> Générer un code de synchronisation
                    </button>
                </form>
                <a href="{{ route('admin.deliverers.syncs.company', $partner) }}" class="text-primary-400 text-sm">Rattacher les livreurs existants à une agence</a>
                <a href="{{ route('admin.deliverers.show', $partner) }}" class="px-4 py-2 bg-dark-50 border border-dark-300 text-gray-300 rounded-lg hover:text-white text-sm text-center">
                    <i class="fas fa-id-card mr-1"></i> Fiche livreur et historique
                </a>
            </div>
        </div>
    </div>

    <!-- Partenaire -->
    <div class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6 mb-6">
        <h2 class="text-lg font-semibold text-white mb-4"><i class="fas fa-building mr-2 text-primary-400"></i>Partenaire</h2>
        <form action="{{ route('admin.delivery-partners.update', $partner) }}" method="POST">
            @csrf
            @method('PUT')
            @include('admin.delivery_partners._partner_fields', ['partner' => $partner])
            <div class="mt-4 flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-gray-300">
                    <input type="checkbox" name="is_active" value="1" @checked($partner->is_active) class="w-4 h-4 rounded bg-dark-50">
                    Partenaire actif (proposé aux acheteurs)
                </label>
                <button type="submit" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">
                    <i class="fas fa-save mr-2"></i> Enregistrer
                </button>
            </div>
        </form>
    </div>

