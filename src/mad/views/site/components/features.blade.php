{{--
    <mad-site-features> — a grade de recursos. Recebe <mad-site-feature> no
    slot (ou qualquer HTML seu).

    `columns` é 2, 3 ou 4; valor fora disso cai em 3. A grade é responsiva
    pelo CSS — em telefone vira uma coluna sozinha.
--}}
@props([
    'id'      => '',
    'title'   => '',
    'lead'    => '',
    'columns' => 3,
    'tone'    => 'plain',
    'class'   => '',
])

@php
    $__cols = (int) $columns;
    $__cols = in_array($__cols, [2, 3, 4], true) ? $__cols : 3;
@endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        @if($title !== '')
            <h2>{{ $title }}</h2>
        @endif

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        <div class="site-features site-grid-{{ $__cols }}">
            {{ $slot ?? '' }}
        </div>
    </div>
</section>
