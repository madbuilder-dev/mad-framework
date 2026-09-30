@php
    /**
     * <mad-pivot-table> — Componente pivot table auto-contido.
     *
     * Replica a logica do BMadTable como componente Blade declarativo.
     * Carrega dados via ORM, monta config e renderiza iframe com
     * o React pivot table em manager.madbuilder.com.br/mad-table.
     *
     * Zero codigo PHP no controller — tudo declarativo no Blade.
     *
     * Props:
     *   model             string      ''            Classe ORM (ex: 'PedidoVenda')
     *   database          string      MAIN_DATABASE Conexao DB
     *   filters           array       []            Filtros inline [['campo','op','val'], ...]
     *   joins             array       []            Joins keyed by table: ['tabela' => ['fk','pk']]
     *                                                ou ['tabela' => ['fk','op','pk']]. FK sem `.`
     *                                                qualifica na base; PK qualifique explícito
     *                                                (`tabela.pk`). Ex: ['vendedor' =>
     *                                                ['pedido_venda.vendedor_id','vendedor.id']]
     *   data              array       null          Dados manuais (dispensa model)
     *   transformers      array       []            ['campo' => callable] transformers
     *   pivot_fields      array       []            Campos (compilado das child tags)
     *   title             string      ''
     *   subtitle          string      ''
     *   height            int|string  600           Altura (px ou string com unidade)
     *   width             string      '100%'
     *   no-panel          bool        false         Sem card wrapper
     *   class             string      ''            CSS extra
     *   no-data-label     string      'Sem dados para exibir'
     *   field-list        bool        false         Mostra lista de campos drag-and-drop
     *   field-list-layout string      'horizontal'  horizontal|vertical-left|vertical-right
     *   virtual-scrolling bool        true
     *   rows-per-page     int         50
     *   subtotals         bool        false
     *   subtotals-position string     'below'       above|below
     *   grand-totals      bool        true
     *   row-totals        bool        true
     *   column-totals     bool        true
     *   language          string      'pt-BR'
     *   currency          string      'BRL'
     *   locale            string      'pt-BR'
     *   theme             string      ''            default|dark|compact
     *   compact           bool        false
     *   presets           array       []
     */

    // ── Identificacao ──────────────────────────────────────────────────────
    $name = 'madpivot_' . uniqid();

    // ── Fonte ORM ──────────────────────────────────────────────────────────
    $model    = $model    ?? '';
    $database = $database ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $filters  = $filters  ?? [];
    $joins    = $joins    ?? [];
    $data     = $data     ?? null;
    $transformers = $transformers ?? [];

    // ── Campos (vindos do compiler) ────────────────────────────────────────
    $pivot_fields = $pivotFields ?? $pivot_fields ?? [];

    // ── Visual ─────────────────────────────────────────────────────────────
    $title       = $title       ?? '';
    $subtitle    = $subtitle    ?? '';
    $height      = $height      ?? 600;
    $width       = \Mad\Support\CssUnits::length((string) ($width ?? ''), '100%');
    $noPanel     = $no_panel    ?? $noPanel ?? false;
    $class       = $class       ?? '';
    $noDataLabel = $no_data_label ?? $noDataLabel ?? 'Sem dados para exibir';

    // ── Config ─────────────────────────────────────────────────────────────
    $fieldList        = $field_list        ?? $fieldList        ?? false;
    $fieldListLayout  = $field_list_layout ?? $fieldListLayout  ?? 'horizontal';
    $virtualScrolling = $virtual_scrolling ?? $virtualScrolling ?? true;
    $rowsPerPage      = $rows_per_page     ?? $rowsPerPage      ?? 50;
    $subtotals        = $subtotals        ?? false;
    $subtotalsPosition = $subtotals_position ?? $subtotalsPosition ?? 'below';
    $grandTotals      = $grand_totals     ?? $grandTotals      ?? true;
    $rowTotals        = $row_totals       ?? $rowTotals        ?? true;
    $columnTotals     = $column_totals    ?? $columnTotals     ?? true;
    $language         = $language         ?? 'pt-BR';
    $defaultCurrency  = $currency         ?? 'BRL';
    $defaultLocale    = $locale           ?? 'pt-BR';
    $theme            = $theme            ?? null;
    $compact          = $compact          ?? false;
    $presets          = $presets          ?? [];

    // F5: :filters aplicados via QuerySource::applyArrayFilters no builder abaixo (100% Query Builder).

    // ── Normalizar pivot fields: field→id, label→name ─────────────────────
    $normalizeField = function (array $pf) use ($defaultCurrency, $defaultLocale): array {
        $cf = [];
        $cf['id']   = $pf['field'] ?? $pf['id'] ?? '';
        $cf['name'] = $pf['label'] ?? $pf['name'] ?? $cf['id'];
        $cf['type'] = $pf['type']  ?? 'string';
        $cf['area'] = $pf['area']  ?? 'rows';
        $cf['order'] = (int)($pf['order'] ?? 0);

        $columnName = $cf['id'];
        if (stripos($columnName, ' as ') !== false) {
            $aliasParts = preg_split('/\s+as\s+/i', $columnName);
            $cf['id'] = trim($aliasParts[1]);
        }
        $cf['_columnName'] = $columnName;

        if (!empty($pf['mask'])) {
            $cf['_mask'] = $pf['mask'];
        }

        if ($cf['area'] === 'values') {
            $cf['aggregation'] = $pf['aggregation'] ?? 'sum';
            $cf['format'] = $pf['format'] ?? ($cf['type'] === 'number' ? 'number' : 'string');
            if (is_string($cf['format']) && stripos($cf['format'], 'currency-') === 0) {
                $cf['currency'] = strtoupper(substr($cf['format'], 9));
                $cf['format']   = 'currency';
            } elseif (is_string($cf['format']) && stripos($cf['format'], 'currency:') === 0) {
                $cf['currency'] = strtoupper(substr($cf['format'], 9));
                $cf['format']   = 'currency';
            }
            if ($cf['format'] === 'currency' && !isset($cf['currency'])) {
                $cf['currency'] = $pf['currency'] ?? $defaultCurrency;
            }
            $cf['locale'] = $pf['locale'] ?? $defaultLocale;
        }

        if ($cf['type'] === 'date' || $cf['type'] === 'datetime') {
            if (!empty($pf['dateFormat'])) $cf['format'] = $pf['dateFormat'];
            if (!isset($cf['locale'])) $cf['locale'] = $pf['locale'] ?? $defaultLocale;
        }

        if (!empty($pf['transformer'])) $cf['transformer'] = $pf['transformer'];
        if (!empty($pf['total']))       $cf['total']       = $pf['total'];
        if (!empty($pf['sort']))        $cf['sort']        = $pf['sort'];

        return $cf;
    };

    $configFields = [];
    foreach ($pivot_fields as $pf) {
        $configFields[] = $normalizeField($pf);
    }

    // Normaliza fields dentro de cada preset (cada preset = view)
    if (!empty($presets) && is_array($presets)) {
        foreach ($presets as &$_preset) {
            if (isset($_preset['fields']) && is_array($_preset['fields'])) {
                $normalized = [];
                foreach ($_preset['fields'] as $pf) {
                    if (!is_array($pf)) continue;
                    $nf = $normalizeField($pf);
                    // Limpa keys internas `_*` do payload enviado ao frontend.
                    foreach (array_keys($nf) as $k) {
                        if ($k[0] === '_') unset($nf[$k]);
                    }
                    $normalized[] = $nf;
                }
                $_preset['fields'] = $normalized;
            }
        }
        unset($_preset);
    }

    // ── Montar config ──────────────────────────────────────────────────────
    $config = [
        'fields'            => [],
        'filters'           => [],
        'sorts'             => [],
        'conditionalFormats' => [],
        'subtotals'         => [
            'enabled'  => $subtotals,
            'position' => $subtotalsPosition,
            'fields'   => [],
        ],
        'grandTotals'       => [
            'rowTotals'    => $rowTotals,
            'columnTotals' => $columnTotals,
            'position'     => ['row' => 'bottom', 'column' => 'right'],
        ],
        'showFieldList'     => $fieldList,
        'fieldListLayout'   => $fieldListLayout,
        'virtualScrolling'  => $virtualScrolling,
        'rowsPerPage'       => (int)$rowsPerPage,
        'language'          => $language,
        'presets'           => $presets,
    ];

    if ($theme)   $config['theme'] = $theme;
    if ($compact) {
        $config['style'] = ['compact' => true];
    }

    // Fields para frontend (sem prefixados com `_` — uso interno do PHP).
    $config['fields'] = array_map(function ($f) {
        $clean = [];
        foreach ($f as $k => $v) {
            if ($k[0] !== '_') $clean[$k] = $v;
        }
        return $clean;
    }, $configFields);

    // ── Carregar dados (replica BMadTable::loadData) ───────────────────────
    $pivotData  = [];
    $pivotError = null;

    if ($data !== null) {
        $pivotData = $data;
    } elseif ($model && !empty($configFields)) {
        try {
            // Pelo Eloquent, com os global scopes do model (unidade/tenant do
            // Multi-unidade, soft delete) — a tabela crua (DB::table) listava os
            // registros de TODAS as unidades num SaaS. Conexão = `database`.
            $__pmClass = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $q = \Mad\Database\QuerySource::modelBaseQuery($__pmClass, $database);
            $q->columns = null;
            $entity = is_string($q->from) ? $q->from : (new $__pmClass)->getTable();

            // Joins reais (keys = nomes das tabelas, padrão BChart)
            if (!empty($joins)) {
                foreach ($joins as $joinTable => $join) {
                    $key = $join[0] ?? '';
                    if (strpos($key, '.') === false) {
                        $key = "{$entity}.{$key}";
                    }
                    if (count($join) > 2) {
                        $operator = $join[1];
                        $value    = $join[2];
                    } else {
                        $operator = '=';
                        $value    = $join[1];
                    }
                    if (strpos($value, '.') === false) {
                        $value = "{$entity}.{$value}";
                    }
                    $q->join($joinTable, $key, $operator, $value);
                }
            }

            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($q, $filters);
            }

            // Extract unique fields by (columnName, mask) — fields com mask diferente
            // viram colunas SQL distintas porque retornam valores diferentes.
            $uniqueFields = [];
            foreach ($configFields as $cf) {
                $colName = $cf['_columnName'];
                $mask    = $cf['_mask'] ?? '';
                $key     = $mask !== '' ? $colName . '|MASK|' . $mask : $colName;
                if (!isset($uniqueFields[$key])) {
                    $uniqueFields[$key] = ['id' => $cf['id'], 'columnName' => $colName, 'mask' => $mask];
                }
            }

            foreach ($uniqueFields as $uf) {
                $colName = $uf['columnName'];
                $colId   = $uf['id'];
                $mask    = $uf['mask'];

                if (strpos($colName, '.') === false && strpos($colName, ':') === false
                    && strpos($colName, '(') === false && stripos($colName, ' as ') === false) {
                    $colName = "{$entity}.{$colName}";
                }

                if ($mask !== '') {
                    // `{$c}` no template é substituído pelo nome qualificado da coluna.
                    $expr = str_replace('{$c}', $colName, $mask);
                    $q->selectRaw("{$expr} as \"{$colId}\"");
                } elseif (stripos($colName, ' as ') === false) {
                    $q->selectRaw("{$colName} as \"{$colId}\"");
                } else {
                    $q->selectRaw($colName);
                }
            }

            foreach ($q->get() as $rowObj) {
                $row = (array) $rowObj;
                if (!empty($transformers)) {
                    foreach ($transformers as $tField => $transformer) {
                        if (isset($row[$tField])) {
                            $row[$tField] = call_user_func($transformer, $row[$tField], $row);
                        }
                    }
                }
                $pivotData[] = $row;
            }
        } catch (\Throwable $e) {
            $pivotError = \Mad\Ui\MadUserError::message($e, mad_t('mad.error.load_failed'), 'mad-pivot-table');
        }
    }

    $hasData   = !empty($pivotData);
    $heightPx  = \Mad\Support\CssUnits::length((string) $height, '600px');   // 600 = default da prop (:64)
    $dataJson  = json_encode($pivotData, JSON_UNESCAPED_UNICODE);
    $configJson = json_encode($config, JSON_UNESCAPED_UNICODE);
@endphp

@if($pivotError)
    {{-- Erro --}}
    <div class="mad-card {{ $class }}">
        <div class="mad-card-content" style="padding:1.5rem;color:var(--mad-danger,#dc3545);">
            <strong>[mad-pivot-table]</strong>
            <p style="margin:.5rem 0 0;font-size:.85rem;">{{ $pivotError }}</p>
        </div>
    </div>

@elseif(!$hasData)
    {{-- Sem dados --}}
    <div class="mad-card {{ $class }}" style="width:{{ $width }};">
        @if($title)
            <div class="mad-card-header">
                <strong class="mad-card-title">{{ $title }}</strong>
                @if($subtitle)
                    <p class="mad-text-muted" style="margin:.25rem 0 0;font-size:.85rem;">{{ $subtitle }}</p>
                @endif
            </div>
        @endif
        <div class="mad-card-content" style="text-align:center;padding:2rem;color:var(--mad-muted);">
            <i data-lucide="table-2" style="width:40px;height:40px;margin-bottom:.5rem;opacity:.3;display:block;margin-left:auto;margin-right:auto;"></i>
            <p style="margin:0;font-size:.9rem;">{{ $noDataLabel }}</p>
        </div>
    </div>

@elseif(!$noPanel)
    {{-- Com panel (card wrapper) --}}
    <div id="{{ $name }}" class="mad-card mad-table-wrapper {{ $class }}" style="width:{{ $width }};">
        @if($title)
            <div class="mad-card-header mad-table-header">
                <strong class="mad-card-title mad-table-title">{{ $title }}</strong>
                @if($subtitle)
                    <p class="mad-text-muted" style="margin:.25rem 0 0;font-size:.85rem;">{{ $subtitle }}</p>
                @endif
            </div>
        @endif
        <div class="mad-card-content" style="padding:0;">
            <div id="{{ $name }}-container" class="mad-table-container">
                <iframe id="{{ $name }}_iframe"
                    src="https://manager.madbuilder.com.br/mad-table"
                    style="width:100%;height:{{ $heightPx }};overflow:auto;border:none;"
                    scrolling="yes"></iframe>
            </div>
        </div>
    </div>

@else
    {{-- Sem panel --}}
    <div id="{{ $name }}" class="mad-table-wrapper {{ $class }}" style="width:{{ $width }};">
        <div id="{{ $name }}-container" class="mad-table-container">
            <iframe id="{{ $name }}_iframe"
                src="https://manager.madbuilder.com.br/mad-table"
                style="width:100%;height:{{ $heightPx }};border:none;"
                scrolling="yes"></iframe>
        </div>
    </div>
@endif

@if($hasData)
<script>
(function () {
    'use strict';
    var iframe = document.getElementById('{{ $name }}_iframe');
    if (!iframe) return;

    iframe.addEventListener('load', function () {
        iframe.contentWindow.postMessage({
            action: 'initTable',
            options: {
                data: {!! $dataJson !!},
                config: {!! $configJson !!}
            }
        }, '*');
    });

    // Fullscreen handler (registra uma vez por pagina)
    if (!window.__madTableFullscreenInited) {
        window.__madTableFullscreenInited = true;
        window.addEventListener('message', function (event) {
            if (event.data && event.data.type === 'mad-table-fullscreen') {
                var iframes = document.querySelectorAll('iframe');
                iframes.forEach(function (el) {
                    if (el.contentWindow === event.source) {
                        var container = el.closest('.mad-table-container');
                        if (!container) return;
                        if (event.data.fullscreen) {
                            container.classList.add('mad-table-fullscreen-container');
                            document.body.classList.add('mad-table-fullscreen');
                        } else {
                            container.classList.remove('mad-table-fullscreen-container');
                            document.body.classList.remove('mad-table-fullscreen');
                        }
                    }
                });
            }
        });
    }
})();
</script>
@endif
