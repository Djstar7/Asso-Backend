@extends('admin.layouts.app')

@section('content')
@php $input = 'w-full px-4 py-2 bg-dark-50 border border-dark-300 text-white rounded-lg focus:ring-2 focus:ring-primary-500'; @endphp
<div class="p-6">
    <div class="mb-6">
        <div class="flex items-center gap-3 mb-2">
            <a href="{{ route('admin.deliverers.show', $deliverer) }}" class="text-gray-400 hover:text-primary-600 transition-colors">
                <i class="fas fa-arrow-left"></i>
            </a>
            <h1 class="text-2xl font-bold text-white">Modifier {{ $deliverer->name }}</h1>
        </div>
        <p class="text-gray-400 ml-10">Identité de l'entreprise. Les zones, quartiers, tarifs et trajets se configurent dans une seule section.</p>
    </div>

    <div class="mb-6 p-4 bg-blue-900/20 border border-blue-500/40 rounded-lg flex flex-wrap items-center justify-between gap-3">
        <div class="text-blue-200 text-sm">
            <i class="fas fa-info-circle mr-1"></i>
            Zones de couverture, quartiers sur la carte, véhicules, prix et trajets : <strong>Partenaires logistiques</strong>.
        </div>
        <a href="{{ route('admin.delivery-partners.edit', $deliverer) }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 text-sm">
            <i class="fas fa-tags mr-1"></i> Configurer zones et tarifs
        </a>
    </div>

    <form action="{{ route('admin.deliverers.update', $deliverer) }}" method="POST" enctype="multipart/form-data">
        @csrf
        @method('PUT')

        <div class="bg-dark-100 rounded-xl shadow-lg p-6 mb-6 border border-dark-200">
            <h3 class="text-lg font-semibold text-white mb-4 flex items-center">
                <i class="fas fa-building text-primary-500 mr-2"></i> Informations de l'entreprise
            </h3>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Nom de l'entreprise <span class="text-red-500">*</span></label>
                    <input type="text" name="company_name" value="{{ old('company_name', $deliverer->name) }}" required class="{{ $input }}">
                    @error('company_name')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Téléphone</label>
                    <input type="text" name="company_phone" value="{{ old('company_phone', $deliverer->phone) }}" placeholder="+237 6XX XX XX XX" class="{{ $input }}">
                    @error('company_phone')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Email</label>
                    <input type="email" name="company_email" value="{{ old('company_email', $deliverer->email) }}" placeholder="contact@entreprise.cm" class="{{ $input }}">
                    <p class="mt-1 text-xs text-gray-400">Les codes de synchronisation des coursiers y sont envoyés.</p>
                    @error('company_email')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-white mb-2">Logo</label>
                    @if($deliverer->logo)
                        <img src="{{ media_url($deliverer->logo) }}" alt="Logo" class="w-20 h-20 object-cover rounded-lg border border-dark-300 mb-2">
                    @endif
                    <input type="file" name="company_logo" accept="image/*" class="{{ $input }}">
                    @error('company_logo')<p class="mt-1 text-sm text-red-400">{{ $message }}</p>@enderror
                </div>
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-white mb-2">Description</label>
                    <textarea name="company_description" rows="3" class="{{ $input }}">{{ old('company_description', $deliverer->description) }}</textarea>
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-300">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $deliverer->is_active)) class="w-4 h-4 rounded bg-dark-50">
                    Partenaire actif (proposé aux acheteurs)
                </label>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit" class="px-6 py-3 bg-gradient-to-r from-primary-500 to-primary-600 text-white rounded-lg hover:shadow-lg">
                <i class="fas fa-save mr-2"></i> Enregistrer
            </button>
            <a href="{{ route('admin.deliverers.show', $deliverer) }}" class="px-6 py-3 bg-dark-50 border border-dark-300 text-gray-300 rounded-lg">Annuler</a>
        </div>
    </form>
</div>
@endsection
