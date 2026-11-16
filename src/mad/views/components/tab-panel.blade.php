@php
    $name  = $name  ?? '';
    $class = $class ?? '';
    $style = $style ?? '';
    // visible-when (fórum #335): o compilador manda `x-mad-when:visible` aqui.
    // O mad-ui.js esconde o TÍTULO da aba na tira e troca a ativa; o painel
    // segue no x-show. Sem condição, o HTML não muda.
    $attrs = $attrs ?? '';
    $_isHidden = $name && \Mad\Component\MadRenderContext::isHidden($name, 'tab');
    \Mad\Component\MadRenderContext::tabPanelRendered((string) $name, (string) ($slot ?? ''));
    // Mesma chave da aba (tab.blade.php): o painel aponta para ela.
    $_ariaKey = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name);
@endphp
<div class="mad-tab-content {{ $class }}{{ $_isHidden ? ' mad-hidden' : '' }}"
     @if($name) data-mad-tab="{{ $name }}" @endif
     role="tabpanel"
     :id="$id('mad-tab-panel', '{{ $_ariaKey }}')"
     :aria-labelledby="$id('mad-tab', '{{ $_ariaKey }}')"
     @if($style) style="{{ $style }}" @endif
     x-show="activeTab === '{{ $name }}'"
     x-cloak{!! $attrs !== '' ? ' ' . $attrs : '' !!}>
    {!! $slot !!}
</div>
