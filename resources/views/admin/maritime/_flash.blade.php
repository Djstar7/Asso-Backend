{{-- Succès et erreurs de session : affichés par le layout. --}}
@if($errors->any())
    <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
        <ul class="text-red-300 text-sm list-disc list-inside">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
