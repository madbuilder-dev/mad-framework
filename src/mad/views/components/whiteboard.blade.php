{{--
    <mad-whiteboard> — quadro branco com notas (post-its): arrastar, redimensionar,
    editar o texto no lugar, cor, zoom e grade de pontos. Cada gesto grava só a
    nota, sem salvar a tela.

    <mad-whiteboard model="Nota" :filters="[['quadro_id', '=', $that->quadroId]]"
        :defaults="['quadro_id' => $that->quadroId]"
        x-field="x" y-field="y" width-field="largura" text-field="texto"
        color-field="cor" meta-field="autor_nome" :locked="$that->quadroTravado()" />

    Roda como componente próprio (Mad\Ui\MadWhiteboard). Guia: docs/mad-whiteboard.md
--}}
@php
    try {
        $__wbConfig = \Mad\Ui\MadWhiteboard::config([
            'owner' => isset($__component) ? get_class($__component) : '',
            'name' => $name ?? '', 'title' => $title ?? '',
            'model' => $model ?? '', 'database' => $database ?? '',
            'filters' => $filters ?? [], 'defaults' => $defaults ?? [], 'orderBy' => $orderBy ?? '',
            'xField' => $xField ?? '', 'yField' => $yField ?? '', 'widthField' => $widthField ?? '',
            'heightField' => $heightField ?? '', 'textField' => $textField ?? '',
            'colorField' => $colorField ?? '', 'metaField' => $metaField ?? '',
            'colors' => $colors ?? null, 'defaultColor' => $defaultColor ?? '',
            'noteWidth' => $noteWidth ?? 200, 'textMax' => $textMax ?? 600, 'placeholder' => $placeholder ?? '',
            'grid' => $grid ?? 24, 'snap' => $snap ?? null,
            'locked' => $locked ?? null, 'canAdd' => $canAdd ?? null, 'canDelete' => $canDelete ?? null,
            'editableNotes' => $editableNotes ?? null, 'zoom' => $zoom ?? null,
            'minWidth' => $minWidth ?? 1100, 'minHeight' => $minHeight ?? 560, 'height' => $height ?? '',
            'emptyText' => $emptyText ?? '',
        ]);
        $__wbError = null;
    } catch (\InvalidArgumentException $e) {
        $__wbConfig = null;
        $__wbError = $e->getMessage();
    }
@endphp
<div class="mad-whiteboard-host {{ $class ?? '' }}" @if(!empty($style)) style="{{ $style }}" @endif>
    @if($__wbConfig !== null)
        {!! \Mad\Ui\MadWhiteboard::renderFor($__wbConfig) !!}
    @else
        <div class="mad-wb-error" role="alert">{{ $__wbError }}</div>
    @endif
</div>
