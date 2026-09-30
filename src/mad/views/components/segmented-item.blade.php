{{-- <mad-segmented-item> — Item de um <mad-segmented>. --}}
{{-- Aceita: active, disabled, icon, attrs, class. Suporta mad:click direto via $attrs. --}}
@php
    $active   = !empty($active);
    $disabled = !empty($disabled);
    $icon     = $icon  ?? '';
    $class    = $class ?? '';
    $attrs    = $attrs ?? '';

    $cls = 'mad-seg-item';
    if ($active)   $cls .= ' active';
    if ($class)    $cls .= ' ' . $class;
@endphp

<button
    type="button"
    class="{{ $cls }}"
    role="tab"
    aria-selected="{{ $active ? 'true' : 'false' }}"
    @if($disabled) disabled @endif
    {!! $attrs !!}>
    @if($icon)
        <i data-lucide="{{ $icon }}" class="mad-seg-icon" style="width:14px;height:14px;"></i>
    @endif
    {!! $slot !!}
</button>
