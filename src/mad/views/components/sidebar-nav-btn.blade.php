@php
    $name     = $name     ?? '';
    $icon     = $icon     ?? '';
    $title    = $title    ?? '';
    $class    = $class    ?? '';
    $attrs    = $attrs    ?? '';
    $hasPanel = !empty($name);
@endphp
<button
    type="button"
    class="mad-sidebar-nav-btn {{ $class }}"
    @if($hasPanel)
        :class="activePanel === '{{ $name }}' ? 'active' : ''"
        @click="activePanel = (activePanel === '{{ $name }}') ? '' : '{{ $name }}'"
    @endif
    @if($title) title="{{ $title }}" @endif
    {!! $attrs !!}
>
    @if($icon)
        <i data-lucide="{{ $icon }}" style="width:18px;height:18px;"></i>
    @endif
</button>
