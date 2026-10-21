{{--
    <mad-map-layer> — camada extra de um <mad-map> (até 4 além da principal),
    com liga/desliga na legenda.

    <mad-map model="Unidade" lat-field="latitude" lng-field="longitude" label="Unidades">
        <mad-map-layer model="Obra" lat-field="lat" lng-field="lng" label="Obras"
            icon="hard-hat" color="warning" title-field="{nome}" click-target="ObraForm::onEdit({id})" />
    </mad-map>

    Não desenha nada: guarda as props para o <mad-map> que a contém
    (Mad\Chart\MadMap::pushLayer). Mesmas props de dados do <mad-map>, mais
    `hidden` (começa desligada). Guia: docs/mad-map.md
--}}
@php
    \Mad\Chart\MadMap::pushLayer([
        'model' => $model ?? '', 'database' => $database ?? '', 'query' => $query ?? null, 'data' => $data ?? null,
        'filters' => $filters ?? [], 'unitField' => $unitField ?? '',
        'latField' => $latField ?? '', 'lngField' => $lngField ?? '', 'keyField' => $keyField ?? '',
        'titleField' => $titleField ?? '', 'popoverContent' => $popoverContent ?? '',
        'colorField' => $colorField ?? '', 'colorMap' => $colorMap ?? null, 'color' => $color ?? '',
        'icon' => $icon ?? '', 'label' => $label ?? '', 'hidden' => $hidden ?? null, 'limit' => $limit ?? null,
        'clickTarget' => $clickTarget ?? '', 'clickAction' => $clickAction ?? '',
    ]);
@endphp
