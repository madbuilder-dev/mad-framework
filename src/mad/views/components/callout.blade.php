@php
    $type    = $type    ?? 'info';   // info | success | warning | danger
    $title   = $title   ?? '';
    $icon    = $icon    ?? '';
    $calloutFooter = $calloutFooter ?? '';  // HTML for action buttons (não usar $actions — colide com MadRenderContext)
    $class   = $class   ?? '';
    $style   = $style   ?? '';

    // Faixa de próximo passo (fw#498): texto à esquerda e o botão da ação à
    // direita — "Pronto para planejar · falta o responsável [Iniciar]".
    //   action-label   texto do botão (sem ele, nada de botão)
    //   action         método da tela (como o mad:click do <mad-btn>; respeita
    //                  a permissão por ação do perfil)
    //   action-href    link, no lugar do método
    //   action-icon / action-variant (primary) / action-confirm
    $actionLabel   = trim((string) ($actionLabel ?? ''));
    $action        = trim((string) ($action ?? ''));
    $actionHref    = trim((string) ($actionHref ?? ''));
    $_actionBtn    = '';
    if ($actionLabel !== '' && ($action !== '' || $actionHref !== '')) {
        $_perm = preg_match('/^\s*(on[A-Za-z0-9_]*)\s*(?:\(|$)/', $action, $_pm) ? $_pm[1] : '';
        $_actionBtn = $__env->make('components.btn', [
            'label'      => $actionLabel,
            'variant'    => trim((string) ($actionVariant ?? '')) ?: 'primary',
            'size'       => 'sm',
            'icon'       => trim((string) ($actionIcon ?? '')),
            'confirm'    => (string) ($actionConfirm ?? ''),
            'href'       => $action === '' ? $actionHref : '',
            'attrs'      => $action !== '' ? 'data-mad-click="' . e($action) . '"' : '',
            'permAction' => $_perm,
        ])->render();
    }

    $defaultIcons = [
        'info'    => 'info',
        'success' => 'check-circle',
        'warning' => 'alert-triangle',
        'danger'  => 'x-circle',
    ];
    $icon = $icon ?: ($defaultIcons[$type] ?? 'info');

    $typeColors = [
        'info'    => 'var(--mad-info)',
        'success' => 'var(--mad-success)',
        'warning' => 'var(--mad-warning)',
        'danger'  => 'var(--mad-danger)',
    ];
    $color = $typeColors[$type] ?? 'var(--mad-info)';
@endphp
<div class="mad-callout mad-callout-{{ $type }} {{ $class }}{{ $_actionBtn !== '' ? ' mad-callout-has-action' : '' }}" @if($style) style="{{ $style }}" @endif>
    @if($_actionBtn !== '')<div class="mad-callout-main">@endif
    <div class="mad-callout-header">
        <i data-lucide="{{ $icon }}" style="width:16px;height:16px;color:{{ $color }};flex-shrink:0;"></i>
        @if($title)<span>{{ $title }}</span>@endif
    </div>
    @if(trim($slot ?? ''))
        <div class="mad-callout-body">{!! $slot !!}</div>
    @endif
    @if($calloutFooter)
        <div class="mad-callout-footer">{!! $calloutFooter !!}</div>
    @endif
    @if($_actionBtn !== '')
    </div>
    <div class="mad-callout-action">{!! $_actionBtn !!}</div>
    @endif
</div>
