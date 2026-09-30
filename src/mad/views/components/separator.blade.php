@php
    $label    = $label    ?? '';
    $vertical = !empty($vertical);
    $class    = $class    ?? '';
@endphp
@if($label)
<div style="display:flex;align-items:center;gap:8px;" class="{{ $class }}">
    <span style="flex:1;height:1px;background:var(--mad-border);display:block;"></span>
    <span style="font-size:11px;color:var(--mad-text-subtle);white-space:nowrap;">{{ $label }}</span>
    <span style="flex:1;height:1px;background:var(--mad-border);display:block;"></span>
</div>
@elseif($vertical)
<span class="mad-sep mad-sep-v {{ $class }}"></span>
@else
<hr class="mad-sep mad-sep-h {{ $class }}">
@endif
