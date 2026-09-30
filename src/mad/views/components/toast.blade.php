{{--
  LEGADO — NÃO inclua em layout novo. O toaster real hoje é o Sonner embutido
  em mad-ui.js (auto-montado em document.body no evento `alpine:initialized`,
  via `_madToastMount()`), disparado pelo evento `mad-toast` (COM hífen) e já
  suficiente sozinho: `MadResponse->toast()`/`MadToast::*` funcionam sem esta
  tag. Este componente é um sistema paralelo mais antigo (região própria
  `#mad-toast-region`, evento `madtoast` SEM hífen) mantido só por
  retrocompatibilidade de quem eventualmente já renderiza `<mad-toast />`.

  Historicamente o `<script>` abaixo fazia `window.madToast = function(...)`
  de forma INCONDICIONAL — se este componente fosse montado (ex.: via SPA nav,
  que reexecuta o inline script) DEPOIS do mad-ui.js já ter definido a versão
  Sonner, ele sobrescrevia (clobber) o global correto e matava todo toast
  disparado por `MadResponse->toast()` dali em diante (bug documentado:
  mad-framework-laravel-teste/docs/tutor-framework-findings.md, achado #7).
  Agora a definição abaixo só entra em vigor se `window.madToast` ainda não
  existir — a versão Sonner sempre vence quando presente.

  JS API (só se o Sonner não estiver carregado):
    madToast('Mensagem salva!', 'success')
    madToast('Erro ao salvar.', 'danger', 'Erro', 6000)

  Tipos: info | success | warning | danger
--}}
<section aria-label="Notifications" aria-live="polite" aria-atomic="false">
<ol id="mad-toast-region"
    class="mad-toast-region"
    x-data="madToastSystem()"
    x-on:madtoast.window="add($event.detail)"
    x-on:mouseenter="expanded = true"
    x-on:mousemove="expanded = true"
    x-on:mouseleave="if (!interacting) expanded = false"
    x-on:pointerup.window="interacting = false"
    :data-expanded="expanded"
    :style="'--front-toast-height:' + frontHeight + 'px; --gap:' + gap + 'px'">

    <template x-for="(toast, idx) in toasts" :key="toast.id">
        <li class="mad-toast"
            :data-type="toast.type"
            :data-mounted="toast.mounted"
            :data-removed="toast.removed"
            :data-visible="idx < visibleToasts"
            :data-front="idx === 0"
            :data-expanded="expanded"
            :data-swiping="toast.swiping"
            :data-swipe-out="toast.swipeOut"
            :data-swipe-direction="toast.swipeDir"
            :data-rich-colors="true"
            :style="{
                '--index': idx,
                '--toasts-before': idx,
                '--z-index': toasts.length - idx,
                '--offset': toast.removed ? toast.offsetBeforeRemove + 'px' : getOffset(idx) + 'px',
                '--initial-height': toast.initialHeight + 'px',
                '--swipe-amount-x': toast.swipeAmountX + 'px',
                '--swipe-amount-y': toast.swipeAmountY + 'px',
            }"
            x-effect="measureHeight(toast, $el)"
            x-on:pointerdown="onPointerDown($event, toast, idx)"
            x-on:pointermove="onPointerMove($event, toast)"
            x-on:pointerup="onPointerUp($event, toast)">

            {{-- Close button --}}
            <button class="mad-toast-close"
                    x-on:click.stop="deleteToast(toast)"
                    aria-label="Fechar">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>

            {{-- Ícone --}}
            <div class="mad-toast-icon" x-html="getIcon(toast.type)"></div>

            {{-- Conteúdo --}}
            <div class="mad-toast-content">
                <div class="mad-toast-title" x-show="toast.title" x-text="toast.title"></div>
                <div class="mad-toast-desc" x-text="toast.message"></div>
            </div>
        </li>
    </template>
</ol>
</section>

<script>
(function() {

var VISIBLE_TOASTS = 3;
var GAP = 14;
var TOAST_LIFETIME = 4000;
var SWIPE_THRESHOLD = 45;
var TIME_BEFORE_UNMOUNT = 200;

var icons = {
    success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    danger:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
};

window.madToastSystem = function() {
    return {
        toasts: [],
        heights: [],
        expanded: false,
        interacting: false,
        gap: GAP,
        visibleToasts: VISIBLE_TOASTS,
        frontHeight: 0,

        add: function(detail) {
            var id = Date.now() + Math.random();
            var duration = detail.duration !== undefined ? detail.duration : TOAST_LIFETIME;

            var toast = {
                id: id,
                message: detail.message || '',
                title: detail.title || '',
                type: detail.type || 'info',
                duration: duration,
                mounted: false,
                removed: false,
                swiping: false,
                swipeOut: false,
                swipeDir: null,
                swipeAmountX: 0,
                swipeAmountY: 0,
                initialHeight: 0,
                offsetBeforeRemove: 0,
                // timer
                timeoutId: null,
                remaining: duration,
                closeTimerStart: 0,
                // pointer
                pointerStart: null,
                swipeLock: null,
                dragStartTime: 0
            };

            this.toasts.unshift(toast);

            var self = this;
            requestAnimationFrame(function() {
                var t = self.toasts.find(function(t) { return t.id === id; });
                if (t) t.mounted = true;
            });
        },

        deleteToast: function(toast) {
            if (toast.removed) return;
            toast.removed = true;
            toast.offsetBeforeRemove = this.getOffset(this.toasts.indexOf(toast));

            // Remove height entry
            this.heights = this.heights.filter(function(h) { return h.id !== toast.id; });
            this.updateFrontHeight();

            if (toast.timeoutId) clearTimeout(toast.timeoutId);

            var self = this;
            setTimeout(function() {
                self.toasts = self.toasts.filter(function(t) { return t.id !== toast.id; });
            }, TIME_BEFORE_UNMOUNT);
        },

        measureHeight: function(toast, el) {
            if (!el || toast.removed) return;
            var h = el.getBoundingClientRect().height;
            if (h <= 0) return;

            toast.initialHeight = h;

            var existing = this.heights.find(function(x) { return x.id === toast.id; });
            if (existing) {
                existing.height = h;
            } else {
                this.heights.unshift({ id: toast.id, height: h });
            }
            this.updateFrontHeight();
            this.scheduleTimer(toast);
        },

        updateFrontHeight: function() {
            this.frontHeight = this.heights.length > 0 ? this.heights[0].height : 0;
        },

        getOffset: function(idx) {
            // Sum heights of all toasts before this one + gaps
            var heightIndex = -1;
            if (idx >= 0 && idx < this.toasts.length) {
                var tid = this.toasts[idx].id;
                for (var i = 0; i < this.heights.length; i++) {
                    if (this.heights[i].id === tid) { heightIndex = i; break; }
                }
            }
            if (heightIndex < 0) return 0;

            var offset = 0;
            for (var i = 0; i < heightIndex; i++) {
                offset += this.heights[i].height;
            }
            return heightIndex * this.gap + offset;
        },

        // Timer management
        scheduleTimer: function(toast) {
            if (toast.duration <= 0 || toast.removed || toast.timeoutId) return;
            if (this.expanded || this.interacting) return;

            toast.closeTimerStart = Date.now();
            var self = this;
            toast.timeoutId = setTimeout(function() {
                self.deleteToast(toast);
            }, toast.remaining);
        },

        pauseTimers: function() {
            for (var i = 0; i < this.toasts.length; i++) {
                var t = this.toasts[i];
                if (t.timeoutId && t.closeTimerStart) {
                    var elapsed = Date.now() - t.closeTimerStart;
                    t.remaining = Math.max(0, t.remaining - elapsed);
                    clearTimeout(t.timeoutId);
                    t.timeoutId = null;
                }
            }
        },

        resumeTimers: function() {
            for (var i = 0; i < this.toasts.length; i++) {
                var t = this.toasts[i];
                if (!t.removed && !t.timeoutId && t.duration > 0) {
                    this.scheduleTimer(t);
                }
            }
        },

        getIcon: function(type) { return icons[type] || icons.info; },

        // Pointer/swipe events (Sonner-style)
        onPointerDown: function(e, toast, idx) {
            if (e.button === 2) return;
            this.interacting = true;
            toast.dragStartTime = Date.now();
            toast.offsetBeforeRemove = this.getOffset(idx);
            toast.pointerStart = { x: e.clientX, y: e.clientY };
            toast.swipeLock = null;
            toast.swiping = true;
            try { e.target.closest('.mad-toast').setPointerCapture(e.pointerId); } catch(ex) {}
        },

        onPointerMove: function(e, toast) {
            if (!toast.pointerStart) return;

            var xd = e.clientX - toast.pointerStart.x;
            var yd = e.clientY - toast.pointerStart.y;

            // Lock direction
            if (!toast.swipeLock && (Math.abs(xd) > 1 || Math.abs(yd) > 1)) {
                toast.swipeLock = Math.abs(xd) > Math.abs(yd) ? 'x' : 'y';
            }

            if (toast.swipeLock === 'x') {
                toast.swipeAmountX = xd;
                toast.swipeAmountY = 0;
            } else if (toast.swipeLock === 'y') {
                // bottom position: only allow swipe down (positive y)
                if (yd > 0) {
                    toast.swipeAmountY = yd;
                } else {
                    toast.swipeAmountY = yd / (1.5 + Math.abs(yd) / 20);
                }
                toast.swipeAmountX = 0;
            }
        },

        onPointerUp: function(e, toast) {
            if (!toast.pointerStart) return;
            this.interacting = false;

            var ax = Math.abs(toast.swipeAmountX);
            var ay = Math.abs(toast.swipeAmountY);
            var amount = toast.swipeLock === 'x' ? ax : ay;
            var elapsed = Date.now() - toast.dragStartTime;
            var velocity = amount / (elapsed || 1);

            if (amount >= SWIPE_THRESHOLD || velocity > 0.11) {
                // Determine direction
                if (toast.swipeLock === 'x') {
                    toast.swipeDir = toast.swipeAmountX > 0 ? 'right' : 'left';
                } else {
                    toast.swipeDir = toast.swipeAmountY > 0 ? 'down' : 'up';
                }
                toast.swipeOut = true;
                this.deleteToast(toast);
                return;
            }

            toast.swipeAmountX = 0;
            toast.swipeAmountY = 0;
            toast.swiping = false;
            toast.swipeLock = null;
            toast.pointerStart = null;
        },

        // Watchers
        init: function() {
            var self = this;
            this.$watch('expanded', function(val) {
                if (val) {
                    self.pauseTimers();
                } else {
                    self.resumeTimers();
                }
            });
        }
    };
};

// Global API — NUNCA sobrescreve um window.madToast já existente (a versão
// Sonner de mad-ui.js, quando presente, sempre vence; ver comentário do
// componente no topo do arquivo). Só assume o papel de toaster se for a
// PRIMEIRA a definir o global (Sonner ausente/ainda não carregado).
window.madToast = window.madToast || function(messageOrObj, type, title, duration) {
    var detail;
    if (typeof messageOrObj === 'object' && messageOrObj !== null) {
        detail = messageOrObj;
    } else {
        detail = {
            message: messageOrObj || '',
            type: type || 'info',
            title: title || '',
            duration: duration !== undefined ? duration : TOAST_LIFETIME
        };
    }
    window.dispatchEvent(new CustomEvent('madtoast', { detail: detail }));
};

// Shortcuts guardados individualmente: se o Sonner já definiu os seus
// (success/danger/error/warning/info), preservam-se intactos mesmo que o
// window.madToast acima também já fosse o dele.
window.madToast.success = window.madToast.success || function(msg, title) { window.madToast(msg, 'success', title); };
window.madToast.danger  = window.madToast.danger  || function(msg, title) { window.madToast(msg, 'danger',  title); };
window.madToast.warning = window.madToast.warning || function(msg, title) { window.madToast(msg, 'warning', title); };
window.madToast.info    = window.madToast.info    || function(msg, title) { window.madToast(msg, 'info',    title); };

})();
</script>
