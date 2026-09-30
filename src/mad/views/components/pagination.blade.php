@php
    $page    = (int)($page    ?? 1);
    $total   = (int)($total   ?? 1);
    $window  = (int)($window  ?? 2);   // pages around current
    $class   = $class ?? '';

    // Build page list with ellipsis
    $pages = [];
    $start = max(1, $page - $window);
    $end   = min($total, $page + $window);

    if ($start > 1) {
        $pages[] = ['type' => 'page', 'n' => 1];
        if ($start > 2) $pages[] = ['type' => 'ellipsis'];
    }
    for ($i = $start; $i <= $end; $i++) {
        $pages[] = ['type' => 'page', 'n' => $i];
    }
    if ($end < $total) {
        if ($end < $total - 1) $pages[] = ['type' => 'ellipsis'];
        $pages[] = ['type' => 'page', 'n' => $total];
    }
@endphp
<nav class="mad-pagination {{ $class }}" aria-label="Paginação">
    {{-- Prev --}}
    @if($slot ?? '')
        {{-- Custom content --}}
        {!! $slot !!}
    @else
        <button type="button"
                class="mad-page-btn"
                @if($page <= 1) disabled @endif
                onclick="madPaginate({{ $page - 1 }})">
            <i data-lucide="chevron-left" style="width:14px;height:14px;"></i>
        </button>

        @foreach($pages as $p)
            @if($p['type'] === 'ellipsis')
                <span class="mad-page-ellipsis">…</span>
            @else
                <button type="button"
                        class="mad-page-btn {{ $p['n'] === $page ? 'mad-page-btn-active' : '' }}"
                        onclick="madPaginate({{ $p['n'] }})">
                    {{ $p['n'] }}
                </button>
            @endif
        @endforeach

        <button type="button"
                class="mad-page-btn"
                @if($page >= $total) disabled @endif
                onclick="madPaginate({{ $page + 1 }})">
            <i data-lucide="chevron-right" style="width:14px;height:14px;"></i>
        </button>
    @endif
</nav>
