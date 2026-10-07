@props(['name' => '', 'variant' => 'secondary', 'size' => '', 'icon' => '', 'iconEnd' => '', 'href' => '', 'type' => 'button', 'disabled' => false, 'loading' => false, 'block' => false, 'attrs' => '', 'class' => '', 'label' => '', 'confirm' => '', 'color' => '', 'title' => '', 'ariaLabel' => '', 'id' => '', 'ariaPressed' => '', 'ariaExpanded' => '', 'ariaControls' => '', 'ariaHaspopup' => '', 'ariaDescribedby' => '', 'permAction' => '', 'permClass' => '', 'position' => ''])
@php
    $disabled = !empty($disabled);
    $loading  = !empty($loading);
    $block    = !empty($block);
    $attrs    = is_scalar($attrs) || $attrs instanceof \Stringable ? (string) $attrs : '';

    // ── Atributos que o componente não declara ──────────────────────────────
    // O pipeline do MadBlade não tem `$attributes`: cada atributo da tag chega
    // como VARIÁVEL camelCase e o que o template não emite é descartado em
    // silêncio — `<mad-btn style="margin-left:auto">` saía sem o style e o
    // botão ficava no lugar errado, sem erro nem aviso. Repassa `style` e os
    // atributos HTML que fazem sentido num botão/link: `data-*`, `aria-*` (os
    // não declarados abaixo), `x-*` (Alpine), handlers `on*` e uma lista curta
    // de atributos nativos. Os declarados (@props) continuam com o tratamento
    // deles, e o que já veio em `attrs=""` vence (atributo duplicado seria
    // ignorado pelo browser).
    $_userStyle = '';
    $_passAttrs = '';
    foreach (get_defined_vars() as $_pk => $_pv) {
        if ($_pk === 'style') {
            $_userStyle = is_scalar($_pv) || $_pv instanceof \Stringable ? trim((string) $_pv) : '';
            continue;
        }
        if (! preg_match('/^(?:(?:data|aria|x)[A-Z0-9][A-Za-z0-9]*|on[a-z]{3,}|tabindex|role|form|formaction|formenctype|formmethod|formnovalidate|formtarget|autofocus|accesskey|rel|download|value|lang|dir|draggable|hidden|translate|spellcheck|popovertarget|popovertargetaction)$/', $_pk)) {
            continue;
        }
        if (in_array($_pk, ['ariaLabel', 'ariaPressed', 'ariaExpanded', 'ariaControls', 'ariaHaspopup', 'ariaDescribedby'], true)) {
            continue; // declarados: tratados no bloco de identidade mais abaixo
        }
        if ($_pv === false || $_pv === null || (! is_scalar($_pv) && ! $_pv instanceof \Stringable)) {
            continue;
        }
        $_pName = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $_pk));
        if ($_pName === 'tabindex' && $href && $disabled) {
            continue; // link desabilitado já sai com tabindex="-1"
        }
        if (preg_match('/(^|\s)' . preg_quote($_pName, '/') . '\s*=/i', $attrs)) {
            continue;
        }
        $_passAttrs .= ' ' . ($_pv === true
            ? $_pName
            : $_pName . '="' . htmlspecialchars((string) $_pv, ENT_QUOTES, 'UTF-8', false) . '"');
    }
    // Antes do `confirm`: um `onclick` escrito direto na tag também é embrulhado.
    if ($_passAttrs !== '') {
        $attrs = ltrim($_passAttrs) . ($attrs !== '' ? ' ' . $attrs : '');
    }

    // confirm="msg" — pergunta antes de executar a ação do botão.
    // O onclick do navigate/target já vem pronto (e html-escapado) em $attrs:
    // embrulhamos o JS original em vez de acrescentar um segundo onclick
    // (atributo duplicado seria ignorado pelo browser). Arrow function preserva
    // o `this` do handler inline — Mad.rowAttach(this, …) depende disso.
    $label   = (string) $label;
    $confirm = (string) $confirm;
    if ($confirm !== '') {
        $_cMsg  = json_encode($confirm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $_cOpts = $variant === 'danger' ? ', {danger:true}' : '';
        if (preg_match('/\bonclick\s*=\s*"([^"]*)"/i', $attrs, $_cm)) {
            $_cJs  = 'Mad.confirm(' . $_cMsg . $_cOpts . ').then(ok => { if (ok) { '
                   . htmlspecialchars_decode($_cm[1], ENT_QUOTES) . ' } })';
            $attrs = str_replace($_cm[0], 'onclick="' . htmlspecialchars($_cJs, ENT_QUOTES) . '"', $attrs);
        } elseif (strpos($attrs, 'data-mad-click') !== false) {
            // MadWire já honra data-mad-confirm no handler de data-mad-click
            $attrs .= ' data-mad-confirm="' . htmlspecialchars($confirm, ENT_QUOTES) . '"';
        } else {
            // submit / reset / <a href> — gate cancela o evento e re-dispara
            $attrs .= ' onclick="' . htmlspecialchars(
                'return Mad.confirmGate(event, ' . $_cMsg . $_cOpts . ')', ENT_QUOTES) . '"';
        }
    }

    // Estado runtime do MadForm (hide/show/disable aplicados no primeiro render)
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name, 'btn');
    $_isDisabled = $name && \Mad\Component\MadRenderContext::isDisabled($name);
    if ($_isDisabled) $disabled = true;

    // ── Permissão por ação (tela de Perfis) ─────────────────────────────────
    // O botão anuncia a ação que executa (mad:click / navigate / type=submit
    // dentro de um <mad-form submit>) e pergunta ao perfil antes de aparecer.
    // Sem ação anunciada, ou fora de qualquer tela, NADA muda: o gate é
    // fail-open de ponta a ponta e só uma chave explicitamente negada mexe no
    // botão. Ver \Mad\Security\ActionGuard.
    $_permAction = is_string($permAction) ? trim($permAction) : '';
    $_permMode   = 'allow';
    $_permTitle  = '';
    if ($_permAction !== '') {
        $_permComp  = \Mad\Component\MadRenderContext::getComponent();
        $_permOwner = is_string($permClass) ? trim($permClass) : '';
        if ($_permOwner === '' && $_permComp) {
            $_permOwner = get_class($_permComp);
        }
        if ($_permOwner !== '') {
            // Salvar é INCLUIR num cadastro novo e EDITAR num registro aberto —
            // e só a tela sabe qual dos dois. Sem tela, null: o guard libera.
            $_permIsNew = null;
            if ($_permComp && method_exists($_permComp, '_recordId')) {
                try {
                    $_permRid   = $_permComp->_recordId();
                    $_permIsNew = ($_permRid === null || $_permRid === '');
                } catch (\Throwable $e) {
                    $_permIsNew = null;
                }
            }
            $_permDec   = \Mad\Security\ActionGuard::decide($_permOwner, $_permAction, $_permIsNew);
            $_permMode  = $_permDec['mode'];
            $_permTitle = $_permDec['title'];
        }
    }
    // Modo "desabilitados, com dica": o botão NÃO leva `disabled`. Com ele o
    // tema (`button:disabled{pointer-events:none}`) e o `.mad-btn:disabled`
    // matavam a dica — o mouse atravessava o botão — e o teclado nem chegava
    // nele. Fica focável, com `aria-disabled` + `data-mad-deny`: o mad-ui.js
    // barra o clique (mouse, Enter, toque) e mostra a dica.
    $_permDeny = $_permMode === 'disable';
    if ($_permDeny) {
        $disabled = false;
        $title    = '';   // a dica do perfil vai na tag e vence o title do chamador
    }

    // `style` da tag. Quem escreveu style em `attrs=""` continua mandando nele.
    // Link desabilitado soma a opacidade ao style do autor (um atributo só).
    $_hasAttrStyle = (bool) preg_match('/(^|\s)style\s*=/i', $attrs);
    $_styleOut = $_hasAttrStyle ? '' : $_userStyle;
    if ($href && $disabled) {
        $_styleOut = ($_styleOut !== '' ? rtrim($_styleOut, "; \t") . ';' : '') . 'opacity:.45;pointer-events:none;';
    }

    $variantClass = "mad-btn-{$variant}";
    $sizeClass    = $size    ? " mad-btn-{$size}"  : '';
    $blockClass   = $block   ? ' mad-btn-block'    : '';
    $hiddenClass  = $_isHidden ? ' mad-hidden'     : '';
    // position="left|center|right" — lado do botão dentro do <mad-form-actions>
    // (o CSS da barra ordena pelos grupos). Fora da allowlist não sai classe.
    $posClass     = in_array($position, ['left', 'center', 'right'], true) ? " mad-pos-{$position}" : '';
    $iconSz = $size === 'sm' ? '12px' : '14px';

    // color="#a78bfa" — cor do ícone. O valor entra num atributo style, então
    // passa por allowlist: hex, rgb()/rgba(), hsl()/hsla(), var(--token) ou
    // nome CSS. Qualquer outra coisa (';', 'url(', 'expression(') é DESCARTADA
    // — sem isso o valor fecharia a declaração e injetaria CSS arbitrário.
    $_iconColor = trim((string) $color);
    if ($_iconColor !== '' && !preg_match(
        '/^(#[0-9a-f]{3,8}|(?:rgb|hsl)a?\([\d\s.,%\/]+\)|var\(--[a-z0-9-]+\)|[a-z]+)$/i',
        $_iconColor
    )) {
        $_iconColor = '';
    }
    // O escape do atributo fica com o {{ }} do Blade no ponto de uso.
    $iconStyle = "width:{$iconSz};height:{$iconSz};"
               . ($_iconColor !== '' ? "color:{$_iconColor};" : '');

    // ── identidade e nome acessível ──────────────────────────────────────────
    // O pipeline do MadBlade NÃO tem `$attributes`: o `parseParams` entrega
    // cada atributo da tag como VARIÁVEL camelCase no escopo do componente, e o
    // que o template não emitir é descartado em silêncio. Era o caso de
    // `title`, `aria-label` e `id` — um `<mad-btn icon="trash-2" title="Excluir">`
    // saía sem nome nenhum, e ícone sem nome acessível é botão que ninguém sabe
    // o que faz (nem o leitor de tela, nem quem só passa o mouse).
    //
    // Quem já escrevia o atributo dentro de `attrs=""` continua valendo: a
    // chave só entra quando NÃO está no `$attrs` (atributo duplicado seria
    // ignorado pelo browser, e o primeiro a aparecer é o do caller).
    $_extraAttrs = '';
    foreach ([
        'id'               => $id,
        'title'            => $title,
        'aria-label'       => $ariaLabel,
        'aria-pressed'     => $ariaPressed,
        'aria-expanded'    => $ariaExpanded,
        'aria-controls'    => $ariaControls,
        'aria-haspopup'    => $ariaHaspopup,
        'aria-describedby' => $ariaDescribedby,
    ] as $_aName => $_aValue) {
        if (is_array($_aValue) || is_object($_aValue)) {
            continue;
        }
        $_aValue = is_bool($_aValue) ? ($_aValue ? 'true' : '') : trim((string) $_aValue);
        if ($_aValue === '') {
            continue;
        }
        if (preg_match('/(^|\s)' . preg_quote($_aName, '/') . '\s*=/i', $attrs)) {
            continue; // o caller já escreveu este atributo em attrs=""
        }
        $_extraAttrs .= ' ' . $_aName . '="' . htmlspecialchars($_aValue, ENT_QUOTES) . '"';
    }
@endphp
{{-- Modo "ocultos": o botão da ação sem permissão simplesmente não vai pra tela. --}}
@if($_permMode !== 'hide')
@if($href)
<a href="{{ $href }}"
   class="mad-btn {{ $variantClass }}{{ $sizeClass }}{{ $blockClass }}{{ $hiddenClass }}{{ $posClass }} {{ $class }}"
   @if($name) data-mad-btn="{{ $name }}" @endif
   @if($disabled) aria-disabled="true" tabindex="-1" @endif
   @if($_styleOut !== '') style="{!! htmlspecialchars($_styleOut, ENT_QUOTES, 'UTF-8', false) !!}" @endif
   @if($_permDeny) aria-disabled="true" data-mad-deny title="{{ $_permTitle }}" @endif
   {!! $_extraAttrs !!} {!! $attrs !!}>
    @if($loading)<div class="mad-spinner mad-spinner-sm"></div>
    @elseif($icon)<i data-lucide="{{ $icon }}" style="{{ $iconStyle }}"></i>@endif
    @if($label !== ''){{ $label }}@endif
    {!! $slot !!}
    @if($iconEnd)<i data-lucide="{{ $iconEnd }}" style="{{ $iconStyle }}"></i>@endif
</a>
@else
<button type="{{ $type }}"
        class="mad-btn {{ $variantClass }}{{ $sizeClass }}{{ $blockClass }}{{ $hiddenClass }}{{ $posClass }} {{ $class }}"
        @if($name) data-mad-btn="{{ $name }}" @endif
        @if($disabled) disabled @endif
        @if($_styleOut !== '') style="{!! htmlspecialchars($_styleOut, ENT_QUOTES, 'UTF-8', false) !!}" @endif
        @if($_permDeny) aria-disabled="true" data-mad-deny title="{{ $_permTitle }}" @endif
        {!! $_extraAttrs !!} {!! $attrs !!}>
    @if($loading)<div class="mad-spinner mad-spinner-sm"></div>
    @elseif($icon)<i data-lucide="{{ $icon }}" style="{{ $iconStyle }}"></i>@endif
    @if($label !== ''){{ $label }}@endif
    {!! $slot !!}
    @if($iconEnd)<i data-lucide="{{ $iconEnd }}" style="{{ $iconStyle }}"></i>@endif
</button>
@endif
@endif
