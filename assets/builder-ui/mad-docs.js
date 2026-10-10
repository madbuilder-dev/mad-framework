/**
 * mad-docs.js
 *
 * Helpers client-side da documentacao do MAD framework:
 *   - Toggle dark/light mode (persistido em localStorage)
 *   - Search palette cmd+K full-text (vanilla, sem Alpine)
 *   - Scrollspy do TOC right
 *   - Re-init de Lucide icons apos navegacao reativa
 *
 * Exposicoes globais:
 *   window.madDocsToggleTheme()
 *   window.madDocsSearchOpen() / window.madDocsSearchClose()
 *   window.madDocsInit()  — chamado apos cada navegacao reativa
 */

(function () {
    'use strict';

    // ── Theme toggle ──────────────────────────────────────────────────
    function getTheme() {
        try {
            return localStorage.getItem('mad-docs-theme') || 'light';
        } catch (e) {
            return 'light';
        }
    }
    function setTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        try { localStorage.setItem('mad-docs-theme', theme); } catch (e) {}
        // Atualiza meta theme-color
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0b1020' : '#ffffff');
    }
    window.madDocsToggleTheme = function () {
        var current = getTheme();
        setTheme(current === 'dark' ? 'light' : 'dark');
    };

    // ── Tabs (Alpine factory) ────────────────────────────────────────
    window.madDocsTabs = function (defaultName) {
        return {
            tabs: [],
            active: defaultName || '',
            init: function (root) {
                if (!root) return;
                // Escaneia children procurando [data-tab-name]
                var nodes = root.querySelectorAll('[data-tab-name]');
                var found = [];
                nodes.forEach(function (n) {
                    // So filhos diretos do mad-docs-tabs-body (evita pegar tabs aninhadas)
                    var parent = n.closest('.mad-docs-tabs');
                    if (parent !== root) return;
                    var name  = n.getAttribute('data-tab-name') || '';
                    var label = n.getAttribute('data-tab-label') || name;
                    if (name) found.push({ name: name, label: label });
                });
                this.tabs = found;
                if (!this.active && found.length > 0) {
                    this.active = found[0].name;
                }
            }
        };
    };

    // ── Copy code button ─────────────────────────────────────────────
    window.madDocsCopy = function (uid) {
        try {
            var el   = document.getElementById(uid);
            var text = el ? el.textContent : '';
            if (!text) return false;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text);
                return true;
            }
            // Fallback p/ browsers antigos / sem HTTPS
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'absolute';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            return true;
        } catch (e) {
            console.error('[madDocsCopy]', e);
            return false;
        }
    };

    // ── Search palette (vanilla — /docs nao carrega Alpine) ──────────
    var searchIndex  = null;
    var searchState  = 'idle'; // idle | loading | ready | error
    var searchElems  = null;   // cache do DOM da palette
    var searchOpen   = false;
    var searchResults = [];
    var searchActive = 0;
    var searchDebounce = null;

    var MAX_RESULTS  = 20;
    var SNIPPET_PAD  = 80;

    /** lowercase + remove acentos (busca PT-BR: "instalacao" acha "Instalação") */
    function norm(s) {
        return String(s == null ? '' : s)
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase();
    }

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function loadSearchIndex(done) {
        if (searchState === 'ready' || searchState === 'loading') {
            if (searchState === 'ready' && done) done();
            return;
        }
        searchState = 'loading';
        var url = (window.MAD_DOCS_SEARCH_URL || '/assets/docs/search-index.json');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                searchIndex = Array.isArray(data) ? data : [];
                // Pre-normaliza uma vez — evita normalizar 200 docs a cada tecla
                for (var i = 0; i < searchIndex.length; i++) {
                    var d = searchIndex[i];
                    d._t = norm(d.title);
                    d._s = norm(d.section);
                    d._h = norm((d.headings || []).map(function (h) {
                        return (h && h.text) ? h.text : h;
                    }).join(' '));
                    d._b = norm((d.body || '') + ' ' + (d.snippet || ''));
                }
                searchState = 'ready';
                if (done) done();
            })
            .catch(function (err) {
                searchState = 'error';
                console.error('[madDocsSearch] falha ao carregar o indice', err);
                if (done) done();
            });
    }

    /**
     * Score de um doc contra os termos (AND entre termos).
     * title > headings > section > body.
     */
    function scoreDoc(doc, terms) {
        var total = 0;
        for (var i = 0; i < terms.length; i++) {
            var t = terms[i];
            var s = 0;
            var ti = doc._t.indexOf(t);
            if (ti !== -1) s += (ti === 0 ? 14 : 10);
            if (doc._h.indexOf(t) !== -1) s += 5;
            if (doc._s.indexOf(t) !== -1) s += 3;
            if (doc._b.indexOf(t) !== -1) s += 1;
            if (s === 0) return 0; // AND: termo ausente descarta o doc
            total += s;
        }
        return total;
    }

    /** Heading cujo texto casa com algum termo → deep-link na ancora. */
    function matchingHeading(doc, terms) {
        var hs = doc.headings || [];
        for (var i = 0; i < hs.length; i++) {
            var h = hs[i];
            if (!h || !h.id || !h.text) continue;
            var ht = norm(h.text);
            for (var j = 0; j < terms.length; j++) {
                if (ht.indexOf(terms[j]) !== -1) return h;
            }
        }
        return null;
    }

    /** Recorte do corpo ao redor do 1o match, com <mark> nos termos. */
    function buildSnippet(doc, terms) {
        var body = doc.body || '';
        var pos  = -1;
        if (body) {
            var nb = norm(body);
            for (var i = 0; i < terms.length && pos === -1; i++) {
                pos = nb.indexOf(terms[i]);
            }
        }
        var text;
        if (pos === -1) {
            text = doc.snippet || body.substring(0, 160);
        } else {
            var start = Math.max(0, pos - SNIPPET_PAD);
            var end   = Math.min(body.length, pos + SNIPPET_PAD * 2);
            text = (start > 0 ? '…' : '') + body.substring(start, end) + (end < body.length ? '…' : '');
        }
        return highlight(text, terms);
    }

    /** Escapa e envolve cada ocorrencia (acento-insensivel) em <mark>. */
    function highlight(text, terms) {
        var plain = String(text || '');
        var nt    = norm(plain);
        var hits  = [];
        for (var i = 0; i < terms.length; i++) {
            var t = terms[i];
            if (!t) continue;
            var from = 0;
            var at;
            while ((at = nt.indexOf(t, from)) !== -1) {
                hits.push([at, at + t.length]);
                from = at + t.length;
            }
        }
        if (!hits.length) return escHtml(plain);

        hits.sort(function (a, b) { return a[0] - b[0]; });
        var merged = [hits[0]];
        for (var k = 1; k < hits.length; k++) {
            var last = merged[merged.length - 1];
            if (hits[k][0] <= last[1]) {
                last[1] = Math.max(last[1], hits[k][1]);
            } else {
                merged.push(hits[k]);
            }
        }

        var out = '';
        var cursor = 0;
        for (var m = 0; m < merged.length; m++) {
            out += escHtml(plain.substring(cursor, merged[m][0]));
            out += '<mark>' + escHtml(plain.substring(merged[m][0], merged[m][1])) + '</mark>';
            cursor = merged[m][1];
        }
        out += escHtml(plain.substring(cursor));
        return out;
    }

    function runSearch(query) {
        var terms = norm(query).split(/\s+/).filter(function (t) { return t.length > 0; });
        if (!terms.length || norm(query).trim().length < 2 || !searchIndex) return [];

        var scored = [];
        for (var i = 0; i < searchIndex.length; i++) {
            var doc = searchIndex[i];
            var score = scoreDoc(doc, terms);
            if (score > 0) scored.push({ doc: doc, score: score });
        }
        scored.sort(function (a, b) {
            if (b.score !== a.score) return b.score - a.score;
            return a.doc.title.length - b.doc.title.length;
        });

        return scored.slice(0, MAX_RESULTS).map(function (row) {
            var h = matchingHeading(row.doc, terms);
            return {
                section: row.doc.section,
                title:   row.doc.title,
                url:     row.doc.url + (h ? '#' + h.id : ''),
                heading: h ? h.text : '',
                snippet: buildSnippet(row.doc, terms)
            };
        });
    }

    // ── DOM da palette ───────────────────────────────────────────────
    function buildPalette() {
        if (searchElems) return searchElems;

        var root = document.createElement('div');
        root.className = 'mad-docs-search-palette';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');
        root.setAttribute('aria-label', 'Buscar na documentacao');
        root.style.display = 'none';
        root.innerHTML =
            '<div class="mad-docs-search-backdrop" data-search-close></div>' +
            '<div class="mad-docs-search-modal">' +
                '<div class="mad-docs-search-input-wrap">' +
                    '<span class="docs-search-ico">&#8981;</span>' +
                    '<input type="text" class="mad-docs-search-input" autocomplete="off" spellcheck="false" ' +
                           'placeholder="Buscar na documentacao..." aria-label="Buscar na documentacao">' +
                    '<span class="mad-docs-search-esc">ESC</span>' +
                '</div>' +
                '<div class="mad-docs-search-results"></div>' +
            '</div>';
        document.body.appendChild(root);

        searchElems = {
            root:    root,
            input:   root.querySelector('.mad-docs-search-input'),
            results: root.querySelector('.mad-docs-search-results')
        };

        root.addEventListener('click', function (e) {
            if (e.target.hasAttribute && e.target.hasAttribute('data-search-close')) closePalette();
        });

        searchElems.input.addEventListener('input', function () {
            clearTimeout(searchDebounce);
            var val = searchElems.input.value;
            searchDebounce = setTimeout(function () {
                searchResults = runSearch(val);
                searchActive  = 0;
                renderResults();
            }, 120);
        });

        searchElems.input.addEventListener('keydown', onPaletteKey);

        searchElems.results.addEventListener('click', function (e) {
            var li = e.target.closest && e.target.closest('li[data-idx]');
            if (!li) return;
            goToResult(searchResults[parseInt(li.getAttribute('data-idx'), 10)]);
        });

        return searchElems;
    }

    function renderResults() {
        var el = searchElems.results;
        var q  = searchElems.input.value.trim();

        if (searchState === 'loading') {
            el.innerHTML = '<div class="mad-docs-search-hint">Carregando índice...</div>';
            return;
        }
        if (searchState === 'error') {
            el.innerHTML = '<div class="mad-docs-search-empty">Não foi possível carregar o índice de busca.</div>';
            return;
        }
        if (q.length < 2) {
            el.innerHTML = '<div class="mad-docs-search-hint">Digite ao menos 2 caracteres para buscar em toda a documentacao.</div>';
            return;
        }
        if (!searchResults.length) {
            el.innerHTML = '<div class="mad-docs-search-empty">Nenhum resultado para <strong>' + escHtml(q) + '</strong>.</div>';
            return;
        }

        var html = '<ul class="mad-docs-search-list">';
        for (var i = 0; i < searchResults.length; i++) {
            var r = searchResults[i];
            html += '<li data-idx="' + i + '"' + (i === searchActive ? ' class="is-active"' : '') + '>'
                 +      '<div class="mad-docs-search-result-section">' + escHtml(r.section)
                 +          (r.heading ? ' &rsaquo; ' + escHtml(r.heading) : '') + '</div>'
                 +      '<div class="mad-docs-search-result-title">' + escHtml(r.title) + '</div>'
                 +      '<div class="mad-docs-search-result-snippet">' + r.snippet + '</div>'
                 +  '</li>';
        }
        html += '</ul>';
        el.innerHTML = html;
        scrollActiveIntoView();
    }

    function scrollActiveIntoView() {
        var li = searchElems.results.querySelector('li.is-active');
        if (li && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
    }

    function setActive(idx) {
        if (!searchResults.length) return;
        searchActive = Math.max(0, Math.min(searchResults.length - 1, idx));
        var items = searchElems.results.querySelectorAll('li[data-idx]');
        for (var i = 0; i < items.length; i++) {
            items[i].classList.toggle('is-active', i === searchActive);
        }
        scrollActiveIntoView();
    }

    function onPaletteKey(e) {
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActive(searchActive + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActive(searchActive - 1);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            goToResult(searchResults[searchActive]);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            closePalette();
        }
    }

    function goToResult(r) {
        if (!r || !r.url) return;
        closePalette();
        window.location.href = r.url;
    }

    function openPalette() {
        var els = buildPalette();
        searchOpen = true;
        els.root.style.display = '';
        document.body.style.overflow = 'hidden';
        loadSearchIndex(function () {
            if (!searchOpen) return;
            if (els.input.value.trim().length >= 2) {
                searchResults = runSearch(els.input.value);
                searchActive  = 0;
            }
            renderResults();
        });
        renderResults();
        els.input.focus();
        els.input.select();
    }

    function closePalette() {
        if (!searchElems) return;
        searchOpen = false;
        searchElems.root.style.display = 'none';
        document.body.style.overflow = '';
    }

    window.madDocsSearchOpen  = openPalette;
    window.madDocsSearchClose = closePalette;

    // Atalho global cmd+K / ctrl+K (registrado uma unica vez)
    if (!window.__madDocsSearchKeys) {
        window.__madDocsSearchKeys = true;
        window.addEventListener('keydown', function (e) {
            if ((e.metaKey || e.ctrlKey) && e.key && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                searchOpen ? closePalette() : openPalette();
            } else if (e.key === 'Escape' && searchOpen) {
                closePalette();
            }
        });
    }

    // ── Scrollspy TOC ────────────────────────────────────────────────
    var scrollspyObserver = null;
    function initScrollspy() {
        if (scrollspyObserver) {
            scrollspyObserver.disconnect();
            scrollspyObserver = null;
        }
        var toc = document.getElementById('docs-toc');
        if (!toc) return;
        var links = toc.querySelectorAll('a[href^="#"]');
        if (!links.length) return;
        var idMap = {};
        links.forEach(function (a) {
            var id = a.getAttribute('href').substring(1);
            idMap[id] = a;
        });

        var headings = [];
        Object.keys(idMap).forEach(function (id) {
            var h = document.getElementById(id);
            if (h) headings.push(h);
        });
        if (!headings.length) return;

        scrollspyObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                var id = entry.target.id;
                var link = idMap[id];
                if (!link) return;
                if (entry.isIntersecting) {
                    // Limpa active de todos, marca este
                    Object.keys(idMap).forEach(function (k) { idMap[k].classList.remove('is-active'); });
                    link.classList.add('is-active');
                }
            });
        }, {
            rootMargin: '-80px 0px -75% 0px',
            threshold: 0
        });

        headings.forEach(function (h) { scrollspyObserver.observe(h); });
    }

    // ── Re-init pos navegacao ────────────────────────────────────────
    function reinit() {
        // Lucide icons (se carregado)
        if (window.lucide) {
            try { window.lucide.createIcons(); } catch (e) {}
        }
        // Prism syntax (se carregado)
        if (window.Prism) {
            try { window.Prism.highlightAll(); } catch (e) {}
        }
        // Scrollspy
        setTimeout(initScrollspy, 100);
    }

    window.madDocsInit = reinit;

    // ── Boot ─────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        // Lucide
        if (window.lucide) {
            try { window.lucide.createIcons(); } catch (e) {}
        }
        // Scrollspy inicial
        setTimeout(initScrollspy, 200);

        // Anchor links smooth scroll com offset
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href^="#"]');
            if (!a) return;
            var href = a.getAttribute('href');
            if (!href || href.length < 2) return;
            var id = href.substring(1);
            var target = document.getElementById(id);
            if (!target) return;
            // So intercepta se for link interno simples
            if (a.classList.contains('mad-docs-anchor') || a.closest('.docs-toc-list')) {
                e.preventDefault();
                var offset = 80;
                var top = target.getBoundingClientRect().top + window.pageYOffset - offset;
                window.scrollTo({ top: top, behavior: 'smooth' });
                history.pushState(null, '', href);
            }
        });
    });

    // Eager init Lucide quando script carrega
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
        setTimeout(reinit, 100);
    }
})();
