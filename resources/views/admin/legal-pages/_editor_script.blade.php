<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        var toolbarOptions = [
            [{ 'header': [1, 2, 3, 4, false] }],
            ['bold', 'italic', 'underline', 'strike'],
            [{ 'list': 'ordered'}, { 'list': 'bullet' }],
            [{ 'indent': '-1'}, { 'indent': '+1' }],
            [{ 'align': [] }],
            [{ 'color': [] }, { 'background': [] }],
            ['link', 'blockquote', 'code-block'],
            ['clean']
        ];

        // Un éditeur par langue ; chacun recopie son HTML dans son champ caché.
        var editors = Array.prototype.map.call(document.querySelectorAll('.legal-editor'), function (el) {
            var quill = new Quill(el, {
                theme: 'snow',
                modules: { toolbar: toolbarOptions },
                placeholder: 'Rédigez le contenu de votre page légale ici...'
            });
            if (el.dataset.initial) {
                quill.root.innerHTML = el.dataset.initial;
            }
            var input = document.getElementById(el.dataset.input);
            var sync = function () {
                // Un éditeur vide (« <p><br></p> ») n'envoie rien : la traduction est retirée.
                input.value = quill.getText().trim() === '' ? '' : quill.root.innerHTML;
            };
            quill.on('text-change', sync);
            sync();

            return sync;
        });

        document.querySelector('form').addEventListener('submit', function () {
            editors.forEach(function (sync) { sync(); });
        });
    });
</script>
