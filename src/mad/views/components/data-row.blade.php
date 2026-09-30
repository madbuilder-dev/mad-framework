@php
    $label = $label ?? '';
    $icon  = $icon  ?? null;
    $class = $class ?? '';
@endphp

<div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--mad-border);" class="{{ $class }}">
    <div style="width:160px;flex-shrink:0;display:flex;align-items:center;gap:6px;">
        @if($icon)
            <i data-lucide="{{ $icon }}" style="width:13px;height:13px;color:var(--mad-text-subtle);flex-shrink:0;"></i>
        @endif
        <span style="font-size:var(--mad-text-xs);color:var(--mad-text-muted);font-weight:500;">{{ $label }}</span>
    </div>
    <div style="flex:1;font-size:var(--mad-text-sm);color:var(--mad-text);">
        {!! $slot !!}
    </div>
</div>
