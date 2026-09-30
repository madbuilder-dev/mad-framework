@php
    $name       = $name ?? '';
    $label      = $label ?? '';
    $length     = (int)($length ?? 6);
    $mode       = $mode ?? 'numeric';
    $separator  = (int)($separator ?? 0);
    $private    = !empty($private ?? false);
    $autoSubmit = !empty($autoSubmit ?? false);
    $hint       = $hint ?? '';
    $error      = $error ?? '';
    $required   = !empty($required ?? false);
    $disabled   = !empty($disabled ?? false);
    $attrs      = $attrs ?? '';
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);

    if ($length < 1) $length = 6;
    if (!in_array($mode, ['numeric', 'alpha', 'alphanumeric'])) $mode = 'numeric';

    // Extract mad:change from attrs
    $madAction = '';
    if (preg_match('/mad:change\s*=\s*"([^"]+)"/', $attrs, $_m)) {
        $madAction = $_m[1];
        $attrs = preg_replace('/\s*mad:change\s*=\s*"[^"]*"/', '', $attrs);
    }

    // Valor inicial: registro > prop `value` (default do dev) — regra em
    // \Mad\Support\MadFieldValue. Antes a prop `value` não era lida.
    $ctxVal = \Mad\Support\MadFieldValue::resolve((string)$name, $value ?? '');

    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    \Mad\Form\MadFormRegistry::register($name, 'input', [
        'label'    => strip_tags($label),
        'type'     => 'otp',
        'required' => $required,
    ]);

    $_jsonConfig = json_encode([
        'length'     => $length,
        'mode'       => $mode,
        'private'    => $private,
        'autoSubmit' => $autoSubmit,
        'madAction'  => $madAction,
        'value'      => $ctxVal,
    ]);

    $inputmode = $mode === 'numeric' ? 'numeric' : 'text';
    $width    = $width ?? '';
    $maxWidth = $maxWidth ?? '';
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" x-data="madOtpField({{ $_jsonConfig }})" {!! $attrs !!} @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}_0">{!! $label !!}{!! $reqStar !!}</label>
    @endif

    <div class="mad-otp-group{{ $hasError ? ' mad-otp-group--error' : '' }}">
        @for($i = 0; $i < $length; $i++)
            @if($separator > 0 && $i > 0 && $i % $separator === 0)
                <span class="mad-otp-separator">&mdash;</span>
            @endif
            <input
                id="{{ $id }}_{{ $i }}"
                type="{{ $private ? 'password' : 'text' }}"
                class="mad-otp-input{{ $hasError ? ' mad-input-error' : '' }}"
                inputmode="{{ $inputmode }}"
                maxlength="1"
                @if($i === 0) autocomplete="one-time-code" @else autocomplete="off" @endif
                @if($required) required @endif
                @if($disabled) disabled @endif
                x-ref="d{{ $i }}"
                @input="onInput({{ $i }}, $event)"
                @keydown="onKeydown({{ $i }}, $event)"
                @paste="onPaste($event)"
                @focus="madSelectOnFocus($event)"
            >
        @endfor
    </div>

    <input type="hidden" name="{{ $name }}" :value="value" mad:model="{{ $name }}">

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
