/**
 * mad-pdv.js — runtime client do <mad-pdv> (frente de caixa).
 *
 * Carrinho 100% client-side em CENTAVOS INTEIROS (§7.6 — nunca float até o
 * payload); servidor tocado só no lookup e no finalize. Config vem do x-data
 * (montada por MadPdvComponent::getClientConfig — sem model/coluna/conexão).
 * Eventos do servidor (CustomEvent no container [data-mad-pdv]):
 *   mad-pdv:lookup-result | mad-pdv:sale-done | mad-pdv:sale-error |
 *   mad-pdv:compose-result
 * Evento que o PDV DISPARA para a tela (sobe até document, cancelável):
 *   mad-pdv:compose — item montável escolhido; a tela abre o montador dela e
 *   chama detail.confirm(escolhas) ou detail.cancel().
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

    /**
     * MadPdvTax — gêmeo JS de \Mad\Pdv\PdvTax (imposto por linha, centavos
     * inteiros, half-up, desconto da venda rateado antes do imposto, resíduo
     * na última linha de peso > 0). O carrinho calcula aqui; o servidor
     * RECALCULA na finalização e é a verdade. Os dois rodam os mesmos vetores
     * (tests/js/pdv-tax.test.mjs × MadPdvTaxTest) — mudou um, mude o outro.
     *
     * Multiplicação e divisão passam por BigInt: centavos × alíquota × 10⁶
     * estoura os 2⁵³ do Number em valores grandes, e o PHP (int 64) não.
     */
    var MadPdvTax = (function () {
        var RATE_SCALE = 10000;

        function divRoundBig(num, den) {
            if (den === 0n) throw new RangeError('Division by zero');
            var neg = (num < 0n) !== (den < 0n);
            if (num < 0n) num = -num;
            if (den < 0n) den = -den;
            var q = num / den;
            if ((num % den) * 2n >= den) q += 1n;
            return neg ? -q : q;
        }

        function divRound(num, den) {
            return Number(divRoundBig(BigInt(num), BigInt(den)));
        }

        /** Número em string fixa (espelha o sprintf('%.6F') do PHP). */
        function fixed6(v) { return v.toFixed(6); }

        function fromDecimal(value) {
            if (value === null || value === undefined || value === '') return 0;
            if (typeof value === 'number' && Number.isInteger(value)) return value * 100;
            var s = typeof value === 'number' ? fixed6(value) : String(value).trim();
            s = s.replace(/[, ]/g, '');
            var m = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(s);
            if (!m) throw new TypeError('Invalid amount: ' + value);
            var neg = m[1] === '-';
            var intPart = m[2] === '' ? '0' : m[2];
            var dec = ((m[3] || '') + '000').slice(0, Math.max(3, (m[3] || '').length));
            var cents = Number(BigInt(intPart) * 100n + BigInt(dec.slice(0, 2)));
            if (Number(dec.charAt(2)) >= 5) cents++;
            return neg ? -cents : cents;
        }

        function toDecimal(cents) {
            var abs = Math.abs(cents);
            return (cents < 0 ? '-' : '') + Math.floor(abs / 100) + '.' + String(abs % 100).padStart(2, '0');
        }

        function rateUnits(ratePct) {
            if (ratePct === null || ratePct === undefined || ratePct === '') return 0;
            var s = typeof ratePct === 'number' ? fixed6(ratePct) : String(ratePct).trim();
            var m = /^(\d*)(?:\.(\d*))?$/.exec(s);
            if (!m) throw new TypeError('Invalid rate: ' + ratePct);
            var dec = ((m[2] || '') + '00000').slice(0, Math.max(5, (m[2] || '').length));
            var units = Number(m[1] === '' ? 0 : m[1]) * RATE_SCALE + Number(dec.slice(0, 4));
            if (Number(dec.charAt(4)) >= 5) units++;
            return units;
        }

        function rateString(units) {
            var intPart = Math.floor(units / RATE_SCALE);
            var dec = String(units % RATE_SCALE).padStart(4, '0').replace(/0+$/, '');
            return dec === '' ? String(intPart) : intPart + '.' + dec;
        }

        function normalizeRate(raw) {
            if (raw === null || raw === undefined || raw === '' || typeof raw === 'boolean' || typeof raw === 'object') return '0';
            var s = typeof raw === 'string' ? raw.trim().replace(',', '.') : raw;
            try { return rateString(rateUnits(s)); } catch (e) { return '0'; }
        }

        function mulRate(cents, ratePct) {
            return Number(divRoundBig(BigInt(cents) * BigInt(rateUnits(ratePct)), BigInt(100 * RATE_SCALE)));
        }

        function netFromGross(grossCents, ratePct) {
            var den = BigInt(100 * RATE_SCALE);
            return Number(divRoundBig(BigInt(grossCents) * den, den + BigInt(rateUnits(ratePct))));
        }

        /** Rateio proporcional; resíduo na ÚLTIMA parte de peso > 0. Array OU objeto. */
        function allocate(total, weights) {
            var keys = Object.keys(weights);
            var out = Array.isArray(weights) ? weights.map(function () { return 0; }) : {};
            keys.forEach(function (k) { out[k] = 0; });
            var sum = 0, lastKey = null;
            keys.forEach(function (k) { if (weights[k] > 0) { sum += weights[k]; lastKey = k; } });
            if (sum === 0 || lastKey === null) return out;
            var given = 0;
            for (var i = 0; i < keys.length; i++) {
                var k = keys[i], w = weights[k];
                if (w <= 0) continue;
                if (k === lastKey) { out[k] = total - given; break; }
                // intdiv do PHP trunca em direção ao zero — BigInt também.
                var part = Number(BigInt(total) * BigInt(w) / BigInt(sum));
                out[k] = part;
                given += part;
            }
            return out;
        }

        function lines(ls, inclusive, discountCents) {
            var weights = [], subtotal = 0;
            ls.forEach(function (l, k) {
                var amount = l.amount | 0;
                if (amount < 0) throw new RangeError('Line amount cannot be negative');
                weights[k] = amount;
                subtotal += amount;
            });
            var discount = Math.max(0, Math.min(discountCents || 0, subtotal));
            var alloc = allocate(discount, weights);
            var out = [], tNet = 0, tTax = 0, tGross = 0;
            ls.forEach(function (l, k) {
                var amount = l.amount | 0;
                var rate = String(l.rate === undefined || l.rate === null ? '0' : l.rate);
                var taxable = (l.taxable === undefined ? true : !!l.taxable) && rateUnits(rate) > 0;
                var base = amount - (alloc[k] || 0);
                var net, tax, gross;
                if (Array.isArray(l.components) && l.components.length) {
                    var cl = componentLine(l, amount, alloc[k] || 0, rate, inclusive);
                    out[k] = cl;
                    tNet += cl.net; tTax += cl.tax; tGross += cl.gross;
                    return;
                }
                if (!taxable) {
                    net = base; tax = 0; gross = base; rate = '0';
                } else if (inclusive) {
                    gross = base; net = netFromGross(base, rate); tax = gross - net;
                } else {
                    net = base; tax = mulRate(base, rate); gross = net + tax;
                }
                out[k] = { amount: amount, discount: alloc[k] || 0, net: net, tax: tax, gross: gross, rate: rate };
                tNet += net; tTax += tax; tGross += gross;
            });
            return { lines: out, subtotal: subtotal, discount: discount, net: tNet, tax: tTax, total: tGross };
        }

        /** Linha com partes de alíquotas diferentes — espelha PdvTax::componentLine. */
        function componentLine(l, amount, lineDisc, lineRate, inclusive) {
            var taxableLine = l.taxable === undefined ? true : !!l.taxable;
            var amounts = [], rates = [], sum = 0;
            l.components.forEach(function (c, i) {
                var a = (c && c.amount) | 0;
                if (a < 0) throw new RangeError('Component amount cannot be negative');
                var r = c && c.rate !== undefined && c.rate !== null ? normalizeRate(c.rate) : normalizeRate(lineRate);
                amounts[i] = a;
                rates[i] = (taxableLine && rateUnits(r) > 0) ? r : '0';
                sum += a;
            });
            if (sum !== amount) throw new RangeError('Components must add up to the line amount');
            var cDisc = allocate(lineDisc, amounts);
            var order = [], groups = {};
            rates.forEach(function (r, i) {
                if (!groups[r]) { groups[r] = []; order.push(r); }
                groups[r].push(i);
            });
            var comps = [], parts = [];
            order.forEach(function (r) {
                var idx = groups[r], bases = {}, baseG = 0, keys = [];
                idx.forEach(function (i) { bases[i] = amounts[i] - cDisc[i]; baseG += bases[i]; });
                var taxG = r === '0' ? 0 : (inclusive ? baseG - netFromGross(baseG, r) : mulRate(baseG, r));
                // allocate pela ordem dos índices (iguais ao PHP): lista densa.
                var w = idx.map(function (i) { return bases[i]; });
                var tx = allocate(taxG, w);
                idx.forEach(function (i, j) {
                    var t = tx[j] || 0;
                    var net = inclusive ? bases[i] - t : bases[i];
                    comps[i] = { amount: amounts[i], discount: cDisc[i], net: net, tax: t, gross: net + t, rate: r };
                });
                var netG = inclusive ? baseG - taxG : baseG;
                parts.push({ rate: r, net: netG, tax: taxG, gross: netG + taxG });
            });
            var net = 0, tax = 0;
            parts.forEach(function (p) { net += p.net; tax += p.tax; });
            return {
                amount: amount, discount: lineDisc, net: net, tax: tax, gross: net + tax,
                rate: (taxableLine && rateUnits(lineRate) > 0) ? lineRate : '0',
                components: comps, parts: parts,
            };
        }

        /** Agrupado por alíquota, na ordem da 1ª aparição (array — ordem estável). */
        function byRate(result) {
            var idx = {}, g = [];
            result.lines.forEach(function (line) {
                (line.parts || [line]).forEach(function (l) {
                    if (l.tax === 0 && rateUnits(l.rate) === 0) return;
                    var key = String(l.rate);
                    if (idx[key] === undefined) { idx[key] = g.length; g.push({ rate: key, net: 0, tax: 0 }); }
                    g[idx[key]].net += l.net;
                    g[idx[key]].tax += l.tax;
                });
            });
            return g;
        }

        function tip(baseCents, pct) {
            var units = rateUnits(typeof pct === 'string' ? pct.trim().replace(',', '.') : pct);
            if (units > 100 * RATE_SCALE) throw new RangeError('Tip percent above 100');
            return Number(divRoundBig(BigInt(Math.max(0, baseCents)) * BigInt(units), BigInt(100 * RATE_SCALE)));
        }

        return {
            RATE_SCALE: RATE_SCALE, fromDecimal: fromDecimal, toDecimal: toDecimal,
            rateUnits: rateUnits, rateString: rateString, normalizeRate: normalizeRate,
            mulRate: mulRate, netFromGross: netFromGross, allocate: allocate, divRound: divRound,
            lines: lines, byRate: byRate, tip: tip,
        };
    })();
    root.MadPdvTax = MadPdvTax;

    /**
     * Partes da linha com opções (mesma conta do PHP _optionTaxComponents):
     * item (preço − share) + cada opção ((acréscimo + share) × qtd), rateando o
     * valor da linha pelo peso de cada parte. null = linha sem partes.
     */
    function optionComponents(amountC, op) {
        if (!op || !op.parts || !op.parts.length) return null;
        var w = [op.baseC].concat(op.parts.map(function (p) { return p.unitC; }));
        var a = MadPdvTax.allocate(amountC, w);
        var out = [{ amount: a[0] }];
        op.parts.forEach(function (p, i) { out.push({ amount: a[i + 1], rate: p.rate }); });
        return out;
    }
    root.MadPdvOptionComponents = optionComponents;

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
                _composePending: {}, // requestId → {product, qty, lineUid|null}
                composing: false,    // esperando o servidor dar o preço do item montado

                // Opções do item (modificadores / combos): grupos vêm do
                // servidor (`on-options` da tela); o modal é do PDV.
                _optPending: {},     // requestId → {product, qty, lineUid|null}
                optLoading: false,
                opt: { open: false, product: null, qty: 1, groups: [], sel: {}, lineUid: null, error: '' },

                // Gorjeta: 'none' | 'percent' (tipPct) | 'custom' (tipCustomC).
                tipMode: 'none',
                tipPct: '',
                tipInput: '',
                tipCustomC: 0,

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
                    this._onCompose = function (e) { self._handleCompose(e.detail || {}); };
                    this._onOptions = function (e) { self._handleOptions(e.detail || {}); };
                    this.$el.addEventListener('mad-pdv:compose-result', this._onCompose);
                    this.$el.addEventListener('mad-pdv:options-result', this._onOptions);
                    this.$el.addEventListener('mad-pdv:lookup-result', this._onLookup);
                    this.$el.addEventListener('mad-pdv:sale-done', this._onDone);
                    this.$el.addEventListener('mad-pdv:sale-error', this._onError);

                    if (this.cfg.customer && this.cfg.customer.enabled && this.cfg.customer.defaultId) {
                        this.customerId = this.cfg.customer.defaultId;
                    }
                    this.saleDiscUnit = this.cfg.behavior.discountInput === 'percent' ? 'percent' : 'money';
                    // Os ícones das linhas do carrinho nascem depois do load (x-for):
                    // sem isto o `<i data-lucide>` nunca vira desenho e os botões
                    // da linha (remover, montar de novo) ficam invisíveis.
                    if (typeof this.$watch === 'function') {
                        this.$watch('cart', function () {
                            self.$nextTick(function () { self._reiconCart(); self._fitCart(); });
                        });
                    }
                    // Carrinho que não cabe na própria largura empilha (fw#368).
                    var cartEl = this.$el.querySelector ? this.$el.querySelector('.mad-pdv-cart') : null;
                    if (cartEl && typeof root.ResizeObserver === 'function') {
                        var lastW = -1;
                        this._cartRo = new root.ResizeObserver(function () {
                            if (cartEl.clientWidth === lastW) return;   // só largura: a altura muda ao empilhar
                            lastW = cartEl.clientWidth;
                            self._fitCart();
                            self._fitScan();
                        });
                        this._cartRo.observe(cartEl);
                    }
                    this._loadHeld();
                    this._tickClock();
                    this._clockTimer = setInterval(function () { self._tickClock(); }, 30000);
                    this.$nextTick(function () { self.focusScan(); });
                },

                /** Desenha os ícones (Lucide) das linhas novas do carrinho. */
                _reiconCart() {
                    var lib = root.lucide;
                    if (!lib || !lib.createIcons || !this.$el || !this.$el.querySelectorAll) return;
                    var nodes = this.$el.querySelectorAll('.mad-pdv-cart-table tbody i[data-lucide]');
                    if (!nodes.length) return;
                    try { lib.createIcons({ nameAttr: 'data-lucide', nodes: nodes }); } catch (e) {}
                },

                /**
                 * Empilha o carrinho (cada linha em duas, como no celular) quando a
                 * tabela não cabe na largura DELE (fw#368). O ponto de quebra
                 * depende das colunas da tela (SKU, desconto, vendedor…), então é
                 * medido: tira a classe, mede a tabela, decide.
                 */
                _fitCart() {
                    var cart = this.$el && this.$el.querySelector ? this.$el.querySelector('.mad-pdv-cart') : null;
                    var table = cart && cart.querySelector('.mad-pdv-cart-table');
                    if (!cart || !table) return;
                    cart.classList.remove('mad-pdv-cart--stack');
                    cart.classList.toggle('mad-pdv-cart--stack', table.offsetWidth > cart.clientWidth + 1);
                },

                /**
                 * Barra de bipagem estreita (coluna espremida pelo menu lateral,
                 * janela de 1024 px): o botão do catálogo fica só com o ícone,
                 * para o campo de código não encolher. Medido como o carrinho —
                 * o ponto depende da coluna, não da janela.
                 */
                _fitScan() {
                    var bar = this.$el && this.$el.querySelector ? this.$el.querySelector('.mad-pdv-scanbar') : null;
                    var input = bar && bar.querySelector('.mad-pdv-scan-input');
                    if (!bar || !input) return;
                    bar.classList.remove('mad-pdv-scanbar--tight');
                    bar.classList.toggle('mad-pdv-scanbar--tight',
                        input.clientWidth < 240 || bar.scrollWidth > bar.clientWidth + 1);
                },

                destroy() {
                    if (this._cartRo) this._cartRo.disconnect();
                    this.$el.removeEventListener('mad-pdv:compose-result', this._onCompose);
                    this.$el.removeEventListener('mad-pdv:options-result', this._onOptions);
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

                /** Casas da moeda na TELA: 2 (padrão) ou 0 (ex.: JPY). O carrinho segue em centavos. */
                _curDecimals() {
                    var d = this.cfg.currency.decimals;
                    return (d === 0 || d === '0') ? 0 : 2;
                },

                /** Espaço entre o símbolo e o número: `gap` da moeda (padrão ' '). */
                _curGap() {
                    var g = this.cfg.currency.gap;
                    return (g === undefined || g === null) ? ' ' : String(g);
                },

                money(c) {
                    var cur = this.cfg.currency;
                    var neg = c < 0;
                    c = Math.abs(Math.round(c || 0));
                    var zero = this._curDecimals() === 0;
                    // 0 casas: centavos → unidade, half-up.
                    var intPart = String(zero ? Math.floor((c + 50) / 100) : Math.floor(c / 100));
                    var dec = zero ? '' : cur.decimal + String(c % 100).padStart(2, '0');
                    var grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, cur.thousand);
                    var sym = (cur.symbol === '' || cur.symbol === undefined || cur.symbol === null)
                        ? '' : cur.symbol + this._curGap();
                    return (neg ? '-' : '') + sym + grouped + dec;
                },

                _moneyPlain(c) {
                    var cur = this.cfg.currency;
                    var abs = Math.abs(c);
                    if (this._curDecimals() === 0) return String(Math.floor((abs + 50) / 100));
                    var intPart = String(Math.floor(abs / 100));
                    return intPart + cur.decimal + String(abs % 100).padStart(2, '0');
                },

                // Preço na busca e no catálogo. O do cadastro de um item
                // montável não é o que vai ser cobrado (pizza com preço por
                // tamanho tem 0 no cadastro): mostra o `composite-price-label`
                // (`:price` = preço do cadastro) ou "Monte o seu".
                priceText(p) {
                    if (!p || !p.composite || !this.cfg.behavior.composite) return this.money(this.toC(p && p.price));
                    var tpl = String(this.cfg.behavior.compositePriceLabel || this.cfg.i18n.compose_price || '');
                    return tpl.indexOf(':price') >= 0 ? tpl.replace(':price', this.money(this.toC(p.price))) : tpl;
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
                    // Mesmo produto com opções diferentes = linhas diferentes.
                    if (l.optSig) parts.push(l.optSig);
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
                get taxOn()  { return !!this.cfg.behavior.tax; },
                get tipsOn() { return !!this.cfg.behavior.tips; },

                /**
                 * Imposto por linha (MadPdvTax): desconto da venda rateado nas
                 * linhas ANTES do imposto. Prévia — o servidor refaz e vale o dele.
                 */
                get taxCalc() {
                    var self = this;
                    var lines = this.cart.map(function (l) {
                        var out = { amount: Math.max(0, self.lineTotalC(l)), rate: self.taxOn ? (l.taxRate || '0') : '0' };
                        var comps = self.taxOn ? optionComponents(out.amount, l.optParts) : null;
                        if (comps) out.components = comps;
                        return out;
                    });
                    return MadPdvTax.lines(lines, !!(this.taxOn && this.cfg.behavior.tax.inclusive), this.saleDiscC);
                },
                get taxC() { return this.taxOn ? this.taxCalc.tax : 0; },
                get taxGroups() {
                    if (!this.taxOn) return [];
                    var self = this;
                    return MadPdvTax.byRate(this.taxCalc).map(function (g) {
                        return { rate: g.rate, taxC: g.tax, label: self.taxRateLabel(g.rate) };
                    });
                },
                taxRateLabel(rate) {
                    var t = this.cfg.behavior.tax || {};
                    var txt = String(rate).replace('.', this.cfg.currency.decimal);
                    return (t.label || this.cfg.i18n.tax || 'Tax') + ' ' + txt + '%'
                        + (t.inclusive ? ' ' + (this.cfg.i18n.tax_included || '(incl.)') : '');
                },

                /** Base da gorjeta em %: líquido SEM imposto, DEPOIS do desconto. */
                get tipBaseC() {
                    return this.taxOn ? this.taxCalc.net : Math.max(0, this.subtotalC - this.saleDiscC);
                },
                get tipC() {
                    if (!this.tipsOn) return 0;
                    if (this.tipMode === 'percent') {
                        try { return MadPdvTax.tip(this.tipBaseC, this.tipPct); } catch (e) { return 0; }
                    }
                    return this.tipMode === 'custom' ? Math.max(0, this.tipCustomC | 0) : 0;
                },

                get totalC() {
                    // Sem imposto nem gorjeta: a conta de sempre.
                    if (!this.taxOn && !this.tipsOn) return Math.max(0, this.subtotalC - this.saleDiscC);
                    var base = this.taxOn ? this.taxCalc.total : Math.max(0, this.subtotalC - this.saleDiscC);
                    return base + this.tipC;
                },

                // ── Gorjeta ───────────────────────────────────────────────
                tipPresetLabel(p) { return String(p).replace('.', this.cfg.currency.decimal) + '%'; },
                isTipPreset(p) { return this.tipMode === 'percent' && this.tipPct === String(p); },
                setTipPct(p) {
                    if (!this.tipsOn) return;
                    if (this.isTipPreset(p)) { this.setTipNone(); return; }
                    this.tipMode = 'percent';
                    this.tipPct = String(p);
                    this.tipInput = '';
                    this._tipChanged();
                },
                setTipNone() {
                    this.tipMode = 'none';
                    this.tipPct = '';
                    this.tipInput = '';
                    this.tipCustomC = 0;
                    this._tipChanged();
                },
                setTipCustom(v) {
                    if (!this.tipsOn) return;
                    var c = Math.max(0, this.parseMoney(v));
                    this.tipInput = v;
                    this.tipCustomC = c;
                    this.tipMode = c > 0 ? 'custom' : 'none';
                    this.tipPct = '';
                    this._tipChanged();
                },
                /** Pagamento lançado a mais depois de baixar a gorjeta não fecha mais: recomeça. */
                _tipChanged() {
                    if (this.paidC > this.totalC) this.payments = [];
                },
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

                    // Item montável (pizza meio a meio, borda, adicionais): não
                    // entra direto — a tela monta e o servidor dá o preço.
                    if (p.composite && this.cfg.behavior.composite) {
                        this._askCompose(p, qty, null);
                        return;
                    }
                    // Item com opções (tamanho, adicionais, combo): abre o modal.
                    if (p.options && this.cfg.behavior.options) {
                        this._askOptions(p, qty, null);
                        return;
                    }

                    var priceC = this.toC(p.price);
                    // Linha candidata: mesmos id/preço e as colunas de entrada
                    // nos DEFAULTS (o estado de quem acabou de ser bipado).
                    var candKey = this._candidateMergeKey(p, priceC);
                    for (var i = 0; i < this.cart.length && this.cfg.behavior.mergeLines !== 'off'; i++) {
                        var l = this.cart[i];
                        if (l.composite) continue;   // linha montada nunca funde
                        if (this._mergeKey(l) === candKey) {
                            this._setLineQty(l, l.qty + qty);
                            this.selLine = i;
                            return;
                        }
                    }
                    var line = this._newLine(p, priceC);
                    this.cart.push(line);
                    this._setLineQty(line, qty);
                    this.selLine = this.cart.length - 1;
                },

                /** Linha nova do carrinho a partir de um produto da busca. */
                _newLine(p, priceC) {
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
                    // Alíquota do produto (vem do lookup só com tax-field).
                    if (this.taxOn) line.taxRate = MadPdvTax.normalizeRate(p.taxRate);
                    return line;
                },

                // ── Item composto ─────────────────────────────────────────
                /**
                 * Pede à TELA que monte o item. O PDV não tem montador: dispara
                 * `mad-pdv:compose` e quem ouve (o montador da tela) chama
                 * `preventDefault()` e depois `detail.confirm(escolhas)` ou
                 * `detail.cancel()`. `line` = linha sendo remontada (ou null).
                 */
                _askCompose(p, qty, line) {
                    var self = this;
                    var done = false;
                    // O elemento que disparou (resultado da busca) sai do DOM
                    // quando a busca fecha: o pedido ao servidor parte do PDV.
                    var at = (this.$el && this.$el.closest && this.$el.closest('[data-mad-pdv]')) || this.$el;
                    var detail = {
                        product: {
                            id: p.id, name: p.name, code: p.code || '', barcode: p.barcode || '',
                            unit: p.unit || '', image: p.image || '',
                            price: p.price !== undefined ? p.price : (p.priceC || 0) / 100,
                        },
                        qty: qty,
                        // Cópia: o montador mexe à vontade sem tocar a linha.
                        choices: (line && line.choices) ? JSON.parse(JSON.stringify(line.choices)) : null,
                        confirm: function (choices) {
                            if (done) return;
                            done = true;
                            self._composeConfirm(p, qty, line, choices, at);
                        },
                        cancel: function () {
                            if (done) return;
                            done = true;
                            self.focusScan();
                        },
                    };
                    var ev = new CustomEvent('mad-pdv:compose', { bubbles: true, cancelable: true, detail: detail });
                    var handled = !this.$el.dispatchEvent(ev);
                    if (!handled) {
                        // Ninguém assumiu: a tela não tem montador.
                        done = true;
                        if (typeof root.madToast === 'function') {
                            root.madToast(this.cfg.i18n.compose_missing || '', 'warning');
                        }
                        this.focusScan();
                    }
                },

                /** Escolhas confirmadas: o SERVIDOR dá preço e descrição. */
                _composeConfirm(p, qty, line, choices, at) {
                    var self = this;
                    var id = ++this._reqSeq;
                    this._composePending[id] = { product: p, qty: qty, lineUid: line ? line.uid : null };
                    this.composing = true;
                    wireCall(at || this.$el, this.cfg.wire.compose, [String(p.id), JSON.stringify(choices || {}), qty, id])
                        .catch(function (err) {
                            console.error('[mad-pdv] compose:', err);
                            delete self._composePending[id];
                            self.composing = false;
                        });
                },

                _handleCompose(d) {
                    var pend = this._composePending[d.requestId];
                    if (!pend) return;
                    delete this._composePending[d.requestId];
                    this.composing = false;
                    if (!d.ok) { this.focusScan(); return; }   // o servidor já avisou o motivo

                    var priceC = this.toC(d.price);
                    var line = null;
                    if (pend.lineUid) {
                        for (var i = 0; i < this.cart.length; i++) {
                            if (this.cart[i].uid === pend.lineUid) { line = this.cart[i]; this.selLine = i; break; }
                        }
                        if (!line) { this.focusScan(); return; }   // removida enquanto montava
                        line.priceC = priceC;
                        // desconto digitado não pode passar do novo bruto
                        line.discC = Math.min(line.discC || 0, Math.round(line.qty * priceC));
                    } else {
                        line = this._newLine(pend.product, priceC);
                        this.cart.push(line);
                        this._setLineQty(line, pend.qty);
                        this.selLine = this.cart.length - 1;
                        line = this.cart[this.selLine];
                    }
                    line.composite   = true;
                    // preço do CADASTRO: é o que o montador recebe ao remontar
                    if (!pend.lineUid) line.baseC = this.toC(pend.product.price);
                    line.description = d.description || '';
                    line.choices     = d.choices || {};
                    // preço mudou → o que já foi lançado de pagamento não fecha mais
                    if (pend.lineUid) this.payments = [];
                    this.focusScan();
                },

                /** Botão "Montar de novo" da linha. */
                recompose(i) {
                    var l = this.cart[i];
                    if (!l || !l.composite) return;
                    this._askCompose({
                        id: l.id, name: l.name, code: l.code, barcode: l.barcode, unit: l.unit, image: l.image,
                        price: (l.baseC !== undefined ? l.baseC : l.priceC) / 100,
                    }, l.qty, l);
                },

                // ── Opções do item (modificadores / combos) ───────────────
                /** Pede ao servidor os grupos de opções do produto. `line` = linha sendo alterada. */
                _askOptions(p, qty, line) {
                    var self = this;
                    var id = ++this._reqSeq;
                    var at = (this.$el && this.$el.closest && this.$el.closest('[data-mad-pdv]')) || this.$el;
                    this._optPending[id] = { product: p, qty: qty, lineUid: line ? line.uid : null };
                    this.optLoading = true;
                    wireCall(at, this.cfg.wire.options, [String(p.id), id])
                        .catch(function (err) {
                            console.error('[mad-pdv] options:', err);
                            delete self._optPending[id];
                            self.optLoading = false;
                        });
                },

                _handleOptions(d) {
                    var pend = this._optPending[d.requestId];
                    if (!pend) return;
                    delete this._optPending[d.requestId];
                    this.optLoading = false;
                    if (!d.ok) { this.focusScan(); return; }   // o servidor já avisou o motivo

                    var groups = Array.isArray(d.groups) ? d.groups : [];
                    var line = null;
                    if (pend.lineUid) {
                        line = this.cart.find(function (l) { return l.uid === pend.lineUid; }) || null;
                        if (!line) { this.focusScan(); return; }   // removida enquanto carregava
                    }
                    // Seleção = {grupo: {opção: qtd}}. Chave repetida na escolha salva = +1.
                    var sel = {};
                    groups.forEach(function (g) {
                        var prev = line && line.optChoices ? (line.optChoices[g.key] || []) : null;
                        var m = {}, n = 0;
                        (g.options || []).forEach(function (o) {
                            var q;
                            if (prev) q = prev.filter(function (k) { return k === o.key; }).length;
                            else q = o.default === true ? 1 : (+o.default || 0);
                            q = Math.min(Math.max(q, o.min || 0), o.max || 1);
                            if (q > 0) { m[o.key] = q; n += q; }
                        });
                        // Grupo obrigatório de uma opção só: já vem marcado.
                        if (!n && g.min >= 1 && (g.options || []).length === 1) m[g.options[0].key] = Math.max(1, g.min);
                        sel[g.key] = m;
                    });
                    this.opt = {
                        open: true, product: pend.product, qty: pend.qty, groups: groups,
                        sel: sel, lineUid: pend.lineUid, error: '',
                    };
                    this._optOpener = (root.document && root.document.activeElement) || null;
                    var self = this;
                    this.$nextTick(function () {
                        var box = self.$refs.optModal;
                        var first = box && box.querySelector('input:not([disabled]), button:not([disabled])');
                        if (first) first.focus();
                    });
                },

                optQty(g, o) { return ((this.opt.sel[g.key] || {})[o.key]) || 0; },

                optIsOn(g, o) { return this.optQty(g, o) > 0; },

                /** Soma das quantidades do grupo (é o que o min/max do grupo conta). */
                optCount(g) {
                    var m = this.opt.sel[g.key] || {}, n = 0;
                    Object.keys(m).forEach(function (k) { n += m[k]; });
                    return n;
                },

                optDisabled(g, o) {
                    return g.max > 1 && !this.optIsOn(g, o) && this.optCount(g) >= g.max;
                },

                _optSet(g, m) {
                    var next = Object.assign({}, this.opt.sel);
                    next[g.key] = m;
                    this.opt.sel = next;
                    this.opt.error = '';
                },

                optToggle(g, o) {
                    var cur = Object.assign({}, this.opt.sel[g.key] || {});
                    var on = (cur[o.key] || 0) > 0;
                    if (g.max === 1) {
                        // Rádio: obrigatório não desmarca; opcional desmarca no 2º clique.
                        if (on) { if (g.min < 1 && !(o.min > 0)) cur = {}; }
                        else { cur = {}; cur[o.key] = 1; }
                    } else if (on) {
                        if (!(o.min > 0)) delete cur[o.key];
                    } else if (this.optCount(g) < g.max) {
                        cur[o.key] = 1;
                    }
                    this._optSet(g, cur);
                },

                /** Contador da opção com quantidade (− 2 +). */
                optCanStep(g, o, d) {
                    var q = this.optQty(g, o) + d;
                    if (q < (o.min || 0) || q > (o.max || 1)) return false;
                    return !(d > 0 && this.optCount(g) >= g.max);
                },

                optStep(g, o, d) {
                    if (!this.optCanStep(g, o, d)) return;
                    var cur = Object.assign({}, this.opt.sel[g.key] || {});
                    var q = (cur[o.key] || 0) + d;
                    if (q > 0) cur[o.key] = q; else delete cur[o.key];
                    this._optSet(g, cur);
                },

                /** "Obrigatório · Escolha 1" / "Até 3" / "Escolha 1 a 2". */
                optHint(g) {
                    var t = this.cfg.i18n;
                    var rule;
                    if (g.min === g.max) rule = g.min === 1 ? (t.options_one || 'Choose 1') : String(t.options_exact || 'Choose :min').replace(':min', g.min);
                    else if (!g.min) rule = String(t.options_up_to || 'Up to :max').replace(':max', g.max);
                    else rule = String(t.options_range || 'Choose :min to :max').replace(':min', g.min).replace(':max', g.max);
                    return (g.min >= 1 ? (t.options_required || 'Required') + ' · ' : '') + rule;
                },

                optPriceText(o) {
                    var c = this.toC(o.price);
                    if (!c) return '';
                    return (c > 0 ? '+ ' : '− ') + this.money(Math.abs(c));
                },

                /** Preço unitário com as opções marcadas (prévia; o servidor refaz). */
                get optUnitC() {
                    if (!this.opt.product) return 0;
                    var self = this;
                    var c = this.toC(this.opt.product.price);
                    this.opt.groups.forEach(function (g) {
                        (g.options || []).forEach(function (o) { c += self.toC(o.price) * self.optQty(g, o); });
                    });
                    return c;
                },

                /** 1ª regra violada (mensagem) ou ''. */
                _optViolation() {
                    var t = this.cfg.i18n;
                    for (var i = 0; i < this.opt.groups.length; i++) {
                        var g = this.opt.groups[i];
                        for (var j = 0; j < (g.options || []).length; j++) {
                            var o = g.options[j], q = this.optQty(g, o);
                            if (q > (o.max || 1)) return String(t.options_opt_max || 'Choose at most :max of “:option”.').replace(':max', o.max || 1).replace(':option', o.label);
                            if (q < (o.min || 0)) return String(t.options_opt_min || 'Choose at least :min of “:option”.').replace(':min', o.min).replace(':option', o.label);
                        }
                        var n = this.optCount(g);
                        if (n < g.min) return String(t.options_min || 'Choose at least :min in “:group”.').replace(':min', g.min).replace(':group', g.label);
                        if (n > g.max) return String(t.options_max || 'Choose at most :max in “:group”.').replace(':max', g.max).replace(':group', g.label);
                    }
                    return '';
                },

                get optValid() { return this.opt.open && this._optViolation() === '' && this.optUnitC >= 0; },

                optConfirm() {
                    if (!this.opt.open) return;
                    var why = this._optViolation();
                    if (why) { this.opt.error = why; return; }
                    var self = this;
                    var choices = {}, list = [], parts = [], shareC = 0;
                    // Ordem do CADASTRO, não a do clique: mesma escolha = mesma assinatura.
                    // Quantidade vai como chave repetida (["bacon","bacon"] = 2× bacon).
                    this.opt.groups.forEach(function (g) {
                        var keys = [];
                        (g.options || []).forEach(function (o) {
                            var q = self.optQty(g, o);
                            if (!q) return;
                            for (var r = 0; r < q; r++) keys.push(o.key);
                            var priceC = self.toC(o.price), sC = self.toC(o.share || 0);
                            shareC += sC * q;
                            list.push({ group: g.label, groupKey: g.key, option: o.label, optionKey: o.key, qty: q,
                                        priceC: priceC, productId: o.productId === undefined ? null : o.productId });
                            parts.push({ unitC: (priceC + sC) * q, rate: o.taxRate === undefined ? null : o.taxRate });
                        });
                        if (keys.length) choices[g.key] = keys;
                    });
                    var sig = JSON.stringify(choices);
                    var priceC = this.optUnitC;
                    var p = this.opt.product;
                    var line = null, i;

                    if (this.opt.lineUid) {
                        for (i = 0; i < this.cart.length; i++) {
                            if (this.cart[i].uid === this.opt.lineUid) { line = this.cart[i]; this.selLine = i; break; }
                        }
                        if (line) {
                            line.priceC = priceC;
                            line.discC = Math.min(line.discC || 0, Math.round(line.qty * priceC));
                            // preço mudou → o que já foi lançado de pagamento não fecha mais
                            this.payments = [];
                        }
                    } else {
                        var cand = this._candidateMergeKey(p, priceC);
                        for (i = 0; i < this.cart.length && this.cfg.behavior.mergeLines !== 'off'; i++) {
                            var l = this.cart[i];
                            if (!l.composite && l.optSig === sig && l.id === p.id && this._mergeKey(l) === this._withSig(cand, sig)) {
                                this._setLineQty(l, l.qty + this.opt.qty);
                                this.selLine = i;
                                this._optClose();
                                return;
                            }
                        }
                        line = this._newLine(p, priceC);
                        line.baseC = this.toC(p.price);
                        this.cart.push(line);
                        this._setLineQty(line, this.opt.qty);
                        this.selLine = this.cart.length - 1;
                        line = this.cart[this.selLine];
                    }
                    if (line) {
                        line.hasOptions = true;
                        line.options = list;
                        line.optChoices = choices;
                        line.optSig = sig;
                        // Partes da linha para o imposto por alíquota (combo misto).
                        line.optParts = { baseC: (line.baseC !== undefined ? line.baseC : this.toC(p.price)) - shareC, parts: parts };
                    }
                    this._optClose();
                },

                _withSig(key, sig) {
                    // Mesma montagem do _mergeKey: assinatura logo depois de id/preço.
                    var parts = key.split('\u0001');
                    parts.splice(2, 0, sig);
                    return parts.join('\u0001');
                },

                optCancel() { this._optClose(); },

                _optClose() {
                    this.opt = { open: false, product: null, qty: 1, groups: [], sel: {}, lineUid: null, error: '' };
                    this.focusScan();
                },

                /** Foco preso no modal (Tab / Shift+Tab giram dentro dele). */
                optTrap(e) {
                    if (e.key !== 'Tab') return;
                    var box = this.$refs.optModal;
                    if (!box) return;
                    var els = Array.prototype.filter.call(
                        box.querySelectorAll('input:not([disabled]), button:not([disabled])'),
                        function (el) { return el.offsetParent !== null || el === root.document.activeElement; });
                    if (!els.length) return;
                    var first = els[0], last = els[els.length - 1];
                    if (e.shiftKey && root.document.activeElement === first) { e.preventDefault(); last.focus(); }
                    else if (!e.shiftKey && root.document.activeElement === last) { e.preventDefault(); first.focus(); }
                },

                /** Texto curto das opções na linha do carrinho ("Grande · Bacon"). */
                optText(l) {
                    return (l.options || []).map(function (o) { return (o.qty > 1 ? o.qty + '× ' : '') + o.option; }).join(' · ');
                },

                /** Botão "Alterar opções" da linha. */
                editOptions(i) {
                    var l = this.cart[i];
                    if (!l || !l.hasOptions) return;
                    this._askOptions({
                        id: l.id, name: l.name, code: l.code, barcode: l.barcode, unit: l.unit, image: l.image,
                        price: (l.baseC !== undefined ? l.baseC : l.priceC) / 100, taxRate: l.taxRate,
                    }, l.qty, l);
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
                    if (l.composite || l.hasOptions) return;   // preço montado/com opções é do servidor
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
                    return (unit || 'money') === 'percent' ? '0' : this.zeroMoney();
                },

                /** "0.00" / "0,00" / "0": separador e casas da moeda do app (F-A21). */
                zeroMoney() {
                    const c = (this.cfg && this.cfg.currency) || {};
                    const d = Number.isInteger(c.decimals) ? c.decimals : 2;
                    return d > 0 ? '0' + (c.decimal || ',') + '0'.repeat(d) : '0';
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
                                // Identidade da linha: duas montagens do mesmo
                                // produto dividem o productId.
                                line: l.uid,
                                // Só as ESCOLHAS viajam; o preço o servidor refaz.
                                choices: l.composite ? (l.choices || {}) : undefined,
                                options: l.hasOptions ? (l.optChoices || {}) : undefined,
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
                    // Imposto/gorjeta: só entram no payload quando ligados (PDV de
                    // sempre manda o mesmo JSON de antes). Valores aqui são
                    // PRÉVIA — o servidor refaz imposto e gorjeta em %.
                    if (this.taxOn) payload.totals.tax = this.taxC / 100;
                    if (this.tipsOn) {
                        payload.tip = this.tipC / 100;
                        payload.tipPercent = this.tipMode === 'percent' ? this.tipPct : null;
                        payload.totals.tip = this.tipC / 100;
                    }
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
                                // `line` aponta UMA linha (item montado); sem ele, vale o produto.
                                if (u.line ? l.uid === u.line : l.id === u.productId) l.priceC = self.toC(u.newPrice);
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
                    if (code === 'compose-failed' && details.items && details.items[0]) {
                        // Mostra QUAL linha precisa ser remontada.
                        for (var ci = 0; ci < this.cart.length; ci++) {
                            if (this.cart[ci].uid === details.items[0].line) { this.selLine = ci; break; }
                        }
                        this.focusScan();
                        return;
                    }
                    if (code === 'options-invalid' && details.items && details.items[0]) {
                        for (var oi = 0; oi < this.cart.length; oi++) {
                            if (this.cart[oi].uid === details.items[0].line) { this.selLine = oi; break; }
                        }
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
                    this.tipMode = 'none';
                    this.tipPct = '';
                    this.tipInput = '';
                    this.tipCustomC = 0;
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
                            tip: { mode: this.tipMode, pct: this.tipPct, input: this.tipInput, customC: this.tipCustomC },
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
                    var tp = s.tip || {};
                    this.tipMode = tp.mode || 'none';
                    this.tipPct = tp.pct || '';
                    this.tipInput = tp.input || '';
                    this.tipCustomC = tp.customC || 0;
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

                    var self      = this;
                    var framed    = root.self !== root.top;
                    var canDetect = framed && ('onbeforeprint' in root);
                    var fired     = false;
                    var mark      = function () { fired = true; };
                    // A folha de impressão só esconde a tela (e mostra o cupom)
                    // com esta classe no <body>: sem ela, o Ctrl P de qualquer
                    // outra tela do app (o mad-pdv.css carrega em todas) sairia
                    // em branco. Sai no `afterprint` — print() pode voltar antes
                    // de o navegador montar a impressão.
                    var hasAfter  = 'onafterprint' in root;
                    var done      = function () {
                        root.removeEventListener('afterprint', done);
                        if (!self.receiptPreview) self._receiptPrinting(false);
                    };

                    this._receiptPrinting(true);
                    if (hasAfter) root.addEventListener('afterprint', done);
                    if (canDetect) root.addEventListener('beforeprint', mark);
                    try {
                        root.print();
                    } catch (e) {
                        fired = false;
                    }
                    if (canDetect) root.removeEventListener('beforeprint', mark);

                    if (canDetect && !fired) {
                        console.warn('[mad-pdv] print() ignorado — iframe sandbox sem "allow-modals". Cupom exibido na tela.');
                        // O cupom fica na tela (e a classe, para o Ctrl P do
                        // operador imprimir só ele) até fechar o preview.
                        if (hasAfter) root.removeEventListener('afterprint', done);
                        this.receiptPreview = true;
                    } else if (!hasAfter) {
                        this._receiptPrinting(false);
                    }
                },

                closeReceiptPreview() {
                    this.receiptPreview = false;
                    this._receiptPrinting(false);
                    this.focusScan();
                },

                /** Liga/desliga a impressão do cupom (classe no <body>, ver mad-pdv.css). */
                _receiptPrinting(on) {
                    var body = root.document && root.document.body;
                    if (body && body.classList) body.classList.toggle('mad-pdv-printing', !!on);
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
                    // Modal de opções aberto: Esc fecha; o resto do teclado é dele.
                    if (this.opt.open) {
                        if (e.key === 'Escape') { e.preventDefault(); this.optCancel(); }
                        return;
                    }

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
