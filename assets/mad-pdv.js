/**
 * mad-pdv.js — runtime client do <mad-pdv> (frente de caixa).
 *
 * Carrinho 100% client-side em CENTAVOS INTEIROS (§7.6 — nunca float até o
 * payload); servidor tocado só no lookup e no finalize. Config vem do x-data
 * (montada por MadPdvComponent::getClientConfig — sem model/coluna/conexão).
 * Eventos do servidor (CustomEvent no container [data-mad-pdv]):
 *   mad-pdv:lookup-result | mad-pdv:sale-done | mad-pdv:sale-error
 *
 * Contrato completo: docs/specs/mad-pdv-v1.md (Rev. 2) no monorepo.
 */
(function (root) {
    'use strict';

    /** Chama o MadWire do componente-pai (mesma sonda do mad-sheet.js). */
    function wireCall(componentEl, method, params) {
        var wire = (typeof MadWire !== 'undefined') ? MadWire : root.MadWire;
        var host = componentEl.closest('[mad-component]');
        if (!wire || !host) {
            console.error('[mad-pdv] MadWire indisponível');
            return Promise.reject(new Error('MadWire indisponível'));
        }
        return wire.call(host, method, params);
    }

    function uuid() {
        if (root.crypto && root.crypto.randomUUID) {
            return root.crypto.randomUUID();
        }
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    function registerPdv() {
        if (root.Alpine.__madPdvRegistered) {
            return;
        }
        root.Alpine.__madPdvRegistered = true;

        root.Alpine.data('madPdv', function (cfg) {
            return {
                cfg: cfg,

                // ── Estado ────────────────────────────────────────────────
                cart: [],            // {uid,id,name,code,barcode,unit,image,priceC,qty,discC,stock,stockWarn}
                selLine: -1,
                _uidSeq: 0,

                scanTerm: '',
                results: [],
                searchOpen: false,
                searchReason: '',
                hi: 0,
                _reqSeq: 0,
                _lastHandled: 0,
                _scanMult: {},       // requestId → multiplicador (3*)
                _reqChannel: {},     // requestId → 'scan' | 'picker'
                _lastPicker: 0,      // out-of-order do modal, separado do scan
                _searchTimer: null,

                // Catálogo navegável (Rev. 5). `stack` guarda o cursor keyset de
                // cada página visitada — é o que permite voltar sem offset.
                picker: { open: false, term: '', items: [], page: 0, stack: [''],
                          after: '', hasMore: false, loading: false },

                payInstallments: 1,
                payDown: '',

                customerId: null,
                saleDiscC: 0,
                // Desconto (R$ ou %): o valor efetivo é SEMPRE centavos
                // (saleDiscC / l.discC) — a % é só a forma de digitar, e a
                // conversão acontece aqui, nunca no payload (§7.6).
                saleDiscInput: '',
                saleDiscUnit: 'money',

                payments: [],        // {method, amountC, tenderedC|null}
                payMethod: null,
                payAmount: '',

                saving: false,
                lastReceipt: null,
                // Fallback de impressão: cupom na tela quando o print() do
                // navegador é ignorado (ver printReceipt).
                receiptPreview: false,
                clientSaleId: uuid(),

                held: [],
                heldOpen: false,

                docPrompt: { open: false, value: '', asked: false },
                clearConfirm: false,
                changeOverlay: { open: false, valueC: 0 },
                _changeTimer: null,

                fullscreen: false,
                clock: '',
                _clockTimer: null,

                // ── Lifecycle ─────────────────────────────────────────────
                init() {
                    var self = this;
                    this._onLookup = function (e) { self._handleLookup(e.detail || {}); };
                    this._onDone   = function (e) { self._handleSaleDone(e.detail || {}); };
                    this._onError  = function (e) { self._handleSaleError(e.detail || {}); };
                    this.$el.addEventListener('mad-pdv:lookup-result', this._onLookup);
                    this.$el.addEventListener('mad-pdv:sale-done', this._onDone);
                    this.$el.addEventListener('mad-pdv:sale-error', this._onError);

                    if (this.cfg.customer && this.cfg.customer.enabled && this.cfg.customer.defaultId) {
                        this.customerId = this.cfg.customer.defaultId;
                    }
                    this.saleDiscUnit = this.cfg.behavior.discountInput === 'percent' ? 'percent' : 'money';
                    this._loadHeld();
                    this._tickClock();
                    this._clockTimer = setInterval(function () { self._tickClock(); }, 30000);
                    this.$nextTick(function () { self.focusScan(); });
                },

                destroy() {
                    this.$el.removeEventListener('mad-pdv:lookup-result', this._onLookup);
                    this.$el.removeEventListener('mad-pdv:sale-done', this._onDone);
                    this.$el.removeEventListener('mad-pdv:sale-error', this._onError);
                    if (this._clockTimer)  clearInterval(this._clockTimer);
                    if (this._searchTimer) clearTimeout(this._searchTimer);
                    if (this._changeTimer) clearTimeout(this._changeTimer);
                },

                _tickClock() {
                    var d = new Date();
                    this.clock = d.toLocaleDateString() + ' ' +
                        d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                },

                // ── Dinheiro / formatação (centavos int) ──────────────────
                toC(v) { return Math.round((Number(v) || 0) * 100); },

                money(c) {
                    var cur = this.cfg.currency;
                    var neg = c < 0;
                    c = Math.abs(Math.round(c || 0));
                    var intPart = String(Math.floor(c / 100));
                    var dec = String(c % 100).padStart(2, '0');
                    var grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, cur.thousand);
                    return (neg ? '-' : '') + cur.symbol + ' ' + grouped + cur.decimal + dec;
                },

                _moneyPlain(c) {
                    var cur = this.cfg.currency;
                    var intPart = String(Math.floor(Math.abs(c) / 100));
                    return intPart + cur.decimal + String(Math.abs(c) % 100).padStart(2, '0');
                },

                // ── Saldo no card do catálogo ─────────────────────────────
                //
                // O lookup só manda `stock` numérico quando stock-field (ou
                // stock-model) está mapeado; sem mapeamento vem null e o card
                // não mostra linha nenhuma. Zero é número: continua aparecendo
                // (marcado), porque "acabou" é a informação mais útil do card.
                hasStock(p) {
                    return !!p && p.stock !== null && p.stock !== undefined && p.stock !== '';
                },

                stockNumber(v) {
                    var cur = this.cfg.currency;
                    var n = Number(v) || 0;
                    // Inteiro sai inteiro (12); fracionário mantém até 3 casas
                    // sem zero à toa (1,5 — nunca 1,500).
                    var txt = Math.abs(n % 1) < 1e-9
                        ? String(Math.round(n))
                        : String(parseFloat(n.toFixed(3)));
                    return txt.replace('.', cur.decimal);
                },

                stockLabel(p) {
                    if (!this.hasStock(p)) return '';
                    var tpl = this.cfg.i18n.stock_badge || 'stock: :n';
                    return String(tpl).replace(':n', this.stockNumber(p.stock));
                },

                parseMoney(str) {
                    var cur = this.cfg.currency;
                    var s = String(str || '').replace(cur.symbol, '').trim();
                    s = s.split(cur.thousand).join('');
                    s = s.split(cur.decimal).join('.');
                    s = s.replace(/[^0-9.\-]/g, '');
                    var v = parseFloat(s);
                    return isNaN(v) ? 0 : Math.round(v * 100);
                },

                /**
                 * Data/hora do cupom. O servidor manda o instante em ISO com
                 * fuso (`date('c')` — o app gerado roda em UTC); impresso cru
                 * saía "2026-09-28T17:03:00+00:00". Aqui vira a hora LOCAL do
                 * navegador do caixa, no idioma do PDV ("28/09/2026 14:03").
                 * O que não for data sai como veio.
                 */
                fmtDateTime(v) {
                    if (v === null || v === undefined || v === '') return '';
                    var d = new Date(v);
                    if (isNaN(d.getTime())) return String(v);
                    var loc = String(this.cfg.locale || '').replace(/_/g, '-') || undefined;
                    var dOpt = { day: '2-digit', month: '2-digit', year: 'numeric' };
                    var tOpt = { hour: '2-digit', minute: '2-digit' };
                    try {
                        return d.toLocaleDateString(loc, dOpt) + ' ' + d.toLocaleTimeString(loc, tOpt);
                    } catch (e) {
                        // locale inválido (RangeError): idioma do navegador
                        return d.toLocaleDateString(undefined, dOpt) + ' ' + d.toLocaleTimeString(undefined, tOpt);
                    }
                },

                fmtQty(q) {
                    q = Number(q) || 0;
                    var s = this.cfg.behavior.allowFraction
                        ? String(parseFloat(q.toFixed(3)))
                        : String(Math.round(q));
                    return s.replace('.', this.cfg.currency.decimal);
                },

                /** Percentual digitado → número (aceita vírgula). */
                _parsePct(str) {
                    var s = String(str || '').replace(this.cfg.currency.decimal, '.').replace(/[^0-9.]/g, '');
                    var v = parseFloat(s);
                    return isNaN(v) || v < 0 ? 0 : Math.min(v, 100);
                },

                /** Base × % → centavos (half-up, igual ao servidor). */
                _pctToC(baseC, pctStr) {
                    return Math.round(baseC * this._parsePct(pctStr) / 100);
                },

                /** Toggle R$/% visível só quando cfg permite os dois. */
                // ── Colunas do carrinho (Rev. 5) ──────────────────────────
                colsAt(slot) {
                    return (this.cfg.columns || []).filter(function (c) { return c.slot === slot; });
                },

                /** #, produto, qtd, preço, [desconto], total, ações + custom. */
                get cartColCount() {
                    return 6
                        + (this.cfg.behavior.discountItem ? 1 : 0)
                        + (this.cfg.columns || []).length;
                },

                /**
                 * Identidade da linha para efeito de FUSÃO.
                 *
                 * Bipar o mesmo produto duas vezes soma a quantidade — menos
                 * quando as colunas de entrada divergem: duas unidades com
                 * vendedores diferentes são duas linhas, e fundir apagaria o
                 * que o operador digitou. Coluna `merge-ignore` fica de fora.
                 */
                _mergeKey(l) {
                    var parts = [l.id, l.priceC];
                    var cols  = this.cfg.columns || [];
                    for (var i = 0; i < cols.length; i++) {
                        var c = cols[i];
                        if (!c.input || c.mergeIgnore) continue;
                        parts.push(((l.cols || {})[c.key]) || '');
                    }
                    return parts.join('\u0001');
                },

                /** Chave que a linha TERIA ao ser criada agora (defaults). */
                _candidateMergeKey(p, priceC) {
                    var parts = [p.id, priceC];
                    var cols  = this.cfg.columns || [];
                    for (var i = 0; i < cols.length; i++) {
                        var c = cols[i];
                        if (!c.input || c.mergeIgnore) continue;
                        parts.push(c.default || '');
                    }
                    return parts.join('\u0001');
                },

                /** Célula editável: grava o valor cru e espelha na exibição. */
                /** Chaves de coluna de ENTRADA da linha (as de exibição não vão). */
                // ── Parcelamento (Rev. 5) ─────────────────────────────────
                //
                // Isto é PREVIEW. O plano que vale é recalculado no servidor a
                // partir do número de parcelas e da entrada — datas e valores
                // nunca viajam no payload.
                _splitInstallments(amountC, n, residue) {
                    n = Math.max(1, n | 0);
                    if (amountC <= 0) return new Array(n).fill(0);
                    var base  = Math.floor(amountC / n);
                    var resto = amountC - base * n;
                    var parts = new Array(n).fill(base);
                    parts[residue === 'primeira' ? 0 : n - 1] += resto;
                    return parts;
                },

                installmentOptionLabel(n) {
                    if (!this.payMethod) return String(n);
                    var c = this.parseMoney(this.payAmount) - this.parseMoney(this.payDown);
                    var parts = this._splitInstallments(Math.max(0, c), n, this.payMethod.residue);
                    return n + 'x ' + this.money(parts[0]);
                },

                get installmentPreview() {
                    if (!this.payMethod || !this.payMethod.maxInstallments
                        || this.payMethod.maxInstallments <= 1) return [];
                    var n = Math.max(1, this.payInstallments | 0);
                    var c = this.parseMoney(this.payAmount) - this.parseMoney(this.payDown);
                    if (c <= 0) return [];

                    var parts = this._splitInstallments(c, n, this.payMethod.residue);
                    var first = this.payMethod.firstDueDays || 0;
                    var every = this.payMethod.intervalDays || 30;
                    var out   = [];
                    for (var i = 0; i < n; i++) {
                        var d = new Date();
                        d.setDate(d.getDate() + first + i * every);
                        out.push({
                            n: i + 1, total: n, amountC: parts[i],
                            dueLabel: d.toLocaleDateString(),
                        });
                    }
                    return out;
                },

                _editableCols(l) {
                    var out  = {};
                    var cols = this.cfg.columns || [];
                    for (var i = 0; i < cols.length; i++) {
                        var c = cols[i];
                        if (!c.input) continue;
                        out[c.key] = ((l.cols || {})[c.key]) || '';
                    }
                    return out;
                },

                setLineCol(i, c, v) {
                    var l = this.cart[i];
                    if (!l) return;
                    var val = String(v == null ? '' : v);
                    if (c.maxlength && val.length > c.maxlength) val = val.slice(0, c.maxlength);
                    if (!l.cols) l.cols = {};
                    if (!l.disp) l.disp = {};
                    l.cols[c.key] = val;
                    l.disp[c.key] = val;
                },

                get discountBoth() { return this.cfg.behavior.discountInput === 'both'; },

                _parseQty(str) {
                    var s = String(str || '').replace(this.cfg.currency.decimal, '.').replace(/[^0-9.]/g, '');
                    var v = parseFloat(s);
                    if (isNaN(v) || v <= 0) return 0;
                    return this.cfg.behavior.allowFraction ? parseFloat(v.toFixed(3)) : Math.round(v);
                },

                // ── Totais (§7.6 em centavos) ─────────────────────────────
                lineTotalC(l) { return Math.round(l.qty * l.priceC) - (l.discC || 0); },
                get subtotalC() {
                    var s = 0;
                    for (var i = 0; i < this.cart.length; i++) s += this.lineTotalC(this.cart[i]);
                    return s;
                },
                get totalC()     { return Math.max(0, this.subtotalC - this.saleDiscC); },
                get paidC()      { var s = 0; this.payments.forEach(function (p) { s += p.amountC; }); return s; },
                get remainingC() { return Math.max(0, this.totalC - this.paidC); },
                get changeNowC() {
                    var s = 0;
                    this.payments.forEach(function (p) { if (p.tenderedC) s += Math.max(0, p.tenderedC - p.amountC); });
                    return s;
                },
                get heldCount()       { return this.held.length; },
                get customerEnabled() { return !!(this.cfg.customer && this.cfg.customer.enabled); },
                get canFinalize() {
                    if (!this.cart.length || this.saving) return false;
                    if (this.paidC !== this.totalC) return false;
                    if (this.cfg.behavior.customerRequired && !this.customerId) return false;
                    return true;
                },

                // ── Scan / busca ──────────────────────────────────────────
                focusScan() {
                    var el = this.$refs.scan;
                    if (el) { el.focus(); el.select(); }
                },

                closeSearch() {
                    this.searchOpen = false;
                    this.results = [];
                    this.searchReason = '';
                    this.hi = 0;
                },

                /** `3*termo` → multiplicador de caixa. */
                _splitMult(term) {
                    var m = /^(\d+(?:[.,]\d+)?)\s*\*\s*(.+)$/.exec(term);
                    if (!m) return { mult: 1, term: term };
                    var q = this._parseQty(m[1]);
                    return { mult: q > 0 ? q : 1, term: m[2].trim() };
                },

                onScanKey(e) {
                    if (e.key === 'ArrowDown' && this.searchOpen) {
                        e.preventDefault();
                        this.hi = Math.min(this.hi + 1, this.results.length - 1);
                        return;
                    }
                    if (e.key === 'ArrowUp' && this.searchOpen) {
                        e.preventDefault();
                        this.hi = Math.max(this.hi - 1, 0);
                        return;
                    }
                    if (e.key === 'Escape') {
                        e.preventDefault();
                        this.closeSearch();
                        this.scanTerm = '';
                        return;
                    }
                    if (e.key !== 'Enter') return;
                    e.preventDefault();

                    if (this.searchOpen && this.results.length) {
                        this.pick(this.hi);
                        return;
                    }
                    var raw = this.scanTerm.trim();
                    if (raw === '') return;
                    var split = this._splitMult(raw);
                    if (this._searchTimer) clearTimeout(this._searchTimer);
                    this._lookup(split.term, 'scan', split.mult);
                },

                onScanInput() {
                    var self = this;
                    if (this._searchTimer) clearTimeout(this._searchTimer);
                    var split = this._splitMult(this.scanTerm.trim());
                    if (split.term.length < this.cfg.behavior.searchMinLength) {
                        this.closeSearch();
                        return;
                    }
                    this._searchTimer = setTimeout(function () {
                        self._lookup(split.term, 'search', split.mult);
                    }, this.cfg.behavior.searchDebounceMs);
                },

                _lookup(term, mode, mult, channel, after) {
                    var id = ++this._reqSeq;
                    this._scanMult[id]    = mult || 1;
                    // O canal fica SÓ no client: com o modal aberto a pistola
                    // continua bipando, e as duas origens dividiam o contador de
                    // out-of-order — a resposta do scan sobrescrevia a grade do
                    // modal, e a da página seguinte era descartada por ter id
                    // menor. Zero mudança no contrato do fio.
                    this._reqChannel[id] = channel || 'scan';
                    wireCall(this.$el, this.cfg.wire.lookup, [term, mode, id, after || ''])
                        .catch(function (err) { console.error('[mad-pdv] lookup:', err); });
                },

                _handleLookup(d) {
                    var id  = d.requestId || 0;
                    var ch  = this._reqChannel[id] || 'scan';
                    delete this._reqChannel[id];

                    if (ch === 'picker') { this._handlePickerLookup(d, id); return; }

                    if (id < this._lastHandled) return; // out-of-order
                    this._lastHandled = id;
                    var mult = this._scanMult[d.requestId] || 1;
                    delete this._scanMult[d.requestId];

                    var items = d.items || [];
                    if (d.mode === 'scan') {
                        if (items.length === 1) {
                            this.addLine(items[0], mult);
                            this.scanTerm = '';
                            this.closeSearch();
                            this.focusScan();
                            return;
                        }
                        if (items.length > 1) {
                            this.results = items;
                            this.searchOpen = true;
                            this.searchReason = '';
                            this.hi = 0;
                            this._curMult = mult;
                            return;
                        }
                        // 0 itens: motivo distinto (inativo ≠ sem preço ≠ não-encontrado)
                        this.results = [];
                        var reasonMap = {
                            'inactive': this.cfg.i18n.inactive,
                            'no-price': this.cfg.i18n.no_price,
                            'not-found': this.cfg.i18n.not_found,
                        };
                        this.searchReason = reasonMap[d.reason] || this.cfg.i18n.not_found;
                        this.searchOpen = true;
                        var self = this;
                        setTimeout(function () { self.closeSearch(); }, 1600);
                        return;
                    }

                    // search
                    this.results = items;
                    this.searchReason = items.length ? '' : this.cfg.i18n.no_results;
                    this.searchOpen = true;
                    this.hi = 0;
                    this._curMult = mult;
                },

                pick(i) {
                    var p = this.results[i];
                    if (!p) return;
                    this.addLine(p, this._curMult || 1);
                    this._curMult = 1;
                    this.scanTerm = '';
                    this.closeSearch();
                    this.focusScan();
                },

                // ── Carrinho ──────────────────────────────────────────────
                addLine(p, mult) {
                    var qty = mult || 1;
                    if (!this.cfg.behavior.allowFraction) qty = Math.max(1, Math.round(qty));

                    var priceC = this.toC(p.price);
                    // Linha candidata: mesmos id/preço e as colunas de entrada
                    // nos DEFAULTS (o estado de quem acabou de ser bipado).
                    var candKey = this._candidateMergeKey(p, priceC);
                    for (var i = 0; i < this.cart.length && this.cfg.behavior.mergeLines !== 'off'; i++) {
                        var l = this.cart[i];
                        if (this._mergeKey(l) === candKey) {
                            this._setLineQty(l, l.qty + qty);
                            this.selLine = i;
                            return;
                        }
                    }
                    var line = {
                        // uuid, não sequencial: o contador zera no F5 e uma venda
                        // retomada do hold traz uids antigos — as chaves do x-for
                        // colidiam, e com coluna de entrada isso põe o valor
                        // digitado na LINHA ERRADA.
                        uid: uuid(),
                        id: p.id,
                        name: p.name,
                        code: p.code || '',
                        barcode: p.barcode || '',
                        unit: p.unit || '',
                        image: p.image || '',
                        priceC: priceC,
                        qty: 0,
                        discC: 0,
                        discInput: '',
                        discUnit: this.cfg.behavior.discountInput === 'percent' ? 'percent' : 'money',
                        stock: (p.stock === null || p.stock === undefined) ? null : Number(p.stock),
                        stockWarn: false,
                        // A "whitelist" deixa de ser uma lista de nomes no
                        // código e passa a ser o conjunto de colunas que o
                        // SERVIDOR declarou: coluna nova chega sozinha.
                        cols: Object.assign({}, p.cols || {}),
                        disp: Object.assign({}, p.disp || {}),
                    };
                    this.cart.push(line);
                    this._setLineQty(line, qty);
                    this.selLine = this.cart.length - 1;
                },

                _setLineQty(l, qty) {
                    if (!this.cfg.behavior.allowFraction) qty = Math.max(1, Math.round(qty));
                    var mode = this.cfg.behavior.stockMode;
                    if (mode === 'block' && l.stock !== null && qty > l.stock) {
                        qty = Math.max(this.cfg.behavior.allowFraction ? 0 : 1, l.stock);
                    }
                    l.qty = qty;
                    l.stockWarn = mode !== 'off' && l.stock !== null && l.qty > l.stock;
                },

                incQty(i, d) {
                    var l = this.cart[i];
                    if (!l) return;
                    var next = l.qty + d;
                    if (next <= 0) { this.removeLine(i); return; }
                    this._setLineQty(l, next);
                },

                setQty(i, v) {
                    var l = this.cart[i];
                    if (!l) return;
                    var q = this._parseQty(v);
                    if (q <= 0) { this.removeLine(i); return; }
                    this._setLineQty(l, q);
                },

                setLinePrice(i, v) {
                    var l = this.cart[i];
                    if (!l || !this.cfg.behavior.allowPriceOverride) return;
                    var c = this.parseMoney(v);
                    if (c > 0) l.priceC = c;
                },

                setLineDisc(i, v) {
                    var l = this.cart[i];
                    if (!l || !this.cfg.behavior.discountItem) return;
                    l.discInput = v;
                    var gross = Math.round(l.qty * l.priceC);
                    var c = (l.discUnit || 'money') === 'percent'
                        ? this._pctToC(gross, v)
                        : Math.max(0, this.parseMoney(v));
                    l.discC = Math.min(Math.max(0, c), gross);
                },

                /** Placeholder do campo conforme a unidade ativa. */
                discPlaceholder(unit) {
                    return (unit || 'money') === 'percent' ? '0' : '0,00';
                },

                removeLine(i) {
                    this.cart.splice(i, 1);
                    if (this.selLine >= this.cart.length) this.selLine = this.cart.length - 1;
                    this.focusScan();
                },

                setSaleDisc(v) {
                    if (!this.cfg.behavior.discountTotal) return;
                    this.saleDiscInput = v;
                    var c = this.saleDiscUnit === 'percent'
                        ? this._pctToC(this.subtotalC, v)
                        : Math.max(0, this.parseMoney(v));
                    this.saleDiscC = Math.min(Math.max(0, c), this.subtotalC);
                },

                /** Troca R$ ⇄ % preservando o VALOR (recalcula o texto). */
                toggleSaleDiscUnit() {
                    if (!this.discountBoth) return;
                    var next = this.saleDiscUnit === 'money' ? 'percent' : 'money';
                    this.saleDiscUnit = next;
                    if (this.saleDiscC <= 0) { this.saleDiscInput = ''; return; }
                    this.saleDiscInput = next === 'percent'
                        ? (this.subtotalC > 0
                            ? String(Math.round(this.saleDiscC / this.subtotalC * 1000) / 10).replace('.', this.cfg.currency.decimal)
                            : '')
                        : this._moneyPlain(this.saleDiscC);
                },

                toggleLineDiscUnit(i) {
                    var l = this.cart[i];
                    if (!l || !this.discountBoth) return;
                    var next = (l.discUnit || 'money') === 'money' ? 'percent' : 'money';
                    l.discUnit = next;
                    var gross = Math.round(l.qty * l.priceC);
                    if (!l.discC) { l.discInput = ''; return; }
                    l.discInput = next === 'percent'
                        ? (gross > 0
                            ? String(Math.round(l.discC / gross * 1000) / 10).replace('.', this.cfg.currency.decimal)
                            : '')
                        : this._moneyPlain(l.discC);
                },

                askClear() {
                    if (this.cart.length) this.clearConfirm = true;
                },

                doClear() {
                    this.clearConfirm = false;
                    this._resetSale();
                    this.focusScan();
                },

                // ── Pagamento ─────────────────────────────────────────────
                payLabel(method) {
                    for (var i = 0; i < this.cfg.payments.length; i++) {
                        if (this.cfg.payments[i].method === method) return this.cfg.payments[i].label;
                    }
                    return method;
                },

                choosePay(p) {
                    if (!this.cart.length || this.remainingC <= 0) return;
                    this.payMethod = p;
                    this.payAmount = this._moneyPlain(this.remainingC);
                    this.payInstallments = 1;
                    this.payDown = '';
                    var self = this;
                    this.$nextTick(function () {
                        var el = self.$refs.payAmount;
                        if (el) { el.focus(); el.select(); }
                    });
                },

                addPayment() {
                    if (!this.payMethod) return;
                    var c = this.parseMoney(this.payAmount);
                    if (c <= 0) return;
                    var entry = { method: this.payMethod.method, amountC: 0, tenderedC: null };
                    if (this.payMethod.allowChange && c > this.remainingC) {
                        entry.amountC = this.remainingC;
                        entry.tenderedC = c;
                    } else {
                        entry.amountC = Math.min(c, this.remainingC);
                    }
                    if (entry.amountC <= 0) return;
                    if (this.payMethod.maxInstallments > 1) {
                        entry.installments = Math.max(1, this.payInstallments | 0);
                        entry.downC        = Math.max(0, this.parseMoney(this.payDown));
                    }
                    this.payments.push(entry);
                    this.payMethod = null;
                    this.payAmount = '';
                    if (this.remainingC > 0) return;
                    this.focusScan();
                },

                // ── Catálogo de produtos (Rev. 5) ─────────────────────────
                openPicker() {
                    this.picker.open  = true;
                    this.picker.term  = '';
                    this.picker.page  = 0;
                    this.picker.stack = [''];       // cursor de cada página vista
                    this._pickerFetch('');
                    var self = this;
                    this.$nextTick(function () {
                        if (self.$refs.pickerSearch) self.$refs.pickerSearch.focus();
                    });
                },

                closePicker() {
                    this.picker.open = false;
                    this.focusScan();
                },

                pickerSearch() {
                    this.picker.page  = 0;
                    this.picker.stack = [''];
                    this._pickerFetch('');
                },

                pickerNext() {
                    if (!this.picker.hasMore) return;
                    this.picker.stack.push(this.picker.after);
                    this.picker.page++;
                    this._pickerFetch(this.picker.after);
                },

                pickerPrev() {
                    if (!this.picker.page) return;
                    this.picker.stack.pop();
                    this.picker.page--;
                    this._pickerFetch(this.picker.stack[this.picker.page] || '');
                },

                pickerPick(p) {
                    this.addLine(p, 1);
                    this.closePicker();
                },

                pickerPickFirst() {
                    if (this.picker.items.length) this.pickerPick(this.picker.items[0]);
                },

                _pickerFetch(after) {
                    this.picker.loading = true;
                    this._lookup(this.picker.term, 'browse', 1, 'picker', after);
                },

                _handlePickerLookup(d, id) {
                    // Contador próprio: o do scan não pode descartar a resposta
                    // do modal (e vice-versa).
                    if (id < this._lastPicker) return;
                    this._lastPicker    = id;
                    this.picker.loading = false;
                    this.picker.items   = d.items || [];
                    this.picker.after   = d.after || '';
                    this.picker.hasMore = !!d.hasMore;
                },

                removePayment(i) {
                    this.payments.splice(i, 1);
                    // A forma aberta ficou com o valor do restante ANTIGO no campo;
                    // re-semear evita o operador confirmar um valor que já não fecha.
                    if (this.payMethod) this.payAmount = this._moneyPlain(this.remainingC);
                    else this.focusScan();
                },

                // ── Finalização (§7.2) ────────────────────────────────────
                finalize() {
                    if (!this.cart.length || this.saving) return;
                    if (this.remainingC > 0) {
                        // F10 com restante: abre a 1ª forma (fluxo de teclado)
                        this.choosePay(this.payMethod || this.cfg.payments[0]);
                        return;
                    }
                    if (this.cfg.behavior.customerRequired && !this.customerId) {
                        this._focusCustomer();
                        return;
                    }
                    if (this.cfg.behavior.askDocument && !this.docPrompt.asked) {
                        this.docPrompt.open = true;
                        var self = this;
                        this.$nextTick(function () {
                            if (self.$refs.docInput) self.$refs.docInput.focus();
                        });
                        return;
                    }
                    this._send();
                },

                confirmDoc() {
                    this.docPrompt.open = false;
                    this.docPrompt.asked = true;
                    this._send();
                },

                skipDoc() {
                    this.docPrompt.value = '';
                    this.docPrompt.open = false;
                    this.docPrompt.asked = true;
                    this._send();
                },

                _send() {
                    var self = this;
                    var payload = {
                        clientSaleId: this.clientSaleId,
                        customerId: this.customerId ? Number(this.customerId) : null,
                        document: this.docPrompt.value || null,
                        saleDiscount: this.saleDiscC / 100,
                        items: this.cart.map(function (l) {
                            return {
                                productId: l.id,
                                qty: l.qty,
                                unitPrice: l.priceC / 100,
                                discount: (l.discC || 0) / 100,
                                total: self.lineTotalC(l) / 100,
                                // Só as colunas de ENTRADA viajam: as de exibição
                                // o servidor re-resolve do produto e ignora o que
                                // vier daqui.
                                cols: self._editableCols(l),
                            };
                        }),
                        payments: this.payments.map(function (p) {
                            return {
                                method: p.method,
                                amount: p.amountC / 100,
                                tendered: p.tenderedC ? p.tenderedC / 100 : undefined,
                                // Só o QUANTAS, nunca o quanto de cada parcela:
                                // o servidor recalcula o plano inteiro (§7.5).
                                installments: p.installments || 1,
                                downPayment: (p.downC || 0) / 100,
                            };
                        }),
                        totals: {
                            subtotal: this.subtotalC / 100,
                            discount: this.saleDiscC / 100,
                            total: this.totalC / 100,
                            paid: this.paidC / 100,
                            change: this.changeNowC / 100,
                        },
                    };
                    this.saving = true;
                    wireCall(this.$el, this.cfg.wire.finalize, [JSON.stringify(payload)])
                        .catch(function (err) {
                            console.error('[mad-pdv] finalize:', err);
                        })
                        .finally(function () { self.saving = false; });
                },

                _handleSaleDone(d) {
                    this.saving = false;
                    // O cupom lê `lastReceipt.number`; servidor anterior ao fix
                    // mandava o número só no evento ("Venda nº undefined").
                    this.lastReceipt = d.receipt
                        ? Object.assign({}, d.receipt, {
                            number: String(d.receipt.number != null && d.receipt.number !== ''
                                ? d.receipt.number
                                : (d.number != null && d.number !== '' ? d.number : (d.saleId != null ? d.saleId : ''))),
                        })
                        : null;
                    var changeC = this.toC(d.change);
                    this._resetSale();
                    if (changeC > 0) {
                        this.changeOverlay = { open: true, valueC: changeC };
                        var self = this;
                        if (this._changeTimer) clearTimeout(this._changeTimer);
                        this._changeTimer = setTimeout(function () { self.closeChange(); }, 4000);
                    }
                    if (this.cfg.behavior.autoPrint && this.lastReceipt) {
                        this.printReceipt();
                    }
                    this.focusScan();
                },

                closeChange() {
                    if (this._changeTimer) clearTimeout(this._changeTimer);
                    this.changeOverlay.open = false;
                    this.focusScan();
                },

                _handleSaleError(d) {
                    this.saving = false;
                    var code = d.code || 'internal';
                    var details = d.details || {};
                    var self = this;

                    if (code === 'price-changed' && details.items) {
                        details.items.forEach(function (u) {
                            self.cart.forEach(function (l) {
                                if (l.id === u.productId) l.priceC = self.toC(u.newPrice);
                            });
                        });
                        // pagamentos podem ter ficado errados com o novo total
                        this.payments = [];
                        this.focusScan();
                        return;
                    }
                    if ((code === 'insufficient-stock' || code === 'conflict') && details.items) {
                        details.items.forEach(function (u) {
                            self.cart.forEach(function (l) {
                                if (l.id === u.productId) {
                                    l.stockWarn = true;
                                    if (u.available !== undefined) l.stock = Number(u.available);
                                }
                            });
                        });
                        this.focusScan();
                        return;
                    }
                    if (code === 'no-price' && details.items) {
                        // Linha de preço sumiu entre o bip e o finalize —
                        // itens saem do carrinho (não têm como ser vendidos).
                        var noPriceIds = details.items.map(function (u) { return u.productId; });
                        this.cart = this.cart.filter(function (l) { return noPriceIds.indexOf(l.id) === -1; });
                        this.payments = [];
                        this.focusScan();
                        return;
                    }
                    if (code === 'customer-required') { this._focusCustomer(); return; }
                    if (code === 'payment-incomplete' || code === 'payment-mismatch'
                        || code === 'invalid-payment-method') {
                        if (code === 'payment-mismatch') this.payments = [];
                        this.choosePay(this.cfg.payments[0]);
                        return;
                    }
                    this.focusScan();
                },

                _focusCustomer() {
                    var el = this.$refs.customer;
                    if (el) el.focus();
                },

                _resetSale() {
                    this.cart = [];
                    this.payments = [];
                    this.payMethod = null;
                    this.payAmount = '';
                    this.saleDiscC = 0;
                    this.saleDiscInput = '';
                    this.selLine = -1;
                    this.docPrompt = { open: false, value: '', asked: false };
                    this.clientSaleId = uuid();

                    var def = (this.cfg.customer && this.cfg.customer.enabled)
                        ? (this.cfg.customer.defaultId || null) : null;
                    this.customerId = def;
                    var sel = this.$refs.customer;
                    if (sel) {
                        sel.value = def ? String(def) : '';
                        sel.dispatchEvent(new Event('change', { bubbles: true }));
                        this.customerId = def; // o change acima re-seta via @change
                    }
                },

                // ── Hold / retomar (§9 — sessionStorage) ──────────────────
                _heldKey() { return 'mad-pdv:' + this.cfg.id + ':held'; },

                _loadHeld() {
                    try {
                        var raw = root.sessionStorage.getItem(this._heldKey());
                        var data = raw ? JSON.parse(raw) : null;
                        // v:1 continua aceito — descartar a venda em espera do
                        // operador num upgrade de framework é hostil; o que
                        // falta (cols/disp) é preenchido no resume.
                        var okV   = data && (data.v === 1 || data.v === 2);
                        this.held = (okV && Array.isArray(data.sales)) ? data.sales : [];
                    } catch (e) {
                        this.held = [];
                    }
                },

                _saveHeld() {
                    try {
                        root.sessionStorage.setItem(this._heldKey(),
                            JSON.stringify({ v: 2, sales: this.held }));
                    } catch (e) { /* quota/aba anônima: hold degrada em silêncio */ }
                },

                toggleHeld() { this.heldOpen = !this.heldOpen; },

                holdCurrent() {
                    if (!this.cfg.behavior.holdSales) return;
                    if (!this.cart.length) { this.heldOpen = true; return; }
                    if (this.held.length >= this.cfg.behavior.holdLimit) {
                        console.warn('[mad-pdv] ' + this.cfg.i18n.hold_limit_reached);
                        this.heldOpen = true;
                        return;
                    }
                    var d = new Date();
                    this.held.push({
                        holdId: uuid(),
                        label: d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
                            + ' · ' + this.cart.length + ' ' + (this.cfg.i18n.items || 'itens').toLowerCase()
                            + ' · ' + this.money(this.totalC),
                        createdAt: d.toISOString(),
                        sale: {
                            clientSaleId: this.clientSaleId,
                            customerId: this.customerId,
                            document: this.docPrompt.value,
                            saleDiscC: this.saleDiscC,
                            cart: JSON.parse(JSON.stringify(this.cart)),
                            payments: JSON.parse(JSON.stringify(this.payments)),
                        },
                    });
                    this._saveHeld();
                    this._resetSale();
                    this.focusScan();
                },

                resumeHeld(holdId) {
                    var idx = this.held.findIndex(function (h) { return h.holdId === holdId; });
                    if (idx < 0) return;
                    if (this.cart.length) {
                        // caixa ocupado: segura a atual antes (se couber)
                        if (this.held.length >= this.cfg.behavior.holdLimit) return;
                        this.holdCurrent();
                        idx = this.held.findIndex(function (h) { return h.holdId === holdId; });
                    }
                    var h = this.held.splice(idx, 1)[0];
                    this._saveHeld();

                    var s = h.sale || {};
                    this.cart = this._backfillCart(s.cart || []);
                    this.payments = s.payments || [];
                    this.saleDiscC = s.saleDiscC || 0;
                    this.customerId = s.customerId || null;
                    this.docPrompt = { open: false, value: s.document || '', asked: false };
                    this.clientSaleId = s.clientSaleId || uuid();
                    this.heldOpen = false;
                    this.focusScan();
                },

                /**
                 * Venda retomada de um hold anterior ao upgrade não tem `cols`
                 * (nem uid único). Preenche com os defaults das colunas atuais
                 * em vez de descartar a venda do operador.
                 */
                _backfillCart(cart) {
                    var cols = this.cfg.columns || [];
                    var self = this;
                    return (cart || []).map(function (l) {
                        if (!l.uid || String(l.uid).indexOf('-') === -1) l.uid = uuid();
                        if (!l.cols) l.cols = {};
                        if (!l.disp) l.disp = {};
                        cols.forEach(function (c) {
                            if (l.cols[c.key] === undefined) {
                                l.cols[c.key] = c.input ? (c.default || '') : '';
                                l.disp[c.key] = l.cols[c.key];
                            }
                        });
                        return l;
                    });
                },

                deleteHeld(holdId) {
                    this.held = this.held.filter(function (h) { return h.holdId !== holdId; });
                    this._saveHeld();
                },

                // ── Impressão (§8) ────────────────────────────────────────

                /**
                 * Em iframe com `sandbox` sem `allow-modals` (o preview do
                 * MadBuilder é assim) o navegador IGNORA `print()` — sem
                 * exceção, só um aviso no console. O único sinal disponível é
                 * que `beforeprint` não dispara: sem ele o cupom vai pra tela,
                 * em vez de o operador clicar em imprimir e nada acontecer.
                 */
                printReceipt() {
                    if (!this.lastReceipt || this.cfg.behavior.printMode === 'off') return;

                    var framed    = root.self !== root.top;
                    var canDetect = framed && ('onbeforeprint' in root);
                    var fired     = false;
                    var mark      = function () { fired = true; };

                    if (canDetect) root.addEventListener('beforeprint', mark);
                    try {
                        root.print();
                    } catch (e) {
                        fired = false;
                    }
                    if (canDetect) root.removeEventListener('beforeprint', mark);

                    if (canDetect && !fired) {
                        console.warn('[mad-pdv] print() ignorado — iframe sandbox sem "allow-modals". Cupom exibido na tela.');
                        this.receiptPreview = true;
                    }
                },

                closeReceiptPreview() {
                    this.receiptPreview = false;
                    this.focusScan();
                },

                // ── Ações custom (§7.4) ───────────────────────────────────
                runAction(a) {
                    if (a.confirm && !root.confirm(a.confirm)) return;
                    var self = this;
                    var summary = {
                        cart: {
                            items: this.cart.map(function (l) {
                                return {
                                    productId: l.id, qty: l.qty,
                                    unitPrice: l.priceC / 100,
                                    discount: (l.discC || 0) / 100,
                                    total: self.lineTotalC(l) / 100,
                                };
                            }),
                            totals: {
                                subtotal: this.subtotalC / 100,
                                discount: this.saleDiscC / 100,
                                total: this.totalC / 100,
                            },
                        },
                    };
                    wireCall(this.$el, a.method, summary)
                        .catch(function (err) { console.error('[mad-pdv] action:', err); });
                },

                // ── Fullscreen / hotkeys (§5.2) ───────────────────────────
                toggleFullscreen() { this.fullscreen = !this.fullscreen; },

                _isTyping() {
                    var el = root.document.activeElement;
                    if (!el) return false;
                    var tag = el.tagName;
                    return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
                        || el.isContentEditable;
                },

                onHotkey(e) {
                    if (e.defaultPrevented) return;

                    // Hotkey do catálogo é configurável (default F2 — a tecla
                    // que a legenda do rodapé anuncia) — checada antes do
                    // switch justamente por não ser tecla fixa.
                    if (this.cfg.behavior.productPicker
                        && e.key === (this.cfg.behavior.productPickerHotkey || 'F2')) {
                        e.preventDefault();
                        this.picker.open ? this.closePicker() : this.openPicker();
                        return;
                    }

                    switch (e.key) {
                        case 'F2':
                            e.preventDefault(); this.focusScan(); return;
                        case 'F4':
                            if (this.customerEnabled) { e.preventDefault(); this._focusCustomer(); }
                            return;
                        case 'F6':
                            if (this.cfg.behavior.discountTotal && this.$refs.saleDisc) {
                                e.preventDefault();
                                this.$refs.saleDisc.focus();
                                this.$refs.saleDisc.select();
                            }
                            return;
                        case 'F8':
                            if (this.cfg.behavior.holdSales) {
                                e.preventDefault();
                                this.cart.length ? this.holdCurrent() : this.toggleHeld();
                            }
                            return;
                        case 'F10':
                            e.preventDefault(); this.finalize(); return;
                        case 'Escape':
                            if (this.receiptPreview)     { this.closeReceiptPreview(); return; }
                            if (this.changeOverlay.open) { this.closeChange(); return; }
                            if (this.docPrompt.open)     { this.docPrompt.open = false; return; }
                            if (this.clearConfirm)       { this.clearConfirm = false; return; }
                            if (this.heldOpen)           { this.heldOpen = false; return; }
                            if (this.payMethod)          { this.payMethod = null; return; }
                            // Desfazer de teclado: sem forma aberta, Esc tira a última
                            // entrada. Antes não fazia nada nesse estado, e a única
                            // saída era mirar o X com o mouse.
                            if (this.picker.open)        { this.closePicker(); return; }
                            if (this.payments.length)    { this.removePayment(this.payments.length - 1); return; }
                            return;
                        case 'Delete':
                            if (this._isTyping()) return;
                            e.preventDefault();
                            if (e.ctrlKey) { this.askClear(); return; }
                            if (this.selLine >= 0 && this.selLine < this.cart.length) {
                                this.removeLine(this.selLine);
                            }
                            return;
                    }

                    // Hotkeys das formas de pagamento (dígitos) + ações custom
                    if (this._isTyping()) return;
                    var key = e.key;
                    for (var i = 0; i < this.cfg.payments.length; i++) {
                        var p = this.cfg.payments[i];
                        if (p.hotkey && p.hotkey === key && this.cart.length && this.remainingC > 0) {
                            e.preventDefault();
                            this.choosePay(p);
                            return;
                        }
                    }
                    for (var j = 0; j < this.cfg.actions.length; j++) {
                        var a = this.cfg.actions[j];
                        if (a.hotkey && a.hotkey === key) {
                            e.preventDefault();
                            this.runAction(a);
                            return;
                        }
                    }
                },
            };
        });
    }

    if (root.Alpine) {
        registerPdv();
    } else {
        root.document.addEventListener('alpine:init', registerPdv);
    }
})(window);
