@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'hint' => '', 'hintIcon' => false, 'error' => '', 'required' => false, 'disabled' => false, 'readonly' => false, 'attrs' => '', 'items' => [], 'selected' => null, 'value' => '','placeholder' => '', 'multiple' => false, 'noSearch' => false, 'allowEmpty' => false, 'noEmpty' => false, 'noResultsCreateAction' => '', 'noResultsCreateLabel' => mad_t('mad.ui.create_new'), 'noResultsCreateIcon' => 'plus', 'noResultsCreateClass' => 'mad-btn mad-btn-primary mad-btn-sm', 'noResultsQuickRegisterAction' => '', 'noResultsQuickRegisterLabel' => mad_t('mad.ui.add'), 'noResultsQuickRegisterIcon' => 'check', 'noResultsQuickRegisterClass' => 'mad-btn mad-btn-success mad-btn-sm', 'noResultsQuickFields' => [], 'noResultsMessage' => '', 'colors' => ''])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $items    = is_array($items) ? $items : [];
    // Fallback: se prop items veio vazia, tenta MadForm->items[$name] —
    // permite que onEdit/mount populem options via $form->setItems(...) e o
    // render inicial ja saia com elas.
    if (empty($items) && $name) {
        $_form = \Mad\Component\MadRenderContext::getForm();
        if ($_form && isset($_form->items[$name]) && is_array($_form->items[$name])) {
            $items = $_form->items[$name];
            // O texto da opção vazia vem junto (4º parâmetro do setItems), como
            // no reload_combo de uma ação. Sem isto o combo abria sem ela e com
            // a primeira opção da lista escolhida (fw#151).
            if (isset($_form->placeholders[$name])) {
                $placeholder = (string) $_form->placeholders[$name];
            }
        }
    }
    // Lista de objetos [['value' => …, 'label' => …]] vira [valor => rótulo] —
    // antes saía <option value="0"></option>, sem erro (\Mad\Support\MadItems).
    // Cor por opção (ponto na lista e no valor): prop `colors`, par de selo
    // `['aberto' => ['Aberto', 'info']]` ou `color` na lista de objetos.
    $__colorMarks = \Mad\Form\OptionHighlights::staticMarks($colors ?? '', $items);
    $items = \Mad\Support\MadItems::normalize($items);
    $_isHidden   = $name && \Mad\Component\MadRenderContext::isHidden($name);
    // `readonly` / `:readonly` da tag trava como o do formulário (fw#315).
    $_isReadonly = !empty($readonly ?? false)
        || ($name && \Mad\Component\MadRenderContext::isReadonly($name));
    // Valores CRUS, antes de qualquer achatamento — só eles preservam o array
    // de um multi-select (MadFieldValue::resolve() devolve '' para array).
    $_rawSelected = $selected;
    $_rawValue    = $value ?? null;
    // `value` é alias de `selected` — quem escreve `<mad-select-field value="x">`
    // esperava seleção, e antes a prop morria em silêncio (nem declarada).
    if (($selected === null || $selected === '') && isset($value) && $value !== '') {
        $selected = $value;
    }
    // Registro > prop `selected`/`value` (default do dev) — \Mad\Support\MadFieldValue.
    // O `$selected === null` de antes invertia: literal na tag ganhava do banco.
    if ($name) {
        $_resolved = \Mad\Support\MadFieldValue::resolve($name, $selected);
        if ($_resolved === '' && isset($$name)) {
            $_resolved = (string)$$name;
        }
        $selected = $_resolved;
    }
    // Multi-select guarda ARRAY. O `(string)` abaixo o viraria "Array" (com
    // warning) e nada casaria; a lista de verdade segue em $_slotValues.
    if (is_array($selected) || is_object($selected)) {
        $selected = '';
    }
    $selected = (string)($selected ?? '');
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    // Slot: o valor corrente tem que sair como `selected` do SERVIDOR, igual ao
    // caminho `:items`. Sem isto o `<select>` single já vem com a PRIMEIRA
    // option elegida pelo navegador (não há `<option value="">` obrigatória), o
    // JS desistia de aplicar `data-mad-selected` e o Salvar gravava a primeira
    // opção por cima do valor real — ver \Mad\Support\MadSelectSlot.
    // `multiple` (prop) = seleção múltipla com checkboxes também no caminho
    // `:items`; `multiple` cru em `attrs` segue valendo pro slot.
    $multiple      = !empty($multiple);
    $_slotMultiple = $multiple || \Mad\Support\MadSelectSlot::isMultiple($attrs);
    // Atributos do modo múltiplo como STRING colada no `data-mad-select`: um
    // `@if` em linha própria deixava a indentação no HTML do select comum
    // (+8 bytes) — e esse HTML é travado byte a byte em
    // SelectFieldSlotSelectedTest, porque todo app publicado usa esse caminho.
    $_multipleAttrs = $multiple
        ? ' multiple data-mad-selectcheck' . ($placeholder !== '' && $placeholder !== null ? ' data-placeholder="' . e($placeholder) . '"' : '')
        : '';
    // `no-search`: a lista abre sem a caixa de busca (também no múltiplo).
    // Colado no `data-mad-select` pelo mesmo motivo do `$_multipleAttrs`.
    $_noSearchAttr = !empty($noSearch) ? ' data-mad-nosearch' : '';
    // Opção em branco no topo da lista — é ela que deixa o campo sem escolha.
    // Sai com `placeholder` (como sempre saiu) ou com `allow-empty`, que
    // dispensa inventar um placeholder só para isso; `no-empty` tira mesmo com
    // placeholder. No múltiplo não existe: desmarca-se item a item.
    $_hasPlaceholder = $placeholder !== '' && $placeholder !== null;
    $_emptyOption    = !$_slotMultiple && empty($noEmpty) && (!empty($allowEmpty) || $_hasPlaceholder);
    $_emptyLabel     = $_hasPlaceholder ? $placeholder : mad_t('mad.ui.select_placeholder');
    $_slotValues   = \Mad\Support\MadSelectSlot::valueList(
        $selected,
        [$name ? \Mad\Component\MadRenderContext::listValue($name) : null, $_rawSelected, $_rawValue],
        $_slotMultiple
    );
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    // `hint-icon`: a ajuda vai para um ⓘ ao lado do rótulo (sai de baixo do campo).
    [$__hintTip, $hint] = \Mad\Support\FieldHint::split($hintIcon ?? false, $hint ?? '', $label ?? '');
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    // As opções que a tela desenha (`:items`, a lista do setItems() ou as
    // <option> do slot): o valor escolhido tem de ser uma delas — o que chega
    // no POST é dado do navegador (fw#531). Cadastro na hora (`no-results-*`)
    // acrescenta opção no navegador: fica sem conferência. Só vai para o
    // estado da tela.
    $__offered = !empty($items) ? array_keys($items) : array_keys(\Mad\Form\ReadMode::slotOptions((string) ($slot ?? '')));
    \Mad\Form\MadFormRegistry::register($name, 'select', [
        'label'    => strip_tags($label),
        'required' => $required,
        'optionsSource' => ($__offered !== [] && empty($noResultsCreateAction) && empty($noResultsQuickRegisterAction))
            ? ($_slotMultiple ? ['offered' => $__offered] : ['one' => true, 'offered' => $__offered])
            : '',
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
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
{{-- Formulário em leitura (fw#498): rótulo e valor no lugar do controle. --}}
@php $__rm = \Mad\Form\ReadMode::mode($__env); @endphp
@if($__rm !== '')
{!! \Mad\Form\ReadMode::field($__rm, ['name' => $name, 'label' => $label, 'raw' => ($__sel = $_slotMultiple ? $_slotValues : $selected), 'text' => \Mad\Form\ReadMode::optionText($items ?: \Mad\Form\ReadMode::slotOptions((string) ($slot ?? '')), $__sel), 'hidden' => $_isHidden, 'style' => $_dimStyle]) !!}
@else
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}{{ $_isReadonly ? ' mad-readonly' : '' }}" @if($name) data-mad-field="{{ $name }}" @endif @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}{!! \Mad\Support\FieldHint::icon($__hintTip) !!}</label>
    @endif
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        class="mad-select{{ $hasError ? ' mad-input-error' : '' }}{{ $_isReadonly ? ' mad-readonly-select' : '' }}"
        data-mad-select{!! $_multipleAttrs !!}{!! $_noSearchAttr !!}
        @if($selected) data-mad-selected="{{ $selected }}" @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        @if(!empty($items))
            @if($_emptyOption)<option value="">{{ $_emptyLabel }}</option>@endif
            @foreach($items as $optKey => $optLabel)
                @php
                    // Coercao: labels como ['Label','variant'] (badge map) ou
                    // outro array — pega primeiro escalar; evita TypeError em htmlentities.
                    if (is_array($optLabel)) {
                        $optLabel = $optLabel[0] ?? '';
                    }
                    if (is_object($optLabel)) {
                        $optLabel = method_exists($optLabel, '__toString') ? (string)$optLabel : '';
                    }
                @endphp
                <option value="{{ $optKey }}"{!! $__colorMarks ? \Mad\Form\OptionHighlights::attrs($__colorMarks[(string) $optKey] ?? null) : '' !!} @if($_slotMultiple ? in_array((string)$optKey, $_slotValues, true) : (string)$optKey === (string)$selected) selected @endif>{{ $optLabel }}</option>
            @endforeach
        @else
            {{-- Slot: quem escreve as <option> escreve também a em branco; só o
                 `allow-empty` explícito a acrescenta, e nunca uma segunda. --}}
            @if($_emptyOption && !empty($allowEmpty) && !preg_match('/<option\b[^>]*\svalue\s*=\s*(""|\'\')/i', (string) $slot))<option value="">{{ $_emptyLabel }}</option>@endif
            {!! \Mad\Support\MadSelectSlot::markSelected((string) $slot, $_slotValues, $_slotMultiple) !!}
        @endif
    </select>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
@endif
