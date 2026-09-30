{{--
    Filtro avançado do grid (<mad-custom-filters>) — botão da toolbar + popover.

    Incluído pelo data-grid.blade.php só quando `$customFilters['enabled']`. O
    botão é desenhado AQUI (servidor): contagem, nome do filtro salvo e rótulo
    só mudam num redesenho. O popover é do Alpine (`madCustomFilter`, mad-ui.js)
    e fica num <template x-teleport="body"> — escapa do overflow da grid e da
    página; o MadWire acha o componente dono pelo rastro do teleporte.

    O rascunho é 100% local: montar/editar condições não fala com o servidor.
    Uma chamada no Aplicar (onCustomFilterApply) — o servidor revalida tudo
    contra as defs. O config vai para o Alpine como literal JSON dentro do
    x-data: JSON_HEX_* tira `<`, `>`, `&`, `'` e `"` de DENTRO dos valores
    (viram escapes \u00XX) e o `{{ }}` escapa as aspas estruturais do JSON —
    um rótulo com `</script>` ou aspas não fecha o atributo.

    Variáveis com prefixo `$_cf`: o MadRenderContext achata as chaves das props
    públicas array da TELA no contexto da view — nome genérico aqui (`$label`,
    `$max`) poderia ser sequestrado por uma prop da tela. As props do próprio
    filtro já ficam fora do contexto (`_viewContextExcludedProps`).
--}}
@php
    $_cf   = (array) ($customFilters ?? []);
    $_cfT  = fn (string $k, array $r = []): string => \Mad\I18n\MadLang::t('mad.gridcf.' . $k, $r);
    $_cfId = 'mad-dg-cf-' . substr(md5((string) ($storageKey ?? '') . '|' . (string) ($gridClass ?? '')), 0, 10);

    // Mapas → lista de pares [valor, rótulo]: objeto JS reordena chave numérica
    // (ids do dbcombo sairiam por id, não na ordem do servidor).
    $_cfPairs = function ($map): array {
        $out = [];
        foreach ((array) $map as $k => $v) {
            if (is_scalar($v) || $v === null) $out[] = [(string) $k, (string) $v];
        }
        return $out;
    };
    // Relação em camelCase (`categoriaCliente`) vira "Categoria cliente", igual
    // ao snake_case: é o título do grupo na lista de colunas do usuário.
    $_cfHuman = function (string $seg): string {
        $s = preg_replace_callback('/([a-z0-9])([A-Z])/', fn ($m) => $m[1] . ' ' . strtolower($m[2]), $seg);
        $s = str_replace('_', ' ', $s);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    };

    $_cfDefs = [];
    foreach ((array) ($_cf['defs'] ?? []) as $_d) {
        if (!is_array($_d) || !isset($_d['key'], $_d['kind'])) continue;
        $_segs = explode('->', (string) $_d['key']);
        array_pop($_segs);
        $_cd = [
            'key'         => (string) $_d['key'],
            'label'       => (string) ($_d['label'] ?? $_d['key']),
            'kind'        => (string) $_d['kind'],
            'ops'         => array_map(
                fn ($op) => [(string) $op, (string) (($_d['opLabels'] ?? [])[$op] ?? $op)],
                array_values((array) ($_d['ops'] ?? []))
            ),
            'placeholder' => (string) ($_d['placeholder'] ?? ''),
            // Colunas de relação: agrupadas pela tabela de origem, caminho em mono.
            'group'       => $_segs ? implode(' → ', array_map($_cfHuman, $_segs)) : '',
            'path'        => implode(' → ', explode('->', (string) $_d['key'])),
        ];
        if (array_key_exists('true', $_d)) {
            $_cd['true']  = (string) $_d['true'];
            $_cd['false'] = (string) ($_d['false'] ?? '0');
        }
        if ($_d['kind'] === 'select' || $_d['kind'] === 'bool') {
            $_cd['opts'] = $_cfPairs($_d['opts'] ?? []);
        } elseif ($_d['kind'] === 'dbcombo') {
            // dbcombo: options já carregadas — mesma lista pesquisável do select.
            $_cd['opts'] = $_cfPairs($_d['options'] ?? []);
        } elseif ($_d['kind'] === 'dbsearch') {
            // dbsearch: busca lazy no MadDbSearchService (mesmo token do filtro de coluna).
            $_cd['search']    = (string) ($_d['search'] ?? '');
            $_cd['minLength'] = (int) ($_d['minLength'] ?? 2);
        }
        $_cfDefs[] = $_cd;
    }

    $_cfI18n = [];
    foreach ([
        'choose_column', 'search_column', 'no_columns', 'choose_operator', 'value_placeholder',
        'search_placeholder', 'yes', 'no', 'and', 'or', 'where', 'switch_match', 'no_value',
        'value_required', 'choose_value', 'choose_values', 'tags_placeholder', 'range_from', 'to',
        'remove_value', 'records_one', 'records_many', 'columns_count', 'nothing_found',
        'selected_one', 'selected_many', 'min_chars', 'min_chars_one', 'loading', 'condition_one', 'active_count',
        'opens_with', 'shared_badge', 'open_with', 'open_with_off', 'suggest_recent', 'suggest_yes',
        'suggest_in', 'max_reached', 'nothing_to_save', 'name_invalid', 'match_all', 'match_any',
    ] as $_k) {
        $_cfI18n[$_k] = $_cfT($_k);
    }

    $_cfTotal = $cfTotal ?? null;
    $_cfClient = [
        'id'         => $_cfId,
        'defs'       => $_cfDefs,
        'match'      => (string) ($_cf['state']['match'] ?? $_cf['match'] ?? 'all'),
        'rules'      => array_values((array) ($_cf['state']['rules'] ?? [])),
        'labels'     => (array) ($_cf['labels'] ?? []),
        'viewId'     => $_cf['viewId'] ?? null,
        'viewName'   => (string) ($_cf['viewName'] ?? ''),
        'max'        => (int) ($_cf['max'] ?? 15),
        'save'       => (string) ($_cf['save'] ?? 'off'),
        'canSave'    => !empty($_cf['canSave']),
        'canShare'   => !empty($_cf['canShare']),
        'saved'      => [
            'mine'   => array_values((array) ($_cf['saved']['mine'] ?? [])),
            'shared' => array_values((array) ($_cf['saved']['shared'] ?? [])),
        ],
        'presets'    => $_cfPairs($_cf['presets'] ?? []),
        'noValueOps' => array_values((array) ($_cf['noValueOps'] ?? [])),
        'listOps'    => array_values((array) ($_cf['listOps'] ?? [])),
        'rangeOps'   => array_values((array) ($_cf['rangeOps'] ?? [])),
        // Total da grid com o filtro EM VIGOR — o "Aplicar · N" do rascunho
        // intocado sai sem ir ao servidor. null = grid ainda sem carga.
        'total'      => is_numeric($_cfTotal) ? (int) $_cfTotal : null,
        'dateFormat' => \Mad\I18n\MadLang::t('mad.tempo.date_format'),
        'i18n'       => $_cfI18n,
    ];
    $_cfJson = json_encode(
        $_cfClient,
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
        | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
    ) ?: '{}';

    $_cfCount = (int) ($_cf['count'] ?? 0);
    $_cfLabel = (string) (($_cf['label'] ?? '') !== '' ? $_cf['label'] : $_cfT('button'));
    $_cfView  = ($_cf['viewId'] ?? null) !== null && (string) ($_cf['viewName'] ?? '') !== '';
    $_cfAria  = $_cfCount === 0
        ? $_cfLabel
        : ($_cfCount === 1
            ? $_cfT('trigger_aria_one', ['label' => $_cfLabel])
            : $_cfT('trigger_aria_many', ['label' => $_cfLabel, 'n' => $_cfCount]));
    $_cfSaveOn = ($_cf['save'] ?? 'off') !== 'off' && !empty($_cf['canSave']);
@endphp
<div class="mad-dg-cf mad-dg-cf-host" data-cf-id="{{ $_cfId }}" x-data="madCustomFilter({{ $_cfJson }})">
    <button type="button" id="{{ $_cfId }}-btn"
            class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-cf-trigger{{ $_cfCount > 0 ? ' is-active' : '' }}"
            aria-haspopup="dialog" aria-expanded="false" :aria-expanded="cfOpen ? 'true' : 'false'"
            aria-controls="{{ $_cfId }}-pop" aria-label="{{ $_cfAria }}"
            @if($_cfView) title="{{ $_cf['viewName'] }}" @endif
            @click="toggle()">
        @if($_cfView)
        <i data-lucide="star" class="mad-dg-cf-trigger-ic"></i>
        <span class="mad-dg-cf-view-name">{{ $_cf['viewName'] }}</span>
        @else
        <i data-lucide="filter" class="mad-dg-cf-trigger-ic"></i>
        <span class="mad-dg-cf-trigger-label">{{ $_cfLabel }}</span>
        @endif
        @if($_cfCount > 0)
        <span class="mad-dg-cf-count">{{ $_cfCount }}</span>
        @endif
    </button>

    <template x-teleport="body">
    <div class="mad-dg-cf mad-dg-cf-layer" x-show="cfOpen" x-cloak>
        <div class="mad-dg-cf-scrim" x-show="sheet" @click="close()"></div>
        <div class="mad-dg-cf-pop" :class="{ 'is-sheet': sheet }" id="{{ $_cfId }}-pop"
             role="dialog" aria-modal="false" :aria-modal="sheet ? 'true' : 'false'"
             aria-labelledby="{{ $_cfId }}-title" @keydown="onKey($event)">
            <div class="mad-dg-cf-grab" aria-hidden="true"></div>

            {{-- ── Cabeçalho: título, filtro salvo em uso, todas|qualquer uma ── --}}
            <div class="mad-dg-cf-head">
                <div class="mad-dg-cf-head-top">
                    <span class="mad-dg-cf-title" id="{{ $_cfId }}-title">{{ $_cfT('title') }}</span>
                    <span class="mad-dg-cf-view-tag" x-show="viewName !== ''" x-cloak>
                        <i data-lucide="star"></i>
                        <span class="mad-dg-cf-view-tag-name" x-text="viewName"></span>
                        <span class="mad-dg-cf-mod" x-show="modified()">· {{ $_cfT('modified') }}</span>
                    </span>
                    <button type="button" class="mad-dg-cf-icon-btn mad-dg-cf-close" @click="close()"
                            aria-label="{{ $_cfT('close') }}">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <div class="mad-dg-cf-match" role="group" aria-label="{{ $_cfT('match_prefix') }} … {{ $_cfT('match_suffix') }}">
                    <span>{{ $_cfT('match_prefix') }}</span>
                    <span class="mad-dg-cf-seg">
                        <button type="button" :aria-pressed="draft.match === 'all' ? 'true' : 'false'" @click="setMatch('all')">{{ $_cfT('match_all') }}</button>
                        <button type="button" :aria-pressed="draft.match === 'any' ? 'true' : 'false'" @click="setMatch('any')">{{ $_cfT('match_any') }}</button>
                    </span>
                    <span>{{ $_cfT('match_suffix') }}</span>
                </div>
            </div>

            {{-- ── Condições ── --}}
            <div class="mad-dg-cf-body" id="{{ $_cfId }}-body">
                <template x-for="(r, i) in draft.rules" :key="r._id">
                    <div class="mad-dg-cf-row" :class="{ 'is-invalid': !!invalid[r._id], 'is-flash': flashId === r._id }">
                        <button type="button" class="mad-dg-cf-conj" :class="{ 'is-toggle': i > 0 }" :disabled="i === 0"
                                @click="toggleMatch()" :title="i > 0 ? switchTitle() : ''" x-text="conjLabel(i)"></button>

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
                </template>

                {{-- Estado vazio: sugestões derivadas das colunas declaradas --}}
                <div class="mad-dg-cf-empty" x-show="draft.rules.length === 0">
                    <div class="mad-dg-cf-empty-ic"><i data-lucide="filter"></i></div>
                    <b>{{ $_cfT('empty_title') }}</b>
                    <p>{{ $_cfT('empty_hint') }}</p>
                    <div class="mad-dg-cf-suggest">
                        <template x-for="s in suggestions" :key="s.id">
                            <button type="button" @click="useSuggestion(s)" x-text="s.label"></button>
                        </template>
                    </div>
                </div>

                <button type="button" class="mad-dg-cf-add" :class="{ 'is-centered': draft.rules.length === 0 }"
                        :disabled="draft.rules.length >= max" :title="draft.rules.length >= max ? cfT('max_reached', { max: max }) : ''"
                        @click="addRule()">
                    <i data-lucide="plus"></i><span>{{ $_cfT('add_condition') }}</span>
                </button>
            </div>

            {{-- ── Rodapé: filtros salvos, limpar, aplicar (· N registros) ── --}}
            <div class="mad-dg-cf-foot">
                @if($_cfSaveOn)
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-cf-saved-toggle"
                        aria-haspopup="menu" :aria-expanded="savedOpen ? 'true' : 'false'" @click="toggleSaved()">
                    <i data-lucide="bookmark"></i><span>{{ $_cfT('my_filters') }}</span><i data-lucide="chevron-down"></i>
                </button>
                @endif
                <div class="mad-dg-cf-grow"></div>
                <span class="mad-dg-cf-zero" x-show="countShown() && countVal === 0" x-cloak>
                    <i data-lucide="circle-alert"></i><span>{{ $_cfT('no_records') }}</span>
                </span>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-dg-cf-clear" @click="clearAll()">{{ $_cfT('clear') }}</button>
                <button type="button" class="mad-btn mad-btn-primary mad-btn-sm mad-dg-cf-apply" @click="apply()">
                    <span>{{ $_cfT('apply') }}</span>
                    <span class="mad-dg-cf-apply-count" x-show="countShown()" x-cloak x-text="countText()"></span>
                </button>
                <span class="mad-dg-cf-kbd" title="{{ $_cfT('shortcut_apply') }}" x-text="kbd"></span>

                @if($_cfSaveOn)
                <div class="mad-dg-cf-saved" x-show="savedOpen" x-cloak role="menu" aria-label="{{ $_cfT('saved_filters') }}">
                    <div class="mad-dg-cf-menu-list">
                        <div class="mad-dg-cf-menu-group">{{ $_cfT('my_filters') }}</div>
                        <template x-for="v in saved.mine" :key="'m' + v.id">
                            <div class="mad-dg-cf-saved-item" :class="{ 'is-current': v.id === viewId }" role="menuitem" tabindex="0"
                                 @click="applySaved(v)" @keydown.enter.prevent="applySaved(v)">
                                <span class="mad-dg-cf-star" :class="{ 'is-on': v.isDefault }"><i data-lucide="star"></i></span>
                                <span class="mad-dg-cf-saved-nm">
                                    <span class="mad-dg-cf-saved-name" x-text="v.name"></span>
                                    <span class="mad-dg-cf-saved-meta" x-text="savedMeta(v)"></span>
                                </span>
                                <span class="mad-dg-cf-saved-acts">
                                    <template x-if="v.canDefault">
                                        <button type="button" @click.stop="toggleDefault(v)"
                                                :aria-label="v.isDefault ? cfT('open_with_off') : cfT('open_with')"
                                                :title="v.isDefault ? cfT('open_with_off') : cfT('open_with')"><i data-lucide="star"></i></button>
                                    </template>
                                    <template x-if="v.canEdit">
                                        <button type="button" class="is-danger" @click.stop="deleteSaved(v)"
                                                aria-label="{{ $_cfT('delete') }}" title="{{ $_cfT('delete') }}"><i data-lucide="trash-2"></i></button>
                                    </template>
                                </span>
                            </div>
                        </template>
                        <div class="mad-dg-cf-menu-empty" x-show="saved.mine.length === 0">{{ $_cfT('no_saved') }}</div>

                        <div class="mad-dg-cf-menu-group" x-show="saved.shared.length > 0"><i data-lucide="users"></i><span>{{ $_cfT('shared_filters') }}</span></div>
                        <template x-for="v in saved.shared" :key="'s' + v.id">
                            <div class="mad-dg-cf-saved-item" :class="{ 'is-current': v.id === viewId }" role="menuitem" tabindex="0"
                                 @click="applySaved(v)" @keydown.enter.prevent="applySaved(v)">
                                <span class="mad-dg-cf-star is-shared"><i data-lucide="users"></i></span>
                                <span class="mad-dg-cf-saved-nm">
                                    <span class="mad-dg-cf-saved-name" x-text="v.name"></span>
                                    <span class="mad-dg-cf-saved-meta" x-text="savedMeta(v)"></span>
                                </span>
                                <span class="mad-dg-cf-saved-acts">
                                    <template x-if="v.canEdit">
                                        <button type="button" class="is-danger" @click.stop="deleteSaved(v)"
                                                aria-label="{{ $_cfT('delete') }}" title="{{ $_cfT('delete') }}"><i data-lucide="trash-2"></i></button>
                                    </template>
                                </span>
                            </div>
                        </template>
                    </div>

                    <form class="mad-dg-cf-save-form" x-show="saveForm" @submit.prevent="submitSave()" novalidate>
                        <label class="mad-dg-cf-save-label">
                            <span>{{ $_cfT('filter_name') }}</span>
                            <input class="mad-dg-cf-input" id="{{ $_cfId }}-svname" maxlength="120" autocomplete="off"
                                   x-model="saveName" placeholder="{{ $_cfT('name_placeholder') }}">
                        </label>
                        @if(!empty($_cf['canShare']))
                        <label class="mad-dg-cf-check">
                            <input type="checkbox" x-model="saveShare">
                            <span>{{ $_cfT('share_with_all') }}<small>{{ $_cfT('share_hint') }}</small></span>
                        </label>
                        @endif
                        <label class="mad-dg-cf-check">
                            <input type="checkbox" x-model="saveDefault">
                            <span>{{ $_cfT('open_with') }}@if(!empty($_cf['canShare']))<small x-show="saveShare && saveDefault" x-cloak>{{ $_cfT('open_with_shared_hint') }}</small>@endif</span>
                        </label>
                        <div class="mad-dg-cf-err" x-show="saveError !== ''" x-text="saveError"></div>
                        <div class="mad-dg-cf-save-row">
                            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" @click="saveForm = false">{{ $_cfT('cancel') }}</button>
                            <button type="submit" class="mad-btn mad-btn-primary mad-btn-sm">{{ $_cfT('save') }}</button>
                        </div>
                    </form>
                    <button type="button" class="mad-dg-cf-save-new" x-show="!saveForm"
                            :disabled="!hasComplete()" :title="hasComplete() ? '' : cfT('nothing_to_save')"
                            @click="openSaveForm()">
                        <i data-lucide="plus"></i><span>{{ $_cfT('save_current') }}</span>
                    </button>
                </div>
                @endif
            </div>

            {{-- ── Lista flutuante: escolha de coluna / de valores ── --}}
            <div class="mad-dg-cf-menu" id="{{ $_cfId }}-menu" x-show="menu !== null" x-cloak>
                <div class="mad-dg-cf-menu-search" x-show="menuHasSearch()">
                    <i data-lucide="search"></i>
                    <input id="{{ $_cfId }}-menuq" autocomplete="off" :value="menu ? menu.q : ''"
                           :placeholder="menu && menu.type === 'field' ? cfT('search_column') : cfT('search_placeholder')"
                           :aria-label="menu && menu.type === 'field' ? cfT('search_column') : cfT('search_placeholder')"
                           aria-controls="{{ $_cfId }}-menulist"
                           @input="menuSearch($event.target.value)" @keydown="menuKey($event)">
                </div>
                <div class="mad-dg-cf-menu-list" id="{{ $_cfId }}-menulist" role="listbox" @keydown="menuKey($event)"
                     :aria-multiselectable="menuMulti() ? 'true' : 'false'">
                    <template x-for="g in menuGroups()" :key="g.id">
                        <div class="mad-dg-cf-menu-grp">
                            <div class="mad-dg-cf-menu-group" x-show="g.label !== ''">
                                <span class="mad-dg-cf-menu-group-ic"><i data-lucide="link"></i></span><span x-text="g.label"></span>
                            </div>
                            <template x-for="it in g.items" :key="it.key">
                                <button type="button" class="mad-dg-cf-opt" :class="{ 'is-active': menu && menu.active === it.idx }"
                                        role="option" :aria-selected="it.selected ? 'true' : 'false'" tabindex="-1"
                                        @click="pickKey(it.key)" @mousemove="if (menu) menu.active = it.idx">
                                    <span class="mad-dg-cf-chk" x-show="it.multi" :class="{ 'is-on': it.selected }"><i data-lucide="check"></i></span>
                                    <span class="mad-dg-cf-kind-slot" x-show="it.icon !== ''" x-html="it.icon"></span>
                                    <span class="mad-dg-cf-opt-label" x-text="it.label"></span>
                                    <span class="mad-dg-cf-opt-meta" x-show="it.meta !== ''" x-text="it.meta"></span>
                                    <span class="mad-dg-cf-opt-check" x-show="!it.multi && it.selected"><i data-lucide="check"></i></span>
                                </button>
                            </template>
                        </div>
                    </template>
                    <div class="mad-dg-cf-menu-empty" x-show="menuEmptyText() !== ''" x-text="menuEmptyText()"></div>
                </div>
                <div class="mad-dg-cf-menu-foot" x-show="menu !== null && (menu.type === 'field' || menuMulti())">
                    <span x-text="menuFootText()"></span>
                    <span x-show="menu && menu.type === 'field'"><span class="mad-dg-cf-kbd">↑↓</span> <span class="mad-dg-cf-kbd">Enter</span></span>
                    <button type="button" class="mad-dg-cf-link" x-show="menuMulti()" @click="closeMenu(true)">{{ $_cfT('done') }}</button>
                </div>
            </div>
        </div>
    </div>
    </template>
</div>
