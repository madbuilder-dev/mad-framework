/**
 * MadGantt v2 — bundle. Source modular em lib/mad/gantt/.
 */

/**
 * MadGantt — Date utilities (pure, no DOM, no Alpine).
 *
 * All Date objects are built at LOCAL midnight to avoid TZ drift.
 * "YYYY-MM-DD" strings are the canonical wire format.
 */
(function (root) {
    'use strict';

    const MS_DAY = 86400000;

    const DAY_NAMES = {
        'pt-br': ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'],
        'en':    ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
    };
    const DAY_NAMES_SHORT = {
        'pt-br': ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'],
        'en':    ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'],
    };
    const MONTH_NAMES = {
        'pt-br': ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'],
        'en':    ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
    };
    const MONTH_FULL = {
        'pt-br': ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                  'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'],
        'en':    ['January', 'February', 'March', 'April', 'May', 'June',
                  'July', 'August', 'September', 'October', 'November', 'December'],
    };

    function parseDate(input) {
        if (!input) return null;
        if (input instanceof Date) {
            if (isNaN(input.getTime())) return null;
            return new Date(input.getFullYear(), input.getMonth(), input.getDate());
        }
        // Aceita 'Y-m-d', 'Y-m-d H:i[:s]' e ISO com 'T' (sufixo Z/offset é
        // descartado — datas do Gantt são tratadas como locais).
        const s = String(input).trim().replace(/(Z|[+-]\d{2}:?\d{2})$/i, '');
        const parts = s.split(/[- :T]/);
        const y  = +parts[0];
        const m  = +parts[1] - 1;
        const d  = +parts[2];
        const h  = +(parts[3] || 0);
        const mi = +(parts[4] || 0);
        const se = +(parts[5] || 0);
        if (!Number.isFinite(y) || !Number.isFinite(m) || !Number.isFinite(d)) return null;
        const out = new Date(y, m, d,
            Number.isFinite(h) ? h : 0,
            Number.isFinite(mi) ? mi : 0,
            Number.isFinite(se) ? se : 0);
        return isNaN(out.getTime()) ? null : out;
    }

    /** Normaliza um locale arbitrário pra uma chave existente nas tabelas. */
    function resolveLocale(locale) {
        const l = String(locale || '').toLowerCase();
        if (MONTH_NAMES[l]) return l;
        if (l.startsWith('pt')) return 'pt-br';
        if (MONTH_NAMES[l.slice(0, 2)]) return l.slice(0, 2);
        return 'en';
    }

    function fmtISO(d) {
        if (!d) return '';
        const pad = (n) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
    }

    function fmtDateTime(d) {
        if (!d) return '';
        const pad = (n) => String(n).padStart(2, '0');
        return `${fmtISO(d)} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    function fmtDate(d, opts = {}) {
        const locale = resolveLocale(opts.locale);
        const day = d.getDate();
        const mo  = MONTH_NAMES[locale][d.getMonth()];
        const yr  = d.getFullYear();
        if (opts.long)  return `${day} ${mo} ${yr}`;
        if (opts.short) return `${String(day).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
        return `${String(day).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${yr}`;
    }

    function daysBetween(a, b) {
        const ms = new Date(b.getFullYear(), b.getMonth(), b.getDate()).getTime()
                 - new Date(a.getFullYear(), a.getMonth(), a.getDate()).getTime();
        return Math.round(ms / MS_DAY);
    }

    function addDays(d, n) {
        const r = new Date(d);
        r.setDate(r.getDate() + n);
        return r;
    }

    function addMonths(d, n) {
        const r = new Date(d);
        const day = r.getDate();
        r.setDate(1);
        r.setMonth(r.getMonth() + n);
        const last = new Date(r.getFullYear(), r.getMonth() + 1, 0).getDate();
        r.setDate(Math.min(day, last));
        return r;
    }

    function isWeekend(d) {
        const w = d.getDay();
        return w === 0 || w === 6;
    }

    function startOfWeek(d, mondayFirst = true) {
        const r = new Date(d);
        const w = mondayFirst ? (r.getDay() + 6) % 7 : r.getDay();
        r.setDate(r.getDate() - w);
        return r;
    }

    function sameDay(a, b) {
        if (!a || !b) return false;
        return a.getFullYear() === b.getFullYear()
            && a.getMonth() === b.getMonth()
            && a.getDate() === b.getDate();
    }

    function isoWeekNumber(d) {
        const t = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
        const day = t.getUTCDay() || 7;
        t.setUTCDate(t.getUTCDate() + 4 - day);
        const yearStart = new Date(Date.UTC(t.getUTCFullYear(), 0, 1));
        return Math.ceil((((t - yearStart) / MS_DAY) + 1) / 7);
    }

    function quarterOf(d) {
        return Math.floor(d.getMonth() / 3) + 1;
    }

    function getDayName(d, locale = 'pt-br', short = false) {
        const map = short ? DAY_NAMES_SHORT : DAY_NAMES;
        return map[resolveLocale(locale)][d.getDay()];
    }

    function getMonthName(d, locale = 'pt-br', full = false) {
        const map = full ? MONTH_FULL : MONTH_NAMES;
        return map[resolveLocale(locale)][d.getMonth()];
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.dates = {
        MS_DAY,
        DAY_NAMES, DAY_NAMES_SHORT, MONTH_NAMES, MONTH_FULL,
        parseDate, resolveLocale, fmtISO, fmtDateTime, fmtDate,
        daysBetween, addDays, addMonths,
        isWeekend, startOfWeek, sameDay,
        isoWeekNumber, quarterOf,
        getDayName, getMonthName,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Color utilities.
 *
 * Generates an 8-tone OKLCH palette per phase from a single hue.
 * Light and dark variants follow the mockup tokens.
 */
(function (root) {
    'use strict';

    // Default hues evenly spread on the color wheel — used when a phase
    // declares no hue. Order picked to read nicely on a kanban-ish gradient.
    const AUTO_HUES = [250, 290, 35, 195, 12, 155, 320, 70, 220, 0, 100, 270];

    function assignAutoHue(idx) {
        return AUTO_HUES[idx % AUTO_HUES.length];
    }

    function phaseColors(hue, dark) {
        const h = Number.isFinite(hue) ? hue : 250;
        const h2 = h + 12;
        if (dark) {
            return {
                hue:        h,
                bar:        `oklch(0.62 0.16 ${h})`,
                barTo:      `oklch(0.54 0.17 ${h2})`,
                progress:   `oklch(0.78 0.18 ${h})`,
                progressTo: `oklch(0.70 0.19 ${h2})`,
                track:      `oklch(0.32 0.05 ${h} / 0.55)`,
                text:       `oklch(0.97 0.02 ${h})`,
                glow:       `oklch(0.70 0.18 ${h} / 0.35)`,
                label:      `oklch(0.78 0.14 ${h})`,
                chipBg:     `oklch(0.28 0.05 ${h})`,
                chipText:   `oklch(0.88 0.08 ${h})`,
                dot:        `oklch(0.72 0.16 ${h})`,
                rowTint:    `oklch(0.62 0.06 ${h} / 0.08)`,
                arrow:      `oklch(0.68 0.10 ${h})`,
            };
        }
        return {
            hue:        h,
            bar:        `oklch(0.62 0.16 ${h})`,
            barTo:      `oklch(0.55 0.17 ${h2})`,
            progress:   `oklch(0.46 0.18 ${h})`,
            progressTo: `oklch(0.39 0.19 ${h2})`,
            track:      `oklch(0.92 0.04 ${h})`,
            text:       `#ffffff`,
            glow:       `oklch(0.60 0.18 ${h} / 0.35)`,
            label:      `oklch(0.42 0.16 ${h})`,
            chipBg:     `oklch(0.95 0.04 ${h})`,
            chipText:   `oklch(0.40 0.18 ${h})`,
            dot:        `oklch(0.62 0.16 ${h})`,
            rowTint:    `oklch(0.62 0.06 ${h} / 0.06)`,
            arrow:      `oklch(0.50 0.14 ${h})`,
        };
    }

    /**
     * Pick a single fill color for a task — phase-driven when a phase is given,
     * task.color as override, fallback accent otherwise.
     */
    function taskFill(task, phase, dark) {
        if (task && task.color) return task.color;
        if (phase && Number.isFinite(phase.hue)) return phaseColors(phase.hue, dark).bar;
        return dark ? '#60a5fa' : '#3b82f6';
    }

    /**
     * Hash a string to one of the AUTO_HUES so two tasks with the same
     * label/group end up with the same color across renders.
     */
    function hashHue(str) {
        let h = 0;
        const s = String(str || '');
        for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
        return AUTO_HUES[Math.abs(h) % AUTO_HUES.length];
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.colors = {
        AUTO_HUES,
        assignAutoHue,
        phaseColors,
        taskFill,
        hashHue,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — SVG helpers.
 *
 * Thin wrappers around document.createElementNS so the render layer
 * stays declarative. Also: rounded elbow path builder used by the
 * dependency arrows and the bar shapes.
 */
(function (root) {
    'use strict';

    const NS = 'http://www.w3.org/2000/svg';

    function createNS(tag, attrs) {
        const el = document.createElementNS(NS, tag);
        if (attrs) setAttrs(el, attrs);
        return el;
    }

    function setAttrs(el, attrs) {
        for (const k in attrs) {
            const v = attrs[k];
            if (v === null || v === undefined || v === false) continue;
            el.setAttribute(k, String(v));
        }
        return el;
    }

    function clear(el) {
        if (!el) return;
        while (el.firstChild) el.removeChild(el.firstChild);
    }

    function appendAll(parent, children) {
        if (!parent || !children) return;
        for (const c of children) if (c) parent.appendChild(c);
    }

    /**
     * Build a rounded elbow path between an ordered list of points.
     * Each interior corner gets a quadratic-style fillet of radius `r`.
     *
     * points: [[x,y], [x,y], ...]
     */
    function roundedPath(points, r = 4) {
        if (!points || points.length === 0) return '';
        if (points.length === 1) return `M ${points[0][0]} ${points[0][1]}`;
        const parts = [`M ${points[0][0]} ${points[0][1]}`];
        for (let i = 1; i < points.length - 1; i++) {
            const [px, py] = points[i - 1];
            const [cx, cy] = points[i];
            const [nx, ny] = points[i + 1];
            // Direction vectors
            const dx1 = sign(cx - px), dy1 = sign(cy - py);
            const dx2 = sign(nx - cx), dy2 = sign(ny - cy);
            // Approach the corner stopping `r` units before it
            const ax = cx - dx1 * Math.min(r, Math.abs(cx - px) / 2);
            const ay = cy - dy1 * Math.min(r, Math.abs(cy - py) / 2);
            // Resume `r` units past it
            const bx = cx + dx2 * Math.min(r, Math.abs(nx - cx) / 2);
            const by = cy + dy2 * Math.min(r, Math.abs(ny - cy) / 2);
            parts.push(`L ${ax} ${ay}`);
            parts.push(`Q ${cx} ${cy} ${bx} ${by}`);
        }
        const last = points[points.length - 1];
        parts.push(`L ${last[0]} ${last[1]}`);
        return parts.join(' ');
    }

    function sign(n) {
        return n > 0 ? 1 : n < 0 ? -1 : 0;
    }

    /**
     * Build a horizontally-rounded rect path. Lets us inset only the leading
     * edge (used by progress overlays when the progress is partial).
     */
    function rectPath(x, y, w, h, r) {
        if (r <= 0) return `M ${x} ${y} h ${w} v ${h} h ${-w} z`;
        const rr = Math.min(r, h / 2, w / 2);
        return `M ${x + rr} ${y}`
             + ` h ${w - 2 * rr}`
             + ` a ${rr} ${rr} 0 0 1 ${rr} ${rr}`
             + ` v ${h - 2 * rr}`
             + ` a ${rr} ${rr} 0 0 1 ${-rr} ${rr}`
             + ` h ${-(w - 2 * rr)}`
             + ` a ${rr} ${rr} 0 0 1 ${-rr} ${-rr}`
             + ` v ${-(h - 2 * rr)}`
             + ` a ${rr} ${rr} 0 0 1 ${rr} ${-rr} z`;
    }

    /**
     * Diamond polygon points string for milestones.
     */
    function diamondPoints(cx, cy, size) {
        return `${cx},${cy - size} ${cx + size},${cy} ${cx},${cy + size} ${cx - size},${cy}`;
    }

    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escText(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.svg = {
        NS,
        createNS, setAttrs, clear, appendAll,
        roundedPath, rectPath, diamondPoints,
        escAttr, escText,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Zoom profiles.
 *
 * Each profile defines: pxPerDay, the major header unit and the minor
 * header unit. New canonical keys are day/week/month/year; the legacy
 * xs/sm/md/lg from the old <mad-gantt> API alias to them so existing
 * demos keep working.
 */
(function (root) {
    'use strict';

    const ZOOM_PROFILES = {
        hour:  { pxPerDay: 480, minor: 'hour',  major: 'day',     barH: 22, hourStep: 2 },
        day:   { pxPerDay: 36,  minor: 'day',   major: 'month',   barH: 22 },
        week:  { pxPerDay: 16,  minor: 'week',  major: 'month',   barH: 20 },
        month: { pxPerDay: 5,   minor: 'month', major: 'quarter', barH: 16 },
        year:  { pxPerDay: 3,   minor: 'month', major: 'year',    barH: 14, fitFullYear: true },
    };

    const LEGACY_ALIAS = {
        xs: 'day',     // 36 px/day equivalent to a tight day view
        sm: 'week',
        md: 'month',
        lg: 'year',
    };

    function normalize(zoom) {
        if (!zoom) return 'day';
        if (ZOOM_PROFILES[zoom]) return zoom;
        if (LEGACY_ALIAS[zoom]) return LEGACY_ALIAS[zoom];
        return 'day';
    }

    function pxPerHour(zoom) {
        return get(zoom).pxPerDay / 24;
    }

    function get(zoom) {
        return ZOOM_PROFILES[normalize(zoom)];
    }

    function pxPerDay(zoom) {
        return get(zoom).pxPerDay;
    }

    function listKeys() {
        return Object.keys(ZOOM_PROFILES);
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.zoom = {
        ZOOM_PROFILES,
        LEGACY_ALIAS,
        normalize,
        get,
        pxPerDay,
        pxPerHour,
        listKeys,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Coordinate system.
 *
 * Computes the visible time range (min/max bounded by tasks) and the
 * task geometry cache (x/y/w in pixels). Cache is rebuilt only when
 * `invalidate()` is called.
 */
(function (root) {
    'use strict';

    const { dates } = root.MadGantt;
    const { parseDate, addDays, daysBetween } = dates;
    const zoomMod = root.MadGantt.zoom;

    function computeRange(tasks, zoom, padDays = 3) {
        if (!tasks || tasks.length === 0) {
            const t = new Date();
            const start = new Date(t.getFullYear(), t.getMonth(), 1);
            const end   = new Date(t.getFullYear(), t.getMonth() + 1, 0);
            return { start, end, totalDays: daysBetween(start, end) + 1 };
        }
        let minD = null;
        let maxD = null;
        for (const t of tasks) {
            const s = parseDate(t.start);
            const e = parseDate(t.end) || s;
            if (!s) continue;
            if (!minD || s < minD) minD = s;
            const eff = e || s;
            if (!maxD || eff > maxD) maxD = eff;
        }
        if (!minD) {
            const t = new Date();
            minD = maxD = t;
        }
        const profile = zoomMod.get(zoom);
        if (profile.fitFullYear) {
            const start = new Date(minD.getFullYear(), 0, 1);
            const end   = new Date(maxD.getFullYear(), 11, 31);
            return { start, end, totalDays: daysBetween(start, end) + 1 };
        }
        // No zoom hora, padding menor: barras tem horas, range muito largo gera scroll inutil.
        const pad = zoom === 'hour' ? 1 : padDays;
        const start = addDays(minD, -pad);
        const end   = addDays(maxD, pad + 1);
        return { start, end, totalDays: daysBetween(start, end) + 1 };
    }

    function dayToX(rangeStart, dateLike, pxPerDay) {
        const d = (dateLike instanceof Date) ? dateLike : parseDate(dateLike);
        if (!d) return 0;
        return daysBetween(rangeStart, d) * pxPerDay;
    }

    function xToDay(rangeStart, x, pxPerDay) {
        const days = Math.round(x / pxPerDay);
        return addDays(rangeStart, days);
    }

    /**
     * Build the per-row geometry cache.
     *
     * rows: [{ kind, task?, phase?, ... }] from core/tree.visibleRows().
     * rowH: row height in pixels.
     *
     * Returns:
     *   { byId: { [taskId]: {x,y,w,h,rowIdx,kind} }, totalHeight }
     */
    /**
     * Offset em dias (float) de `d` a partir de `rangeStart` — dia inteiro via
     * daysBetween (meia-noite + round, DST-safe) + fração intradia do relógio
     * local. Divisão crua de ms desalinhava barras do grid após virada de DST.
     */
    function dayOffsetFloat(rangeStart, d) {
        return daysBetween(rangeStart, d)
            + (d.getHours() * 60 + d.getMinutes()) / 1440;
    }

    function buildGeom(rows, range, pxPerDay, rowH) {
        const byId = {};
        let y = 0;
        const { parseDate } = dates;
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.kind === 'task' || row.kind === 'summary') {
                const t = row.task;
                const s = parseDate(t.start);
                const e = parseDate(t.end) || s;
                if (s) {
                    const sFloat = dayOffsetFloat(range.start, s);
                    const eFloat = dayOffsetFloat(range.start, e);
                    const x = sFloat * pxPerDay;
                    // If end has explicit time (HH:MM), use precise width.
                    // Otherwise include the whole end day (legacy date-only behavior).
                    const endStr = String(t.end || '');
                    const hasTime = /\d{1,2}:\d{2}/.test(endStr) || /T\d/.test(endStr);
                    const rawW = hasTime
                        ? (eFloat - sFloat) * pxPerDay
                        : (eFloat - sFloat + 1) * pxPerDay;
                    const w = Math.max(2, rawW);
                    byId[t.id] = { x, y, w, h: rowH, rowIdx: i, kind: row.kind, milestone: !!t.milestone };
                }
            }
            y += rowH;
        }
        return { byId, totalHeight: y };
    }

    /**
     * Mutate a single geom entry in place (called by drag interactions
     * to avoid full geom rebuild on every pointer move).
     */
    function patchGeom(geom, taskId, dDays, pxPerDay, mode) {
        const g = geom.byId[taskId];
        if (!g) return;
        const step = dDays * pxPerDay;
        if (mode === 'move') {
            g.x += step;
        } else if (mode === 'right') {
            g.w = Math.max(pxPerDay, g.w + step);
        } else if (mode === 'left') {
            g.x += step;
            g.w = Math.max(pxPerDay, g.w - step);
        }
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.coords = {
        computeRange,
        dayToX,
        xToDay,
        dayOffsetFloat,
        buildGeom,
        patchGeom,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Tree + phase grouping.
 *
 * `visibleRows(ctx)` returns the unified, ordered list consumed by
 * both the sidebar renderer and the chart-svg renderer.
 *
 *   ctx = {
 *     tasks: [{ id, name, start, end, parentId?, phase?, milestone?, ... }],
 *     phases: [{ id, name, code, hue }] | null,
 *     phaseField: 'phase' | null,
 *     expanded: { [taskId]: bool },
 *     collapsedPhases: { [phaseId]: bool },
 *     filterText: string,
 *   }
 *
 * Each emitted row: { kind: 'phase'|'task'|'summary', task?, phase?, depth, count? }
 */
(function (root) {
    'use strict';

    const { colors } = root.MadGantt;

    function visibleRows(ctx) {
        const out = [];
        const tasks = ctx.tasks || [];
        const expanded = ctx.expanded || {};
        const collapsedPhases = ctx.collapsedPhases || {};
        const q = (ctx.filterText || '').trim().toLowerCase();
        // phaseFilter: null = todas as fases visiveis | Set<phaseId> = apenas essas
        const phaseFilter = ctx.phaseFilter || null;

        const matches = (t) => {
            if (!q) return true;
            const name = String(t.name || '').toLowerCase();
            const code = String(t.code || t.id || '').toLowerCase();
            const owner = String(t.owner || '').toLowerCase();
            return name.includes(q) || code.includes(q) || owner.includes(q);
        };

        // Index tasks by id and by parent
        const byId = {};
        const childrenOf = {};
        for (const t of tasks) {
            byId[t.id] = t;
            const pid = (t.parentId != null && t.parentId !== '') ? String(t.parentId) : '__root__';
            (childrenOf[pid] = childrenOf[pid] || []).push(t);
        }

        // Determine roots — top-level tasks (no parent in dataset)
        const roots = [];
        for (const t of tasks) {
            const pid = t.parentId != null && t.parentId !== '' ? String(t.parentId) : null;
            if (!pid || !byId[pid]) roots.push(t);
        }

        // Phase grouping branch
        if (ctx.phaseField && ctx.phases && ctx.phases.length > 0) {
            const phasesById = {};
            for (const p of ctx.phases) phasesById[String(p.id)] = p;

            // Bucket roots by phase value
            const phaseField = ctx.phaseField;
            const buckets = {};
            const phaseOrder = ctx.phases.map((p) => String(p.id));
            const unassignedKey = '__noPhase__';

            for (const root of roots) {
                const pv = root[phaseField] != null ? String(root[phaseField]) : unassignedKey;
                (buckets[pv] = buckets[pv] || []).push(root);
            }

            const emittedKeys = new Set();
            for (const pid of phaseOrder) {
                if (!buckets[pid]) continue;
                if (phaseFilter && !phaseFilter.has(pid)) { emittedKeys.add(pid); continue; }
                const phase = phasesById[pid];
                emitPhase(phase, buckets[pid]);
                emittedKeys.add(pid);
            }
            // Catch-all: roots whose phase value is not in ctx.phases.
            // Tasks SEM fase só somem se '__none__' foi explicitamente
            // desmarcado — antes qualquer filtro ativo as engolia sem opção
            // de religar no menu.
            for (const key of Object.keys(buckets)) {
                if (emittedKeys.has(key)) continue;
                if (phaseFilter && !phaseFilter.has(key === unassignedKey ? '__none__' : key)) continue;
                const phase = key === unassignedKey
                    ? { id: '__none__', name: (ctx.noPhaseLabel || '— Sem fase —'), code: '', hue: 250 }
                    : { id: key, name: key, code: '', hue: colors.hashHue(key) };
                emitPhase(phase, buckets[key]);
            }
        } else {
            // Plain tree (no phase grouping)
            for (const r of roots) {
                walk(r, 0);
            }
        }

        return out;

        function emitPhase(phase, phaseRoots) {
            // Pre-filter to know if any descendant matches the search query
            const filtered = q ? filterTree(phaseRoots) : phaseRoots;
            if (filtered.length === 0) return;
            const count = countTasks(filtered);
            out.push({ kind: 'phase', phase, depth: 0, count });
            if (!collapsedPhases[String(phase.id)]) {
                for (const r of filtered) walk(r, 1);
            }
        }

        function walk(task, depth) {
            const kids = childrenOf[String(task.id)] || [];
            const isSummary = kids.length > 0;
            const localMatch = matches(task);
            const filteredKids = q ? filterTree(kids) : kids;
            // When filtering, drop tasks whose subtree has no matches AND who don't match themselves
            if (q && !localMatch && filteredKids.length === 0) return;
            out.push({
                kind: isSummary ? 'summary' : 'task',
                task,
                depth,
                expandable: isSummary,
            });
            if (isSummary && (expanded[String(task.id)] !== false)) {
                for (const k of filteredKids) walk(k, depth + 1);
            }
        }

        function filterTree(list) {
            const kept = [];
            for (const t of list) {
                const kids = childrenOf[String(t.id)] || [];
                const kidsKept = filterTree(kids);
                if (matches(t) || kidsKept.length > 0) kept.push(t);
            }
            return kept;
        }

        function countTasks(list) {
            let n = 0;
            for (const t of list) {
                n++;
                const kids = childrenOf[String(t.id)] || [];
                n += countTasks(kids);
            }
            return n;
        }
    }

    /**
     * Derive the phase list from a tasks array when the caller hasn't
     * supplied one explicitly. Each unique phase value gets a hashed hue.
     */
    function derivePhases(tasks, phaseField) {
        if (!phaseField) return [];
        const seen = {};
        const order = [];
        for (const t of tasks) {
            const v = t[phaseField];
            if (v == null || v === '') continue;
            const key = String(v);
            if (seen[key]) continue;
            seen[key] = true;
            order.push({
                id: key,
                name: key,
                code: '',
                hue: colors.hashHue(key),
            });
        }
        return order;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.tree = {
        visibleRows,
        derivePhases,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Critical path (CPM).
 *
 * Forward + backward pass over the dependency graph; tasks with slack=0
 * are on the critical path. Supports FS / SS / FF / SF with lag in days.
 *
 *   dependencies: [{ from, to, type: 'FS'|'SS'|'FF'|'SF', lag: int }]
 *   tasks:        [{ id, start, end, duration? }]
 *
 * Returns a Set of taskIds on the critical path.
 */
(function (root) {
    'use strict';

    const { dates } = root.MadGantt;
    const { parseDate, daysBetween } = dates;

    function compute(tasks, dependencies) {
        if (!tasks || tasks.length === 0) return new Set();
        const byId = {};
        for (const t of tasks) byId[String(t.id)] = t;

        const dur = (t) => {
            const s = parseDate(t.start);
            const e = parseDate(t.end) || s;
            if (!s) return 1;
            return Math.max(1, daysBetween(s, e) + 1);
        };

        // Build adjacency (successors + predecessors)
        const succ = {};
        const pred = {};
        for (const t of tasks) {
            succ[t.id] = [];
            pred[t.id] = [];
        }
        const validDeps = [];
        for (const d of (dependencies || [])) {
            if (!byId[d.from] || !byId[d.to]) continue;
            const type = (d.type || 'FS').toUpperCase();
            const lag = +(d.lag || 0);
            succ[d.from].push({ to: d.to, type, lag });
            pred[d.to].push({ from: d.from, type, lag });
            validDeps.push({ from: String(d.from), to: String(d.to), type, lag });
        }

        // Topo sort
        const order = topoSort(tasks, succ);
        if (!order) {
            console.warn('[MadGantt] critical path desligado: ciclo de dependências detectado');
            return new Set(); // cycle: bail
        }

        // Baseline de calendário: ancorar cada task na própria data agendada
        // (es=0 pra todo mundo fazia ramos independentes "começarem juntos" e
        // marcava caminho crítico errado em cronogramas com folgas reais).
        let minStart = null;
        for (const t of tasks) {
            const s = parseDate(t.start);
            if (s && (!minStart || s < minStart)) minStart = s;
        }
        const dateEs = (t) => {
            const s = parseDate(t.start);
            return (s && minStart) ? daysBetween(minStart, s) : 0;
        };

        // Forward pass — earliest start / finish
        const es = {}, ef = {};
        for (const id of order) {
            const t = byId[id];
            const d = dur(t);
            let est = dateEs(t);
            for (const p of pred[id]) {
                const pd = dur(byId[p.from]);
                let candidate;
                switch (p.type) {
                    case 'SS': candidate = es[p.from] + p.lag; break;
                    case 'FF': candidate = ef[p.from] + p.lag - d; break;
                    case 'SF': candidate = es[p.from] + p.lag - d; break;
                    case 'FS':
                    default:   candidate = ef[p.from] + p.lag;
                }
                if (candidate > est) est = candidate;
            }
            es[id] = est;
            ef[id] = est + d;
        }

        // Backward pass — latest start / finish
        const projectEnd = Math.max(0, ...order.map((id) => ef[id]));
        const ls = {}, lf = {};
        for (let i = order.length - 1; i >= 0; i--) {
            const id = order[i];
            const t = byId[id];
            const d = dur(t);
            let lft = projectEnd;
            const downs = succ[id];
            if (downs.length > 0) {
                lft = Infinity;
                for (const s of downs) {
                    const sd = dur(byId[s.to]);
                    let candidate;
                    switch (s.type) {
                        case 'SS': candidate = ls[s.to] - s.lag + d; break;
                        case 'FF': candidate = lf[s.to] - s.lag; break;
                        case 'SF': candidate = lf[s.to] - s.lag + d; break;
                        case 'FS':
                        default:   candidate = ls[s.to] - s.lag;
                    }
                    if (candidate < lft) lft = candidate;
                }
            }
            lf[id] = lft;
            ls[id] = lft - d;
        }

        // Slack = ls - es
        const critical = new Set();
        for (const id of order) {
            const slack = ls[id] - es[id];
            if (Math.abs(slack) < 1e-6) critical.add(id);
        }
        return critical;
    }

    function topoSort(tasks, succ) {
        const indeg = {};
        for (const t of tasks) indeg[t.id] = 0;
        for (const from in succ) {
            for (const e of succ[from]) {
                indeg[e.to] = (indeg[e.to] || 0) + 1;
            }
        }
        const queue = [];
        for (const id in indeg) if (indeg[id] === 0) queue.push(id);
        const order = [];
        while (queue.length) {
            const id = queue.shift();
            order.push(id);
            for (const e of (succ[id] || [])) {
                indeg[e.to]--;
                if (indeg[e.to] === 0) queue.push(e.to);
            }
        }
        if (order.length !== tasks.length) return null; // cycle
        return order;
    }

    /**
     * DFS-based cycle detection for interactive dep creation:
     * given current deps + candidate (from, to), would adding it cycle?
     */
    function wouldCycle(tasks, dependencies, from, to) {
        if (String(from) === String(to)) return true;
        const succ = {};
        for (const d of dependencies) {
            (succ[d.from] = succ[d.from] || []).push(d.to);
        }
        // Adding "from -> to" creates a cycle iff `to` can already reach `from`.
        const seen = new Set();
        const stack = [String(to)];
        while (stack.length) {
            const n = stack.pop();
            if (n === String(from)) return true;
            if (seen.has(n)) continue;
            seen.add(n);
            for (const nx of (succ[n] || [])) stack.push(String(nx));
        }
        return false;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.criticalPath = {
        compute,
        wouldCycle,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Working-day calendar.
 *
 * Stateless helpers for working-days, holidays, business-hour windows.
 *
 *   workingDays: [1..5] (Sun=0 .. Sat=6)
 *   holidays:    ['YYYY-MM-DD', ...]
 *   workingHours:[startHour, endHour] (24h)
 */
(function (root) {
    'use strict';

    const { dates } = root.MadGantt;
    const { fmtISO, addDays } = dates;

    function isWorkingDay(date, workingDays = [1, 2, 3, 4, 5], holidays = []) {
        if (!date) return false;
        const wd = date.getDay();
        if (!workingDays.includes(wd)) return false;
        const iso = fmtISO(date);
        if (holidays.includes(iso)) return false;
        return true;
    }

    function isNonWorking(date, workingDays, holidays) {
        return !isWorkingDay(date, workingDays, holidays);
    }

    /**
     * Count working days from `start` (inclusive) to `end` (inclusive).
     */
    function countWorkingDays(start, end, workingDays, holidays) {
        if (!start || !end) return 0;
        let n = 0;
        const sign = start <= end ? 1 : -1;
        let cur = new Date(start.getFullYear(), start.getMonth(), start.getDate());
        const endN = end.getFullYear() * 10000 + (end.getMonth() + 1) * 100 + end.getDate();
        while (true) {
            const curN = cur.getFullYear() * 10000 + (cur.getMonth() + 1) * 100 + cur.getDate();
            if (isWorkingDay(cur, workingDays, holidays)) n++;
            if (curN === endN) break;
            cur = addDays(cur, sign);
            const guard = cur.getFullYear() * 10000 + (cur.getMonth() + 1) * 100 + cur.getDate();
            if (sign > 0 && guard > endN + 1) break;
            if (sign < 0 && guard < endN - 1) break;
        }
        return n;
    }

    /**
     * Step forward `n` working days from `start`. Useful for auto-scheduling.
     */
    function addWorkingDays(start, n, workingDays, holidays) {
        if (!start || n === 0) return start;
        let cur = new Date(start);
        const step = n > 0 ? 1 : -1;
        let remaining = Math.abs(n);
        while (remaining > 0) {
            cur = addDays(cur, step);
            if (isWorkingDay(cur, workingDays, holidays)) remaining--;
        }
        return cur;
    }

    function isWithinHours(hour, workingHours = [0, 24]) {
        return hour >= workingHours[0] && hour < workingHours[1];
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.calendar = {
        isWorkingDay,
        isNonWorking,
        countWorkingDays,
        addWorkingDays,
        isWithinHours,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — SVG <defs>.
 *
 * Builds gradients per phase, arrow markers, drop-shadow filter.
 * Rebuilt only when (phases, theme) changes.
 */
(function (root) {
    'use strict';

    const { svg, colors } = root.MadGantt;
    const { createNS, clear } = svg;

    // Sufixo por instância: ids de defs são resolvidos DOCUMENT-wide pelos
    // paint servers (url(#...)) — com 2+ gantts na página, ids fixos faziam
    // todos usarem os gradientes/setas da 1ª instância.
    let scope = '';

    function setScope(uid) {
        scope = uid ? `-${slug(uid)}` : '';
    }

    function buildDefs(defsEl, phases, theme) {
        clear(defsEl);
        const dark = theme === 'dark';

        // Phase gradients
        for (const p of (phases || [])) {
            const c = colors.phaseColors(p.hue, dark);
            defsEl.appendChild(linearGradient(gradientId(p.id), c.bar, c.barTo));
            defsEl.appendChild(linearGradient(progressId(p.id), c.progress, c.progressTo));
        }

        // Default gradient (no phase)
        const def = colors.phaseColors(220, dark);
        defsEl.appendChild(linearGradient(gradientId(''), def.bar, def.barTo));
        defsEl.appendChild(linearGradient(progressId(''), def.progress, def.progressTo));

        // Arrow markers
        defsEl.appendChild(arrowMarker(arrowId(false), dark ? 'oklch(0.60 0.012 255)' : 'oklch(0.52 0.01 250)'));
        defsEl.appendChild(arrowMarker(arrowId(true),  dark ? 'oklch(0.72 0.22 25)' : 'oklch(0.55 0.22 25)'));

        // Drop shadow filter for bars
        defsEl.appendChild(shadowFilter(`mg-bar-shadow${scope}`, 1, 2, 0.15));
        defsEl.appendChild(shadowFilter(`mg-bar-shadow-hover${scope}`, 2, 6, 0.25));
        defsEl.appendChild(glowFilter(`mg-crit-glow${scope}`, 4, dark ? 'oklch(0.72 0.22 25 / 0.4)' : 'oklch(0.55 0.22 25 / 0.4)'));
    }

    function linearGradient(id, from, to) {
        const g = createNS('linearGradient', { id, x1: '0', y1: '0', x2: '1', y2: '1' });
        g.appendChild(createNS('stop', { offset: '0%',   'stop-color': from }));
        g.appendChild(createNS('stop', { offset: '100%', 'stop-color': to }));
        return g;
    }

    function arrowMarker(id, color) {
        const m = createNS('marker', {
            id,
            viewBox: '0 0 10 10',
            refX: '9', refY: '5',
            markerWidth: '5', markerHeight: '5',
            orient: 'auto-start-reverse',
        });
        m.appendChild(createNS('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: color }));
        return m;
    }

    function shadowFilter(id, dy, blur, opacity) {
        const f = createNS('filter', { id, x: '-50%', y: '-50%', width: '200%', height: '200%' });
        f.appendChild(createNS('feDropShadow', {
            dx: '0', dy: String(dy), stdDeviation: String(blur),
            'flood-color': 'rgb(0,0,0)', 'flood-opacity': String(opacity),
        }));
        return f;
    }

    function glowFilter(id, blur, color) {
        const f = createNS('filter', { id, x: '-50%', y: '-50%', width: '200%', height: '200%' });
        f.appendChild(createNS('feDropShadow', {
            dx: '0', dy: '0', stdDeviation: String(blur),
            'flood-color': color, 'flood-opacity': '1',
        }));
        return f;
    }

    function slug(s) {
        return String(s || 'default').replace(/[^a-zA-Z0-9_-]/g, '_');
    }

    function gradientId(phaseId) {
        return (phaseId ? `mg-grad-${slug(phaseId)}` : 'mg-grad-default') + scope;
    }

    function progressId(phaseId) {
        return (phaseId ? `mg-prog-${slug(phaseId)}` : 'mg-prog-default') + scope;
    }

    function arrowId(crit) {
        return (crit ? 'mg-arrow-crit' : 'mg-arrow') + scope;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.defs = {
        buildDefs,
        setScope,
        gradientId,
        progressId,
        arrowId,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Timeline header renderer.
 *
 * Builds two stacked rows:
 *   Major  — month / quarter / year (depending on zoom)
 *   Minor  — day / week / month     (depending on zoom)
 *
 * Cells are absolutely positioned inside a `position:relative` parent.
 */
(function (root) {
    'use strict';

    const { dates, zoom } = root.MadGantt;
    const { addDays, isWeekend, sameDay, isoWeekNumber, getDayName, getMonthName, quarterOf } = dates;

    const HOUR_MS = 3600000;

    function render(rootEl, range, zoomKey, today, opts = {}) {
        if (!rootEl) return;
        const profile = zoom.get(zoomKey);
        const pxPerDay = profile.pxPerDay;
        const totalWidth = range.totalDays * pxPerDay;
        const locale = opts.locale || 'pt-br';
        const showWeekends = opts.showWeekends !== false;

        rootEl.innerHTML = '';
        rootEl.style.width = `${totalWidth}px`;
        rootEl.style.position = 'relative';

        const major = document.createElement('div');
        major.className = 'mad-gantt-header-major';
        const minor = document.createElement('div');
        minor.className = 'mad-gantt-header-minor';

        // Major row
        for (const cell of buildMajor(range, profile, locale)) {
            const div = document.createElement('div');
            div.className = 'mad-gantt-header-major-cell';
            div.style.left  = `${cell.offset}px`;
            div.style.width = `${cell.width}px`;
            div.innerHTML = `<span class="mad-gantt-header-month">${cell.short}</span>`
                          + (cell.year ? `<span class="mad-gantt-header-year">${cell.year}</span>` : '');
            major.appendChild(div);
        }

        // Minor row
        for (const cell of buildMinor(range, profile, today, locale)) {
            const div = document.createElement('div');
            let cls = 'mad-gantt-header-minor-cell';
            if (cell.weekend && showWeekends) cls += ' is-weekend';
            if (cell.today) cls += ' is-today';
            if (cell.midnight) cls += ' is-midnight';
            div.className = cls;
            div.style.left  = `${cell.offset}px`;
            div.style.width = `${cell.width}px`;
            div.innerHTML = `<span class="mad-gantt-header-minor-label">${cell.label}</span>`
                          + (cell.sublabel ? `<span class="mad-gantt-header-minor-sub">${cell.sublabel}</span>` : '');
            minor.appendChild(div);
        }

        rootEl.appendChild(major);
        rootEl.appendChild(minor);
    }

    function buildMajor(range, profile, locale) {
        const cells = [];
        const start = range.start;
        const totalDays = range.totalDays;
        const pxPerDay = profile.pxPerDay;
        const end = addDays(start, totalDays);

        if (profile.major === 'day') {
            // Zoom hora — major mostra a data por dia inteiro
            for (let i = 0; i < totalDays; i++) {
                const d = addDays(start, i);
                cells.push({
                    short: `${getDayName(d, locale, true).toUpperCase()} ${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}`,
                    year:  String(d.getFullYear()),
                    offset: i * pxPerDay,
                    width:  pxPerDay,
                });
            }
            return cells;
        }

        if (profile.major === 'year') {
            let cursor = new Date(start.getFullYear(), 0, 1);
            while (cursor < end) {
                const next = new Date(cursor.getFullYear() + 1, 0, 1);
                const vs = cursor < start ? start : cursor;
                const ve = next > end ? end : next;
                cells.push({
                    short: String(cursor.getFullYear()),
                    year: '',
                    offset: dayOffset(vs, start) * pxPerDay,
                    width:  dayOffset(ve, vs) * pxPerDay,
                });
                cursor = next;
            }
        } else if (profile.major === 'quarter') {
            let cursor = new Date(start.getFullYear(), Math.floor(start.getMonth() / 3) * 3, 1);
            while (cursor < end) {
                const next = new Date(cursor.getFullYear(), cursor.getMonth() + 3, 1);
                const vs = cursor < start ? start : cursor;
                const ve = next > end ? end : next;
                cells.push({
                    short: `Q${quarterOf(cursor)}`,
                    year: String(cursor.getFullYear()),
                    offset: dayOffset(vs, start) * pxPerDay,
                    width:  dayOffset(ve, vs) * pxPerDay,
                });
                cursor = next;
            }
        } else {
            let cursor = new Date(start.getFullYear(), start.getMonth(), 1);
            while (cursor < end) {
                const next = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
                const vs = cursor < start ? start : cursor;
                const ve = next > end ? end : next;
                cells.push({
                    short: getMonthName(cursor, locale).toUpperCase(),
                    year:  String(cursor.getFullYear()),
                    offset: dayOffset(vs, start) * pxPerDay,
                    width:  dayOffset(ve, vs) * pxPerDay,
                });
                cursor = next;
            }
        }
        return cells;
    }

    function buildMinor(range, profile, today, locale) {
        const cells = [];
        const { start, totalDays } = range;
        const pxPerDay = profile.pxPerDay;
        const end = addDays(start, totalDays);

        if (profile.minor === 'hour') {
            // pxPerDay = ex. 480, hourStep = 2 → 12 celulas de 40px/dia
            const step = profile.hourStep || 2;
            const pxPerHour = pxPerDay / 24;
            const cellW = step * pxPerHour;
            const cellsPerDay = Math.ceil(24 / step);
            for (let d = 0; d < totalDays; d++) {
                const day = addDays(start, d);
                const dayOff = d * pxPerDay;
                for (let h = 0; h < 24; h += step) {
                    const cellOff = dayOff + h * pxPerHour;
                    const isMidnight = (h === 0);
                    cells.push({
                        label:   `${pad2(h)}:00`,
                        sublabel: '',
                        offset:  cellOff,
                        width:   cellW,
                        weekend: isWeekend(day),
                        today:   sameDay(day, today)
                                  && today.getHours() >= h
                                  && today.getHours() < h + step,
                        midnight: isMidnight,
                    });
                }
            }
        } else if (profile.minor === 'day') {
            for (let i = 0; i < totalDays; i++) {
                const d = addDays(start, i);
                cells.push({
                    label:    String(d.getDate()).padStart(2, '0'),
                    sublabel: getDayName(d, locale, true),
                    offset: i * pxPerDay,
                    width:  pxPerDay,
                    weekend: isWeekend(d),
                    today:   sameDay(d, today),
                });
            }
        } else if (profile.minor === 'week') {
            let i = 0;
            while (i < totalDays) {
                const d = addDays(start, i);
                const dow = (d.getDay() + 6) % 7; // Mon=0
                const consumed = Math.min(7 - dow, totalDays - i);
                // Semana corrente destaca se HOJE cai dentro da célula — só o
                // 1º dia quase nunca marcava a semana atual.
                const cellEnd = addDays(d, consumed);
                cells.push({
                    label:    `S${String(isoWeekNumber(d)).padStart(2, '0')}`,
                    sublabel: `${pad2(d.getDate())}/${pad2(d.getMonth() + 1)}`,
                    offset: i * pxPerDay,
                    width:  consumed * pxPerDay,
                    today:  today >= d && today < cellEnd,
                });
                i += consumed;
            }
        } else {
            // minor === 'month'
            let cursor = new Date(start.getFullYear(), start.getMonth(), 1);
            while (cursor < end) {
                const next = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
                const vs = cursor < start ? start : cursor;
                const ve = next > end ? end : next;
                cells.push({
                    label:    getMonthName(cursor, locale).toUpperCase(),
                    sublabel: String(cursor.getFullYear()).slice(2),
                    offset: dayOffset(vs, start) * pxPerDay,
                    width:  dayOffset(ve, vs) * pxPerDay,
                });
                cursor = next;
            }
        }
        return cells;
    }

    function dayOffset(a, b) {
        const aa = new Date(a.getFullYear(), a.getMonth(), a.getDate()).getTime();
        const bb = new Date(b.getFullYear(), b.getMonth(), b.getDate()).getTime();
        return Math.round((aa - bb) / 86400000);
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.header = {
        render,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Sidebar renderer (column-driven).
 *
 * Renderiza linhas de fase (colapsaveis) + linhas de tarefa em arvore.
 * As COLUNAS sao declaradas via <mad-gantt-column> e chegam em opts.columns
 * (cada uma: field, label, width, format, tree, align, hidden). A sidebar
 * itera as colunas VISIVEIS — honra width/format/label/align e a coluna `tree`
 * (indent + caret). Colunas `hidden` so alimentam o mapping (nao renderizam).
 */
(function (root) {
    'use strict';

    const { dates, colors, svg } = root.MadGantt;
    const { parseDate, daysBetween, fmtDate } = dates;
    const { escAttr, escText } = svg;

    const DEFAULT_LABELS = {
        name: 'Tarefa', code: 'ID', duration: 'Dur', owner: 'Resp.',
        start: 'Início', end: 'Fim', progress: '%', phase: 'Fase',
    };

    /** Colunas visiveis (exclui hidden); fallback p/ layout default; garante 1 tree. */
    function resolveColumns(opts) {
        let cols = (opts.columns || []).filter((c) => c && !c.hidden && c.field);
        if (!cols.length) {
            cols = [
                { field: 'name',     label: 'Tarefa', width: 220, tree: true, align: 'left' },
                { field: 'code',     label: 'ID',     width: 52,  align: 'left' },
                { field: 'duration', label: 'Dur',    width: 44,  format: 'days', align: 'right' },
            ];
            if (opts.showAvatars !== false) {
                cols.push({ field: 'owner', label: 'Resp.', width: 60, align: 'right' });
            }
        }
        if (!cols.some((c) => c.tree)) cols[0] = Object.assign({}, cols[0], { tree: true });
        return cols;
    }

    function render(rootEl, rows, opts = {}) {
        if (!rootEl) return;
        const rowH = opts.rowH || 38;
        const collapsedPhases = opts.collapsedPhases || {};
        const expanded = opts.expanded || {};
        const showAvatars = opts.showAvatars !== false;
        const people = opts.people || {};
        const dark = opts.theme === 'dark';
        const cols = resolveColumns(opts);

        const html = [];
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.kind === 'phase') {
                html.push(renderPhaseRow(row, rowH, collapsedPhases, dark));
            } else {
                html.push(renderTaskRow(row, rowH, expanded, showAvatars, people, opts, cols));
            }
        }
        rootEl.innerHTML = html.join('');
    }

    function renderPhaseRow(row, rowH, collapsedPhases, dark) {
        const p = row.phase;
        const collapsed = !!collapsedPhases[String(p.id)];
        const c = colors.phaseColors(p.hue, dark);
        const code = p.code ? `<span class="mad-gantt-phase-code" style="color:${c.label}">${escText(p.code)}</span>` : '';
        return `
            <div class="mad-gantt-phase-row" data-phase-id="${escAttr(p.id)}" style="height:${rowH}px">
                <span class="mad-gantt-phase-caret">${collapsed ? '▸' : '▾'}</span>
                <span class="mad-gantt-phase-dot" style="background:${c.dot}"></span>
                <span class="mad-gantt-phase-name">${escText(p.name)}</span>
                ${code}
                <span class="mad-gantt-phase-count">${row.count}</span>
            </div>`;
    }

    function renderTaskRow(row, rowH, expanded, showAvatars, people, opts, cols) {
        const t = row.task;
        const isExpandable = !!row.expandable;
        const open = expanded[String(t.id)] !== false;
        const indent = (row.depth || 0) * 14;
        const isCrit = opts.criticalSet && opts.criticalSet.has(String(t.id));
        const isDim  = opts.criticalMode && !isCrit;
        const isSummary = row.kind === 'summary';

        const summaryCls = isSummary ? ' is-summary' : '';
        const critCls = isCrit ? ' is-critical' : '';
        const dimCls  = isDim ? ' is-dimmed' : '';

        const caret = isExpandable
            ? `<span class="mad-gantt-task-caret" data-toggle-task="${escAttr(t.id)}">${open ? '▾' : '▸'}</span>`
            : '<span class="mad-gantt-task-caret mad-gantt-task-caret--leaf"></span>';
        const milestone = t.milestone ? '<span class="mad-gantt-task-milestone-ico">◆</span>' : '';

        const cells = cols.map((c) => {
            const w = `width:${c.width || 120}px`;

            // Coluna arvore: indent + caret + (milestone) + nome
            if (c.tree) {
                const val = c.field === 'name' ? t.name : t[c.field];
                return `<span class="mad-gantt-tl-cell mad-gantt-tl-tree" style="${w};padding-left:${10 + indent}px">`
                     + `${caret}${milestone}`
                     + `<span class="mad-gantt-task-name" title="${escAttr(val)}">${escText(val)}</span>`
                     + `</span>`;
            }

            // Coluna de responsavel com avatares
            if (c.field === 'owner' && showAvatars) {
                return `<span class="mad-gantt-tl-cell mad-gantt-tl-avatars" style="${w}">${avatarsHtml(t, people)}</span>`;
            }

            const align = c.align || 'left';
            return `<span class="mad-gantt-tl-cell" style="${w};text-align:${align}">${fmtCell(t, c)}</span>`;
        }).join('');

        return `
            <div class="mad-gantt-task-row${summaryCls}${critCls}${dimCls}"
                 data-task-id="${escAttr(t.id)}"
                 style="height:${rowH}px">
                ${cells}
            </div>`;
    }

    /** Formata o valor de uma celula conforme col.format. */
    function fmtCell(t, c) {
        if (c.field === 'duration') return computeDuration(t) + 'd';
        let v = t[c.field];
        if (c.field === 'code' && (v == null || v === '')) v = t.id; // code cai p/ id
        if (v == null || v === '') return '';
        switch (c.format) {
            case 'date': {
                const d = parseDate(v);
                return d ? escText(fmtDate(d)) : escText(String(v));
            }
            case 'days':    return escText(String(v)) + 'd';
            // progress interno é fração 0..1 (normalizado no ingest) — clamp puro.
            case 'percent': return Math.round(Math.max(0, Math.min(1, Number(v) || 0)) * 100) + '%';
            default:        return escText(String(v));
        }
    }

    function avatarsHtml(t, people) {
        const ids = collectAssignees(t);
        if (!ids.length) return '';
        const visible = ids.slice(0, 3);
        const more = ids.length - visible.length;
        const stack = visible.map((id) => {
            const p = people[id] || { name: id, initials: initialsOf(id), color: colors.phaseColors(colors.hashHue(id), false).bar };
            return `<span class="mad-gantt-avatar" style="background:${escAttr(p.color)}" title="${escAttr(p.name)}">${escText(p.initials)}</span>`;
        }).join('');
        const moreEl = more > 0 ? `<span class="mad-gantt-avatar mad-gantt-avatar-more">+${more}</span>` : '';
        return `<span class="mad-gantt-task-avatars">${stack}${moreEl}</span>`;
    }

    function computeDuration(t) {
        const s = parseDate(t.start);
        const e = parseDate(t.end) || s;
        if (!s) return 0;
        return Math.max(1, daysBetween(s, e) + 1);
    }

    function collectAssignees(t) {
        if (Array.isArray(t.assignees)) return t.assignees.map(String);
        if (Array.isArray(t.owners))    return t.owners.map(String);
        if (t.owner) return String(t.owner).split(/[,;]\s*/).filter(Boolean);
        return [];
    }

    function initialsOf(name) {
        const s = String(name || '').trim();
        if (!s) return '?';
        const parts = s.split(/\s+/);
        return ((parts[0][0] || '') + (parts[1] ? parts[1][0] : '')).toUpperCase().slice(0, 2);
    }

    /**
     * Tasklist head — um cabecalho por coluna visivel (label + width + align).
     */
    function renderHead(rootEl, opts = {}) {
        if (!rootEl) return;
        const cols = resolveColumns(opts);
        const cells = cols.map((c) => {
            const align = c.tree ? 'left' : (c.align || 'left');
            const label = c.label || DEFAULT_LABELS[c.field] || c.field;
            const cls = c.tree ? 'mad-gantt-th-cell is-tree' : 'mad-gantt-th-cell';
            return `<span class="${cls}" style="width:${c.width || 120}px;text-align:${align}">${escText(label)}</span>`;
        }).join('');
        rootEl.innerHTML = `<div class="mad-gantt-tl-head-row">${cells}</div>`;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.sidebar = {
        render,
        renderHead,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Bar shape renderer.
 *
 * Renders <g class="mad-gantt-bar-group" data-task-id="..."> with:
 *   - track rect
 *   - fill rect (gradient)
 *   - progress overlay rect
 *   - critical halo stroke
 *   - invisible resize handles (left/right)
 *   - text label + percent
 *
 * Summary bars render as low-profile fills with caret accents.
 * Milestones render as diamonds.
 */
(function (root) {
    'use strict';

    const { svg, defs, colors } = root.MadGantt;
    const { createNS, diamondPoints, escAttr, escText } = svg;

    const TEXT_PADDING = 8;

    function renderBar(group, task, geom, ctx) {
        const phase = ctx.phaseOfTask(task);
        const dark  = ctx.theme === 'dark';
        const c     = phase ? colors.phaseColors(phase.hue, dark) : colors.phaseColors(220, dark);
        const isCrit = ctx.criticalSet && ctx.criticalSet.has(String(task.id));
        const isDim  = ctx.criticalMode && !isCrit;
        const barH = ctx.barH;
        const barY = geom.y + (geom.h - barH) / 2;
        const radius = 4;
        const phaseId = phase ? phase.id : '';

        group.setAttribute('class', `mad-gantt-bar-group${isCrit ? ' is-critical' : ''}${isDim ? ' is-dimmed' : ''}`);
        group.setAttribute('data-task-id', task.id);

        // Track
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-bar-track',
            x: geom.x, y: barY, width: geom.w, height: barH, rx: radius,
            fill: c.track,
        }));

        // Fill
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-bar-fill',
            x: geom.x, y: barY, width: geom.w, height: barH, rx: radius,
            fill: `url(#${defs.gradientId(phaseId)})`,
            'data-drag-mode': 'move',
        }));

        // Progress overlay
        const prog = clamp01(task.progress);
        if (prog > 0) {
            group.appendChild(createNS('rect', {
                class: 'mad-gantt-bar-progress',
                x: geom.x, y: barY,
                width: geom.w * prog, height: barH, rx: radius,
                fill: `url(#${defs.progressId(phaseId)})`,
                'pointer-events': 'none',
            }));
        }

        // Critical halo
        if (isCrit) {
            group.appendChild(createNS('rect', {
                class: 'mad-gantt-bar-crit-stroke',
                x: geom.x + 0.5, y: barY + 0.5,
                width: geom.w - 1, height: barH - 1, rx: radius,
                fill: 'none',
                'pointer-events': 'none',
            }));
        }

        // Resize handles
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-bar-handle mad-gantt-bar-handle--left',
            x: geom.x - 3, y: barY, width: 6, height: barH,
            'data-drag-mode': 'left',
        }));
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-bar-handle mad-gantt-bar-handle--right',
            x: geom.x + geom.w - 3, y: barY, width: 6, height: barH,
            'data-drag-mode': 'right',
        }));

        // Labels (conditional on width)
        if (geom.w > 60 && task.name) {
            const label = createNS('text', {
                class: 'mad-gantt-bar-label',
                x: geom.x + TEXT_PADDING,
                y: barY + barH / 2 + 1,
                'dominant-baseline': 'middle',
                fill: c.text,
                'pointer-events': 'none',
            });
            label.textContent = task.name;
            group.appendChild(label);
        }
        if (geom.w > 110 && prog > 0) {
            const pct = createNS('text', {
                class: 'mad-gantt-bar-pct',
                x: geom.x + geom.w - TEXT_PADDING,
                y: barY + barH / 2 + 1,
                'text-anchor': 'end',
                'dominant-baseline': 'middle',
                fill: c.text,
                'pointer-events': 'none',
            });
            pct.textContent = `${Math.round(prog * 100)}%`;
            group.appendChild(pct);
        }

        // Connection anchors (bolinhas de dependencia) — visiveis no hover.
        // Arrastar de uma ancora cria dependencia sem precisar de tecla.
        const cy = barY + barH / 2;
        group.appendChild(createNS('circle', {
            class: 'mad-gantt-bar-anchor mad-gantt-bar-anchor--start',
            cx: geom.x, cy, r: 4,
            'data-dep-anchor': 'start',
            'data-dep-task': task.id,
        }));
        group.appendChild(createNS('circle', {
            class: 'mad-gantt-bar-anchor mad-gantt-bar-anchor--end',
            cx: geom.x + geom.w, cy, r: 4,
            'data-dep-anchor': 'end',
            'data-dep-task': task.id,
        }));
    }

    function renderSummary(group, task, geom, ctx) {
        const phase = ctx.phaseOfTask(task);
        const dark  = ctx.theme === 'dark';
        const c     = phase ? colors.phaseColors(phase.hue, dark) : colors.phaseColors(220, dark);
        const barH = Math.max(8, ctx.barH * 0.45);
        const barY = geom.y + (geom.h - barH) / 2;
        const phaseId = phase ? phase.id : '';

        group.setAttribute('class', 'mad-gantt-bar-group is-summary');
        group.setAttribute('data-task-id', task.id);

        // Body (slim bar)
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-summary-body',
            x: geom.x, y: barY, width: geom.w, height: barH, rx: 2,
            fill: `url(#${defs.gradientId(phaseId)})`,
        }));
        // End caps
        const capH = barH + 4;
        group.appendChild(createNS('polygon', {
            class: 'mad-gantt-summary-cap',
            points: `${geom.x},${barY - 2} ${geom.x + 5},${barY - 2} ${geom.x},${barY + capH - 2}`,
            fill: c.label,
        }));
        group.appendChild(createNS('polygon', {
            class: 'mad-gantt-summary-cap',
            points: `${geom.x + geom.w},${barY - 2} ${geom.x + geom.w - 5},${barY - 2} ${geom.x + geom.w},${barY + capH - 2}`,
            fill: c.label,
        }));
    }

    function renderMilestone(group, task, geom, ctx) {
        const phase = ctx.phaseOfTask(task);
        const dark  = ctx.theme === 'dark';
        const c     = phase ? colors.phaseColors(phase.hue, dark) : colors.phaseColors(155, dark);
        const isCrit = ctx.criticalSet && ctx.criticalSet.has(String(task.id));
        const isDim  = ctx.criticalMode && !isCrit;
        const size = 11;
        const cx = geom.x + geom.w / 2;
        const cy = geom.y + geom.h / 2;
        const phaseId = phase ? phase.id : '';

        group.setAttribute('class', `mad-gantt-milestone${isCrit ? ' is-critical' : ''}${isDim ? ' is-dimmed' : ''}`);
        group.setAttribute('data-task-id', task.id);
        group.setAttribute('data-cx', cx); // âncora p/ patchBar mover via transform no drag

        if (isCrit) {
            group.appendChild(createNS('polygon', {
                class: 'mad-gantt-milestone-halo',
                points: diamondPoints(cx, cy, size + 3),
                fill: 'none',
            }));
        }

        group.appendChild(createNS('polygon', {
            points: diamondPoints(cx, cy, size),
            fill: `url(#${defs.gradientId(phaseId)})`,
            stroke: c.label,
            'stroke-width': '1.5',
            'data-drag-mode': 'move',
        }));

        group.appendChild(createNS('polygon', {
            points: diamondPoints(cx, cy, size - 3),
            fill: c.progressTo,
            opacity: '0.5',
            'pointer-events': 'none',
        }));
    }

    function renderBaseline(group, task, geom, ctx) {
        if (!task.baselineStart || !task.baselineEnd) return;
        const bsX = ctx.dayToX(task.baselineStart);
        const beX = ctx.dayToX(task.baselineEnd) + ctx.pxPerDay;
        const barH = Math.max(4, ctx.barH * 0.25);
        const y = geom.y + geom.h - barH - 4;
        group.appendChild(createNS('rect', {
            class: 'mad-gantt-baseline',
            x: bsX, y, width: Math.max(2, beX - bsX), height: barH, rx: 1,
        }));
    }

    function clamp01(v) {
        // progress interno é fração 0..1 (normalizado no ingest) — clamp puro.
        if (v == null) return 0;
        const n = +v;
        if (!Number.isFinite(n)) return 0;
        return Math.max(0, Math.min(1, n));
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.bars = {
        renderBar,
        renderSummary,
        renderMilestone,
        renderBaseline,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Dependency arrow renderer.
 *
 * Three routing styles:
 *   elbow    — right-angle bends with rounded corners (default)
 *   curved   — cubic bezier
 *   straight — single line
 *
 * Endpoint policy depends on dep.type (FS / SS / FF / SF).
 */
(function (root) {
    'use strict';

    const { svg, defs } = root.MadGantt;
    const { createNS, roundedPath } = svg;

    function render(g, dependencies, geom, ctx) {
        if (!g) return;
        while (g.firstChild) g.removeChild(g.firstChild);
        const style = ctx.arrowStyle || 'elbow';
        const rowH = ctx.rowH;
        const criticalSet = ctx.criticalSet || new Set();
        const criticalMode = !!ctx.criticalMode;

        for (const dep of (dependencies || [])) {
            const from = geom.byId[dep.from];
            const to   = geom.byId[dep.to];
            if (!from || !to) continue;
            const isCrit = criticalSet.has(String(dep.from)) && criticalSet.has(String(dep.to));
            const isDim  = criticalMode && !isCrit;
            const arrow = buildArrow(from, to, dep.type || 'FS', style, rowH);
            const wrap = createNS('g', {
                class: `mad-gantt-arrow${isCrit ? ' is-critical' : ''}${isDim ? ' is-dimmed' : ''}`,
                'data-dep-from': dep.from,
                'data-dep-to': dep.to,
            });
            wrap.appendChild(createNS('path', {
                d: arrow.path, fill: 'none',
                'marker-end': `url(#${defs.arrowId(isCrit)})`,
            }));
            g.appendChild(wrap);
        }
    }

    function buildArrow(from, to, type, style, rowH) {
        // FS = end-of-from → start-of-to
        // SS = start-of-from → start-of-to
        // FF = end-of-from → end-of-to
        // SF = start-of-from → end-of-to
        let sx, tx;
        switch (type) {
            case 'SS': sx = from.x; tx = to.x; break;
            case 'FF': sx = from.x + from.w; tx = to.x + to.w; break;
            case 'SF': sx = from.x; tx = to.x + to.w; break;
            case 'FS':
            default:   sx = from.x + from.w; tx = to.x;
        }
        const sy = from.y + from.h / 2;
        const ty = to.y   + to.h / 2;
        // Folga mínima antes da borda — a ponta do marker precisa ENCOSTAR na
        // barra (6px deixava a seta flutuando sem encaixar).
        const headPad = 2;

        if (style === 'straight') {
            const endX = endpointAdjust(tx, sx, type, headPad);
            return { path: `M ${sx} ${sy} L ${endX} ${ty}` };
        }

        if (style === 'curved') {
            const dx = Math.max(20, Math.abs(tx - sx) / 2);
            const endX = endpointAdjust(tx, sx, type, headPad);
            return {
                path: `M ${sx} ${sy} C ${sx + dx} ${sy}, ${endX - dx} ${ty}, ${endX} ${ty}`,
            };
        }

        // Default: elbow
        const points = elbowPoints(sx, sy, tx, ty, from, to, type, rowH, headPad);
        return { path: roundedPath(points, 4) };
    }

    function elbowPoints(sx, sy, tx, ty, from, to, type, rowH, headPad) {
        const stepOut  = 10;
        const fromEnd  = type === 'FS' || type === 'FF'; // sai pela ponta direita da origem
        const intoLeft = type === 'FS' || type === 'SS'; // entra pela borda esquerda do destino
        const exitX    = fromEnd  ? sx + stepOut : sx - stepOut;
        const entryPre = intoLeft ? tx - stepOut : tx + stepOut; // canal vertical antes da entrada
        const txIn     = intoLeft ? tx - headPad : tx + headPad;

        // O segmento FINAL é sempre HORIZONTAL entrando na borda do destino —
        // a versão antiga terminava em vertical 6px AO LADO da barra (a ponta
        // descia sem encostar em nada).

        // Rota em Z (4 pontos): canal vertical no meio do caminho. Só vale
        // quando o canal é monotônico E o 1º segmento se afasta da origem
        // (senão a linha atravessa a própria barra — caso SS "pra frente").
        const monotonic = intoLeft ? exitX <= entryPre : exitX >= entryPre;
        if (Math.abs(sy - ty) >= 1 && monotonic) {
            const midX = halfway(exitX, entryPre);
            const dirOut = fromEnd ? 1 : -1;
            if (Math.sign(midX - sx) === dirOut) {
                return [[sx, sy], [midX, sy], [midX, ty], [txIn, ty]];
            }
        }

        // Mesma linha com canal direto (ex.: FS pra frente na própria row)
        if (Math.abs(sy - ty) < 1) {
            const direct = intoLeft ? (sx < txIn && exitX <= entryPre)
                                    : (sx > txIn && exitX >= entryPre);
            if (direct) return [[sx, sy], [txIn, ty]];
        }

        // Rota em U (6 pontos): stub pra fora da origem, desce/sobe pro canal
        // na divisa da row, corre até ANTES do destino, alinha na altura da
        // barra e entra na horizontal. Cobre overlap, backward e SS/SF.
        const dropY = Math.abs(sy - ty) < 1
            ? sy + rowH / 2 + 4
            : sy + (ty > sy ? 1 : -1) * (rowH / 2 + 4);
        return [
            [sx, sy],
            [exitX, sy],
            [exitX, dropY],
            [entryPre, dropY],
            [entryPre, ty],
            [txIn, ty],
        ];
    }

    function endpointAdjust(tx, sx, type, headPad) {
        const reverse = (type === 'FF' || type === 'SF');
        return reverse ? tx + headPad : tx - headPad;
    }

    function halfway(a, b) {
        return Math.round((a + b) / 2);
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.dependencies = {
        render,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Main SVG composer.
 *
 * Sub-passes in order:
 *   weekendStripes → gridLines → rowBackgrounds → dependencyArrows
 *                 → bars/milestones → todayLine
 *
 * Each sub-pass mutates a dedicated <g> layer (added once, cleared/refilled).
 */
(function (root) {
    'use strict';

    const { svg, dates, zoom, defs, bars, dependencies } = root.MadGantt;
    const { createNS, clear } = svg;
    const { addDays, isWeekend, sameDay } = dates;

    const LAYER_IDS = [
        'mg-layer-weekends',
        'mg-layer-grid',
        'mg-layer-rowbg',
        'mg-layer-deps',
        'mg-layer-bars',
        'mg-layer-today',
    ];

    function ensureLayers(svgEl) {
        // Single shared <defs>
        let defsEl = svgEl.querySelector('defs');
        if (!defsEl) {
            defsEl = createNS('defs');
            svgEl.appendChild(defsEl);
        }
        const layers = {};
        for (const id of LAYER_IDS) {
            let g = svgEl.querySelector(`#${id}`);
            if (!g) {
                g = createNS('g', { id });
                svgEl.appendChild(g);
            }
            layers[id] = g;
        }
        return { defsEl, layers };
    }

    function render(svgEl, rows, geom, ctx) {
        if (!svgEl) return;
        const { defsEl, layers } = ensureLayers(svgEl);
        const profile  = zoom.get(ctx.zoom);
        const pxPerDay = profile.pxPerDay;
        const chartW   = ctx.range.totalDays * pxPerDay;
        const chartH   = geom.totalHeight;

        svgEl.setAttribute('width', String(chartW));
        svgEl.setAttribute('height', String(chartH));
        svgEl.style.width  = `${chartW}px`;
        svgEl.style.height = `${chartH}px`;

        // Defs por instância (render é síncrono — o escopo vale até o fim
        // deste frame, cobrindo bars/milestones/deps abaixo).
        defs.setScope(ctx.uid || svgEl.closest('.mad-gantt')?.id || '');
        defs.buildDefs(defsEl, ctx.phases, ctx.theme);

        if (ctx.showWeekends !== false && (profile.minor === 'day' || profile.minor === 'hour')) {
            renderWeekends(layers['mg-layer-weekends'], ctx.range, pxPerDay, chartH, ctx);
        } else {
            clear(layers['mg-layer-weekends']);
        }

        if (ctx.showGrid !== false) {
            renderGrid(layers['mg-layer-grid'], ctx.range, pxPerDay, chartH, profile);
        } else {
            clear(layers['mg-layer-grid']);
        }

        renderRowBackgrounds(layers['mg-layer-rowbg'], rows, chartW, ctx.rowH, ctx);

        dependencies.render(layers['mg-layer-deps'], ctx.dependencies, geom, {
            arrowStyle: ctx.arrowStyle || 'elbow',
            rowH: ctx.rowH,
            criticalSet: ctx.criticalSet,
            criticalMode: ctx.criticalMode,
        });

        renderBars(layers['mg-layer-bars'], rows, geom, ctx);
        renderToday(layers['mg-layer-today'], ctx.range, pxPerDay, chartH, ctx.today);
    }

    function renderWeekends(g, range, pxPerDay, chartH, ctx) {
        clear(g);
        // workingDays()/holidays() do builder valem aqui: sombreia todo dia
        // NÃO-útil (antes era sáb/dom hardcoded e feriado não existia).
        const workingDays = (ctx && Array.isArray(ctx.workingDays) && ctx.workingDays.length)
            ? ctx.workingDays : [1, 2, 3, 4, 5];
        const holidays = new Set((ctx && ctx.holidays) || []);
        const { fmtISO } = dates;
        for (let i = 0; i < range.totalDays; i++) {
            const d = addDays(range.start, i);
            const isHoliday = holidays.has(fmtISO(d));
            if (workingDays.includes(d.getDay()) && !isHoliday) continue;
            g.appendChild(createNS('rect', {
                class: `mad-gantt-weekend${isHoliday ? ' mad-gantt-holiday' : ''}`,
                x: i * pxPerDay, y: 0,
                width: pxPerDay, height: chartH,
            }));
        }
    }

    function renderGrid(g, range, pxPerDay, chartH, profile) {
        clear(g);
        if (profile.minor === 'hour') {
            const step = profile.hourStep || 2;
            const pxPerHour = pxPerDay / 24;
            for (let i = 0; i <= range.totalDays; i++) {
                // Linha forte na meia-noite (mudanca de dia)
                g.appendChild(createNS('line', {
                    class: 'mad-gantt-grid-line mad-gantt-grid-line--strong',
                    x1: i * pxPerDay, x2: i * pxPerDay,
                    y1: 0, y2: chartH,
                    'stroke-width': '2',
                }));
                // Linhas fracas a cada N horas
                if (i < range.totalDays) {
                    for (let h = step; h < 24; h += step) {
                        const x = i * pxPerDay + h * pxPerHour;
                        g.appendChild(createNS('line', {
                            class: 'mad-gantt-grid-line',
                            x1: x, x2: x, y1: 0, y2: chartH,
                            'stroke-width': '0.5',
                        }));
                    }
                }
            }
        } else if (profile.minor === 'day') {
            for (let i = 0; i <= range.totalDays; i++) {
                const d = addDays(range.start, i);
                const dow = d.getDay();
                const isMon = dow === 1;
                const isMonth = d.getDate() === 1;
                const weight = isMonth ? 2 : isMon ? 1 : 0.5;
                g.appendChild(createNS('line', {
                    class: weight >= 1 ? 'mad-gantt-grid-line mad-gantt-grid-line--strong' : 'mad-gantt-grid-line',
                    x1: i * pxPerDay, x2: i * pxPerDay,
                    y1: 0, y2: chartH,
                    'stroke-width': String(weight),
                }));
            }
        } else if (profile.minor === 'week') {
            for (let i = 0; i <= range.totalDays; i++) {
                const d = addDays(range.start, i);
                if ((d.getDay() + 6) % 7 === 0 || d.getDate() === 1) {
                    const isMonth = d.getDate() === 1;
                    g.appendChild(createNS('line', {
                        class: isMonth ? 'mad-gantt-grid-line mad-gantt-grid-line--strong' : 'mad-gantt-grid-line',
                        x1: i * pxPerDay, x2: i * pxPerDay,
                        y1: 0, y2: chartH,
                        'stroke-width': isMonth ? '2' : '1',
                    }));
                }
            }
        } else {
            for (let i = 0; i <= range.totalDays; i++) {
                const d = addDays(range.start, i);
                if (d.getDate() === 1) {
                    g.appendChild(createNS('line', {
                        class: 'mad-gantt-grid-line',
                        x1: i * pxPerDay, x2: i * pxPerDay,
                        y1: 0, y2: chartH,
                        'stroke-width': '1',
                    }));
                }
            }
        }
    }

    function renderRowBackgrounds(g, rows, chartW, rowH, ctx) {
        clear(g);
        const dark = ctx.theme === 'dark';
        const { colors } = root.MadGantt;
        let y = 0;
        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.kind === 'phase') {
                const c = colors.phaseColors(row.phase.hue, dark);
                g.appendChild(createNS('rect', {
                    class: 'mad-gantt-phase-bg',
                    x: 0, y, width: chartW, height: rowH,
                    fill: c.rowTint,
                }));
            } else {
                g.appendChild(createNS('line', {
                    class: 'mad-gantt-row-divider',
                    x1: 0, x2: chartW, y1: y + rowH, y2: y + rowH,
                }));
            }
            y += rowH;
        }
    }

    function renderBars(g, rows, geom, ctx) {
        clear(g);
        const phasesById = {};
        for (const p of (ctx.phases || [])) phasesById[String(p.id)] = p;
        const ctx2 = {
            ...ctx,
            phaseOfTask: (t) => {
                if (!ctx.phaseField) return null;
                const v = t[ctx.phaseField];
                return v != null ? (phasesById[String(v)] || null) : null;
            },
            barH: zoom.get(ctx.zoom).barH,
            pxPerDay: zoom.get(ctx.zoom).pxPerDay,
            dayToX: (d) => {
                const { coords } = root.MadGantt;
                return coords.dayToX(ctx.range.start, d, zoom.get(ctx.zoom).pxPerDay);
            },
        };

        for (let i = 0; i < rows.length; i++) {
            const row = rows[i];
            if (row.kind === 'phase') continue;
            const t = row.task;
            const gm = geom.byId[t.id];
            if (!gm) continue;
            const group = createNS('g');
            if (t.milestone) {
                bars.renderMilestone(group, t, gm, ctx2);
            } else if (row.kind === 'summary') {
                bars.renderSummary(group, t, gm, ctx2);
            } else {
                bars.renderBaseline(group, t, gm, ctx2);
                bars.renderBar(group, t, gm, ctx2);
            }
            g.appendChild(group);
        }
    }

    function renderToday(g, range, pxPerDay, chartH, today) {
        clear(g);
        if (!today) today = new Date();
        // Offset DST-safe (dia inteiro round + fração intradia do relógio)
        const off = root.MadGantt.coords.dayOffsetFloat(range.start, today);
        if (off < 0 || off > range.totalDays) return;
        const x = off * pxPerDay;
        g.appendChild(createNS('line', {
            class: 'mad-gantt-today-line',
            x1: x, x2: x, y1: 0, y2: chartH,
        }));
        g.appendChild(createNS('circle', {
            class: 'mad-gantt-today-dot',
            cx: x, cy: 6, r: 4,
        }));
    }

    /**
     * Fast path: update only one bar's x/w after a drag tick.
     * Mutates DOM attributes; avoids a full re-render.
     */
    function patchBar(svgEl, taskId, geom, ctx) {
        if (!svgEl) return;
        const group = svgEl.querySelector(`#mg-layer-bars [data-task-id="${cssEscape(taskId)}"]`);
        if (!group || !geom) return;
        // Milestone: grupo só tem polygons — move o diamante via transform.
        if (group.getAttribute('class') && group.getAttribute('class').includes('mad-gantt-milestone')) {
            const baseCx = parseFloat(group.getAttribute('data-cx'));
            if (Number.isFinite(baseCx)) {
                const newCx = geom.x + geom.w / 2;
                group.setAttribute('transform', `translate(${newCx - baseCx},0)`);
            }
            return;
        }

        const barH = zoom.get(ctx.zoom).barH;
        const barY = geom.y + (geom.h - barH) / 2;
        const fill   = group.querySelector('.mad-gantt-bar-fill');
        const track  = group.querySelector('.mad-gantt-bar-track');
        const prog   = group.querySelector('.mad-gantt-bar-progress');
        const crit   = group.querySelector('.mad-gantt-bar-crit-stroke');
        const hL     = group.querySelector('.mad-gantt-bar-handle--left');
        const hR     = group.querySelector('.mad-gantt-bar-handle--right');
        const label  = group.querySelector('.mad-gantt-bar-label');
        const pct    = group.querySelector('.mad-gantt-bar-pct');

        // Ratio de progresso lido ANTES de atualizar a width do fill (senão
        // prog/fillNovo congela o overlay em px absolutos durante o resize).
        const oldFillW = fill ? parseFloat(fill.getAttribute('width')) : 0;

        if (track) {
            track.setAttribute('x', geom.x);
            track.setAttribute('width', geom.w);
        }
        if (fill) {
            fill.setAttribute('x', geom.x);
            fill.setAttribute('width', geom.w);
        }
        if (prog && fill) {
            const taskProgress = +(prog.getAttribute('data-prog') || 0)
                || (oldFillW > 0 ? parseFloat(prog.getAttribute('width')) / oldFillW : 0) || 0;
            prog.setAttribute('x', geom.x);
            const w = geom.w * Math.max(0, Math.min(1, taskProgress));
            if (Number.isFinite(w) && w > 0) prog.setAttribute('width', w);
        }
        if (crit) {
            crit.setAttribute('x', geom.x + 0.5);
            crit.setAttribute('width', geom.w - 1);
        }
        if (hL) hL.setAttribute('x', geom.x - 3);
        if (hR) hR.setAttribute('x', geom.x + geom.w - 3);
        if (label) label.setAttribute('x', geom.x + 8);
        if (pct)   pct.setAttribute('x', geom.x + geom.w - 8);
    }

    function cssEscape(s) {
        if (window.CSS && CSS.escape) return CSS.escape(String(s));
        return String(s).replace(/[^a-zA-Z0-9_-]/g, (c) => `\\${c}`);
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.chartSvg = {
        render,
        patchBar,
        ensureLayers,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Minimap.
 *
 * Compact ~260×80 SVG with all tasks colored by phase + a viewport
 * overlay rect synced to the chart scroll position. Click jumps the
 * chart to the clicked day (centered on viewport).
 */
(function (root) {
    'use strict';

    const { dates, colors, svg } = root.MadGantt;
    const { parseDate, daysBetween } = dates;
    const { createNS, clear } = svg;

    function render(rootEl, ctx) {
        if (!rootEl) return;
        const w = ctx.width || 260;
        const h = ctx.height || 80;
        const dark = ctx.theme === 'dark';
        const tasks = ctx.tasks || [];
        const phases = ctx.phases || [];
        const range = ctx.range;
        if (!range) return;
        const totalDays = range.totalDays;

        let s = rootEl.querySelector('svg');
        if (!s) {
            s = createNS('svg', { viewBox: `0 0 ${w} ${h}`, width: String(w), height: String(h) });
            rootEl.appendChild(s);
        } else {
            s.setAttribute('viewBox', `0 0 ${w} ${h}`);
            s.setAttribute('width', String(w));
            s.setAttribute('height', String(h));
            clear(s);
        }

        const rowsCount = Math.max(1, phases.length || 1);
        const rowH = (h - 12) / rowsCount;
        const phaseIdx = {};
        phases.forEach((p, i) => { phaseIdx[String(p.id)] = i; });

        // Phase row tints
        for (let i = 0; i < phases.length; i++) {
            const c = colors.phaseColors(phases[i].hue, dark);
            s.appendChild(createNS('rect', {
                x: 0, y: 6 + i * rowH, width: w, height: rowH - 2,
                fill: c.rowTint,
            }));
        }

        // Task rects / milestones
        for (const t of tasks) {
            const start = parseDate(t.start);
            const end   = parseDate(t.end) || start;
            if (!start) continue;
            const sOff = daysBetween(range.start, start);
            const eOff = daysBetween(range.start, end);
            const x  = (sOff / totalDays) * w;
            const tw = Math.max(2, ((eOff - sOff + 1) / totalDays) * w);
            const pi = ctx.phaseField && t[ctx.phaseField] != null
                ? (phaseIdx[String(t[ctx.phaseField])] ?? 0)
                : 0;
            const phase = phases[pi];
            const color = phase ? colors.phaseColors(phase.hue, dark).bar : (dark ? '#60a5fa' : '#3b82f6');
            if (t.milestone) {
                s.appendChild(createNS('circle', {
                    cx: x, cy: 6 + pi * rowH + rowH / 2, r: 2.2,
                    fill: color,
                }));
            } else {
                s.appendChild(createNS('rect', {
                    x, y: 6 + pi * rowH + 2,
                    width: tw, height: rowH - 6, rx: 1.5,
                    fill: color,
                }));
            }
        }

        // Viewport overlay
        const vStart = ctx.viewportStart || 0;
        const vDays  = Math.max(1, ctx.viewportDays || 1);
        const vx = (vStart / totalDays) * w;
        const vw = Math.min(w - vx, (vDays / totalDays) * w);
        s.appendChild(createNS('rect', {
            class: 'mad-gantt-minimap-viewport',
            x: Math.max(0, vx), y: 2,
            width: Math.max(8, vw), height: h - 4,
            rx: 2,
        }));
    }

    function dayFromClick(e, rootEl, totalDays) {
        const rect = rootEl.getBoundingClientRect();
        const x = e.clientX - rect.left;
        return (x / rect.width) * totalDays;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.minimap = {
        render,
        dayFromClick,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Hover tooltip.
 *
 * Single shared HTML node appended to <body> (escapes any
 * transform/filter ancestor that would break fixed positioning).
 */
(function (root) {
    'use strict';

    const { dates, colors, svg } = root.MadGantt;
    const { parseDate, daysBetween, fmtDate, fmtDateTime } = dates;

    function fmtTaskDate(d, raw, locale) {
        if (!d) return '—';
        const hasTime = typeof raw === 'string' && /\d{1,2}:\d{2}/.test(raw);
        if (hasTime) {
            const pad = (n) => String(n).padStart(2, '0');
            return `${fmtDate(d, { long: true, locale })} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
        }
        return fmtDate(d, { long: true, locale });
    }
    const { escAttr, escText } = svg;

    let tipEl = null;
    let raf = 0;
    let lastTask = null;
    let lastPos = null;
    let lastCtx = null;

    function ensure() {
        if (tipEl) return tipEl;
        tipEl = document.createElement('div');
        tipEl.className = 'mad-gantt-tooltip';
        tipEl.style.display = 'none';
        document.body.appendChild(tipEl);
        return tipEl;
    }

    function show(task, pos, ctx) {
        lastTask = task;
        lastPos  = pos;
        lastCtx  = ctx;
        if (raf) cancelAnimationFrame(raf);
        raf = requestAnimationFrame(commit);
    }

    function commit() {
        if (!lastTask) { hide(); return; }
        const el = ensure();
        el.innerHTML = renderHTML(lastTask, lastCtx);
        el.style.left = `${lastPos.x + 14}px`;
        el.style.top  = `${lastPos.y + 14}px`;
        el.style.display = '';
        // Re-position if it overflows the viewport
        const r = el.getBoundingClientRect();
        const vw = window.innerWidth, vh = window.innerHeight;
        if (r.right > vw - 8) el.style.left = `${lastPos.x - r.width - 14}px`;
        if (r.bottom > vh - 8) el.style.top  = `${lastPos.y - r.height - 14}px`;
    }

    function hide() {
        if (raf) { cancelAnimationFrame(raf); raf = 0; }
        if (tipEl) tipEl.style.display = 'none';
        lastTask = null;
    }

    function renderHTML(task, ctx) {
        const phase = ctx && ctx.phaseOfTask ? ctx.phaseOfTask(task) : null;
        const dark  = ctx && ctx.theme === 'dark';
        const c     = phase ? colors.phaseColors(phase.hue, dark) : colors.phaseColors(220, dark);
        const s = parseDate(task.start);
        const e = parseDate(task.end) || s;
        const dur = s ? Math.max(1, daysBetween(s, e) + 1) : 0;
        const prog = clamp01(task.progress);
        const code = task.code || task.id || '';
        const isCrit = ctx && ctx.criticalSet && ctx.criticalSet.has(String(task.id));

        // Template custom via popover()/popover-title/popover-content —
        // placeholders {title} {start} {end} {percent} {rowId} {owner} {code}.
        if (ctx && (ctx.popTitle || ctx.popContent)) {
            const sub = (tpl) => String(tpl || '').replace(/\{(title|start|end|percent|rowId|owner|code)\}/g, (_, k) => {
                switch (k) {
                    case 'title':   return String(task.name || '');
                    case 'start':   return String(task.start || '');
                    case 'end':     return String(task.end || '');
                    case 'percent': return String(Math.round(prog * 100));
                    case 'rowId':   return String(task.id || '');
                    case 'owner':   return String(task.owner || '');
                    case 'code':    return String(code);
                    default:        return '';
                }
            });
            const t = escText(sub(ctx.popTitle || '{title}'));
            const b = escText(sub(ctx.popContent || ''));
            return `<div class="tt-name">${t}</div>${b ? `<div class="tt-custom">${b}</div>` : ''}`;
        }

        // Textos da dica no idioma do app (config.labels, montado no
        // MadGantt::view()); o default é pt-BR, como o resto do bundle. Os
        // selos "caminho crítico"/"marco" eram "CRITICAL"/"MILESTONE" fixos.
        const L = Object.assign({
            owners: 'Responsáveis', dependsOn: 'Depende de', start: 'Início',
            end: 'Fim', duration: 'Duração', progress: 'Progresso',
            day: 'dia', days: 'dias',
            criticalTag: 'Crítica', milestoneTag: 'Marco',
        }, (ctx && ctx.labels) || {});
        const tag = (txt) => escText(String(txt || '').toUpperCase());

        const chips = [
            code ? `<span class="tt-code" style="background:${c.chipBg};color:${c.chipText};border-color:${c.label}">${escText(code)}</span>` : '',
            phase ? `<span class="tt-phase">${escText(phase.name)}</span>` : '',
            isCrit ? `<span class="tt-crit">${tag(L.criticalTag)}</span>` : '',
            task.milestone ? `<span class="tt-milestone">${tag(L.milestoneTag)}</span>` : '',
        ].filter(Boolean).join('');

        const locale   = (ctx && ctx.locale) || 'pt-br';
        const startLbl = fmtTaskDate(s, task.start, locale);
        const endLbl   = fmtTaskDate(e, task.end,   locale);

        const assignees = collectAssignees(task);
        const people = (ctx && ctx.people) || {};
        const assigneesHtml = assignees.length > 0 ? `
            <div class="tt-assignees">
                <div class="tt-key">${escText(L.owners)}</div>
                <div class="tt-people">
                    ${assignees.map((id) => {
                        const p = people[id] || { name: id, initials: initialsOf(id), color: colors.phaseColors(colors.hashHue(id), dark).bar };
                        return `<div class="tt-person">
                            <span class="tt-avatar" style="background:${escAttr(p.color)}">${escText(p.initials)}</span>
                            <span>${escText(p.name)}</span>
                        </div>`;
                    }).join('')}
                </div>
            </div>` : '';

        const deps = collectDeps(task, ctx);
        const depsHtml = deps.length > 0 ? `
            <div class="tt-deps">
                <div class="tt-key">${escText(L.dependsOn)}</div>
                <div class="tt-dep-list">${deps.map(escText).join(' · ')}</div>
            </div>` : '';

        return `
            <div class="tt-head">${chips}</div>
            <div class="tt-name">${escText(task.name || '')}</div>
            <div class="tt-grid">
                <div class="tt-cell"><div class="tt-key">${escText(L.start)}</div><div class="tt-val">${escText(startLbl)}</div></div>
                <div class="tt-cell"><div class="tt-key">${escText(L.end)}</div><div class="tt-val">${escText(endLbl)}</div></div>
                <div class="tt-cell"><div class="tt-key">${escText(L.duration)}</div><div class="tt-val">${dur} ${escText(dur === 1 ? L.day : L.days)}</div></div>
                <div class="tt-cell"><div class="tt-key">${escText(L.progress)}</div>
                    <div class="tt-val">
                        <div class="tt-progress"><div class="tt-progress-fill" style="width:${Math.round(prog * 100)}%;background:${c.bar}"></div></div>
                        <span>${Math.round(prog * 100)}%</span>
                    </div>
                </div>
            </div>
            ${assigneesHtml}
            ${depsHtml}`;
    }

    function clamp01(v) {
        // progress interno é fração 0..1 (normalizado no ingest) — clamp puro.
        if (v == null) return 0;
        const n = +v;
        if (!Number.isFinite(n)) return 0;
        return Math.max(0, Math.min(1, n));
    }

    function collectAssignees(t) {
        if (Array.isArray(t.assignees)) return t.assignees.map(String);
        if (Array.isArray(t.owners))    return t.owners.map(String);
        if (t.owner) return String(t.owner).split(/[,;]\s*/).filter(Boolean);
        return [];
    }

    function collectDeps(t, ctx) {
        if (Array.isArray(t.deps) && t.deps.length) return t.deps.map(String);
        if (ctx && ctx.dependencies) {
            return ctx.dependencies
                .filter((d) => String(d.to) === String(t.id))
                .map((d) => String(d.from));
        }
        return [];
    }

    function initialsOf(name) {
        const s = String(name || '').trim();
        if (!s) return '?';
        const parts = s.split(/\s+/);
        return ((parts[0][0] || '') + (parts[1] ? parts[1][0] : '')).toUpperCase().slice(0, 2);
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.tooltip = {
        show, hide,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Workload band.
 *
 * Aggregates assignments by day or task-count and renders a stacked
 * column chart aligned with the chart's pxPerDay scale. No chart.js
 * dependency — pure SVG.
 */
(function (root) {
    'use strict';

    const { dates, zoom, colors, svg } = root.MadGantt;
    const { addDays, fmtISO } = dates;
    const { createNS, clear } = svg;

    function render(rootEl, ctx) {
        if (!rootEl) return;
        const profile = zoom.get(ctx.zoom);
        const pxPerDay = profile.pxPerDay;
        const totalW = ctx.range.totalDays * pxPerDay;
        const h = 96;
        const dark = ctx.theme === 'dark';
        const mode = ctx.workloadMode || 'hours';

        const series = aggregate(ctx, mode);
        // Redução sem spread — (dias × recursos) grandes estouravam o limite
        // de argumentos do spread (RangeError) e derrubavam o workload.
        let maxDay = 1;
        for (const v of Object.values(series)) {
            let tot = 0;
            for (const n of Object.values(v)) tot += n;
            if (tot > maxDay) maxDay = tot;
        }

        // Capacidade agregada (h/dia) — referência visual no modo hours.
        const resources = ctx.resources || [];
        const capacity = mode === 'hours'
            ? resources.reduce((s2, r) => s2 + (Number(r.capacity) || 0), 0)
            : 0;

        // Escala com headroom: pico OU capacidade (o que for maior) + 15% —
        // sem isso o dia de pico colava no teto e tudo parecia "cheio".
        const scaleMax = Math.max(maxDay, capacity || 0) * 1.15;
        const plotH = h - 14; // faixa útil (deixa ar no topo)

        clear(rootEl);
        const wrap = document.createElement('div');
        wrap.className = 'mad-gantt-workload-inner';
        wrap.style.width = `${totalW}px`;
        wrap.style.height = `${h}px`;
        wrap.style.position = 'relative';

        const s = createNS('svg', { width: String(totalW), height: String(h) });
        s.style.display = 'block';

        // Baseline
        s.appendChild(createNS('line', {
            x1: 0, y1: h - 1, x2: totalW, y2: h - 1,
            stroke: dark ? 'oklch(0.34 0.018 255)' : 'oklch(0.85 0.008 250)',
            'stroke-width': '1',
        }));

        const colorOf = {};
        resources.forEach((r, i) => {
            colorOf[String(r.id)] = colors.phaseColors(colors.assignAutoHue(i), dark).bar;
        });
        const critColor = dark ? 'oklch(0.72 0.22 25)' : 'oklch(0.55 0.22 25)';
        const nameOf = {};
        resources.forEach((r) => { nameOf[String(r.id)] = r.name || r.id; });

        for (let i = 0; i < ctx.range.totalDays; i++) {
            const d = addDays(ctx.range.start, i);
            const key = fmtISO(d);
            const dayValues = series[key] || {};
            const x = i * pxPerDay;
            let acc = 0;
            let dayTotal = 0;
            for (const n of Object.values(dayValues)) dayTotal += n;
            const overloaded = capacity > 0 && dayTotal > capacity + 1e-9;
            const parts = [];
            for (const rid of Object.keys(dayValues)) {
                const v = dayValues[rid];
                const barH = (v / scaleMax) * plotH;
                if (barH <= 0) continue;
                parts.push(`${nameOf[rid] || rid}: ${Math.round(v * 10) / 10}`);
                const rect = createNS('rect', {
                    class: 'mad-gantt-workload-bar',
                    x: x + 1, y: h - 1 - acc - barH,
                    width: Math.max(1, pxPerDay - 2),
                    height: barH,
                    fill: colorOf[rid] || (dark ? '#60a5fa' : '#3b82f6'),
                    opacity: '0.85',
                    rx: 1,
                });
                if (overloaded) rect.setAttribute('stroke', critColor);
                s.appendChild(rect);
                acc += barH;
            }
            if (parts.length) {
                const title = createNS('title');
                const unit = mode === 'hours' ? 'h' : '';
                title.textContent = `${key} — ${Math.round(dayTotal * 10) / 10}${unit}`
                    + (capacity ? ` / ${capacity}${unit}` : '')
                    + `\n${parts.join('\n')}`;
                const hit = createNS('rect', {
                    x, y: 0, width: pxPerDay, height: h,
                    fill: 'transparent',
                });
                hit.appendChild(title);
                s.appendChild(hit);
            }
        }

        // Linha de capacidade agregada (tracejada) — só no modo horas.
        if (capacity > 0) {
            const capY = h - 1 - (capacity / scaleMax) * plotH;
            s.appendChild(createNS('line', {
                class: 'mad-gantt-workload-cap',
                x1: 0, y1: capY, x2: totalW, y2: capY,
                stroke: critColor,
                'stroke-width': '1',
                'stroke-dasharray': '4 3',
                opacity: '0.7',
            }));
        }

        wrap.appendChild(s);
        rootEl.appendChild(wrap);
    }

    function aggregate(ctx, mode) {
        const out = {};
        const assignments = ctx.assignments || [];
        const tasksById = {};
        for (const t of (ctx.tasks || [])) tasksById[String(t.id)] = t;

        for (const a of assignments) {
            const t = tasksById[String(a.task_id || a.taskId)];
            if (!t) continue;
            const start = dates.parseDate(t.start);
            const end   = dates.parseDate(t.end) || start;
            if (!start) continue;
            const days = Math.max(1, dates.daysBetween(start, end) + 1);
            const hoursPerDay = mode === 'tasks' ? 1 : ((a.hours || 8) / days);
            const rid = String(a.resource_id || a.resourceId || 'unknown');
            for (let i = 0; i < days; i++) {
                const d = dates.addDays(start, i);
                const key = dates.fmtISO(d);
                (out[key] = out[key] || {});
                out[key][rid] = (out[key][rid] || 0) + hoursPerDay;
            }
        }
        return out;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.workload = {
        render,
        aggregate,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Scroll synchronisation.
 *
 * Wires three scroll containers:
 *   chartScroll  (the truth) — horizontal + vertical
 *   headerScroll — mirrors chartScroll.scrollLeft
 *   tasklist     — mirrors chartScroll.scrollTop
 *
 * RAF-throttled to keep 60fps under heavy datasets.
 */
(function (root) {
    'use strict';

    function attach(ctx) {
        const chartEl    = ctx.chartScroll;
        const headerEl   = ctx.headerScroll;
        const tasklistEl = ctx.tasklist;
        // Banda de carga espelha o scrollLeft. Getter lazy: o container vive
        // dentro de <template x-if="showWorkload"> e pode montar depois.
        const workloadEl = () => (ctx.getWorkloadScroll ? ctx.getWorkloadScroll() : null);
        if (!chartEl) return null;

        let raf = 0;
        let lastLeft = -1, lastTop = -1;
        let suspendUntil = 0;

        const onChartScroll = () => {
            if (raf) return;
            raf = requestAnimationFrame(() => {
                raf = 0;
                const left = chartEl.scrollLeft;
                const top  = chartEl.scrollTop;
                if (Date.now() < suspendUntil) return;
                if (left !== lastLeft) {
                    lastLeft = left;
                    if (headerEl && headerEl.scrollLeft !== left) headerEl.scrollLeft = left;
                    const wl = workloadEl();
                    if (wl && wl.scrollLeft !== left) wl.scrollLeft = left;
                }
                if (top !== lastTop) {
                    lastTop = top;
                    if (tasklistEl && tasklistEl.scrollTop !== top) tasklistEl.scrollTop = top;
                }
                if (ctx.onViewport) ctx.onViewport(left, chartEl.clientWidth);
            });
        };

        const onTasklistScroll = () => {
            const top = tasklistEl.scrollTop;
            if (chartEl.scrollTop !== top) {
                suspendUntil = Date.now() + 50;
                chartEl.scrollTop = top;
            }
        };

        chartEl.addEventListener('scroll', onChartScroll, { passive: true });
        if (tasklistEl) tasklistEl.addEventListener('scroll', onTasklistScroll, { passive: true });

        // Sync once on attach (covers initial scroll-to-today)
        onChartScroll();

        return {
            detach() {
                chartEl.removeEventListener('scroll', onChartScroll);
                if (tasklistEl) tasklistEl.removeEventListener('scroll', onTasklistScroll);
                if (raf) cancelAnimationFrame(raf);
            },
            sync: onChartScroll,
        };
    }

    function jumpToDay(chartEl, dayOffset, pxPerDay) {
        if (!chartEl) return;
        const x = Math.max(0, dayOffset * pxPerDay);
        chartEl.scrollLeft = x;
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.scrollSync = {
        attach,
        jumpToDay,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Bar drag-to-move / drag-to-resize.
 *
 * Pointer-based, snaps to pxPerDay. Live-patches the bar's SVG
 * attributes during drag (no full re-render). On drop, calls back
 * into the Alpine component to commit + fire MadWire.
 */
(function (root) {
    'use strict';

    const { dates, zoom, coords, chartSvg } = root.MadGantt;
    const { addDays, fmtISO, fmtDateTime, parseDate } = dates;

    const HOUR_MS = 3600000;

    function attach(svgEl, ctx) {
        if (!svgEl) return null;

        let state = null;

        const onPointerDown = (e) => {
            // Shift = criar dependencia (drag-dependency cuida). Nao mover barra.
            if (e.shiftKey) return;
            // Ancora de dependencia (bolinha) = drag-dependency cuida.
            if (e.target.closest('[data-dep-anchor]')) return;
            // So botao esquerdo
            if (e.button !== undefined && e.button !== 0) return;
            const target = e.target.closest('[data-drag-mode]');
            if (!target) return;
            const group = target.closest('[data-task-id]');
            if (!group) return;
            const mode = target.getAttribute('data-drag-mode');
            const taskId = group.getAttribute('data-task-id');
            const task = ctx.getTask(taskId);
            if (!task) return;
            if (task.readonly || ctx.readonly) return;
            e.preventDefault();
            e.stopPropagation();
            const zk = ctx.getZoom();
            state = {
                taskId,
                mode,
                startX: e.clientX,
                origStart: parseDate(task.start),
                origEnd:   parseDate(task.end) || parseDate(task.start),
                lastStep:  0,
                pxPerDay:  zoom.get(zk).pxPerDay,
                zoomKey:   zk,
                hourMode:  zk === 'hour',
            };
            try { target.setPointerCapture(e.pointerId); } catch (_) {}
            svgEl.classList.add('is-dragging');
        };

        const onPointerMove = (e) => {
            if (!state) return;
            const dx = e.clientX - state.startX;

            // Snap por hora no zoom hour, por dia no resto.
            const pxPerUnit = state.hourMode
                ? state.pxPerDay / 24
                : state.pxPerDay;
            const dStep = Math.round(dx / pxPerUnit);
            if (dStep === state.lastStep) return;
            state.lastStep = dStep;

            const shift = (d, units) => state.hourMode
                ? new Date(d.getTime() + units * HOUR_MS)
                : addDays(d, units);

            const geom = ctx.getGeom();
            const baseStart = state.origStart;
            const baseEnd   = state.origEnd;
            let newStart = baseStart;
            let newEnd   = baseEnd;
            if (state.mode === 'move')  { newStart = shift(baseStart, dStep); newEnd = shift(baseEnd, dStep); }
            else if (state.mode === 'left')  { newStart = shift(baseStart, dStep); }
            else if (state.mode === 'right') { newEnd   = shift(baseEnd,   dStep); }

            // Date-only: start==end é task de 1 dia válida (span mínimo 0);
            // clamp de 1 dia aqui empurrava o start 1 dia pra trás no drag.
            const minSpan = state.hourMode ? HOUR_MS : 0;
            if (newEnd.getTime() - newStart.getTime() < minSpan) {
                if (state.mode === 'right') newEnd   = new Date(newStart.getTime() + minSpan);
                else                         newStart = new Date(newEnd.getTime()   - minSpan);
            }

            const range = ctx.getRange();
            const sFloat = root.MadGantt.coords.dayOffsetFloat(range.start, newStart);
            // Date-only: end é inclusivo (+1 dia), como no buildGeom — sem isso
            // a barra encolhia 1 dia ao iniciar o drag.
            const eFloat = root.MadGantt.coords.dayOffsetFloat(range.start, newEnd)
                + (state.hourMode ? 0 : 1);
            const g = geom.byId[state.taskId];
            if (g) {
                g.x = sFloat * state.pxPerDay;
                g.w = Math.max(2, (eFloat - sFloat) * state.pxPerDay);
                chartSvg.patchBar(svgEl, state.taskId, g, { zoom: ctx.getZoom() });
            }
            ctx.onDragTick && ctx.onDragTick(state.taskId, newStart, newEnd, state.mode);
        };

        const onPointerUp = (e) => {
            if (!state) return;
            svgEl.classList.remove('is-dragging');
            const { taskId, origStart, origEnd, lastStep, mode, hourMode } = state;
            state = null;
            if (lastStep === 0) {
                // Gesto voltou ao ponto de origem — restaura o visual/estado
                // que os ticks intermediários já mutaram (sem round-trip).
                ctx.onDragTick && ctx.onDragTick(taskId, origStart, origEnd, mode);
                return;
            }

            // Drag moved the bar — suppress the synthesized `click` that the
            // browser fires right after pointerup. Otherwise downstream
            // listeners (e.g. svg click → onTaskClick → drawer) treat a drag
            // as a click.
            const swallowClick = (ev) => {
                ev.stopPropagation();
                ev.stopImmediatePropagation();
                ev.preventDefault();
            };
            svgEl.addEventListener('click', swallowClick, { capture: true, once: true });
            // Safety net: if no click fires within 350ms, remove the listener.
            setTimeout(() => svgEl.removeEventListener('click', swallowClick, { capture: true }), 350);

            const task = ctx.getTask(taskId);
            if (!task) return;
            const shift = (d, units) => hourMode
                ? new Date(d.getTime() + units * HOUR_MS)
                : addDays(d, units);
            let newStart = origStart;
            let newEnd   = origEnd;
            if (mode === 'move')  { newStart = shift(origStart, lastStep); newEnd = shift(origEnd, lastStep); }
            if (mode === 'left')  { newStart = shift(origStart, lastStep); }
            if (mode === 'right') { newEnd   = shift(origEnd,   lastStep); }
            if (newEnd < newStart) [newStart, newEnd] = [newEnd, newStart];
            const fmt = hourMode ? fmtDateTime : fmtISO;
            ctx.onDragCommit && ctx.onDragCommit(taskId, fmt(newStart), fmt(newEnd), mode);
        };

        svgEl.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerUp);

        return {
            detach() {
                svgEl.removeEventListener('pointerdown', onPointerDown);
                window.removeEventListener('pointermove', onPointerMove);
                window.removeEventListener('pointerup', onPointerUp);
                window.removeEventListener('pointercancel', onPointerUp);
            },
        };
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.dragBar = {
        attach,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Drag-to-create dependency.
 *
 * Dois gestos:
 *   1. Arrastar de uma ANCORA (bolinha nas pontas da barra, visivel no
 *      hover) ate outra barra — sem tecla. Ancora 'end' = origem (from),
 *      'start' = destino (to).
 *   2. Fallback: Shift + arrastar do corpo da barra ate outra.
 *
 * Ghost path segue o ponteiro. Drop valido cria dependencia FS;
 * deteccao de ciclo rejeita drops invalidos.
 */
(function (root) {
    'use strict';

    const { svg, criticalPath } = root.MadGantt;
    const { createNS } = svg;

    function attach(svgEl, ctx) {
        if (!svgEl) return null;

        let state = null;
        let ghost = null;

        const onPointerDown = (e) => {
            if (e.button !== undefined && e.button !== 0) return;

            // Gesto 1: ancora (bolinha) — preferencial, sem tecla.
            const anchor = e.target.closest('[data-dep-anchor]');
            let originId = null;
            let anchorSide = 'end';

            if (anchor) {
                originId   = anchor.getAttribute('data-dep-task');
                anchorSide = anchor.getAttribute('data-dep-anchor'); // start | end
            } else if (e.shiftKey) {
                // Gesto 2: shift + corpo da barra
                const group = e.target.closest('[data-task-id]');
                if (!group) return;
                originId   = group.getAttribute('data-task-id');
                anchorSide = 'end';
            } else {
                return;
            }
            if (!originId) return;

            const task = ctx.getTask(originId);
            if (!task) return;
            e.preventDefault();
            e.stopPropagation();

            const geom = ctx.getGeom();
            const g = geom.byId[originId];
            if (!g) return;
            // Origem do ghost: ponta da ancora clicada
            const sx = anchorSide === 'start' ? g.x : g.x + g.w;
            const sy = g.y + g.h / 2;
            state = { originId, anchorSide, sx, sy };

            ghost = createNS('path', {
                class: 'mad-gantt-arrow-ghost',
                d: `M ${sx} ${sy} L ${sx} ${sy}`,
                fill: 'none',
            });
            const layer = ctx.getDepsLayer();
            if (layer) layer.appendChild(ghost);
            svgEl.classList.add('mg-linking');
        };

        const onPointerMove = (e) => {
            if (!state || !ghost) return;
            const pt = clientToSvg(svgEl, e.clientX, e.clientY);
            const { sx, sy } = state;
            const tx = pt.x, ty = pt.y;
            const midX = (sx + tx) / 2;
            ghost.setAttribute('d', `M ${sx} ${sy} L ${midX} ${sy} L ${midX} ${ty} L ${tx} ${ty}`);
        };

        const onPointerUp = (e) => {
            if (!state) return;
            svgEl.classList.remove('mg-linking');
            const target = document.elementFromPoint(e.clientX, e.clientY);
            const targetGroup = target && target.closest && target.closest('[data-task-id]');
            const { originId, anchorSide } = state;
            state = null;
            if (ghost && ghost.parentNode) ghost.parentNode.removeChild(ghost);
            ghost = null;
            if (!targetGroup) return;

            const dropId = targetGroup.getAttribute('data-task-id');
            if (!dropId || dropId === originId) return;

            // Ancora 'end' = origem e predecessor (from). 'start' = origem
            // e sucessor (to), entao o drop vira o predecessor.
            const fromId = anchorSide === 'start' ? dropId : originId;
            const toId   = anchorSide === 'start' ? originId : dropId;

            const deps = ctx.getDependencies();
            const existing = deps.some((d) => String(d.from) === String(fromId) && String(d.to) === String(toId));
            if (existing) return;
            if (criticalPath.wouldCycle(ctx.getTasks(), deps, fromId, toId)) {
                ctx.onCycleRejected && ctx.onCycleRejected(fromId, toId);
                return;
            }
            ctx.onDependencyCreate && ctx.onDependencyCreate(fromId, toId, 'FS', 0);
        };

        svgEl.addEventListener('pointerdown', onPointerDown);
        window.addEventListener('pointermove', onPointerMove);
        window.addEventListener('pointerup', onPointerUp);
        window.addEventListener('pointercancel', onPointerUp);

        return {
            detach() {
                svgEl.removeEventListener('pointerdown', onPointerDown);
                window.removeEventListener('pointermove', onPointerMove);
                window.removeEventListener('pointerup', onPointerUp);
                window.removeEventListener('pointercancel', onPointerUp);
            },
        };
    }

    function clientToSvg(svgEl, clientX, clientY) {
        // O rect do SVG (filho direto do scroller) JÁ desloca com o scroll —
        // somar scrollLeft/Top de novo contava o scroll 2× e deslocava o ghost.
        const rect = svgEl.getBoundingClientRect();
        return { x: clientX - rect.left, y: clientY - rect.top };
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.dragDependency = {
        attach,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Hover wiring.
 *
 * Single delegated set of listeners on the chart SVG that resolves the
 * underlying task and shows/hides the shared tooltip.
 */
(function (root) {
    'use strict';

    const { tooltip } = root.MadGantt;

    function attach(svgEl, ctx) {
        if (!svgEl) return null;

        let hoverTaskId = null;
        let hideTimer = 0;

        const onMove = (e) => {
            // Sem tooltip durante drag — re-render de innerHTML por RAF em
            // cima do gesto só atrapalha (e o dado mostrado estaria stale).
            if (svgEl.classList.contains('is-dragging')) {
                scheduleHide();
                return;
            }
            const target = e.target.closest('[data-task-id]');
            if (!target) {
                scheduleHide();
                return;
            }
            const taskId = target.getAttribute('data-task-id');
            const task = ctx.getTask(taskId);
            if (!task) return;
            if (hoverTaskId !== taskId) hoverTaskId = taskId;
            if (hideTimer) { clearTimeout(hideTimer); hideTimer = 0; }
            tooltip.show(task, { x: e.clientX, y: e.clientY }, ctx.tooltipCtx());
        };

        const onLeave = () => scheduleHide();

        function scheduleHide() {
            if (hideTimer) return;
            hideTimer = setTimeout(() => {
                hoverTaskId = null;
                tooltip.hide();
                hideTimer = 0;
            }, 80);
        }

        svgEl.addEventListener('pointermove', onMove);
        svgEl.addEventListener('pointerleave', onLeave);

        return {
            detach() {
                svgEl.removeEventListener('pointermove', onMove);
                svgEl.removeEventListener('pointerleave', onLeave);
                if (hideTimer) clearTimeout(hideTimer);
                tooltip.hide();
            },
        };
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.hover = {
        attach,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Keyboard shortcuts.
 *
 *   Esc            clear selection / hide tooltip
 *   ⌘K / Ctrl+K    focus search
 *   ←/→           horizontal scroll (1 day at zoom=day, 7 at week, etc.)
 *   Home / End     jump to start / end of range
 *   T              today
 */
(function (root) {
    'use strict';

    const { tooltip, zoom, scrollSync } = root.MadGantt;

    function attach(rootEl, ctx) {
        if (!rootEl) return null;

        const onKeydown = (e) => {
            if (e.target && e.target.matches('input, textarea, [contenteditable]')) {
                if ((e.key === 'k' || e.key === 'K') && (e.ctrlKey || e.metaKey)) {
                    // allow ⌘K to refocus regardless
                } else return;
            }
            const meta = e.ctrlKey || e.metaKey;
            if (e.key === 'Escape') {
                tooltip.hide();
                ctx.clearSelection && ctx.clearSelection();
                return;
            }
            if ((e.key === 'k' || e.key === 'K') && meta) {
                e.preventDefault();
                ctx.focusSearch && ctx.focusSearch();
                return;
            }
            if (e.key === 't' || e.key === 'T') {
                if (!meta) {
                    ctx.goToday && ctx.goToday();
                    return;
                }
            }
            const chart = ctx.getChartScroll && ctx.getChartScroll();
            if (!chart) return;
            const pxPerDay = zoom.get(ctx.getZoom()).pxPerDay;
            const step = e.shiftKey ? pxPerDay * 30 : pxPerDay * 7;
            if (e.key === 'ArrowLeft')  { chart.scrollLeft -= step; e.preventDefault(); }
            if (e.key === 'ArrowRight') { chart.scrollLeft += step; e.preventDefault(); }
            if (e.key === 'Home')       { chart.scrollLeft = 0; e.preventDefault(); }
            if (e.key === 'End')        { chart.scrollLeft = chart.scrollWidth; e.preventDefault(); }
        };

        rootEl.addEventListener('keydown', onKeydown);
        if (!rootEl.hasAttribute('tabindex')) rootEl.setAttribute('tabindex', '0');

        return {
            detach() {
                rootEl.removeEventListener('keydown', onKeydown);
            },
        };
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.keyboard = {
        attach,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — MadWire bridge.
 *
 * Wraps every server-side dispatch in one place. Callers pass the
 * Alpine component (`this`) plus a payload. If MadWire isn't loaded
 * we no-op silently (useful for static demos).
 */
(function (root) {
    'use strict';

    // `onceKey`: while a call with the same key is pending on this component,
    // repeats are dropped (MadWire.callOnce). Clicks that open a form use it —
    // a double click used to open the form twice, one drawer over the other.
    function call(componentEl, method, payload, onceKey) {
        if (!method) return Promise.resolve(null);
        // MadWire is declared `const` at script scope in mad-livewire.js, so
        // it lives in the script-realm globals (NOT on window). Probe both
        // locations to stay compatible across loaders.
        let MW = null;
        try { MW = (typeof MadWire !== 'undefined') ? MadWire : null; } catch (_) {}
        if (!MW && root.MadWire) MW = root.MadWire;
        if (MW && typeof MW.call === 'function') {
            try {
                // MadWire.call expects the element to be (or contain) the
                // [mad-component] wrapper. Walk up if needed.
                const wrapper = (componentEl && componentEl.closest && componentEl.closest('[mad-component]')) || componentEl;
                if (onceKey && typeof MW.callOnce === 'function') {
                    return Promise.resolve(MW.callOnce(wrapper, method, payload, onceKey));
                }
                return Promise.resolve(MW.call(wrapper, method, payload));
            } catch (e) { console.warn('[MadGantt] MadWire.call failed', e); }
        }
        // Fallback: Alpine's $wire (if hosted inside livewire-style adapter)
        if (componentEl && componentEl._wire && typeof componentEl._wire.call === 'function') {
            try { return Promise.resolve(componentEl._wire.call(method, payload)); }
            catch (e) { console.warn('[MadGantt] $wire.call failed', e); }
        }
        // Last resort: dispatch a custom DOM event so external code can react
        if (componentEl && typeof CustomEvent === 'function') {
            componentEl.dispatchEvent(new CustomEvent('mad-gantt:wire', {
                bubbles: true,
                detail: { method, payload },
            }));
        }
        return Promise.resolve(null);
    }

    function fireTaskClick(el, method, taskId, extra)   { return call(el, method, { task_id: taskId, ...extra }, 'open'); }
    function fireTaskUpdate(el, method, taskId, start, end, mode, extra) {
        return call(el, method, { task_id: taskId, start, end, mode, ...extra });
    }
    function fireDependencyCreate(el, method, from, to, type, lag) {
        return call(el, method, { from, to, type: type || 'FS', lag: lag || 0 });
    }
    function fireDependencyDelete(el, method, from, to) {
        return call(el, method, { from, to });
    }
    function fireReload(el, method, start, end) {
        return call(el, method, { start, end });
    }
    function fireDayClick(el, method, date, rowId) {
        return call(el, method, { date, row_id: rowId }, 'open');
    }
    function fireHeaderAction(el, method, name) {
        return call(el, method, { name });
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.wire = {
        call,
        fireTaskClick,
        fireTaskUpdate,
        fireDependencyCreate,
        fireDependencyDelete,
        fireReload,
        fireDayClick,
        fireHeaderAction,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — sessionStorage-backed UI preferences.
 *
 * Persists per-gantt-id: zoom, viewMode, expanded tasks, collapsed phases,
 * sidebar width. Storage is best-effort; quota or privacy mode failures
 * are swallowed.
 */
(function (root) {
    'use strict';

    const KEY = (id) => `mad-gantt:${id || 'default'}`;

    function load(id) {
        try {
            const raw = sessionStorage.getItem(KEY(id));
            if (!raw) return {};
            return JSON.parse(raw);
        } catch (e) {
            return {};
        }
    }

    function save(id, patch) {
        try {
            const cur = load(id);
            const merged = { ...cur, ...patch };
            sessionStorage.setItem(KEY(id), JSON.stringify(merged));
        } catch (e) {
            // ignore quota / disabled storage
        }
    }

    function clear(id) {
        try { sessionStorage.removeItem(KEY(id)); } catch (e) {}
    }

    root.MadGantt = root.MadGantt || {};
    root.MadGantt.persistence = {
        load, save, clear,
    };

})(typeof window !== 'undefined' ? window : globalThis);
/**
 * MadGantt — Alpine component.
 *
 * Orchestrates all modules. Registers `Alpine.data('madGantt', ...)`.
 * Preserves the public API consumed by the current Blade view + by
 * MadWire callbacks.
 */
(function (root) {
    'use strict';

    function init() {
        if (!root.Alpine) {
            console.warn('[MadGantt] Alpine.js not loaded; component will not register.');
            return;
        }
        if (root.Alpine.__madGanttRegistered) return;
        root.Alpine.__madGanttRegistered = true;

        const M = root.MadGantt;
        const escText = M.svg.escText;
        const {
            dates, zoom, colors,
            coords, tree, criticalPath: cpm, calendar,
            defs, header, sidebar, chartSvg, dependencies, minimap, tooltip, workload,
            scrollSync, dragBar, dragDependency, hover, keyboard,
            wire, persistence,
        } = M;

        root.Alpine.data('madGantt', (cfg = {}) => ({

            // ── PUBLIC reactive state ───────────────────────────────────────
            zoom:               zoom.normalize(cfg.zoom || 'day'),
            viewMode:           cfg.viewMode || 'days',
            startDate:          cfg.startDate || '',
            endDate:            cfg.endDate || '',
            title:              cfg.title || '',
            locale:             cfg.locale || 'pt-br',
            tasks:              normalizeTasks(cfg.tasks || cfg.events || [], cfg.progressScale),
            progressScale:      cfg.progressScale || 'auto',
            dependencies:       cfg.dependencies || [],
            phases:             cfg.phases || [],
            phaseField:         cfg.phaseField || null,
            people:             cfg.people || {},
            resources:          cfg.resources || [],
            assignments:        cfg.assignments || [],
            workingDays:        cfg.workingDays || [1, 2, 3, 4, 5],
            holidays:           cfg.holidays || [],
            workingHours:       cfg.workingHours || [0, 24],
            criticalPath:       !!cfg.criticalPath,
            criticalMode:       !!cfg.criticalMode,
            showWorkload:       !!cfg.showWorkload,
            workloadMode:       cfg.workloadMode || 'hours',
            workloadModeActive: (cfg.workloadMode === 'toggle' ? 'hours' : (cfg.workloadMode || 'hours')),
            arrowStyle:         cfg.arrowStyle || 'elbow',
            density:            cfg.density || 'comfortable',
            // Largura da sidebar: quando ha colunas declaradas, e a soma das
            // larguras das colunas visiveis (cada uma renderiza na largura dada);
            // senao, o task-col-width explicito (default 300).
            taskColWidth: (function () {
                const vis = (cfg.columns || []).filter((c) => c && !c.hidden && c.field);
                if (vis.length) return vis.reduce((s, c) => s + (parseInt(c.width, 10) || 120), 0);
                return cfg.taskColWidth || 300;
            })(),
            showMinimap:        cfg.showMinimap !== false,
            showSearch:         cfg.showSearch !== false,
            showWeekends:       cfg.showWeekends !== false,
            showGrid:           cfg.showGrid !== false,
            showAvatars:        cfg.showAvatars !== false,
            showViewModeButton: !!cfg.showViewModeButton,
            showZoomButton:     !!cfg.showZoomButton,
            enableInlineEdit:   !!cfg.enableInlineEdit,
            enableMultiSelect:  !!cfg.enableMultiSelect,
            headerActions:      cfg.headerActions || [],
            filterText:         '',
            expanded:           {},
            collapsedPhases:    {},
            // Filtro de fases: null = todas | { phaseId: true } = somente essas
            phaseFilterMap:     null,
            phaseFilterOpen:    false,
            selectedTaskIds:    {},
            theme:              detectTheme(),

            // legacy fields (kept so existing demos that read them don't break)
            rows: cfg.rows || [],
            events: cfg.events || [],
            interval: cfg.interval || '30 days',
            dates: cfg.dates || [],
            hours: cfg.hours || ['00', '06', '12', '18'],
            minutesStep: cfg.minutesStep || 1440,
            striped: !!cfg.striped,
            stripedRows: !!cfg.stripedRows,
            compactEvents: !!cfg.compactEvents,
            treeMode: cfg.treeMode || hasTreeShape(cfg.tasks),
            columns: cfg.columns || [],
            baselines: cfg.baselines || [],
            autoSchedule: !!cfg.autoSchedule,
            criticalIds: {},
            criticalSet: new Set(),

            // Dropdown / popover state (used by Blade)
            viewModeOpen: false,
            zoomOpen: false,
            popVisible: false,
            popX: 0, popY: 0,
            popTitleText: '',
            popContentText: '',

            _ganttId: cfg.id || null,
            _today: new Date(),
            _range: null,
            _rows:  [],
            _geom:  { byId: {}, totalHeight: 0 },
            _bindings: [],
            _rafFlush: 0,
            _dirty: { all: true },
            _themeMo: null,

            // ── Lifecycle ───────────────────────────────────────────────────
            init() {
                // Chave de persistência ESTÁVEL entre reloads: cfg.id explícito
                // ou rota+posição na página. O id DOM ($uid do blade) é único
                // por render — usá-lo fazia zoom/expanded nunca restaurarem e
                // acumulava chaves órfãs na sessionStorage.
                if (!this._ganttId) {
                    const idx = Array.prototype.indexOf.call(document.querySelectorAll('.mad-gantt'), this.$el);
                    this._ganttId = `mad-gantt:${location.pathname}#${idx >= 0 ? idx : 0}`;
                }
                if (!this.$el.id) this.$el.id = `mad-gantt-${Math.random().toString(36).slice(2, 9)}`;
                const prefs = persistence.load(this._ganttId);
                if (prefs.zoom)            this.zoom = zoom.normalize(prefs.zoom);
                if (prefs.viewMode)        this.viewMode = prefs.viewMode;
                if (prefs.expanded)        this.expanded = prefs.expanded;
                if (prefs.collapsedPhases) this.collapsedPhases = prefs.collapsedPhases;
                if (prefs.phaseFilterMap !== undefined) this.phaseFilterMap = prefs.phaseFilterMap;

                // If no explicit phases given but phaseField is set, derive them
                if (this.phaseField && (!this.phases || this.phases.length === 0)) {
                    this.phases = tree.derivePhases(this.tasks, this.phaseField);
                }

                // Tasks sem fase ganham entrada "Sem fase" no menu de filtro —
                // sem ela, qualquer filtro ativo as escondia sem como religar.
                if (this.phaseField && this.phases.length > 0) {
                    const pf = this.phaseField;
                    const hasUnphased = this.tasks.some((t) => t[pf] == null || t[pf] === '');
                    const hasEntry = this.phases.some((p) => String(p.id) === '__none__');
                    if (hasUnphased && !hasEntry) {
                        this.phases = [...this.phases, {
                            id: '__none__',
                            name: (cfg.labels && cfg.labels.noPhase) || '— Sem fase —',
                            code: '',
                            hue: 250,
                        }];
                    }
                }

                // Baselines de addBaseline() → props flat que o renderer lê
                // (task.baselineStart/End). Antes o array só era armazenado.
                for (const b of (cfg.baselines || [])) {
                    if (!b || !b.start || !b.end) continue;
                    const bid = String(b.taskId || b.task_id || '');
                    const t = this.tasks.find((x) => x.id === bid);
                    if (t) { t.baselineStart = b.start; t.baselineEnd = b.end; }
                }

                this._observeTheme();
                this._markDirty('all');
                this.$nextTick(() => {
                    this._setup();
                    this._flush();
                    this._scrollToToday();
                });
            },

            destroy() {
                if (this._rafFlush) { cancelAnimationFrame(this._rafFlush); this._rafFlush = 0; }
                for (const b of this._bindings) { try { b.detach && b.detach(); } catch (_) {} }
                if (this._themeMo) this._themeMo.disconnect();
                tooltip.hide();
            },

            // ── Setup (event bindings) ─────────────────────────────────────
            _setup() {
                const chartScroll = this.$refs.chartScroll;
                const headerScroll = this.$refs.headerScroll;
                const tasklist = this.$refs.tasklist;
                const svgEl = this.$refs.chartSvg;
                const mmEl  = this.$refs.minimap;
                const tasklistEl = tasklist;

                if (chartScroll) {
                    this._bindings.push(scrollSync.attach({
                        chartScroll, headerScroll, tasklist,
                        getWorkloadScroll: () => this.$refs.workloadScroll
                            || this.$el.querySelector('.mad-gantt-workload-scroll'),
                        onViewport: (left, width) => {
                            const pxPerDay = zoom.get(this.zoom).pxPerDay;
                            this._viewportStart = left / pxPerDay;
                            this._viewportDays  = width / pxPerDay;
                            this._markDirty('minimap');
                            this._scheduleFlush();
                        },
                    }));
                }
                if (svgEl) {
                    this._bindings.push(dragBar.attach(svgEl, {
                        // Sem handler de update não há o que persistir: drag
                        // vira no-op silencioso que se perde no reload — então
                        // fail-closed pra readonly.
                        readonly: !(cfg.taskUpdateMethod || cfg.eventUpdateMethod),
                        getTask: (id) => this._getTask(id),
                        getZoom: () => this.zoom,
                        getGeom: () => this._geom,
                        getRange: () => this._range,
                        onDragTick: (id, start, end /*, mode */) => {
                            // Visual already patched by drag-bar.js
                            // Update task in memory so subsequent renders match.
                            // Zoom hora preserva o horário — fmtISO date-only
                            // aqui apagava a hora da task só de encostar nela.
                            const t = this._getTask(id);
                            const fmt = this.zoom === 'hour' ? dates.fmtDateTime : dates.fmtISO;
                            if (t) { t.start = fmt(start); t.end = fmt(end); }
                        },
                        onDragCommit: (id, start, end, mode) => {
                            this._markDirty('chart'); this._markDirty('deps');
                            if (this.criticalPath) this._recomputeCritical();
                            this._scheduleFlush();
                            wire.fireTaskUpdate(this.$el, cfg.taskUpdateMethod || cfg.eventUpdateMethod, id, start, end, mode);
                        },
                    }));
                    this._bindings.push(dragDependency.attach(svgEl, {
                        getTask: (id) => this._getTask(id),
                        getTasks: () => this.tasks,
                        getDependencies: () => this.dependencies,
                        getGeom: () => this._geom,
                        getDepsLayer: () => svgEl.querySelector('#mg-layer-deps'),
                        onCycleRejected: () => console.warn('[MadGantt] dependency rejected: would create cycle'),
                        onDependencyCreate: (from, to, type, lag) => {
                            this.dependencies.push({ from, to, type, lag });
                            this._markDirty('deps');
                            if (this.criticalPath) this._recomputeCritical();
                            this._scheduleFlush();
                            wire.fireDependencyCreate(this.$el, cfg.dependencyCreateMethod, from, to, type, lag);
                        },
                    }));
                    this._bindings.push(hover.attach(svgEl, {
                        getTask: (id) => this._getTask(id),
                        tooltipCtx: () => this._tooltipCtx(),
                    }));
                }
                this._bindings.push(keyboard.attach(this.$el, {
                    clearSelection: () => { this.selectedTaskIds = {}; },
                    focusSearch: () => {
                        const input = this.$el.querySelector('.mad-gantt-search input, .mad-gantt-filter-input');
                        if (input) input.focus();
                    },
                    goToday: () => this.goToday(),
                    getChartScroll: () => this.$refs.chartScroll,
                    getZoom: () => this.zoom,
                }));

                // Sidebar click delegation (phase + task carets, row click)
                if (tasklistEl) {
                    tasklistEl.addEventListener('click', (e) => this._onSidebarClick(e));
                }

                // SVG click for task selection + task click callback
                if (svgEl) {
                    svgEl.addEventListener('click', (e) => this._onSvgClick(e));
                }

                // Minimap click → jump
                if (mmEl) {
                    mmEl.addEventListener('mousedown', (e) => {
                        if (!this._range) return;
                        const day = minimap.dayFromClick(e, mmEl, this._range.totalDays);
                        const pxPerDay = zoom.get(this.zoom).pxPerDay;
                        const vDays = this._viewportDays || 30;
                        scrollSync.jumpToDay(this.$refs.chartScroll, Math.max(0, day - vDays / 2), pxPerDay);
                    });
                }
            },

            _observeTheme() {
                if (!root.MutationObserver) return;
                this._themeMo = new root.MutationObserver(() => {
                    const t = detectTheme();
                    if (t !== this.theme) {
                        this.theme = t;
                        this._markDirty('all');
                        this._scheduleFlush();
                    }
                });
                this._themeMo.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'class'] });
            },

            _scrollToToday() {
                if (!this._range || !this.$refs.chartScroll) return;
                const pxPerDay = zoom.get(this.zoom).pxPerDay;
                // Offset DST-safe (dia inteiro round + fração intradia)
                const offFloat = coords.dayOffsetFloat(this._range.start, this._today);
                const x = Math.max(0, offFloat * pxPerDay - 200);
                this.$refs.chartScroll.scrollLeft = x;
            },

            // ── Reactive watchers via Alpine $watch (set up in init) ───────
            // We use $watch programmatically inside _setup() to avoid eager getters.

            // ── PUBLIC API METHODS ──────────────────────────────────────────
            goPrev() {
                if (!this._range) return;
                const profile = zoom.get(this.zoom);
                const pxPerDay = profile.pxPerDay;
                const chart = this.$refs.chartScroll;
                if (chart) chart.scrollLeft -= pxPerDay * (this._viewportDays * 0.6 || 7);
            },
            goNext() {
                if (!this._range) return;
                const profile = zoom.get(this.zoom);
                const pxPerDay = profile.pxPerDay;
                const chart = this.$refs.chartScroll;
                if (chart) chart.scrollLeft += pxPerDay * (this._viewportDays * 0.6 || 7);
            },
            goToday() { this._scrollToToday(); },

            setZoom(z) {
                this.zoom = zoom.normalize(z);
                persistence.save(this._ganttId, { zoom: this.zoom });
                this.zoomOpen = false;
                this._markDirty('all');
                this._scheduleFlush(() => this._scrollToToday());
            },
            setViewMode(m) {
                this.viewMode = m;
                persistence.save(this._ganttId, { viewMode: m });
                this.viewModeOpen = false;
                this._markDirty('all');
                this._scheduleFlush();
            },
            toggleCriticalMode() {
                this.criticalMode = !this.criticalMode;
                if (this.criticalMode && !this.criticalPath) {
                    this.criticalPath = true;
                    this._recomputeCritical();
                }
                this._markDirty('chart'); this._markDirty('deps'); this._markDirty('sidebar');
                this._scheduleFlush();
            },

            togglePhase(phaseId) {
                const key = String(phaseId);
                this.collapsedPhases[key] = !this.collapsedPhases[key];
                persistence.save(this._ganttId, { collapsedPhases: this.collapsedPhases });
                this._markDirty('all');
                this._scheduleFlush();
            },

            // ── Filtro de fases ─────────────────────────────────────────
            isPhaseActive(phaseId) {
                if (!this.phaseFilterMap) return true; // null = todas ativas
                return !!this.phaseFilterMap[String(phaseId)];
            },
            activePhaseCount() {
                if (!this.phaseFilterMap) return (this.phases || []).length;
                return Object.values(this.phaseFilterMap).filter(Boolean).length;
            },
            togglePhaseFilter(phaseId) {
                const key = String(phaseId);
                if (!this.phaseFilterMap) {
                    // Bootstrap: tudo ativo, agora desliga uma
                    this.phaseFilterMap = {};
                    for (const p of (this.phases || [])) this.phaseFilterMap[String(p.id)] = true;
                }
                this.phaseFilterMap[key] = !this.phaseFilterMap[key];
                // Tudo ativo de novo → volta a null pra economizar diff
                const allOn = (this.phases || []).every((p) => this.phaseFilterMap[String(p.id)]);
                if (allOn) this.phaseFilterMap = null;
                persistence.save(this._ganttId, { phaseFilterMap: this.phaseFilterMap });
                this._markDirty('all');
                this._scheduleFlush();
            },
            selectAllPhases() {
                this.phaseFilterMap = null;
                persistence.save(this._ganttId, { phaseFilterMap: null });
                this._markDirty('all');
                this._scheduleFlush();
            },
            clearAllPhases() {
                this.phaseFilterMap = {};
                for (const p of (this.phases || [])) this.phaseFilterMap[String(p.id)] = false;
                persistence.save(this._ganttId, { phaseFilterMap: this.phaseFilterMap });
                this._markDirty('all');
                this._scheduleFlush();
            },
            toggleTask(taskId) {
                const key = String(taskId);
                this.expanded[key] = this.expanded[key] === false ? true : false;
                persistence.save(this._ganttId, { expanded: this.expanded });
                this._markDirty('all');
                this._scheduleFlush();
            },

            onHeaderAction(method) {
                if (method) wire.fireHeaderAction(this.$el, method, method);
            },

            setWorkloadActive(mode) {
                this.workloadModeActive = mode;
                this._markDirty('workload');
                this._scheduleFlush();
            },

            getTimeTitle() {
                if (!this._range) return this.title || '';
                const sLbl = dates.fmtDate(this._range.start, { locale: this.locale });
                const eLbl = dates.fmtDate(dates.addDays(this._range.start, this._range.totalDays - 1), { locale: this.locale });
                return `${sLbl} — ${eLbl}`;
            },

            // ── Internal helpers ────────────────────────────────────────────
            _getTask(id) {
                const key = String(id);
                for (const t of this.tasks) if (String(t.id) === key) return t;
                return null;
            },

            _phaseOfTask(task) {
                if (!this.phaseField) return null;
                const v = task[this.phaseField];
                if (v == null) return null;
                for (const p of this.phases) if (String(p.id) === String(v)) return p;
                return null;
            },

            _tooltipCtx() {
                return {
                    theme:        this.theme,
                    phases:       this.phases,
                    phaseField:   this.phaseField,
                    phaseOfTask:  (t) => this._phaseOfTask(t),
                    people:       this.people,
                    locale:       this.locale,
                    criticalSet:  this.criticalSet,
                    dependencies: this.dependencies,
                    popTitle:     cfg.popTitle || '',
                    popContent:   cfg.popContent || '',
                    labels:       cfg.labels || {},
                };
            },

            _recomputeCritical() {
                this.criticalSet = cpm.compute(this.tasks, this.dependencies);
                this.criticalIds = {};
                this.criticalSet.forEach((id) => { this.criticalIds[id] = true; });
            },

            _onSidebarClick(e) {
                const phaseRow = e.target.closest('.mad-gantt-phase-row');
                if (phaseRow) { this.togglePhase(phaseRow.getAttribute('data-phase-id')); return; }
                const taskCaret = e.target.closest('[data-toggle-task]');
                if (taskCaret) { this.toggleTask(taskCaret.getAttribute('data-toggle-task')); return; }
            },

            _onSvgClick(e) {
                const target = e.target.closest('[data-task-id]');
                if (!target) {
                    // Clique em área vazia do chart → on-day-click(date)
                    if (cfg.dayClickMethod && this._range && this.$refs.chartSvg) {
                        const rect = this.$refs.chartSvg.getBoundingClientRect();
                        const pxPerDay = zoom.get(this.zoom).pxPerDay;
                        const dayIdx = Math.floor((e.clientX - rect.left) / pxPerDay);
                        if (dayIdx >= 0 && dayIdx < this._range.totalDays) {
                            const d = dates.addDays(this._range.start, dayIdx);
                            wire.fireDayClick(this.$el, cfg.dayClickMethod, dates.fmtISO(d), null);
                        }
                    }
                    return;
                }
                const id = target.getAttribute('data-task-id');
                if (this.enableMultiSelect && (e.ctrlKey || e.metaKey)) {
                    this.selectedTaskIds = { ...this.selectedTaskIds };
                    if (this.selectedTaskIds[id]) delete this.selectedTaskIds[id];
                    else this.selectedTaskIds[id] = true;
                } else {
                    this.selectedTaskIds = { [id]: true };
                }
                wire.fireTaskClick(this.$el, cfg.taskClickMethod || cfg.eventClickMethod, id);
            },

            // ── Dirty-flag scheduler ───────────────────────────────────────
            _markDirty(layer) {
                if (layer === 'all') { this._dirty = { all: true }; return; }
                this._dirty[layer] = true;
            },
            _scheduleFlush(after) {
                if (this._rafFlush) cancelAnimationFrame(this._rafFlush);
                this._rafFlush = requestAnimationFrame(() => {
                    this._rafFlush = 0;
                    this._flush();
                    if (typeof after === 'function') after.call(this);
                });
            },
            _flush() {
                const d = this._dirty;
                this._dirty = {};
                if (d.all) {
                    this._fullRecompute();
                    this._renderLayers({ all: true });
                    return;
                }
                // Só as camadas marcadas — scroll (minimap) não pode custar um
                // rebuild de header/sidebar/SVG inteiros por frame.
                this._renderLayers(d);
            },

            _fullRecompute() {
                this._range = coords.computeRange(this.tasks, this.zoom);
                if (this.phaseField && (!this.phases || this.phases.length === 0)) {
                    this.phases = tree.derivePhases(this.tasks, this.phaseField);
                }
                // phaseFilter: Set<id> derivado do phaseFilterMap (null = sem filtro)
                let phaseFilter = null;
                if (this.phaseFilterMap) {
                    phaseFilter = new Set();
                    for (const k in this.phaseFilterMap) {
                        if (this.phaseFilterMap[k]) phaseFilter.add(k);
                    }
                }
                this._rows = tree.visibleRows({
                    tasks: this.tasks,
                    phases: this.phases,
                    phaseField: this.phaseField,
                    expanded: this.expanded,
                    collapsedPhases: this.collapsedPhases,
                    filterText: this.filterText,
                    phaseFilter,
                    noPhaseLabel: (cfg.labels && cfg.labels.noPhase) || undefined,
                });
                const rowH = densityToRowH(this.density);
                const pxPerDay = zoom.get(this.zoom).pxPerDay;
                this._geom = coords.buildGeom(this._rows, this._range, pxPerDay, rowH);
                if (this.criticalPath) this._recomputeCritical();
            },

            _renderAll() {
                // Alias público (mad.js ops / integrações): render completo.
                this._renderLayers({ all: true });
            },

            _renderLayers(d) {
                if (!this._range) return;
                const all = !!d.all;
                const rowH = densityToRowH(this.density);
                const ctx = {
                    uid: this._ganttId,
                    range: this._range,
                    zoom: this.zoom,
                    rowH,
                    theme: this.theme,
                    today: this._today,
                    phases: this.phases,
                    phaseField: this.phaseField,
                    tasks: this.tasks,
                    dependencies: this.dependencies,
                    arrowStyle: this.arrowStyle,
                    criticalSet: this.criticalSet,
                    criticalMode: this.criticalMode,
                    showWeekends: this.showWeekends,
                    showGrid: this.showGrid,
                    workingDays: this.workingDays,
                    holidays: this.holidays,
                };

                // Header
                if ((all || d.header) && this.$refs.headerInner) {
                    header.render(this.$refs.headerInner, this._range, this.zoom, this._today, {
                        locale: this.locale, showWeekends: this.showWeekends,
                    });
                }

                // Sidebar (head + body)
                if (all || d.sidebar) {
                    if (this.$refs.tasklistHead) {
                        sidebar.renderHead(this.$refs.tasklistHead, {
                            showAvatars: this.showAvatars,
                            columns: this.columns,
                        });
                    }
                    if (this.$refs.tasklist) {
                        sidebar.render(this.$refs.tasklist, this._rows, {
                            rowH,
                            collapsedPhases: this.collapsedPhases,
                            expanded: this.expanded,
                            showAvatars: this.showAvatars,
                            people: this.people,
                            columns: this.columns,
                            criticalSet: this.criticalSet,
                            criticalMode: this.criticalMode,
                            theme: this.theme,
                        });
                    }
                }

                // Chart SVG (barras + setas — o render cobre os dois)
                if ((all || d.chart || d.deps) && this.$refs.chartSvg) {
                    chartSvg.render(this.$refs.chartSvg, this._rows, this._geom, ctx);
                }

                // Minimap
                if ((all || d.minimap || d.chart) && this.showMinimap && this.$refs.minimap) {
                    minimap.render(this.$refs.minimap, {
                        ...ctx,
                        viewportStart: this._viewportStart || 0,
                        viewportDays:  this._viewportDays  || 30,
                    });
                }

                // Workload
                if ((all || d.workload || d.chart) && this.showWorkload && this.$refs.workloadBody) {
                    workload.render(this.$refs.workloadBody, {
                        ...ctx,
                        resources: this.resources,
                        assignments: this.assignments,
                        workloadMode: this.workloadModeActive,
                    });
                }

                // Stats (progresso médio muda junto com o chart)
                if (all || d.chart || d.stats) {
                    this._renderStats();
                }
            },

            _renderStats() {
                const el = this.$refs.stats;
                if (!el) return;
                const L = Object.assign({
                    tasks: 'Tarefas', milestones: 'Milestones', critical: 'Caminho crítico',
                    nodes: 'nós', avgProgress: 'Progresso médio', today: '↻ Hoje',
                }, cfg.labels || {});
                const tasks = this.tasks.filter((t) => !t.milestone);
                const ms    = this.tasks.filter((t) => t.milestone);
                const avg   = tasks.length > 0
                    ? Math.round(tasks.reduce((a, t) => a + clamp01(t.progress), 0) / tasks.length * 100)
                    : 0;
                const critCount = this.criticalSet ? this.criticalSet.size : 0;
                el.innerHTML = `
                    <span class="mad-gantt-stat"><span class="stat-key">${escText(L.tasks)}</span><span class="stat-val">${tasks.length}</span></span>
                    <span class="mad-gantt-stat"><span class="stat-key">${escText(L.milestones)}</span><span class="stat-val">${ms.length}</span></span>
                    <span class="mad-gantt-stat"><span class="stat-key">${escText(L.critical)}</span><span class="stat-val stat-crit">${critCount} ${escText(L.nodes)}</span></span>
                    <span class="mad-gantt-stat"><span class="stat-key">${escText(L.avgProgress)}</span><span class="stat-val">${avg}%</span></span>
                    <button type="button" class="mad-gantt-btn-today">${escText(L.today)}</button>`;
                // Listener direto — antes o @click via innerHTML dependia do
                // MutationObserver do Alpine re-inicializar o nó a cada render.
                const btn = el.querySelector('.mad-gantt-btn-today');
                if (btn) btn.addEventListener('click', () => this.goToday());
            },

            // ── Watchers (called from Blade via x-effect) ─────────────────
            onFilterChanged() {
                this._markDirty('all');
                this._scheduleFlush();
            },
        }));
    }

    // ── Module-private helpers ────────────────────────────────────────────

    function detectTheme() {
        const d = document.documentElement;
        if (d.dataset.theme === 'dark') return 'dark';
        if (d.classList.contains('dark') || d.classList.contains('theme-dark')) return 'dark';
        return 'light';
    }

    function densityToRowH(density) {
        if (density === 'compact')  return 30;
        if (density === 'spacious') return 48;
        return 38;
    }

    function clamp01(v) {
        // Pós-ingest o progress é SEMPRE fração 0..1 (normalizeTask) — clamp puro.
        const n = +v;
        if (!Number.isFinite(n)) return 0;
        return Math.max(0, Math.min(1, n));
    }

    /**
     * Converte progress de qualquer escala pra fração 0..1 — ÚNICO ponto com
     * heurística de escala do bundle. scale: 'fraction' | 'percent' | 'auto'.
     * No 'auto', valores >1 são lidos como escala 0-100 (ambiguidade inerente
     * do valor 1: tratado como 100% fração — use progress-scale explícito
     * quando o banco guarda 0-100).
     */
    function normProgress(v, scale) {
        const n = +v;
        if (!Number.isFinite(n)) return 0;
        if (scale === 'percent') return Math.max(0, Math.min(1, n / 100));
        if (scale === 'fraction') return Math.max(0, Math.min(1, n));
        if (n > 1 && n <= 100) return Math.max(0, Math.min(1, n / 100));
        return Math.max(0, Math.min(1, n));
    }

    /** Hora "meia-noite exata" em serialização DATETIME = data-only. */
    function stripMidnight(v) {
        if (typeof v !== 'string') return v;
        return v.replace(/[T ]00:00(:00)?(\.0+)?$/, '');
    }

    /** Normalização canônica de UMA task (ingest + upsert parcial). */
    function normalizeTask(t, progressScale) {
        const out = {
            ...t,
            id: t.id != null ? String(t.id) : '',
            parentId: t.parentId != null && t.parentId !== '' ? String(t.parentId) : '',
            milestone: !!t.milestone || t.type === 'milestone',
        };
        if ('start' in out) out.start = stripMidnight(out.start);
        if ('end' in out)   out.end   = stripMidnight(out.end);
        if (out.progress != null && out.progress !== '') {
            out.progress = normProgress(out.progress, progressScale || 'auto');
        }
        return out;
    }

    function normalizeTasks(tasks, progressScale) {
        if (!Array.isArray(tasks)) return [];
        return tasks.map((t) => normalizeTask(t, progressScale));
    }

    root.MadGantt.util = Object.assign(root.MadGantt.util || {}, {
        normProgress, normalizeTask, stripMidnight,
    });

    function hasTreeShape(tasks) {
        if (!Array.isArray(tasks)) return false;
        for (const t of tasks) if (t && t.parentId != null && t.parentId !== '') return true;
        return false;
    }

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('alpine:init', init);
    } else if (root.Alpine) {
        init();
    } else {
        document.addEventListener('alpine:init', init);
    }

})(typeof window !== 'undefined' ? window : globalThis);
