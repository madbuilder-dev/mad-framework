/**
 * MadReconcile — client da conciliação two-panel (<mad-reconcile>).
 *
 * Registra `Alpine.data('madReconcile', ...)`. Estado 100% efêmero
 * (seleções/filtro): toda action server-side termina em forceFullRender —
 * o wrapper re-renderiza e o componente Alpine re-inicializa limpo.
 *
 * Server-side dispatch via MadWire.call (mesmo bridge do mad-gantt.js):
 *   onAutoMatch() · onManualMatch(idsL, idsR) · onConfirmGroup(id)
 *   onConfirmAll() · onRejectGroup(id) · onUnmatch(id)
 */
(function (root) {
    'use strict';

    function wireCall(componentEl, method, payload) {
        var MW = null;
        try { MW = (typeof MadWire !== 'undefined') ? MadWire : null; } catch (_) {}
        if (!MW && root.MadWire) MW = root.MadWire;
        if (MW && typeof MW.call === 'function') {
            try {
                var wrapper = (componentEl && componentEl.closest && componentEl.closest('[mad-component]')) || componentEl;
                return Promise.resolve(MW.call(wrapper, method, payload));
            } catch (e) { console.warn('[MadReconcile] MadWire.call failed', e); }
        }
        return Promise.resolve(null);
    }

    function init() {
        var Alpine = root.Alpine;
        if (!Alpine || Alpine.__madReconcileRegistered) return;
        Alpine.__madReconcileRegistered = true;

        Alpine.data('madReconcile', function (cfg) {
            cfg = cfg || {};

            return {
                cfg: cfg,
                selL: [],
                selR: [],
                sumL: 0,
                sumR: 0,
                filterL: '',
                filterR: '',
                busy: false,

                get diff() {
                    return this.sumL - this.sumR;
                },

                get diffOk() {
                    return Math.abs(this.diff) <= (parseFloat(this.cfg.tolerance) || 0) + 0.005;
                },

                fmt(value) {
                    try {
                        return new Intl.NumberFormat(undefined, {
                            minimumFractionDigits: 2,
                            maximumFractionDigits: 2,
                        }).format(value);
                    } catch (_) { return String(value); }
                },

                toggle(side, id, amount) {
                    var sel = side === 'L' ? this.selL : this.selR;
                    var idx = sel.indexOf(id);
                    if (idx >= 0) {
                        sel.splice(idx, 1);
                        amount = -amount;
                    } else {
                        sel.push(id);
                    }
                    if (side === 'L') this.sumL += amount;
                    else this.sumR += amount;
                },

                /** Filtro client-side: casa contra o data-rc-text da linha. */
                rowVisible(el, side) {
                    var q = (side === 'L' ? this.filterL : this.filterR).trim().toLowerCase();
                    if (!q) return true;
                    return (el.dataset.rcText || '').indexOf(q) !== -1;
                },

                // ── Actions ──────────────────────────────────────────────
                _call(method, params) {
                    var self = this;
                    if (this.busy) return Promise.resolve(null);
                    this.busy = true;
                    return wireCall(this.$el, method, params || [])
                        .finally(function () { self.busy = false; });
                },

                autoMatch()          { this._call('onAutoMatch'); },
                confirmAll()         { this._call('onConfirmAll'); },
                confirmGroup(id)     { this._call('onConfirmGroup', [id]); },
                rejectGroup(id)      { this._call('onRejectGroup', [id]); },
                unmatch(id)          { this._call('onUnmatch', [id]); },

                manualMatch() {
                    if (!this.selL.length || !this.selR.length || !this.diffOk) return;
                    this._call('onManualMatch', [
                        JSON.stringify(this.selL),
                        JSON.stringify(this.selR),
                    ]);
                },
            };
        });
    }

    // Boot (espelha mad-gantt.js)
    if (document.readyState === 'loading') {
        document.addEventListener('alpine:init', init);
    } else if (root.Alpine) {
        init();
    } else {
        document.addEventListener('alpine:init', init);
    }

})(typeof window !== 'undefined' ? window : globalThis);
