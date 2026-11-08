/**
 * MadAgenda — client da agenda por recurso (<mad-agenda>).
 *
 * Registra `Alpine.data('madAgenda', cfg)`. A barra (navegação, visões,
 * filtro de recurso) é Alpine; a grade é desenhada aqui a partir do payload
 * JSON (`<script type="application/json" data-ag-payload>`) e os cliques,
 * arrastes e teclado são DELEGADOS no palco — redesenhar não religa nada.
 *
 * Servidor (MadWire.call no componente da tela):
 *   onAgendaLoad(key, ctx)                         → evento mad-agenda:data
 *   onAgendaMove(key, id, start, end, res, ctx)    → mad-agenda:result + :data
 *   onAgendaResize(key, id, start, end, res, ctx)  → idem
 * Métodos da tela (Mad.call): on-slot-click {start,end,date,time,resource},
 * on-event-click {id}. Alvos de navegação (Mad.go): slot/event-click-target.
 */
(function (root) {
    'use strict';

    var DAY = 1440;
    // Largura (px) de um cartão: abaixo de FULL_W vira compacto (hora + 1ª palavra,
    // status em bolinha); abaixo de MIN_W não cabe — o grupo mostra "+N".
    var FULL_W = 92, MIN_W = 46;
    var scrollMemo = root.__madAgendaScroll || (root.__madAgendaScroll = {});

    // ── utilitários ──────────────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function hhmm(m) { m = Math.max(0, Math.min(DAY, m)); return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
    function minOf(dt) { return parseInt(dt.substr(11, 2), 10) * 60 + parseInt(dt.substr(14, 2), 10); }
    function dateOf(dt) { return dt.substr(0, 10); }
    function parseDate(d) { var p = d.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }
    function fmtDate(o) { return o.getFullYear() + '-' + pad(o.getMonth() + 1) + '-' + pad(o.getDate()); }
    function addDays(d, n) { var o = parseDate(d); o.setDate(o.getDate() + n); return fmtDate(o); }
    function addMonths(d, n) { var o = parseDate(d); o.setDate(1); o.setMonth(o.getMonth() + n); return fmtDate(o); }
    function dt(d, m) {
        // minuto pode passar de 24h (arrastar até o fim): normaliza para o dia seguinte
        var extra = Math.floor(m / DAY);
        return (extra ? addDays(d, extra) : d) + ' ' + hhmm(m - extra * DAY);
    }
    function diffMin(a, b) { // b - a, "Y-m-d H:i"
        var da = parseDate(dateOf(a)), db = parseDate(dateOf(b));
        return Math.round((db - da) / 864e5) * DAY + minOf(b) - minOf(a);
    }
    function snap(m, step) { return Math.round(m / step) * step; }
    function t(cfg, k, rep) {
        var s = (cfg.i18n && cfg.i18n[k]) || k;
        if (rep) Object.keys(rep).forEach(function (r) { s = s.split(':' + r).join(rep[r]); });
        return s;
    }

    function wire(el, method, params, opts) {
        var MW = root.MadWire;
        if (!MW || typeof MW.call !== 'function') return Promise.resolve({ ok: false, reason: 'config' });
        var wrapper = (MW.resolveWrapper && MW.resolveWrapper(el)) || el.closest('[mad-component]') || el;
        try { return Promise.resolve(MW.call(wrapper, method, params, {}, opts || {})); }
        catch (e) { console.warn('[MadAgenda] MadWire.call falhou', e); return Promise.resolve({ ok: false, reason: 'error' }); }
    }
    function toast(msg, type) {
        if (root.madToast) root.madToast({ message: msg, type: type || 'warning' });
    }
    function go(target, params) {
        if (!target || !root.Mad || !root.Mad.go) return;
        var url = target.url || '';
        if (url && root.Mad._resolveUrlTemplate) url = root.Mad._resolveUrlTemplate(url, params);
        root.Mad.go(target['class'], target.method || 'show', params, url || undefined);
    }

    // Faixas de expediente → minutos fechados dentro de [min, max].
    function closedRanges(open, min, max) {
        if (!open) return [];
        if (!open.length) return [[min, max]];
        var out = [], cur = min;
        open.forEach(function (w) {
            if (w[0] > cur) out.push([cur, Math.min(w[0], max)]);
            cur = Math.max(cur, w[1]);
        });
        if (cur < max) out.push([cur, max]);
        return out.filter(function (r) { return r[1] > r[0]; });
    }
    function hoursFor(res, date) {
        if (!res || !res.hours) return null; // aberto o dia todo
        if (res.hours.all) return res.hours.all;
        var by = res.hours.by || {};
        if (by[date]) return by[date];
        var dow = String(parseDate(date).getDay());
        return by[dow] || [];
    }
    function unionHours(list) {
        var all = [], anyOpen = false;
        for (var i = 0; i < list.length; i++) {
            if (list[i] === null) return null;
            all = all.concat(list[i]);
            if (list[i].length) anyOpen = true;
        }
        if (!anyOpen) return [];
        all.sort(function (a, b) { return a[0] - b[0]; });
        var out = [];
        all.forEach(function (w) {
            var last = out[out.length - 1];
            if (last && w[0] <= last[1]) last[1] = Math.max(last[1], w[1]); else out.push([w[0], w[1]]);
        });
        return out;
    }

    // Lanes: eventos que se sobrepõem dividem a largura da coluna.
    function layout(items) {
        items.sort(function (a, b) { return a.s - b.s || b.e - a.e; });
        var cluster = [], clusterEnd = -1, lanesEnd = [], cid = 0;
        function flush() {
            var n = lanesEnd.length || 1;
            cluster.forEach(function (it) { it.lanes = n; it.cluster = cid; });
            cluster = []; lanesEnd = []; cid++;
        }
        items.forEach(function (it) {
            if (it.s >= clusterEnd && cluster.length) flush();
            var lane = 0;
            while (lane < lanesEnd.length && lanesEnd[lane] > it.s) lane++;
            lanesEnd[lane] = it.e;
            it.lane = lane;
            cluster.push(it);
            clusterEnd = Math.max(clusterEnd, it.e);
        });
        flush();
        return items;
    }

    function init() {
        var Alpine = root.Alpine;
        if (!Alpine || Alpine.__madAgendaRegistered) return;
        Alpine.__madAgendaRegistered = true;

        Alpine.data('madAgenda', function (cfg) {
            cfg = cfg || {};
            return {
                cfg: cfg,
                data: { resources: [], events: [], blocks: [] },
                view: 'day',
                date: '',
                resource: '',
                filters: {},
                loading: false,
                mobile: false,
                title: '',
                _loadedAt: Date.now(),
                _drag: null,
                _seq: 0,
                _timers: [],

                init: function () {
                    var self = this;
                    var node = this.$el.querySelector('script[data-ag-payload]');
                    var payload = {};
                    try { payload = JSON.parse(node ? node.textContent : '{}') || {}; } catch (e) { payload = {}; }
                    var mq = root.matchMedia ? root.matchMedia('(max-width: 640px)') : null;
                    this.mobile = !!(mq && mq.matches);
                    if (mq) {
                        var onMq = function (e) { self.mobile = e.matches; self.render(); };
                        if (mq.addEventListener) mq.addEventListener('change', onMq); else if (mq.addListener) mq.addListener(onMq);
                    }
                    this.apply(payload, true);

                    this.$el.addEventListener('mad-agenda:data', function (e) { self.apply(e.detail || {}, false); });
                    this.$el.addEventListener('mad-agenda:result', function (e) {
                        var d = e.detail || {};
                        if (!d.ok) self._flash(d.id);
                    });
                    var stage = this.$refs.stage;
                    stage.addEventListener('pointerdown', function (e) { self.onPointerDown(e); });
                    stage.addEventListener('pointermove', function (e) { self.onHover(e); });
                    stage.addEventListener('pointerleave', function () { self._hideGhost(); });
                    stage.addEventListener('click', function (e) { self.onClick(e); });
                    stage.addEventListener('keydown', function (e) { self.onKey(e); });
                    stage.addEventListener('scroll', function () {
                        var sc = self._scroller();
                        if (sc) scrollMemo[self.cfg.key] = { date: self.date, view: self.view, top: sc.scrollTop };
                    }, true);
                    var tools = this.$el.querySelector('.mad-ag-tools');
                    if (tools) tools.addEventListener('change', function (e) {
                        if (e.target && e.target.name) self.reload();
                    });

                    if (root.ResizeObserver) {
                        var raf = 0;
                        this._ro = new ResizeObserver(function () {
                            cancelAnimationFrame(raf);
                            raf = requestAnimationFrame(function () {
                                if (self._drag || self.view === 'month' || (self.view === 'week' && self.mobile)) return;
                                var n = Math.max(1, self.$refs.stage.querySelectorAll('.mad-ag-col').length);
                                if (Math.abs(self._colWidth(n, self.view === 'week') - (self._usedColW || 0)) > 6) self.render();
                            });
                        });
                        this._ro.observe(stage);
                    }

                    // linha do agora / auto-atualização
                    this._timers.push(setInterval(function () {
                        if (!document.body.contains(self.$el)) { self._stop(); return; }
                        self._paintNow();
                    }, 60000));
                    if (cfg.autoRefresh > 0) {
                        this._timers.push(setInterval(function () {
                            if (!document.body.contains(self.$el)) { self._stop(); return; }
                            if (self._drag || document.hidden || self.loading) return;
                            self.load({}, { background: true });
                        }, cfg.autoRefresh * 1000));
                    }
                },

                destroy: function () { this._stop(); },
                _stop: function () {
                    this._timers.forEach(clearInterval); this._timers = [];
                    if (this._ro) { this._ro.disconnect(); this._ro = null; }
                    this.closeMore();
                },

                // ── estado / servidor ─────────────────────────────────────
                apply: function (p, first) {
                    var prevDate = this.date, prevView = this.view;
                    this.data = {
                        resources: p.resources || [], events: p.events || [], blocks: p.blocks || [],
                        min: p.min != null ? p.min : 480, max: p.max != null ? p.max : 1080,
                        today: p.today || fmtDate(new Date()), now: p.now != null ? p.now : null,
                        start: p.start, end: p.end, truncated: !!p.truncated,
                    };
                    this._loadedAt = Date.now();
                    this.view = p.view || this.view || (cfg.views || ['day'])[0];
                    this.date = p.date || this.date || this.data.today;
                    this.resource = p.resource != null ? String(p.resource) : this.resource;
                    this.filters = p.filters || this.filters || {};
                    this.loading = false;
                    if (this.mobile && this.view === 'day' && !this.resource && this.data.resources.length) {
                        this.resource = this.data.resources[0].id;
                    }
                    this.title = this._title();
                    this.render();
                    this._syncBar();
                    var memo = scrollMemo[cfg.key];
                    if (first && memo && memo.date === this.date && memo.view === this.view) {
                        var sc = this._scroller(); if (sc) sc.scrollTop = memo.top;
                    } else if (first || prevDate !== this.date || prevView !== this.view) {
                        this._scrollToStart();
                    }
                },

                // Data e recurso da barra são componentes do framework (madDatePicker e
                // o combo de data-mad-select): o valor vem daqui, não de binding.
                _syncBar: function () {
                    var self = this, dp = this.$el.querySelector('.mad-ag-datepick');
                    if (dp && root.Alpine && root.Alpine.$data) {
                        try { var p = root.Alpine.$data(dp); if (p && p.setValue) p.setValue(this.date); } catch (e) { /* picker montando */ }
                    }
                    var sel = this.$refs.resSelect;
                    if (!sel) return;
                    var opts = [];
                    if (this.view !== 'day') opts.push(['', t(cfg, 'all_resources')]);
                    this.data.resources.forEach(function (r) { opts.push([String(r.id), r.title || '']); });
                    var sig = JSON.stringify(opts), v = this.resource == null ? '' : String(this.resource);
                    if (sel._agSig !== sig) {
                        sel._agSig = sig;
                        sel.innerHTML = opts.map(function (o) { return '<option value="' + esc(o[0]) + '">' + esc(o[1]) + '</option>'; }).join('');
                        if (sel._madSelect && sel._madSelect.refreshOptions) sel._madSelect.refreshOptions(false);
                    }
                    if (sel._madSelect && sel._madSelect.setValue) { sel._madSelect.setValue(v, true); return; }
                    sel.value = v;
                    if (root._madCreateSelect) root._madCreateSelect(sel);
                    // o combo pode montar depois (MutationObserver do framework)
                    setTimeout(function () { if (sel._madSelect) sel._madSelect.setValue(self.resource == null ? '' : String(self.resource), true); }, 0);
                },


                ctx: function (over) {
                    var c = { date: this.date, view: this.view, resource: this.resource || '', filters: this._collectFilters() };
                    over = over || {};
                    Object.keys(over).forEach(function (k) { c[k] = over[k]; });
                    return c;
                },

                _collectFilters: function () {
                    var out = {};
                    var tools = this.$el.querySelector('.mad-ag-tools');
                    if (!tools) return this.filters || {};
                    tools.querySelectorAll('input[name], select[name], textarea[name]').forEach(function (f) {
                        if ((f.type === 'checkbox' || f.type === 'radio') && !f.checked) {
                            if (f.type === 'checkbox' && !(f.name in out)) out[f.name] = '';
                            return;
                        }
                        out[f.name] = f.value;
                    });
                    return out;
                },

                load: function (over, opts) {
                    var self = this;
                    var c = this.ctx(over);
                    this.date = c.date; this.view = c.view; this.resource = c.resource;
                    this.title = this._title();
                    this._syncBar();
                    this.loading = true;
                    var seq = ++this._seq;
                    return wire(this.$el, 'onAgendaLoad', [cfg.key, c], opts).then(function (res) {
                        if (seq === self._seq) self.loading = false;
                        return res;
                    });
                },
                reload: function () { return this.load({}); },

                prev: function () { this.load({ date: this._step(-1) }); },
                next: function () { this.load({ date: this._step(1) }); },
                today: function () { this.load({ date: this.data.today }); },
                goDate: function (d) { if (/^\d{4}-\d{2}-\d{2}$/.test(d)) this.load({ date: d }); },
                setView: function (v) { if (v !== this.view) this.load({ view: v }); },
                setResource: function (r) {
                    r = r == null ? '' : String(r);
                    if (r === String(this.resource == null ? '' : this.resource)) return;
                    this.resource = r;
                    if (this.view === 'day') { this.render(); } else { this.load({ resource: r }); }
                },
                openDay: function (d) { this.load({ date: d, view: this.cfg.views.indexOf('day') >= 0 ? 'day' : this.view }); },
                _step: function (n) {
                    if (this.view === 'week') return addDays(this.date, 7 * n);
                    if (this.view === 'month') return addMonths(this.date, n);
                    return addDays(this.date, n);
                },
                navLabel: function (dir) {
                    var k = (dir < 0 ? 'prev_' : 'next_') + (this.view === 'week' ? 'week' : this.view === 'month' ? 'month' : 'day');
                    return t(cfg, k);
                },
                barKey: function (e) {
                    var tag = (e.target.tagName || '').toLowerCase();
                    if (tag === 'input' || tag === 'select' || tag === 'textarea') return;
                    if (e.target.closest && e.target.closest('.mad-sel, .mad-ag-datepick, .mad-drp-popup')) return;
                    if (e.key === 'ArrowLeft') { e.preventDefault(); this.prev(); }
                    else if (e.key === 'ArrowRight') { e.preventDefault(); this.next(); }
                },
                tr: function (k, rep) { return t(cfg, k, rep); },

                _title: function () {
                    var loc = cfg.locale || 'pt-BR';
                    try {
                        var d = parseDate(this.date);
                        if (this.view === 'month') {
                            var m = new Intl.DateTimeFormat(loc, { month: 'long', year: 'numeric' }).format(d);
                            return m.charAt(0).toUpperCase() + m.slice(1);
                        }
                        if (this.view === 'week') {
                            var ws = this._weekStart(this.date), s = parseDate(ws), e = parseDate(addDays(ws, 6));
                            var f = new Intl.DateTimeFormat(loc, { day: 'numeric', month: 'short' });
                            return f.formatRange ? f.formatRange(s, e) : t(cfg, 'week_of', { start: f.format(s), end: f.format(e) });
                        }
                        var txt = new Intl.DateTimeFormat(loc, { weekday: 'long', day: 'numeric', month: 'long' }).format(d);
                        txt = txt.charAt(0).toUpperCase() + txt.slice(1);
                        return this.date === this.data.today ? t(cfg, 'today') + ', ' + txt.charAt(0).toLowerCase() + txt.slice(1) : txt;
                    } catch (e) { return this.date; }
                },
                _weekStart: function (d) {
                    var o = parseDate(d), diff = (o.getDay() - (cfg.weekStart || 0) + 7) % 7;
                    return addDays(d, -diff);
                },

                // ── desenho ───────────────────────────────────────────────
                render: function () {
                    var stage = this.$refs.stage;
                    if (!stage) return;
                    this._rmap = this._resById();
                    var html;
                    if (this.view === 'month') html = this._month();
                    else if (this.view === 'week' && this.mobile) html = this._weekList();
                    else html = this._grid(this.view === 'week' ? this._weekCols() : this._dayCols());
                    this.closeMore();
                    stage.innerHTML = html;
                    this._fitCorner();
                    this._paintNow();
                },

                // Palavra do contador no canto (58px; 46 no celular): encolhe e, se ainda
                // não couber inteira, sai do canto e fica na dica — nunca cortada no meio.
                _fitCorner: function () {
                    var sm = this.$refs.stage.querySelector('.mad-ag-corner small');
                    if (!sm || sm.scrollWidth <= sm.clientWidth + 1) return;
                    sm.classList.add('is-tight');
                    if (sm.scrollWidth <= sm.clientWidth + 1) return;
                    var corner = sm.parentNode;
                    corner.setAttribute('title', corner.textContent.replace(/(\d)(\D)/, '$1 $2'));
                    corner.setAttribute('aria-label', corner.getAttribute('title'));
                    sm.hidden = true;
                },

                _resById: function () {
                    var m = {};
                    this.data.resources.forEach(function (r) { m[r.id] = r; });
                    return m;
                },
                _evColor: function (ev, res) { return ev.color || (res && res.color) || ''; },

                _dayCols: function () {
                    var self = this, d = this.date, res = this.data.resources.slice();
                    if (this.mobile && this.resource) res = res.filter(function (r) { return r.id === self.resource; });
                    if (!this.data.resources.length) {
                        res = [{ id: null, title: '', subtitle: '', _pseudo: true, hours: null }];
                    }
                    return res.map(function (r) {
                        var open = r._pseudo ? null : hoursFor(r, d);
                        return {
                            date: d, resource: r, resId: r.id,
                            events: self.data.events.filter(function (e) { return dateOf(e.start) === d && (r._pseudo || e.resource_id === r.id); }),
                            blocks: self.data.blocks.filter(function (b) { return dateOf(b.start) <= d && dateOf(b.end) >= d && (b.resource_id === null || r._pseudo || b.resource_id === r.id); }),
                            open: open,
                        };
                    });
                },
                _weekCols: function () {
                    var self = this, start = this._weekStart(this.date), resId = this.resource || '';
                    var rmap = this._rmap || this._resById(), cols = [];
                    for (var i = 0; i < 7; i++) {
                        var d = addDays(start, i);
                        var open = resId && rmap[resId] ? hoursFor(rmap[resId], d)
                            : (this.data.resources.length ? unionHours(this.data.resources.map(function (r) { return hoursFor(r, d); })) : null);
                        cols.push({
                            date: d, resource: null, resId: resId || null,
                            events: this.data.events.filter(function (e) { return dateOf(e.start) === d && (!resId || e.resource_id === resId); }),
                            blocks: this.data.blocks.filter(function (b) { return dateOf(b.start) <= d && dateOf(b.end) >= d && (b.resource_id === null || (resId && b.resource_id === resId)); }),
                            open: open,
                        });
                    }
                    return cols;
                },

                _occupancy: function (col) {
                    if (col.resource && col.resource.occupancy != null) return col.resource.occupancy;
                    var ign = cfg.occIgnore || [], used = 0;
                    var avail = col.open === null ? (this.data.max - this.data.min)
                        : col.open.reduce(function (s, w) { return s + (w[1] - w[0]); }, 0);
                    if (!avail) return 0;
                    col.events.forEach(function (e) {
                        if (ign.indexOf(e.status) >= 0) return;
                        var s = minOf(e.start), en = dateOf(e.end) === dateOf(e.start) ? minOf(e.end) : DAY;
                        used += Math.max(0, en - s);
                    });
                    return Math.min(100, Math.round(used / avail * 100));
                },
                _evWord: function (n) {
                    var l = cfg.eventLabel;
                    if (l && l.length === 2) return n === 1 ? l[0] : l[1];
                    return t(cfg, n === 1 ? 'event' : 'events');
                },
                _countLabel: function (n) { return n + ' ' + this._evWord(n); },
                _activeCount: function (list) {
                    var ign = cfg.occIgnore || [];
                    return list.filter(function (e) { return ign.indexOf(e.status) < 0; }).length;
                },

                _avatar: function (r, cls) {
                    var c = r.color ? ' style="--ag-c:' + esc(r.color) + '"' : '';
                    return '<span class="' + cls + '"' + c + ' aria-hidden="true">'
                        + (r.avatar ? '<img src="' + esc(r.avatar) + '" alt="" loading="lazy">' : esc(r.initials || (r.title || '?').charAt(0))) + '</span>';
                },

                _grid: function (cols) {
                    var self = this, d = this.data, hp = cfg.hourHeight || 64;
                    var min = d.min, max = d.max, height = (max - min) / 60 * hp;
                    var isWeek = this.view === 'week';
                    var colW = this._colWidth(cols.length, isWeek);
                    this._usedColW = colW;
                    this._more = {};
                    var total = 0;
                    cols.forEach(function (c) { total += self._activeCount(c.events); });

                    var head = '<div class="mad-ag-head" role="row"><div class="mad-ag-corner" role="columnheader"><strong>' + total + '</strong><small>' + esc(self._evWord(total)) + '</small></div>';
                    cols.forEach(function (c) {
                        if (isWeek) {
                            var o = parseDate(c.date), loc = cfg.locale || 'pt-BR';
                            var wd = new Intl.DateTimeFormat(loc, { weekday: 'short' }).format(o).replace('.', '');
                            var n = self._activeCount(c.events);
                            head += '<button type="button" role="columnheader" class="mad-ag-colhead mad-ag-dayhead' + (c.date === d.today ? ' is-today' : '') + '" data-ag-open-day="' + c.date + '">'
                                + '<span class="mad-ag-wd">' + esc(wd) + '</span><span class="mad-ag-dn">' + o.getDate() + '</span>'
                                + '<span class="mad-ag-meta">' + (c.open && !c.open.length ? esc(t(cfg, 'closed')) : (n ? esc(self._countLabel(n)) : '')) + '</span></button>';
                        } else if (c.resource && c.resource._pseudo) {
                            head += '<div class="mad-ag-colhead" role="columnheader"><span class="mad-ag-coltxt"><span class="mad-ag-colname">' + esc(self.title) + '</span><span class="mad-ag-meta">' + esc(self._countLabel(self._activeCount(c.events))) + '</span></span></div>';
                        } else {
                            var r = c.resource, occ = self._occupancy(c), off = c.open && !c.open.length;
                            var meta = [];
                            if (r.subtitle) meta.push(esc(r.subtitle));
                            if (off) meta.push(esc(t(cfg, 'off')));
                            else {
                                meta.push(esc(self._countLabel(self._activeCount(c.events))));
                            }
                            head += '<div class="mad-ag-colhead" role="columnheader"' + (r.color ? ' style="--ag-c:' + esc(r.color) + '"' : '') + '>'
                                + self._avatar(r, 'mad-ag-avatar')
                                + '<span class="mad-ag-coltxt"><span class="mad-ag-colname" title="' + esc(r.title) + '">' + esc(r.title) + '</span>'
                                + '<span class="mad-ag-meta">' + meta.join(' · ') + '</span></span>'
                                + (cfg.occupancy && !off ? '<span class="mad-ag-occ-n" title="' + esc(t(cfg, 'occupied', { n: occ })) + '">' + occ + '%</span>' : '')
                                + (cfg.occupancy && !off ? '<span class="mad-ag-occ" aria-hidden="true"><i style="width:' + occ + '%"></i></span>' : '')
                                + '</div>';
                        }
                    });
                    head += '</div>';

                    var gutter = '<div class="mad-ag-gutter" aria-hidden="true">';
                    for (var m = min; m < max; m += 60) {
                        gutter += '<div class="mad-ag-hour" style="top:' + ((m - min) / 60 * hp) + 'px">' + hhmm(m) + '</div>';
                    }
                    gutter += '</div>';

                    var body = '';
                    cols.forEach(function (c, ci) {
                        var off = c.open && !c.open.length;
                        var label = isWeek ? c.date : (c.resource && c.resource.title) || '';
                        body += '<div class="mad-ag-col' + (off ? ' is-off' : '') + (c.date === d.today ? ' is-today' : '') + '" role="gridcell" aria-label="' + esc(label) + '"'
                            + ' data-ag-col="' + ci + '" data-date="' + c.date + '" data-res="' + esc(c.resId == null ? '' : c.resId) + '">';
                        closedRanges(c.open, min, max).forEach(function (r) {
                            body += '<div class="mad-ag-closed" style="top:' + ((r[0] - min) / 60 * hp) + 'px;height:' + ((r[1] - r[0]) / 60 * hp) + 'px">'
                                + (off ? '<span>' + esc(t(cfg, 'off')) + '</span>' : '') + '</div>';
                        });
                        c.blocks.forEach(function (b) {
                            var s = dateOf(b.start) < c.date ? 0 : minOf(b.start);
                            var e = dateOf(b.end) > c.date ? DAY : minOf(b.end);
                            s = Math.max(s, min); e = Math.min(e, max);
                            if (e <= s) return;
                            body += '<div class="mad-ag-block mad-ag-block--' + esc(b.kind) + '" data-ag-block style="top:' + ((s - min) / 60 * hp) + 'px;height:' + ((e - s) / 60 * hp) + 'px"><span>' + esc(b.label) + '</span></div>';
                        });
                        var items = c.events.map(function (e) {
                            var s = minOf(e.start), en = dateOf(e.end) === dateOf(e.start) ? minOf(e.end) : DAY;
                            return { ev: e, s: s, e: Math.max(en, s + 1) };
                        });
                        body += self._colEvents(layout(items), ci, colW, min, hp, isWeek);
                        body += '</div>';
                    });

                    var style = '--ag-cols:' + cols.length + ';--ag-hour:' + hp + 'px';
                    return '<div class="mad-ag-scroll' + (isWeek ? ' is-week' : '') + '" style="' + style + '" role="grid" aria-label="' + esc(this.title) + '">'
                        + head + '<div class="mad-ag-body" style="height:' + height + 'px">' + gutter + body
                        + '<div class="mad-ag-now" hidden><span></span></div></div></div>';
                },

                // Largura de uma coluna da grade, estimada pelo palco (a grade tem
                // largura mínima por coluna e rola de lado quando não cabe).
                _colWidth: function (n, isWeek) {
                    var stage = this.$refs.stage, gutter = this.mobile ? 46 : 58;
                    var minCol = this.mobile ? 0 : (isWeek ? 120 : 160);
                    var w = stage ? stage.clientWidth : 0;
                    if (!w) return Math.max(minCol, 160);
                    return Math.max(minCol, Math.floor((w - gutter - 2) / Math.max(1, n)));
                },

                // Eventos de uma coluna. Grupo de sobrepostos que não cabe (cada cartão
                // com menos de MIN_W px) mostra os que cabem e um "+N" que abre a lista
                // do horário; cartão estreito vira compacto. Nada de texto cortado no meio.
                _colEvents: function (items, ci, colW, min, hp, isWeek) {
                    var self = this, out = '', groups = {}, order = [];
                    items.forEach(function (it) {
                        if (!groups[it.cluster]) { groups[it.cluster] = []; order.push(it.cluster); }
                        groups[it.cluster].push(it);
                    });
                    var fit = Math.max(1, Math.floor((colW - 4) / MIN_W));
                    order.forEach(function (g) {
                        var list = groups[g], lanes = list[0].lanes;
                        if (lanes <= fit) {
                            list.forEach(function (it) { out += self._eventHtml(it, min, hp, isWeek, colW / lanes - 6 < FULL_W); });
                            return;
                        }
                        var shown = fit - 1, hidden = [];
                        list.forEach(function (it) {
                            if (it.lane < shown) { it.lanes = fit; out += self._eventHtml(it, min, hp, isWeek, colW / fit - 6 < FULL_W); }
                            else hidden.push(it);
                        });
                        out += self._moreHtml(ci + ':' + g, hidden, shown, fit, min, hp);
                    });
                    return out;
                },

                _moreHtml: function (key, hidden, lane, lanes, min, hp) {
                    var s = DAY, e = 0;
                    hidden.forEach(function (it) { s = Math.min(s, it.s); e = Math.max(e, it.e); });
                    this._more[key] = hidden.map(function (it) { return it.ev; });
                    var top = (Math.max(s, min) - min) / 60 * hp + 1;
                    var h = Math.max(22, (e - Math.max(s, min)) / 60 * hp - 2);
                    var w = 100 / lanes, n = hidden.length;
                    var aria = this._countLabel(n) + ' · ' + hhmm(s) + '–' + hhmm(e >= DAY ? DAY : e);
                    return '<button type="button" class="mad-ag-more" data-ag-more="' + esc(key) + '" aria-haspopup="dialog" aria-label="' + esc(aria) + '" title="' + esc(aria) + '"'
                        + ' style="top:' + top + 'px;height:' + h + 'px;left:calc(' + (lane * w) + '% + 3px);width:calc(' + w + '% - 6px)">'
                        + '+' + n + '</button>';
                },

                // Lista do horário (aberta pelo "+N"): um item por evento; clique ou
                // Enter = on-event-click. Esc ou clique fora fecha e devolve o foco.
                openMore: function (btn) {
                    this.closeMore();
                    var key = btn.getAttribute('data-ag-more');
                    var list = (this._more || {})[key] || [];
                    var body = btn.closest('.mad-ag-body');
                    if (!list.length || !body) return;
                    var self = this, rmap = this._rmap || {};
                    var html = '<div class="mad-ag-pop" role="dialog" aria-label="' + esc(btn.getAttribute('aria-label')) + '">'
                        + '<div class="mad-ag-pop-head">' + esc(btn.getAttribute('aria-label')) + '</div>';
                    list.slice().sort(function (a, b) { return a.start < b.start ? -1 : 1; }).forEach(function (e) {
                        var res = e.resource_id != null ? rmap[e.resource_id] : null, color = self._evColor(e, res);
                        html += '<div class="mad-ag-litem' + (e.status ? ' st-' + esc(e.status.replace(/[^A-Za-z0-9_-]/g, '')) : '') + '" role="button" tabindex="0" data-ag-ev="' + esc(e.id) + '"' + (color ? ' style="--ag-c:' + esc(color) + '"' : '') + '>'
                            + '<span class="mad-ag-ev-time">' + hhmm(minOf(e.start)) + '</span><span class="mad-ag-ltxt"><strong>' + esc(e.title) + '</strong>'
                            + '<small>' + esc([e.subtitle, res && !self.resource ? res.title : ''].filter(Boolean).join(' · ')) + '</small></span>'
                            + (e.status_label ? '<span class="mad-ag-ev-st' + (e.status_tone ? ' tone-' + e.status_tone : '') + '">' + esc(e.status_label) + '</span>' : '') + '</div>';
                    });
                    body.insertAdjacentHTML('beforeend', html + '</div>');
                    var pop = body.lastElementChild, br = body.getBoundingClientRect(), r = btn.getBoundingClientRect();
                    var left = Math.min(r.left - br.left, body.clientWidth - pop.offsetWidth - 4);
                    pop.style.left = Math.max(0, left) + 'px';
                    pop.style.top = Math.max(0, Math.min(r.top - br.top, body.clientHeight - pop.offsetHeight - 4)) + 'px';
                    var first = pop.querySelector('[data-ag-ev]');
                    if (first) first.focus();
                    var off = function (ev) {
                        if (ev.type === 'keydown' && ev.key !== 'Escape') return;
                        if (ev.type !== 'keydown' && pop.contains(ev.target)) return;
                        if (ev.type === 'keydown') ev.stopPropagation();
                        self.closeMore();
                        if (ev.type === 'keydown') {
                            var b = self.$refs.stage.querySelector('[data-ag-more="' + (root.CSS && CSS.escape ? CSS.escape(key) : key) + '"]');
                            if (b) b.focus();
                        }
                    };
                    setTimeout(function () {
                        document.addEventListener('pointerdown', off, true);
                        document.addEventListener('keydown', off, true);
                    }, 0);
                    this._moreOff = off;
                },
                closeMore: function () {
                    var pop = this.$refs.stage && this.$refs.stage.querySelector('.mad-ag-pop');
                    if (pop) pop.remove();
                    if (this._moreOff) {
                        document.removeEventListener('pointerdown', this._moreOff, true);
                        document.removeEventListener('keydown', this._moreOff, true);
                        this._moreOff = null;
                    }
                },

                _eventHtml: function (it, min, hp, isWeek, compact) {
                    var e = it.ev, rmap = this._rmap || {};
                    var res = e.resource_id != null ? rmap[e.resource_id] : null;
                    var top = (Math.max(it.s, min) - min) / 60 * hp + 1;
                    var h = Math.max(20, (it.e - Math.max(it.s, min)) / 60 * hp - 2);
                    var w = 100 / it.lanes, left = it.lane * w;
                    var size = compact ? ' is-compact' + (h < 38 ? ' is-short' : '') : (h < 38 ? ' is-short' : (h < 64 ? ' is-medium' : ''));
                    var color = this._evColor(e, res);
                    var cls = 'mad-ag-ev' + size + (e.status ? ' st-' + esc(e.status.replace(/[^A-Za-z0-9_-]/g, '')) : '')
                        + (e.movable ? ' is-movable' : '') + (e.status_tone ? ' tone-' + e.status_tone : '');
                    var time = hhmm(it.s) + '–' + hhmm(it.e >= DAY ? DAY : it.e);
                    var aria = time + ', ' + e.title + (e.subtitle ? ', ' + e.subtitle : '') + (res ? ', ' + res.title : '') + (e.status_label ? ', ' + e.status_label : '');
                    var html = '<div class="' + cls + '" role="button" tabindex="0" data-ag-ev="' + esc(e.id) + '"'
                        + (e.group ? ' data-ag-group="' + esc(e.group) + '"' : '')
                        + ' aria-label="' + esc(aria) + '"'
                        + ' style="top:' + top + 'px;height:' + h + 'px;left:calc(' + left + '% + 3px);width:calc(' + w + '% - 6px)' + (color ? ';--ag-c:' + esc(color) : '') + '">';
                    if (e.html) {
                        html += '<div class="mad-ag-ev-custom">' + e.html + '</div>';
                    } else if (compact) {
                        // hora de início + 1ª palavra do título (só se couber inteira) + status em bolinha
                        var laneW = (this._usedColW || 160) / it.lanes - 6;
                        var word = String(e.title || '').trim().split(/\s+/)[0] || '';
                        var room = Math.floor((laneW - 12 - (e.alert ? 18 : 0)) / 7);
                        if (word.length > room) word = '';
                        var dot = (e.status_label || e.status) ? '<span class="mad-ag-dot' + (e.status_tone ? ' tone-' + e.status_tone : '') + '" title="' + esc(e.status_label || e.status) + '"></span>' : '';
                        html = html.replace('<div class="', '<div title="' + esc(aria) + '" class="');
                        html += '<div class="mad-ag-ev-top"><span class="mad-ag-ev-time">' + hhmm(it.s) + '</span>' + dot + '</div>'
                            + ((word || e.alert) ? '<div class="mad-ag-ev-title">' + (e.alert ? '<span class="mad-ag-alert" title="' + esc(e.alert) + '">!</span>' : '') + (word ? '<span>' + esc(word) + '</span>' : '') + '</div>' : '');
                    } else {
                        var badges = (e.badges || []).map(function (b) {
                            return '<span class="mad-ag-badge' + (b.tone ? ' tone-' + b.tone : '') + '"' + (b.title ? ' title="' + esc(b.title) + '"' : '') + '>' + esc(b.text) + '</span>';
                        }).join('');
                        var sub = e.subtitle || '';
                        if (isWeek && res && !this.resource) sub = sub ? sub + ' · ' + res.title : res.title;
                        html += '<div class="mad-ag-ev-top"><span class="mad-ag-ev-time">' + time + '</span>' + (badges ? '<span class="mad-ag-badges">' + badges + '</span>' : '') + '</div>'
                            + '<div class="mad-ag-ev-title">' + (e.alert ? '<span class="mad-ag-alert" title="' + esc(e.alert) + '">!</span>' : '')
                            + (e.avatar ? '<img class="mad-ag-ev-av" src="' + esc(e.avatar) + '" alt="" loading="lazy">' : '')
                            + '<span>' + esc(e.title) + '</span></div>'
                            + (sub ? '<div class="mad-ag-ev-sub">' + esc(sub) + '</div>' : '')
                            + (e.status_label ? '<span class="mad-ag-ev-st' + (e.status_tone ? ' tone-' + e.status_tone : '') + '">' + esc(e.status_label) + '</span>' : '');
                    }
                    if (e.resizable) html += '<span class="mad-ag-rz" data-ag-rz aria-hidden="true"></span>';
                    return html + '</div>';
                },

                _weekList: function () {
                    var self = this, start = this._weekStart(this.date), resId = this.resource || '';
                    var rmap = this._rmap || this._resById(), loc = cfg.locale || 'pt-BR', out = '<div class="mad-ag-list">';
                    for (var i = 0; i < 7; i++) {
                        var d = addDays(start, i), o = parseDate(d);
                        var evs = this.data.events.filter(function (e) { return dateOf(e.start) === d && (!resId || e.resource_id === resId); })
                            .sort(function (a, b) { return a.start < b.start ? -1 : 1; });
                        if (d < this.data.today && !evs.length) continue;
                        var lbl = new Intl.DateTimeFormat(loc, { weekday: 'long', day: 'numeric', month: 'short' }).format(o);
                        out += '<section class="mad-ag-lday' + (d === this.data.today ? ' is-today' : '') + '"><button type="button" class="mad-ag-lhead" data-ag-open-day="' + d + '">'
                            + '<span>' + esc(lbl.charAt(0).toUpperCase() + lbl.slice(1)) + '</span><small>' + esc(evs.length ? this._countLabel(this._activeCount(evs)) : t(cfg, 'no_events')) + '</small></button>';
                        evs.forEach(function (e) {
                            var res = e.resource_id != null ? rmap[e.resource_id] : null, color = self._evColor(e, res);
                            out += '<div class="mad-ag-litem' + (e.status ? ' st-' + esc(e.status.replace(/[^A-Za-z0-9_-]/g, '')) : '') + '" role="button" tabindex="0" data-ag-ev="' + esc(e.id) + '"' + (color ? ' style="--ag-c:' + esc(color) + '"' : '') + '>'
                                + '<span class="mad-ag-ev-time">' + hhmm(minOf(e.start)) + '</span><span class="mad-ag-ltxt"><strong>' + esc(e.title) + '</strong>'
                                + '<small>' + esc([e.subtitle, res && !resId ? res.title : ''].filter(Boolean).join(' · ')) + '</small></span>'
                                + (e.status_label ? '<span class="mad-ag-ev-st' + (e.status_tone ? ' tone-' + e.status_tone : '') + '">' + esc(e.status_label) + '</span>' : '') + '</div>';
                        });
                        out += '</section>';
                    }
                    return out + '</div>';
                },

                _month: function () {
                    var self = this, d = this.data, start = d.start, end = d.end, loc = cfg.locale || 'pt-BR';
                    if (!start || !end) return '';
                    var month = this.date.substr(0, 7), resId = this.resource || '', ign = cfg.occIgnore || [];
                    var byDay = {};
                    d.events.forEach(function (e) {
                        if (resId && e.resource_id !== resId) return;
                        (byDay[dateOf(e.start)] = byDay[dateOf(e.start)] || []).push(e);
                    });
                    var head = '<div class="mad-ag-mhead" aria-hidden="true">';
                    for (var i = 0; i < 7; i++) {
                        head += '<span>' + esc(new Intl.DateTimeFormat(loc, { weekday: 'short' }).format(parseDate(addDays(start, i))).replace('.', '')) + '</span>';
                    }
                    head += '</div>';
                    var cells = '';
                    for (var day = start; day <= end; day = addDays(day, 1)) {
                        var evs = (byDay[day] || []).sort(function (a, b) { return a.start < b.start ? -1 : 1; });
                        var n = evs.filter(function (e) { return ign.indexOf(e.status) < 0; }).length;
                        var o = parseDate(day), out = day.substr(0, 7) !== month;
                        var aria = new Intl.DateTimeFormat(loc, { day: 'numeric', month: 'long' }).format(o) + ': ' + self._countLabel(n);
                        cells += '<button type="button" class="mad-ag-mday' + (out ? ' is-out' : '') + (day === d.today ? ' is-today' : '') + '" data-ag-open-day="' + day + '" aria-label="' + esc(aria) + '">'
                            + '<span class="mad-ag-mtop"><span class="mad-ag-mnum">' + o.getDate() + '</span>' + (n ? '<span class="mad-ag-mcount">' + n + '</span>' : '') + '</span><span class="mad-ag-mlist">';
                        evs.slice(0, 3).forEach(function (e) {
                            var res = e.resource_id != null ? self._rmap[e.resource_id] : null, color = self._evColor(e, res);
                            cells += '<span class="mad-ag-mitem' + (ign.indexOf(e.status) >= 0 ? ' is-ignored' : '') + '"' + (color ? ' style="--ag-c:' + esc(color) + '"' : '') + '>' + hhmm(minOf(e.start)) + ' ' + esc(e.title) + '</span>';
                        });
                        if (evs.length > 3) cells += '<span class="mad-ag-mmore">' + esc(t(cfg, 'more', { n: evs.length - 3 })) + '</span>';
                        cells += '</span></button>';
                    }
                    return '<div class="mad-ag-month">' + head + '<div class="mad-ag-mgrid">' + cells + '</div></div>';
                },

                _scroller: function () { return this.$refs.stage && this.$refs.stage.querySelector('.mad-ag-scroll'); },
                _scrollToStart: function () {
                    var sc = this._scroller();
                    if (!sc) return;
                    var hp = cfg.hourHeight || 64, min = this.data.min, target = null;
                    if (this.date === this.data.today && this.data.now != null) target = this.data.now;
                    if (target === null) {
                        var self = this;
                        this.data.events.forEach(function (e) {
                            if (self.view === 'day' && dateOf(e.start) !== self.date) return;
                            var m = minOf(e.start); if (target === null || m < target) target = m;
                        });
                    }
                    sc.scrollTop = target === null ? 0 : Math.max(0, (target - min) / 60 * hp - 60);
                },
                _nowMin: function () {
                    if (this.data.now == null) return null;
                    return this.data.now + Math.floor((Date.now() - this._loadedAt) / 60000);
                },
                _paintNow: function () {
                    var line = this.$refs.stage && this.$refs.stage.querySelector('.mad-ag-now');
                    if (!line) return;
                    var now = this._nowMin(), d = this.data, hp = cfg.hourHeight || 64;
                    var col = this.$refs.stage.querySelector('.mad-ag-col[data-date="' + d.today + '"]');
                    if (!cfg.nowLine || now === null || now < d.min || now > d.max || !col) { line.hidden = true; return; }
                    line.hidden = false;
                    line.style.top = ((now - d.min) / 60 * hp) + 'px';
                    if (this.view === 'week') {
                        line.style.left = col.offsetLeft + 'px';
                        line.style.width = col.offsetWidth + 'px';
                        line.style.right = 'auto';
                    }
                    line.querySelector('span').textContent = hhmm(now);
                },

                _eventById: function (id) {
                    for (var i = 0; i < this.data.events.length; i++) if (String(this.data.events[i].id) === String(id)) return this.data.events[i];
                    return null;
                },
                _flash: function (id) {
                    var el = id != null && this.$refs.stage.querySelector('[data-ag-ev="' + (root.CSS && CSS.escape ? CSS.escape(String(id)) : id) + '"]');
                    if (el) { el.classList.add('is-refused'); setTimeout(function () { el.classList.remove('is-refused'); }, 900); }
                },

                // ── slot (passar o mouse / clicar num horário livre) ───────
                _slotAt: function (col, clientY) {
                    var r = col.getBoundingClientRect(), hp = cfg.hourHeight || 64, step = cfg.slot || 15;
                    var m = this.data.min + Math.floor(((clientY - r.top) / hp * 60) / step) * step;
                    return Math.max(this.data.min, Math.min(this.data.max - step, m));
                },
                _ghost: function () {
                    if (!this._ghostEl) { this._ghostEl = document.createElement('div'); this._ghostEl.className = 'mad-ag-ghost'; this._ghostEl.setAttribute('aria-hidden', 'true'); }
                    return this._ghostEl;
                },
                _hideGhost: function () { if (this._ghostEl) this._ghostEl.style.display = 'none'; },
                _showGhost: function (col, m, minutes, text) {
                    var g = this._ghost(), hp = cfg.hourHeight || 64;
                    if (g.parentNode !== col) col.appendChild(g);
                    g.style.display = 'block';
                    g.style.top = ((m - this.data.min) / 60 * hp) + 'px';
                    g.style.height = Math.max(18, minutes / 60 * hp - 2) + 'px';
                    g.textContent = text;
                },
                _slotEnabled: function () { return !!(cfg.slotClick || cfg.slotTarget); },
                _groupHl: function (e) {
                    var evEl = e.target.closest && e.target.closest('[data-ag-group]');
                    var g = evEl ? evEl.getAttribute('data-ag-group') : '';
                    if (g === this._hlGroup) return;
                    this._hlGroup = g;
                    this.$refs.stage.querySelectorAll('.is-group-hl').forEach(function (x) { x.classList.remove('is-group-hl'); });
                    if (g) this.$refs.stage.querySelectorAll('[data-ag-group="' + (root.CSS && CSS.escape ? CSS.escape(g) : g) + '"]').forEach(function (x) { x.classList.add('is-group-hl'); });
                },
                onHover: function (e) {
                    this._groupHl(e);
                    if (this._drag || e.pointerType === 'touch' || !this._slotEnabled()) return;
                    var col = e.target.closest && e.target.closest('.mad-ag-col');
                    if (!col || col.classList.contains('is-off') || e.target.closest('[data-ag-ev],[data-ag-block]')) { this._hideGhost(); return; }
                    var m = this._slotAt(col, e.clientY);
                    this._showGhost(col, m, cfg.slot || 15, '+ ' + hhmm(m));
                },

                onClick: function (e) {
                    if (this._justDragged) return;
                    var more = e.target.closest('[data-ag-more]');
                    if (more) { this.openMore(more); return; }
                    var open = e.target.closest('[data-ag-open-day]');
                    if (open) { this.openDay(open.getAttribute('data-ag-open-day')); return; }
                    var evEl = e.target.closest('[data-ag-ev]');
                    if (evEl) { this.eventClick(evEl.getAttribute('data-ag-ev'), evEl); return; }
                    var col = e.target.closest('.mad-ag-col');
                    if (!col || e.target.closest('[data-ag-block]') || col.classList.contains('is-off') || !this._slotEnabled()) return;
                    var m = this._slotAt(col, e.clientY), d = col.getAttribute('data-date'), res = col.getAttribute('data-res') || '';
                    var p = { start: dt(d, m), end: dt(d, m + (cfg.slot || 15)), date: d, time: hhmm(m), resource: res };
                    if (cfg.slotClick) root.Mad && root.Mad.call && root.Mad.call(cfg.slotClick, p, this.$el);
                    else go(cfg.slotTarget, p);
                },
                eventClick: function (id, el) {
                    if (el && el.closest && el.closest('.mad-ag-pop')) this.closeMore();
                    var ev = this._eventById(id);
                    if (!ev) return;
                    if (cfg.eventClick) { if (root.Mad && root.Mad.call) root.Mad.call(cfg.eventClick, { id: ev.id }, el || this.$el); }
                    else if (cfg.eventTarget) go(cfg.eventTarget, { id: ev.id });
                },
                onKey: function (e) {
                    var evEl = e.target.closest && e.target.closest('[data-ag-ev]');
                    if (evEl && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); this.eventClick(evEl.getAttribute('data-ag-ev'), evEl); }
                },

                // ── arrastar / redimensionar ──────────────────────────────
                onPointerDown: function (e) {
                    if (e.button !== 0 && e.pointerType === 'mouse') return;
                    var evEl = e.target.closest('[data-ag-ev]');
                    if (!evEl || !evEl.closest('.mad-ag-col')) return;
                    var ev = this._eventById(evEl.getAttribute('data-ag-ev'));
                    if (!ev) return;
                    var resize = !!e.target.closest('[data-ag-rz]');
                    if (resize ? !ev.resizable : !ev.movable) return;
                    var self = this, rect = evEl.getBoundingClientRect();
                    var st = {
                        ev: ev, el: evEl, resize: resize, x0: e.clientX, y0: e.clientY, offY: e.clientY - rect.top,
                        rect: rect, active: false, touch: e.pointerType === 'touch', target: null, pointerId: e.pointerId,
                    };
                    if (resize) { e.preventDefault(); e.stopPropagation(); }
                    var hold = null;
                    if (st.touch && !resize) {
                        hold = setTimeout(function () { self._startDrag(st); }, 350);
                    }
                    var move = function (ev2) {
                        if (ev2.pointerId !== st.pointerId) return;
                        var dist = Math.abs(ev2.clientX - st.x0) + Math.abs(ev2.clientY - st.y0);
                        if (!st.active) {
                            if (st.touch && !resize) { if (dist > 8) { clearTimeout(hold); cleanup(); } return; }
                            if (dist < 5) return;
                            self._startDrag(st);
                        }
                        ev2.preventDefault();
                        self._dragMove(st, ev2.clientX, ev2.clientY);
                    };
                    var up = function (ev2) {
                        if (ev2.pointerId !== st.pointerId) return;
                        clearTimeout(hold);
                        cleanup();
                        if (st.active) self._dragEnd(st, ev2.type === 'pointercancel');
                    };
                    var key = function (ev2) { if (ev2.key === 'Escape' && st.active) { cleanup(); self._dragEnd(st, true); } };
                    var touchBlock = function (ev2) { if (st.active) ev2.preventDefault(); };
                    function cleanup() {
                        document.removeEventListener('pointermove', move);
                        document.removeEventListener('pointerup', up);
                        document.removeEventListener('pointercancel', up);
                        document.removeEventListener('keydown', key, true);
                        document.removeEventListener('touchmove', touchBlock);
                    }
                    document.addEventListener('pointermove', move, { passive: false });
                    document.addEventListener('pointerup', up);
                    document.addEventListener('pointercancel', up);
                    document.addEventListener('keydown', key, true);
                    document.addEventListener('touchmove', touchBlock, { passive: false });
                },

                _startDrag: function (st) {
                    if (st.active) return;
                    st.active = true;
                    this._drag = st;
                    this._hideGhost();
                    if (!st.resize) {
                        var c = st.el.cloneNode(true);
                        c.classList.add('mad-ag-dragging');
                        c.removeAttribute('tabindex');
                        c.style.width = st.rect.width + 'px';
                        c.style.height = st.rect.height + 'px';
                        c.style.left = st.rect.left + 'px';
                        c.style.top = st.rect.top + 'px';
                        this.$el.appendChild(c);
                        st.clone = c;
                    }
                    st.el.classList.add(st.resize ? 'is-resizing' : 'is-source');
                    if (navigator.vibrate && st.touch) try { navigator.vibrate(10); } catch (e) { /* sem vibração */ }
                },

                _dragMove: function (st, x, y) {
                    var hp = cfg.hourHeight || 64, step = cfg.slot || 15, d = this.data;
                    var dur = diffMin(st.ev.start, st.ev.end);
                    if (st.resize) {
                        var col0 = st.el.closest('.mad-ag-col'), r0 = col0.getBoundingClientRect();
                        var s = minOf(st.ev.start);
                        var end = Math.max(s + step, snap(d.min + (y - r0.top) / hp * 60, step));
                        end = Math.min(end, d.max);
                        st.el.style.height = Math.max(20, (end - Math.max(s, d.min)) / 60 * hp - 2) + 'px';
                        var tm = st.el.querySelector('.mad-ag-ev-time');
                        if (tm) tm.textContent = hhmm(s) + '–' + hhmm(end);
                        st.target = { date: dateOf(st.ev.start), min: s, end: end, res: st.ev.resource_id };
                        this._autoScroll(x, y);
                        return;
                    }
                    st.clone.style.left = (x - (st.x0 - st.rect.left)) + 'px';
                    st.clone.style.top = (y - st.offY) + 'px';
                    st.clone.style.display = 'none';
                    var under = document.elementFromPoint(x, y);
                    st.clone.style.display = '';
                    var col = under && under.closest ? under.closest('.mad-ag-col') : null;
                    if (col && this.$el.contains(col) && !col.classList.contains('is-off')) {
                        var r = col.getBoundingClientRect();
                        var m = snap(d.min + (y - st.offY - r.top) / hp * 60, step);
                        m = Math.max(d.min, Math.min(d.max - Math.min(dur, d.max - d.min), m));
                        var res = col.getAttribute('data-res');
                        st.target = { date: col.getAttribute('data-date'), min: m, end: m + dur, res: this.view === 'week' ? st.ev.resource_id : (res === '' ? null : res) };
                        var rtitle = '';
                        if (this.view === 'day' && res) { var rr = this._resById()[res]; rtitle = rr ? ' · ' + rr.title : ''; }
                        this._showGhost(col, m, dur, hhmm(m) + '–' + hhmm(m + dur) + rtitle);
                    } else {
                        st.target = null;
                        this._hideGhost();
                    }
                    this._autoScroll(x, y);
                },

                _autoScroll: function (x, y) {
                    var sc = this._scroller();
                    if (!sc) return;
                    var r = sc.getBoundingClientRect(), dy = 0, dx = 0;
                    if (y > r.bottom - 40) dy = 14; else if (y < r.top + 70) dy = -14;
                    if (x > r.right - 40) dx = 14; else if (x < r.left + 70) dx = -14;
                    if (dy) sc.scrollTop += dy;
                    if (dx) sc.scrollLeft += dx;
                },

                _dragEnd: function (st, cancel) {
                    var self = this;
                    this._drag = null;
                    this._hideGhost();
                    if (st.clone) st.clone.remove();
                    st.el.classList.remove('is-source', 'is-resizing');
                    this._justDragged = true;
                    setTimeout(function () { self._justDragged = false; }, 250);
                    var tg = st.target, ev = st.ev;
                    if (cancel || !tg) { this.render(); return; }
                    var start = dt(tg.date, tg.min), end = dt(tg.date, tg.end);
                    var res = tg.res == null ? '' : String(tg.res);
                    if (start === ev.start && end === ev.end && res === String(ev.resource_id == null ? '' : ev.resource_id)) { this.render(); return; }

                    // otimista: o cartão já fica no lugar novo; recusa/servidor fora → volta
                    var before = { start: ev.start, end: ev.end, resource_id: ev.resource_id };
                    ev.start = start; ev.end = end; ev.resource_id = tg.res == null ? null : String(tg.res);
                    ev._pending = true;
                    this.render();
                    var pend = this.$refs.stage.querySelector('[data-ag-ev="' + (root.CSS && CSS.escape ? CSS.escape(String(ev.id)) : ev.id) + '"]');
                    if (pend) pend.classList.add('is-pending');
                    var restore = function () {
                        ev.start = before.start; ev.end = before.end; ev.resource_id = before.resource_id; ev._pending = false;
                        self.render();
                    };
                    wire(this.$el, st.resize ? 'onAgendaResize' : 'onAgendaMove', [cfg.key, String(ev.id), start, end, res, this.ctx()])
                        .then(function (out) {
                            if (out && out.ok === false) { restore(); self._flash(ev.id); toast(t(cfg, 'offline'), 'warning'); }
                        }, function () { restore(); toast(t(cfg, 'offline'), 'warning'); });
                },
            };
        });
    }

    if (root.Alpine) init();
    document.addEventListener('alpine:init', init);
})(typeof window !== 'undefined' ? window : globalThis);
