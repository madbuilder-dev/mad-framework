{{--
    <mad-site-cta> — a faixa de fechamento: uma frase e um botão.

    Um convite por página. Duas faixas de CTA na mesma rolagem cansam e
    nenhuma converte.
--}}
@props([
    'id'             => '',
    'title'          => '',
    'text'           => '',
    'primaryLabel'   => '',
    'primaryHref'    => '',
    'secondaryLabel' => '',
    'secondaryHref'  => '',
    'class'          => '',
])

<section class="site-cta {{ $class }}" @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        @if($title !== '')
            <h2>{{ $title }}</h2>
        @endif

        @if($text !== '')
            <p>{{ $text }}</p>
        @endif

        {{ $slot ?? '' }}

        @if($primaryLabel !== '' || $secondaryLabel !== '')
            <div>
                @if($primaryLabel !== '')
                    <a class="site-btn site-btn--primary" href="{{ $primaryHref }}">{{ $primaryLabel }}</a>
                @endif
                @if($secondaryLabel !== '')
                    <a class="site-btn site-btn--ghost" href="{{ $secondaryHref }}">{{ $secondaryLabel }}</a>
                @endif
            </div>
        @endif
    </div>
</section>
