@php
    $id      = $id      ?? '';
    $title   = $title   ?? '';
    $size    = $size    ?? 'lg';
    // Igual à gaveta: tela em modal não fecha no clique fora (opt-in por tela:
    // protected static bool $closeOnBackdrop = true).
    $closeOnBackdrop = (bool) ($closeOnBackdrop ?? false);
    // Igual à gaveta: opt-in por tela com protected static bool $confirmDiscard = true.
    $confirmClose = (bool) ($confirmClose ?? false);
    $wrapId  = 'madwrap-' . preg_replace('/[^a-z0-9]/', '-', strtolower($id));
@endphp
<div id="{{ $wrapId }}" data-mad-wrapper="modal">
    <mad-modal :name="$id" :title="$title" :size="$size" :close-on-backdrop="$closeOnBackdrop" :confirm-close="$confirmClose">
        {!! $content !!}
    </mad-modal>
</div>
<script>
(function () {
    var wrap = document.getElementById('{{ $wrapId }}');
    if (!wrap) return;

    // Remove instâncias anteriores da mesma modal
    document.querySelectorAll('#{{ $wrapId }}').forEach(function (el) {
        if (el !== wrap) el.remove();
    });

    // A janela que É a tela (aberta pela URL, ou rota aberta pelo menu) nasce
    // dentro do conteúdo e sobe para o body: a troca de tela não a levava junto
    // e ela ficava por cima da tela seguinte (fw#393). Fecha quando a rota muda.
    var host = wrap.parentNode;
    var routeLayer = !!(host && host.closest && host.closest('#mad_main, .mad-tab-pane, .mad-tab-panes'));

    // Move para o body — ignora onde o framework inseriu
    document.body.appendChild(wrap);

    // z-index empilhado
    window._madOverlayDepth = (window._madOverlayDepth || 1000) + 10;
    var overlay = wrap.querySelector('.mad-modal-overlay');
    if (overlay) overlay.style.zIndex = window._madOverlayDepth;

    // Remove do DOM após fechar (aguarda transição)
    function onClose(e) {
        if (e.detail && e.detail.name === '{{ $id }}' && e.detail.action === 'close') {
            window.removeEventListener('madmodal', onClose);
            window.removeEventListener('mad:navigated', onRouteChange);
            window.removeEventListener('mad:tab-activated', onRouteChange);
            window._madOverlayDepth = Math.max(1000, (window._madOverlayDepth || 1010) - 10);
            setTimeout(function () { wrap.remove(); }, 300);
        }
    }
    window.addEventListener('madmodal', onClose);

    // A rota é lida no tick seguinte: o pushState da navegação vem logo depois
    // do evento (e da própria abertura, quando o menu abre a rota da janela).
    var routePath = null;
    function onRouteChange() {
        setTimeout(function () {
            if (routePath === null || !wrap.isConnected) return;
            if (window.location.pathname !== routePath) {
                window.dispatchEvent(new CustomEvent('madmodal', { detail: { name: '{{ $id }}', action: 'close' } }));
            }
        }, 0);
    }
    if (routeLayer) {
        setTimeout(function () { routePath = window.location.pathname; }, 0);
        window.addEventListener('mad:navigated', onRouteChange);
        window.addEventListener('mad:tab-activated', onRouteChange);
    }

    if (window.Alpine) Alpine.initTree(wrap);
    if (window.lucide) lucide.createIcons();
    // Sequencia unica de init de campo — ver _madEnergizeFields (mad-ui.js).
    // Esta lista tinha SO os selects: form aberto em modal ficava sem mascara,
    // force-case, db-search e select-check.
    if (typeof _madEnergizeFields === 'function') _madEnergizeFields(wrap);
    window.dispatchEvent(new CustomEvent('madmodal', { detail: { name: '{{ $id }}', action: 'open' } }));
})();
</script>
