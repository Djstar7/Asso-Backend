@extends('admin.layouts.app')

@section('title', $manager->exists ? 'Modifier le gestionnaire' : 'Nouveau gestionnaire')
@section('header', 'Gestionnaires')

@section('content')
@php
    $input = 'w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-primary-500';
    $selected = old('permissions', $manager->admin_permissions ?? []);
@endphp
<div class="p-6 space-y-6">
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-2xl font-bold text-white">{{ $manager->exists ? 'Modifier ' . $manager->name : 'Nouveau gestionnaire' }}</h1>
            <p class="text-gray-400 mt-1">
                @if($manager->exists)
                    Les changements de sections s'appliquent dès son prochain clic.
                @else
                    Un mot de passe est généré et envoyé à l'adresse saisie.
                @endif
            </p>
        </div>
        <a href="{{ route('admin.managers.index') }}"
           class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>Retour
        </a>
    </div>

    <form method="POST" action="{{ $manager->exists ? route('admin.managers.update', $manager) : route('admin.managers.store') }}" class="space-y-6">
        @csrf
        @if($manager->exists) @method('PUT') @endif

        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-white mb-4">Identité</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Prénom <span class="text-red-500">*</span></label>
                    <input type="text" name="first_name" value="{{ old('first_name', $manager->first_name) }}" required maxlength="255" class="{{ $input }}">
                    @error('first_name')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Nom <span class="text-red-500">*</span></label>
                    <input type="text" name="last_name" value="{{ old('last_name', $manager->last_name) }}" required maxlength="255" class="{{ $input }}">
                    @error('last_name')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-300 mb-2">Email <span class="text-red-500">*</span></label>
                    <input type="email" name="email" value="{{ old('email', $manager->email) }}" required maxlength="255" class="{{ $input }}">
                    <p class="text-xs text-gray-500 mt-1">Identifiant de connexion. Doit être différent de celui d'un compte de l'application.</p>
                    @error('email')<p class="text-red-500 text-sm mt-1">{{ $message }}</p>@enderror
                </div>
            </div>
        </div>

        <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-white mb-1">Accès</h2>
            <p class="text-sm text-gray-400 mb-4">Les cases grisées sont toujours accordées. Cochez les sections supplémentaires à ouvrir.</p>
            @error('permissions')<p class="text-red-500 text-sm mb-3">{{ $message }}</p>@enderror
            @error('permissions.*')<p class="text-red-500 text-sm mb-3">{{ $message }}</p>@enderror

            <div class="space-y-6">
                @foreach($permissionGroups as $group => $permissions)
                    <div>
                        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-3">{{ $group }}</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                            @foreach($permissions as $key => $label)
                                @if(in_array($key, $rolePermissions, true))
                                {{-- Incluse dans le rôle : case figée, rien n'est envoyé. --}}
                                <label class="flex items-center px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-300 opacity-60">
                                    <input type="checkbox" checked disabled class="rounded mr-3">
                                    <span class="text-sm">{{ $label }}</span>
                                </label>
                                @else
                                <label class="flex items-center px-3 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-300 cursor-pointer hover:border-primary-500">
                                    <input type="checkbox" name="permissions[]" value="{{ $key }}" class="rounded mr-3"
                                           @checked(in_array($key, $selected, true))>
                                    <span class="text-sm">{{ $label }}</span>
                                </label>
                                @endif
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <p class="text-xs text-gray-500 mt-6">
                <i class="fas fa-lock mr-1"></i>
                Toujours réservés à l'administrateur : gestionnaires, paramètres, paiements, services, maintenance, bypass OTP, base de données et coffre-fort.
            </p>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600">
                <i class="fas {{ $manager->exists ? 'fa-save' : 'fa-paper-plane' }} mr-2"></i>{{ $manager->exists ? 'Enregistrer' : 'Créer et envoyer les accès' }}
            </button>
        </div>
    </form>
</div>
@endsection
