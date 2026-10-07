@php
    $class    = $class ?? '';
    // position="left|center|right" — lado do grupo dentro do <mad-form-actions>.
    $position = $position ?? '';
    $posClass = in_array($position, ['left', 'center', 'right'], true) ? " mad-pos-{$position}" : '';
@endphp
<div class="mad-btn-group{{ $posClass }} {{ $class }}">
    {!! $slot !!}
</div>
