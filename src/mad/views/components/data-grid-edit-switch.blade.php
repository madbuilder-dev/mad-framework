{{-- ====================================================================
    Chave liga/desliga na célula (edit-type="switch", fórum #297).

    Alterna e grava a linha na hora pelo MESMO caminho da edição na célula
    (commitInline → onInlineSave): servidor confere a coluna, o valor e a
    permissão, e devolve a linha do banco — recusa volta a chave sozinha;
    sem resposta, o madDataGrid volta a chave (toggleSwitch).

    Modos: inline/click = um clique alterna; dblclick (padrão do <mad-col
    edit>) = duplo clique alterna (o clique simples não muda nada).
    Sem permissão de editar (editLockedTitle): chave desabilitada com o motivo.

    Variáveis: $col (GridColumn), $rowId, $cellVal (valor cru), $rendered
    (valor formatado da coluna — vai na dica e no leitor de tela).
==================================================================== --}}
@if(($col ?? null) instanceof \Mad\Grid\GridColumn)
@php
    $_swRowJs  = \Mad\Grid\MadDataGrid::rowIdJs($rowId ?? 0);
    $_swOn     = $col->switchIsOn($cellVal ?? null);
    $_swLocked = $col->editLockedTitle !== '';
    $_swDbl    = $col->editMode === 'dblclick';
    $_swField  = json_encode((string) $col->field, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $_swVals   = json_encode([(string) $col->editOn, (string) $col->editOff], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
    $_swLabel  = trim(strip_tags((string) $col->label));
    $_swText   = trim(html_entity_decode(strip_tags((string) ($rendered ?? '')), ENT_QUOTES));
    $_swTitle  = $_swLocked ? $col->editLockedTitle
        : ($_swDbl ? mad_t('mad.grid.switch_dblclick') : '');
@endphp
<div class="mad-dg-edit-switch" data-mad-no-row-click
     @if($_swDbl) @click.prevent="" @dblclick.prevent="toggleSwitch({{ $_swRowJs }}, {{ $_swField }}, {{ $_swVals }}, true)" @endif
     @if($_swTitle !== '') title="{{ $_swTitle }}" @endif>
    <label class="mad-toggle mad-toggle--sm mad-dg-switch{{ $_swDbl ? ' mad-dg-switch--dbl' : '' }}">
        <input type="checkbox" data-mad-dg-switch
               aria-label="{{ $_swLabel !== '' ? $_swLabel : $col->field }}{{ $_swText !== '' ? ': ' . $_swText : '' }}"
               @if($_swOn) checked @endif
               @if($_swLocked) disabled @endif
               @if($_swDbl)
               @keydown.enter.prevent="toggleSwitch({{ $_swRowJs }}, {{ $_swField }}, {{ $_swVals }}, true)"
               @else
               @change="toggleSwitch({{ $_swRowJs }}, {{ $_swField }}, {{ $_swVals }}, false)"
               @endif>
        <span class="mad-toggle-track"></span>
    </label>
</div>
@endif
