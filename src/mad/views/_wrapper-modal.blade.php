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
            window._madOverlayDepth = Math.max(1000, (window._madOverlayDepth || 1010) - 10);
            setTimeout(function () { wrap.remove(); }, 300);
        }
    }
    window.addEventListener('madmodal', onClose);

    if (window.Alpine) Alpine.initTree(wrap);
    if (window.lucide) lucide.createIcons();
    // Sequencia unica de init de campo — ver _madEnergizeFields (mad-ui.js).
    // Esta lista tinha SO os selects: form aberto em modal ficava sem mascara,
    // force-case, db-search e select-check.
    if (typeof _madEnergizeFields === 'function') _madEnergizeFields(wrap);
    window.dispatchEvent(new CustomEvent('madmodal', { detail: { name: '{{ $id }}', action: 'open' } }));
})();
</script>
