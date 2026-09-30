@php
    $variant = $variant ?? 'default';
    $icon    = $icon    ?? null;
    $dot     = $dot     ?? false;

    $dotColors = [
        'success' => 'var(--mad-success)', 'warning' => 'var(--mad-warning)',
        'error'   => 'var(--mad-error)',   'info'    => 'var(--mad-info)',
        'default' => 'var(--mad-text-subtle)', 'primary' => 'var(--mad-primary)',
    ];
    $dotColor = $dotColors[$variant] ?? $dotColors['default'];
@endphp

<span class="mad-badge mad-badge-{{ $variant }}" style="display:inline-flex;align-items:center;gap:5px;">
    @if($dot)
        <span style="width:6px;height:6px;border-radius:50%;background:{{ $dotColor }};flex-shrink:0;"></span>
    @elseif($icon)
        <i data-lucide="{{ $icon }}" style="width:11px;height:11px;"></i>
    @endif
    {!! $slot !!}
</span>
