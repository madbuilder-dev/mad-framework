@php
    $name  = $name  ?? '';
    $class = $class ?? '';
    $style = $style ?? '';
    $_isHidden = $name && \Mad\Component\MadRenderContext::isHidden($name, 'tab');
@endphp
<div class="mad-tab-content {{ $class }}{{ $_isHidden ? ' mad-hidden' : '' }}"
     @if($name) data-mad-tab="{{ $name }}" @endif
     @if($style) style="{{ $style }}" @endif
     x-show="activeTab === '{{ $name }}'"
     x-cloak>
    {!! $slot !!}
</div>
