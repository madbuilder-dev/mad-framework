{{--
    <mad-site-prose> — texto corrido com a tipografia do site (Sobre, Termos,
    Política de privacidade, corpo de artigo).

    Só embrulha: o conteúdo é o seu HTML, escrito direto no slot. É aqui que
    <h2>, <p>, <ul> e <table> ganham espaçamento e medida de linha legíveis
    sem nenhuma classe extra.
--}}
@props([
    'id'    => '',
    'class' => '',
])

<div class="site-prose {{ $class }}" @if($id) id="{{ $id }}" @endif>
    {{ $slot ?? '' }}
</div>
