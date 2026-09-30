@php
    $title      = $title      ?? '';
    $breadcrumb = $breadcrumb ?? null;  // ex: 'Módulo > Submódulo'
    $icon       = $icon       ?? null;  // lucide icon name
    $actions    = $actions    ?? null;  // HTML string com botões
    $class      = $class      ?? '';
    $style      = $style      ?? '';
    // Tela embutida num <mad-transporter> (MadTransporter::embedHeader()).
    $_embed      = \Mad\Ui\MadTransporter::embedHeader();
    $_hasActions = $actions || !empty(trim($slot ?? ''));
@endphp
@if($_embed === 'compact' || $_embed === 'title')
    {{-- Embutida: a página de fora já mostra título e breadcrumb. Fica a barra
         de ações (o "Novo" da lista embutida) e, com header="title", o título
         da tela em linha compacta. Sem nada disso, não desenha nada. --}}
    @if($_hasActions || ($_embed === 'title' && (string) $title !== ''))
    <div class="mad-page-embed-bar {{ $class }}" @if($style) style="{{ $style }}" @endif>
        @if($_embed === 'title' && (string) $title !== '')
            <div class="mad-page-embed-title">{{ $title }}</div>
        @endif
        @if($_hasActions)
            <div class="mad-page-header-actions">
                {!! $actions !!}
                {!! $slot ?? '' !!}
            </div>
        @endif
    </div>
    @endif
@else
<div class="mad-page-header {{ $class }}" @if($style) style="{{ $style }}" @endif>

    <div class="mad-page-header-left">
        @if($icon)
            <div class="mad-page-header-icon">
                <i data-lucide="{{ $icon }}" style="width:18px;height:18px;"></i>
            </div>
            <div class="mad-page-header-vsep"></div>
        @endif
        <div>
            @if($breadcrumb)
                <div class="mad-page-breadcrumb">{!! $breadcrumb !!}</div>
            @endif
            <h1 class="mad-page-title">{{ $title }}</h1>
        </div>
    </div>
    @if($_hasActions)
        <div class="mad-page-header-actions">
            {!! $actions !!}
            {!! $slot ?? '' !!}
        </div>
    @endif
</div>
<div class="mad-page-header-sep"></div>
@endif
