{{--
    <mad-week-grid> — linhas de registros (pessoas) × dias da semana, com um
    estado por célula que muda a cada clique (ex.: P presencial → R remoto →
    F ausente → vazio).

    <mad-week-grid model="Escala" row-field="contato_id" week-field="semana"
        day-fields="seg,ter,qua,qui,sex" note-field="foco" note-label="Foco da semana"
        rows-model="Contato" rows-label="nome" rows-sub="cargo"
        holiday-model="Feriado" holiday-date-field="data" holiday-label-field="nome"
        copy-week :editable-rows="$that->linhasLiberadas()" />

    Roda como componente próprio (Mad\Ui\MadWeekGrid): cada clique grava só a
    célula. Guia: docs/mad-week-grid.md
--}}
@php
    try {
        $__wgConfig = \Mad\Ui\MadWeekGrid::config([
            'owner' => isset($__component) ? get_class($__component) : '',
            'name' => $name ?? '', 'title' => $title ?? '',
            'model' => $model ?? '', 'database' => $database ?? '', 'filters' => $filters ?? [],
            'defaults' => $defaults ?? [],
            'rowField' => $rowField ?? '', 'weekField' => $weekField ?? '', 'dayFields' => $dayFields ?? null,
            'dateField' => $dateField ?? '', 'stateField' => $stateField ?? '',
            'noteField' => $noteField ?? '', 'noteLabel' => $noteLabel ?? '',
            'notePlaceholder' => $notePlaceholder ?? '', 'noteMax' => $noteMax ?? 255,
            'saveAction' => $saveAction ?? '',
            'rows' => $rows ?? null, 'rowsModel' => $rowsModel ?? '', 'rowsLabel' => $rowsLabel ?? '',
            'rowsSub' => $rowsSub ?? '', 'rowsFilters' => $rowsFilters ?? [], 'rowsTitle' => $rowsTitle ?? '',
            'states' => $states ?? null, 'allowEmpty' => $allowEmpty ?? null,
            'days' => $days ?? 5, 'week' => $week ?? '',
            'holidays' => $holidays ?? null, 'holidayModel' => $holidayModel ?? '',
            'holidayDateField' => $holidayDateField ?? '', 'holidayLabelField' => $holidayLabelField ?? '',
            'holidayFilters' => $holidayFilters ?? [], 'holidayLock' => $holidayLock ?? null,
            'readonly' => $readonly ?? null,
            'editableRows' => $editableRows ?? null,
            'copyWeek' => $copyWeek ?? null, 'totals' => $totals ?? null, 'legend' => $legend ?? null,
            'emptyText' => $emptyText ?? '',
        ]);
        $__wgError = null;
    } catch (\InvalidArgumentException $e) {
        $__wgConfig = null;
        $__wgError = $e->getMessage();
    }
@endphp
<div class="mad-week-grid-host {{ $class ?? '' }}" @if(!empty($style)) style="{{ $style }}" @endif>
    @if($__wgConfig !== null)
        {!! \Mad\Ui\MadWeekGrid::renderFor($__wgConfig) !!}
    @else
        <div class="mad-wg-error" role="alert">{{ $__wgError }}</div>
    @endif
</div>
