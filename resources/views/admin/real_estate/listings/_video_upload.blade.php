<div id="listing-video-upload" @if($exists) data-url="{{ route('admin.real-estate.listings.video.store', $listing) }}" @endif>
    <label for="listing-video-file" class="{{ $label }}">Ajouter une vidéo</label>
    <input id="listing-video-file" type="file" name="video" accept="video/mp4,video/quicktime,video/x-m4v,video/3gpp,video/webm,.mp4,.mov,.m4v,.3gp,.webm" class="{{ $in }}" aria-describedby="listing-video-help listing-video-status">
    <p id="listing-video-help" class="text-xs text-gray-400 mt-1">MP4, MOV, M4V, 3GP ou WebM · 100 Mo maximum.
        {{ $exists ? 'L’envoi démarre dès la sélection, sans réenregistrer les autres champs.' : 'La vidéo sera envoyée en cliquant sur Enregistrer.' }}
    </p>
    <progress id="listing-video-progress" max="100" value="0" class="hidden w-full mt-2" aria-label="Progression de l’envoi de la vidéo"></progress>
    <p id="listing-video-status" class="text-sm mt-2" role="status" aria-live="polite"></p>
    <video id="listing-video-preview" class="hidden w-full max-h-64 mt-3 rounded-lg" controls playsinline preload="metadata"></video>
</div>

@push('scripts')
<script>
(function () {
    const root = document.getElementById('listing-video-upload');
    if (!root) return;
    const input = document.getElementById('listing-video-file');
    const status = document.getElementById('listing-video-status');
    const progress = document.getElementById('listing-video-progress');
    const preview = document.getElementById('listing-video-preview');
    const form = root.closest('form');
    let uploading = false;
    function message(text, error = false) {
        status.textContent = text;
        status.classList.toggle('text-red-400', error);
        status.classList.toggle('text-green-300', !error);
    }
    form.addEventListener('submit', event => {
        if (uploading) {
            event.preventDefault();
            message('Attendez la fin de l’envoi de la vidéo avant d’enregistrer.', true);
        }
    });
    input.addEventListener('change', function () {
        const file = input.files[0];
        if (!file || uploading) return;
        if (!/\.(mp4|mov|m4v|3gp|webm)$/i.test(file.name) || file.size > 100 * 1024 * 1024 || file.size === 0) {
            message('Choisissez une vidéo MP4, MOV, M4V, 3GP ou WebM de 100 Mo maximum.', true);
            input.value = '';
            return;
        }
        if (!root.dataset.url) {
            message('Vidéo sélectionnée. Cliquez sur Enregistrer pour l’envoyer.');
            return;
        }
        const data = new FormData();
        data.append('video', file);
        data.append('_token', form.querySelector('[name="_token"]').value);
        const xhr = new XMLHttpRequest();
        uploading = true;
        input.disabled = true;
        progress.value = 0;
        progress.classList.remove('hidden');
        message('Envoi de la vidéo…');
        xhr.open('POST', root.dataset.url);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.timeout = 300000;
        xhr.upload.onprogress = event => {
            if (event.lengthComputable) progress.value = Math.round(event.loaded / event.total * 100);
        };
        xhr.onload = () => {
            let result;
            try { result = JSON.parse(xhr.responseText); } catch (_) { result = null; }
            if (xhr.status === 201 && result?.success && result.media?.url) {
                preview.src = result.media.url;
                preview.classList.remove('hidden');
                input.value = '';
                message('Vidéo enregistrée. Elle est maintenant la vidéo de présentation de cette annonce.');
            } else if (xhr.status === 413) {
                message('Le serveur refuse la taille de cet envoi. Réduisez la vidéo ou faites relever la limite d’envoi du serveur.', true);
            } else {
                const errors = Object.values(result?.errors || {}).flat();
                message(errors.join(' ') || (xhr.status === 419 ? 'Votre session a expiré. Rechargez la page.' : 'La vidéo n’a pas été enregistrée. Réessayez.'), true);
            }
        };
        xhr.onerror = () => message('L’envoi a échoué. Vérifiez votre connexion et sélectionnez à nouveau la vidéo.', true);
        xhr.ontimeout = () => message('L’envoi a pris trop de temps. Réessayez avec une vidéo plus légère.', true);
        xhr.onloadend = () => {
            uploading = false;
            input.value = '';
            input.disabled = false;
            progress.classList.add('hidden');
        };
        xhr.send(data);
    });
})();
</script>
@endpush
