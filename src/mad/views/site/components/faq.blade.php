{{--
    <mad-site-faq> — as perguntas que o visitante faria antes de comprar.

    O acordeão é <details>/<summary> nativo: abre sem JavaScript, é
    pesquisável pelo Ctrl+F do navegador e o Google indexa a resposta.
--}}
@props([
    'id'    => '',
    'title' => '',
    'lead'  => '',
    'tone'  => 'plain',
    'class' => '',
])

{{-- `site-faq` é a COLUNA ESTREITA de leitura (780px). Na `<section>`, que
     ocupa a largura da tela, ela empurrava tudo para a borda esquerda: por
     isso a seção só organiza a faixa e a coluna é um bloco interno. --}}
<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $title !== '' ? $title : mad_t('mad.site_faq_title') }}</h2>

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        <div class="site-faq">
            {{ $slot ?? '' }}
        </div>
    </div>
</section>
