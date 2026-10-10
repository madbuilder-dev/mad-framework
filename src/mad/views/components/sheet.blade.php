@php
    // Template interno — só renderiza via MadSheet::_renderInlineSheet, que
    // injeta $__component. Sem host (render direto/preview), aborta cedo.
    if (!isset($__component) || !$__component instanceof \Mad\Sheet\MadSheet) {
        return;
    }
    $sheetKey = $__component->shCfgKey;
    $sheetCfg = [
        'key'     => $sheetKey,
        'columns' => $__component->getClientColumns(),
        'rowsMin' => $__component->getRowsMin(),
        'maxRows' => $__component->getMaxRows(),
        'totals'  => $__component->hasTotals(),
    ];
    $cfgJson = json_encode($sheetCfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
@endphp

{{-- x-data com aspas SIMPLES: o JSON estrutural usa aspas duplas (chaves), e
     JSON_HEX_APOS garante que nenhum apóstrofo cru escapa do atributo. --}}
<div class="mad-sheet" data-mad-sheet="{{ $sheetKey }}" x-data='madSheet({!! $cfgJson !!})'>

    {{-- Toolbar --}}
    <div class="mad-sheet-toolbar">
        <div class="mad-sheet-toolbar-left">
            <button type="button" class="mad-sheet-btn" @click="addRows(10)" :disabled="saving">
                <i data-lucide="plus"></i> {{ mad_t('mad.sheet.add_rows', ['count' => 10]) }}
            </button>
            <button type="button" class="mad-sheet-btn" @click="undo()" :disabled="saving || !undoStack.length">
                <i data-lucide="undo-2"></i> {{ mad_t('mad.sheet.undo') }}
            </button>
        </div>
        <div class="mad-sheet-toolbar-status">
            <span class="mad-sheet-count" x-show="dirtyCount > 0" x-cloak>
                <span x-text="dirtyCount"></span> {{ mad_t('mad.sheet.rows_filled') }}
            </span>
            <span class="mad-sheet-errors-badge" x-show="errorCount > 0" x-cloak>
                <span x-text="errorCount"></span> {{ mad_t('mad.sheet.errors') }}
            </span>
        </div>
        <div class="mad-sheet-toolbar-right">
            <button type="button" class="mad-sheet-btn" @click="validate()" :disabled="saving || dirtyCount === 0">
                <i data-lucide="check-check"></i> {{ mad_t('mad.sheet.validate') }}
            </button>
            <button type="button" class="mad-sheet-btn mad-sheet-btn--primary" @click="save()" :disabled="saving || dirtyCount === 0">
                <i data-lucide="save"></i> {{ mad_t('mad.sheet.save') }}
            </button>
        </div>
    </div>

    {{-- Grade --}}
    <div class="mad-sheet-scroll" x-ref="scroll" @scroll="onScroll()">
        <table class="mad-sheet-table">
            <colgroup>
                <col class="mad-sheet-col-rownum">
                <template x-for="col in cfg.columns" :key="col.field">
                    <col :style="col.width ? ('width:' + col.width) : ''">
                </template>
            </colgroup>
            <thead>
                <tr>
                    <th class="mad-sheet-rownum">#</th>
                    <template x-for="col in cfg.columns" :key="col.field">
                        <th>
                            <span x-text="col.label"></span><span class="mad-sheet-req" x-show="col.required">*</span>
                        </th>
                    </template>
                </tr>
            </thead>
            {{-- undo/limpeza de erro por delegação (focusin/focusout): os
                 cell-components escrevem em rows[r] por caminhos próprios
                 (popup do date, máscara do money) — hooks por input não cobrem.
                 inert enquanto o Salvar não responde: a resposta limpa a
                 planilha, e o que fosse digitado no intervalo sumia sem ser gravado. --}}
            <tbody @keydown="onKey($event)" @paste="onPaste($event)"
                   @focusin="onFocusIn($event)" @focusout="onFocusOut($event)"
                   :inert="saving">
                <tr class="mad-sheet-spacer" aria-hidden="true"><td :colspan="cfg.columns.length + 1" :style="'height:' + padTop + 'px;padding:0;border:0;'"></td></tr>
                {{-- :key inclui epoch: paste/undo/fill-down mutam rows por FORA
                     dos cell-components (madMoneyCell etc guardam estado próprio)
                     — epoch++ força re-init das células visíveis. --}}
                <template x-for="r in windowRows" :key="r + '.' + epoch">
                    {{-- x-effect registra deps reativas nas colunas compute:
                         digitou num campo referenciado → recalcula a linha. --}}
                    <tr x-effect="computeRow(r)">
                        <td class="mad-sheet-rownum">
                            <span x-text="r + 1"></span>
                            <button type="button" class="mad-sheet-row-clear" tabindex="-1"
                                    x-show="isRowFilled(rows[r])"
                                    @click="clearRow(r)"
                                    title="{{ mad_t('mad.sheet.clear_row') }}">&times;</button>
                        </td>
                        {{-- Células reusam os cell-components do framework (mesmos do
                             field-list): madMoneyCell, madNumericCell, madDatePicker.
                             Todos mutam rows[r] direto (row-proxy reativo). --}}
                        <template x-for="col in cfg.columns" :key="col.field">
                            <td :class="cellClass(r, col)" :title="cellError(r, col.field)">
                                <template x-if="col.type === 'combo' && !col.searchToken">
                                    {{-- data-mad-select: auto-mount global (mad-ui.js) enhança o
                                         select nativo pro MAD Select do dbcombo — dropdown com
                                         busca, teleportado pro body (fixed, sem clipping).
                                         selectOption → change bubbles no nativo → cellComboChanged. --}}
                                    <select class="mad-sheet-input" data-mad-select
                                            :data-r="r" :data-f="col.field"
                                            :disabled="col.readonly"
                                            :data-mad-noresults-payload="col.noResultsPayload || false"
                                            :value="cellGet(r, col.field)"
                                            @change="cellComboChanged(r, col, $event.target)">
                                        <option value=""></option>
                                        <template x-for="[optValue, optLabel] in Object.entries(col.options || {})" :key="optValue">
                                            <option :value="optValue" x-text="optLabel"
                                                    :selected="String(cellGet(r, col.field)) === String(optValue)"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="col.type === 'combo' && col.searchToken">
                                    {{-- Busca server-side (MadDbSearchService): data-mad-dbsearch
                                         liga o modo single-ajax do MAD Select (debounce 300ms,
                                         min-length; 0 = fetch-on-open p/ cascade). A config da
                                         query viaja SÓ no token criptografado. dep-value = valor
                                         da coluna-pai NESTA linha (cascade por linha). Option
                                         pré-selecionada preserva o label entre recriações
                                         (virtual scroll) via cache comboLabel. --}}
                                    <select class="mad-sheet-input" data-mad-dbsearch
                                            :data-r="r" :data-f="col.field"
                                            :disabled="col.readonly"
                                            :data-mad-search-token="col.searchToken"
                                            :data-min-length="col.searchMinLen"
                                            :data-placeholder="col.placeholder || false"
                                            :data-mad-dep-value="col.dependsOn ? String(cellGet(r, col.dependsOn) || '') : false"
                                            :data-mad-noresults-payload="col.noResultsPayload || false"
                                            @change="cellComboChanged(r, col, $event.target)">
                                        <option value=""></option>
                                        <template x-if="String(cellGet(r, col.field)) !== ''">
                                            <option :value="cellGet(r, col.field)" selected
                                                    x-text="comboLabel(col.field, cellGet(r, col.field))"></option>
                                        </template>
                                    </select>
                                </template>

                                <template x-if="col.type === 'money'">
                                    <div class="mad-sheet-cellwrap"
                                         x-data="madMoneyCell({ decimals: col.decimals, min: col.min, max: col.max, decimalSep: col.decimalSep, thousandSep: col.thousandSep, fillDirection: col.fillDirection, allowNegative: col.allowNegative, field: col.field, row: rows[r] })">
                                        <span class="mad-sheet-cell-affix" x-show="col.prefix" x-text="col.prefix"></span>
                                        <input type="text" inputmode="numeric" class="mad-sheet-input"
                                               x-ref="input"
                                               :data-r="r" :data-f="col.field"
                                               :readonly="col.readonly"
                                               :placeholder="col.placeholder"
                                               :value="display"
                                               @input="_onInput($event)"
                                               @blur="_onBlur()"
                                               @focus="madSelectOnFocus($event)">
                                        <span class="mad-sheet-cell-affix" x-show="col.suffix" x-text="col.suffix"></span>
                                    </div>
                                </template>

                                <template x-if="col.type === 'number'">
                                    <div class="mad-sheet-cellwrap"
                                         x-data="madNumericCell({ decimals: col.decimals, min: col.min, max: col.max, step: col.step, field: col.field, row: rows[r] })">
                                        <input type="text" inputmode="decimal" class="mad-sheet-input"
                                               x-ref="input"
                                               :data-r="r" :data-f="col.field"
                                               :readonly="col.readonly"
                                               :placeholder="col.placeholder"
                                               :value="display"
                                               @input="_onInput($event)"
                                               @focus="_onFocus($event)"
                                               @blur="_onBlur($event)">
                                    </div>
                                </template>

                                <template x-if="col.type === 'date'">
                                    <div class="mad-sheet-datewrap mad-input-group"
                                         x-data="madDatePicker({ displayMask: col.displayMask, databaseMask: col.databaseMask, min: col.min, max: col.max, initialValue: rows[r][col.field] || '', onCommit: function (iso) { rows[r][col.field] = iso; } })"
                                         x-init="$watch(() => rows[r][col.field], v => setIso(v))"
                                         {{-- popup vive num container overflow:auto — reposiciona
                                              como fixed (por cima de tudo, flip quando não cabe) --}}
                                         x-effect="if (isOpen) $nextTick(() => positionDatePopup($root))"
                                         @keydown.escape="close()"
                                         @click.outside="close()">
                                        <input type="text" class="mad-sheet-input"
                                               autocomplete="off" inputmode="numeric"
                                               :data-r="r" :data-f="col.field"
                                               :readonly="col.readonly"
                                               :placeholder="col.placeholder || 'dd/mm/aaaa'"
                                               @click="if (!col.readonly) open()">
                                        <button type="button" class="mad-sheet-date-btn" tabindex="-1"
                                                :disabled="col.readonly" @click="toggle()">
                                            {{-- SVG inline: linhas virtualizadas clonam depois do pass
                                                 do lucide — data-lucide ficaria sem render --}}
                                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/></svg>
                                        </button>
                                        @include('components.partials.date-popup', ['withTime' => false])
                                    </div>
                                </template>

                                <template x-if="col.type === 'text'">
                                    {{-- cellSetMasked: mask/force-case rodam AQUI (não no init
                                         global de mask do mad-ui — listener 1x no DOMContentLoaded
                                         perderia as células virtualizadas e correria com o @input). --}}
                                    <input type="text" class="mad-sheet-input"
                                           :data-r="r" :data-f="col.field"
                                           :readonly="col.readonly"
                                           :placeholder="col.placeholder"
                                           :maxlength="col.maxlength || false"
                                           :value="cellGet(r, col.field)"
                                           @input="cellSetMasked(r, col, $event)">
                                </template>
                            </td>
                        </template>
                    </tr>
                </template>
                <tr class="mad-sheet-spacer" aria-hidden="true"><td :colspan="cfg.columns.length + 1" :style="'height:' + padBottom + 'px;padding:0;border:0;'"></td></tr>
            </tbody>
            <tfoot x-show="cfg.totals" x-cloak>
                <tr>
                    <td class="mad-sheet-rownum">&Sigma;</td>
                    <template x-for="col in cfg.columns" :key="col.field">
                        <td class="mad-sheet-total" x-text="totalFor(col)"></td>
                    </template>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
