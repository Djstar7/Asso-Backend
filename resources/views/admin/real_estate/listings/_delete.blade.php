<form method="POST" action="{{ route('admin.real-estate.listings.destroy', $listing) }}"
      onsubmit="return confirm('Supprimer cette annonce ? Elle sera retirée de la publication. Les réservations et paiements existants seront conservés.');">
    @csrf
    @method('DELETE')
    <button type="submit" aria-label="Supprimer {{ $listing->title }}" class="px-4 py-2 border border-red-500/50 text-red-300 rounded-lg hover:bg-red-500/10">
        <i class="fas fa-trash mr-2"></i>Supprimer
    </button>
</form>
