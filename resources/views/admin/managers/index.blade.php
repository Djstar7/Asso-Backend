@extends('admin.layouts.app')

@section('title', 'Gestionnaires')
@section('header', 'Gestionnaires')

@section('content')
<div class="p-6">
    <div class="flex flex-col md:flex-row md:justify-between md:items-center gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Gestionnaires</h1>
            <p class="text-gray-400 mt-1">Comptes d'accès au tableau de bord limités à certaines sections</p>
        </div>
        <a href="{{ route('admin.managers.create') }}" class="px-4 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600 transition-all">
            <i class="fas fa-plus mr-2"></i>Nouveau gestionnaire
        </a>
    </div>

    <div class="bg-dark-100 rounded-xl shadow-lg p-4 mb-6">
        <form method="GET" action="{{ route('admin.managers.index') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Nom ou email…"
                   class="md:col-span-3 px-4 py-2 bg-dark-50 text-white border border-dark-200 rounded-lg focus:outline-none focus:border-primary-500">
            <button type="submit" class="px-6 py-2 bg-primary-500 text-white rounded-lg hover:bg-primary-600"><i class="fas fa-search mr-2"></i>Filtrer</button>
        </form>
    </div>

    @if($managers->isEmpty())
        <div class="bg-dark-100 rounded-xl shadow-lg p-12 text-center">
            <i class="fas fa-user-shield text-6xl text-gray-600 mb-4"></i>
            <h3 class="text-xl font-semibold text-white mb-2">Aucun gestionnaire</h3>
            <p class="text-gray-400">Créez un gestionnaire : il recevra ses identifiants par e-mail.</p>
        </div>
    @else
        <div class="bg-dark-100 rounded-xl shadow-lg overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-400 border-b border-dark-200">
                        <th class="px-4 py-3">Gestionnaire</th>
                        <th class="px-4 py-3">Rôle</th>
                        <th class="px-4 py-3">Sections supplémentaires</th>
                        <th class="px-4 py-3">Créé le</th>
                        <th class="px-4 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($managers as $manager)
                        <tr class="border-b border-dark-200 hover:bg-dark-50/50">
                            <td class="px-4 py-3">
                                <div class="text-white font-medium">{{ $manager->name }}</div>
                                <div class="text-xs text-gray-500">{{ $manager->email }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="px-3 py-1 bg-primary-500/20 text-primary-400 text-xs font-semibold rounded-full">{{ $manager->backofficeRoleLabel() }}</span>
                            </td>
                            <td class="px-4 py-3 text-gray-300">
                                @forelse($manager->admin_permissions ?? [] as $permission)
                                    <span class="inline-block px-2 py-0.5 mb-1 bg-dark-50 border border-dark-200 text-xs rounded">{{ $permissionLabels[$permission] ?? $permission }}</span>
                                @empty
                                    <span class="text-gray-500">—</span>
                                @endforelse
                            </td>
                            <td class="px-4 py-3 text-gray-400">{{ $manager->created_at?->format('d/m/Y') }}</td>
                            <td class="px-4 py-3">
                                <div class="flex justify-end gap-2">
                                    <a href="{{ route('admin.managers.edit', $manager) }}" class="px-3 py-1.5 bg-gradient-to-r from-primary-500 to-primary-600 text-white text-sm rounded-lg hover:shadow-lg" title="Modifier les accès">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <form action="{{ route('admin.managers.resend-credentials', $manager) }}" method="POST"
                                          data-confirm="Générer un nouveau mot de passe et l'envoyer à {{ $manager->email }} ? L'ancien ne fonctionnera plus.">
                                        @csrf
                                        <button type="submit" class="px-3 py-1.5 bg-blue-600 text-white text-sm rounded-lg hover:bg-blue-700" title="Renvoyer les accès">
                                            <i class="fas fa-paper-plane"></i>
                                        </button>
                                    </form>
                                    <form action="{{ route('admin.managers.destroy', $manager) }}" method="POST"
                                          data-confirm="Supprimer le compte de {{ $manager->name }} ? Il ne pourra plus se connecter.">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="px-3 py-1.5 bg-red-600 text-white text-sm rounded-lg hover:bg-red-700" title="Supprimer">
                                            <i class="fas fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="mt-4">{{ $managers->links() }}</div>
    @endif
</div>
@endsection
