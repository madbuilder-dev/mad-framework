{{--
    <mad-site-section> — faixa de conteúdo livre (o "resto" do site).

    É uma SEÇÃO, não um card: fundo da página, largura do container, sem
    borda nem sombra. `tone="tint"` alterna o fundo para separar duas faixas
    vizinhas — é o único recurso de separação do kit, de propósito.
--}}
@props([
    'id'    => '',
    'title' => '',
    'lead'  => '',
    'tone'  => 'plain',
    'class' => '',
])

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        @if($title !== '')
            <h2>{{ $title }}</h2>
        @endif

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
