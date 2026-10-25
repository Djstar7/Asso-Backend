@php
    $paginationUser = auth()->user();
    $paginationLocale = $paginationUser && method_exists($paginationUser, 'preferredLocale')
        ? $paginationUser->preferredLocale() : app()->getLocale();
    $paginationText = fn ($key, $replace = []) => __('admin_pagination.'.$key, $replace, $paginationLocale);
    // Preserve filters, including nested arrays and other paginators' page parameters.
    $paginator->appends(request()->query());
    $pagePosition = $paginationText($lengthAware ? 'page' : 'simple_page', [
        'current' => $paginator->currentPage(),
        'last' => $lengthAware ? $paginator->lastPage() : '',
    ]);
    $pageNumbers = [];
    if ($lengthAware && $paginator->hasPages()) {
        $lastPage = $paginator->lastPage();
        $currentPage = $paginator->currentPage();
        $candidates = array_unique([1, max(1, $currentPage - 1), $currentPage, min($lastPage, $currentPage + 1), $lastPage]);
        sort($candidates);
        $previousNumber = 0;
        foreach ($candidates as $number) {
            if ($number > $lastPage) continue;
            if ($previousNumber && $number - $previousNumber === 2) $pageNumbers[] = $number - 1;
            elseif ($previousNumber && $number - $previousNumber > 2) $pageNumbers[] = null;
            $pageNumbers[] = $number;
            $previousNumber = $number;
        }
    }
@endphp
@once
    <style>
        .asso-pagination{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:16px;padding:20px 0 4px;border-top:1px solid #27272a;color:#a1a1aa;font-size:13px;line-height:1.5;font-variant-numeric:tabular-nums}
        .asso-pagination__summary{margin:0;color:#a1a1aa}
        .asso-pagination__controls,.asso-pagination__pages{display:flex;align-items:center;gap:5px}
        .asso-pagination__control{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-width:36px;min-height:38px;padding:7px 10px;border:1px solid transparent;border-radius:8px;font:inherit;font-weight:500;line-height:1.5;color:#d4d4d8;text-decoration:none;white-space:nowrap}
        a.asso-pagination__control:hover{background:#27272a;border-color:#3f3f46;color:#fff}
        a.asso-pagination__control:focus-visible{outline:2px solid #ff9266;outline-offset:3px}
        .asso-pagination__control[aria-current="page"]{background:#ff7344;border-color:#ff7344;color:#18181b;font-weight:700}
        .asso-pagination__control[aria-disabled="true"]{color:#71717a;cursor:default}
        .asso-pagination__chevron{width:15px;height:15px;flex:none;stroke:currentColor;fill:none;stroke-width:1.8}
        .asso-pagination__ellipsis{padding:0 4px;color:#a1a1aa}
        .asso-pagination__position{display:none;text-align:center;color:#d4d4d8;white-space:nowrap}
        .asso-pagination--simple .asso-pagination__position{display:block}
        @media(max-width:767px){
            .asso-pagination{display:block;padding-top:16px}
            .asso-pagination__summary{margin-bottom:12px;font-size:12px}
            .asso-pagination__controls{justify-content:space-between;gap:6px;width:100%}
            .asso-pagination__pages{display:none}
            .asso-pagination__position{display:block;font-size:12px}
            .asso-pagination__control{min-height:40px;padding:7px 8px;font-size:12px;gap:4px}
        }
        @media(prefers-reduced-motion:no-preference){a.asso-pagination__control{transition:background-color .15s,color .15s,border-color .15s}}
    </style>
@endonce
<nav class="asso-pagination{{ $lengthAware ? '' : ' asso-pagination--simple' }}" aria-label="{{ $paginationText('label') }}" lang="{{ $paginationLocale }}">
    <p class="asso-pagination__summary">
        @if ($paginator->count() === 0)
            {{ $paginationText('empty') }}
        @elseif ($lengthAware)
            {{ $paginationText('summary', ['first' => $paginator->firstItem(), 'last' => $paginator->lastItem(), 'total' => $paginator->total()]) }}
        @else
            {{ $pagePosition }}
        @endif
    </p>
    @if ($paginator->hasPages())
        <div class="asso-pagination__controls">
            @if ($paginator->onFirstPage())
                <span class="asso-pagination__control" aria-disabled="true">
            @else
                <a class="asso-pagination__control" href="{{ $paginator->previousPageUrl() }}" rel="prev">
            @endif
                <svg class="asso-pagination__chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m12 5-5 5 5 5"/></svg>
                {{ $paginationText('previous') }}
            @if ($paginator->onFirstPage()) </span> @else </a> @endif

            @if ($lengthAware)
                <div class="asso-pagination__pages">
                    @foreach ($pageNumbers as $number)
                        @if ($number === null)
                            <span class="asso-pagination__ellipsis" aria-hidden="true">…</span>
                        @elseif ($number === $paginator->currentPage())
                            <span class="asso-pagination__control" aria-current="page" aria-label="{{ $paginationText('current', ['page' => $number]) }}">{{ $number }}</span>
                        @else
                            <a class="asso-pagination__control" href="{{ $paginator->url($number) }}" aria-label="{{ $paginationText('go_to', ['page' => $number]) }}">{{ $number }}</a>
                        @endif
                    @endforeach
                </div>
            @endif
            <span class="asso-pagination__position">{{ $pagePosition }}</span>

            @if ($paginator->hasMorePages())
                <a class="asso-pagination__control" href="{{ $paginator->nextPageUrl() }}" rel="next">
            @else
                <span class="asso-pagination__control" aria-disabled="true">
            @endif
                {{ $paginationText('next') }}
                <svg class="asso-pagination__chevron" viewBox="0 0 20 20" aria-hidden="true"><path d="m8 5 5 5-5 5"/></svg>
            @if ($paginator->hasMorePages()) </a> @else </span> @endif
        </div>
    @endif
</nav>
