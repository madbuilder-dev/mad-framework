{{-- Single-line labeled value pulled from a model attribute.
     Usage: mad-doc-variable-field(:value="$record->nome", format=text, prefix="Cliente: "). --}}
@props([
    'value' => null,
    'format' => 'text',
    'prefix' => '',
    'suffix' => '',
    'align' => 'left',
    'size' => 11,
    'bold' => false,
])

@php
    $alignSafe = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
    $sizePt = max(6, min(36, (int) $size));
    $weight = $bold ? 600 : 400;
    $formatted = \Mad\Doc\MadDocRuntime::applyFormat($value, (string) $format);
@endphp

<div style="text-align:{{ $alignSafe }};font-size:{{ $sizePt }}pt;font-weight:{{ $weight }};margin:0 0 2pt 0;">{{ $prefix }}{{ $formatted }}{{ $suffix }}</div>
