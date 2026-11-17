@php
    $variant = $variant ?? 'default';   // default | underline | vertical
    $default = $default ?? '';          // name of the initially active tab
    $class   = $class   ?? '';
    $style   = $style   ?? '';
    // wrap: abas quebram linha em vez de rolar (tela larga com muitas abas).
    $wrap    = filter_var($wrap ?? false, FILTER_VALIDATE_BOOLEAN) || ($wrap ?? null) === '';
    // "Visível para" (#346): a aba inicial pode ter sido tirada no servidor
    // para este usuário — começa então pela primeira que ficou.
    if ($default !== '' && ! str_contains((string) ($slot ?? ''), 'data-mad-tab="' . e($default) . '"')
        && preg_match('/data-mad-tab="([^"]*)"/', (string) ($slot ?? ''), $_firstTab)) {
        $default = html_entity_decode($_firstTab[1], ENT_QUOTES);
    }
    $variantClass = match($variant) {
        'underline' => ' mad-tabs-underline',
        'vertical'  => ' mad-tabs-vertical',
        default     => '',
    };
@endphp
{{-- x-id: escopo dos ids que ligam aba e painel (aria-controls/aria-labelledby,
     #576) — o mesmo `name` em dois grupos da tela não colide.
     data-mad-tabs-default: a aba inicial que o SERVIDOR pediu. No redesenho
     da tela o navegador mantém a aba em que o usuário estava (Mad.restoreUiState)
     — a menos que o servidor tenha mudado este default: aí vale o novo. --}}
<div class="mad-tabs{{ $variantClass }}{{ $wrap ? ' mad-tabs-wrap' : '' }} {{ $class }}" @if($style) style="{{ $style }}" @endif
     data-mad-tabs-default="{{ $default }}"
     x-data="{ activeTab: '{{ $default }}' }"
     x-id="['mad-tab', 'mad-tab-panel']">
    {!! $slot !!}
</div>
