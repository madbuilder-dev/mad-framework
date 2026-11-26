@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 
    'width' => '', 'maxWidth' => '',
    'label'       => '',
    'name'        => '',
    'value'       => '',
    'height'      => 300,
    'placeholder' => '',
    'hint'        => '', 'hintIcon' => false,
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
    'uploadUrl'    => '',     // endereço que recebe a imagem colada/arrastada (POST multipart `file`, devolve {"location": "..."})
    'uploadAccept' => '',     // tipos aceitos no envio: 'png,jpg' ou 'image/png,image/jpeg' ('' = qualquer imagem)
    'uploadMaxMb'  => '',     // tamanho máximo da imagem enviada, em MB ('' = sem limite no navegador)
    'paste'        => '',     // 'clean' = colar de fora traz só os destaques (sem fonte, cor e estilo)
    'options'      => [],     // opções extras do TinyMCE (array), mescladas por último
])
@php // hint-icon: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
@endphp
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

    // O editor monta este conteúdo como HTML dentro da página. O que está no
    // banco pode ter sido gravado antes de a limpeza valer sempre (ou por
    // fora do formulário: importação, API, edição na célula): sai limpo daqui
    // — sem `<script>`, sem atributo de evento, sem URL `javascript:`.
    if (is_string($initVal) && $initVal !== '') {
        $initVal = \Mad\Util\MadHtmlSanitizer::sanitize($initVal);
    }

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

    $edCfgArr = [
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
    ];

    // fw#629 — imagem colada/arrastada enviada ao servidor, colagem limpa e
    // opções extras do TinyMCE. Tudo opt-in: sem estas props a configuração
    // do editor sai exatamente como antes (nenhuma chave nova).
    $uploadUrl    = (string) (($uploadUrl ?? '') !== '' ? $uploadUrl : ($upload_url ?? ''));
    $uploadAccept = (string) (($uploadAccept ?? '') !== '' ? $uploadAccept : ($upload_accept ?? ''));
    $uploadMaxMb  = ($uploadMaxMb ?? '') !== '' ? $uploadMaxMb : ($upload_max_mb ?? '');
    if (trim($uploadUrl) !== '') {
        $edCfgArr['uploadUrl'] = trim($uploadUrl);

        // Tipos: extensão (png, .jpg) ou MIME de imagem (image/webp). `image/*`
        // ou nenhum tipo reconhecido = qualquer imagem.
        $extMime = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jpe' => 'image/jpeg', 'png' => 'image/png',
            'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'avif' => 'image/avif',
        ];
        $acceptMime = [];
        $acceptLabel = [];
        foreach (preg_split('/[\s,;|]+/', strtolower($uploadAccept), -1, PREG_SPLIT_NO_EMPTY) as $tok) {
            $tok = ltrim($tok, '.');
            if ($tok === 'image/*' || $tok === 'image') {
                $acceptMime = [];
                break;
            }
            $mime = str_starts_with($tok, 'image/') ? $tok : ($extMime[$tok] ?? null);
            if ($mime === null || in_array($mime, $acceptMime, true)) {
                continue;
            }
            $acceptMime[]  = $mime;
            $acceptLabel[] = $mime === 'image/jpeg' ? 'JPG' : strtoupper(preg_replace('/\+.*$/', '', substr($mime, 6)));
        }
        if ($acceptMime) {
            $edCfgArr['uploadAccept']      = $acceptMime;
            $edCfgArr['uploadAcceptLabel'] = implode(', ', $acceptLabel);
        }

        if (is_numeric($uploadMaxMb) && (float) $uploadMaxMb > 0) {
            $mb = (float) $uploadMaxMb;
            $edCfgArr['uploadMaxMb'] = floor($mb) == $mb ? (int) $mb : $mb;
        }
    }

    if (strtolower(trim((string) $paste)) === 'clean') {
        $edCfgArr['paste'] = 'clean';
    }

    // `:options="[...]"` (ou JSON em texto): saída para o que as props não cobrem.
    $extraOpts = $options;
    if (is_string($extraOpts) && trim($extraOpts) !== '') {
        $extraOpts = json_decode($extraOpts, true);
    }
    if (is_array($extraOpts) && $extraOpts !== [] && ! array_is_list($extraOpts)) {
        $edCfgArr['options'] = $extraOpts;
    }

    $edCfg = json_encode($edCfgArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    \Mad\Form\MadFormRegistry::register($name, 'html-editor', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);

    // Formulário em leitura (Visualizar da listagem, <mad-form read> — fórum
    // #422): o texto formatado no lugar do editor, sem barra e sem edição. O
    // HTML sai pela mesma limpeza da entrada (sem script, atributo de evento ou
    // URL `javascript:`); vazio (só parágrafo em branco) vira "—".
    $__rm = \Mad\Form\ReadMode::mode($__env);
    if ($__rm !== '') {
        $__rmHtml = is_string($initVal) ? $initVal
            : (is_scalar($initVal) ? \Mad\Util\MadHtmlSanitizer::sanitize((string) $initVal) : '');
        $__rmEmpty = trim(
            html_entity_decode(strip_tags($__rmHtml, '<img><hr><table>'), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            " \t\n\r\0\x0B\u{00A0}"
        ) === '';
    }
@endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'html' => '<div class="mad-display-field-html">' . $__rmHtml . '</div>', 'empty' => $__rmEmpty, 'raw' => $__rmHtml, 'class' => 'mad-field-read-html', 'style' => $_dimStyle]) !!}
@else
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
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
@endif
