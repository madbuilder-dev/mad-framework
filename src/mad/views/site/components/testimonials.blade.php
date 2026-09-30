{{--
    <mad-site-testimonials> — o que os clientes dizem.

    Sem conteúdo no slot, a seção mostra três ESPAÇOS marcados como exemplo,
    não três elogios inventados com nome de pessoa. Depoimento falso numa
    landing page de software de gestão é o tipo de detalhe que o comprador
    percebe — e a partir dali ele desconfia também do preço.
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
    $__slot = trim((string) ($slot ?? ''));
@endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $title !== '' ? $title : mad_t('mad.site_testimonials_title') }}</h2>

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        <div class="site-testimonials site-grid-{{ $__cols }}">
            @if($__slot !== '')
                {{ $slot ?? '' }}
            @else
                @for($__i = 0; $__i < 3; $__i++)
                    <figure class="site-testimonial">
                        <blockquote>{{ mad_t('mad.site_testimonial_placeholder') }}</blockquote>
                        <figcaption>{{ mad_t('mad.site_example_content') }}</figcaption>
                    </figure>
                @endfor
            @endif
        </div>
    </div>
</section>
