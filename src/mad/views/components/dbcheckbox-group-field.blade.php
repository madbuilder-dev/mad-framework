@php
    /**
     * mad-dbcheckbox-group-field — Checkbox group com auto-load do banco + persistência automática.
     *
     * Combina o carregamento de options do dbcombo-field com a renderização do checkbox-group-field.
     * Suporta dois modos de persistência via MadForm->save():
     *   - comma: IDs separados por vírgula numa coluna da mesma tabela
     *   - table: registros numa tabela pivot/relacionada (delete all + insert)
     *
     * Props:
     *   name        string   Nome do campo
     *   label       string   Label do campo
     *   database    string   Conexão (padrão: main_database)
     *   model       string   Classe do modelo fonte das options (ex: 'Categoria', 'Grupo')
     *   key         string   Campo da PK (padrão: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} {sobrenome}'
     *   order-by    string   Campo para ordenar (padrão: igual a display)
     *   query       Builder|null    Eloquent/Query Builder para filtrar as options
     *   filters     array           Atalho: [['field','op','val'], ...] — aplicado no Query Builder
     *   order       string   Direção do order-by: 'asc' (padrão) ou 'desc'
     *   selected    mixed    Valores pré-selecionados (array ou string CSV)
     *   layout      string   vertical (padrão) ou horizontal
     *   as          string   '' (caixas padrão) ou 'button' (pílulas segmentadas)
     *   size        string   '' (normal), 'sm' ou 'lg'
     *   break-items int      Quebra linha a cada N items (só vale com layout=horizontal e as='')
     *   width       string   Largura do campo (qualquer unidade CSS)
     *   max-width   string   Largura máxima do campo
     *   separator   string   Separador CSV (padrão: ',')
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   disabled    bool
     *   mode        string   Modo de persistência: 'comma' (padrão) ou 'table'
     *   pivot-model string   Classe do model Eloquent da tabela pivot (mode=table)
     *   foreign-key string   Coluna FK para o registro pai (mode=table; padrão: singular da tabela do pai + _id)
     *   item-key    string   Coluna FK para o item selecionado (mode=table)
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model       = $model       ?? '';
    $keyField    = $key         ?? 'id';
    $display     = $display     ?? 'nome';
    $orderBy     = $orderBy     ?? '';
    // Direção do `order-by`. O painel do builder já escrevia `order="desc"` e
    // nada acontecia: as options saíam sempre ASC. Valor fora do par cai em
    // asc no `itemsFromQuery` / no serviço de busca, que normalizam.
    $orderDir    = $order       ?? '';
    $filters     = $filters     ?? [];
    $query       = $query       ?? null;   // Eloquent/Query Builder (novo padrão)
    $selected    = $selected    ?? (isset($$name) ? $$name : []);
    $layout      = $layout      ?? 'vertical';
    $as          = $as          ?? '';
    $size        = $size        ?? '';
    $breakItems  = (int) ($breakItems ?? 0);
    $separator   = $separator   ?? ',';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';

    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';
    $horizontal  = ($layout === 'horizontal');

    // Variantes visuais. A classe base `mad-checkbox-group` fica SEMPRE no
    // container: o op `reload_checkbox_group` (mad.js) e a coleta de valores do
    // mad-livewire.js keiam nela. Diferente do radio, que TROCA a base por
    // `mad-radio-btn-group`, aqui as variantes são apenas modificadores.
    $isButton  = ($as === 'button');
    $sizeCls   = $size === 'sm' ? ' mad-checkbox-group-sm'
               : ($size === 'lg' ? ' mad-checkbox-group-lg' : '');
    $wrapClass = 'mad-checkbox-group'
               . ($horizontal ? ' mad-checkbox-group-h' : '')
               . ($isButton   ? ' mad-checkbox-group-btn' : '')
               . $sizeCls;
    // Quebra a cada N: só faz sentido em linha e fora do segmented (que já é
    // uma peça única). Uma variável só, para o atributo e o loop não divergirem.
    $breakOn   = ($breakItems > 0 && $horizontal && !$isButton) ? $breakItems : 0;
    $count     = 0;

    // Auto-load selected from pivot table (mode=table)
    if ($mode === 'table' && empty($selected) && $pivotModel && $itemKey) {
        $selected = \Mad\Component\MadRenderContext::loadPivotSelected($pivotModel, $foreignKey, $itemKey, $database);
    }

    // Normalize selected to array of strings
    if (is_string($selected)) {
        $selected = $selected !== '' ? explode($separator, $selected) : [];
    }
    $selected = array_map('strval', (array) $selected);

    // Registro no MadFormRegistry com metadados de persistência
    \Mad\Form\MadFormRegistry::register($name, 'db-checkbox-group', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'mode'       => $mode,
        'pivotModel' => $pivotModel,
        'foreignKey' => $foreignKey,
        'itemKey'    => $itemKey,
        'database'   => $database,
    ]);

    // :filters (array DSL) → Query Builder interno → caminho :query.
    // Sem :query e sem :filters, cai no ramo items() (model puro) abaixo.
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
    }

    // Falha ao carregar: o campo continua vazio para o usuário final, o motivo
    // vai para o log e, com APP_DEBUG, aparece no próprio campo (fórum #41).
    $__optError   = null;
    $__optMissing = [];
    $__optCtx     = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy,
    ];
    // Carrega options do banco — :query (Builder) tem prioridade sobre model puro
    $options = [];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Throwable $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbcheckbox-group-field', $__optCtx);
        }
    } elseif ($model) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::items(
                $model,
                $keyField,
                $display,
                $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Exception $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbcheckbox-group-field', $__optCtx);
        }
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbcheckbox-group-field', $__optCtx);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <span class="mad-label">{!! $label !!}{!! $reqStar !!}</span>
    @endif
    <div class="{{ $wrapClass }}" data-mad-model="{{ $name }}"@if($breakOn) data-mad-break="{{ $breakOn }}"@endif>
        @foreach($options as $optKey => $optLabel)
            @php
                $chkId     = $name . '_' . $optKey;
                $isChecked = in_array((string) $optKey, $selected);
                $count++;
            @endphp
            <label class="mad-checkbox-wrap" for="{{ $chkId }}">
                <input
                    type="checkbox"
                    id="{{ $chkId }}"
                    name="{{ $name }}[]"
                    value="{{ $optKey }}"
                    class="mad-checkbox"
                    @if($isChecked) checked @endif
                    @if($disabled) disabled @endif
                >
                <span class="mad-checkbox-box"></span>
                <span class="mad-checkbox-label">{{ $optLabel }}</span>
            </label>
            @if($breakOn && $count % $breakOn === 0 && !$loop->last)
                <div class="mad-checkbox-break"></div>
            @endif
        @endforeach
    </div>
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
