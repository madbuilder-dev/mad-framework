@php
    $name             = $name ?? '';
    $label            = $label ?? '';
    $placeholder      = $placeholder ?? '';
    $hint             = $hint ?? '';
    $error            = $error ?? '';
    $required         = !empty($required ?? false);
    $disabled         = !empty($disabled ?? false);
    $readonly         = !empty($readonly ?? false);
    $maxlength        = $maxlength ?? '';
    $toggleVisibility = !isset($toggleVisibility) || !empty($toggleVisibility);
    $strongPassword   = !empty($strongPassword ?? false);
    $strongOptions    = $strongOptions ?? [];
    $autocomplete     = $autocomplete ?? 'current-password';
    $attrs            = $attrs ?? '';

    // Estado runtime do MadForm (hide/show/readonly)
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly = $name && \Mad\Component\MadRenderContext::isReadonly($name);
    if ($_isReadonly) $readonly = true;

    // Valor inicial (registro > prop `value`) + injeção de mad:model — regra em
    // \Mad\Support\MadFieldValue. A atribuição direta de antes descartava os
    // atributos do caller (mad:change, @change, data-*) sem avisar.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value ?? '');

    $id       = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    // Defaults das regras de senha forte (mesmo do TPassword.php)
    $defaultRules = [
        'minLength'          => ['value' => 8,    'message' => '8 caracteres'],
        'requireNumbers'     => ['value' => true, 'message' => 'Pelo menos 1 número'],
        'requireLowercase'   => ['value' => true, 'message' => 'Pelo menos 1 letra minúscula'],
        'requireUppercase'   => ['value' => true, 'message' => 'Pelo menos 1 letra maiúscula'],
        'requireSpecialChar' => ['value' => true, 'message' => 'Pelo menos 1 caractere especial'],
    ];
    $rules = $defaultRules;
    foreach ($strongOptions as $k => $v) {
        if (isset($defaultRules[$k]) && is_array($v)) {
            $rules[$k] = array_merge($defaultRules[$k], $v);
        }
    }

    \Mad\Form\MadFormRegistry::register($name, 'input', [
        'label'          => strip_tags($label),
        'type'           => 'password',
        'required'       => $required,
        'maxlength'      => $maxlength,
        'strongPassword' => $strongPassword,
        'strongOptions'  => $strongPassword ? $rules : null,
    ]);

    $_jsonConfig = json_encode([
        'toggleVisibility' => $toggleVisibility,
        'strongPassword'   => $strongPassword,
        'rules'            => $rules,
        'popoverTitle'     => 'A senha deve ter:',
    ], JSON_UNESCAPED_UNICODE);
    $width    = $width ?? '';
    $maxWidth = $maxWidth ?? '';
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }} mad-password-field"
     @if($name) data-mad-field="{{ $name }}" @endif
     x-data="madPasswordField({{ $_jsonConfig }})"
     style="position:relative;{{ $_dimStyle }}">
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            :type="visible ? 'text' : 'password'"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            autocomplete="{{ $autocomplete }}"
            @if($maxlength) maxlength="{{ $maxlength }}" @endif
            @if($required) required @endif
            @if($disabled) disabled @endif
            @if($readonly) readonly @endif
            x-ref="input"
            @input="onInput($event)"
            @focus="onFocus()"
            @blur="onBlur()"
            {!! $attrs !!}
        >
        @if($toggleVisibility)
            <button type="button" class="mad-password-toggle-btn" tabindex="-1"
                    @if($disabled || $readonly) disabled @endif
                    @click="toggleVisible()">
                <i data-lucide="eye"     x-show="!visible" style="width:14px;height:14px;"></i>
                <i data-lucide="eye-off" x-show="visible"  style="width:14px;height:14px;" x-cloak></i>
            </button>
        @endif
    </div>

    @if($strongPassword)
        <div class="mad-password-strength-popover" x-show="showPopover" x-cloak x-transition.opacity>
            <p class="mad-password-strength-title" x-text="popoverTitle"></p>
            <ul class="mad-password-strength-list">
                <template x-for="(rule, key) in activeRules" :key="key">
                    <li :class="{ 'mad-pwd-rule-ok': checks[key] }">
                        <i :data-lucide="checks[key] ? 'check' : 'x'" style="width:12px;height:12px;"></i>
                        <span x-text="rule.message"></span>
                    </li>
                </template>
            </ul>
        </div>
    @endif

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
