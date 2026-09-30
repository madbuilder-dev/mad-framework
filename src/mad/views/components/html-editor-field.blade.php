@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 
    'width' => '', 'maxWidth' => '',
    'label'       => '',
    'name'        => '',
    'value'       => '',
    'height'      => 300,
    'placeholder' => '',
    'hint'        => '',
    'error'       => '',
    'required'    => false,
    'disabled'    => false,
    'attrs'       => '',
    'locale'      => '',      // '' = pt_BR (default). Aceita: pt_BR, pt_PT, en, es
    'toolbar'     => '',      // '' = preset completo. String override.
    'plugins'     => '',      // '' = preset completo. String override.
    'menubar'     => '',      // '' = default ativo. Aceita 'false' pra desligar ou outra string
    'statusbar'   => true,    // barra inferior
    'skin'        => '',      // '' = oxide. Aceita 'oxide-dark'
    'content_css' => '',      // '' = default. Aceita 'dark'
])
@php
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $content_css = $contentCss ?? $content_css;

    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $statusbar = !empty($statusbar) && $statusbar !== 'false';
    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    $id        = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $height    = (int) $height;

    // Registro > prop `value` (default do dev) — regra em \Mad\Support\MadFieldValue.
    // Com array_key_exists puro, coluna presente e VAZIA (form de criação)
    // atropelava o conteúdo default escrito na tag.
    $initVal = \Mad\Support\MadFieldValue::resolve((string) $name, $value);

    // Presets padrao (dev pode sobrescrever via prop)
    $defaultPlugins = 'advlist autolink lists link image charmap preview anchor '
                    . 'searchreplace visualblocks visualchars code fullscreen '
                    . 'insertdatetime media table paste help wordcount '
                    // emoticons removido: plugin exige js/emojis.min.js que não é distribuído (404)
                    . 'codesample hr nonbreaking pagebreak quickbars '
                    . 'directionality';

    $defaultToolbar = 'undo redo | blocks fontsize | '
                    . 'bold italic underline strikethrough | forecolor backcolor | '
                    . 'alignleft aligncenter alignright alignjustify | '
                    . 'bullist numlist outdent indent | '
                    . 'link image media table codesample | '
                    . 'removeformat searchreplace fullscreen code preview | help';

    $defaultMenubar = 'file edit view insert format tools table help';

    $effPlugins = $plugins !== '' ? $plugins : $defaultPlugins;
    $effToolbar = $toolbar !== '' ? $toolbar : $defaultToolbar;
    $effMenubar = $menubar === '' ? $defaultMenubar : $menubar; // 'false' desliga
    $effLocale  = $locale  !== '' ? $locale  : 'pt_BR';
    $effSkin    = $skin    !== '' ? $skin    : 'oxide';
    $effContent = $content_css !== '' ? $content_css : 'default';

    $edCfg = json_encode([
        'height'      => $height,
        'placeholder' => $placeholder,
        'disabled'    => $disabled,
        'initVal'     => $initVal,
        'plugins'     => $effPlugins,
        'toolbar'     => $effToolbar,
        'menubar'     => $effMenubar === 'false' ? false : $effMenubar,
        'statusbar'   => $statusbar,
        'locale'      => $effLocale,
        'skin'        => $effSkin,
        'content_css' => $effContent,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name, 'html-editor', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-html-editor-wrap{{ $hasError ? ' mad-html-editor-error' : '' }}"
         x-data="madHtmlEditor({{ $edCfg }})"
         x-init="init()">
        <textarea
            id="{{ $id }}"
            name="{{ $name }}"
            mad:model="{{ $name }}"
            x-ref="textarea"
            @if($required) required @endif
            {!! $attrs !!}
        >{{ $initVal }}</textarea>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
