@php
    $name  = $name  ?? '';
    $class = $class ?? '';
    $style = $style ?? '';
    $_isHidden = $name && \Mad\Component\MadRenderContext::isHidden($name, 'tab');
    \Mad\Component\MadRenderContext::tabPanelRendered((string) $name, (string) ($slot ?? ''));
@endphp
<div class="mad-tab-content {{ $class }}{{ $_isHidden ? ' mad-hidden' : '' }}"
     @if($name) data-mad-tab="{{ $name }}" @endif
     @if($style) style="{{ $style }}" @endif
     x-show="activeTab === '{{ $name }}'"
     x-cloak>
    {!! $slot !!}
</div>
