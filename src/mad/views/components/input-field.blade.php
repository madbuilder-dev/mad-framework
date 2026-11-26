@props(['autocomplete' => null, 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'inputBorderColor' => '', 'inputBorderWidth' => '', 'inputBorderSides' => '', 'align' => '', 'label' => '', 'name' => '', 'value' => '', 'type' => 'text', 'placeholder' => '','hint' => '', 'hintIcon' => false, 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'maxlength' => '', 'min' => '', 'max' => '', 'attrs' => '', 'mask' => '', 'icon' => '', 'iconColor' => '', 'iconSide' => 'left', 'forceCase' => '', 'stripMask' => null, 'width' => '', 'maxWidth' => '', 'togglePassword' => false, 'validateDocument' => false, 'maskBy' => '', 'maskMap' => ''])
@php
    $required  = !empty($required);
    $disabled  = !empty($disabled);
    $readonly  = !empty($readonly);
    // `strip-mask` não escrito: a máscara sai só quando não cabe no `max:` da
    // regra do campo (fw#540, MadForm::validate).
    $_maskFit  = !isset($stripMask);
    $stripMask = !empty($stripMask);
    $togglePassword = !empty($togglePassword);
    // Estado runtime do MadForm (hide/show/readonly) aplicado no primeiro render
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly = $name && \Mad\Component\MadRenderContext::isReadonly($name);
    if ($_isReadonly) $readonly = true;
    // Valor inicial (registro > prop `value`) + mad:model — regra em
    // \Mad\Support\MadFieldValue. A ATRIBUIÇÃO de antes (não concatenação)
    // jogava fora tudo que o caller passou em `attrs` — mad:change, @change,
    // data-* —, e o único jeito de escapar era o caller mandar o próprio
    // mad:model junto. Prop e attrs do caller agora convivem.
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value ?? '');
    // Mascara — alias conhecido ou pattern literal (9 = digito, A = letra, * = alfanumerico, demais = literal)
    $_maskAttr = '';
    // Máscara pelo valor de outro campo (fórum #381): `mask-by="tipo_fj"` +
    // `mask-map="F:cpf,J:cnpj"`. A máscara fixa sai; o navegador troca pelo
    // valor do campo de controle (mad-ui.js) e o Salvar valida pelo valor
    // ENVIADO dele (MadMask::resolveBy, mapa do registro assinado). Valor fora
    // do mapa = sem máscara e sem validação de documento. Sem `mask-by`, nada
    // muda (nem atributo, nem chave no registro).
    $_maskBy  = \Mad\Support\MadMask::maskByName((string) ($maskBy ?? ''));
    $_maskMap = $_maskBy !== '' ? \Mad\Support\MadMask::parseMap((string) ($maskMap ?? '')) : [];
    if ($_maskBy !== '' && $_maskMap !== []) {
        $mask = '';
    } else {
        $_maskBy = '';
    }
    if ($mask !== '') {
        $_maskAttr = ' data-mad-mask="' . htmlspecialchars((string)$mask, ENT_QUOTES) . '"';
        // Auto maxlength quando alias tem tamanho fixo e usuario nao passou maxlength.
        // Tabela em \Mad\Support\MadMask — compartilhada com o <mad-field-list>.
        if ($maxlength === '' || $maxlength === null) {
            $_aliasMax = \Mad\Support\MadMask::aliasMaxLength((string)$mask);
            if ($_aliasMax !== null) {
                $maxlength = (string)$_aliasMax;
            }
        }
    }
    // "Validar documento" (fórum #341). Nua: o tipo vem da máscara (cpf, cnpj,
    // cpfcnpj). Com valor: o tipo dito (cns, renavam, vin, placa, pix, cpf…).
    // Desligada (o padrão) ou tipo desconhecido, nada muda: sem atributo no
    // input e sem chave no __mad_form. Ligada, o Salvar recusa formato/dígito
    // errado (MadForm::validate) e o navegador avisa ao sair do campo (mad-ui.js).
    $_docKind = \Mad\Support\MadDocument::resolve($validateDocument ?? false, (string)$mask);
    if ($_docKind !== '') {
        $_maskAttr .= ' data-mad-validate-doc="' . $_docKind . '"';
    }
    $_docByMask = false;
    if ($_maskBy !== '') {
        // O atributo `data-mad-mask` nasce vazio: o mad-ui.js o aponta pelo
        // valor do campo de controle (e o reaponta quando ele muda).
        $_maskAttr = ' data-mad-mask="" data-mad-mask-by="' . htmlspecialchars($_maskBy, ENT_QUOTES)
            . '" data-mad-mask-map="' . htmlspecialchars((string) json_encode($_maskMap, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"';
        $_docByMask = \Mad\Support\MadDocument::enabled($validateDocument ?? false);
        if ($_docByMask) {
            $_maskAttr .= ' data-mad-validate-doc-by="1"';
        }
    }
    // Force case (upper/lower/title) — aplicado client-side apos mascara
    $_forceCaseAttr = '';
    if ($forceCase !== '' && in_array(strtolower((string)$forceCase), ['upper', 'lower', 'title'], true)) {
        $_forceCaseAttr = ' data-mad-force="' . htmlspecialchars(strtolower((string)$forceCase), ENT_QUOTES) . '"';
    }
    // togglePassword — quando true, inicia como password e botao olho alterna
    $_effectiveType = $togglePassword ? 'password' : $type;
    // Width / max-width inline — normalizado (width="200" → 200px; sem isso a
    // declaração era inválida e a prop não fazia nada).
    $_dimStyle  = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false, $inputBorderColor ?? '', $inputBorderWidth ?? '', $inputBorderSides ?? '');
    $_styleAttr = $_dimStyle !== '' ? ' style="' . $_dimStyle . '"' : '';
    // Wrapper precisa quando ha icone ou toggle
    $_iconSide = strtolower((string)$iconSide) === 'right' ? 'right' : 'left';
    $_hasIcon  = $icon !== '';
    $_useGroup = $_hasIcon || $togglePassword;
    $_iconStyle = $iconColor !== '' ? ' style="color:' . htmlspecialchars((string)$iconColor, ENT_QUOTES) . '"' : '';
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError  = !empty($error);
    // `hint-icon`: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';
    // Field binding
    $action    = $action  ?? '';
    $trigger   = $trigger ?? 'change';
    $target    = $target  ?? '';
    // URL amigável do action, assada no servidor. Sem ela o Mad.exec monta
    // `/app/<Classe>/<metodo>?static=1` no client — forma que só existe para
    // classe com exposeClass(); um form de resource() dá 404 ali.
    // Só assamos quando a classe RESOLVE: com classe desconhecida o MadAction
    // não consegue decidir o static=1, e trocar a URL às cegas quebraria quem
    // hoje depende do fallback estático.
    $_actionUrl = '';
    if ($action !== '') {
        $_aParts = preg_split('/@|::/', (string) $action, 2);
        $_aCls   = trim($_aParts[0] ?? '');
        $_aMtd   = trim($_aParts[1] ?? '');
        if ($_aCls !== '' && $_aMtd !== '' && class_exists($_aCls)) {
            $_candidate = \Mad\Ui\MadAction::to($_aCls, $_aMtd)->url();
            // O Mad.exec sempre POSTa. Rota de resource() é GET-only: assar a
            // URL ali trocaria o 404 por 405 — nenhum progresso. Nesse caso não
            // assamos, e o client segue no fallback histórico.
            if (\Mad\Ui\MadAction::acceptsPost($_candidate)) {
                $_actionUrl = $_candidate;
            }
        }
    }
    $autocompleteSource = $autocompleteSource ?? '';
    \Mad\Form\MadFormRegistry::register($name, 'input', [
        'label'     => strip_tags($label),
        'type'      => $_effectiveType,
        'required'  => $required,
        'maxlength' => $maxlength,
        'min'       => $min,
        'max'       => $max,
        'mask'      => $mask,
        'stripMask' => $stripMask,
        'maskFit'   => $_maskFit,
        'validateDocument' => $_docKind,
        // Fórum #402: a condição de exibição do próprio campo vai junto (cifrada)
        // para o Salvar não conferir o documento do campo que ela esconde.
        'visibleWhen' => ($_docKind !== '' || $_docByMask) ? \Mad\Form\WhenCondition::fromAttrs((string) $attrs) : '',
    ] + ($_maskBy !== '' ? ['maskBy' => $_maskBy, 'maskMap' => $_maskMap, 'validateDocumentByMask' => $_docByMask] : []));
@endphp
{{-- Formulário em leitura (fw#498): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '' && $type !== 'hidden')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => ($__rv = \Mad\Support\MadFieldValue::resolve((string) $name, $value ?? '')), 'text' => ($_effectiveType === 'password' && $__rv !== '' ? '••••••' : $__rv), 'hidden' => $_isHidden, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}"
     @if($name) data-mad-field="{{ $name }}" @endif
     @if($togglePassword) x-data="{ visible: false }" @endif{!! $_styleAttr !!}>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif

    @if($_useGroup)
        <div class="mad-input-group">
    @endif

    @if($_hasIcon && $_iconSide === 'left')
        <span class="mad-input-addon"{!! $_iconStyle !!}>
            <i data-lucide="{{ $icon }}" style="width:14px;height:14px;"></i>
        </span>
    @endif

    <input
        id="{{ $id }}"
        name="{{ $name }}"
        @if($togglePassword) :type="visible ? 'text' : 'password'" @else type="{{ $_effectiveType }}" @endif
        class="mad-input{{ \Mad\Support\CssUnits::alignClass($align ?? '') }}{{ $hasError ? ' mad-input-error' : '' }}"
        placeholder="{{ $placeholder }}"
        @if($maxlength) maxlength="{{ $maxlength }}" @endif
        @if($min !== '') min="{{ $min }}" @endif
        @if($max !== '') max="{{ $max }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($readonly) readonly @endif
        @if($action)
            data-mad-action="{{ $action }}"
            data-mad-trigger="{{ $trigger }}"
            @if($_actionUrl) data-mad-action-url="{{ $_actionUrl }}" @endif
            @if($target) data-mad-target="{{ $target }}" @endif
        @endif
        @if($autocompleteSource)
            data-mad-autocomplete="{{ $autocompleteSource }}"
            autocomplete="off"
        @elseif($type !== 'hidden')
            {{-- autocomplete do campo ou opção do projeto (fórum #436); senha fica como está --}}
            {!! \Mad\Support\FieldAutocomplete::attr($autocomplete ?? null, (string) $attrs, $_effectiveType === 'password') !!}
        @endif
        {!! $_maskAttr !!}
        {!! $_forceCaseAttr !!}
        {!! $attrs !!}
    >

    @if($_hasIcon && $_iconSide === 'right' && !$togglePassword)
        <span class="mad-input-addon mad-input-addon-r"{!! $_iconStyle !!}>
            <i data-lucide="{{ $icon }}" style="width:14px;height:14px;"></i>
        </span>
    @endif

    @if($togglePassword)
        <button type="button" class="mad-password-toggle-btn" tabindex="-1"
                @if($disabled || $readonly) disabled @endif
                @click="visible = !visible">
            <i data-lucide="eye"     x-show="!visible" style="width:14px;height:14px;"></i>
            <i data-lucide="eye-off" x-show="visible"  style="width:14px;height:14px;" x-cloak></i>
        </button>
    @endif

    @if($_useGroup)
        </div>
    @endif

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
