@extends('admin.layouts.app')

@section('content')
<div class="p-6 space-y-6">
    <!-- En-tête -->
    <div class="flex justify-between items-center">
        <div>
            <h1 class="text-3xl font-bold text-gray-100">Arbre d'Affiliation</h1>
            <p class="text-gray-400 mt-1">Visualisez la structure du réseau de parrainage ({{ $settings->max_levels }} niveaux max)</p>
        </div>
    </div>

    <!-- Top Parrains -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        @foreach($topReferrers->take(4) as $referrer)
            <div class="bg-gradient-to-br from-primary-600 to-primary-700 rounded-lg p-6 text-white">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-12 h-12 rounded-full bg-white bg-opacity-20 flex items-center justify-center font-bold">
                        {{ strtoupper(substr($referrer->first_name, 0, 1)) }}{{ strtoupper(substr($referrer->last_name, 0, 1)) }}
                    </div>
                    <span class="bg-white bg-opacity-20 px-3 py-1 rounded-full text-xs font-bold">
                        Top {{ $loop->iteration }}
                    </span>
                </div>
                <h3 class="font-bold text-lg truncate">{{ $referrer->name }}</h3>
                <p class="text-primary-100 text-sm truncate">{{ $referrer->email }}</p>
                <div class="mt-4 pt-4 border-t border-white border-opacity-20">
                    <div class="flex items-center justify-between">
                        <span class="text-sm">Filleuls</span>
                        <span class="font-bold text-xl">{{ $referrer->referrals_count }}</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <!-- Arbre complet -->
    <div class="bg-dark-100 border border-dark-200 rounded-lg p-6">
        <h3 class="text-xl font-semibold text-gray-100 mb-6">
            <i class="fas fa-sitemap text-primary-500 mr-2"></i>
            Structure Complète du Réseau
        </h3>

        <div class="space-y-4">
            @forelse($topReferrers as $referrer)
                <div class="bg-dark-50 border border-dark-200 rounded-lg overflow-hidden"
                     x-data="{ open: false, loaded: false }"
                     @click.self="if (!open) { open = true; loadTree{{ $referrer->id }}(); }">
                    <!-- Niveau 0 (Parrain principal) -->
                    <div class="flex items-center justify-between p-4 cursor-pointer hover:bg-dark-100 transition-colors"
                         @click="open = !open; if (open && !loaded) { loadTree{{ $referrer->id }}(); }">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-primary-500 to-primary-600 flex items-center justify-center text-white font-bold">
                                {{ strtoupper(substr($referrer->first_name, 0, 1)) }}{{ strtoupper(substr($referrer->last_name, 0, 1)) }}
                            </div>
                            <div>
                                <h4 class="font-semibold text-gray-100">{{ $referrer->name }}</h4>
                                <p class="text-sm text-gray-400">{{ $referrer->email }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <span class="bg-blue-600 text-white px-3 py-1 rounded text-xs font-bold">
                                    {{ $referrer->referral_code }}
                                </span>
                                <span class="bg-green-600 text-white px-3 py-1 rounded text-xs font-bold">
                                    <i class="fas fa-users mr-1"></i>
                                    {{ $referrer->referrals_count }} filleul(s)
                                </span>
                            </div>
                            <i class="fas fa-chevron-down text-gray-400 transition-transform" :class="{ 'rotate-180': open }"></i>
                        </div>
                    </div>

                    <!-- Filleuls (tous niveaux) -->
                    <div x-show="open" x-collapse class="border-t border-dark-200">
                        <div class="p-4 bg-dark-100">
                            <div id="tree-{{ $referrer->id }}" class="space-y-3">
                                <div class="text-center text-gray-400 py-4">
                                    <i class="fas fa-spinner fa-spin mr-2"></i>
                                    Chargement de l'arbre...
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <div class="text-center py-12">
                    <i class="fas fa-sitemap text-6xl text-gray-600 mb-4"></i>
                    <p class="text-gray-400 text-lg">Aucun réseau d'affiliation trouvé</p>
                    <p class="text-gray-500 text-sm mt-2">Aucun utilisateur n'a encore parrainé de filleuls</p>
                </div>
            @endforelse
        </div>
    </div>
</div>

@push('scripts')
<script>
    function escapeAffiliateText(value) {
        const text = document.createElement('span');
        text.textContent = String(value ?? '');
        return text.innerHTML;
    }

    function renderTree(nodes, level) {
        if (!nodes || nodes.length === 0) {
            return '<p class="text-gray-400 text-sm text-center py-4">Aucun filleul pour le moment</p>';
        }

        const colors = ['blue', 'green', 'purple', 'orange'];
        const color = colors[Math.min(level - 1, colors.length - 1)];
        const marginClass = level === 1 ? '' : `ml-${level * 4}`;

        let html = '';
        nodes.forEach(node => {
            html += `
                <div class="${marginClass} border-l-4 border-${color}-500 pl-4 py-2">
                    <div class="bg-dark-50 rounded-lg p-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-gradient-to-br from-${color}-500 to-${color}-600 flex items-center justify-center text-white text-xs font-bold">
                                    ${escapeAffiliateText(node.name.split(' ').map(n => n[0]).join('').toUpperCase())}
                                </div>
                                <div>
                                    <h5 class="font-medium text-gray-200 text-sm">${escapeAffiliateText(node.name)}</h5>
                                    <p class="text-xs text-gray-400">${escapeAffiliateText(node.email)}</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="bg-${color}-600 text-white px-2 py-1 rounded text-xs">
                                    Niveau ${escapeAffiliateText(node.level)}
                                </span>
                                <span class="text-xs text-gray-400">
                                    <i class="fas fa-users mr-1"></i>${escapeAffiliateText(node.total_referrals)}
                                </span>
                                <span class="text-xs text-green-400 font-medium">
                                    ${escapeAffiliateText(node.total_earned)}
                                </span>
                            </div>
                        </div>
                        ${node.children && node.children.length > 0 ? renderTree(node.children, level + 1) : ''}
                    </div>
                </div>
            `;
        });
        return html;
    }

    @foreach($topReferrers as $referrer)
    function loadTree{{ $referrer->id }}() {
        const container = document.getElementById('tree-{{ $referrer->id }}');
        if (container.dataset.loaded === 'true') return;

        fetch('{{ route("admin.affiliate.tree-data", $referrer) }}')
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok');
                return res.json();
            })
            .then(data => {
                container.innerHTML = renderTree(data, 1);
                container.dataset.loaded = 'true';
            })
            .catch(error => {
                console.error('Error loading tree:', error);
                container.innerHTML = '<p class="text-red-400 text-sm text-center py-4">Erreur lors du chargement de l\'arbre</p>';
            });
    }
    @endforeach
</script>
@endpush
@endsection
