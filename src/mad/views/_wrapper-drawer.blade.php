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
            window._madOverlayDepth = Math.max(1000, (window._madOverlayDepth || 1010) - 10);
            setTimeout(function () { wrap.remove(); }, 300);
        }
    }
    window.addEventListener('maddrawer', onClose);

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
