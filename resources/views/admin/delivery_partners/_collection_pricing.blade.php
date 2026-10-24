@php($input = 'w-full bg-dark-50 border border-dark-300 rounded-lg px-3 py-2 text-white text-sm')
<div class="mb-5 p-5 bg-dark-100 border border-dark-200 rounded-xl">
    <h2 class="text-lg font-semibold text-white">Tarif boutique du vendeur → agence Solex</h2>
    <p class="mt-2 text-sm text-gray-300">Ce montant est ajouté au transport international DHL/FedEx. Il est commun aux destinations utilisant cette agence. Les commandes déjà payées gardent leur prix.</p>
    <p class="mt-2 text-sm text-gray-400">Collecte = forfait au poids + supplément au-delà du poids inclus + distance × FCFA/km. La distance est estimée à vol d’oiseau. Les taxes et frais ASSO apparaissent séparément dans le total.</p>
</div>
@foreach($agencies as $agency)
    @php($pricing = $agency->collection_pricing ?? [])
    <form method="POST" action="{{ route('admin.delivery-partners.agencies.collection-pricing', [$partner, $agency]) }}" class="mb-4 p-5 bg-dark-100 border border-dark-200 rounded-xl">
        @csrf
        <h3 class="text-white font-semibold">{{ $agency->name }} · {{ $agency->city }}</h3>
        @if(!$pricing)<p class="text-sm text-yellow-300 mt-1">Tarif central non défini : les trajets DHL/FedEx utilisent encore leur tarif de collecte de repli.</p>@endif
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3 mt-4">
            @foreach(['collection_price' => ['Forfait collecte (FCFA)', $pricing['ranges'][0]['price'] ?? 2500], 'collection_max_kg' => ['Poids inclus (kg)', $pricing['ranges'][0]['max'] ?? 5], 'collection_extra_per_kg' => ['Supplément FCFA/kg au-delà', $pricing['extra_per_kg'] ?? 250], 'collection_price_per_km' => ['Supplément FCFA/km', $pricing['price_per_km'] ?? 0]] as $field => [$label, $value])
                <label class="text-sm text-gray-300">{{ $label }}<input required type="number" min="{{ $field === 'collection_max_kg' ? '0.001' : '0' }}" step="0.001" name="{{ $field }}" value="{{ $value }}" class="{{ $input }}"></label>
            @endforeach
        </div>
        <div class="flex flex-wrap items-center gap-4 mt-4">
            <button class="px-4 py-2 bg-primary-500 rounded-lg text-white text-sm">Enregistrer le tarif de collecte</button>
            <a class="text-primary-400 text-sm" href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => 'agencies', 'page' => $agencies->currentPage()]) }}">Modifier le GPS ou l’adresse de l’agence</a>
        </div>
    </form>
@endforeach
{{ $agencies->links() }}
