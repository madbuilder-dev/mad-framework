@php
    $name     = $name     ?? '';
    $icon     = $icon     ?? '';
    $title    = $title    ?? '';
    $class    = $class    ?? '';
    $disabled = $disabled ?? false;
    $_isHidden = $name && \Mad\Component\MadRenderContext::isHidden($name, 'tab');
    // O texto da aba nomeia, no aviso das marcas mudadas por fora, o checklist
    // sem rótulo que estiver no painel dela.
    \Mad\Component\MadRenderContext::tabRendered((string) $name, (string) ($slot ?? ''));
    // Chave do id (aba ↔ painel, #576): id de HTML não aceita espaço.
    $_ariaKey = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name);
@endphp
<button
    type="button"
    role="tab"
    class="mad-tab {{ $class }}{{ $_isHidden ? ' mad-hidden' : '' }}"
    @if($name) data-mad-tab="{{ $name }}" @endif
    :id="$id('mad-tab', '{{ $_ariaKey }}')"
    :aria-controls="$id('mad-tab-panel', '{{ $_ariaKey }}')"
    @if($title) title="{{ $title }}" @endif
    :class="activeTab === '{{ $name }}' ? 'mad-tab-active' : ''"
    @if(!$disabled) @click="activeTab = '{{ $name }}'" @endif
    :aria-selected="activeTab === '{{ $name }}'"
    @if($disabled) disabled @endif
>
    @if($icon)
        <i data-lucide="{{ $icon }}" style="width:14px;height:14px;flex-shrink:0;"></i>
    @endif
    {!! $slot !!}
</button>
