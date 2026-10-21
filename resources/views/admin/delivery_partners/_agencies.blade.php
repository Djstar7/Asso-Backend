@php($input = 'w-full px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-white text-sm')
<div class="bg-dark-100 rounded-xl border border-dark-200 p-6 mb-6">
    <h2 class="text-lg font-semibold text-white mb-3">Agences de retrait</h2>
    <p class="text-sm text-gray-400 mb-4">Coordonnées communiquées au client lorsque son colis est disponible. L'équipe ASSO renseigne le suivi et les confirmations du partenaire.</p>
    @foreach($agencies as $agency)
        <form method="POST" action="{{ route('admin.delivery-partners.agencies.update', [$partner, $agency]) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3 border-b border-dark-200 pb-4 mb-4">
            @csrf @method('PUT')
            @foreach(['code' => 'Code', 'name' => 'Nom', 'city' => 'Ville', 'address' => 'Adresse', 'phone' => 'Téléphone'] as $field => $label)
                <label class="text-sm text-gray-300">{{ $label }}<input name="{{ $field }}" value="{{ $agency->$field }}" required maxlength="{{ ['code'=>40,'name'=>150,'city'=>120,'address'=>255,'phone'=>40][$field] }}" class="{{ $input }}"></label>
            @endforeach
            <label class="text-sm text-gray-300"><input type="checkbox" name="is_active" value="1" @checked($agency->is_active)> Agence active</label>
            <button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm">Enregistrer l'agence</button>
        </form>
    @endforeach
    <div class="mb-5">{{ $agencies->links() }}</div>
    <form method="POST" action="{{ route('admin.delivery-partners.agencies.store', $partner) }}" class="grid grid-cols-1 md:grid-cols-3 gap-3">
        @csrf
        @foreach(['code' => 'Code unique (ex. SOLEX-YDE)', 'name' => 'Nom', 'city' => 'Ville', 'address' => 'Adresse', 'phone' => 'Téléphone'] as $field => $label)
            <label class="text-sm text-gray-300">{{ $label }}<input name="{{ $field }}" value="{{ old($field) }}" required maxlength="{{ ['code'=>40,'name'=>150,'city'=>120,'address'=>255,'phone'=>40][$field] }}" class="{{ $input }}"></label>
        @endforeach
        <div class="flex items-end"><button class="px-4 py-2 bg-primary-500 text-white rounded-lg text-sm">Ajouter une agence</button></div>
    </form>
</div>
