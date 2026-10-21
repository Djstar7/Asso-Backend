@extends('admin.layouts.app')

@section('skip-charts', 'true')

@section('content')
<div class="w-full min-w-0">
    <div class="mb-5 flex items-start gap-3">
        <a href="{{ route('admin.delivery-partners.index') }}" class="text-gray-400 hover:text-primary-500 pt-1" aria-label="Retour aux partenaires"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $partner->name }}</h1>
            <p class="text-sm text-gray-400 mt-1">Gérez la couverture, les trajets et les agences dans des rubriques séparées.</p>
        </div>
    </div>
    <nav class="flex flex-wrap gap-2 mb-5" aria-label="Configuration du partenaire">
        @foreach($sections as $key => $label)
            <a href="{{ route('admin.delivery-partners.edit', [$partner, 'section' => $key]) }}"
               @if($section === $key) aria-current="page" @endif
               class="px-4 py-2 rounded-lg text-sm font-medium border {{ $section === $key ? 'bg-primary-500 border-primary-500 text-white' : 'bg-dark-100 border-dark-300 text-gray-300 hover:border-primary-400' }}">{{ $label }}</a>
        @endforeach
    </nav>
    @if($errors->any())
        <div class="mb-5 p-4 bg-red-900/20 border-l-4 border-red-500 rounded">
            <ul class="text-red-300 text-sm list-disc list-inside">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif
    @switch($section)
        @case('network')
            @include('admin.delivery_partners._network')
            @break
        @case('routes')
            @include('admin.delivery_partners._routes')
            @break
        @case('agencies')
            @include('admin.delivery_partners._agencies')
            @break
        @case('cities')
            @include('admin.delivery_partners._legacy_grids')
            @break
        @default
            @include('admin.delivery_partners._settings')
    @endswitch
</div>
@endsection

@push('scripts')
<script>
    // The form is ready after deferred scripts; remote images need not hold the overlay.
    document.addEventListener('DOMContentLoaded', () => window.pageLoader?.hide(), { once: true });
</script>
@endpush
