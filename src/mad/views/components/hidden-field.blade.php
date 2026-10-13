@props(['name' => '', 'value' => '', 'label' => '', 'attrs' => '', 'error' => ''])
@php
    // Campo oculto (fw#261): nada na tela, vai no Salvar como os outros. Valor
    // inicial e mad:model pela mesma regra dos demais campos (registro aberto >
    // `value` da tag) — \Mad\Support\MadFieldValue.
    $attrs = is_scalar($attrs) || $attrs instanceof \Stringable ? (string) $attrs : '';
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string) $name, $value ?? '');
    \Mad\Form\MadFormRegistry::register((string) $name, 'input', [
        'label' => strip_tags((string) $label),
        'type'  => 'hidden',
    ]);
@endphp
{{-- O lugar do erro existe sempre, como nos outros campos: a recusa do
     Salvar para este campo (`fieldError()`, validação do Model) precisa de onde
     aparecer. Vazio não ocupa espaço (`[data-field-error]:empty`). --}}
<input type="hidden"@if($name) name="{{ $name }}"@endif {!! $attrs !!}>@if($name)<p class="mad-field-hint{{ $error ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $error ?: '' !!}</p>@endif
