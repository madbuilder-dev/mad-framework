{{--
    Uma condição do filtro avançado ([e/ou] [coluna] [operador] [valor] [x]).
    Incluída duas vezes pelo data-grid-custom-filters.blade.php: no nível de
    cima e dentro de um grupo — nos dois casos dentro de um x-for com `(r, i)`.
    O e/ou da linha é o do NÍVEL dela (conjLabel/toggleMatch olham o grupo).
    Usa o `$_cfT` do arquivo que inclui (renderizada sozinha, cria o mesmo).
--}}
@php
    $_cfT ??= fn (string $k, array $r = []): string => \Mad\I18n\MadLang::t('mad.gridcf.' . $k, $r);
@endphp
<div class="mad-dg-cf-row" :class="{ 'is-invalid': !!invalid[r._id], 'is-flash': flashId === r._id }">
    <button type="button" class="mad-dg-cf-conj" :class="{ 'is-toggle': i > 0 }" :disabled="i === 0"
            @click="toggleMatch(r)" :title="i > 0 ? switchTitle(r) : ''" x-text="conjLabel(i, r)"></button>

    <div class="mad-dg-cf-fcell">
        <button type="button" class="mad-dg-cf-field" :id="domId('f', r)"
                aria-haspopup="listbox" :aria-expanded="menuIs('field', r) ? 'true' : 'false'"
                @click="toggleMenu('field', r)">
            <span class="mad-dg-cf-kind-slot" x-html="r.k ? kindIcon(def(r.k)) : ''"></span>
            <span class="mad-dg-cf-name" :class="{ 'is-placeholder': !r.k }" x-text="r.k ? def(r.k).label : cfT('choose_column')"></span>
            <span class="mad-dg-cf-caret"><i data-lucide="chevron-down"></i></span>
        </button>
    </div>

    <div class="mad-dg-cf-ocell">
        <select class="mad-dg-cf-select" :id="domId('o', r)" :disabled="!r.k"
                aria-label="{{ $_cfT('choose_operator') }}" @change="setOp(r, $event.target.value)">
            <template x-if="!r.k"><option value="">{{ $_cfT('choose_operator') }}</option></template>
            <template x-for="o in opsOf(r)" :key="o[0]">
                <option :value="o[0]" :selected="o[0] === r.op" x-text="o[1]"></option>
            </template>
        </select>
    </div>

    <div class="mad-dg-cf-val">
        <template x-if="shapeOf(r) === 'none'">
            <div class="mad-dg-cf-none" x-text="r.k ? cfT('no_value') : cfT('choose_column')"></div>
        </template>

        <template x-if="shapeOf(r) === 'bool'">
            <div class="mad-dg-cf-bool" role="group" aria-label="{{ $_cfT('value_placeholder') }}">
                <button type="button" :aria-pressed="String(r.v) === boolVal(r, true) ? 'true' : 'false'"
                        @click="r.v = boolVal(r, true)">{{ $_cfT('yes') }}</button>
                <button type="button" :aria-pressed="String(r.v) === boolVal(r, false) ? 'true' : 'false'"
                        @click="r.v = boolVal(r, false)">{{ $_cfT('no') }}</button>
            </div>
        </template>

        <template x-if="shapeOf(r) === 'preset'">
            <div class="mad-dg-cf-val-col">
                <select class="mad-dg-cf-select" :id="domId('v', r)" aria-label="{{ $_cfT('value_placeholder') }}"
                        @change="r.v = $event.target.value">
                    <template x-for="p in presets" :key="p[0]">
                        <option :value="p[0]" :selected="p[0] === r.v" x-text="p[1]"></option>
                    </template>
                </select>
                <div class="mad-dg-cf-hint" x-text="presetHint(r.v)"></div>
            </div>
        </template>

        <template x-if="shapeOf(r) === 'range-number' || shapeOf(r) === 'range-date'">
            <div class="mad-dg-cf-val-col">
                <div class="mad-dg-cf-pair">
                    <input class="mad-dg-cf-input" :class="{ 'is-need': !!invalid[r._id] }" :id="domId('v', r)"
                           :type="shapeOf(r) === 'range-date' ? 'date' : 'number'" inputmode="decimal" step="any"
                           x-model="r.v[0]" :placeholder="cfT('range_from')" aria-label="{{ $_cfT('range_from') }}"
                           @input="clearInvalid(r)" @keydown.enter.prevent="apply()">
                    <span class="mad-dg-cf-pair-sep">{{ $_cfT('and') }}</span>
                    <input class="mad-dg-cf-input" :class="{ 'is-need': !!invalid[r._id] }"
                           :type="shapeOf(r) === 'range-date' ? 'date' : 'number'" inputmode="decimal" step="any"
                           x-model="r.v[1]" :placeholder="cfT('to')" aria-label="{{ $_cfT('to') }}"
                           @input="clearInvalid(r)" @keydown.enter.prevent="apply()">
                </div>
                <div class="mad-dg-cf-err" x-show="!!invalid[r._id]" x-text="cfT('value_required')"></div>
            </div>
        </template>

        <template x-if="shapeOf(r) === 'tags'">
            <div class="mad-dg-cf-val-col">
                <div class="mad-dg-cf-tags" :class="{ 'is-need': !!invalid[r._id] }"
                     @click="if ($event.target === $el) $el.querySelector('input').focus()">
                    <template x-for="(tg, j) in r.v" :key="j + '|' + tg">
                        <span class="mad-dg-cf-tag">
                            <span class="mad-dg-cf-tag-txt" x-text="tg"></span>
                            <button type="button" @click="r.v.splice(j, 1)" :aria-label="cfT('remove_value', { value: tg })"><i data-lucide="x"></i></button>
                        </span>
                    </template>
                    <input class="mad-dg-cf-tag-input" :id="domId('v', r)" autocomplete="off"
                           :inputmode="def(r.k).kind === 'number' ? 'decimal' : 'text'"
                           :placeholder="r.v.length ? '' : cfT('tags_placeholder')" aria-label="{{ $_cfT('values_placeholder') }}"
                           @keydown="tagKey($event, r)" @paste="tagPaste($event, r)"
                           @input="tagInput($event, r)" @blur="tagBlur($event, r)">
                </div>
                <div class="mad-dg-cf-err" x-show="!!invalid[r._id]" x-text="cfT('value_required')"></div>
            </div>
        </template>

        <template x-if="shapeOf(r) === 'opt-single' || shapeOf(r) === 'opt-multi'">
            <div class="mad-dg-cf-val-col">
                <div class="mad-dg-cf-tags is-btn" :class="{ 'is-need': !!invalid[r._id] }" role="button" tabindex="0"
                     :id="domId('v', r)" aria-haspopup="listbox" :aria-expanded="menuIs('opt', r) ? 'true' : 'false'"
                     :aria-label="def(r.k).label"
                     @click="toggleMenu('opt', r)"
                     @keydown.enter.prevent="toggleMenu('opt', r)" @keydown.space.prevent="toggleMenu('opt', r)"
                     @keydown.arrow-down.prevent="openMenu('opt', r)">
                    <template x-for="x in selectedVals(r)" :key="x">
                        <span class="mad-dg-cf-tag">
                            <span class="mad-dg-cf-tag-txt" x-text="valueLabel(r, x)"></span>
                            <template x-if="shapeOf(r) === 'opt-multi'">
                                <button type="button" @click.stop="removeOpt(r, x)" :aria-label="cfT('remove_value', { value: valueLabel(r, x) })"><i data-lucide="x"></i></button>
                            </template>
                        </span>
                    </template>
                    <span class="mad-dg-cf-ph" x-show="selectedVals(r).length === 0"
                          x-text="shapeOf(r) === 'opt-multi' ? cfT('choose_values') : cfT('choose_value')"></span>
                </div>
                <div class="mad-dg-cf-err" x-show="!!invalid[r._id]" x-text="cfT('value_required')"></div>
            </div>
        </template>

        <template x-if="['text', 'number', 'date', 'datetime'].includes(shapeOf(r))">
            <div class="mad-dg-cf-val-col">
                <input class="mad-dg-cf-input" :class="{ 'is-need': !!invalid[r._id] }" :id="domId('v', r)"
                       :type="inputType(r)" :inputmode="shapeOf(r) === 'number' ? 'decimal' : null"
                       :step="shapeOf(r) === 'number' ? 'any' : null"
                       x-model="r.v" :placeholder="valuePlaceholder(r)" aria-label="{{ $_cfT('value_placeholder') }}"
                       autocomplete="off" @input="clearInvalid(r)" @keydown.enter.prevent="apply()">
                <div class="mad-dg-cf-err" x-show="!!invalid[r._id]" x-text="cfT('value_required')"></div>
            </div>
        </template>
    </div>

    <button type="button" class="mad-dg-cf-del" @click="removeRow(r)" aria-label="{{ $_cfT('remove') }}" title="{{ $_cfT('remove') }}">
        <i data-lucide="trash-2"></i>
    </button>
</div>
