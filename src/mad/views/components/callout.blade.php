@php
    $type    = $type    ?? 'info';   // info | success | warning | danger
    $title   = $title   ?? '';
    $icon    = $icon    ?? '';
    $calloutFooter = $calloutFooter ?? '';  // HTML for action buttons (não usar $actions — colide com MadRenderContext)
    $class   = $class   ?? '';
    $style   = $style   ?? '';

    $defaultIcons = [
        'info'    => 'info',
        'success' => 'check-circle',
        'warning' => 'alert-triangle',
        'danger'  => 'x-circle',
    ];
    $icon = $icon ?: ($defaultIcons[$type] ?? 'info');

    $typeColors = [
        'info'    => 'var(--mad-info)',
        'success' => 'var(--mad-success)',
        'warning' => 'var(--mad-warning)',
        'danger'  => 'var(--mad-danger)',
    ];
    $color = $typeColors[$type] ?? 'var(--mad-info)';
@endphp
<div class="mad-callout mad-callout-{{ $type }} {{ $class }}" @if($style) style="{{ $style }}" @endif>
    <div class="mad-callout-header">
        <i data-lucide="{{ $icon }}" style="width:16px;height:16px;color:{{ $color }};flex-shrink:0;"></i>
        @if($title)<span>{{ $title }}</span>@endif
    </div>
    @if(trim($slot ?? ''))
        <div class="mad-callout-body">{!! $slot !!}</div>
    @endif
    @if($calloutFooter)
        <div class="mad-callout-footer">{!! $calloutFooter !!}</div>
    @endif
</div>
