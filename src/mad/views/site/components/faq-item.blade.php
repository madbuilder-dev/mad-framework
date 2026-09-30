{{--
    <mad-site-faq-item> — uma pergunta dentro de <mad-site-faq>.

    O atributo `name` do <details> faz o acordeão exclusivo (abrir uma fecha
    a anterior) sem uma linha de JavaScript. Navegador antigo ignora o
    atributo e simplesmente deixa as duas abertas — degradação aceitável.

    Resposta com HTML (lista, link) vai no slot.
--}}
@props([
    'question' => '',
    'answer'   => '',
    'open'     => false,
    'group'    => 'faq',
    'class'    => '',
])

@php
    $__open  = filter_var($open, FILTER_VALIDATE_BOOLEAN);
    $__group = trim((string) $group);
@endphp

<details class="site-faq__item {{ $class }}"
         @if($__group !== '') name="{{ $__group }}" @endif
         @if($__open) open @endif>
    <summary>{{ $question }}</summary>
    <div>
        @if($answer !== '')
            <p>{{ $answer }}</p>
        @endif
        {{ $slot ?? '' }}
    </div>
</details>
