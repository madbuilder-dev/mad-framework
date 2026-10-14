@php
    $variant = $variant ?? 'default';   // default | underline | vertical
    $default = $default ?? '';          // name of the initially active tab
    $class   = $class   ?? '';
    $style   = $style   ?? '';
    // wrap: abas quebram linha em vez de rolar (tela larga com muitas abas).
    $wrap    = filter_var($wrap ?? false, FILTER_VALIDATE_BOOLEAN) || ($wrap ?? null) === '';
    $variantClass = match($variant) {
        'underline' => ' mad-tabs-underline',
        'vertical'  => ' mad-tabs-vertical',
        default     => '',
    };
@endphp
{{-- data-mad-tabs-default: a aba inicial que o SERVIDOR pediu. No redesenho
     da tela o navegador mantém a aba em que o usuário estava (Mad.restoreUiState)
     — a menos que o servidor tenha mudado este default: aí vale o novo. --}}
<div class="mad-tabs{{ $variantClass }}{{ $wrap ? ' mad-tabs-wrap' : '' }} {{ $class }}" @if($style) style="{{ $style }}" @endif
     data-mad-tabs-default="{{ $default }}"
     x-data="{ activeTab: '{{ $default }}' }">
    {!! $slot !!}
</div>
