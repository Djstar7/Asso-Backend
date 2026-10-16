@php
    $styles = [
        'draft' => ['Brouillon', 'bg-gray-500/20 text-gray-300 border-gray-500/50'],
        'pending_review' => ['À relire', 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'],
        'published' => ['Publiée', 'bg-green-500/20 text-green-300 border-green-500/50'],
        'rejected' => ['Refusée', 'bg-red-500/20 text-red-300 border-red-500/50'],
        'archived' => ['Archivée', 'bg-gray-700/40 text-gray-400 border-gray-600/50'],
        'pending' => ['En attente', 'bg-yellow-500/20 text-yellow-300 border-yellow-500/50'],
        'verified' => ['Vérifié', 'bg-green-500/20 text-green-300 border-green-500/50'],
        'none' => ['Non envoyé', 'bg-gray-500/20 text-gray-300 border-gray-500/50'],
    ];
    [$label, $class] = $styles[$status] ?? [$status, 'bg-gray-500/20 text-gray-300 border-gray-500/50'];
@endphp
<span class="px-2.5 py-1 text-xs font-semibold rounded-full border {{ $class }}">{{ $label }}</span>
