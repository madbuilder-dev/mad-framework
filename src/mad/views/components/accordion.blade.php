@php $class = $class ?? ''; $style = $style ?? ''; @endphp
<div class="mad-accordion {{ $class }}" @if($style) style="{{ $style }}" @endif>
    {!! $slot !!}
</div>
