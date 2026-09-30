@props(['type' => 'info', 'title' => '', 'icon' => '', 'class' => '', 'style' => ''])
@php
    /* 'danger' e 'error' são sinônimos aqui: o CSS só define
       .mad-alert-danger, mas o icon map só tinha 'error' — quem seguia
       a doc (type="danger") ganhava caixa certa com ícone default
       errado. Aceita os dois; a doc recomenda 'danger'. */
    $defaultIcons = [
        'info' => 'info', 'success' => 'check-circle',
        'warning' => 'alert-triangle', 'error' => 'x-circle',
        'danger' => 'x-circle',
    ];
    $icon = $icon ?: ($defaultIcons[$type] ?? 'info');
@endphp

<div class="mad-alert mad-alert-{{ $type }} {{ $class }}" role="alert" @if($style) style="{{ $style }}" @endif>
    <i data-lucide="{{ $icon }}" style="flex-shrink:0;width:16px;height:16px;margin-top:1px;"></i>
    <div style="flex:1;">
        @if($title)
            <p style="font-weight:600;margin:0 0 2px;">{{ $title }}</p>
        @endif
        <div style="font-size:var(--mad-text-sm);">{!! $slot !!}</div>
    </div>
</div>
