@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'inputBorderColor' => '', 'inputBorderWidth' => '', 'inputBorderSides' => '', 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'options' => [], 'selected' => '', 'placeholder' => mad_t('mad.ui.select_placeholder'), 'min-length' => 0, 'hint' => '', 'hintIcon' => false, 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'noResultsCreateAction' => '', 'noResultsCreateLabel' => mad_t('mad.ui.create_new'), 'noResultsCreateIcon' => 'plus', 'noResultsCreateClass' => 'mad-btn mad-btn-primary mad-btn-sm', 'noResultsQuickRegisterAction' => '', 'noResultsQuickRegisterLabel' => mad_t('mad.ui.add'), 'noResultsQuickRegisterIcon' => 'check', 'noResultsQuickRegisterClass' => 'mad-btn mad-btn-success mad-btn-sm', 'noResultsQuickFields' => [], 'noResultsMessage' => ''])
@php // hint-icon: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
@endphp
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $minLength = (int)($minLength ?? 0);
    $selected  = (string)$selected;
    \Mad\Form\MadFormRegistry::register($name, 'unique-search', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);

    $noResultsAttrs = \Mad\Form\MadNoResultsHelper::buildAttrs([
        'name'         => $name,
        'createAction' => $noResultsCreateAction,
        'createLabel'  => $noResultsCreateLabel,
        'createIcon'   => $noResultsCreateIcon,
        'createClass'  => $noResultsCreateClass,
        'quickAction'  => $noResultsQuickRegisterAction,
        'quickLabel'   => $noResultsQuickRegisterLabel,
        'quickIcon'    => $noResultsQuickRegisterIcon,
        'quickClass'   => $noResultsQuickRegisterClass,
        'quickFields'  => $noResultsQuickFields,
        'message'      => $noResultsMessage,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false, $inputBorderColor ?? '', $inputBorderWidth ?? '', $inputBorderSides ?? ''); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
        data-mad-uniquesearch
        data-min-length="{{ $minLength }}"
        data-placeholder="{{ $placeholder }}"
        @if($required) required @endif
        @if($disabled) disabled @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        <option value="">{{ $placeholder }}</option>
        @foreach($options as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                @if((string)$optKey === $selected) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
    </select>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
