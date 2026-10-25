@php
    $id      = $id      ?? '';
    $title   = $title   ?? '';
    $size    = $size    ?? 'lg';
    $side    = $side    ?? 'right';
    // Tela inteira na gaveta = formulário: o clique fora NÃO fecha (perdia o
    // que foi digitado). X, Esc, Voltar e Salvar continuam fechando. Opt-in
    // por tela: protected static bool $closeOnBackdrop = true.
    $closeOnBackdrop = (bool) ($closeOnBackdrop ?? false);
    // Esc/X/clique fora perguntam antes de fechar com alteração não salva
    // (opt-in por tela: protected static bool $confirmDiscard = true).
    $confirmClose = (bool) ($confirmClose ?? false);
    $wrapId  = 'madwrap-' . preg_replace('/[^a-z0-9]/', '-', strtolower($id));
@endphp
<div id="{{ $wrapId }}" data-mad-wrapper="drawer">
    <mad-drawer :name="$id" :title="$title" :size="$size" :side="$side" :close-on-backdrop="$closeOnBackdrop" :confirm-close="$confirmClose">
        {!! $content !!}
    </mad-drawer>
</div>
<script>
(function () {
    var wrap = document.getElementById('{{ $wrapId }}');
    if (!wrap) return;

    // Remove instâncias anteriores do mesmo drawer
    document.querySelectorAll('#{{ $wrapId }}').forEach(function (el) {
        if (el !== wrap) el.remove();
    });

    // A cortina que É a tela (aberta pela URL, ou rota aberta pelo menu) nasce
    // dentro do conteúdo e sobe para o body: a troca de tela não a levava junto
    // e ela ficava por cima da tela seguinte (fw#393). Fecha quando a rota muda.
    var host = wrap.parentNode;
    var routeLayer = !!(host && host.closest && host.closest('#mad_main, .mad-tab-pane, .mad-tab-panes'));

    // Move para o body — ignora onde o framework inseriu
    document.body.appendChild(wrap);

    // z-index empilhado
    window._madOverlayDepth = (window._madOverlayDepth || 1000) + 10;
    var overlay = wrap.querySelector('.mad-drawer-overlay');
    if (overlay) overlay.style.zIndex = window._madOverlayDepth;

    // Remove do DOM após fechar (aguarda transição)
    function onClose(e) {
        if (e.detail && e.detail.name === '{{ $id }}' && e.detail.action === 'close') {
            window.removeEventListener('maddrawer', onClose);
            window.removeEventListener('mad:navigated', onRouteChange);
            window.removeEventListener('mad:tab-activated', onRouteChange);
            window._madOverlayDepth = Math.max(1000, (window._madOverlayDepth || 1010) - 10);
            setTimeout(function () { wrap.remove(); }, 300);
        }
    }
    window.addEventListener('maddrawer', onClose);

    // A rota é lida no tick seguinte: o pushState da navegação vem logo depois
    // do evento (e da própria abertura, quando o menu abre a rota da cortina).
    var routePath = null;
    function onRouteChange() {
        setTimeout(function () {
            if (routePath === null || !wrap.isConnected) return;
            if (window.location.pathname !== routePath) {
                window.dispatchEvent(new CustomEvent('maddrawer', { detail: { name: '{{ $id }}', action: 'close' } }));
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
    // Esta lista tinha SO os selects: form aberto em drawer (o caminho normal
    // de editar um registro pela listagem) ficava sem mascara, force-case,
    // db-search e select-check.
    if (typeof _madEnergizeFields === 'function') _madEnergizeFields(wrap);
    window.dispatchEvent(new CustomEvent('maddrawer', { detail: { name: '{{ $id }}', action: 'open' } }));
})();
</script>
