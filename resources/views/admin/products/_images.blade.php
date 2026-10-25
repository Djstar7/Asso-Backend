{{-- Galerie photos produit : ajout multiple par glisser-déposer + gestion des photos existantes. --}}
@php($existingImages = isset($product) ? $product->images : collect())

<div class="bg-dark-100 rounded-xl shadow-lg p-6" id="image_manager">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-bold text-white flex items-center">
            <i class="fas fa-images text-primary-500 mr-2"></i>Photos du produit
        </h3>
        <span class="text-xs text-gray-400" id="im_counter"></span>
    </div>

    @if($existingImages->isNotEmpty())
        <p class="text-sm text-gray-400 mb-3">
            <i class="fas fa-arrows-alt mr-1"></i>Glissez les photos pour changer l'ordre. La première est la photo principale.
        </p>
        <div id="im_existing" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mb-5">
            @foreach($existingImages as $image)
                <div class="im-existing relative group rounded-lg overflow-hidden border-2 {{ $image->is_primary ? 'border-primary-500' : 'border-dark-300' }} cursor-move bg-dark-50"
                     draggable="true" data-id="{{ $image->id }}" id="image-{{ $image->id }}">
                    <img src="{{ media_url($image->image_path) }}" alt="{{ $product->name }}" class="w-full h-32 object-cover pointer-events-none">
                    <span class="im-primary-badge absolute top-2 left-2 px-2 py-0.5 bg-primary-600 text-white text-xs rounded-full {{ $image->is_primary ? '' : 'hidden' }}">
                        <i class="fas fa-star mr-1"></i>Principale
                    </span>
                    <div class="absolute inset-x-0 bottom-0 p-2 flex justify-end gap-1 bg-gradient-to-t from-black/80 to-transparent opacity-100 md:opacity-0 md:group-hover:opacity-100 transition">
                        <button type="button" data-make-primary="{{ $image->id }}" title="Définir comme principale"
                                class="w-8 h-8 rounded-full bg-white/10 hover:bg-primary-600 text-white"><i class="fas fa-star text-xs"></i></button>
                        <button type="button" data-delete-image="{{ $image->id }}" title="Supprimer"
                                class="w-8 h-8 rounded-full bg-white/10 hover:bg-red-600 text-white"><i class="fas fa-trash text-xs"></i></button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Zone d'ajout --}}
    <label for="im_input" id="im_dropzone"
           class="flex flex-col items-center justify-center gap-2 px-6 py-8 border-2 border-dashed border-dark-300 rounded-xl cursor-pointer bg-dark-50/50 hover:border-primary-500 hover:bg-primary-500/5 transition text-center">
        <i class="fas fa-cloud-upload-alt text-3xl text-primary-500"></i>
        <span class="text-white font-medium">Glissez vos photos ici ou <span class="text-primary-400 underline">parcourez</span></span>
        <span class="text-xs text-gray-400">Plusieurs photos possibles, en une ou plusieurs fois · JPG, PNG, WEBP · 5 Mo max par photo · 15 photos max</span>
    </label>
    <input type="file" id="im_input" name="images[]" multiple accept="image/jpeg,image/png,image/webp,image/gif" class="hidden" data-custom-file-upload>
    <p id="im_error" class="hidden mt-2 text-sm text-red-400"></p>
    @error('images')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror
    @error('images.*')<p class="mt-2 text-sm text-red-400">{{ $message }}</p>@enderror

    <div id="im_new" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3 mt-4"></div>
</div>

@push('scripts')
<script>
(function () {
    const MAX_FILES = 15, MAX_SIZE = 5 * 1024 * 1024;
    const productId = @json(isset($product) ? $product->id : null);
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="_token"]')?.value;
    const input = document.getElementById('im_input');
    const dropzone = document.getElementById('im_dropzone');
    const newGrid = document.getElementById('im_new');
    const existingGrid = document.getElementById('im_existing');
    const errorBox = document.getElementById('im_error');
    let files = [];

    const existingCount = () => existingGrid ? existingGrid.querySelectorAll('.im-existing').length : 0;

    function showError(message) {
        errorBox.textContent = message;
        errorBox.classList.toggle('hidden', !message);
    }

    function updateCounter() {
        const total = existingCount() + files.length;
        document.getElementById('im_counter').textContent = total ? `${total} photo${total > 1 ? 's' : ''}` : 'Aucune photo';
    }

    // Le champ fichier natif remplace sa sélection à chaque ouverture : on reconstruit sa liste.
    function syncInput() {
        const dt = new DataTransfer();
        files.forEach(f => dt.items.add(f));
        input.files = dt.files;
        renderNew();
    }

    function addFiles(list) {
        const rejected = [];
        Array.from(list).forEach(file => {
            if (!file.type.startsWith('image/')) return rejected.push(`${file.name} (format non accepté)`);
            if (file.size > MAX_SIZE) return rejected.push(`${file.name} (plus de 5 Mo)`);
            if (existingCount() + files.length >= MAX_FILES) return rejected.push(`${file.name} (limite de ${MAX_FILES} photos)`);
            if (files.some(f => f.name === file.name && f.size === file.size)) return;
            files.push(file);
        });
        showError(rejected.length ? 'Photos ignorées : ' + rejected.join(', ') : '');
        syncInput();
    }

    function renderNew() {
        newGrid.innerHTML = '';
        files.forEach((file, index) => {
            const isMain = existingCount() === 0 && index === 0;
            const card = document.createElement('div');
            card.className = `relative rounded-lg overflow-hidden border-2 ${isMain ? 'border-primary-500' : 'border-dark-300'} bg-dark-50`;
            const url = URL.createObjectURL(file);
            card.innerHTML = `
                <img src="${url}" class="w-full h-32 object-cover" alt="">
                <span class="absolute top-2 left-2 px-2 py-0.5 ${isMain ? 'bg-primary-600' : 'bg-blue-600'} text-white text-xs rounded-full">
                    <i class="fas ${isMain ? 'fa-star' : 'fa-plus'} mr-1"></i>${isMain ? 'Principale' : 'Nouvelle'}
                </span>
                <div class="absolute inset-x-0 bottom-0 p-2 flex justify-end gap-1 bg-gradient-to-t from-black/80 to-transparent">
                    ${index > 0 ? `<button type="button" data-first="${index}" title="Mettre en premier" class="w-8 h-8 rounded-full bg-white/10 hover:bg-primary-600 text-white"><i class="fas fa-star text-xs"></i></button>` : ''}
                    <button type="button" data-remove="${index}" title="Retirer" class="w-8 h-8 rounded-full bg-white/10 hover:bg-red-600 text-white"><i class="fas fa-times text-xs"></i></button>
                </div>`;
            card.querySelector('img').onload = () => URL.revokeObjectURL(url);
            newGrid.appendChild(card);
        });
        updateCounter();
    }

    input.addEventListener('change', e => {
        // input.files vient d'être remplacé par la nouvelle sélection : on l'ajoute aux précédentes.
        const picked = Array.from(e.target.files).filter(f => !files.includes(f));
        addFiles(picked);
    });

    ['dragenter', 'dragover'].forEach(evt => dropzone.addEventListener(evt, e => {
        e.preventDefault();
        dropzone.classList.add('border-primary-500', 'bg-primary-500/10');
    }));
    ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, e => {
        e.preventDefault();
        dropzone.classList.remove('border-primary-500', 'bg-primary-500/10');
    }));
    dropzone.addEventListener('drop', e => addFiles(e.dataTransfer.files));

    newGrid.addEventListener('click', e => {
        const btn = e.target.closest('button');
        if (!btn) return;
        if (btn.dataset.remove !== undefined) files.splice(+btn.dataset.remove, 1);
        if (btn.dataset.first !== undefined) files.unshift(...files.splice(+btn.dataset.first, 1));
        syncInput();
    });

    // ---------- Photos déjà enregistrées (édition) ----------
    function markPrimary() {
        existingGrid?.querySelectorAll('.im-existing').forEach((card, i) => {
            card.classList.toggle('border-primary-500', i === 0);
            card.classList.toggle('border-dark-300', i !== 0);
            card.querySelector('.im-primary-badge').classList.toggle('hidden', i !== 0);
        });
    }

    function saveOrder() {
        const order = Array.from(existingGrid.querySelectorAll('.im-existing')).map(c => +c.dataset.id);
        markPrimary();
        fetch(`/admin/products/${productId}/images/reorder`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
            body: JSON.stringify({ order }),
        }).then(r => { if (!r.ok) throw new Error(); })
          .catch(() => showError("L'ordre des photos n'a pas pu être enregistré. Réessayez."));
    }

    if (existingGrid && productId) {
        let dragged = null;
        existingGrid.addEventListener('dragstart', e => {
            dragged = e.target.closest('.im-existing');
            dragged?.classList.add('opacity-40');
        });
        existingGrid.addEventListener('dragend', () => {
            dragged?.classList.remove('opacity-40');
            dragged = null;
            saveOrder();
        });
        existingGrid.addEventListener('dragover', e => {
            e.preventDefault();
            const target = e.target.closest('.im-existing');
            if (!dragged || !target || target === dragged) return;
            const rect = target.getBoundingClientRect();
            const after = (e.clientX - rect.left) > rect.width / 2;
            target.parentNode.insertBefore(dragged, after ? target.nextSibling : target);
        });

        existingGrid.addEventListener('click', e => {
            const btn = e.target.closest('button');
            if (!btn) return;
            const card = btn.closest('.im-existing');
            if (btn.dataset.makePrimary) {
                existingGrid.prepend(card);
                saveOrder();
            }
            if (btn.dataset.deleteImage) {
                const doDelete = () => fetch(`/admin/products/${productId}/images/${btn.dataset.deleteImage}`, {
                    method: 'DELETE',
                    headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                }).then(r => r.json()).then(data => {
                    if (!data.success) throw new Error();
                    card.remove();
                    markPrimary();
                    renderNew();
                }).catch(() => showError("La photo n'a pas pu être supprimée."));
                window.customConfirm('Supprimer définitivement cette photo ?', doDelete, { source: btn });
            }
        });
    }

    updateCounter();
})();
</script>
@endpush
