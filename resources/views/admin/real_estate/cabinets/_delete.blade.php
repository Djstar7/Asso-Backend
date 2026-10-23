<form method="POST" action="{{ route('admin.real-estate.cabinets.destroy', $cabinet) }}"
      data-confirm="Supprimer ce cabinet partenaire ? Ses annonces seront archivées. Les réservations et paiements existants seront conservés.">
    @csrf
    @method('DELETE')
    <button type="submit" aria-label="Supprimer {{ $cabinet->name }}" class="px-4 py-2 border border-red-500/50 text-red-300 rounded-lg hover:bg-red-500/10">
        <i class="fas fa-trash mr-2"></i>Supprimer
    </button>
</form>
