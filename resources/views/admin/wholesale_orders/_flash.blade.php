@if(session('success'))
    <div class="mb-6 p-4 bg-green-900/20 border-l-4 border-green-500 rounded">
        <p class="text-green-300"><i class="fas fa-check-circle mr-2"></i>{{ session('success') }}</p>
    </div>
@endif
@if(session('error'))
    <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
        <p class="text-red-300"><i class="fas fa-exclamation-circle mr-2"></i>{{ session('error') }}</p>
    </div>
@endif
@if($errors->any())
    <div class="mb-6 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
        <ul class="text-red-300 text-sm list-disc list-inside">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif
