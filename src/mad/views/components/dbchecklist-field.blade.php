@php
    /**
     * mad-dbchecklist-field — Checklist que carrega items do banco automaticamente.
     *
     * Mesmo conceito do dbcombo: recebe model/display/filters e carrega via Eloquent/QuerySource.
     * Renderiza internamente o checklist-field v2 com busca, counter e select-all.
     *
     * Props (db):
     *   model       string       Classe do modelo (ex: 'IamGroup')
     *   database    string       Conexao (default: MAIN_DATABASE)
     *   key         string       Campo da PK (default: 'id')
     *   display     string       Campo a exibir (default: 'nome') — suporta template '{nome} ({email})'
     *   order-by    string       Campo para ordenar (default: display)
     *   filters     array        [['field','op','val'], ...] — mesma sintaxe do dbcombo (com subselect)
     *
     * Props (checklist):
     *   name, label, columns, selected, searchable, height, placeholder, hint, error, required, disabled
     *
     * Colunas podem ser definidas via tag:
     *   <mad-dbchecklist-field name="groups" model="IamGroup" database="iam">
     *       <mad-col field="id" label="ID" width="60px" center />
     *       <mad-col field="name" label="Nome" />
     *   </mad-dbchecklist-field>
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $model       = $model       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $keyField    = $key         ?? 'id';
    $display     = $display     ?? 'nome';
    $orderBy     = $orderBy     ?? '';
    $filters     = $filters     ?? [];
    $query       = $query       ?? null;   // Eloquent/Query Builder (novo padrão)
    $columns     = $columns     ?? [];
    $selected    = $selected    ?? [];
    $searchable  = !isset($searchable) || !empty($searchable);
    $height      = $height      ?? '320px';
    $placeholder = $placeholder ?? 'Buscar...';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $mode        = $mode        ?? 'comma';
    $pivotModel  = $pivotModel  ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $itemKey     = $itemKey     ?? '';

    // Ordenação
    $order = null;
    if ($orderBy) {
        $order = $orderBy;
    } elseif (!str_contains($display, '{')) {
        $order = $display;
    }

    // De onde saem as opções: a marca nova é conferida, no Salvar, na consulta
    // deste Model. Com `:query` própria quem decide a lista é o código da tela,
    // e não há o que conferir (visto ANTES de `:filters` virar consulta).
    $__optionsSource = ($model && !\Mad\Database\QuerySource::isQuery($query))
        ? ['model' => $model, 'key' => $keyField] : '';

    // :filters (array DSL) → Query Builder interno → caminho :query.
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
        if ($order) {
            $query->orderBy($order);
        }
    }

    // Carrega items do banco — :query (Builder) tem prioridade sobre model puro.
    // Builder: get() preserva global scopes (soft-delete). Records: caminho legado.
    // Falha ao carregar: lista vazia para o usuário final, motivo no log e, com
    // APP_DEBUG, no próprio campo (fórum #41) — ver \Mad\Form\OptionsLoadError.
    $items = [];
    $_rawObjects = [];  // objetos originais para transforms
    $objects = null;
    $__optError = null;
    $__optCtx   = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy,
    ];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $objects = (clone $query)->get()->all();
        } catch (\Throwable $e) {
            $objects = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbchecklist-field', $__optCtx);
        }
    } elseif ($model) {
        try {
            // builder-native: model puro → Query Builder.
            $__m2 = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $objects = \Mad\Database\QuerySource::recordsFromQuery($__m2::query(), $order);
        } catch (\Throwable $e) {
            $objects = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbchecklist-field', $__optCtx);
        }
    }
    if ($objects) {
        $__optError ??= \Mad\Form\OptionsLoadError::handleMissing(
            \Mad\Form\ModelOptionsLoader::missingDisplayColumns(reset($objects), $display),
            'mad-dbchecklist-field', $__optCtx
        );
        try {
            foreach ($objects as $obj) {
                if (method_exists($obj, 'toArray')) {
                    $row = $obj->toArray();
                    if (str_contains($display, '{')) {
                        $row['__display'] = method_exists($obj, 'render')
                            ? $obj->render($display)
                            : \Mad\Form\ModelOptionsLoader::mask($obj, $display);
                    }
                    $items[] = $row;
                    $_rawObjects[] = $obj;
                } else {
                    $items[] = (array)$obj;
                    $_rawObjects[] = $obj;
                }
            }
        } catch (\Throwable $e) {
        }
    }

    // Auto-gera colunas se não definidas
    if (empty($columns)) {
        $columns = [
            ['key' => $keyField, 'label' => 'ID', 'width' => '60px', 'align' => 'center'],
        ];
        $displayKey = str_contains($display, '{') ? '__display' : $display;
        $displayLabel = str_contains($display, '{') ? 'Nome' : ucfirst($display);
        $columns[] = ['key' => $displayKey, 'label' => $displayLabel];
    }
@endphp
<?php $__env->startComponent('components.checklist-field', [
    // repasse explícito: o startComponent NÃO herda o escopo, então prop nova do
    // checklist-field precisa ser listada aqui também (label-gap e o estilo do
    // label são delas).
    'labelGap'    => $labelGap ?? '',
    'labelColor'  => $labelColor ?? '',
    'labelSize'   => $labelSize ?? '',
    'labelWeight' => $labelWeight ?? '',
    'labelItalic' => $labelItalic ?? false,
    'width'       => $width ?? '',
    'maxWidth'    => $maxWidth ?? '',
    'name'        => $name,
    'label'       => $label,
    'items'       => $items,
    'columns'     => $columns,
    'idColumn'    => $keyField,
    'selected'    => $selected,
    'searchable'  => $searchable,
    'height'      => $height,
    'placeholder' => $placeholder,
    'hint'        => $hint,
    'error'       => $error,
    'required'    => $required,
    'disabled'    => $disabled,
    'rawObjects'  => $_rawObjects,
    'mode'        => $mode,
    'pivotModel'  => $pivotModel,
    'foreignKey'  => $foreignKey,
    'itemKey'     => $itemKey,
    'database'    => $database,
    'optionsError' => $__optError,
    'optionsSource' => $__optionsSource,
]); ?>
<?php echo $__env->renderComponent(); ?>
