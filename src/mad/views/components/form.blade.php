@php
    $submit = $submit ?? '';
    $class  = $class  ?? '';
    $id     = $id     ?? '';
    $attrs  = $attrs  ?? '';
    // label-gap e estilo do label (label-color/-size/-weight/-italic) do FORM
    // inteiro: viram custom properties no <form> e descem por herança para todo
    // .mad-field dentro dele. Campo com o seu próprio continua ganhando — o
    // wrapper dele é o ancestral mais próximo.
    $_labelGap  = \Mad\Support\CssUnits::length((string) ($labelGap ?? ''));
    $_formStyle = ($_labelGap !== '' ? '--mad-label-gap:' . $_labelGap . ';' : '')
        . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
    $_gapStyle  = $_formStyle !== '' ? ' style="' . $_formStyle . '"' : '';
    // token() é chamado APÓS o slot renderizar → todos os fields já foram registrados
    $token  = \Mad\Form\MadFormRegistry::token();
@endphp
<form novalidate enctype="multipart/form-data"
    @if($id)     id="{{ $id }}"                       @endif
    @if($class)  class="{{ $class }}"                 @endif
    @if($submit) data-mad-submit="{{ $submit }}"      @endif
    {{-- $_gapStyle DEPOIS de $attrs: atributo duplicado → o HTML fica com o primeiro, então um style cru em attrs continua mandando --}}
    {!! $attrs !!}{!! $_gapStyle !!}
>
    {!! $slot !!}
    @if($token)
        <input type="hidden" name="__mad_form" value="{{ $token }}">
    @endif
</form>
