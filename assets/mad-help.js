/*!
 * mad-help.js — Ajuda do app (manual do sistema, fórum #227).
 *
 *   - Botão ? (cabeçalho da tela: [data-mad-help-screen]; pílula do topo:
 *     [data-mad-help-open]) abre a cortina lateral com as páginas do manual
 *     ligadas à tela ativa (atributo mad-component). Sem página → estado vazio
 *     com link para a Ajuda completa.
 *   - madHelpSearch(url): componente Alpine da busca da /app/ajuda.
 *
 * Config vem do casco em window.__MAD_HELP__ (null = app sem manual publicado:
 * nada aparece). O HTML da cortina vem pronto do servidor
 * (HelpCenter::context?static=1) — já renderizado sem HTML cru.
 */
(function () {
    'use strict';

    if (window.MadHelp) return;

    function cfg() { return window.__MAD_HELP__ || null; }
    function label(k, fb) { var c = cfg(); return (c && c.labels && c.labels[k]) || fb; }

    /** Nome da classe sem namespace (App\Control\Cad\FeriadoForm → FeriadoForm). */
    function baseName(cls) {
        cls = String(cls || '').replace(/\//g, '\\');
        var i = cls.lastIndexOf('\\');
        var b = i >= 0 ? cls.slice(i + 1) : cls;
        return /^[A-Za-z_][A-Za-z0-9_]*$/.test(b) ? b : '';
    }

    function visible(el) {
        return !!(el && (el.offsetWidth || el.offsetHeight || el.getClientRects().length));
    }

    /**
     * Tela ativa: a cortina/modal aberta por cima (a última visível) vence; senão
     * a tela do conteúdo principal (aba visível). Só o componente de topo — um
     * componente aninhado (sub-form, combo) não é a tela.
     */
    function zOf(el) {
        var z = parseInt(window.getComputedStyle(el).zIndex, 10);
        return isNaN(z) ? 0 : z;
    }

    /** Gavetas/modais abertas, da de cima (maior z-index; empate = a mais nova) para a de baixo. */
    function openOverlays() {
        var list = Array.prototype.filter.call(
            document.querySelectorAll('[data-mad-overlay]'), visible
        ).filter(function (o) { return !o.classList.contains('mad-help-overlay'); });
        return list.map(function (o, i) { return { el: o, z: zOf(o), i: i }; })
            .sort(function (a, b) { return (b.z - a.z) || (b.i - a.i); })
            .map(function (x) { return x.el; });
    }

    function activeScreen() {
        var overlays = openOverlays();
        for (var i = 0; i < overlays.length; i++) {
            // O botão ? do cabeçalho da gaveta/modal de tela já diz qual é.
            var btn = overlays[i].querySelector('.mad-drawer-header [data-mad-help-screen], .mad-modal-header [data-mad-help-screen]');
            if (btn) return baseName(btn.getAttribute('data-mad-help-screen'));
            var inOverlay = overlays[i].querySelector('[mad-component]');
            if (inOverlay) return baseName(inOverlay.getAttribute('mad-component'));
        }
        var roots = document.querySelectorAll('#mad_main [mad-component], #mad_content [mad-component], main [mad-component]');
        for (var j = 0; j < roots.length; j++) {
            var el = roots[j];
            if (!visible(el)) continue;
            if (el.parentElement && el.parentElement.closest('[mad-component]')) continue;
            return baseName(el.getAttribute('mad-component'));
        }
        var any = document.querySelector('[mad-component]');
        return any ? baseName(any.getAttribute('mad-component')) : '';
    }

    /** Título da tela (o do <mad-page-header>) para o subtítulo da cortina. */
    function screenTitle(screen) {
        var nodes = document.querySelectorAll('[mad-component]');
        for (var i = nodes.length - 1; i >= 0; i--) {
            if (baseName(nodes[i].getAttribute('mad-component')) !== screen || !visible(nodes[i])) continue;
            var t = nodes[i].querySelector('.mad-page-title, .mad-drawer-title');
            // Tela em gaveta/modal: o título fica no cabeçalho dela, fora do componente.
            var box = nodes[i].closest('.mad-drawer, .mad-modal');
            if ((!t || !t.textContent.trim()) && box) t = box.querySelector('.mad-drawer-title, .mad-modal-title');
            if (t && t.textContent.trim()) return t.textContent.trim();
        }
        return '';
    }

    // ── Cortina ──────────────────────────────────────────────────────────────
    var overlay = null;
    var lastFocus = null;
    var seq = 0;

    function build() {
        if (overlay && document.body.contains(overlay)) return overlay;
        overlay = document.createElement('div');
        overlay.className = 'mad-ui mad-drawer-overlay mad-help-overlay';
        overlay.setAttribute('data-mad-help-overlay', '');
        overlay.hidden = true;
        overlay.innerHTML =
            '<div class="mad-drawer mad-help-drawer" role="dialog" aria-modal="true" aria-labelledby="mad-help-drawer-title">' +
              '<div class="mad-drawer-header">' +
                '<div class="mad-drawer-header-left">' +
                  '<div class="mad-drawer-header-icon-group"><div class="mad-drawer-header-icon"><i data-lucide="circle-help" style="width:20px;height:20px;"></i></div><div class="mad-drawer-header-vdivider"></div></div>' +
                  '<div class="mad-drawer-header-meta"><div class="mad-drawer-subtitle" data-mad-help-screen-name></div><div class="mad-drawer-title" id="mad-help-drawer-title"></div></div>' +
                '</div>' +
                '<button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon" data-mad-help-close><i data-lucide="x" style="width:16px;height:16px;"></i></button>' +
              '</div>' +
              '<div class="mad-drawer-body mad-help-drawer-body" data-mad-help-body></div>' +
            '</div>';
        overlay.querySelector('#mad-help-drawer-title').textContent = label('title', 'Ajuda');
        overlay.querySelector('[data-mad-help-close]').setAttribute('aria-label', label('close', 'Fechar'));
        overlay.addEventListener('mousedown', function (e) { overlay._down = e.target === overlay; });
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay && overlay._down) { close(); return; }
            if (e.target.closest('[data-mad-help-close]')) { close(); return; }
            // Link que leva para outra tela (Ajuda, página inteira): fecha a cortina;
            // a navegação segue pelo casco (generator="mad").
            var a = e.target.closest('a[href]');
            if (a && (a.hasAttribute('data-mad-help-close-on-click') || a.getAttribute('generator') === 'mad')) {
                close();
            }
        });
        document.body.appendChild(overlay);
        return overlay;
    }

    function icons() { if (window.lucide && lucide.createIcons) { try { lucide.createIcons(); } catch (e) { /* ignore */ } } }

    function open(screen) {
        var c = cfg();
        if (!c) return false;
        screen = baseName(screen) || activeScreen();
        var el = build();
        var body = el.querySelector('[data-mad-help-body]');
        el.querySelector('[data-mad-help-screen-name]').textContent = screenTitle(screen);
        body.innerHTML = '<div class="mad-help-loading">' + escapeHtml(label('loading', 'Carregando…')) + '</div>';
        lastFocus = document.activeElement;
        // Acima da gaveta/modal aberta (as de tela empilham o z-index em 10).
        var top = openOverlays()[0];
        el.style.zIndex = top ? String(Math.max(zOf(top) + 20, 1030)) : '';
        el.hidden = false;
        document.documentElement.classList.add('mad-help-open');
        icons();
        var btn = el.querySelector('[data-mad-help-close]');
        if (btn) btn.focus();

        var my = ++seq;
        var url = c.context + (c.context.indexOf('?') >= 0 ? '&' : '?') + 'static=1&screen=' + encodeURIComponent(screen || '');
        fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                if (r.redirected || r.status === 401) { window.location.reload(); throw new Error('auth'); }
                if (!r.ok) throw new Error('http ' + r.status);
                return r.text();
            })
            .then(function (html) {
                if (my !== seq) return;
                body.innerHTML = html;
                icons();
            })
            .catch(function (err) {
                if (my !== seq || String(err && err.message) === 'auth') return;
                body.innerHTML = '<div class="mad-help-empty mad-empty"><div class="mad-empty-title">' +
                    escapeHtml(label('error', 'Não foi possível carregar a ajuda.')) + '</div>' +
                    '<a class="mad-btn mad-btn-primary mad-btn-sm" generator="mad" href="' + escapeHtml(c.base) + '">' +
                    escapeHtml(label('open', 'Abrir a Ajuda')) + '</a></div>';
            });
        return true;
    }

    function close() {
        if (!overlay || overlay.hidden) return;
        overlay.hidden = true;
        seq++;
        document.documentElement.classList.remove('mad-help-open');
        if (lastFocus && lastFocus.focus && document.body.contains(lastFocus)) {
            try { lastFocus.focus(); } catch (e) { /* ignore */ }
        }
    }

    function isOpen() { return !!(overlay && !overlay.hidden); }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    // ── Eventos globais ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var t = e.target;
        if (!t || !t.closest) return;

        var trigger = t.closest('[data-mad-help-screen], [data-mad-help-open]');
        if (trigger && cfg()) {
            e.preventDefault();
            e.stopPropagation();
            open(trigger.getAttribute('data-mad-help-screen') || '');
            return;
        }

        // Âncora de título dentro do texto da Ajuda: rola até o título sem mexer
        // no endereço (o casco usa a URL para navegar).
        var a = t.closest('.mad-help-prose a[href^="#"], .mad-help-toc a[href^="#"]');
        if (a) {
            var id = decodeURIComponent(a.getAttribute('href').slice(1));
            var scope = a.closest('.mad-help-drawer-body') || document;
            var target = id ? (scope.querySelector('[id="' + id.replace(/"/g, '') + '"]')) : null;
            if (target) {
                e.preventDefault();
                target.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen()) {
            e.preventDefault();
            e.stopPropagation();
            close();
        }
    }, true);

    // ── Busca da /app/ajuda (Alpine) ─────────────────────────────────────────
    window.madHelpSearch = function (url) {
        return {
            q: '',
            results: [],
            loading: false,
            _seq: 0,
            run: function () {
                var self = this;
                var q = (this.q || '').trim();
                if (q.length < 2) { this.results = []; this.loading = false; return; }
                var my = ++this._seq;
                this.loading = true;
                fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'static=1&q=' + encodeURIComponent(q), {
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
                })
                    .then(function (r) { return r.ok ? r.json() : { results: [] }; })
                    .then(function (j) {
                        if (my !== self._seq) return;
                        self.results = (j && Array.isArray(j.results)) ? j.results : [];
                        self.loading = false;
                    })
                    .catch(function () { if (my === self._seq) { self.results = []; self.loading = false; } });
            }
        };
    };

    window.MadHelp = { open: open, close: close, isOpen: isOpen, activeScreen: activeScreen };
})();
