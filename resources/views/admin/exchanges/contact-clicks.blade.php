@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Statistiques de Contacts Externes</h1>
            <p class="text-gray-400 mt-1">Suivi des clics WhatsApp et appels</p>
        </div>
        <a href="{{ route('admin.exchanges.index') }}"
            class="px-4 py-2 bg-dark-100 border border-dark-200 hover:bg-dark-50 text-gray-100 rounded-lg transition-colors">
            <i class="fas fa-arrow-left mr-2"></i>
            Retour
        </a>
    </div>

    <!-- Filtres -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-4">
        <form method="GET" action="{{ route('admin.exchanges.contact-clicks') }}" class="flex flex-wrap gap-4 items-end">
            <div class="flex-1 min-w-[200px]">
                <label for="start_date" class="block text-sm font-medium text-gray-300 mb-2">Date de début</label>
                <input type="date" id="start_date" name="start_date"
                    value="{{ request('start_date') }}"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label for="end_date" class="block text-sm font-medium text-gray-300 mb-2">Date de fin</label>
                <input type="date" id="end_date" name="end_date"
                    value="{{ request('end_date') }}"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
            </div>
            <div class="flex-1 min-w-[200px]">
                <label for="contact_type" class="block text-sm font-medium text-gray-300 mb-2">Type de contact</label>
                <select id="contact_type" name="contact_type"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
                    <option value="">Tous</option>
                    <option value="whatsapp" {{ request('contact_type') === 'whatsapp' ? 'selected' : '' }}>WhatsApp</option>
                    <option value="call" {{ request('contact_type') === 'call' ? 'selected' : '' }}>Appels</option>
                </select>
            </div>
            <div class="flex-1 min-w-[200px]">
                <label for="seller_id" class="block text-sm font-medium text-gray-300 mb-2">Vendeur</label>
                <select id="seller_id" name="seller_id"
                    class="w-full px-4 py-2 bg-dark-50 border border-dark-200 rounded-lg text-gray-100 focus:ring-2 focus:ring-blue-500">
                    <option value="">Tous</option>
                    @foreach($sellers as $seller)
                        <option value="{{ $seller->id }}" {{ request('seller_id') == $seller->id ? 'selected' : '' }}>
                            {{ $seller->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="px-6 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-lg transition-colors">
                Filtrer
            </button>
            @if(request()->hasAny(['start_date', 'end_date', 'contact_type', 'seller_id']))
                <a href="{{ route('admin.exchanges.contact-clicks') }}"
                    class="px-6 py-2 bg-dark-50 border border-dark-200 hover:bg-dark-100 text-gray-100 rounded-lg transition-colors">
                    Réinitialiser
                </a>
            @endif
        </form>
    </div>

    <!-- Statistiques rapides -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-blue-100 text-sm font-medium">Total Clics</p>
                    <p class="text-3xl font-bold mt-2">{{ number_format($clicks->total()) }}</p>
                </div>
                <div class="bg-blue-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-mouse-pointer text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-green-600 to-green-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-green-100 text-sm font-medium">WhatsApp</p>
                    <p class="text-3xl font-bold mt-2">
                        {{ number_format($clicks->where('contact_type', 'whatsapp')->count()) }}
                    </p>
                </div>
                <div class="bg-green-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fab fa-whatsapp text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-indigo-600 to-indigo-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-indigo-100 text-sm font-medium">Appels</p>
                    <p class="text-3xl font-bold mt-2">
                        {{ number_format($clicks->where('contact_type', 'call')->count()) }}
                    </p>
                </div>
                <div class="bg-indigo-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-phone text-2xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg p-6 text-white">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-purple-100 text-sm font-medium">Vendeurs Actifs</p>
                    <p class="text-3xl font-bold mt-2">
                        {{ number_format($clicks->unique('seller_id')->count()) }}
                    </p>
                </div>
                <div class="bg-purple-500 bg-opacity-30 p-3 rounded-lg">
                    <i class="fas fa-users text-2xl"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Liste des clics -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-dark-50 border-b border-dark-200">
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">ID</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Type</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Utilisateur</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Vendeur Contacté</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Produit</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">IP</th>
                        <th class="text-left py-3 px-4 text-gray-300 font-medium">Date</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($clicks as $click)
                    <tr class="border-b border-dark-200 hover:bg-dark-50 transition-colors">
                        <td class="py-3 px-4 text-gray-300 font-mono text-sm">#{{ $click->id }}</td>
                        <td class="py-3 px-4">
                            @if($click->contact_type === 'whatsapp')
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-green-600 text-white">
                                    <i class="fab fa-whatsapp mr-2"></i>
                                    WhatsApp
                                </span>
                            @else
                                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-indigo-600 text-white">
                                    <i class="fas fa-phone mr-2"></i>
                                    Appel
                                </span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                @if($click->user->avatar)
                                    <img src="{{ media_url($click->user->avatar) }}" alt="{{ $click->user->name }}"
                                        class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div class="w-8 h-8 bg-blue-600 rounded-full flex items-center justify-center">
                                        <span class="text-white text-xs">{{ substr($click->user->name, 0, 1) }}</span>
                                    </div>
                                @endif
                                <div>
                                    <div class="text-gray-100 text-sm font-medium">{{ $click->user->name }}</div>
                                    <div class="text-gray-400 text-xs">{{ $click->user->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                @if($click->seller->avatar)
                                    <img src="{{ media_url($click->seller->avatar) }}" alt="{{ $click->seller->name }}"
                                        class="w-8 h-8 rounded-full object-cover">
                                @else
                                    <div class="w-8 h-8 bg-purple-600 rounded-full flex items-center justify-center">
                                        <span class="text-white text-xs">{{ substr($click->seller->name, 0, 1) }}</span>
                                    </div>
                                @endif
                                <div>
                                    <div class="text-gray-100 text-sm font-medium">{{ $click->seller->name }}</div>
                                    <div class="text-gray-400 text-xs">{{ $click->seller->email }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            @if($click->product)
                                <span class="text-gray-300 text-sm">{{ Str::limit($click->product->name, 30) }}</span>
                            @else
                                <span class="text-gray-500 italic text-sm">Produit supprimé</span>
                            @endif
                        </td>
                        <td class="py-3 px-4">
                            <span class="text-gray-400 font-mono text-xs">{{ $click->ip_address ?? 'N/A' }}</span>
                        </td>
                        <td class="py-3 px-4">
                            <div class="text-sm">
                                <div class="text-gray-300">{{ $click->created_at->format('d/m/Y') }}</div>
                                <div class="text-gray-500">{{ $click->created_at->format('H:i:s') }}</div>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="7" class="py-12 text-center text-gray-400">
                            <i class="fas fa-inbox text-5xl mb-3"></i>
                            <p class="text-lg">Aucun clic enregistré</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Pagination -->
    @if($clicks->hasPages())
        <div class="flex justify-center">
            {{ $clicks->links() }}
        </div>
    @endif
</div>
@endsection
