{{--
    Vidéo de présentation d'un produit grossiste (Chine / Turquie / Dubaï).

    La vidéo part dès qu'elle est choisie, par morceaux de 4 Mo, sans attendre
    l'enregistrement du formulaire : barre de progression réelle, reprise d'un
    morceau en cas de coupure, et aucune limite d'upload PHP à relever. Seul son
    identifiant (video_id) est envoyé avec le produit.
--}}
@php
    $currentVideo = isset($product) ? $product->video : null;
    $currentVideoData = $currentVideo ? [
        'id' => $currentVideo->id,
        'status' => $currentVideo->status,
        'duration' => $currentVideo->duration,
        'poster_url' => $currentVideo->poster_path ? route('admin.product-videos.media', [$currentVideo, 'poster']) : null,
        'video_url' => $currentVideo->isReady() ? route('admin.product-videos.media', [$currentVideo, 'video']) : null,
        'name' => $currentVideo->original_name,
    ] : null;
@endphp

<div class="mt-6 border-t border-dark-300 pt-4" id="video_manager"
     data-chunk-url="{{ route('admin.product-videos.chunk') }}"
     data-base-url="{{ url('/admin/product-videos') }}"
     data-max-mb="{{ \App\Models\ProductVideo::MAX_SIZE_MB }}"
     data-extensions="{{ implode(',', \App\Models\ProductVideo::EXTENSIONS) }}"
     data-current='@json($currentVideoData)'>

    <h3 class="text-sm font-semibold text-white mb-1">
        <i class="fas fa-video text-primary-500 mr-1"></i> Vidéo de présentation
        <span class="text-gray-400 font-normal">(optionnelle)</span>
    </h3>
    <p class="text-xs text-gray-400 mb-3">
        Lue en boucle et sans le son sur la carte du catalogue grossiste, avec le son sur la fiche produit.
        Format vertical (filmé au téléphone) conseillé · {{ \App\Models\ProductVideo::MAX_SIZE_MB }} Mo max.
    </p>

    <input type="hidden" name="video_id" id="vm_video_id" value="{{ old('video_id') }}">
    <input type="hidden" name="remove_video" id="vm_remove" value="0">

    <div class="flex gap-4 items-start">
        {{-- Aperçu au format téléphone --}}
        <div id="vm_frame" class="relative w-28 shrink-0 rounded-lg overflow-hidden bg-dark-50 border border-dark-300 hidden" style="aspect-ratio: 9 / 16;">
            <video id="vm_player" class="w-full h-full object-cover" muted loop playsinline></video>
            <span id="vm_duration" class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/70 text-white text-[10px] hidden"></span>
        </div>

        <div class="flex-1 min-w-0">
            <label for="vm_input" id="vm_dropzone"
                   class="flex flex-col items-center justify-center gap-1 px-4 py-6 border-2 border-dashed border-dark-300 rounded-xl cursor-pointer bg-dark-50/50 hover:border-primary-500 hover:bg-primary-500/5 transition text-center">
                <i class="fas fa-film text-2xl text-primary-500"></i>
                <span class="text-white text-sm font-medium">Glissez une vidéo ici ou <span class="text-primary-400 underline">parcourez</span></span>
                <span class="text-xs text-gray-400">MP4, MOV, WEBM · l'envoi commence tout de suite</span>
            </label>
            {{-- Sans attribut name : le fichier ne part pas avec le formulaire, seulement par morceaux. --}}
            <input type="file" id="vm_input" accept="video/*,.mp4,.mov,.m4v,.webm,.3gp,.mkv" class="hidden">

            <div id="vm_state" class="hidden">
                <div class="flex items-center justify-between gap-3 text-sm">
                    <span id="vm_name" class="text-white truncate"></span>
                    <span id="vm_percent" class="text-gray-300 shrink-0"></span>
                </div>
                <div class="mt-2 h-2 rounded-full bg-dark-300 overflow-hidden">
                    <div id="vm_bar" class="h-full bg-primary-500 transition-all duration-200" style="width: 0%"></div>
                </div>
                <p id="vm_status" class="mt-2 text-xs text-gray-400"></p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button type="button" id="vm_cancel" class="px-3 py-1.5 text-xs rounded-lg bg-dark-300 text-white hover:bg-dark-200">
                        <i class="fas fa-times mr-1"></i>Annuler l'envoi
                    </button>
                    <button type="button" id="vm_retry" class="hidden px-3 py-1.5 text-xs rounded-lg bg-primary-600 text-white hover:bg-primary-700">
                        <i class="fas fa-redo mr-1"></i>Réessayer
                    </button>
                    <button type="button" id="vm_replace" class="hidden px-3 py-1.5 text-xs rounded-lg bg-dark-300 text-white hover:bg-dark-200">
                        <i class="fas fa-exchange-alt mr-1"></i>Remplacer
                    </button>
                    <button type="button" id="vm_delete" class="hidden px-3 py-1.5 text-xs rounded-lg bg-dark-300 text-red-300 hover:bg-red-600 hover:text-white">
                        <i class="fas fa-trash mr-1"></i>Retirer la vidéo
                    </button>
                </div>
            </div>
            <p id="vm_error" class="hidden mt-2 text-sm text-red-400"></p>
        </div>
    </div>
</div>

@push('scripts')
<script>
(function () {
    const root = document.getElementById('video_manager');
    if (!root) return;

    const CHUNK_SIZE = 4 * 1024 * 1024; // sous upload_max_filesize (20 Mo) avec de la marge
    const MAX_BYTES = parseInt(root.dataset.maxMb, 10) * 1024 * 1024;
    const EXTENSIONS = root.dataset.extensions.split(',');
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value;

    const el = (id) => document.getElementById(id);
    const input = el('vm_input'), dropzone = el('vm_dropzone'), frame = el('vm_frame'), player = el('vm_player');
    const state = el('vm_state'), nameEl = el('vm_name'), percentEl = el('vm_percent'), bar = el('vm_bar');
    const statusEl = el('vm_status'), errorEl = el('vm_error'), durationEl = el('vm_duration');
    const btnCancel = el('vm_cancel'), btnRetry = el('vm_retry'), btnReplace = el('vm_replace'), btnDelete = el('vm_delete');
    const idInput = el('vm_video_id'), removeInput = el('vm_remove');
    const form = root.closest('form');

    let current = null;      // { file, uploadId, xhr, cancelled }
    let uploading = false;
    let pollTimer = null;
    let localUrl = null;
    let existing = JSON.parse(root.dataset.current || 'null'); // vidéo déjà rattachée (édition)

    const fmtDuration = (s) => {
        if (!s && s !== 0) return '';
        const t = Math.round(s), m = Math.floor(t / 60), r = String(t % 60).padStart(2, '0');
        return `${m}:${r}`;
    };
    const fmtSize = (b) => b >= 1048576 ? (b / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(b / 1024)) + ' Ko';
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
            const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
        }));

    function show(elem, visible) { elem.classList.toggle('hidden', !visible); }
    function setError(msg) { errorEl.textContent = msg || ''; show(errorEl, !!msg); }
    function setProgress(ratio) {
        const pct = Math.min(100, Math.round(ratio * 100));
        bar.style.width = pct + '%';
        percentEl.textContent = pct + ' %';
    }
    function setButtons({ cancel = false, retry = false, replace = false, remove = false }) {
        show(btnCancel, cancel); show(btnRetry, retry); show(btnReplace, replace); show(btnDelete, remove);
    }
    function preview(src, duration) {
        if (!src) { show(frame, false); return; }
        player.src = src;
        player.play().catch(() => {});
        show(frame, true);
        durationEl.textContent = fmtDuration(duration);
        show(durationEl, !!duration);
    }
    function reset() {
        clearTimeout(pollTimer);
        if (localUrl) { URL.revokeObjectURL(localUrl); localUrl = null; }
        current = null; uploading = false;
        idInput.value = '';
        player.removeAttribute('src'); player.load();
        show(frame, false); show(state, false); show(dropzone, true);
        setError('');
    }

    // ---------- Envoi par morceaux ----------

    function sendChunk(index, total) {
        return new Promise((resolve, reject) => {
            const { file, uploadId } = current;
            const start = index * CHUNK_SIZE;
            const blob = file.slice(start, Math.min(file.size, start + CHUNK_SIZE));

            const data = new FormData();
            data.append('upload_id', uploadId);
            data.append('index', index);
            data.append('total', total);
            data.append('size', file.size);
            data.append('name', file.name);
            data.append('chunk', blob, file.name + '.part' + index);

            const xhr = new XMLHttpRequest();
            current.xhr = xhr;
            xhr.open('POST', root.dataset.chunkUrl);
            xhr.setRequestHeader('X-CSRF-TOKEN', CSRF);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = (e) => {
                if (e.lengthComputable) setProgress((start + e.loaded) / file.size);
            };
            xhr.onload = () => {
                let body = null;
                try { body = JSON.parse(xhr.responseText); } catch (_) {}
                if (xhr.status >= 200 && xhr.status < 300) return resolve(body);
                const message = body?.message || (body?.errors && Object.values(body.errors)[0]?.[0])
                    || (xhr.status === 413 ? 'Morceau refusé par le serveur (trop volumineux).' : 'Envoi refusé.');
                // Erreur de validation : inutile de réessayer le même morceau.
                reject({ fatal: xhr.status === 422 || xhr.status === 419 || xhr.status === 413, message });
            };
            xhr.onerror = () => reject({ fatal: false, message: 'Connexion interrompue.' });
            xhr.onabort = () => reject({ aborted: true });
            xhr.send(data);
        });
    }

    async function upload() {
        const { file } = current;
        const total = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        uploading = true;
        setError('');
        setButtons({ cancel: true });
        statusEl.textContent = `Envoi de ${fmtSize(file.size)}…`;

        let result = null;
        for (let i = 0; i < total; i++) {
            let attempt = 0;
            while (true) {
                try {
                    result = await sendChunk(i, total);
                    break;
                } catch (err) {
                    if (err.aborted || current?.cancelled) { uploading = false; return; }
                    // Coupure réseau : on renvoie le même morceau, jusqu'à 3 fois.
                    if (!err.fatal && attempt < 3) {
                        attempt++;
                        statusEl.textContent = `Connexion instable, nouvel essai (${attempt}/3)…`;
                        await new Promise(r => setTimeout(r, 1500 * attempt));
                        continue;
                    }
                    uploading = false;
                    setError(err.message);
                    statusEl.textContent = 'Envoi interrompu.';
                    setButtons({ retry: !err.fatal, replace: true });
                    return;
                }
            }
        }

        uploading = false;
        setProgress(1);
        const video = result?.video;
        if (!video) {
            setError('Réponse inattendue du serveur.');
            setButtons({ retry: true, replace: true });
            return;
        }
        idInput.value = video.id;
        current.videoId = video.id;
        track(video);
    }

    // ---------- Suivi du traitement (affiche, aperçu, conversion) ----------

    function track(video) {
        clearTimeout(pollTimer);
        if (video.duration) { durationEl.textContent = fmtDuration(video.duration); show(durationEl, true); }

        if (video.status === 'ready') {
            const published = existing && existing.id === video.id;
            statusEl.innerHTML = '<span class="text-green-400"><i class="fas fa-check-circle mr-1"></i>'
                + (published ? 'Vidéo publiée' : 'Vidéo prête') + '</span>'
                + (video.duration ? ' · ' + fmtDuration(video.duration) : '')
                + (published ? '' : ' — elle sera publiée à l\'enregistrement du produit.');
            if (video.video_url && !localUrl) preview(video.video_url, video.duration);
            setButtons({ replace: true, remove: true });
            return;
        }
        if (video.status === 'failed') {
            idInput.value = '';
            setError(video.error || 'Cette vidéo n\'a pas pu être traitée.');
            statusEl.textContent = '';
            setButtons({ replace: true });
            return;
        }

        statusEl.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i>Préparation de la vidéo (aperçu, compression)… '
            + 'Vous pouvez déjà enregistrer le produit.';
        setButtons({ replace: true, remove: true });
        pollTimer = setTimeout(() => {
            fetch(`${root.dataset.baseUrl}/${video.id}`, { headers: { 'Accept': 'application/json' } })
                .then(r => r.json())
                .then(body => body?.video && track(body.video))
                .catch(() => { pollTimer = setTimeout(() => track(video), 4000); });
        }, 2000);
    }

    // ---------- Choix du fichier ----------

    function start(file) {
        if (!file) return;
        const ext = (file.name.split('.').pop() || '').toLowerCase();
        if (!EXTENSIONS.includes(ext)) {
            setError(`Format non pris en charge. Formats acceptés : ${EXTENSIONS.join(', ')}.`);
            return;
        }
        if (file.size > MAX_BYTES) {
            setError(`Vidéo trop lourde (${fmtSize(file.size)}). Maximum : ${root.dataset.maxMb} Mo.`);
            return;
        }

        // Une vidéo envoyée mais pas encore rattachée est remplacée : on la supprime.
        const previous = current?.videoId;
        if (previous && !existing) discard(previous);

        clearTimeout(pollTimer);
        if (localUrl) URL.revokeObjectURL(localUrl);
        localUrl = URL.createObjectURL(file);
        preview(localUrl, null);

        current = { file, uploadId: uuid(), cancelled: false };
        nameEl.textContent = file.name;
        setProgress(0);
        show(dropzone, false);
        show(state, true);
        upload();
    }

    function discard(id) {
        fetch(`${root.dataset.baseUrl}/${id}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        }).catch(() => {});
    }

    input.addEventListener('change', () => { start(input.files[0]); input.value = ''; });
    ['dragover', 'dragenter'].forEach(evt => dropzone.addEventListener(evt, (e) => {
        e.preventDefault(); dropzone.classList.add('border-primary-500');
    }));
    ['dragleave', 'drop'].forEach(evt => dropzone.addEventListener(evt, (e) => {
        e.preventDefault(); dropzone.classList.remove('border-primary-500');
    }));
    dropzone.addEventListener('drop', (e) => start(e.dataTransfer.files[0]));

    btnCancel.addEventListener('click', () => {
        if (!current) return;
        current.cancelled = true;
        current.xhr?.abort();
        if (current.videoId) discard(current.videoId);
        reset();
        if (existing) showExisting();
    });
    btnRetry.addEventListener('click', () => { if (current) { current.uploadId = uuid(); setProgress(0); upload(); } });
    btnReplace.addEventListener('click', () => input.click());
    btnDelete.addEventListener('click', () => {
        const confirmAndRemove = () => {
            if (current?.videoId) discard(current.videoId);
            // Vidéo déjà publiée : retirée à l'enregistrement du produit.
            if (existing) removeInput.value = '1';
            existing = null;
            reset();
        };
        if (window.customConfirm) window.customConfirm('Retirer la vidéo de ce produit ?', confirmAndRemove);
        else if (confirm('Retirer la vidéo de ce produit ?')) confirmAndRemove();
    });

    // Enregistrer pendant l'envoi ferait perdre la vidéo : on attend la fin.
    form?.addEventListener('submit', (e) => {
        if (!uploading) return;
        e.preventDefault();
        setError(`Envoi de la vidéo en cours (${percentEl.textContent}). Patientez avant d'enregistrer le produit.`);
        root.scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
    window.addEventListener('beforeunload', (e) => {
        if (uploading) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---------- Vidéo existante (édition) ----------

    function showExisting() {
        if (!existing) return;
        nameEl.textContent = existing.name || 'Vidéo actuelle';
        setProgress(1);
        percentEl.textContent = '';
        show(dropzone, false);
        show(state, true);
        preview(existing.video_url, existing.duration);
        track(existing);
    }
    // Retour du formulaire après une erreur de validation : la vidéo déjà
    // envoyée est toujours là (video_id conservé), on réaffiche son état.
    const pendingId = idInput.value;
    if (pendingId && !(existing && String(existing.id) === pendingId)) {
        fetch(`${root.dataset.baseUrl}/${pendingId}`, { headers: { 'Accept': 'application/json' } })
            .then(r => r.ok ? r.json() : null)
            .then(body => {
                if (!body?.video) { idInput.value = ''; return; }
                current = { videoId: body.video.id, cancelled: false };
                nameEl.textContent = 'Vidéo envoyée';
                setProgress(1);
                show(dropzone, false);
                show(state, true);
                preview(body.video.video_url, body.video.duration);
                track(body.video);
            })
            .catch(() => {});
    } else {
        showExisting();
    }
})();
</script>
@endpush
