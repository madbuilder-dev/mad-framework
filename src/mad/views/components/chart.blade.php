@php
    /**
     * <mad-chart> — Componente universal de gráfico ECharts.
     *
     * Props:
     *   config   MadChart|MadChartConfig  Obrigatório. Builder ou config pré-construído.
     *   class    string                   Classe CSS extra no wrapper.
     *   height   int|null                 Sobrescreve a altura (px).
     *   title    string|null              Sobrescreve o título.
     */
    $config = $config ?? null;
    $class  = $class  ?? '';
    $height = $height ?? null;
    $title  = $title  ?? null;

    // Click-to-filter (drill-through) — calls MadWire action on slice/bar click.
    $clickAction = $clickAction ?? '';

    // Aceita tanto o builder (lazy) quanto um MadChartConfig pré-construído
    if ($config instanceof \Mad\Chart\MadChart) {
        $cfg = $config->toConfig();
    } elseif ($config instanceof \Mad\Chart\MadChartConfig) {
        $cfg = $config;
    } else {
        $cfg = null;
    }

    $chartTitle  = $title  ?? ($cfg ? $cfg->title    : '');
    // Os dois call sites abaixo concatenavam a unidade no style sem checar, então
    // height="300px" virava altura com unidade DUPLICADA: declaração inválida,
    // altura descartada em silêncio. A unidade sai daqui, já normalizada.
    $chartHeight = \Mad\Support\CssUnits::length(
        (string) ($height ?? ($cfg ? $cfg->height : 300)),
        '300px',
    );
    $chartWidth  = \Mad\Support\CssUnits::length((string) ($cfg ? $cfg->width : '100%'), '100%');
    $chartName   = $cfg ? $cfg->name     : ('chart_' . uniqid());
    $showPanel   = $cfg ? $cfg->showPanel : true;
    $hasData     = $cfg ? $cfg->hasData   : false;
    $subtitle    = $cfg ? $cfg->subtitle  : '';
@endphp

@if($cfg && !$hasData)
    {{-- Estado vazio --}}
    <div class="mad-card {{ $class }}" style="width:{{ $chartWidth }};">
        @if($chartTitle)
            <div class="mad-card-header">
                <strong class="mad-card-title">{{ $chartTitle }}</strong>
                @if($subtitle)
                    <p class="mad-text-muted" style="margin:.25rem 0 0;font-size:.85rem;">{{ $subtitle }}</p>
                @endif
            </div>
        @endif
        <div class="mad-card-content" style="text-align:center;padding:2rem;color:var(--mad-muted);">
            <i data-lucide="chart-bar" style="width:40px;height:40px;margin-bottom:.5rem;opacity:.3;display:block;margin-left:auto;margin-right:auto;"></i>
            <p style="margin:0;font-size:.9rem;">Sem dados para exibir</p>
        </div>
    </div>

@elseif($cfg && $showPanel)
    {{-- Gráfico com card MAD UI --}}
    <div class="mad-card {{ $class }}" style="width:{{ $chartWidth }};">
        @if($chartTitle)
            <div class="mad-card-header">
                <strong class="mad-card-title">{{ $chartTitle }}</strong>
                @if($subtitle)
                    <p class="mad-text-muted" style="margin:.25rem 0 0;font-size:.85rem;">{{ $subtitle }}</p>
                @endif
            </div>
        @endif
        <div class="mad-card-content" style="padding:0;">
            <div
                id="{{ $chartName }}-container"
                style="width:100%;height:{{ $chartHeight }};"
            ></div>
        </div>
    </div>

@elseif($cfg)
    {{-- Sem painel — container bare --}}
    <div
        id="{{ $chartName }}-container"
        class="{{ $class }}"
        style="width:{{ $chartWidth }};height:{{ $chartHeight }};"
    ></div>
@endif

@if($cfg && $hasData)
<script>
(function () {
    'use strict';
    const NAME    = '{{ $chartName }}';
    const OPTIONS = {!! $cfg->optionsJson !!};
    const __dv_{{ $chartName }} = {!! $cfg->displayValuesJson !!};

    function initChart() {
        const el = document.getElementById(NAME + '-container');
        if (!el || el.dataset.echartInited) return;
        if (typeof echarts === 'undefined') {
            console.error('[mad-chart] echarts.min.js não está carregado. Adicione-o no tema.');
            return;
        }

        // Descarta instância anterior (morph após filtro reativa o mesmo NAME)
        if (window.__madCharts && window.__madCharts[NAME]) {
            try { window.__madCharts[NAME].dispose(); } catch {}
            delete window.__madCharts[NAME];
        }

        el.dataset.echartInited = '1';
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const chart = echarts.init(el, isDark ? 'dark' : null, { renderer: 'svg' });
        if (isDark) { OPTIONS.backgroundColor = 'transparent'; }
        chart.setOption(OPTIONS);

        if (!window.__madCharts) window.__madCharts = {};
        window.__madCharts[NAME] = chart;

        @if($clickAction)
        // Click-to-filter (drill-through)
        el.style.cursor = 'pointer';
        chart.on('click', function (params) {
            var wrapper = el.closest('[mad-component]');
            if (!wrapper) return;
            if (typeof MadWire === 'undefined' || !MadWire.call) return;
            MadWire.call(wrapper, '{{ $clickAction }}', [
                params.name,
                params.value,
                params.dataIndex
            ]);
        });
        @endif

        // ── Glue de resize/tema com CLEANUP ──────────────────────────────
        // Morph re-executa este script: sem cleanup, listeners globais e
        // observers acumulavam e chamavam resize() em charts descartados.
        if (!window.__madChartCleanup) window.__madChartCleanup = {};
        if (window.__madChartCleanup[NAME]) {
            try { window.__madChartCleanup[NAME](); } catch {}
        }
        const __cleanupFns = [];
        const __safeResize = () => { try { chart.resize(); } catch {} };

        if (window.ResizeObserver) {
            const target = el.closest('.mad-card') || el.parentElement || el;
            // Guard anti-loop: resize() pode mudar o pai e re-disparar o
            // observer — só resize quando o tamanho realmente mudou.
            let __lastW = -1, __lastH = -1;
            const ro = new ResizeObserver((entries) => {
                const r = entries[0].contentRect;
                if (Math.abs(r.width - __lastW) < 1 && Math.abs(r.height - __lastH) < 1) return;
                __lastW = r.width; __lastH = r.height;
                __safeResize();
            });
            ro.observe(target);
            __cleanupFns.push(() => ro.disconnect());
        } else {
            const onWinResize = () => __safeResize();
            window.addEventListener('resize', onWinResize);
            __cleanupFns.push(() => window.removeEventListener('resize', onWinResize));
        }

        const onTab    = () => setTimeout(__safeResize, 80);
        const onDrawer = (e) => { if (e && e.detail && e.detail.action === 'open') setTimeout(__safeResize, 120); };
        const onModal  = (e) => { if (e && e.detail && e.detail.action === 'open') setTimeout(__safeResize, 120); };
        document.addEventListener('shown.bs.tab', onTab);
        window.addEventListener('maddrawer', onDrawer);
        window.addEventListener('madmodal', onModal);
        __cleanupFns.push(() => {
            document.removeEventListener('shown.bs.tab', onTab);
            window.removeEventListener('maddrawer', onDrawer);
            window.removeEventListener('madmodal', onModal);
        });

        // Toggle de tema em runtime re-inicializa no tema novo.
        const themeMo = new MutationObserver(() => {
            const nowDark = document.documentElement.getAttribute('data-theme') === 'dark';
            if (nowDark === isDark) return;
            try { window.__madChartCleanup[NAME] && window.__madChartCleanup[NAME](); } catch {}
            delete el.dataset.echartInited;
            initChart();
        });
        themeMo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
        __cleanupFns.push(() => themeMo.disconnect());

        window.__madChartCleanup[NAME] = function () {
            __cleanupFns.forEach(fn => { try { fn(); } catch {} });
            window.__madChartCleanup[NAME] = null;
        };
    }

    function __madBoot(attempt) {
        if (typeof echarts === 'undefined') {
            // echarts pode carregar depois (pagina injetada via AJAX):
            // tenta por ~10s antes de desistir.
            if ((attempt || 0) < 40) {
                setTimeout(function () { __madBoot((attempt || 0) + 1); }, 250);
            } else {
                console.error('[mad-chart] echarts.min.js não está carregado. Adicione-o no tema.');
            }
            return;
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initChart);
        } else {
            initChart();
        }
    }
    __madBoot(0);
})();
</script>
@endif
