/**
 * MadOrgChart — client da hierarquia visual (<mad-org-chart>).
 *
 * Registra `Alpine.data('madOrgChart', ...)`. Interações de nó (toggle,
 * click, drag) são DELEGADAS no viewport — HTML lazy injetado pelo
 * onLoadChildren (op html) funciona sem re-init de Alpine.
 *
 * Server-side dispatch via MadWire.call (mesmo bridge do mad-gantt.js):
 *   onLoadChildren(nodeId) · onReparent(nodeId, newParentId)
 *
 * Eventos do PHP:
 *   mad-orgchart:children { nodeId, count } → limpa spinner do toggle lazy
 */
(function (root) {
    'use strict';

    var ZOOM_MIN = 0.3, ZOOM_MAX = 2, ZOOM_STEP = 0.15;

    function wireCall(componentEl, method, payload) {
        var MW = null;
        try { MW = (typeof MadWire !== 'undefined') ? MadWire : null; } catch (_) {}
        if (!MW && root.MadWire) MW = root.MadWire;
        if (MW && typeof MW.call === 'function') {
            try {
                var wrapper = (componentEl && componentEl.closest && componentEl.closest('[mad-component]')) || componentEl;
                return Promise.resolve(MW.call(wrapper, method, payload));
            } catch (e) { console.warn('[MadOrgChart] MadWire.call failed', e); }
        }
        return Promise.resolve(null);
    }

    function init() {
        var Alpine = root.Alpine;
        if (!Alpine || Alpine.__madOrgChartRegistered) return;
        Alpine.__madOrgChartRegistered = true;

        Alpine.data('madOrgChart', function (cfg) {
            cfg = cfg || {};

            return {
                cfg: cfg,
                zoom: 1,
                panX: 0,
                panY: 0,
                search: '',
                hits: [],
                hitIndex: 0,
                _panning: null,       // {x, y, startX, startY}
                _dragId: null,        // nó sendo arrastado (re-parent)
                _loaded: {},          // nodeId -> true (lazy já carregado)

                get canvasStyle() {
                    return 'transform: translate(' + this.panX + 'px,' + this.panY + 'px) scale(' + this.zoom + ');';
                },

                init() {
                    var self = this;
                    this._onChildren = function (e) {
                        var id = String((e.detail && e.detail.nodeId) || '');
                        var toggle = self.$el.querySelector('[data-oc-toggle="' + id + '"]');
                        if (toggle) {
                            toggle.removeAttribute('data-oc-lazy');
                            toggle.classList.remove('mad-oc-toggle--loading');
                        }
                        self._loaded[id] = true;
                    };
                    this.$el.addEventListener('mad-orgchart:children', this._onChildren);
                },

                destroy() {
                    this.$el.removeEventListener('mad-orgchart:children', this._onChildren);
                },

                // ── Pan / zoom ───────────────────────────────────────────
                panStart(e) {
                    // só no fundo (não em card/botão) e botão esquerdo
                    if (e.button !== 0 || e.target.closest('.mad-oc-card') || e.target.closest('button')) return;
                    this._panning = { x: this.panX, y: this.panY, startX: e.clientX, startY: e.clientY };
                },

                panMove(e) {
                    if (!this._panning) return;
                    this.panX = this._panning.x + (e.clientX - this._panning.startX);
                    this.panY = this._panning.y + (e.clientY - this._panning.startY);
                },

                panEnd() {
                    this._panning = null;
                },

                onWheel(e) {
                    var dir = e.deltaY < 0 ? 1 : -1;
                    this._zoomBy(dir * ZOOM_STEP, e);
                },

                zoomIn()  { this._zoomBy(ZOOM_STEP); },
                zoomOut() { this._zoomBy(-ZOOM_STEP); },

                _zoomBy(delta, e) {
                    var next = Math.min(ZOOM_MAX, Math.max(ZOOM_MIN, this.zoom + delta));
                    if (e && this.$refs.viewport) {
                        // zoom ancorado no cursor: mantém o ponto sob o mouse
                        var rect  = this.$refs.viewport.getBoundingClientRect();
                        var cx    = e.clientX - rect.left, cy = e.clientY - rect.top;
                        var ratio = next / this.zoom;
                        this.panX = cx - (cx - this.panX) * ratio;
                        this.panY = cy - (cy - this.panY) * ratio;
                    }
                    this.zoom = next;
                },

                resetView() {
                    this.zoom = 1;
                    this.panX = 0;
                    this.panY = 0;
                },

                // ── Delegação: toggle / click no card ────────────────────
                onChartClick(e) {
                    var toggle = e.target.closest('[data-oc-toggle]');
                    if (toggle) {
                        this._toggleNode(toggle);
                        return;
                    }
                    var card = e.target.closest('[data-oc-card]');
                    if (card && this.cfg.clickTarget && !this._dragId) {
                        var fwd = this.cfg.forwardParams || {};
                        var params = Object.assign({ id: card.dataset.ocCard }, fwd);
                        Object.keys(fwd).forEach(function (k) { params['_forward_param_' + k] = fwd[k]; });
                        if (root.Mad && typeof root.Mad.go === 'function') {
                            // cfg.clickUrl = TEMPLATE amigavel assado no PHP
                            // (com __MAD_id__). Sem ele, o Mad.go monta a forma
                            // generica /app/<Classe>/<metodo>.
                            var url = (typeof root.Mad._resolveUrlTemplate === 'function')
                                ? root.Mad._resolveUrlTemplate(this.cfg.clickUrl, params)
                                : null;
                            root.Mad.go(this.cfg.clickTarget, this.cfg.clickMethod || 'onShow', params, url);
                        }
                    }
                },

                _toggleNode(toggle) {
                    var id = toggle.dataset.ocToggle;
                    var node = toggle.closest('.mad-oc-node');
                    if (!node) return;

                    // lazy: primeiro clique busca os filhos no servidor
                    if (toggle.hasAttribute('data-oc-lazy') && !this._loaded[id]) {
                        toggle.classList.add('mad-oc-toggle--loading');
                        wireCall(this.$el, 'onLoadChildren', [parseInt(id, 10)]);
                        return;
                    }
                    node.classList.toggle('mad-oc-node--collapsed');
                },

                // ── Busca ────────────────────────────────────────────────
                onSearch() {
                    var q = this.search.trim().toLowerCase();
                    this.$el.querySelectorAll('.mad-oc-card--hit').forEach(function (el) {
                        el.classList.remove('mad-oc-card--hit');
                    });
                    this.hits = [];
                    this.hitIndex = 0;
                    if (!q) return;

                    var self = this;
                    this.$el.querySelectorAll('[data-oc-search]').forEach(function (card) {
                        if ((card.dataset.ocSearch || '').indexOf(q) !== -1) {
                            card.classList.add('mad-oc-card--hit');
                            self.hits.push(card);
                        }
                    });
                    if (this.hits.length) this._centerOn(this.hits[0]);
                },

                nextHit() {
                    if (!this.hits.length) return;
                    this.hitIndex = (this.hitIndex + 1) % this.hits.length;
                    this._centerOn(this.hits[this.hitIndex]);
                },

                /** Centraliza um card no viewport (expande ancestrais colapsados). */
                _centerOn(card) {
                    var node = card.closest('.mad-oc-node');
                    while (node) {
                        node.classList.remove('mad-oc-node--collapsed');
                        node = node.parentElement ? node.parentElement.closest('.mad-oc-node') : null;
                    }
                    var vp = this.$refs.viewport, cv = this.$refs.canvas;
                    if (!vp || !cv) return;
                    var vpRect = vp.getBoundingClientRect();
                    var cRect  = card.getBoundingClientRect();
                    // posição do card relativa ao canvas transformado
                    this.panX += (vpRect.left + vpRect.width / 2) - (cRect.left + cRect.width / 2);
                    this.panY += (vpRect.top + vpRect.height / 3) - (cRect.top + cRect.height / 2);
                },

                // ── Drag re-parent ───────────────────────────────────────
                onDragStart(e) {
                    if (!this.cfg.draggable) { e.preventDefault(); return; }
                    var card = e.target.closest('[data-oc-card]');
                    if (!card) { e.preventDefault(); return; }
                    this._dragId = card.dataset.ocCard;
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', this._dragId);
                    card.classList.add('mad-oc-card--dragging');
                },

                onDragOver(e) {
                    if (!this._dragId) return;
                    var card = e.target.closest('[data-oc-card]');
                    if (!card || card.dataset.ocCard === this._dragId) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    card.classList.add('mad-oc-card--drop');
                },

                onDragLeave(e) {
                    var card = e.target.closest('[data-oc-card]');
                    if (card) card.classList.remove('mad-oc-card--drop');
                },

                onDrop(e) {
                    if (!this._dragId) return;
                    var card = e.target.closest('[data-oc-card]');
                    if (!card || card.dataset.ocCard === this._dragId) return;
                    e.preventDefault();
                    // servidor valida ciclo/authz; sucesso termina em full render
                    wireCall(this.$el, 'onReparent', [
                        parseInt(this._dragId, 10),
                        parseInt(card.dataset.ocCard, 10),
                    ]);
                    this._cleanupDrag();
                },

                onDragEnd() {
                    this._cleanupDrag();
                },

                _cleanupDrag() {
                    this._dragId = null;
                    this.$el.querySelectorAll('.mad-oc-card--dragging, .mad-oc-card--drop').forEach(function (el) {
                        el.classList.remove('mad-oc-card--dragging', 'mad-oc-card--drop');
                    });
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
