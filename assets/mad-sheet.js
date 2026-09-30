/**
 * MadSheet — client da planilha de lançamento em lote (<mad-sheet>).
 *
 * Registra `Alpine.data('madSheet', ...)`. O PHP (MadSheet) fala com este
 * componente por CustomEvents despachados no container [data-mad-sheet]:
 *
 *   mad-sheet:errors  { errors: [{row, field, msg}] }  → marca células
 *   mad-sheet:valid   { count }                        → limpa marcas
 *   mad-sheet:saved   { count }                        → reseta a grade
 *
 * Server-side dispatch via MadWire.call (mesmo bridge do mad-gantt.js):
 * onValidateBatch / onSaveBatch recebem JSON { indiceAbsoluto: {campo: valor} }
 * só com as linhas preenchidas — o índice absoluto é o que mapeia o erro de
 * volta pra célula.
 *
 * Editor entry-first:
 *   - Enter/setas navegam células (←/→ só com o caret na borda do input)
 *   - paste TSV do Excel espalha multi-célula a partir da âncora
 *   - Ctrl+D fill-down, Ctrl+Z undo (stack de snapshots, cap 50)
 *   - virtual scroll por janela (linhas fixas de SHEET_ROW_H px)
 */
(function (root) {
    'use strict';

    var SHEET_ROW_H   = 37;   // altura fixa da linha (sincronizada com o CSS)
    var WINDOW_EXTRA  = 10;   // linhas extras render além do viewport
    var UNDO_CAP      = 50;

    // ── MadWire bridge (mesma sonda do mad-gantt.js) ─────────────────────
    function wireCall(componentEl, method, payload) {
        var MW = null;
        try { MW = (typeof MadWire !== 'undefined') ? MadWire : null; } catch (_) {}
        if (!MW && root.MadWire) MW = root.MadWire;
        if (MW && typeof MW.call === 'function') {
            try {
                var wrapper = (componentEl && componentEl.closest && componentEl.closest('[mad-component]')) || componentEl;
                return Promise.resolve(MW.call(wrapper, method, payload));
            } catch (e) { console.warn('[MadSheet] MadWire.call failed', e); }
        }
        return Promise.resolve(null);
    }

    function init() {
        var Alpine = root.Alpine;
        if (!Alpine || Alpine.__madSheetRegistered) return;
        Alpine.__madSheetRegistered = true;

        Alpine.data('madSheet', function (cfg) {
            cfg = cfg || {};
            cfg.columns = Array.isArray(cfg.columns) ? cfg.columns : [];

            return {
                cfg: cfg,
                rows: [],
                cellErrors: {},   // "r:field" -> msg
                undoStack: [],
                saving: false,
                viewStart: 0,
                viewCount: 40,
                padTop: 0,
                padBottom: 0,
                // Muda quando rows são mutadas por FORA dos cell-components
                // (paste/undo/fill-down/clear) — entra no :key do x-for e
                // força re-init de madMoneyCell/madNumericCell/madDatePicker.
                epoch: 0,
                _editSnapshot: null,   // snapshot no focusin; vira undo no focusout se mudou

                // Cache field+"\0"+value → label das opções escolhidas nos
                // combos (search mode não tem lista baked; a option
                // pré-selecionada precisa do label ao recriar a célula no
                // virtual scroll).
                comboLabels: {},

                // ── Lifecycle ────────────────────────────────────────────
                init() {
                    var self = this;
                    // Colunas compute: compila a fórmula (JS já compilado pelo
                    // servidor — FieldListColumn::compileFormula) 1x por coluna.
                    this._computeFns = [];
                    cfg.columns.forEach(function (col) {
                        if (!col.computeJs) return;
                        try {
                            self._computeFns.push({
                                field: col.field,
                                fn: new Function('row', '"use strict"; return (' + col.computeJs + ');'),
                            });
                        } catch (e) { console.warn('[MadSheet] compute inválido em ' + col.field, e); }
                    });

                    this.addRows(Math.max(1, cfg.rowsMin || 8), true);

                    this._onErrors = function (e) { self._applyErrors((e.detail && e.detail.errors) || []); };
                    this._onValid  = function ()  { self.cellErrors = {}; };
                    this._onSaved  = function ()  { self._resetAfterSave(); };
                    this.$el.addEventListener('mad-sheet:errors', this._onErrors);
                    this.$el.addEventListener('mad-sheet:valid',  this._onValid);
                    this.$el.addEventListener('mad-sheet:saved',  this._onSaved);

                    // Capture global: QUALQUER scroll (página, container, painel
                    // ancestral) move o input sob um popup fixed — segue ou fecha.
                    this._onAnyScroll = function () { self._syncDatePopups(); };
                    document.addEventListener('scroll', this._onAnyScroll, true);

                    this.$nextTick(function () { self._recalcWindow(); });
                },

                destroy() {
                    this.$el.removeEventListener('mad-sheet:errors', this._onErrors);
                    this.$el.removeEventListener('mad-sheet:valid',  this._onValid);
                    this.$el.removeEventListener('mad-sheet:saved',  this._onSaved);
                    document.removeEventListener('scroll', this._onAnyScroll, true);
                },

                // ── Virtual window ───────────────────────────────────────
                get windowRows() {
                    var out = [];
                    var end = Math.min(this.rows.length, this.viewStart + this.viewCount);
                    for (var r = this.viewStart; r < end; r++) out.push(r);
                    return out;
                },

                onScroll() {
                    // popups fixed são tratados pelo listener capture global
                    this._recalcWindow();
                },

                /**
                 * Reposiciona o popup do madDatePicker como FIXED: a célula vive
                 * num container overflow:auto que cortaria o absolute. Flip pra
                 * CIMA quando não cabe abaixo do input. Chamado via x-effect do
                 * datewrap (escopo filho enxerga o madSheet pai).
                 */
                positionDatePopup(root) {
                    var pop = root ? root.querySelector('.mad-drp-popup') : null;
                    if (!pop) return;
                    var rect = root.getBoundingClientRect();
                    pop.style.position = 'fixed';
                    pop.style.zIndex   = '1000';
                    pop.style.right    = 'auto';
                    pop.style.bottom   = 'auto';
                    var w = pop.offsetWidth  || 280;
                    var h = pop.offsetHeight || 330;
                    pop.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - w - 8)) + 'px';
                    var fitsBelow = rect.bottom + h + 4 <= window.innerHeight;
                    var fitsAbove = rect.top - h - 4 >= 0;
                    if (fitsBelow) {
                        pop.style.top = (rect.bottom + 2) + 'px';    // abaixo (preferência)
                    } else if (fitsAbove) {
                        pop.style.top = (rect.top - h - 4) + 'px';   // flip pra cima
                    } else {
                        // viewport baixa: não cabe em nenhum lado — clampa 100%
                        // visível (sobrepõe o input; melhor que cortar o popup)
                        pop.style.top = Math.max(8, window.innerHeight - h - 8) + 'px';
                    }
                },

                /**
                 * Popups abertos durante scroll: seguem o input (reposiciona o
                 * fixed) enquanto ele estiver na área visível do container;
                 * fecham quando o input sai. Fechar sempre mataria o popup
                 * recém-aberto quando o focus auto-scrolla a célula da borda.
                 */
                _syncDatePopups() {
                    if (!root.Alpine) return;
                    var self   = this;
                    var scRect = this.$refs.scroll ? this.$refs.scroll.getBoundingClientRect() : null;
                    this.$el.querySelectorAll('.mad-sheet-datewrap').forEach(function (el) {
                        try {
                            var data = root.Alpine.$data(el);
                            if (!data || !data.isOpen) return;
                            var r = el.getBoundingClientRect();
                            if (scRect && (r.bottom < scRect.top + 4 || r.top > scRect.bottom - 4)) {
                                data.close();
                            } else {
                                self.positionDatePopup(el);
                            }
                        } catch (_) {}
                    });
                },

                _recalcWindow() {
                    var sc = this.$refs.scroll;
                    if (!sc) return;
                    var first = Math.floor(sc.scrollTop / SHEET_ROW_H) - WINDOW_EXTRA;
                    this.viewStart = Math.max(0, first);
                    this.viewCount = Math.ceil(sc.clientHeight / SHEET_ROW_H) + WINDOW_EXTRA * 2;
                    var end        = Math.min(this.rows.length, this.viewStart + this.viewCount);
                    this.padTop    = this.viewStart * SHEET_ROW_H;
                    this.padBottom = Math.max(0, (this.rows.length - end) * SHEET_ROW_H);
                },

                // ── Rows / cells ─────────────────────────────────────────
                _blankRow() {
                    var row = {};
                    this.cfg.columns.forEach(function (col) { row[col.field] = col.default || ''; });
                    return row;
                },

                addRows(n, skipUndo) {
                    n = Math.max(1, n | 0);
                    var room = Math.max(0, (this.cfg.maxRows || 2000) - this.rows.length);
                    n = Math.min(n, room);
                    if (!n) return;
                    if (!skipUndo) this.pushUndo();
                    for (var i = 0; i < n; i++) this.rows.push(this._blankRow());
                    this._recalcWindow();
                },

                clearRow(r) {
                    if (!this.rows[r]) return;
                    this.pushUndo();
                    this.rows[r] = this._blankRow();
                    this._clearRowErrors(r);
                    this.computeRow(r);
                    this.epoch++; // re-init células da linha
                },

                _clearRowErrors(r) {
                    var self = this;
                    Object.keys(this.cellErrors).forEach(function (key) {
                        if (key.indexOf(r + ':') === 0) delete self.cellErrors[key];
                    });
                },

                cellGet(r, field) {
                    var row = this.rows[r];
                    return row ? (row[field] != null ? row[field] : '') : '';
                },

                cellSet(r, field, value) {
                    if (!this.rows[r]) return;
                    this.rows[r][field] = value;
                    delete this.cellErrors[r + ':' + field];
                },

                // ── Compute (colunas calculadas) ─────────────────────────
                /**
                 * Recalcula as colunas compute da linha r. Chamado via
                 * x-effect no <tr>: as leituras row['campo'] dentro da fn
                 * registram deps reativas — digitou num campo referenciado,
                 * recalcula ao vivo. Guard !== evita write-loop (fórmula não
                 * deve referenciar o próprio field — documentado).
                 */
                computeRow(r) {
                    if (!this._computeFns || !this._computeFns.length) return;
                    var row = this.rows[r];
                    if (!row) return;
                    // Linha sem digitação → compute vazio (0 calculado inflaria
                    // o Σ count e marcaria a linha visualmente preenchida).
                    var blank = !this.isRowFilled(row);
                    for (var i = 0; i < this._computeFns.length; i++) {
                        var f = this._computeFns[i];
                        var v = '';
                        if (!blank) {
                            try { v = f.fn(row); } catch (_) { v = 0; }
                            if (typeof v === 'number' && !isFinite(v)) v = 0;
                        }
                        if (row[f.field] !== v) row[f.field] = v;
                    }
                },

                /**
                 * Passe imperativo sobre TODAS as linhas — mutações em lote
                 * (paste/undo/fill-down/clear/addRows) tocam linhas fora da
                 * janela virtual, onde não existe x-effect montado.
                 */
                _computeAll() {
                    if (!this._computeFns || !this._computeFns.length) return;
                    for (var r = 0; r < this.rows.length; r++) this.computeRow(r);
                },

                // ── Mask / force-case (colunas text) ─────────────────────
                /**
                 * Handler de @input das células text: aplica mask (aliases
                 * cpf/cnpj/... ou pattern 9/A/*) + force-case ANTES do
                 * cellSet. Roda aqui (não no init global de mask do mad-ui):
                 * células virtualizadas nascem depois do DOMContentLoaded e o
                 * listener global correria com o @input do Alpine.
                 */
                cellSetMasked(r, col, e) {
                    var el = e.target;
                    var v  = el.value;

                    if (col.mask && root._madResolveMaskPattern && root._madApplyMaskPattern) {
                        var pattern = root._madResolveMaskPattern(col.mask, v);
                        if (pattern) {
                            var caretRaw = 0;
                            try {
                                var before = v.slice(0, el.selectionStart || 0);
                                caretRaw = before.replace(/[^0-9A-Za-z]/g, '').length;
                            } catch (_) {}
                            var masked = root._madApplyMaskPattern(v, pattern);
                            if (masked !== v) {
                                el.value = masked;
                                if (root._madMaskedCursor) {
                                    try {
                                        var pos = root._madMaskedCursor(pattern, caretRaw);
                                        el.setSelectionRange(pos, pos);
                                    } catch (_) {}
                                }
                            }
                            v = masked;
                        }
                    }

                    if (col.forceCase && root._madApplyForceCase) {
                        var cased = root._madApplyForceCase(v, col.forceCase);
                        if (cased !== v) {
                            var selStart = null;
                            try { selStart = el.selectionStart; } catch (_) {}
                            el.value = cased;
                            if (selStart != null) { try { el.setSelectionRange(selStart, selStart); } catch (_) {} }
                            v = cased;
                        }
                    }

                    this.cellSet(r, col.field, v);
                },

                // ── Combos (baked e search) ──────────────────────────────
                /**
                 * @change dos selects combo: grava o valor, memoriza o label
                 * (opção escolhida ou quick-registered), propaga o label pra
                 * lista baked (quick register aparece em todas as células) e
                 * limpa os combos DEPENDENTES desta linha (cascade).
                 */
                cellComboChanged(r, col, el) {
                    var v = el.value;
                    this.cellSet(r, col.field, v);

                    var label = '';
                    try {
                        var opt = el.selectedOptions && el.selectedOptions[0];
                        label = opt ? String(opt.textContent || '').trim() : '';
                    } catch (_) {}
                    if (v !== '' && label !== '') {
                        this.comboLabels[col.field + ' ' + v] = label;
                        // Combo baked: opção nova (quick register) entra na
                        // lista reativa — as demais células ganham na hora.
                        if (!col.searchToken && col.options && !(v in col.options)) {
                            col.options[v] = label;
                        }
                    }

                    this._clearDependents(r, col.field);
                },

                /** Label de um valor de combo (cache → lista baked → o próprio valor). */
                comboLabel(field, value) {
                    var key = field + ' ' + value;
                    if (this.comboLabels[key]) return this.comboLabels[key];
                    var col = this.cfg.columns.find(function (c) { return c.field === field; });
                    if (col && col.options && col.options[value] != null) return col.options[value];
                    return String(value);
                },

                /**
                 * Cascade: mudou o pai → zera os filhos DESTA linha (recursivo
                 * pra netos). Se a célula filha está montada (janela visível),
                 * reseta também o MAD Select (valor + cache de results — o
                 * min-length 0 cacheia a lista do pai anterior).
                 */
                _clearDependents(r, field) {
                    var self = this;
                    this.cfg.columns.forEach(function (c) {
                        if (c.dependsOn !== field) return;
                        if (self.rows[r] && self.rows[r][c.field] !== '') {
                            self.rows[r][c.field] = '';
                            delete self.cellErrors[r + ':' + c.field];
                        }
                        var el = self.$el.querySelector('select[data-r="' + r + '"][data-f="' + c.field + '"]');
                        if (el && el._madSelect) {
                            try {
                                if (el._madSelect.setValue) el._madSelect.setValue('', true);
                                el._madSelect.results = [];
                            } catch (_) {}
                        }
                        self._clearDependents(r, c.field);
                    });
                },

                // Undo + limpeza de erro por DELEGAÇÃO: cell-components (money/
                // date) escrevem em rows[r] por caminhos próprios — o par
                // focusin/focusout cobre todos os tipos de célula.
                onFocusIn(e) {
                    var t = e.target;
                    if (!t || !t.dataset || t.dataset.r == null) return;
                    this._editSnapshot = JSON.stringify(this.rows);
                    delete this.cellErrors[t.dataset.r + ':' + t.dataset.f];
                },

                onFocusOut(e) {
                    if (!this._editSnapshot) return;
                    if (JSON.stringify(this.rows) !== this._editSnapshot) {
                        this._pushSnapshot(this._editSnapshot);
                    }
                    this._editSnapshot = null;
                },

                /** "1.234,56" / "1,234.56" / "12,5" → "1234.56" (decimal ponto). */
                _normalizeNumber(raw) {
                    var s = raw.replace(/\s/g, '');
                    var lastComma = s.lastIndexOf(','), lastDot = s.lastIndexOf('.');
                    if (lastComma > -1 && lastDot > -1) {
                        // separador decimal = o que aparece por último
                        s = lastComma > lastDot
                            ? s.replace(/\./g, '').replace(',', '.')
                            : s.replace(/,/g, '');
                    } else if (lastComma > -1) {
                        s = s.replace(',', '.');
                    }
                    return s;
                },

                isRowFilled(row) {
                    if (!row) return false;
                    var cols = this.cfg.columns;
                    for (var i = 0; i < cols.length; i++) {
                        if (cols[i].readonly) continue;
                        var v = row[cols[i].field];
                        if (v == null || String(v).trim() === '') continue;
                        // money/number: cell-components escrevem 0 no blur mesmo
                        // sem digitação — zero NÃO conta como preenchido
                        if ((cols[i].type === 'money' || cols[i].type === 'number') && !parseFloat(v)) continue;
                        if (String(v) !== String(cols[i].default || '')) return true;
                    }
                    return false;
                },

                get dirtyCount() {
                    var self = this, n = 0;
                    this.rows.forEach(function (row) { if (self.isRowFilled(row)) n++; });
                    return n;
                },

                get errorCount() {
                    return Object.keys(this.cellErrors).length;
                },

                // ── Teclado ──────────────────────────────────────────────
                onKey(e) {
                    var t = e.target;
                    if (!t || !t.dataset || t.dataset.r == null) return;
                    var r = parseInt(t.dataset.r, 10);
                    var f = t.dataset.f;

                    // Ctrl/Cmd+Z — undo global da grade
                    if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'z') {
                        e.preventDefault();
                        this.undo();
                        return;
                    }
                    // Ctrl/Cmd+D — fill-down (copia célula de cima)
                    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'd') {
                        e.preventDefault();
                        if (r > 0) {
                            this.pushUndo();
                            this.rows[r][f] = this.rows[r - 1][f];
                            delete this.cellErrors[r + ':' + f];
                            this.computeRow(r);
                            this._editSnapshot = null; // undo já registrado
                            this.epoch++;              // re-init célula (cell-component)
                            this.focusCell(r, f);
                        }
                        return;
                    }

                    switch (e.key) {
                        case 'Enter':
                            e.preventDefault();
                            if (r + 1 >= this.rows.length && this.isRowFilled(this.rows[r])) this.addRows(1, true);
                            this.focusCell(Math.min(r + 1, this.rows.length - 1), f);
                            break;
                        case 'ArrowDown':
                            if (t.tagName === 'SELECT') return; // nativo
                            e.preventDefault();
                            this.focusCell(Math.min(r + 1, this.rows.length - 1), f);
                            break;
                        case 'ArrowUp':
                            if (t.tagName === 'SELECT') return;
                            e.preventDefault();
                            this.focusCell(Math.max(0, r - 1), f);
                            break;
                        case 'ArrowLeft':
                            if (this._caretAtStart(t)) { e.preventDefault(); this._focusSibling(r, f, -1); }
                            break;
                        case 'ArrowRight':
                            if (this._caretAtEnd(t)) { e.preventDefault(); this._focusSibling(r, f, +1); }
                            break;
                    }
                },

                _caretAtStart(t) {
                    if (t.tagName === 'SELECT') return true;
                    try { return t.selectionStart === 0 && t.selectionEnd === 0; } catch (_) { return true; }
                },

                _caretAtEnd(t) {
                    if (t.tagName === 'SELECT') return true;
                    try { return t.selectionStart === t.value.length && t.selectionEnd === t.value.length; } catch (_) { return true; }
                },

                _focusSibling(r, field, dir) {
                    var cols = this.cfg.columns;
                    var i = cols.findIndex(function (c) { return c.field === field; });
                    var next = i + dir;
                    while (next >= 0 && next < cols.length && cols[next].readonly) next += dir;
                    if (next >= 0 && next < cols.length) {
                        this.focusCell(r, cols[next].field);
                    } else if (dir > 0 && r + 1 < this.rows.length) {
                        this.focusCell(r + 1, this._firstEditableField());
                    } else if (dir < 0 && r > 0) {
                        var lastEditable = null;
                        cols.forEach(function (c) { if (!c.readonly) lastEditable = c.field; });
                        if (lastEditable) this.focusCell(r - 1, lastEditable);
                    }
                },

                _firstEditableField() {
                    var cols = this.cfg.columns;
                    for (var i = 0; i < cols.length; i++) if (!cols[i].readonly) return cols[i].field;
                    return cols.length ? cols[0].field : '';
                },

                focusCell(r, field) {
                    var self = this;
                    // garante a linha dentro da janela virtual antes de focar
                    var sc = this.$refs.scroll;
                    if (sc) {
                        var top = r * SHEET_ROW_H, bottom = top + SHEET_ROW_H;
                        var headH = sc.querySelector('thead') ? sc.querySelector('thead').offsetHeight : 0;
                        if (top < sc.scrollTop) sc.scrollTop = top;
                        else if (bottom > sc.scrollTop + sc.clientHeight - headH) sc.scrollTop = bottom - sc.clientHeight + headH;
                        this._recalcWindow();
                    }
                    this.$nextTick(function () {
                        var el = self.$el.querySelector('[data-r="' + r + '"][data-f="' + field + '"]');
                        if (!el) return;
                        // combo enhançado (MAD Select): o nativo fica oculto —
                        // clica o control (abre dropdown + foca a busca)
                        var sel = el.closest('.mad-sel');
                        if (sel) {
                            var control = sel.querySelector('.mad-sel-control');
                            if (control) control.click();
                            return;
                        }
                        el.focus();
                        if (el.select && el.tagName === 'INPUT' && el.type === 'text') el.select();
                    });
                },

                // ── Paste TSV (Excel) ────────────────────────────────────
                onPaste(e) {
                    var t = e.target;
                    if (!t || !t.dataset || t.dataset.r == null) return;
                    var text = (e.clipboardData || root.clipboardData);
                    text = text ? text.getData('text/plain') : '';
                    if (!text || (text.indexOf('\t') === -1 && text.indexOf('\n') === -1)) {
                        return; // valor simples: paste nativo na célula
                    }
                    e.preventDefault();

                    var lines = text.replace(/\r/g, '').split('\n');
                    while (lines.length && lines[lines.length - 1] === '') lines.pop();
                    if (!lines.length) return;

                    this.pushUndo();

                    var startR = parseInt(t.dataset.r, 10);
                    var cols   = this.cfg.columns;
                    var startC = cols.findIndex(function (c) { return c.field === t.dataset.f; });
                    if (startC < 0) startC = 0;

                    for (var li = 0; li < lines.length; li++) {
                        var r = startR + li;
                        if (r >= (this.cfg.maxRows || 2000)) break;
                        while (r >= this.rows.length) this.rows.push(this._blankRow());

                        var cells = lines[li].split('\t');
                        var c = startC;
                        for (var ci = 0; ci < cells.length && c < cols.length; ci++, c++) {
                            while (c < cols.length && cols[c].readonly) c++;
                            if (c >= cols.length) break;
                            var col = cols[c];
                            var v   = cells[ci].trim();
                            if (col.type === 'number' || col.type === 'money') v = this._normalizeNumber(v);
                            if (col.type === 'date') v = this._normalizeDate(v, col);
                            this.rows[r][col.field] = v;
                            delete this.cellErrors[r + ':' + col.field];
                        }
                    }
                    this._computeAll();
                    this._editSnapshot = null; // undo do paste já registrado
                    this.epoch++;              // re-init células (cell-components releem rows)
                    this._recalcWindow();
                },

                /**
                 * "12/07/2026" (Excel pt-BR) → "2026-07-12"; ISO passa direto.
                 * database-mask customizada → NÃO normaliza (a validação do
                 * servidor confere no formato da coluna).
                 */
                _normalizeDate(raw, col) {
                    var s = String(raw).trim();
                    if (col && col.databaseMask && col.databaseMask !== 'yyyy-mm-dd') return s;
                    var m = s.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
                    if (m) {
                        return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
                    }
                    return s;
                },

                // ── Undo ─────────────────────────────────────────────────
                pushUndo() {
                    this._pushSnapshot(JSON.stringify(this.rows));
                },

                _pushSnapshot(snapshot) {
                    this.undoStack.push(snapshot);
                    if (this.undoStack.length > UNDO_CAP) this.undoStack.shift();
                },

                undo() {
                    var snapshot = this.undoStack.pop();
                    if (!snapshot) return;
                    try {
                        this.rows = JSON.parse(snapshot);
                        this.cellErrors = {};
                        this._editSnapshot = null;
                        this._computeAll();
                        this.epoch++; // re-init células (cell-components releem rows)
                        this._recalcWindow();
                    } catch (_) {}
                },

                // ── Totais ───────────────────────────────────────────────
                totalFor(col) {
                    if (!col.total) return '';
                    var self = this, sum = 0, count = 0;
                    this.rows.forEach(function (row) {
                        var v = row[col.field];
                        if (v == null || String(v).trim() === '') return;
                        count++;
                        var n = parseFloat(self._normalizeNumber(String(v)));
                        if (!isNaN(n)) sum += n;
                    });
                    if (col.total === 'count') return count ? String(count) : '';
                    if (!count) return '';
                    try {
                        return new Intl.NumberFormat(undefined, {
                            minimumFractionDigits: col.decimals != null ? col.decimals : 2,
                            maximumFractionDigits: col.decimals != null ? col.decimals : 2,
                        }).format(sum);
                    } catch (_) { return String(sum); }
                },

                // ── Server actions ───────────────────────────────────────
                _collect() {
                    var self = this, out = {};
                    this.rows.forEach(function (row, r) {
                        if (self.isRowFilled(row)) out[r] = row;
                    });
                    return out;
                },

                validate() {
                    var payload = this._collect();
                    if (!Object.keys(payload).length) return;
                    wireCall(this.$el, 'onValidateBatch', [JSON.stringify(payload)]);
                },

                save() {
                    var self = this;
                    var payload = this._collect();
                    if (!Object.keys(payload).length || this.saving) return;
                    this.saving = true;
                    wireCall(this.$el, 'onSaveBatch', [JSON.stringify(payload)])
                        .finally(function () { self.saving = false; });
                },

                // ── Server events ────────────────────────────────────────
                _applyErrors(errors) {
                    var map = {};
                    (errors || []).forEach(function (err) {
                        if (err && err.field != null) map[err.row + ':' + err.field] = err.msg || 'error';
                    });
                    this.cellErrors = map;
                    var first = (errors || [])[0];
                    if (first) this.focusCell(parseInt(first.row, 10) || 0, first.field);
                },

                _resetAfterSave() {
                    this.rows = [];
                    this.cellErrors = {};
                    this.undoStack = [];
                    this._editSnapshot = null;
                    this.addRows(Math.max(1, this.cfg.rowsMin || 8), true);
                    this.epoch++;
                    if (this.$refs.scroll) this.$refs.scroll.scrollTop = 0;
                },

                // ── Visual ───────────────────────────────────────────────
                cellClass(r, col) {
                    return {
                        'mad-sheet-cell': true,
                        'mad-sheet-cell--readonly': !!col.readonly,
                        'mad-sheet-cell--error': !!this.cellErrors[r + ':' + col.field],
                        'mad-sheet-cell--num': col.type === 'number' || col.type === 'money',
                    };
                },

                cellError(r, field) {
                    return this.cellErrors[r + ':' + field] || '';
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
