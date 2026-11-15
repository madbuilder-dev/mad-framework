@props(['name' => '', 'title' => '', 'subtitle' => '', 'icon' => '', 'side' => 'right', 'size' => 'lg', 'dismissible' => true, 'closeOnBackdrop' => true, 'confirmClose' => false, 'class' => '', 'help' => ''])
@php
    // `dismissible` = pode ser fechada (X e Esc). `close-on-backdrop` = fecha
    // também no clique na área escurecida — separado de propósito: formulário
    // (tela com $wrapper = DRAWER, <mad-detail-form mode="drawer">) desliga só
    // o clique fora, que perdia o que foi digitado, e mantém X/Esc.
    // `="false"` sem `:` chega como a string "false" (truthy) — por isso o
    // filter_var em vez do !empty().
    $dismissible     = is_bool($dismissible) ? $dismissible
        : (filter_var($dismissible, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true);
    $closeOnBackdrop = $dismissible && (is_bool($closeOnBackdrop) ? $closeOnBackdrop
        : (filter_var($closeOnBackdrop, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true));
    // `confirm-close` = Esc, X e clique fora perguntam antes de fechar quando
    // há alteração não salva (MadOverlayEsc.dismiss, mad.js). Opt-in; na tela
    // com $wrapper = DRAWER vem de `protected static bool $confirmDiscard`.
    $confirmClose = $dismissible && (is_bool($confirmClose) ? $confirmClose
        : (filter_var($confirmClose, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false));
    $confirmCloseAttrs = $confirmClose ? \Mad\Support\OverlayConfirmClose::attrs() : '';
    $sizes = ['sm' => '320px', 'md' => '420px', 'lg' => '560px', 'xl' => '720px', 'full' => '100vw'];
    // `size` fora do mapa é escape hatch pra medida livre (size="400"), e ia crua
    // pro style — declaração inválida, drawer na largura default do CSS.
    $width = \Mad\Support\CssUnits::length((string) ($sizes[$size] ?? $size), $sizes['lg']);
    $sideClass = $side === 'left' ? 'mad-drawer-left' : '';
    // `help` = classe da tela para o botão ? da Ajuda (manual do sistema) no
    // cabeçalho. Vem do wrapper de tela (MadComponentWrapper) só quando o app
    // tem manual; vazio = sem botão.
    $help = is_string($help) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $help) ? $help : '';
@endphp
<div x-data="{ open: false }"
     @maddrawer.window="$event.detail.name === '{{ $name }}' && (open = $event.detail.action === 'open')"
     x-cloak>
    {{-- Teleport overlay pra body — escapa de containing blocks (transform/filter/contain
         em ancestrais) que prendem position:fixed dentro do <mad-page-content>.        --}}
    <template x-teleport="body">
        {{-- data-mad-overlay*: o Esc global (MadOverlayEsc, mad.js) fecha a
             gaveta do TOPO por aqui — nome e se pode ser dispensada. O clique
             fora (MadOverlayBackdrop, mad.js) só fecha com
             data-mad-close-on-backdrop="1" e quando o botão desceu E subiu na
             área escura: arrastar a seleção do texto de um campo e soltar fora
             não fecha (o @click.self de antes fechava — o click cai no
             ancestral comum, que é o overlay). --}}
        <div class="mad-ui mad-drawer-overlay {{ $sideClass }} {{ $class }}"
             data-mad-overlay="drawer" data-mad-overlay-name="{{ $name }}" data-mad-dismissible="{{ $dismissible ? '1' : '0' }}"
             data-mad-close-on-backdrop="{{ $closeOnBackdrop ? '1' : '0' }}"{!! $confirmCloseAttrs !!}
             x-show="open"
             x-transition>
            <div class="mad-drawer" style="width:{{ $width }};">
                @if($title)
                    <div class="mad-drawer-header">
                        <div class="mad-drawer-header-left">
                            @if($icon)
                                <div class="mad-drawer-header-icon-group">
                                    <div class="mad-drawer-header-icon">
                                        <i data-lucide="{{ $icon }}" style="width:20px;height:20px;"></i>
                                    </div>
                                    <div class="mad-drawer-header-vdivider"></div>
                                </div>
                            @endif
                            <div class="mad-drawer-header-meta">
                                @if($subtitle)
                                    <div class="mad-drawer-subtitle">{{ $subtitle }}</div>
                                @endif
                                <div class="mad-drawer-title">{{ $title }}</div>
                            </div>
                        </div>
                        @if($help !== '')
                            <button type="button"
                                    class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon mad-overlay-help-btn"
                                    data-mad-help-screen="{{ $help }}"
                                    title="{{ mad_t('mad.help.screen_help') }}"
                                    aria-label="{{ mad_t('mad.help.screen_help') }}">
                                <i data-lucide="circle-help" style="width:16px;height:16px;"></i>
                            </button>
                        @endif
                        @if($dismissible)
                            <button type="button"
                                    class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon"
                                    aria-label="{{ mad_t('mad.ui.close') }}"
                                    @if($confirmClose)
                                    @click="MadOverlayEsc.dismiss($el.closest('[data-mad-overlay]'))"
                                    @else
                                    @click="open = false; $dispatch('maddrawer', { name: '{{ $name }}', action: 'close' })"
                                    @endif>
                                <i data-lucide="x" style="width:16px;height:16px;"></i>
                            </button>
                        @endif
                    </div>
                @endif
                <div class="mad-drawer-body">
                    {!! $slot !!}
                </div>
            </div>
        </div>
    </template>
</div>
