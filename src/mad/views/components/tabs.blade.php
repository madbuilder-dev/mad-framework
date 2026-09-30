@php
    $variant = $variant ?? 'default';   // default | underline | vertical
    $default = $default ?? '';          // name of the initially active tab
    $class   = $class   ?? '';
    $style   = $style   ?? '';
    $variantClass = match($variant) {
        'underline' => ' mad-tabs-underline',
        'vertical'  => ' mad-tabs-vertical',
        default     => '',
    };
@endphp
{{-- data-mad-tabs-default: a aba inicial que o SERVIDOR pediu. No redesenho
     da tela o navegador mantém a aba em que o usuário estava (Mad.restoreUiState)
     — a menos que o servidor tenha mudado este default: aí vale o novo. --}}
<div class="mad-tabs{{ $variantClass }} {{ $class }}" @if($style) style="{{ $style }}" @endif
     data-mad-tabs-default="{{ $default }}"
     x-data="{ activeTab: '{{ $default }}' }">
    {!! $slot !!}
</div>
