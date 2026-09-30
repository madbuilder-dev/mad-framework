@php
    $toolbar = $toolbar ?? null;  // HTML string com botões de ação
    $class   = $class   ?? '';
    $style   = $style   ?? '';
@endphp
<div class="mad-page-content {{ $class }}" @if($style) style="{{ $style }}" @endif>
    @if($toolbar)
        <div class="mad-page-toolbar">
            {!! $toolbar !!}
        </div>
    @endif
    <div class="mad-page-body">
        {!! $slot !!}
    </div>
</div>
