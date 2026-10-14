@php $class = $class ?? ''; @endphp
{{--
  Strip de abas com navegação moderna (sem scrollbar nativa):
    - scrollbar do navegador escondida
    - setas sempre visíveis do lado onde há mais abas (calha própria, fora da lista)
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
         @focusin="reveal($event.target)"
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
            const was = this.canLeft + ':' + this.canRight;
            this.canLeft  = el.scrollLeft > 1;
            this.canRight = el.scrollLeft < max - 1;
            // A calha da seta que surgiu/sumiu muda a largura útil: a aba que
            // acabou de ser trazida para a vista volta a ser enquadrada (1 vez).
            if (this._revealed && was !== this.canLeft + ':' + this.canRight) {
                const tab = this._revealed;
                this._revealed = null;
                requestAnimationFrame(() => this.reveal(tab, true));
            }
            // Sem esmaecimento (#359): a máscara de 32 px apagava a aba visível
            // da borda. Quem avisa que há mais abas é a seta, sempre visível
            // daquele lado numa calha própria (CSS .is-left/.is-right).
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
            this.reveal(el.querySelector('.mad-tab-active, .mad-tab[aria-selected="true"]'));
        },

        // Traz a aba para a área útil, longe do esmaecimento. Também no foco por
        // teclado (Tab): o navegador rolava só o mínimo e a aba ficava apagada.
        reveal(tab, again) {
            const el = this.$refs.list;
            if (!el || !tab || !el.contains(tab)) return;
            const active = tab.closest('.mad-tab') || tab;
            this._revealed = again ? null : active;
            const pad = 16;
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
