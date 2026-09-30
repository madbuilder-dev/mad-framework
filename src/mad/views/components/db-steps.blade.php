@php
    /**
     * mad-db-steps — Steps alimentado automaticamente do banco de dados.
     *
     * Props:
     *   model       (string)   Classe do model Eloquent (ex: 'EtapaNegociacao')
     *   database    (string)   Conexao (default: MAIN_DATABASE)
     *   key         (string)   Campo PK (default: 'id')
     *   display     (string)   Campo do label (default: 'nome')
     *   color-field (string)   Campo da cor (ex: 'cor') — aplica cor customizada por step
     *   order-by    (string)   Ordenacao (default: 'id asc')
     *   filters     (array)    Filtros: [['campo','op','val'], ...]
     *   value       (string)   Valor atual (ID do step ativo)
     *   variant     (string)   arrows, dots, numbers, progress (default: 'arrows')
     *   clickable   (bool)     Steps completos sao clicaveis
     *   size        (string)   sm, '', lg
     *   class       (string)   Classes CSS extras
     *   mad:click   (string)   Metodo PHP ao clicar
     */

    $model      = $model      ?? '';
    $database   = $database   ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $keyField   = $key        ?? 'id';
    $display    = $display    ?? 'nome';
    $colorField = $color_field ?? $colorField ?? '';
    $orderBy    = $order_by   ?? $orderBy ?? 'id asc';
    $filters    = $filters    ?? [];
    $value      = $value      ?? '';
    $variant    = $variant    ?? 'arrows';
    $clickable  = !empty($clickable);
    $size       = $size       ?? '';
    $class      = $class      ?? '';
    $madClick   = $madClick   ?? '';

    // Load steps from DB — builder-first via Query Builder (:filters DSL aplicado no builder)
    $steps   = [];
    $current = 0;

    if ($model) {
        try {
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            $records = \Mad\Database\QuerySource::recordsFromQuery($__qb, $orderBy);

            $index = 1;
            foreach ($records as $rec) {
                $step = [
                    'key'         => (string) $rec->{$keyField},
                    'label'       => (string) $rec->{$display},
                    'description' => '',
                    'icon'        => '',
                    'status'      => '',
                ];

                if ($colorField && !empty($rec->{$colorField})) {
                    $step['color'] = (string) $rec->{$colorField};
                }

                $steps[] = $step;

                if ((string) $rec->{$keyField} === (string) $value) {
                    $current = $index;
                }
                $index++;
            }
        } catch (\Throwable $e) {
            $steps = [];
        }
    }
@endphp

@include('components.steps', [
    'variant'  => $variant,
    'current'  => $current,
    'steps'    => $steps,
    'clickable'=> $clickable,
    'size'     => $size,
    'class'    => $class,
    'madClick' => $madClick,
])
