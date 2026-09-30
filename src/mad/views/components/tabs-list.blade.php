@php $class = $class ?? ''; @endphp
{{--
  Strip de abas com navegação moderna (sem scrollbar nativa):
    - scrollbar do navegador escondida
    - fade nas bordas apenas no lado onde há mais conteúdo (vars --mad-fade-*)
    - botões chevron que aparecem no hover quando há overflow
    - aba ativa rola pra dentro da vista automaticamente
    - wheel vertical vira scroll horizontal sobre o strip
  `activeTab` vem do x-data pai (<mad-tabs>) — escopo Alpine é herdado.
--}}
<div class="mad-tabs-list-wrap"
     x-data="madTabsScroller()"
     x-init="init()"
     :class="{ 'is-left': canLeft, 'is-right': canRight }">

    <button type="button" class="mad-tabs-nav mad-tabs-nav-left"
            x-show="canLeft" x-cloak
            @click="page(-1)" tabindex="-1" aria-label="Rolar abas para a esquerda">
        <i data-lucide="chevron-left"></i>
    </button>

    <div class="mad-tabs-list {{ $class }}"
         x-ref="list"
         @scroll.passive="update()"
         @wheel="onWheel($event)">
        {!! $slot !!}
    </div>

    <button type="button" class="mad-tabs-nav mad-tabs-nav-right"
            x-show="canRight" x-cloak
            @click="page(1)" tabindex="-1" aria-label="Rolar abas para a direita">
        <i data-lucide="chevron-right"></i>
    </button>
</div>

<script>
if (typeof window.madTabsScroller !== 'function') {
window.madTabsScroller = function () {
    return {
        canLeft: false,
        canRight: false,

        init() {
            const list = this.$refs.list;
            if (!list) return;
            this.update();

            // Recalcula fade/setas quando o strip ou o conteúdo muda de tamanho
            if (typeof ResizeObserver === 'function') {
                this._ro = new ResizeObserver(() => this.update());
                this._ro.observe(list);
            }

            // Aba ativa (estado do pai) entra na vista ao trocar
            try {
                this.$watch('activeTab', () => this.$nextTick(() => this.scrollActiveIntoView()));
            } catch (e) { /* sem activeTab no escopo — ignora */ }

            this.$nextTick(() => {
                this.update();
                this.scrollActiveIntoView();
                if (typeof window._madLucide === 'function') window._madLucide();
            });
        },

        update() {
            const el = this.$refs.list;
            if (!el) return;
            const max = el.scrollWidth - el.clientWidth;
            this.canLeft  = el.scrollLeft > 1;
            this.canRight = el.scrollLeft < max - 1;
            el.style.setProperty('--mad-fade-l', this.canLeft  ? '32px' : '0px');
            el.style.setProperty('--mad-fade-r', this.canRight ? '32px' : '0px');
        },

        page(dir) {
            const el = this.$refs.list;
            if (!el) return;
            el.scrollBy({ left: dir * Math.max(180, el.clientWidth * 0.7), behavior: 'smooth' });
        },

        onWheel(e) {
            const el = this.$refs.list;
            if (!el || el.scrollWidth <= el.clientWidth) return;
            // Só sequestra o wheel quando o gesto é predominantemente vertical
            if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
                el.scrollLeft += e.deltaY;
                e.preventDefault();
            }
        },

        scrollActiveIntoView() {
            const el = this.$refs.list;
            if (!el) return;
            const active = el.querySelector('.mad-tab-active, .mad-tab[aria-selected="true"]');
            if (!active) return;
            const pad = 32;
            const er = el.getBoundingClientRect();
            const ar = active.getBoundingClientRect();
            if (ar.left < er.left + pad) {
                el.scrollBy({ left: ar.left - er.left - pad, behavior: 'smooth' });
            } else if (ar.right > er.right - pad) {
                el.scrollBy({ left: ar.right - er.right + pad, behavior: 'smooth' });
            }
        },
    };
};
}
</script>
