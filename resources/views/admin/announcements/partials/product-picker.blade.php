{{-- Produit ouvert par un toucher sur l'annonce : push seulement, le seul canal qui arrive dans l'application. --}}
<div x-show="channel === 'push'" x-cloak class="bg-dark-100 rounded-xl shadow-lg border border-dark-200 p-6"
     x-data="{
        selected: @js($linkedProduct ? ['id' => $linkedProduct->id, 'name' => $linkedProduct->name] : null),
        query: '',
        results: [],
        searched: false,
        async search() {
            const term = this.query.trim();
            if (!term) { this.results = []; this.searched = false; return; }
            const response = await fetch(@js(route('admin.announcements.product-search')) + '?q=' + encodeURIComponent(term), {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok || term !== this.query.trim()) return;
            this.results = (await response.json()).products;
            this.searched = true;
        },
        choose(product) {
            this.selected = product;
            this.query = '';
            this.results = [];
            this.searched = false;
        },
     }">
    <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
        <i class="fas fa-box text-primary-500 mr-2"></i>
        Produit lié (optionnel)
    </h3>

    <input type="hidden" name="product_id" :value="selected ? selected.id : ''">

    <template x-if="selected">
        <div class="flex items-center justify-between p-3 bg-dark-50 border border-dark-300 rounded-lg">
            <div>
                <p class="text-white font-medium" x-text="selected.name"></p>
                <p class="text-xs text-gray-400" x-text="'N° ' + selected.id"></p>
            </div>
            @unless($disabled)
                <button type="button" @click="selected = null" class="text-sm text-red-400 hover:text-red-300">
                    <i class="fas fa-times mr-1"></i> Retirer
                </button>
            @endunless
        </div>
    </template>

    @unless($disabled)
        <div x-show="!selected">
            <input type="search" x-model="query" @input.debounce.300ms="search()" @keydown.enter.prevent
                   placeholder="Nom ou numéro du produit"
                   class="w-full px-4 py-3 bg-dark-50 border border-dark-300 rounded-lg text-white focus:ring-2 focus:ring-primary-500 focus:border-transparent">
            <div class="mt-2 space-y-1">
                <template x-for="product in results" :key="product.id">
                    <button type="button" @click="choose(product)"
                            class="w-full text-left p-3 bg-dark-50 hover:bg-dark-200 border border-dark-300 rounded-lg transition-colors">
                        <span class="block text-white font-medium" x-text="product.name"></span>
                        <span class="block text-xs text-gray-400" x-text="'N° ' + product.id + (product.shop ? ' · ' + product.shop : '')"></span>
                    </button>
                </template>
                <p x-show="searched && results.length === 0" class="text-sm text-gray-400">Aucun produit disponible pour cette recherche.</p>
            </div>
        </div>
    @endunless

    <p class="mt-2 text-xs text-gray-400">Un toucher sur l'annonce ouvre la fiche de ce produit. Sans produit, l'annonce reste un simple message.</p>
    @error('product_id')
        <p class="mt-1 text-sm text-red-400">{{ $message }}</p>
    @enderror
</div>
