@php
    $name  = $name  ?? '';
    $class = $class ?? '';
    $style = $style ?? '';
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
     x-cloak>
    {!! $slot !!}
</div>
