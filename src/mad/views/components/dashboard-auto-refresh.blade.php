@php
    // Auto-refresh do dashboard: re-executa as queries via MadWire com o
    // estado JA confirmado no server (onAtualizar) — nunca re-submete inputs
    // meio-editados nem recarrega a pagina (o antigo fallback de
    // window.location.reload() sobrevivia a navegacao SPA e recarregava a
    // tela ERRADA em loop).
    $seconds = (int) ($seconds ?? 0);
    if ($seconds > 0) $seconds = max(5, $seconds); // minimo 5s (contrato do docblock)
@endphp
@if ($seconds > 0)
<span data-mad-auto-refresh="{{ $seconds }}" hidden></span>
<script>
(function () {
    // Mata timer global de versoes antigas deste componente (se sobrou).
    if (window.__madDashboardRefreshTimer) {
        clearInterval(window.__madDashboardRefreshTimer);
        window.__madDashboardRefreshTimer = null;
    }
    // Idempotente por design: roda no load E a cada re-execucao pos-morph
    // (document.currentScript nao e confiavel em script re-injetado).
    document.querySelectorAll('[data-mad-auto-refresh]').forEach(function (marker) {
        var wrapper = marker.closest('[mad-component]');
        if (!wrapper) return;
        if (wrapper.__madAutoRefreshTimer) clearInterval(wrapper.__madAutoRefreshTimer);

        var sec = parseInt(marker.getAttribute('data-mad-auto-refresh'), 10) || 0;
        if (sec < 5) return;

        var timer = setInterval(function () {
            // Wrapper saiu do DOM (navegacao SPA / morph trocou o elemento):
            // encerra este timer — o script re-executado ja criou o novo.
            if (!wrapper.isConnected) { clearInterval(timer); return; }
            // Feature desligada num re-render parcial (marker sumiu): encerra.
            if (!wrapper.querySelector('[data-mad-auto-refresh]')) { clearInterval(timer); return; }
            // Aba em background: nao gasta request.
            if (document.hidden) return;
            // Request em voo: pula o tick (fila do MadWire cuidaria, mas nao
            // faz sentido empilhar refreshes).
            if (wrapper.hasAttribute('data-mad-busy')) return;
            // Usuario digitando num campo do dashboard: nao atropela.
            var ae = document.activeElement;
            if (ae && wrapper.contains(ae) && ae.matches('input, select, textarea')) return;

            // MadWire e const de script classico (nao window.MadWire) — usa o
            // binding global por nome, igual db-chart.
            if (typeof MadWire !== 'undefined') MadWire.call(wrapper, 'onAtualizar');
        }, sec * 1000);
        wrapper.__madAutoRefreshTimer = timer;
    });
})();
</script>
@endif
