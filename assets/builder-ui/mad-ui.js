/**
 * mad-ui.js — Alpine.js components for mad-ui design system
 * ──────────────────────────────────────────────────────────
 * Versão  : 1.0
 * Requer  : Alpine.js v3  (carregue este arquivo ANTES do Alpine)
 *
 * COMPONENTES
 *   madMegamenu    — dropdown de apps com busca e filtro
 *   madTabs        — troca de abas com painéis
 *   madSelectCards — seleção exclusiva entre cards
 *   madSubmenu     — painel lateral com accordion e item ativo
 *   madRail        — sidebar de ícones com módulo ativo
 *   madDropdown    — dropdown genérico (open/close + outside click)
 *   madAlert       — alerta dismissível com animação
 *   madToast       — fila de toasts temporários
 *
 * USO BÁSICO
 *   <script src="mad-ui.js"></script>       <!-- antes do Alpine -->
 *   <script defer src="alpine.min.js"></script>
 */

// ═══════════════════════════════════════════════════════════════════════════
// Resposta JSON do wire com rede de segurança (MadErrorModal).
//
// Substitui `r.json()`: se o servidor devolveu 500 / página de erro do Laravel
// / payload com `_exception`, abre o modal 90×90 (página + texto + markdown
// pro agente de IA) e resolve `null` — o chamador só faz `if (!data) return;`.
// Sem o MadErrorModal carregado, cai no json() puro (comportamento antigo).
// ═══════════════════════════════════════════════════════════════════════════

function _madWireJson(res, context) {
    if (window.MadErrorModal && window.MadErrorModal.guardJson) {
        return window.MadErrorModal.guardJson(res, context || {});
    }
    return res.json();
}

// ═══════════════════════════════════════════════════════════════════════════
// Cabeçalhos de TODO POST para o wire (/app/_mad-wire) feito por este arquivo.
//
// O entry point do wire exige o X-CSRF-TOKEN de quem está logado (o mesmo
// esquema do MadWire, em mad-livewire.js). Cada fetch montava os headers à
// mão e dois deles esqueciam o token: a cascata de colunas do <mad-field-list>
// (depends-on) e os eventos on-add/on-remove/on-totalize voltavam 403 "CSRF
// token mismatch" — o combo dependente ficava vazio e o total não atualizava.
// Um lugar só: POST novo para o wire usa este helper.
// ═══════════════════════════════════════════════════════════════════════════

function _madWireHeaders(extra) {
    var headers = { 'X-Requested-With': 'XMLHttpRequest' };
    var meta  = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? (meta.getAttribute('content') || '') : '';
    if (token) headers['X-CSRF-TOKEN'] = token;
    if (extra) {
        Object.keys(extra).forEach(function (k) { headers[k] = extra[k]; });
    }
    return headers;
}

// Resposta de um POST direto ao wire (eventos e cascata do <mad-field-list>):
// mesmo tratamento do caminho parcial do MadWire. Duas coisas que se perdiam:
//  - `bind` (o <span> do @madBind): o Mad.applyOps não conhece — o resumo que
//    um on-totalize atualiza não mudava na tela;
//  - o estado: ação que não muda nada (ou que mexe em prop array) responde com
//    o componente redesenhado (`html`), sem `mad_state` solto — aqui não há
//    redesenho (o usuário está digitando na grade), mas o estado novo vem
//    dentro do html e tem que ir pro wrapper, senão a próxima ação parte do
//    estado velho.
function _madSyncWireState(wrapper, data) {
    if (!wrapper || !data) return;
    var st = data.mad_state || '';
    if (!st && typeof data.html === 'string') {
        var m = /\bmad-state="([^"]*)"/.exec(data.html);
        if (m) {
            var ta = document.createElement('textarea');
            ta.innerHTML = m[1];   // desfaz o htmlspecialchars do atributo
            st = ta.value;
        }
    }
    if (st) wrapper.setAttribute('mad-state', st);
}

function _madApplyWireOps(ops, wrapper) {
    if (!ops || !ops.length) return;
    if (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.applyOps === 'function' && wrapper) {
        MadWire.applyOps(ops, wrapper);
    } else if (typeof Mad !== 'undefined' && Mad.applyOps) {
        Mad.applyOps(ops, wrapper || null);
    }
}

// POST direto ao wire em FILA com as demais chamadas do componente — a mesma
// fila do MadWire.call (MadWire.enqueue, em mad-livewire.js). Sem ela, dois
// pedidos da Lista de itens (on-change de coluna, cascata, on-add / on-remove /
// on-totalize) corriam em paralelo e valia a resposta que chegasse POR ÚLTIMO,
// não a do último pedido: trocar o produto duas vezes seguidas podia deixar na
// linha o preço do PRIMEIRO, e o estado do componente voltava ao de uma
// resposta velha — sem aviso (framework#175). `job(wrapper)` monta o pedido na
// hora de ENVIAR (estado e campos daquele momento) e devolve a promise do fetch.
function _madWireQueued(wrapper, job) {
    if (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.enqueue === 'function') {
        return MadWire.enqueue(wrapper, job);
    }
    return Promise.resolve().then(function () { return job(wrapper); });
}

// [mad-component] dono de `el`, atravessando teleport: um <mad-field-list> ou
// <mad-detail-form> dentro de um <mad-drawer>/<mad-modal> da tela vive num
// clone no <body>, onde o closest() puro não acha o componente — e o evento
// (cascata, on-add, on-totalize, before-add/before-delete) sumia calado.
function _madOwnerComponent(el) {
    if (!el || !el.closest) return null;
    var direct = el.closest('[mad-component]');
    if (direct) return direct;
    if (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.closestComponent === 'function') {
        try { return MadWire.closestComponent(el); } catch (e) { return null; }
    }
    return null;
}

// ═══════════════════════════════════════════════════════════════════════════
// MAD service URL — endpoints estáticos (db combo/search/entry, cep/cnpj, …).
// Monta a rota amigável /app/services/{slug}/{metodo}?static=1 direto.
// static=1 é legítimo aqui: são métodos PHP estáticos (echo JSON direto).
// ═══════════════════════════════════════════════════════════════════════════

function _madServiceUrl(slug, method, params) {
    // slug = nome amigável do serviço (ex.: 'db-search', 'cep') — NÃO o nome da
    // classe interna. A rota /app/services/{slug}/{method?} resolve a classe pelo
    // ->defaults('class') no servidor (ver MadRoutes::exposeService).
    var extra = Object.assign({ static: '1' }, params || {});
    var base = ((window.MadShell && window.MadShell.appBase) || '/app').replace(/\/+$/, '');
    return base + '/services/' + encodeURIComponent(slug) + '/' + encodeURIComponent(method)
         + '?' + new URLSearchParams(extra).toString();
}

/**
 * Aviso "Erro ao carregar opções: …" de um campo que lê do banco, vindo de um
 * endpoint de opções (recarga da cascata do dbcombo, busca dbunique/dbmulti,
 * dbentry, combo do cadastro rápido).
 *
 * O servidor só manda `error` com APP_DEBUG ligado (\Mad\Form\OptionsLoadError);
 * em produção a resposta não tem a chave e isto só tira um aviso antigo. É a
 * mesma linha que o render do campo emite (partials/options-error), para o
 * erro aparecer no próprio campo e não só no log — antes a cascata vinha vazia
 * sem rastro nenhum na tela. Só mexe na linha que ELE criou (valor "ajax"): o
 * aviso do render (ex.: coluna do display inexistente) não some porque uma
 * busca seguinte voltou sem erro.
 */
function _madOptionsError(el, msg) {
    var field = (el && el.closest) ? el.closest('.mad-field') : null;
    if (!field) return;
    var cur = field.querySelector('p[data-mad-options-error="ajax"]');
    if (!msg) {
        if (cur && cur.parentNode) cur.parentNode.removeChild(cur);
        return;
    }
    msg = String(msg);
    if (window.console && console.warn) console.warn('[mad] ' + msg);
    if (!cur) {
        cur = document.createElement('p');
        cur.className = 'mad-field-hint mad-error';
        cur.setAttribute('data-mad-options-error', 'ajax');
        cur.setAttribute('role', 'alert');
        var slot = field.querySelector('[data-field-error]');
        if (slot && slot.parentNode) slot.parentNode.insertBefore(cur, slot);
        else field.appendChild(cur);
    }
    cur.textContent = msg;
}
window._madOptionsError = _madOptionsError;

// ═══════════════════════════════════════════════════════════════════════════
// Numeric helpers — formatação pt-BR (separador milhar '.', decimal ',')
// Disponíveis globalmente para uso na grid e em componentes Blade.
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Seleciona o conteúdo do campo ao receber foco — também no Safari.
 *
 * `@focus="$event.target.select()"` funciona no Chrome e no Firefox, mas o
 * WebKit desfaz a seleção: no clique o foco chega no meio do mousedown e o
 * navegador põe o cursor onde o mouse caiu DEPOIS do handler. No campo de
 * moeda isso anexava o dígito ao valor antigo (1.234,50 + 9 → 12.345,09) e no
 * OTP (maxlength=1) impedia sobrescrever o dígito. Adiar para a próxima volta
 * do event loop — o mesmo que o $nextTick do madNumericField faz — vale nos
 * três. Se o foco já saiu do campo, não seleciona nada.
 */
function madSelectOnFocus(e) {
    var el = e && e.target;
    if (!el || typeof el.select !== 'function') return;
    setTimeout(function () {
        if (document.activeElement === el) el.select();
    }, 0);
}

/**
 * Parseia string formatada pt-BR para float.
 *   "1.234,56"  → 1234.56
 *   "1234.56"   → 1234.56  (aceita ponto como decimal se não houver vírgula)
 *   ""          → 0
 */
function madNumParse(str) {
    if (str == null || str === '') return 0;
    str = String(str).trim();
    // Se contém vírgula, trata como formato pt-BR
    if (str.indexOf(',') !== -1) {
        str = str.replace(/\./g, '').replace(',', '.');
    }
    const n = parseFloat(str);
    return isNaN(n) ? 0 : n;
}


/**
 * Converte data pt-BR (dd/mm/yyyy [hh:mm]) para ISO (yyyy-mm-dd [HH:MM:SS]).
 * Vazio → ''. Usado pelos editores inline de date/datetime do MadDataGrid.
 *   _madBrDateToIso('25/04/2026')          → '2026-04-25'
 *   _madBrDateToIso('25/04/2026 14:30',true)→ '2026-04-25 14:30:00'
 */
function _madBrDateToIso(v, withTime) {
    if (v == null) return '';
    v = String(v).trim();
    if (!v) return '';
    if (withTime) {
        const m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})(?:[\sT](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!m) return v;
        const hh = m[4] || '00', mm = m[5] || '00', ss = m[6] || '00';
        return `${m[3]}-${m[2]}-${m[1]} ${hh}:${mm}:${ss}`;
    }
    const m = v.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
    if (!m) return v;
    return `${m[3]}-${m[2]}-${m[1]}`;
}
window._madBrDateToIso = _madBrDateToIso;

/**
 * Formata float para string pt-BR.
 *   madNumFmt(1234.56, 2) → "1.234,56"
 */
function madNumFmt(val, decimals) {
    if (decimals == null) decimals = 2;
    const num = typeof val === 'number' ? val : madNumParse(val);
    const fixed = Math.abs(num).toFixed(decimals);
    const parts = fixed.split('.');
    const intPart = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    const sign = num < 0 ? '-' : '';
    return decimals > 0
        ? sign + intPart + ',' + parts[1]
        : sign + intPart;
}

/**
 * Reverse-mask para edicao monetaria em data-grid (estilo calculadora).
 * Le valor cru (so digitos), interpreta como centavos e aplica formato pt-BR
 * direto no input. Devolve o numero cru pra atualizar editValue do Alpine.
 *
 *   "12345"   → input "123,45",        retorna 123.45
 *   "1234567" → input "12.345,67",     retorna 12345.67
 */
function madDgMoneyMask(input, decimals) {
    if (!input) return 0;
    if (decimals == null) decimals = 2;
    var raw = String(input.value || '').replace(/\D/g, '');
    var intVal = parseInt(raw, 10) || 0;
    var str = String(intVal).padStart(decimals + 1, '0');
    var ip = str.slice(0, str.length - decimals) || '0';
    var dp = decimals > 0 ? str.slice(str.length - decimals) : '';
    ip = ip.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    input.value = decimals > 0 ? ip + ',' + dp : ip;
    return intVal / Math.pow(10, decimals);
}

/**
 * Arredonda para N casas decimais. Usado pelo compileFormula() do field-list.
 *   _madRound(1.005, 2) → 1.01
 */
function _madRound(val, decimals) {
    if (decimals == null) decimals = 2;
    var factor = Math.pow(10, decimals);
    return Math.round((+val + Number.EPSILON) * factor) / factor;
}

/**
 * Aplica desconto (% ou monetario) sobre um subtotal. Usado pelo compileFormula() do field-list.
 *   _madDiscount(1000, 10, '%')  → 900   (10% de desconto)
 *   _madDiscount(1000, 10, 'R$') → 990   (R$10 de desconto)
 *   _madDiscount(1000, 10, '$')  → 990   ($10 de desconto)
 *   Resultado nunca fica negativo.
 *   Logica: se tipo === '%' aplica percentual; qualquer outro valor trata como monetario.
 */
function _madDiscount(subtotal, desconto, tipo) {
    subtotal = +subtotal || 0;
    desconto = +desconto || 0;
    if (String(tipo).trim() === '%') {
        return Math.max(subtotal * (1 - desconto / 100), 0);
    }
    return Math.max(subtotal - desconto, 0);
}

/**
 * Calcula o total de uma linha de detalhe: qty * price - discount.
 * Suporta desconto em % (padrão) ou R$ (valor absoluto).
 *
 * Uso no FieldListColumn:
 *   ->compute('madCalcLineTotal(row)')
 *
 * Ou com campos customizados:
 *   ->compute("madCalcLineTotal(row, {qty:'qtd', price:'preco', disc:'desc', discType:'desc_tipo'})")
 */
function madCalcLineTotal(row, opts) {
    opts = opts || {};
    var qty   = parseFloat(row[opts.qty      || 'quantidade'])   || 0;
    var price = parseFloat(row[opts.price    || 'valor'])        || 0;
    var disc  = parseFloat(row[opts.disc     || 'desconto'])     || 0;
    var tipo  = row[opts.discType || 'desconto_tipo']            || '%';
    var sub   = qty * price;
    var total = tipo === 'R$' ? sub - disc : sub * (1 - disc / 100);
    return Math.round(Math.max(total, 0) * 100) / 100;
}

/* ── Sonner toast constants (global scope — used by _madToastMount outside alpine:init) ── */
const _SONNER_GAP = 14;
const _SONNER_VISIBLE = 3;
const _SONNER_LIFETIME = 4000;
const _SONNER_SWIPE_THRESHOLD = 45;
const _SONNER_UNMOUNT_MS = 200;
const _sonnerIcons = {
    success: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>',
    danger:  '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
    warning: '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    info:    '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>',
};

/* ═══════════════════════════════════════════════════════════════
   MAD Alpine alias plugin — reescreve mad-* → x-*
   Permite uso de marca MAD: <div mad-data="..." mad-text="X" mad-if="Y">.
   Reservados (NAO reescreve): atributos do wrapper MadComponent.
   =══════════════════════════════════════════════════════════════ */
const _MAD_ALPINE_RESERVED = new Set([
    'mad-component', 'mad-id', 'mad-state', 'mad-endpoint',
    'mad-action', 'mad-params', 'mad-form', 'mad-name'
]);

function _madRewriteAlpineAttrs(root) {
    if (!root || !root.querySelectorAll) return;
    const scan = [root, ...root.querySelectorAll('*')];
    for (const el of scan) {
        if (!el.attributes) continue;
        for (const attr of Array.from(el.attributes)) {
            const n = attr.name;
            if (!n.startsWith('mad-')) continue;
            if (_MAD_ALPINE_RESERVED.has(n)) continue;
            // mad-foo → x-foo, mad-on:click → x-on:click, mad-bind:class → x-bind:class
            const xName = 'x-' + n.slice(4);
            // Ja existe x-equivalente declarado? respeita
            if (el.hasAttribute(xName)) { el.removeAttribute(n); continue; }
            el.setAttribute(xName, attr.value);
            el.removeAttribute(n);
        }
    }
}

// Reescreve antes do Alpine processar
document.addEventListener('alpine:init', () => {
    _madRewriteAlpineAttrs(document.body);
});

// Reescreve em fragmentos novos (drawer/modal lazy, morphdom, etc)
if (typeof MutationObserver !== 'undefined') {
    const _madAlpineObs = new MutationObserver((muts) => {
        for (const m of muts) {
            for (const node of m.addedNodes) {
                if (node.nodeType === 1) _madRewriteAlpineAttrs(node);
            }
        }
    });
    if (document.body) {
        _madAlpineObs.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', () => {
            _madAlpineObs.observe(document.body, { childList: true, subtree: true });
        });
    }
}

document.addEventListener('alpine:init', () => {

    /* ═══════════════════════════════════════════════════════════════
       1. madMegamenu — dropdown de apps com busca e filtro
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madMegamenu()" @keydown.escape="close()" style="position:relative">

           <!-- trigger -->
           <button @click="toggle()" :aria-expanded="open">Apps</button>

           <!-- dropdown -->
           <div class="mad-megamenu" x-show="open" x-cloak @click.outside="close()"
                x-transition:enter="mad-fade-in" x-transition:leave="mad-fade-out">

             <div class="mad-megamenu-search">
               <div class="mad-megamenu-search-icon">🔍</div>
               <input class="mad-megamenu-search-input" x-model="query"
                      placeholder="Buscar módulo..." @keydown.escape="close()">
             </div>

             <div class="mad-megamenu-grid">
               <!-- Coluna de módulo -->
               <div class="mad-megamenu-col" x-show="groupVisible(['Contas a Pagar','Contas a Receber'])">
                 <div class="mad-megamenu-module-header">
                   <div class="mad-megamenu-module-icon">💰</div>
                   <span class="mad-megamenu-module-title">Financeiro</span>
                 </div>
                 <div class="mad-megamenu-items">
                   <a class="mad-megamenu-item" x-show="matches('Contas a Pagar')" href="#">
                     <div class="mad-megamenu-item-icon"><i data-lucide="receipt"></i></div>
                     <span>Contas a Pagar</span>
                   </a>
                   <a class="mad-megamenu-item" x-show="matches('Contas a Receber')" href="#">
                     <div class="mad-megamenu-item-icon"><i data-lucide="hand-coins"></i></div>
                     <span>Contas a Receber</span>
                   </a>
                 </div>
               </div>
             </div>
           </div>
         </div>
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madMegamenu', () => ({
        open: false,
        query: '',

        toggle() {
            this.open = !this.open;
            if (this.open) {
                this.$nextTick(() => {
                    const input = this.$el.querySelector('.mad-megamenu-search-input');
                    if (input) input.focus();
                });
            }
        },

        close() {
            this.open = false;
            this.query = '';
        },

        /** Retorna true se o label do item contém a query */
        matches(label) {
            if (!this.query.trim()) return true;
            return label.toLowerCase().includes(this.query.toLowerCase());
        },

        /** Retorna true se ALGUM item do grupo é visível (para ocultar colunas vazias) */
        groupVisible(labels) {
            if (!this.query.trim()) return true;
            return labels.some(l => l.toLowerCase().includes(this.query.toLowerCase()));
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       2. madTabs — troca de abas com painéis
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madTabs('aba-geral')">

           <div class="mad-tabs-list">
             <button class="mad-tab" :class="{'mad-tab-active': isActive('aba-geral')}"
                     @click="select('aba-geral')">Geral</button>
             <button class="mad-tab" :class="{'mad-tab-active': isActive('aba-avancado')}"
                     @click="select('aba-avancado')">Avançado</button>
           </div>

           <div class="mad-tab-content" x-show="isActive('aba-geral')">...</div>
           <div class="mad-tab-content" x-show="isActive('aba-avancado')" x-cloak>...</div>

         </div>
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madTabs', (defaultTab = null) => ({
        active: defaultTab,

        init() {
            // Se não definido, ativa o primeiro tab encontrado
            if (!this.active) {
                const first = this.$el.querySelector('[data-tab]');
                if (first) this.active = first.dataset.tab;
            }
        },

        select(tab) {
            this.active = tab;
            this.$dispatch('mad-tab-change', { tab });
        },

        isActive(tab) {
            return this.active === tab;
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       3. madSelectCards — seleção exclusiva entre cards visuais
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madSelectCards('job')">

           <!-- campo hidden sincronizado -->
           <input type="hidden" name="type" :value="selected">

           <div class="mad-select-cards">
             <div class="mad-select-card" :class="{'mad-select-card-active': isSelected('job')}"
                  @click="select('job')" :aria-selected="isSelected('job')">
               <div class="mad-select-card-icon">⚡</div>
               <span class="mad-select-card-title">Job</span>
               <span class="mad-select-card-desc">Método handle()</span>
             </div>
             <div class="mad-select-card" :class="{'mad-select-card-active': isSelected('method')}"
                  @click="select('method')" :aria-selected="isSelected('method')">
               <div class="mad-select-card-icon">🔧</div>
               <span class="mad-select-card-title">Method</span>
               <span class="mad-select-card-desc">Método específico</span>
             </div>
           </div>

         </div>
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madSelectCards', (defaultValue = null) => ({
        selected: defaultValue,

        select(value) {
            this.selected = value;
            this.$dispatch('mad-select-card-change', { value });
        },

        isSelected(value) {
            return this.selected === value;
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       4. madSubmenu — painel lateral com accordion e item ativo
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madSubmenu('financeiro')" class="mad-submenu">

           <div class="mad-submenu-title">Financeiro</div>
           <div class="mad-submenu-list">

             <!-- Item simples -->
             <a class="mad-submenu-item" :class="{'mad-active': isActive('pagar')}"
                @click="setActive('pagar')" href="#">
               <i data-lucide="receipt"></i> Contas a Pagar
             </a>

             <!-- Item expansível (accordion) -->
             <a class="mad-submenu-item" @click.prevent="toggleGroup('relatorios')">
               <i data-lucide="chart-bar"></i>
               Relatórios
               <span style="margin-left:auto;font-size:10px"
                     x-text="isGroupOpen('relatorios') ? '▲' : '▼'"></span>
             </a>
             <div class="mad-submenu-children" x-show="isGroupOpen('relatorios')">
               <a class="mad-submenu-item" :class="{'mad-active': isActive('dre')}"
                  @click="setActive('dre')" href="#">DRE</a>
               <a class="mad-submenu-item" :class="{'mad-active': isActive('extrato')}"
                  @click="setActive('extrato')" href="#">Extrato</a>
             </div>

           </div>
         </div>

       Parâmetros:
         madSubmenu(activeItem, openGroups)
           activeItem  — string: id do item ativo inicial  (default: null)
           openGroups  — array:  grupos abertos inicialmente (default: [])
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madSubmenu', (activeItem = null, openGroups = []) => ({
        activeItem,
        openGroups: Array.isArray(openGroups) ? [...openGroups] : [openGroups].filter(Boolean),

        setActive(id) {
            this.activeItem = id;
            this.$dispatch('mad-submenu-change', { item: id });
        },

        isActive(id) {
            return this.activeItem === id;
        },

        toggleGroup(id) {
            const idx = this.openGroups.indexOf(id);
            if (idx === -1) this.openGroups.push(id);
            else           this.openGroups.splice(idx, 1);
        },

        openGroup(id) {
            if (!this.openGroups.includes(id)) this.openGroups.push(id);
        },

        closeGroup(id) {
            this.openGroups = this.openGroups.filter(g => g !== id);
        },

        isGroupOpen(id) {
            return this.openGroups.includes(id);
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       5. madRail — sidebar de ícones com módulo ativo
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madRail('financeiro')" class="mad-rail">
           <div class="mad-rail-pill">
             <div class="mad-rail-modules">
               <a class="mad-rail-item" :class="{'mad-active': isActive('financeiro')}"
                  @click="select('financeiro')" title="Financeiro">💰</a>
               <a class="mad-rail-item" :class="{'mad-active': isActive('estoque')}"
                  @click="select('estoque')" title="Estoque">📦</a>
             </div>
             <div class="mad-rail-light">
               <a class="mad-rail-item" title="Configurações">⚙</a>
             </div>
           </div>
         </div>

         <!-- Para sincronizar o submenu -->
         <div x-data="madSubmenu()"
              @mad-rail-change.window="setActive($event.detail.module)">
           ...
         </div>
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madRail', (defaultModule = null) => ({
        active: defaultModule,

        select(id) {
            this.active = id;
            this.$dispatch('mad-rail-change', { module: id });
        },

        isActive(id) {
            return this.active === id;
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       6. madDropdown — dropdown genérico (open/close + outside click)
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madDropdown()" style="position:relative"
              @keydown.escape="close()">

           <button @click="toggle()" :aria-expanded="open">
             Opções ▾
           </button>

           <div class="mad-card mad-shadow-lg"
                style="position:absolute;top:calc(100%+4px);right:0;min-width:160px;z-index:50"
                x-show="open" x-cloak @click.outside="close()"
                x-transition:enter-start="mad-opacity-0"
                x-transition:enter-end="mad-opacity-100">
             <div class="mad-card-content mad-stack-1">
               <button class="mad-btn mad-btn-ghost" @click="close()">Editar</button>
               <button class="mad-btn mad-btn-ghost" @click="close()">Excluir</button>
             </div>
           </div>

         </div>
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madDropdown', () => ({
        open: false,

        toggle() { this.open = !this.open; },
        close()  { this.open = false; },
        open_()  { this.open = true; }, // alias para evitar conflito com prop 'open'
    }));


    /* ═══════════════════════════════════════════════════════════════
       6b. madContextMenu — menu de contexto (right-click)
       ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madContextMenu', () => ({
        open: false,
        x: 0,
        y: 0,

        openAt(e) {
            var rect = this.$el.getBoundingClientRect();
            this.x = e.clientX - rect.left;
            this.y = e.clientY - rect.top;
            this.open = true;

            this.$nextTick(() => {
                var menu = this.$el.querySelector('.mad-context-menu');
                if (!menu) return;
                var mr = menu.getBoundingClientRect();
                var vw = window.innerWidth;
                var vh = window.innerHeight;
                if (mr.right > vw - 8) this.x -= (mr.right - vw + 8);
                if (mr.bottom > vh - 8) this.y -= (mr.bottom - vh + 8);
                if (this.x < 0) this.x = 0;
                if (this.y < 0) this.y = 0;

                if (window.lucide) lucide.createIcons({ nodes: menu.querySelectorAll('[data-lucide]') });
            });
        },

        close() { this.open = false; },

        menuStyle() {
            return 'left:' + this.x + 'px;top:' + this.y + 'px;';
        }
    }));


    /* ═══════════════════════════════════════════════════════════════
       7. madAlert — alerta dismissível com animação
       ═══════════════════════════════════════════════════════════════

       HTML:
         <div x-data="madAlert()" x-show="visible" x-cloak
              class="mad-alert mad-alert-info">
           <div class="mad-alert-icon">ℹ️</div>
           <div class="mad-flex-1">
             <div class="mad-alert-title">Título</div>
             <div class="mad-alert-desc">Descrição aqui.</div>
           </div>
           <button @click="dismiss()" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon">✕</button>
         </div>

       Parâmetros:
         madAlert(autoDismiss)
           autoDismiss — ms para fechar automaticamente (0 = nunca, default: 0)
    ═══════════════════════════════════════════════════════════════ */
    Alpine.data('madAlert', (autoDismiss = 0) => ({
        visible: true,

        init() {
            if (autoDismiss > 0) {
                setTimeout(() => this.dismiss(), autoDismiss);
            }
        },

        dismiss() {
            this.visible = false;
            this.$dispatch('mad-alert-dismissed');
        },
    }));


    /* ═══════════════════════════════════════════════════════════════
       8. madToastRegion — fila de notificações por posição
       ═══════════════════════════════════════════════════════════════

       Criado automaticamente via JS — não precisa de HTML no template.

       DISPARO:
         madToast({ message: 'Salvo!', type: 'success' })
         madToast({ message: 'Erro!',  type: 'danger',  position: 'top-center' })

       Posições: top-left | top-center | top-right (padrão)
                 bottom-left | bottom-center | bottom-right

       Parâmetros:
         message   — string (obrigatório)
         type      — info | success | warning | danger   (padrão: info)
         title     — string opcional
         icon      — string/emoji opcional
         duration  — ms (padrão: 3500, 0 = não fecha)
         position  — string (padrão: top-right)
    ═══════════════════════════════════════════════════════════════ */
    /* ── Sonner-style toast (Alpine component) ── */

    Alpine.data('madSonnerToaster', (myPosition = 'bottom-right') => ({
        toasts: [],
        heights: [],
        expanded: false,
        interacting: false,
        _position: myPosition,
        _yPos: myPosition.split('-')[0] || 'bottom',
        _xPos: myPosition.split('-')[1] || 'right',

        get frontHeight() {
            return this.heights.length > 0 ? this.heights[0].height : 0;
        },

        onEvent(detail) {
            const pos = detail.position || 'bottom-right';
            if (pos !== this._position) return;
            this.add(detail);
        },

        add({ message = '', type = 'info', title = '', duration = _SONNER_LIFETIME }) {
            const id = Date.now() + Math.random();
            this.toasts.unshift({
                id, message, title, type, duration,
                mounted: false, removed: false,
                swiping: false, swipeOut: false, swipeDir: null,
                swipeAmountX: 0, swipeAmountY: 0,
                initialHeight: 0, offsetBeforeRemove: 0,
                timeoutId: null, remaining: duration, closeTimerStart: 0,
                pointerStart: null, swipeLock: null, dragStartTime: 0,
            });
            requestAnimationFrame(() => {
                const t = this.toasts.find(t => t.id === id);
                if (t) t.mounted = true;
            });
        },

        deleteToast(toast) {
            if (toast.removed) return;
            toast.removed = true;
            toast.offsetBeforeRemove = this._getOffset(this.toasts.indexOf(toast));
            this.heights = this.heights.filter(h => h.id !== toast.id);
            if (toast.timeoutId) clearTimeout(toast.timeoutId);
            setTimeout(() => {
                this.toasts = this.toasts.filter(t => t.id !== toast.id);
            }, _SONNER_UNMOUNT_MS);
        },

        measureHeight(toast, el) {
            if (!el || toast.removed) return;
            const h = el.getBoundingClientRect().height;
            if (h <= 0) return;
            toast.initialHeight = h;
            const existing = this.heights.find(x => x.id === toast.id);
            if (existing) { existing.height = h; }
            else { this.heights.unshift({ id: toast.id, height: h }); }
            this._scheduleTimer(toast);
        },

        _getOffset(idx) {
            if (idx < 0 || idx >= this.toasts.length) return 0;
            const tid = this.toasts[idx].id;
            let hi = this.heights.findIndex(x => x.id === tid);
            if (hi < 0) return 0;
            let offset = 0;
            for (let i = 0; i < hi; i++) offset += this.heights[i].height;
            return hi * _SONNER_GAP + offset;
        },

        getIcon(type) { return _sonnerIcons[type] || _sonnerIcons.info; },

        toastStyle(idx) {
            const t = this.toasts[idx];
            if (!t) return {};
            return {
                '--index': idx,
                '--toasts-before': idx,
                '--z-index': this.toasts.length - idx,
                '--offset': (t.removed ? t.offsetBeforeRemove : this._getOffset(idx)) + 'px',
                '--initial-height': t.initialHeight + 'px',
                '--swipe-amount-x': t.swipeAmountX + 'px',
                '--swipe-amount-y': t.swipeAmountY + 'px',
            };
        },

        _scheduleTimer(toast) {
            if (toast.duration <= 0 || toast.removed || toast.timeoutId) return;
            if (this.expanded || this.interacting) return;
            toast.closeTimerStart = Date.now();
            toast.timeoutId = setTimeout(() => this.deleteToast(toast), toast.remaining);
        },
        _pauseTimers() {
            for (const t of this.toasts) {
                if (t.timeoutId && t.closeTimerStart) {
                    t.remaining = Math.max(0, t.remaining - (Date.now() - t.closeTimerStart));
                    clearTimeout(t.timeoutId);
                    t.timeoutId = null;
                }
            }
        },
        _resumeTimers() {
            for (const t of this.toasts) {
                if (!t.removed && !t.timeoutId && t.duration > 0) this._scheduleTimer(t);
            }
        },

        onPointerDown(e, toast, idx) {
            if (e.button === 2) return;
            if (e.target.closest('.mad-toast-close')) return; // don't swipe on close btn
            this.interacting = true;
            toast.dragStartTime = Date.now();
            toast.offsetBeforeRemove = this._getOffset(idx);
            toast.pointerStart = { x: e.clientX, y: e.clientY };
            toast.swipeLock = null;
            toast.swiping = true;
            try { e.target.closest('.mad-toast').setPointerCapture(e.pointerId); } catch(ex) {}
        },
        onPointerMove(e, toast) {
            if (!toast.pointerStart) return;
            const xd = e.clientX - toast.pointerStart.x;
            const yd = e.clientY - toast.pointerStart.y;
            if (!toast.swipeLock && (Math.abs(xd) > 1 || Math.abs(yd) > 1)) {
                toast.swipeLock = Math.abs(xd) > Math.abs(yd) ? 'x' : 'y';
            }
            if (toast.swipeLock === 'x') {
                toast.swipeAmountX = xd; toast.swipeAmountY = 0;
            } else if (toast.swipeLock === 'y') {
                toast.swipeAmountY = yd > 0 ? yd : yd / (1.5 + Math.abs(yd) / 20);
                toast.swipeAmountX = 0;
            }
        },
        onPointerUp(e, toast) {
            if (!toast.pointerStart) return;
            this.interacting = false;
            const amount = toast.swipeLock === 'x' ? Math.abs(toast.swipeAmountX) : Math.abs(toast.swipeAmountY);
            const velocity = amount / (Date.now() - toast.dragStartTime || 1);
            if (amount >= _SONNER_SWIPE_THRESHOLD || velocity > 0.11) {
                toast.swipeDir = toast.swipeLock === 'x'
                    ? (toast.swipeAmountX > 0 ? 'right' : 'left')
                    : (toast.swipeAmountY > 0 ? 'down' : 'up');
                toast.swipeOut = true;
                this.deleteToast(toast);
                return;
            }
            toast.swipeAmountX = 0; toast.swipeAmountY = 0;
            toast.swiping = false; toast.swipeLock = null; toast.pointerStart = null;
        },

        init() {
            this.$watch('expanded', v => v ? this._pauseTimers() : this._resumeTimers());
        },
    }));

});


/* ═══════════════════════════════════════════════════════════════════
   FIELD-LIST onChange — handler para data-mad-fl-change
   ═══════════════════════════════════════════════════════════════════

   Quando um select/input dentro de um field-list tem data-mad-fl-change="metodo",
   ao mudar de valor:
     1. Envia AJAX para o MadComponent com o valor selecionado
     2. Recebe MadResponse com ops específicos do field-list:
        - fl_combo: popula um <select> na mesma row
        - fl_val:   define o value de um campo na mesma row
     3. Aplica os ops na row de origem
*/
document.addEventListener('change', function(e) {
    var el     = e.target;
    var action = el.getAttribute('data-mad-fl-change');
    if (!action) return;

    var wrapper = _madOwnerComponent(el);
    var row     = el.closest('.mad-fl-row');
    if (!wrapper || !row) return;

    var endpoint = wrapper.getAttribute('mad-endpoint');
    if (!endpoint) return;

    // Em fila com os outros pedidos do componente (ver _madWireQueued): o
    // pedido é montado na hora de enviar, com o estado que a resposta anterior
    // deixou e a linha como está na tela.
    _madWireQueued(wrapper, function(wrapper) {
    var body = new FormData();
    body.append('mad_state',  wrapper.getAttribute('mad-state'));
    body.append('mad_id',     wrapper.getAttribute('mad-id'));
    body.append('mad_action', action);
    // Coleta dados da row atual (todos os inputs nomeados)
    var rowData = {};
    row.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(inp) {
        var name = inp.name.replace(/\[\]$/, '');
        if (!rowData.hasOwnProperty(name)) rowData[name] = inp.value;
    });
    body.append('mad_params', JSON.stringify([el.value, rowData]));

    // Coleta mad:model values do formulário (necessário para getData() no PHP)
    wrapper.querySelectorAll('[data-mad-model], [data-mad-model-live]').forEach(function(mel) {
        var prop = (mel.dataset.madModel || mel.dataset.madModelLive || '').trim();
        if (prop) body.append('mad_model[' + prop + ']', mel.type === 'checkbox' ? (mel.checked ? '1' : '0') : mel.value);
    });

    // Inclui o schema token do formulário (para transformação de datas, etc.)
    var formToken = wrapper.querySelector('input[name="__mad_form"]');
    if (formToken) body.append('__mad_form', formToken.value);

    // Coleta dados de field-lists (rows Alpine) se houver
    if (typeof _madCollectFieldLists === 'function') {
        _madCollectFieldLists(wrapper, body);
    }

    // CSRF: o entry point do wire valida o X-CSRF-TOKEN (ver _madWireHeaders).
    // Sem ele a requisição volta 403 e o onChange do field-list não dispara.
    return fetch(endpoint, { method: 'POST', body: body, headers: _madWireHeaders() })
        .then(function(r) { return _madWireJson(r, { source: 'mad-fl-change', method: 'POST' }); })
        .then(function(data) {
            if (!data) return;   // erro do servidor já exibido (MadErrorModal)
            if (data.error) {
                console.error('[mad-fl-change]', data.error);
                return;
            }
            // Atualiza o state criptografado no wrapper
            _madSyncWireState(wrapper, data);
            // Aplica ops do field-list na row de origem; o resto (toast, bind,
            // alert…) com as regras do wire, no escopo do componente.
            var ops = data.ops || [];
            var others = [];
            ops.forEach(function(op) {
                if (op.op === 'fl_combo') {
                    _madFlApplyCombo(row, op.target, op.options);
                } else if (op.op === 'fl_val') {
                    _madFlApplyVal(row, op.target, op.content);
                } else {
                    others.push(op);
                }
            });
            _madApplyWireOps(others, wrapper);
        })
        .catch(function(err) {
            console.error('[mad-fl-change] Erro de rede:', err);
        });
    });
});

/**
 * Popula um <select> dentro de uma row do field-list.
 * Trata MAD Select (destroy/reinit) e sincroniza Alpine x-model.
 */
function _madFlApplyCombo(row, targetName, options) {
    var target = row.querySelector('select[name="' + targetName + '[]"]');
    if (!target) return;

    // Reconstrói as options no <select> nativo (fonte da verdade)
    target.innerHTML = '<option value="">Selecione...</option>';
    Object.keys(options).forEach(function(k) {
        var opt = document.createElement('option');
        opt.value = k;
        opt.textContent = options[k];
        target.appendChild(opt);
    });

    // Limpa o valor selecionado e sincroniza Alpine
    target.value = '';
    target.dispatchEvent(new Event('input', { bubbles: true }));

    // MAD Select: re-projeta a UI; se ainda não montado, monta agora
    if (target._madSelect) target._madSelect.refreshOptions(true);
    else if (target.hasAttribute('data-mad-select')) _madCreateSelect(target);
}

/**
 * Registry global de options atualizadas por setItems('campo[]').
 * Usado pelo addRow() para aplicar options corretas em novas rows.
 */
var _madFlComboOptionsCache = {};

/**
 * Aplica fl_combo em TODAS as rows de field-lists dentro de um container,
 * e cacheia as options para que novas rows criadas via addRow() já venham corretas.
 *
 * Usada pelo setItems('campo[]') via MadWire (global, diferente do on-change que é por row).
 */
function _madFlApplyComboAll(container, targetName, options) {
    // 1) Cacheia para novas rows
    _madFlComboOptionsCache[targetName] = options;

    // 2) Atualiza rows existentes
    container.querySelectorAll('.mad-fl-row').forEach(function(row) {
        _madFlApplyCombo(row, targetName, options);
    });
}

/**
 * Aplica options cacheadas (via setItems('campo[]')) em uma row recém-criada.
 * Chamada pelo x-init de cada row do field-list, APÓS _madInitSelects.
 */
function _madFlApplyCachedOptions(row) {
    if (typeof _madFlComboOptionsCache === 'undefined') return;
    for (var field in _madFlComboOptionsCache) {
        _madFlApplyCombo(row, field, _madFlComboOptionsCache[field]);
    }
}

/**
 * Define o valor de um campo (input/select/textarea) dentro de uma row do field-list.
 * Sincroniza com Alpine x-model via evento input.
 */
function _madFlApplyVal(row, targetName, value) {
    var target = row.querySelector('[name="' + targetName + '[]"]');
    if (!target) return;

    // Se tem MAD Select, usa a API dele
    if (target._madSelect) {
        target._madSelect.setValue(value, true);
        return;
    }

    target.value = value;
    // Dispara evento para sincronizar Alpine x-model
    target.dispatchEvent(new Event('input', { bubbles: true }));

    // Campo mascarado de FORM (numeric/money) dentro da row: o componente
    // reformata com as próprias casas decimais.
    if (_madSyncMaskedField(target)) return;

    // Se é campo money (hidden dentro de .mad-fl-money), atualiza o display visível
    var moneyWrap = target.closest('.mad-fl-money');
    if (moneyWrap) {
        var visible = moneyWrap.querySelector('input[type="text"]');
        if (visible) {
            visible.value = madNumFmt(parseFloat(value) || 0, 2);
        }
    }
}


/* ═══════════════════════════════════════════════════════════════════
   FIELD-LIST depends-on — Camada 3: auto-reload quando campo pai muda
   ═══════════════════════════════════════════════════════════════════

   Quando um select com data-mad-fl-depends="campo_pai" existe na row,
   e o campo pai muda de valor, faz AJAX genérico para carregar
   options filtradas e aplica via _madFlApplyCombo.
   Suporta N níveis de cascata (Estado → Cidade → Bairro).
*/
document.addEventListener('change', function(e) {
    var el  = e.target;
    var row = el.closest('.mad-fl-row');
    if (!row) return;

    // Descobre o nome do campo que mudou
    var fieldName = (el.name || '').replace(/\[\]$/, '');
    if (!fieldName) return;

    // Encontra selects dependentes deste campo na mesma row
    var deps = row.querySelectorAll('[data-mad-fl-depends="' + fieldName + '"]');
    if (!deps.length) return;

    var wrapper  = _madOwnerComponent(el);
    if (!wrapper) return;
    var endpoint = wrapper.getAttribute('mad-endpoint');
    if (!endpoint) return;

    deps.forEach(function(dep) {
        var parentValue = el.value;
        var targetName  = (dep.name || '').replace(/\[\]$/, '');

        if (!parentValue) {
            // Pai vazio → limpa o dependente
            _madFlApplyCombo(row, targetName, {});
            return;
        }

        // Em fila (ver _madWireQueued), com o estado da hora do envio.
        _madWireQueued(wrapper, function(wrapper) {
        var body = new FormData();
        body.append('mad_state',  wrapper.getAttribute('mad-state'));
        body.append('mad_id',     wrapper.getAttribute('mad-id'));
        body.append('mad_action', '_madFlDependentOptions');
        // Cascata AJAX usa token criptografado (padrao do <mad-dbcombo-field>):
        // cliente envia apenas { target, token, value } — model/database/column
        // ficam opacos, sem possibilidade de manipulacao client-side.
        body.append('mad_params', JSON.stringify({
            target: targetName,
            token:  dep.dataset.madFlDepToken || '',
            value:  parentValue
        }));

        return fetch(endpoint, { method: 'POST', body: body, headers: _madWireHeaders() })
            .then(function(r) { return _madWireJson(r, { source: 'mad-fl-depends', method: 'POST' }); })
            .then(function(data) {
                if (!data) return;   // erro do servidor já exibido (MadErrorModal)
                if (data.error) {
                    console.error('[mad-fl-depends]', data.error);
                    if (typeof MadDialog !== 'undefined' && MadDialog.show) {
                        MadDialog.show({ type: 'error', title: 'Erro', message: String(data.error) });
                    }
                    return;
                }
                _madSyncWireState(wrapper, data);
                // O pai mudou de novo enquanto esta resposta vinha (ou foi
                // limpo, o que não gera pedido): as opções são de um valor
                // que já não está na tela.
                if (String(el.value === undefined || el.value === null ? '' : el.value) !== String(parentValue)) return;

                // Separa fl_combo (escopo row) dos demais ops (regras do wire, ver _madApplyWireOps).
                // Sem esse split, alert/script/toast/dump_modal/reload_combo etc. eram
                // silenciosamente ignorados — incluindo os alertas injetados pelo handler
                // quando TExceptionView emite __mad_error em modo debug.
                var rowOps    = [];
                var globalOps = [];
                (data.ops || []).forEach(function(op) {
                    if (op.op === 'fl_combo') rowOps.push(op);
                    else                      globalOps.push(op);
                });

                rowOps.forEach(function(op) {
                    _madFlApplyCombo(row, op.target, op.options);
                });

                _madApplyWireOps(globalOps, wrapper);
            })
            .catch(function(err) {
                console.error('[mad-fl-depends] Erro:', err);
                if (typeof MadDialog !== 'undefined' && MadDialog.show) {
                    MadDialog.show({ type: 'error', title: 'Erro de rede', message: String(err && err.message || err) });
                }
            });
        });
    });
});

/* ═══════════════════════════════════════════════════════════════════
   FIELD-LIST auto-fire — Camada 2: dispara onChange em selects populados no init
   ═══════════════════════════════════════════════════════════════════

   Após render de rows existentes (edição), auto-dispara o 'change' event
   em selects que têm data-mad-fl-change e já possuem valor.
   Útil quando o dev usa on-change sem depends-on para popular dependentes.

   Otimização: agrupa por (método, valor) para evitar N requests iguais.
   Usa MutationObserver para detectar quando rows são inseridas no DOM.
*/
(function() {
    var _firing = false;

    function _madFlAutoFire(flContainer) {
        if (_firing) return;
        _firing = true;

        setTimeout(function() {
            // Coleta selects com onChange que já têm valor
            var selects = flContainer.querySelectorAll('.mad-fl-row select[data-mad-fl-change]');
            var seen = {};

            selects.forEach(function(sel) {
                if (!sel.value || sel.value === '') return;
                // Se esse select também tem depends-on, a Camada 3 já cuida dele na mudança do pai.
                // Mas no init, precisamos disparar o onChange para lógica extra (ex: setar preço).
                var key = sel.getAttribute('data-mad-fl-change') + '::' + sel.value;
                if (seen[key]) return;
                seen[key] = true;
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });

            _firing = false;
        }, 100);
    }

    // Observa quando field-lists são inseridos ou atualizados no DOM
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                // Detecta mad-fl inserido ou contendo mad-fl
                var fls = [];
                if (node.classList && node.classList.contains('mad-fl')) fls.push(node);
                if (node.querySelectorAll) {
                    node.querySelectorAll('.mad-fl').forEach(function(fl) { fls.push(fl); });
                }
                fls.forEach(function(fl) {
                    // Só auto-fire se o field-list já tem rows renderizadas
                    if (fl.querySelectorAll('.mad-fl-row').length > 0) {
                        _madFlAutoFire(fl);
                    }
                });
            });
        });
    });

    observer.observe(document.body, { childList: true, subtree: true });
})();


/* ═══════════════════════════════════════════════════════════════════
   NO-RESULTS helpers — "cadastrar novo" + "quick register" no dropdown
   do MAD Select
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Renderiza um campo do quick-form (modo multi-campo). Suporta type=text,
 * number, email, tel, password, date e select (options pre-resolvidas).
 */
function _madRenderQuickField(f) {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    var name        = esc(f.name);
    var label       = esc(f.label || f.name);
    var placeholder = esc(f.placeholder || '');
    var value       = esc(f.value || '');
    var required    = f.required ? ' required' : '';
    var type        = (f.type || 'text').toLowerCase();
    var html  = '<div class="mad-ts-noresults-quick-field">';
    html += '<label class="mad-label">' + label + (f.required ? ' <span class="mad-required">*</span>' : '') + '</label>';
    if (type === 'select') {
        // data-mad-select: entrega o <select> ao auto-mount do MAD Select
        // (busca + teclado + highlight). Sem esse atributo o combo do
        // <mad-quick-form> ficava <select> NATIVO — o `dbcombo` vira
        // type=select com options pré-carregadas no servidor
        // (MadNoResultsHelper::resolveQuickFields), então era exatamente o
        // caso do usuário: combo com 200 países e zero campo de busca.
        html += '<select class="mad-input mad-select" data-mad-select '
              + 'data-mad-noresults-quick-field="' + name + '"'
              + (f.token ? ' data-mad-quick-token="' + esc(f.token) + '"' : '')
              + (placeholder ? ' data-placeholder="' + placeholder + '"' : '')
              + required + '>';
        html += '<option value="">' + (placeholder || 'Selecione...') + '</option>';
        (f.options || []).forEach(function (opt) {
            var sel = (value !== '' && String(opt.value) === value) ? ' selected' : '';
            html += '<option value="' + esc(opt.value) + '"' + sel + '>' + esc(opt.label) + '</option>';
        });
        html += '</select>';
    } else {
        var allowed = ['text', 'number', 'email', 'tel', 'password', 'date'];
        var itype = allowed.indexOf(type) >= 0 ? type : 'text';
        html += '<input type="' + itype + '" class="mad-input" '
              + 'data-mad-noresults-quick-field="' + name + '" '
              + 'placeholder="' + placeholder + '" '
              + 'value="' + value + '"'
              + required + '>';
    }
    html += '</div>';
    return html;
}
window._madRenderQuickField = _madRenderQuickField;

/**
 * Recarrega as options das dbcombos internas do popover do quick-form
 * (selects com data-mad-quick-token).
 *
 * As options renderizadas vieram CONGELADAS do payload do render do form
 * host — um registro criado depois (ex.: pais novo via no-results-create de
 * outra combo na mesma tela) nao estaria aqui. O token (cifrado server-side
 * pelo MadNoResultsHelper, flag `full`) re-busca a lista completa no
 * MadDbComboService na abertura do popover. Falha ou lista vazia mantem as
 * options congeladas — degrada exatamente pro comportamento antigo.
 */
function _madRefreshQuickCombos(pop) {
    pop.querySelectorAll('select[data-mad-quick-token]').forEach(function (sel) {
        var token = sel.getAttribute('data-mad-quick-token') || '';
        if (!token) return;
        fetch(_madServiceUrl('db-combo', 'load', { token: token }))
            .then(function (r) { return r.text(); })
            .then(function (text) { return _madExtractJson(text) || { options: [] }; })
            .then(function (data) {
                // Popover fechado/reaberto antes da resposta — select destacado
                if (!document.body.contains(sel)) return;
                _madOptionsError(sel, data.error);
                var list = Array.isArray(data.options) ? data.options : [];
                if (!list.length) return;
                var prev      = sel.value;
                var prevLabel = prev && sel.selectedOptions.length ? sel.selectedOptions[0].textContent : '';
                var ph = sel.querySelector('option[value=""]');
                sel.innerHTML = '';
                if (ph) sel.appendChild(ph);
                var found = false;
                list.forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = String(o.value);
                    opt.textContent = String(o.label);
                    if (prev && opt.value === prev) { opt.selected = true; found = true; }
                    sel.appendChild(opt);
                });
                if (prev && !found) {
                    // Prefill sumiu da lista nova — preserva a selecao com
                    // option sintetica em vez de zerar o campo por baixo do usuario.
                    var keep = document.createElement('option');
                    keep.value = prev;
                    keep.textContent = prevLabel || prev;
                    keep.selected = true;
                    sel.appendChild(keep);
                }
                if (prev) sel.value = prev;
                // Re-projeta o MAD Select SEM disparar change — change acionaria
                // os listeners globais de depends-on/autofill do form host.
                if (sel._madSelect && sel._madSelect.refreshOptions) sel._madSelect.refreshOptions(true);
            })
            .catch(function (err) { console.warn('[mad-quick] refresh de combo falhou', err); });
    });
}
window._madRefreshQuickCombos = _madRefreshQuickCombos;

/**
 * Anexa params na query string de uma URL ja montada, preservando o que ja
 * existe nela (a rota amigavel pode vir com ?static=1, ?_forward_param_*, ...).
 * Canonico em mad.js (Mad._urlWithParams); a copia local so cobre o caso de o
 * mad.js nao ter carregado.
 */
function _madUrlWithParams(url, params) {
    if (window.Mad && typeof Mad._urlWithParams === 'function') {
        return Mad._urlWithParams(url, params);
    }
    var u = String(url == null ? '' : url);
    var qs = new URLSearchParams(params || {}).toString();
    if (!qs) return u;
    return u + (u.indexOf('?') >= 0 ? '&' : '?') + qs;
}
window._madUrlWithParams = _madUrlWithParams;

/**
 * Le o payload no-results de um <select data-mad-noresults-payload> e devolve
 * { config } (ou null se nao configurado). O MAD Select consome config para
 * renderizar o bloco no-results no dropdown e disparar create/quick.
 */
function _madBuildNoResultsHelpers(el) {
    var payload = el.dataset.madNoresultsPayload;
    if (!payload) return null;
    var cfg;
    try {
        // atob() devolve string binaria (Latin-1). O payload e base64 de JSON
        // UTF-8 — decodifica os bytes como UTF-8, senao acentos viram mojibake
        // (ex.: "Pais"->"PaAs", "Codigo"->"CA3digo").
        var _bin = atob(payload), _bytes = new Uint8Array(_bin.length);
        for (var _i = 0; _i < _bin.length; _i++) _bytes[_i] = _bin.charCodeAt(_i);
        cfg = JSON.parse(new TextDecoder('utf-8').decode(_bytes));
    }
    catch (e) { console.warn('[mad-noresults] payload invalido', e); return null; }
    return { config: cfg };
}
window._madBuildNoResultsHelpers = _madBuildNoResultsHelpers;

/**
 * Abre o quick-form em um popover flutuante ancorado no combo.
 *
 * Usado quando ha multiplos campos (cfg.quick.fields). E uma UX bem melhor
 * que renderizar o form dentro do dropdown apertado.
 *
 *   - Cria um <div class="mad-quick-popover"> e teleporta para body
 *   - Renderiza header (titulo + close), body (campos), footer (cancel + submit)
 *   - Posiciona embaixo do combo (ou em cima se nao couber)
 *   - Pre-preenche o primeiro campo com o termo digitado no combo
 *   - Esc / click fora / cancel → fecha
 *   - Enter num input → submit
 *   - Ao salvar com sucesso, popover fecha + combo recebe option nova selecionada
 */
function _madShowQuickFormPopover(cfg, ts) {
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function iconHtml(iconName) {
        if (!iconName) return '';
        return '<i data-lucide="' + esc(iconName) + '" style="width:14px;height:14px;"></i> ';
    }

    // Remove popover anterior se existir
    document.querySelectorAll('.mad-quick-popover-active').forEach(function (el) { el.remove(); });

    var pop = document.createElement('div');
    pop.className = 'mad-quick-popover mad-quick-popover-active mad-ui';
    pop.setAttribute('role', 'dialog');

    var fields = cfg.quick.fields || [];
    var title  = cfg.quick.label || 'Cadastrar';
    var isSingleField = !fields.length;

    var html = '<div class="mad-quick-popover-header">'
             + '<div class="mad-quick-popover-title">' + iconHtml(cfg.quick.icon) + esc(title) + '</div>'
             + '<button type="button" class="mad-quick-popover-close" aria-label="Fechar" data-mad-popover-close>'
             + '<i data-lucide="x" style="width:16px;height:16px;"></i>'
             + '</button>'
             + '</div>';

    html += '<div class="mad-quick-popover-body">';
    if (cfg.message) {
        html += '<div class="mad-quick-popover-msg">' + esc(cfg.message) + '</div>';
    }
    if (isSingleField) {
        // Modo simples — sem fields configurados. Renderiza um unico input
        // marcado com data-mad-noresults-quick-input (o _madRunQuickRegister
        // le esse input e envia como `term` ao servidor).
        var ph = cfg.quick.placeholder || 'Digite o valor...';
        html += '<div class="mad-field">'
              + '<input type="text" class="mad-input" placeholder="' + esc(ph) + '" '
              + 'data-mad-noresults-quick-input autofocus>'
              + '</div>';
    } else {
        fields.forEach(function (f) {
            html += _madRenderQuickField(f);
        });
    }
    html += '</div>';

    html += '<div class="mad-quick-popover-footer">'
          + '<button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" data-mad-popover-cancel>Cancelar</button>'
          + '<button type="button" class="' + esc(cfg.quick.btnClass) + '" data-mad-popover-submit>'
          + iconHtml(cfg.quick.icon) + esc(title)
          + '</button>'
          + '</div>';

    pop.innerHTML = html;
    document.body.appendChild(pop);

    // Enhance dos <select data-mad-select> ANTES de medir/posicionar: o wrapper
    // .mad-sel muda a altura do popover. O auto-mount global (MutationObserver)
    // tambem pegaria, mas so no proximo tick — tarde demais pro offsetHeight.
    if (typeof _madEnhanceSelects === 'function') _madEnhanceSelects(pop);

    // Dropdown do MAD Select e teleportado pro <body>: sai do stacking context
    // do popover e disputa z-index na raiz. Esta marca rebaseia --mad-z-float
    // (9999 -> 10060) acima do popover (--mad-z-quick, 10050); sem ela a lista
    // do combo abriria ATRAS do popover. Ver bloco 1.1 do mad-ui.css.
    document.body.classList.add('mad-quick-popover-open');

    // Posicionamento — ancora no wrapper do MAD Select (ou no proprio elemento se nao houver)
    var anchor = ts && ts.wrapper ? ts.wrapper : null;
    if (anchor) {
        var rect = anchor.getBoundingClientRect();
        pop.style.left     = Math.max(8, rect.left) + 'px';
        pop.style.minWidth = Math.max(rect.width, 320) + 'px';
        // Tenta abaixo; se nao couber, posiciona acima
        var below = rect.bottom + 8;
        if (below + pop.offsetHeight > window.innerHeight - 8) {
            var above = rect.top - pop.offsetHeight - 8;
            pop.style.top = (above > 8 ? above : 8) + 'px';
        } else {
            pop.style.top = below + 'px';
        }
        // Garante que nao sai pela direita
        var maxLeft = window.innerWidth - pop.offsetWidth - 8;
        if (parseFloat(pop.style.left) > maxLeft) pop.style.left = Math.max(8, maxLeft) + 'px';
    } else {
        pop.style.top  = '50%';
        pop.style.left = '50%';
        pop.style.transform = 'translate(-50%, -50%)';
    }

    // Pre-preenche o primeiro campo com o termo do combo
    // (funciona tanto para [data-mad-noresults-quick-field] quanto para single-input)
    // Só em <input>: num <select> o term nao casa com nenhum <option> e o
    // assignment ZERA o valor (e, se enhançado, dessincroniza o MAD Select).
    var firstField = pop.querySelector('[data-mad-noresults-quick-field], [data-mad-noresults-quick-input]');
    var term = ts && ts.control_input ? ts.control_input.value.trim() : '';
    if (firstField && term && firstField.tagName === 'INPUT') {
        firstField.value = term;
    }

    // Lucide
    if (typeof lucide !== 'undefined' && lucide.createIcons) {
        try { lucide.createIcons({ nameAttr: 'data-lucide', root: pop }); } catch (e) {}
    }

    // Re-busca as options das dbcombos internas (async, pos-posicionamento:
    // o popover abre instantaneo com as options congeladas do payload e o
    // refresh chega por cima — ver _madRefreshQuickCombos).
    _madRefreshQuickCombos(pop);

    // Foco no primeiro field — se ja tem term, foca no segundo (multi-campo)
    setTimeout(function () {
        // Select enhançado nao e focavel (native oculto + input do control so
        // aparece com o dropdown aberto) — foca o proximo <input> de texto.
        var focusables = [].filter.call(
            pop.querySelectorAll('[data-mad-noresults-quick-field], [data-mad-noresults-quick-input]'),
            function (el) { return el.tagName === 'INPUT' && !el.closest('.mad-sel'); }
        );
        var prefilled = !!(firstField && term && firstField.tagName === 'INPUT');
        var target = (prefilled && focusables.length > 1) ? focusables[1] : focusables[0];
        if (target) {
            target.focus();
            target.select && target.select();
        }
    }, 50);

    // Listeners
    function close() {
        pop.remove();
        document.body.classList.remove('mad-quick-popover-open');
        document.removeEventListener('mousedown', onOutside, true);
        document.removeEventListener('keydown', onKey, true);
    }
    // Ha algum MAD Select do popover com dropdown aberto?
    function hasOpenSelect() {
        var sels = pop.querySelectorAll('select.mad-select-init');
        for (var i = 0; i < sels.length; i++) {
            if (sels[i]._madSelect && sels[i]._madSelect.open) return true;
        }
        return false;
    }
    function onOutside(e) {
        // O dropdown do MAD Select e teleportado pro <body> — clicar numa option
        // e clique "fora" do popover no DOM, mas dentro dele na UI. Sem esta
        // guarda, escolher um item no combo fechava o quick-form inteiro.
        if (e.target && e.target.closest && e.target.closest('.mad-sel-dropdown')) return;
        if (!pop.contains(e.target)) close();
    }
    function onKey(e) {
        if (e.key === 'Escape') {
            // Esc com combo aberto fecha so o dropdown — deixa o evento seguir
            // (o handler do MAD Select esta no wrapper, fase de bubbling: um
            // stopPropagation aqui, na CAPTURA, mataria o fechamento dele).
            if (hasOpenSelect()) return;
            e.stopPropagation();
            close();
        } else if (e.key === 'Enter' && e.target && e.target.matches('[data-mad-noresults-quick-field], [data-mad-noresults-quick-input]') && e.target.tagName !== 'TEXTAREA') {
            e.preventDefault();
            submit();
        }
    }
    function submit() {
        var btn = pop.querySelector('[data-mad-popover-submit]');
        _madRunQuickRegister(cfg, ts, btn, pop);
    }

    pop.querySelector('[data-mad-popover-close]').addEventListener('click', close);
    pop.querySelector('[data-mad-popover-cancel]').addEventListener('click', close);
    pop.querySelector('[data-mad-popover-submit]').addEventListener('click', function (e) {
        e.preventDefault();
        submit();
    });

    // Expoe close para o _madRunQuickRegister fechar em sucesso
    pop._madClose = close;

    // Atrasa o outside-click pra nao fechar imediatamente quando o usuario clicou no botao
    setTimeout(function () {
        document.addEventListener('mousedown', onOutside, true);
        document.addEventListener('keydown', onKey, true);
    }, 50);

    if (ts) ts.close();
    return pop;
}
window._madShowQuickFormPopover = _madShowQuickFormPopover;

/**
 * Executa quick-register via fetch — shared entre o dropdown do MAD Select e o popover.
 *
 * Suporta tres modos:
 *  - simples (cfg.quick.fields vazio): so envia `term`, scope = dropdown
 *  - multi-campo dropdown (legado): envia `field[name]=value` de cada campo do dropdown
 *  - multi-campo popover (recomendado): mesmo que acima mas scope = popover
 *
 * @param {object} cfg config decoded do payload
 * @param {object} ts  MAD Select instance (pode ser null)
 * @param {Element} buttonEl botao que disparou
 * @param {Element=} popoverEl scope override (quando aberto via popover)
 */
function _madRunQuickRegister(cfg, ts, buttonEl, popoverEl) {
    if (!cfg || !cfg.quick) return;
    // Scope onde estao os inputs: popover (preferido) > dropdown do MAD Select
    var scope = popoverEl
              || (buttonEl && buttonEl.closest('.mad-quick-popover, .mad-ts-noresults'))
              || (ts && ts.dropdown ? ts.dropdown : null);
    if (!scope && buttonEl) {
        scope = buttonEl.parentElement;
    }
    var fields   = cfg.quick.fields || [];
    var isMulti  = fields.length > 0;

    // Coleta valores
    var term = '';
    var values = {};
    if (isMulti) {
        // Pega cada input/select cujo data-mad-noresults-quick-field bate
        var fieldEls = scope ? scope.querySelectorAll('[data-mad-noresults-quick-field]') : [];
        fieldEls.forEach(function (el) {
            var n = el.dataset.madNoresultsQuickField;
            values[n] = el.value;
        });
        // Validar required
        for (var i = 0; i < fields.length; i++) {
            var f = fields[i];
            if (f.required && (!values[f.name] || String(values[f.name]).trim() === '')) {
                if (typeof madToast !== 'undefined') {
                    madToast.warning('Preencha o campo: ' + (f.label || f.name));
                }
                return;
            }
        }
        // term pode ser usado por convencao (1o field) ou ficar vazio
        term = ts && ts.control_input ? ts.control_input.value.trim() : '';
    } else {
        var inputEl = scope ? scope.querySelector('[data-mad-noresults-quick-input]') : null;
        term = inputEl && inputEl.value.trim()
                ? inputEl.value.trim()
                : (ts && ts.control_input ? ts.control_input.value.trim() : '');
        if (!term) {
            if (typeof madToast !== 'undefined') madToast.warning('Digite um valor antes de cadastrar.');
            return;
        }
    }

    if (buttonEl) buttonEl.disabled = true;
    var body = new FormData();
    body.append('token', cfg.quick.token);
    body.append('term',  term);
    Object.keys(values).forEach(function (k) {
        body.append('field[' + k + ']', values[k]);
    });

    fetch(cfg.quick.endpoint, { method: 'POST', body: body, credentials: 'same-origin' })
        .then(function (r) { return _madWireJson(r, { source: 'mad-quick-add', method: 'POST' }); })
        .then(function (data) {
            if (!data) return;   // erro do servidor já exibido (MadErrorModal)
            if (data && data.ok) {
                var value = String(data.value);
                var label = data.label != null ? data.label : term;
                // Callback custom do popover (quando o caller registra _madOnSuccess)
                if (popoverEl && typeof popoverEl._madOnSuccess === 'function') {
                    popoverEl._madOnSuccess(value, label);
                    if (typeof popoverEl._madClose === 'function') popoverEl._madClose();
                } else if (ts && ts.addOption) {
                    // MAD Select: insere a nova option e seleciona
                    ts.addOption({ value: value, text: label });
                    ts.addItem(value, false);
                    ts.refreshOptions(false);
                    ts.close();
                    if (popoverEl && typeof popoverEl._madClose === 'function') {
                        popoverEl._madClose();
                    }
                }
                if (typeof madToast !== 'undefined') {
                    madToast.success(data.toast || 'Cadastrado!');
                }
            } else {
                if (typeof madToast !== 'undefined') {
                    madToast.danger((data && data.error) || 'Falha ao cadastrar');
                }
            }
        })
        .catch(function (err) {
            console.error('[mad-noresults] quick register falhou', err);
            if (typeof madToast !== 'undefined') madToast.danger('Erro de rede.');
        })
        .then(function () {
            if (buttonEl) buttonEl.disabled = false;
        });
}

/* ═══════════════════════════════════════════════════════════════════
   MAD SELECT — componente Alpine próprio de select
   ═══════════════════════════════════════════════════════════════════

   Enhancer de <select> nativo. O <select> permanece a ÚNICA fonte da
   verdade (name/data-mad-model/options/selected): o componente Alpine é
   uma PROJEÇÃO dele. Toda mutação passa pelo <select> e re-projeta a UI,
   o que elimina o desync de modelo paralelo.

   API imperativa em el._madSelect (consumida por mad.js, field-list,
   data-grid, _syncFormInputs, CEP/CNPJ): setValue/getValue/getItem/
   clearOptions/addOption(s)/refreshOptions/clear/addItem/lock/unlock/
   close/on/off/destroy + getters wrapper/control/control_input/dropdown.

   Modos (lidos do dataset do <select>): single, single-search, multi,
   tags, single-ajax, multi-ajax, check (checkbox no dropdown).

   Opção em branco: a `<option value="">` do <select> é a primeira linha da
   lista do modo single — é por ela que o campo volta a ficar vazio — e o
   texto dela é o que o campo mostra sem valor.
   Sem busca: `data-mad-nosearch` (prop `no-search`) abre a lista sem a caixa
   de digitação, nos modos single e check.
   "×" de limpar: no single com valor, quando o campo pode ficar vazio (tem a
   opção em branco, não é obrigatório nem está travado) — getter `clearable`. */

/* Acha um <option> pelo value sem depender de querySelector escapado. */
function _madSelOpt(el, v) {
    v = String(v);
    var opts = el.options;
    for (var i = 0; i < opts.length; i++) { if (opts[i].value === v) return opts[i]; }
    return null;
}

/* A opção em branco do <select>: a primeira `<option value="">` HABILITADA.
   A desabilitada de value vazio é o aviso de lista que não carregou
   (`data-mad-options-error`) — não é placeholder nem escolha. */
function _madSelEmptyOpt(el) {
    var opts = el.options;
    for (var i = 0; i < opts.length; i++) { if (opts[i].value === '' && !opts[i].disabled) return opts[i]; }
    return null;
}

/* O HTML do SERVIDOR já traz uma escolha explícita?
   Lê o ATRIBUTO `selected`, nunca a propriedade: a propriedade é true na
   primeira option de todo <select> single sem `<option value="">`, e isso é
   default do navegador, não escolha de ninguém. Option `selected` de value
   VAZIO é placeholder — também não conta como escolha. */
function _madSelHasServerChoice(el) {
    var opts = el.options;
    for (var i = 0; i < opts.length; i++) {
        if (opts[i].hasAttribute('selected') && opts[i].value !== '') return true;
    }
    return false;
}

function _madSelectFactory() {
    return {
        // ── config (preenchida no init a partir do dataset) ──
        mode: 'single', multiple: false, ajax: false, canCreate: false,
        checkbox: false, searchable: true, token: '',
        minLen: 0, maxItems: null, maxDisplay: 3, placeholder: '',
        noResultsMsg: 'Nenhum registro encontrado.', noResCfg: null,
        // ── estado transiente de UI ──
        open: false, query: '', activeIndex: -1, loading: false, results: [],
        _v: 0, _posV: 0, _locked: false, _debounce: null, _labelMap: {},
        _listeners: {}, _outside: null, _onScroll: null, _ctrlRect: null,
        _isTouch: false, _uid: '', _native: null, _taBuf: '', _taTimer: null,

        init() {
            // Resolve o <select> via $root (NÃO via $refs): o select pode já ter sido
            // marcado como inicializado pelo Alpine.initTree do container antes de
            // _madCreateSelect adicionar o x-ref, o que faria $refs.native falhar.
            var el = this._native = this.$root.querySelector('select');
            if (!el) return;
            this._uid = 'ms' + (window._madSelUid = (window._madSelUid || 0) + 1);
            this._parseConfig(el);
            this._seedLabels();
            el._madSelect = this;
            el.classList.add('mad-select-init');
            this._isTouch = !!(window.matchMedia && window.matchMedia('(hover:none) and (pointer:coarse)').matches);
            if (typeof _madBuildNoResultsHelpers === 'function') {
                var h = _madBuildNoResultsHelpers(el);
                if (h) { this.noResCfg = h.config; if (h.config && h.config.message) this.noResultsMsg = h.config.message; }
            }
            if (el.classList.contains('mad-input-error')) this.$root.classList.add('mad-sel-error');
            // Aplica o valor do servidor a menos que o HTML já traga a escolha.
            // NÃO pode gatear em getValue(): num <select> single sem
            // `<option value="">` o navegador já elege a primeira option, o
            // getValue() vinha truthy e o valor do servidor era DESCARTADO — o
            // form abria na 1ª opção e o Salvar gravava ela por cima do valor
            // real (ver \Mad\Support\MadSelectSlot, que agora marca no
            // servidor). Em multi o getValue() devolve array, sempre truthy,
            // então `data-mad-selected` nunca era aplicado ali também.
            // Só o init roda isto, e o init só roda em node NOVO
            // (_madCreateSelect é idempotente por el._madSelect /
            // .mad-select-init; o morph do MadWire troca o node inteiro e traz
            // HTML fresco do servidor) — logo não há como desfazer escolha do
            // usuário numa tela já montada.
            var pre = el.dataset.madSelected;
            if (pre && !_madSelHasServerChoice(el)) this.setValue(pre, true);
            this.$nextTick(function () { if (window.lucide && lucide.createIcons) { try { lucide.createIcons({ nameAttr: 'data-lucide', root: this.$root }); } catch (e) {} } }.bind(this));
            this._v++;
        },
        destroy() {
            clearTimeout(this._debounce);
            clearTimeout(this._taTimer);
            this._detachOutside();
            if (this._native) { try { delete this._native._madSelect; } catch (e) {} }
        },

        _parseConfig(el) {
            var d = el.dataset;
            this.multiple = el.multiple;
            if (d.madDbsearch !== undefined)        { this.mode = 'single-ajax'; this.ajax = true; this.multiple = false; }
            else if (d.madDbmultisearch !== undefined) { this.mode = 'multi-ajax'; this.ajax = true; this.multiple = true; }
            else if (d.madSelectcheck !== undefined) { this.mode = 'check'; this.checkbox = true; this.multiple = true; }
            else if (d.madMultientry !== undefined) { this.mode = 'tags'; this.multiple = true; this.canCreate = d.create === '1'; }
            else if (d.madMultiselect !== undefined) { this.mode = 'multi'; this.multiple = true; }
            else if (d.madUniquesearch !== undefined) { this.mode = 'single-search'; this.multiple = false; }
            else { this.mode = this.multiple ? 'multi' : 'single'; }
            this.token = d.madSearchToken || '';
            this.maxItems = parseInt(d.maxSize || d.max || '0') || null;
            this.maxDisplay = parseInt(d.maxDisplay || '3') || 3;
            var ml = d.minLength;
            this.minLen = (ml !== undefined && ml !== '' && !isNaN(parseInt(ml))) ? parseInt(ml) : (this.ajax ? 3 : 0);
            // Sem `data-placeholder`, o campo vazio mostra o texto da própria
            // opção em branco: é ali que o `placeholder` do combo chega (antes
            // saía sempre "Selecione...", qualquer que fosse o texto escolhido).
            var eo = this.multiple ? null : _madSelEmptyOpt(el);
            this.placeholder = d.placeholder || (eo ? (eo.textContent || '').trim() : '')
                || (this.ajax ? 'Digite para buscar...' : (this.mode === 'tags' ? 'Digite e pressione Enter...' : 'Selecione...'));
            // `no-search`: só onde a busca é filtro de uma lista já carregada.
            // Nos demais modos digitar É o campo (busca no servidor, tags, chips).
            this.searchable = !(d.madNosearch !== undefined && (this.mode === 'single' || this.mode === 'check'));
        },
        _seedLabels() {
            var el = this._native, o = el.options;
            for (var i = 0; i < o.length; i++) { if (o[i].value !== '') this._labelMap[o[i].value] = o[i].textContent; }
        },
        _labelFor(v) {
            var o = _madSelOpt(this._native, v);
            if (o) return o.textContent;
            return this._labelMap[v] != null ? this._labelMap[v] : v;
        },

        // ── getters derivados (reativos via _v) ──
        get selectedValues() {
            this._v;
            var el = this._native; if (!el) return [];
            var out = [], so = el.selectedOptions;
            for (var i = 0; i < so.length; i++) { if (so[i].value !== '') out.push(so[i].value); }
            return out;
        },
        get displayItems() {
            this._v;
            var self = this;
            return this.selectedValues.map(function (v) { return { value: v, text: self._labelFor(v) }; });
        },
        get hasValue() { return this.selectedValues.length > 0; },
        get nativeOptions() {
            this._v;
            var el = this._native; if (!el) return [];
            var out = [], o = el.options;
            for (var i = 0; i < o.length; i++) { if (o[i].value !== '') out.push({ value: o[i].value, text: o[i].textContent }); }
            return out;
        },
        // Opção em branco como linha da lista: sem ela, depois de escolher um
        // item não havia como deixar o campo vazio de novo. Só no single (no
        // múltiplo desmarca-se item a item), fora de campo obrigatório (vazio
        // ali não é escolha) e fora da busca no servidor (a lista é o
        // resultado). A option DESABILITADA de value vazio é o aviso de lista
        // que não carregou, não uma escolha — ver _madSelEmptyOpt.
        get emptyOption() {
            this._v;
            var el = this._native;
            if (!el || this.multiple || this.ajax || el.required) return null;
            var o = _madSelEmptyOpt(el);
            return o ? { value: '', text: (o.textContent || '').trim() || this.placeholder, empty: true } : null;
        },
        // "×" do controle (o allowClear do select2): limpa o campo sem abrir a
        // lista. Mesma regra da linha em branco — o campo precisa PODER ficar
        // vazio — e vale também na busca no servidor, que não tem a linha.
        get clearable() {
            this._v;
            var el = this._native;
            return !!el && !this.multiple && !this._locked && !el.disabled && !el.required
                && this.selectedValues.length > 0 && !!_madSelEmptyOpt(el);
        },
        get visibleOptions() {
            this._v; this.query;
            var base;
            if (this.ajax) {
                base = this.results;
            } else {
                var q = (this.query || '').toLowerCase();
                base = this.nativeOptions;
                if (q) base = base.filter(function (o) { return o.text.toLowerCase().indexOf(q) >= 0; });
                // Lista sem nenhum item fica como era: "nenhum registro" e os
                // botões de cadastro, sem uma linha em branco sozinha no lugar.
                else if (base.length) { var eo = this.emptyOption; if (eo) base = [eo].concat(base); }
            }
            // Multi (não-checkbox): esconde os já selecionados da lista — vale também
            // pro AJAX, pra não reaparecerem após selecionar (ficam só como chips).
            if (this.multiple && !this.checkbox) {
                var sel = this.selectedValues;
                base = base.filter(function (o) { return sel.indexOf(o.value) < 0; });
            }
            return base;
        },
        get dropStyle() {
            // Objeto (não string): Alpine mescla por-propriedade e NÃO sobrescreve
            // o display gerenciado pelo x-show. Touch usa bottom-sheet via CSS.
            this._posV;
            if (!this.open || this._isTouch) return {};
            var r = this._ctrlRect;
            if (!r) return {};
            // SEM z-index aqui: quem manda é `.mad-sel-dropdown { z-index:
            // var(--mad-z-float) }` no CSS. Inline vence regra sem !important,
            // então o 9999 cravado aqui bloqueava qualquer rebase de camada —
            // era por isso que a lista abria ATRÁS do quick-form popover.
            var o = { position: 'fixed', left: r.left + 'px', 'min-width': r.width + 'px' };
            // flip pra cima se não couber abaixo
            if (r.bottom + 280 > window.innerHeight && r.top > 300) o.bottom = (window.innerHeight - r.top + 4) + 'px';
            else o.top = (r.bottom + 4) + 'px';
            return o;
        },
        isSelected(v) { this._v; return this.selectedValues.indexOf(String(v)) >= 0; },
        // Busca AJAX com mínimo de caracteres e query ainda curta → pede mais chars
        // em vez de "nenhum registro" (que engana o usuário).
        get needsMoreChars() { return this.ajax && this.minLen > 0 && (this.query || '').trim().length < this.minLen; },
        get minCharsMsg() {
            var n = this.minLen;
            return 'Digite ao menos ' + n + ' ' + (n === 1 ? 'caractere' : 'caracteres') + ' para buscar.';
        },
        // Multi estático: lista vazia porque TODOS já foram selecionados (não por falta de match).
        get allSelected() {
            return this.multiple && !this.checkbox && !this.ajax
                && (this.query || '').trim() === ''
                && this.nativeOptions.length > 0 && this.visibleOptions.length === 0;
        },
        // Estado de "dica" (não é erro de busca): pede mais chars ou tudo já selecionado.
        get isEmptyHint() { return this.needsMoreChars || this.allSelected; },
        get emptyMsg() {
            if (this.needsMoreChars) return this.minCharsMsg;
            if (this.allSelected) return 'Todos os itens já foram selecionados.';
            return this.noResultsMsg;
        },

        // ── interações ──
        onControlClick(e) {
            if (this._locked || this._native.disabled) return;
            if (e.target && (e.target.closest('.mad-sel-chip-x') || e.target.closest('.mad-sel-clear'))) return;
            this.openDropdown();
            this._focusControl();
        },
        // Cursor na busca (sem busca, no próprio controle: é dele que saem as
        // setas, Enter, Esc e a letra inicial). Espera a caixa APARECER: o
        // x-show a mostra num setTimeout próprio e, no Firefox, o $nextTick
        // chegava antes — o focus() caía num input ainda display:none e o
        // primeiro clique abria a lista sem cursor (só o segundo deixava digitar).
        _focusControl() {
            var self = this, tries = 0;
            var attempt = function () {
                var i = self.searchable ? self.$refs.input : self.control;
                if (!i) return;
                if (i.getClientRects().length) { i.focus(); return; }
                if (self.open && tries++ < 25) setTimeout(attempt, 16);
            };
            this.$nextTick(attempt);
        },
        openDropdown() {
            if (this._locked || this.open) { if (this.open) this._measure(); return; }
            this.open = true; this.activeIndex = this._currentIndex();
            this._measure(); this._attachOutside();
            if (this.ajax && this.minLen === 0 && !this.results.length) this._fetch('');
            this._emit('dropdown_open');
            var self = this;
            this.$nextTick(function () { self._reicon(); if (self.activeIndex > 0) self._revealActive(); });
        },
        // Linha ativa ao abrir (single com a lista já carregada): o item atual
        // ou, com o campo vazio, a linha em branco — assim a seta para baixo
        // continua caindo no primeiro item. Nos outros modos, nenhuma.
        _currentIndex() {
            if (this.multiple || this.ajax) return -1;
            var vo = this.visibleOptions, sv = this.selectedValues;
            if (!sv.length) return (vo.length && vo[0].empty) ? 0 : -1;
            for (var i = 0; i < vo.length; i++) { if (vo[i].value === sv[0]) return i; }
            return -1;
        },
        // Traz a linha ativa para dentro da lista rolando SÓ a lista (o
        // scrollIntoView rolaria também a página com a lista ainda se posicionando).
        _revealActive() {
            var d = this.dropdown, a = d && d.querySelector('.mad-sel-option.is-active');
            if (a) d.scrollTop = Math.max(0, a.offsetTop - (d.clientHeight - a.offsetHeight) / 2);
        },
        closeDropdown() { if (!this.open) return; this.open = false; this.query = ''; this._detachOutside(); },
        _measure() {
            var c = this.$root.querySelector('.mad-sel-control');
            if (c) this._ctrlRect = c.getBoundingClientRect();
            this._posV++;
        },

        onSearchInput() {
            this.activeIndex = 0;
            if (!this.open) this.openDropdown();
            if (!this.ajax) return;
            var q = (this.query || '').trim();
            if (q.length < this.minLen) { this.results = []; this.loading = false; return; }
            clearTimeout(this._debounce); this.loading = true;
            var self = this;
            this._debounce = setTimeout(function () { self._fetch(q); }, 300);
        },
        _fetch(q) {
            var el = this._native, self = this;
            var dep = el.dataset.madDepValue || '';
            var params = { token: this.token, q: q };
            if (dep) params.depValue = dep;
            this.loading = true;
            fetch(_madServiceUrl('db-search', 'onSearch', params))
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    _madOptionsError(el, d.error);
                    self.results = d.results || [];
                    self.results.forEach(function (r) { self._labelMap[r.value] = r.text; });
                    self.activeIndex = self.results.length ? 0 : -1;
                })
                .catch(function () { self.results = []; })
                .finally(function () { self.loading = false; self.$nextTick(function () { self._reicon(); }); });
        },

        selectOption(v) {
            v = String(v);
            var el = this._native;
            if (this.multiple) {
                var opt = this._ensureOption(v);
                if (!opt.selected && this.maxItems && this.selectedValues.length >= this.maxItems) return;
                opt.selected = !opt.selected;
                this._commit(); this._emit(opt.selected ? 'item_add' : 'item_remove', v);
                // AJAX: mantém query + results pra continuar selecionando vários da mesma
                // busca (o selecionado some da lista via filtro em visibleOptions).
                // Estático: limpa a busca pra mostrar o restante das opções —
                // EXCETO no modo checkbox: lá a lista re-renderizava inteira sob o
                // cursor e o clique seguinte caía em outra opção (desmarcando uma
                // já escolhida). Mantém o filtro pra marcar vários do mesmo termo.
                if (!this.ajax && !this.checkbox) this.query = '';
                var self = this; this.$nextTick(function () { var i = self.$refs.input; if (i) { i.focus(); self._measure(); } });
            } else {
                // Linha em branco: o campo volta a vazio pelo mesmo caminho do clear().
                if (v === '') { this.clearValue(); return; }
                this._ensureOption(v); el.value = v;
                this._commit(); this._emit('item_add', v);
                this.closeDropdown();
            }
        },
        removeItem(v) {
            v = String(v);
            var o = _madSelOpt(this._native, v);
            if (o) { o.selected = false; this._commit(); this._emit('item_remove', v); }
        },
        createTag() {
            var q = (this.query || '').trim();
            if (!q) return;
            var m = this.nativeOptions.filter(function (o) { return o.text.toLowerCase() === q.toLowerCase(); })[0];
            if (m) { this.selectOption(m.value); return; }
            this._labelMap[q] = q; this.selectOption(q);
        },
        clearValue() {
            // O "×" pode estar à vista num campo que acabou de ser travado.
            if (this._locked || this._native.disabled) return;
            this.clear(false); this.closeDropdown();
        },

        onKeydown(e) {
            // Delete/Backspace com a lista fechada: o teclado do "×".
            if (!this.open && (e.key === 'Delete' || e.key === 'Backspace') && this.clearable) {
                e.preventDefault(); this.clearValue(); return;
            }
            // Sem busca, o teclado faz o que a caixa de digitação fazia: Espaço
            // abre a lista e a letra leva ao primeiro item que começa com ela.
            if (!this.searchable && !e.ctrlKey && !e.metaKey && !e.altKey && e.key && e.key.length === 1) {
                e.preventDefault();
                if (e.key !== ' ') this._typeAhead(e.key);
                else if (!this.open) this.openDropdown();
                return;
            }
            if (!this.open && (e.key === 'ArrowDown' || e.key === 'Enter')) { if (e.key === 'ArrowDown') e.preventDefault(); this.openDropdown(); return; }
            if (!this.open) return;
            var opts = this.visibleOptions;
            if (e.key === 'ArrowDown') { e.preventDefault(); this.activeIndex = Math.min(opts.length - 1, this.activeIndex + 1); this._scrollActive(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.activeIndex = Math.max(0, this.activeIndex - 1); this._scrollActive(); }
            else if (e.key === 'Enter') {
                e.preventDefault();
                var ao = this.activeIndex >= 0 ? opts[this.activeIndex] : null;
                // A lista abre com a linha do valor atual ativa: Enter nela só
                // fecha, sem disparar change de um valor que não mudou.
                if (ao && !this.multiple && !this.ajax && ao.value === (this.selectedValues[0] || '')) this.closeDropdown();
                else if (ao) this.selectOption(ao.value);
                else if (this.canCreate) this.createTag();
            }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); this.closeDropdown(); }
            else if (e.key === 'Backspace' && !this.query && this.multiple) { var sv = this.selectedValues; if (sv.length) this.removeItem(sv[sv.length - 1]); }
        },
        // Letras digitadas em sequência (até ~0,7 s entre elas) formam o começo
        // do texto procurado; a linha em branco não entra na procura.
        _typeAhead(ch) {
            var self = this;
            clearTimeout(this._taTimer);
            this._taBuf += ch.toLowerCase();
            this._taTimer = setTimeout(function () { self._taBuf = ''; }, 700);
            if (!this.open) this.openDropdown();
            var opts = this.visibleOptions, buf = this._taBuf;
            for (var i = 0; i < opts.length; i++) {
                if (!opts[i].empty && String(opts[i].text).trim().toLowerCase().indexOf(buf) === 0) { this.activeIndex = i; this._scrollActive(); return; }
            }
        },
        _scrollActive() {
            var self = this;
            this.$nextTick(function () { var d = self.dropdown; if (!d) return; var a = d.querySelector('.mad-sel-option.is-active'); if (a) a.scrollIntoView({ block: 'nearest' }); });
        },
        summaryText() {
            this._v;
            var items = this.displayItems;
            if (!items.length) return '';
            var shown = items.slice(0, this.maxDisplay).map(function (i) { return i.text; }).join(', ');
            var extra = items.length - this.maxDisplay;
            return extra > 0 ? shown + '  +' + extra + ' ' + (extra === 1 ? 'item' : 'itens') : shown;
        },
        fireCreate() {
            var cfg = this.noResCfg, term = (this.query || '');
            if (cfg && cfg.create && window.Mad && typeof Mad.go === 'function') {
                var params = { _term: term };
                // Identidade da origem ASSINADA pelo PHP (MadNoResultsHelper):
                // o form alvo devolve a option pro combo certo sem confiar em
                // query string. `_field_name` continua indo por compat com app
                // em framework antigo — sai no 5.18.
                if (cfg.create.token) params._mad_origin = cfg.create.token;
                params._field_name = cfg.fieldName || '';
                // Escopo do combo de origem: evita acertar um <select> homônimo
                // de outra tela quando o alvo salva depois de o usuário navegar.
                var _cmpEl = this.$root && this.$root.closest ? this.$root.closest('[mad-component]') : null;
                if (_cmpEl) params._mad_origin_cmp = _cmpEl.getAttribute('mad-id') || '';
                // cfg.create.url vem ASSADA do PHP (rota amigável). Só os params
                // dinâmicos entram aqui. Sem url (payload antigo em cache), o
                // Mad.go cai no fallback /app/<Classe>/<metodo>.
                var url = cfg.create.url ? _madUrlWithParams(cfg.create.url, params) : undefined;
                Mad.go(cfg.create.class, cfg.create.method, params, url);
                this.closeDropdown();
            }
        },
        fireQuick() {
            var cfg = this.noResCfg;
            if (cfg && cfg.quick && typeof _madShowQuickFormPopover === 'function') {
                _madShowQuickFormPopover(cfg, this); this.closeDropdown();
            }
        },
        noResIcon(name) { return name ? '<i data-lucide="' + name + '" style="width:14px;height:14px;"></i> ' : ''; },
        // Realça o trecho que casa com a busca (escapa HTML, envolve em <mark>).
        highlight(text) {
            var t = String(text == null ? '' : text);
            var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };
            var safe = esc(t);
            var q = (this.query || '').trim();
            if (!q) return safe;
            var safeQ = esc(q).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            try { return safe.replace(new RegExp('(' + safeQ + ')', 'ig'), '<mark class="mad-sel-hl">$1</mark>'); }
            catch (e) { return safe; }
        },

        // ── internals ──
        _ensureOption(v) {
            var el = this._native, o = _madSelOpt(el, v);
            if (!o) { o = document.createElement('option'); o.value = v; o.textContent = this._labelFor(v); o.setAttribute('data-mad-synthetic', ''); el.appendChild(o); }
            return o;
        },
        _commit() { this._v++; this._native.dispatchEvent(new Event('change', { bubbles: true })); },
        _emit(name, val) { (this._listeners[name] || []).forEach(function (cb) { try { cb(val); } catch (e) { console.error(e); } }); },
        _reicon() { var d = this.dropdown; if (d && window.lucide && lucide.createIcons) { try { lucide.createIcons({ nameAttr: 'data-lucide', root: d }); } catch (e) {} } },
        _attachOutside() {
            if (this._outside) return;
            var self = this;
            this._outside = function (e) {
                if (self.$root.contains(e.target)) return;
                var d = self.dropdown;
                if (d && d.contains(e.target)) return;
                self.closeDropdown();
            };
            this._onScroll = function () { if (self.open) self._measure(); };
            document.addEventListener('mousedown', this._outside, true);
            window.addEventListener('scroll', this._onScroll, true);
            window.addEventListener('resize', this._onScroll, true);
        },
        _detachOutside() {
            if (this._outside) { document.removeEventListener('mousedown', this._outside, true); this._outside = null; }
            if (this._onScroll) { window.removeEventListener('scroll', this._onScroll, true); window.removeEventListener('resize', this._onScroll, true); this._onScroll = null; }
        },

        // ── API pública (el._madSelect.*) — escreve sempre no <select> ──
        setValue(value, silent) {
            var el = this._native, vals;
            if (this.multiple) {
                if (Array.isArray(value)) vals = value.map(String);
                else if (value === null || value === undefined || value === '') vals = [];
                else vals = String(value).split(',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
            } else {
                vals = (value === null || value === undefined || value === '') ? [] : [String(value)];
            }
            var self = this;
            vals.forEach(function (v) { self._ensureOption(v); });
            var o = el.options;
            for (var i = 0; i < o.length; i++) { o[i].selected = vals.indexOf(o[i].value) >= 0; }
            this._v++;
            if (silent !== true) el.dispatchEvent(new Event('change', { bubbles: true }));
        },
        getValue() { var sv = this.selectedValues; return this.multiple ? sv : (sv.length ? sv[0] : ''); },
        getItem(v) { v = String(v); if (!v) return null; var s = document.createElement('span'); s.textContent = this._labelFor(v); return s; },
        addOption(o) {
            if (!o) return;
            var el = this._native, lbl = (o.text != null ? o.text : o.label);
            var existing = _madSelOpt(el, o.value);
            if (!existing) { var op = document.createElement('option'); op.value = o.value; op.textContent = lbl; el.appendChild(op); }
            // Option SINTÉTICA (criada por _ensureOption sem rótulo) exibe o
            // próprio id — _labelFor lê o textContent ANTES do _labelMap, então
            // sem reescrever aqui o rótulo de verdade nunca apareceria. Option
            // real (vinda do servidor) não é tocada.
            else if (lbl != null && lbl !== '' && String(lbl) !== existing.textContent
                && (existing.hasAttribute('data-mad-synthetic') || existing.textContent === existing.value)) {
                existing.textContent = lbl;
                existing.removeAttribute('data-mad-synthetic');
            }
            this._labelMap[o.value] = lbl; this._v++;
        },
        addOptions(arr) { var self = this; (arr || []).forEach(function (o) { self.addOption(o); }); },
        clearOptions() {
            var el = this._native, keep = _madSelOpt(el, '');
            el.innerHTML = ''; if (keep) el.appendChild(keep); this._labelMap = {}; this._v++;
        },
        refreshOptions(silent) { this._seedLabels(); this._v++; },
        refreshFromSelect() { this._seedLabels(); this._v++; },
        clear(silent) {
            var el = this._native, o = el.options;
            for (var i = 0; i < o.length; i++) o[i].selected = false;
            if (!this.multiple) el.value = '';
            this._v++;
            if (silent !== true) el.dispatchEvent(new Event('change', { bubbles: true }));
            this._emit('clear');
        },
        addItem(v, silent) { var o = this._ensureOption(String(v)); o.selected = true; this._v++; if (silent !== true) this._commit(); },
        lock() { this._locked = true; this.$root.classList.add('mad-sel-locked'); this._native.disabled = true; },
        unlock() { this._locked = false; this.$root.classList.remove('mad-sel-locked'); this._native.disabled = false; },
        close() { this.closeDropdown(); },
        on(evt, cb) { (this._listeners[evt] = this._listeners[evt] || []).push(cb); },
        off(evt, cb) { if (this._listeners[evt]) this._listeners[evt] = this._listeners[evt].filter(function (f) { return f !== cb; }); },
        get wrapper() { return this.$root; },
        get control() { return this.$root.querySelector('.mad-sel-control'); },
        get control_input() { return this.$refs.input; },
        get dropdown() { return (this._uid && document.querySelector('.mad-sel-dropdown[data-mad-owner="' + this._uid + '"]')) || this.$refs.dropdown || null; },
    };
}

/* Markup do wrapper (control + dropdown teleportado). O <select> nativo é
   movido pra dentro como x-ref="native" pelo _madCreateSelect. */
var _MAD_SEL_CONTROL_TPL =
'<div class="mad-sel-control" :class="{\'has-clear\':clearable}" @click="onControlClick($event)" role="combobox" :aria-expanded="open" :tabindex="searchable?null:-1">' +
  '<template x-if="!multiple && !checkbox">' +
    '<span class="mad-sel-single" x-show="!open || !searchable" :class="{\'is-placeholder\':!hasValue}" x-text="hasValue?displayItems[0].text:placeholder"></span>' +
  '</template>' +
  '<template x-if="multiple && !checkbox">' +
    '<span class="mad-sel-chips">' +
      '<template x-for="item in displayItems" :key="item.value">' +
        '<span class="mad-sel-chip"><span x-text="item.text"></span>' +
        '<span class="mad-sel-chip-x" role="button" tabindex="-1" aria-label="Remover" @mousedown.prevent.stop="removeItem(item.value)">&times;</span></span>' +
      '</template>' +
    '</span>' +
  '</template>' +
  '<template x-if="checkbox">' +
    '<span class="mad-sel-summary" x-show="!open || !searchable" :class="{\'is-placeholder\':!hasValue}" x-text="hasValue?summaryText():placeholder"></span>' +
  '</template>' +
  '<input class="mad-sel-input" x-ref="input" type="text" x-model="query" @input="onSearchInput()" @focus="openDropdown()" ' +
    'x-show="searchable && (open || (multiple && !checkbox))" :placeholder="(multiple && hasValue)?\'\':placeholder" ' +
    'autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false">' +
  '<span class="mad-sel-clear" role="button" tabindex="-1" aria-label="Limpar" title="Limpar" x-show="clearable" x-cloak @mousedown.prevent.stop="clearValue()" @click.stop>&times;</span>' +
  '<i class="mad-sel-caret" data-lucide="chevron-down"></i>' +
'</div>' +
'<template x-teleport="body">' +
  '<div class="mad-sel-dropdown mad-ui" x-ref="dropdown" :data-mad-owner="_uid" x-show="open" :style="dropStyle" x-cloak ' +
    ':class="{\'mad-sel-dropdown-touch\':_isTouch}" @mousedown.prevent>' +
    '<template x-if="open">' +
      '<div class="mad-sel-dropdown-inner">' +
        '<ul class="mad-sel-list" role="listbox">' +
          '<template x-for="(opt,i) in visibleOptions" :key="opt.value">' +
            '<li class="mad-sel-option" role="option" :class="{\'is-active\':i===activeIndex,\'is-selected\':isSelected(opt.value),\'is-empty\':opt.empty}" ' +
              '@mouseenter="activeIndex=i" @mousedown.prevent="selectOption(opt.value)">' +
              '<template x-if="checkbox"><input type="checkbox" class="mad-sel-checkbox" tabindex="-1" :checked="isSelected(opt.value)" @click.prevent></template>' +
              '<span class="mad-sel-option-text" x-html="highlight(opt.text)"></span>' +
            '</li>' +
          '</template>' +
        '</ul>' +
        '<template x-if="loading"><div class="mad-sel-loading">Carregando…</div></template>' +
        '<template x-if="!loading && visibleOptions.length===0">' +
          '<div class="mad-ts-noresults mad-sel-noresults" :class="{\'mad-sel-hint\':isEmptyHint}">' +
            '<div class="mad-ts-noresults-msg" x-text="emptyMsg"></div>' +
            '<template x-if="!isEmptyHint && canCreate && query.trim()">' +
              '<span class="mad-btn mad-btn-primary mad-btn-sm mad-ts-noresults-btn" role="button" tabindex="0" @mousedown.prevent="createTag()"><i data-lucide="plus"></i> <span x-text="\'Adicionar: \'+query.trim()"></span></span>' +
            '</template>' +
            '<template x-if="!isEmptyHint && noResCfg && noResCfg.create">' +
              '<span class="mad-ts-noresults-btn" role="button" tabindex="0" :class="noResCfg.create.btnClass" @mousedown.prevent="fireCreate()" x-html="noResIcon(noResCfg.create.icon)+noResCfg.create.label"></span>' +
            '</template>' +
            '<template x-if="!isEmptyHint && noResCfg && noResCfg.quick">' +
              '<span class="mad-ts-noresults-btn" role="button" tabindex="0" :class="noResCfg.quick.btnClass" @mousedown.prevent="fireQuick()" x-html="noResIcon(noResCfg.quick.icon)+noResCfg.quick.label"></span>' +
            '</template>' +
          '</div>' +
        '</template>' +
      '</div>' +
    '</template>' +
  '</div>' +
'</template>';

/* Registra o componente Alpine (idempotente). */
function _madRegisterSelect() {
    if (!window.Alpine || window.Alpine.__madSelectRegistered) return;
    window.Alpine.__madSelectRegistered = true;
    window.Alpine.data('madSelect', _madSelectFactory);
}
document.addEventListener('alpine:init', _madRegisterSelect);
if (window.Alpine) _madRegisterSelect();

/* Seletor de todos os <select> que viram MAD Select. */
var _MAD_SEL_SELECTOR = 'select[data-mad-select],select[data-mad-multiselect],select[data-mad-uniquesearch],select[data-mad-multientry],select[data-mad-dbsearch],select[data-mad-dbmultisearch],select[data-mad-selectcheck]';

/* Enhance um único <select>: cria o wrapper .mad-sel, move o select pra
   dentro e monta o componente Alpine. Idempotente.
   Mount: usa Alpine.initTree se disponível; senão o MutationObserver nativo
   do Alpine monta o x-data recém-inserido. */
function _madCreateSelect(el) {
    if (!el || el._madSelect || el.classList.contains('mad-select-init')) return;
    if (el.closest('.mad-sel')) return;
    if (!window.Alpine) return;
    _madRegisterSelect();
    var wrap = document.createElement('div');
    wrap.className = 'mad-sel';
    wrap.setAttribute('x-data', 'madSelect()');
    // init() é auto-chamado pelo Alpine; NÃO usar x-init (evita double-init).
    // Forma LONGA do Alpine: '@keydown' (shorthand) é nome de atributo inválido
    // via setAttribute (InvalidCharacterError em engines estritas) — 'x-on:keydown'
    // é equivalente pro Alpine e é um nome válido.
    wrap.setAttribute('x-on:keydown', 'onKeydown($event)');
    el.parentNode.insertBefore(wrap, el);
    wrap.appendChild(el);
    el.classList.add('mad-sel-native');
    wrap.insertAdjacentHTML('beforeend', _MAD_SEL_CONTROL_TPL);
    if (typeof window.Alpine.initTree === 'function') {
        try { window.Alpine.initTree(wrap); } catch (e) { /* observer do Alpine monta */ }
    }
}
window._madCreateSelect = _madCreateSelect;

/* Enhance todos os selects dentro de root (qualquer hook MAD). */
function _madEnhanceSelects(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches(_MAD_SEL_SELECTOR)) _madCreateSelect(root);
    root.querySelectorAll(_MAD_SEL_SELECTOR).forEach(_madCreateSelect);
}
window._madEnhanceSelects = _madEnhanceSelects;

/* Auto-mount global: observa o DOM inteiro e enhança qualquer <select> MAD
   assim que entra na página — INDEPENDENTE de quem injeta (drawer/overlay
   move o conteúdo pro body antes do _madInit*, deixando o container vazio).
   Isso garante que todo select vire MAD Select sem depender dos call-sites. */
function _madSelectAutoMount() {
    if (window._madSelectObs) { _madEnhanceSelects(document.body); return; }
    window._madSelectObs = new MutationObserver(function (muts) {
        for (var i = 0; i < muts.length; i++) {
            var added = muts[i].addedNodes;
            for (var j = 0; j < added.length; j++) {
                if (added[j].nodeType === 1) _madEnhanceSelects(added[j]);
            }
        }
    });
    var start = function () {
        window._madSelectObs.observe(document.body, { childList: true, subtree: true });
        _madEnhanceSelects(document.body);
    };
    if (document.body) start();
    else document.addEventListener('DOMContentLoaded', start);
}
// Inicia quando o Alpine estiver pronto (e tenta já, se body existe).
document.addEventListener('alpine:init', function () { setTimeout(_madSelectAutoMount, 0); });
if (window.Alpine && document.body) _madSelectAutoMount();
else document.addEventListener('DOMContentLoaded', function () { if (window.Alpine) _madSelectAutoMount(); });

/**
 * Inicializa o MAD Select em todos os <select data-mad-select> dentro de `root`.
 * Idempotente (via el._madSelect / .mad-select-init). Mantido por compat com
 * os call-sites; o auto-mount global cobre os casos que esses não alcançam.
 */
function _madInitSelects(root) {
    var scope = root || document;
    scope.querySelectorAll('select[data-mad-select]').forEach(function (el) {
        _madCreateSelect(el);
    });
}
window._madInitSelects = _madInitSelects;

/* ═══════════════════════════════════════════════════════════════════
   AUTOCOMPLETE — input com data-mad-autocomplete="varName"
   ═══════════════════════════════════════════════════════════════════

   Lê a variável do Alpine scope (ou window) e mostra dropdown filtrado.
   Funciona com inputs dinâmicos (field-list, detail-form).
   Suporta navegação por teclado (setas + Enter + Escape).
*/
(function() {
    var _acBox = null;
    var _acInput = null;
    var _acIdx = -1;

    function _resolveSource(input) {
        var src = input.dataset.madAutocomplete;
        if (!src) return [];
        // Tenta Alpine scope
        var el = input.closest('[x-data]');
        if (el && window.Alpine) {
            try {
                var data = Alpine.$data(el);
                var val = src.split('.').reduce(function(o, k) { return o && o[k]; }, data);
                if (Array.isArray(val)) return val;
            } catch(e) {}
        }
        // Fallback: window
        try {
            var val2 = src.split('.').reduce(function(o, k) { return o && o[k]; }, window);
            if (Array.isArray(val2)) return val2;
        } catch(e) {}
        return [];
    }

    function _show(input) {
        _hide();
        var items = _resolveSource(input);
        if (!items.length) return;

        var q = (input.value || '').toLowerCase();
        var filtered = items.filter(function(item) {
            var text = typeof item === 'object' ? (item.label || item.text || item.value || String(item)) : String(item);
            return !q || text.toLowerCase().indexOf(q) >= 0;
        });
        if (!filtered.length) return;

        var box = document.createElement('div');
        box.className = 'mad-ac-dropdown';
        var rect = input.getBoundingClientRect();
        box.style.cssText = 'position:fixed;z-index:var(--mad-z-float,9999);top:' + (rect.bottom + 2) + 'px;left:' + rect.left + 'px;width:' + rect.width + 'px;';

        filtered.forEach(function(item, idx) {
            var text = typeof item === 'object' ? (item.label || item.text || item.value || String(item)) : String(item);
            var value = typeof item === 'object' ? (item.value || text) : text;
            var div = document.createElement('div');
            div.className = 'mad-ac-item';
            div.textContent = text;
            div.dataset.value = value;
            div.dataset.idx = idx;
            div.onmouseenter = function() { _setActive(idx); };
            div.onmousedown = function(e) {
                e.preventDefault();
                _select(input, value);
            };
            box.appendChild(div);
        });

        document.body.appendChild(box);
        _acBox = box;
        _acInput = input;
        _acIdx = -1;
    }

    function _hide() {
        if (_acBox) { _acBox.remove(); _acBox = null; }
        _acInput = null;
        _acIdx = -1;
    }

    function _select(input, value) {
        input.value = value;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        _hide();
    }

    function _setActive(idx) {
        if (!_acBox) return;
        var items = _acBox.querySelectorAll('.mad-ac-item');
        items.forEach(function(el) { el.classList.remove('mad-ac-active'); });
        if (idx >= 0 && idx < items.length) {
            items[idx].classList.add('mad-ac-active');
            items[idx].scrollIntoView({ block: 'nearest' });
        }
        _acIdx = idx;
    }

    // Delegation: focusin
    document.addEventListener('focusin', function(e) {
        if (e.target.dataset && e.target.dataset.madAutocomplete) _show(e.target);
    });

    // Delegation: input
    document.addEventListener('input', function(e) {
        if (e.target.dataset && e.target.dataset.madAutocomplete && _acInput === e.target) _show(e.target);
    });

    // Delegation: blur (com delay para permitir click)
    document.addEventListener('focusout', function(e) {
        if (_acInput === e.target) setTimeout(_hide, 150);
    });

    // Delegation: keyboard
    document.addEventListener('keydown', function(e) {
        if (!_acBox || !_acInput || _acInput !== e.target) return;
        var items = _acBox.querySelectorAll('.mad-ac-item');
        var count = items.length;
        if (!count) return;

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            _setActive((_acIdx + 1) % count);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            _setActive(_acIdx <= 0 ? count - 1 : _acIdx - 1);
        } else if (e.key === 'Enter' && _acIdx >= 0) {
            e.preventDefault();
            _select(_acInput, items[_acIdx].dataset.value);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            _hide();
        }
    });
})();


/* ═══════════════════════════════════════════════════════════════════
   *-when — estado condicional de campo/seção no cliente
   ═══════════════════════════════════════════════════════════════════

   <mad-money-field name="valor" disabled-when="{tipo} != 2" />
   <mad-form-section title="Valores" visible-when="{tipo} == 2">…

   O compilador MAD (MadBlade::parseParams) troca disabled-when /
   readonly-when / required-when / visible-when por `x-mad-when:<tipo>="expr"`
   no `attrs` do componente. Aqui `{campo}` vira o valor atual do campo
   `name="campo"` do mesmo formulário, e a expressão é reavaliada a cada
   change/input dele — inclusive os disparados pelo servidor (op `val` do
   MadWire e o setItems do combo disparam input/change).

   Alvo: o wrapper `.mad-field` do campo; sem ele (seção, botão), o próprio
   elemento. Vários *-when sobre o mesmo controle (seção + campo) se somam:
   cada condição é um VOTO; o controle fica desabilitado enquanto houver voto
   e, sem votos, volta ao estado que tinha antes (nunca reabilita o que o
   servidor renderizou `disabled`). Desabilitado NÃO é enviado no submit
   (FormData nativo); readonly-when e visible-when continuam enviando.
   Expressão inválida ou que lança = sem efeito (o campo fica como está).
*/
// <madWhen:core> — núcleo puro, fatiado por tests/js/mad-when.test.mjs
var _madWhen = (function () {
    var KINDS = { disabled: 1, readonly: 1, required: 1, visible: 1 };
    var PROP = { disabled: 'disabled', readonly: 'readOnly', required: 'required' };
    var NOT_TEXT = { hidden: 1, checkbox: 1, radio: 1, file: 1, button: 1, submit: 1, reset: 1, image: 1, range: 1, color: 1 };
    var CTL_SEL = 'input,select,textarea,button';
    var _seq = 0;

    function compile(expr) {
        var js = String(expr == null ? '' : expr).replace(/\{([a-zA-Z_]\w*)\}/g, function (_, n) {
            return '$v(' + JSON.stringify(n) + ')';
        });
        if (js.trim() === '') return null;
        try { return new Function('$v', 'return (' + js + ');'); }
        catch (e) { return null; }
    }

    function _q(name) { return String(name).replace(/["\\]/g, '\\$&'); }

    // Valor atual de `name` no escopo. Radio/checkbox → valor do marcado
    // (senão o hidden de fallback do switch/checkbox, senão ''); resto → value.
    function readValue(scope, name) {
        var n = _q(name);
        var els = scope.querySelectorAll('[name="' + n + '"],[name="' + n + '[]"]');
        if (!els.length) return '';
        var hidden = null, checkable = false;
        for (var i = 0; i < els.length; i++) {
            var t = String(els[i].type || '').toLowerCase();
            if (t === 'checkbox' || t === 'radio') {
                checkable = true;
                if (els[i].checked) return els[i].value;
            } else if (t === 'hidden' && hidden === null) {
                hidden = els[i];
            }
        }
        if (checkable) return hidden ? hidden.value : '';
        return els[0].value == null ? '' : els[0].value;
    }

    function controls(root) {
        var out = [];
        if (root.matches && root.matches(CTL_SEL)) out.push(root);
        var inner = root.querySelectorAll ? root.querySelectorAll(CTL_SEL) : [];
        for (var i = 0; i < inner.length; i++) out.push(inner[i]);
        return out;
    }

    function accepts(kind, ctl) {
        var tag = String(ctl.tagName || '').toLowerCase();
        var t = String(ctl.type || '').toLowerCase();
        if (kind === 'disabled') return true;
        if (kind === 'readonly') return tag === 'textarea' || (tag === 'input' && !NOT_TEXT[t]);
        if (kind === 'required') return tag !== 'button' && t !== 'hidden';
        return false;
    }

    // Voto de uma instância sobre um controle. Com voto: força a propriedade.
    // Sem votos: devolve o estado de antes da 1ª vez que alguém votou.
    function vote(ctl, kind, id, on) {
        var p = PROP[kind];
        var st = ctl._madWhen || (ctl._madWhen = {});
        var k = st[kind] || (st[kind] = { votes: new Set(), base: false, enforcing: false });
        if (on) k.votes.add(id); else k.votes.delete(id);
        if (k.votes.size > 0) {
            if (!k.enforcing) { k.base = !!ctl[p]; k.enforcing = true; }
            ctl[p] = true;
        } else if (k.enforcing) {
            ctl[p] = k.base;
            k.enforcing = false;
        }
    }

    function voteHidden(root, id, hide) {
        var st = root._madWhenVis || (root._madWhenVis = { votes: new Set(), base: '', enforcing: false });
        if (hide) st.votes.add(id); else st.votes.delete(id);
        if (st.votes.size > 0) {
            if (!st.enforcing) { st.base = root.style.display; st.enforcing = true; }
            root.style.display = 'none';
        } else if (st.enforcing) {
            root.style.display = st.base;
            st.enforcing = false;
        }
    }

    // Uma condição montada num elemento. Não liga listener nenhum: quem
    // decide QUANDO reavaliar é a diretiva (e o teste).
    function mount(el, kind, expression) {
        if (!KINDS[kind]) return null;
        var fn = compile(expression);
        if (!fn) {
            if (window.console) window.console.warn('[mad-when] expressão inválida em ' + kind + '-when:', expression);
            return null;
        }
        var id = ++_seq;
        var root = (el.closest && el.closest('.mad-field')) || el;
        var scope = (el.closest && (el.closest('form') || el.closest('[mad-component]'))) || document;
        var touched = new Set();
        var warned = false;
        var getter = function (n) { return readValue(scope, n); };

        function evaluate() {
            try { return !!fn(getter); }
            catch (e) {
                if (!warned && window.console) { warned = true; window.console.warn('[mad-when] erro ao avaliar ' + kind + '-when:', expression, e); }
                return null;
            }
        }

        function apply() {
            var on = evaluate();
            if (kind === 'visible') { voteHidden(root, id, on === false); return; }
            if (on === null) on = false;
            var list = controls(root);
            for (var i = 0; i < list.length; i++) {
                if (!accepts(kind, list[i])) continue;
                touched.add(list[i]);
                vote(list[i], kind, id, on);
            }
            if (kind === 'disabled' && root.classList) root.classList.toggle('mad-when-disabled', on);
        }

        function release() {
            touched.forEach(function (ctl) { vote(ctl, kind, id, false); });
            touched.clear();
            if (kind === 'visible') voteHidden(root, id, false);
            if (kind === 'disabled' && root.classList) root.classList.remove('mad-when-disabled');
        }

        return { id: id, root: root, scope: scope, apply: apply, release: release };
    }

    return { KINDS: KINDS, compile: compile, readValue: readValue, controls: controls, mount: mount };
})();
// </madWhen:core>

// Liga uma condição a um elemento. `onCleanup` recebe o teardown (o `cleanup`
// do Alpine na diretiva; no-op na montagem fora do Alpine, logo abaixo).
function _madWhenAttach(el, kind, expression, onCleanup) {
        // Re-init do mesmo elemento (MAD Select move o <select> pro wrapper
        // dele; initTree duplo): derruba a instância anterior antes.
        var reg = el._madWhenInst || (el._madWhenInst = {});
        if (reg[kind]) reg[kind]();

        var inst = _madWhen.mount(el, kind, expression);
        if (!inst) return;

        var dead = false, pending = false;
        // Adiado um tick: o valor de campo com máscara (hidden `:value`) só é
        // atualizado pelo Alpine depois do handler de input que disparou.
        var schedule = function () {
            if (pending || dead) return;
            pending = true;
            setTimeout(function () { pending = false; if (!dead) inst.apply(); }, 0);
        };
        inst.scope.addEventListener('change', schedule);
        inst.scope.addEventListener('input', schedule);

        var teardown = function () {
            if (dead) return;
            dead = true;
            inst.scope.removeEventListener('change', schedule);
            inst.scope.removeEventListener('input', schedule);
            inst.release();
            if (reg[kind] === teardown) delete reg[kind];
        };
        reg[kind] = teardown;
        onCleanup(teardown);

        inst.apply();   // já no init: sem piscar o campo habilitado
        schedule();     // e de novo quando os irmãos Alpine terminarem de montar
}

function _madRegisterWhen() {
    if (!window.Alpine || window.Alpine.__madWhenRegistered) return;
    window.Alpine.__madWhenRegistered = true;
    window.Alpine.directive('mad-when', function (el, dir, utils) {
        _madWhenAttach(el, dir.value, dir.expression, utils.cleanup);
    });
}
document.addEventListener('alpine:init', _madRegisterWhen);
if (window.Alpine) _madRegisterWhen();

// O Alpine só percorre o que está DENTRO de um `x-data` (ou o que é inserido
// depois do start — a navegação do mad.js injeta a página e chama initTree).
// Uma página renderizada direto pelo servidor deixa a `<section>` / o input de
// um campo sem x-data próprio de fora, e a condição não ligava, calada. Após o
// start, monta à mão o que ficou para trás — sem initTree, que ativaria as
// OUTRAS diretivas soltas daquele trecho. Se o Alpine alcançar o elemento
// depois, a diretiva derruba esta instância e monta a dela (sem duplicar).
function _madWhenMountOrphans(root) {
    var kinds = Object.keys(_madWhen.KINDS);
    var sel = kinds.map(function (k) { return '[x-mad-when\\:' + k + ']'; }).join(',');
    var els = (root || document).querySelectorAll(sel);
    for (var i = 0; i < els.length; i++) {
        for (var j = 0; j < kinds.length; j++) {
            var attr = 'x-mad-when:' + kinds[j];
            if (!els[i].hasAttribute(attr)) continue;
            if (els[i]._madWhenInst && els[i]._madWhenInst[kinds[j]]) continue;
            _madWhenAttach(els[i], kinds[j], els[i].getAttribute(attr), function () {});
        }
    }
}
document.addEventListener('alpine:initialized', function () { _madWhenMountOrphans(document); });


/* ═══════════════════════════════════════════════════════════════════
   DBCOMBO depends-on — cascata automática entre combos
   ═══════════════════════════════════════════════════════════════════

   Quando um select/input muda de valor, busca selects com
   data-mad-depends="nome_do_campo" no mesmo formulário/wrapper
   e recarrega suas options via AJAX (MadDbComboService).
*/
document.addEventListener('change', function(e) {
    var el   = e.target;
    var name = (el.name || el.id || '').replace(/\[\]$/, '');
    if (!name) return;

    var scope = el.closest('[mad-component]') || el.closest('form') || document;
    var deps  = scope.querySelectorAll('[data-mad-depends="' + name + '"]');
    if (!deps.length) return;

    var parentValue = el.value;

    deps.forEach(function(dep) {
        var token = dep.dataset.madDepToken || '';

        // Limpa o combo filho
        var ms = dep._madSelect;
        if (ms) { ms.clear(true); ms.clearOptions(); }

        if (!parentValue || !token) return;

        // DB Search fields (dbunique/dbmulti): nao faz fetch aqui.
        // Apenas armazena o valor do pai para o load() do MAD Select usar.
        if (dep.dataset.madDbsearch !== undefined || dep.dataset.madDbmultisearch !== undefined) {
            dep.dataset.madDepValue = parentValue;
            return;
        }

        // Fetch para carregar options filtradas — payload minimo (so o token
        // assinado server-side + o valor selecionado do pai). O servidor
        // decripta o token para recuperar a config segura da query.
        fetch(_madServiceUrl('db-combo', 'load', { token: token, value: parentValue }))
            .then(function(r) { return r.text(); })
            .then(function(text) {
                return _madExtractJson(text) || { options: {} };
            })
            .then(function(data) {
                _madOptionsError(dep, data.error);
                var options = data.options || {};
                // Valor posto no filho ENQUANTO o fetch voava — a mesma resposta
                // do servidor que setou o pai costuma trazer o filho junto
                // (form->set('uf') + setItems('municipio', …, $cod)). O fetch
                // termina sempre depois e zerava o filho: o CEP preenchia a UF
                // e apagava o município. Sobrevive se existir na lista nova;
                // valor de outro pai (troca manual SP→PR) não existe e cai.
                var wanted = dep.value || '';
                // Reconstrói as options no <select> nativo (fonte da verdade)
                var placeholder = dep.querySelector('option[value=""]');
                dep.innerHTML = '';
                if (placeholder) dep.appendChild(placeholder);
                else {
                    var opt0 = document.createElement('option');
                    opt0.value = '';
                    opt0.textContent = 'Selecione...';
                    dep.appendChild(opt0);
                }
                Object.keys(options).forEach(function(k) {
                    var opt = document.createElement('option');
                    opt.value = k;
                    opt.textContent = options[k];
                    dep.appendChild(opt);
                });
                dep.value = (wanted !== '' && Object.prototype.hasOwnProperty.call(options, wanted)) ? wanted : '';
                // MAD Select: re-projeta a UI a partir do <select>
                if (ms) ms.refreshOptions(true);

                // Cascata: dispara change no filho para propagar para netos
                dep.dispatchEvent(new Event('change', { bubbles: true }));
            })
            .catch(function(err) {
                console.error('[MadDbCombo] depends-on error:', err);
            });
    });
});

/**
 * Sincroniza a MÁSCARA de um campo numeric/money depois de alguém escrever
 * direto no input `[name]` (que nesses campos é o HIDDEN).
 *
 * O visível não tem `name`: ele mostra `display`, calculado no Alpine a partir
 * de `rawValue`. Quem seta só o hidden (auto-fill `<fill>`, op `val` do
 * `$this->form->set()`, fl_val do field-list) trocava o valor do POST e deixava
 * a tela com o número velho — "não preencheu" do ponto de vista de quem olha.
 * Cada componente formata com as SUAS casas decimais via setValue().
 */
function _madSyncMaskedField(el) {
    if (!el || !window.Alpine) return false;
    var box = el.closest('[x-data]');
    if (!box) return false;
    try {
        var ad = Alpine.$data(box);
        // Contrato do campo mascarado: `setValue()` MAIS o estado que ele
        // formata — `rawValue` (numeric/money, `name` no hidden) ou
        // `selectedDate` (date/datetime, `name` no input visível).
        // O par é proposital: o madSpinnerField também tem `setValue()`, mas
        // escreve no próprio input e dispara `change` — entraria em eco com o
        // evento que o chamador dispara na sequência.
        if (ad && typeof ad.setValue === 'function'
            && ('rawValue' in ad || 'selectedDate' in ad)) {
            ad.setValue(el.value);
            return true;
        }
    } catch (e) { /* nó sem escopo Alpine */ }
    return false;
}

/**
 * Campo `name` visível PARA `src` — sub-form do detail (ou linha do field-list)
 * primeiro, documento depois.
 *
 * Master e detail podem ter campos de mesmo `name` (mesma razão do
 * `df_field_error` ser escopado). Uma busca global escreveria no campo do
 * master quando o `<fill>` foi disparado dentro do sub-form.
 */
function _madScopedField(src, name) {
    var sel   = '[name="' + name + '"]';
    var scope = src.closest('[data-df-fields]') || src.closest('.mad-fl-row');
    return (scope && scope.querySelector(sel))
        || document.querySelector(sel)
        || document.getElementById(name);
}

/* ═══════════════════════════════════════════════════════════════════
   AUTO-FILL (<fill>) — preenche outros campos ao selecionar um registro
   ═══════════════════════════════════════════════════════════════════

   Selects de banco (dbcombo/dbunique-search) com data-mad-autofill-token:
   ao mudar o valor, busca os valores resolvidos server-side
   (MadAutoFillService) e escreve nos campos-alvo. Espelha a cascata acima —
   payload mínimo { token, value }; a config (model/colunas/transform) vive
   cifrada no token, nunca no cliente.
*/
document.addEventListener('change', function (e) {
    var src = e.target;
    if (!src || !src.dataset || src.dataset.madAutofillToken === undefined) return;

    var token   = src.dataset.madAutofillToken || '';
    var fields  = (src.dataset.madAutofillFields || '').split(',')
        .map(function (s) { return s.trim(); }).filter(Boolean);
    var value   = src.value;
    var srcName = src.name || src.id || '';

    // Escreve val no campo `name` (input/select nativo, MAD-select ou texto).
    // onlyEmpty: não sobrescreve campo que já tem valor.
    function write(name, val, onlyEmpty) {
        if (!name || name === srcName) return;       // nunca reescreve a própria origem
        var el = _madScopedField(src, name);
        if (!el) return;
        if (el._madSelect) {
            if (onlyEmpty && el.value) return;
            el._madSelect.setValue(val, true);       // silent: não re-dispara change (evita recursão)
            return;
        }
        var tag = el.tagName;
        if (tag === 'INPUT' || tag === 'SELECT' || tag === 'TEXTAREA') {
            if (onlyEmpty && el.value) return;
            el.value = val;
            // Campo mascarado (numeric/money): `name` mora no HIDDEN e o texto
            // que o usuário vê é `display` no Alpine. Sem isto o auto-fill
            // gravava o valor e a tela continuava em "0,00".
            _madSyncMaskedField(el);
            el.dispatchEvent(new Event('input', { bubbles: true }));
        } else {
            if (onlyEmpty && el.textContent) return;
            el.textContent = val;
        }
    }

    // Sem valor (deselecionou): limpa os campos-alvo (igual ao clear do dbseek).
    if (!value) {
        fields.forEach(function (name) { write(name, '', false); });
        return;
    }
    if (!token) return;

    fetch(_madServiceUrl('auto-fill', 'resolve', { token: token, value: value }))
        .then(function (r) { return r.text(); })
        .then(function (text) { return _madExtractJson(text) || { values: {}, onlyEmpty: [] }; })
        .then(function (data) {
            // Transforms que falharam server-side (callable inválido, spec
            // desconhecido, data não-parseável) — visíveis pro dev no console.
            (data.warnings || []).forEach(function (w) { console.warn('[MadAutoFill]', w); });

            var values    = data.values || {};
            var onlyEmpty = {};
            (data.onlyEmpty || []).forEach(function (f) { onlyEmpty[f] = true; });
            Object.keys(values).forEach(function (name) {
                write(name, values[name] == null ? '' : String(values[name]), !!onlyEmpty[name]);
            });
        })
        .catch(function (err) {
            console.error('[MadAutoFill] error:', err);
        });
});

/**
 * Teleporta o filter-popover para o <body> com position:fixed relativo ao botão.
 * Necessário porque .mad-dg-table-wrap tem overflow:auto, que clipa position:absolute.
 * Usado pelo openPopover() do Alpine (caso não-sticky) e pelo setupProxyFilters (caso sticky).
 * @param {HTMLElement} btnEl   — o botão .mad-dg-filter-btn (pode ser do proxy no sticky)
 * @param {HTMLElement} [wrap]  — o .mad-dg-filter-wrap real (opcional; inferido de btnEl se omitido)
 * @param {HTMLElement} [pop]   — o .mad-dg-filter-popover real (opcional; inferido de wrap se omitido)
 */
window._madPositionFilterPopover = function(btnEl, wrap, pop) {
    wrap = wrap || btnEl.closest('.mad-dg-filter-wrap');
    if (!wrap) return;
    pop = pop || wrap.querySelector('.mad-dg-filter-popover');
    if (!pop) return;

    // Toggle: recoloca se já estiver teleportado
    if (pop._madOrigParent) {
        const ad = wrap._x_dataStack && wrap._x_dataStack[0];
        if (ad) ad.open = false;
        _madReattachPopover(pop);
        return;
    }

    // Garante open=true via Alpine (idempotente se já setado pelo openPopover)
    const adOpen = wrap._x_dataStack && wrap._x_dataStack[0];
    if (adOpen) adOpen.open = true;

    // Garante data-mad-select em selects ainda sem hook
    pop.querySelectorAll('select.mad-select:not([data-mad-select]):not(.mad-select-init)').forEach(function(s) {
        s.setAttribute('data-mad-select', '');
    });
    _madEnergizeFields(pop);

    // Teleporta para body com position:fixed
    const rect = btnEl.getBoundingClientRect();
    pop._madOrigParent = wrap;
    pop._madOrigStyle  = pop.getAttribute('style') || '';
    pop._madGridWrap   = wrap.closest('.mad-dg-wrap');
    document.body.appendChild(pop);

    // Um degrau ABAIXO da camada flutuante: a lista do MAD Select de dentro do
    // popover também vive no <body> em --mad-z-float, e com z-index igual vence
    // quem vem depois no DOM — o popover, anexado agora. A lista abria por
    // baixo dele e a 1ª opção ficava atrás dos botões Filtrar/Limpar. O calc
    // resolve no próprio popover, então o rebase do quick-form continua valendo.
    pop.style.cssText = [
        'position:fixed',
        'top:' + (rect.bottom + 4) + 'px',
        'left:' + rect.left + 'px',
        'z-index:calc(var(--mad-z-float,9999) - 1)',
        'display:flex',
        'flex-direction:column',
        'gap:6px',
    ].join(';');

    // Filtro de coluna alinhada à direita fica na borda direita do th: abrir
    // pra direita a partir do botão vazaria da tela na última coluna. Nesse
    // caso a borda direita do popover encosta na do botão.
    const popW = pop.offsetWidth;
    const vw   = document.documentElement.clientWidth || window.innerWidth;
    if (rect.left + popW > vw - 8) {
        pop.style.left = Math.max(8, rect.right - popW) + 'px';
    }

    // MutationObserver: detecta quando Alpine fecha (display:none) → recoloca
    const obs = new MutationObserver(function() {
        if (pop.parentElement === document.body &&
            getComputedStyle(pop).display === 'none') {
            _madReattachPopover(pop);
        }
    });
    obs.observe(pop, { attributes: true, attributeFilter: ['style'] });
    pop._madObs = obs;

    // Click fora fecha
    setTimeout(function() {
        const outsideClick = function(ev) {
            if (!pop.contains(ev.target) && ev.target !== btnEl) {
                const ad = wrap._x_dataStack && wrap._x_dataStack[0];
                if (ad) ad.open = false;
                _madReattachPopover(pop);
                document.removeEventListener('click', outsideClick, true);
            }
        };
        pop._madOutClick = outsideClick;
        document.addEventListener('click', outsideClick, true);
    }, 0);
};

function _madReattachPopover(pop) {
    if (!pop._madOrigParent) return;
    pop._madOrigParent.appendChild(pop);
    if (pop._madOrigStyle !== undefined) {
        pop.setAttribute('style', pop._madOrigStyle);
        if (!pop._madOrigStyle) pop.removeAttribute('style');
    }
    if (pop._madObs)      { pop._madObs.disconnect(); delete pop._madObs; }
    if (pop._madOutClick) { document.removeEventListener('click', pop._madOutClick, true); delete pop._madOutClick; }
    delete pop._madOrigParent;
    delete pop._madOrigStyle;
}
window._madReattachPopover = _madReattachPopover;

document.addEventListener('DOMContentLoaded', function () {
    // Delay para o runtime legado carregar o conteúdo inicial via AJAX
    setTimeout(function () { _madInitSelects(); }, 300);
});


/* ═══════════════════════════════════════════════════════════════════
   RADIO BUTTON GROUP — toggle is-active class no clique
   ═══════════════════════════════════════════════════════════════════ */

document.addEventListener('change', function (e) {
    var input = e.target;
    if (!input || !input.classList || !input.classList.contains('mad-radio-btn-input')) return;
    var group = input.closest('.mad-radio-btn-group');
    if (!group) return;
    group.querySelectorAll('.mad-radio-btn').forEach(function (lbl) {
        lbl.classList.remove('is-active');
    });
    var label = input.closest('.mad-radio-btn');
    if (label) label.classList.add('is-active');
});


/* ═══════════════════════════════════════════════════════════════════
   LUCIDE ICONS — integração automática
   ═══════════════════════════════════════════════════════════════════ */

/**
 * Chama lucide.createIcons() quando disponível.
 * Seguro de usar mesmo se Lucide não estiver carregado.
 *
 * _madLucide()         → processa toda a página
 * _madLucide([el])     → processa apenas o nó informado (mais eficiente)
 */
function _madLucide(nodes) {
    if (typeof lucide === 'undefined') return;
    nodes ? lucide.createIcons({ nodes }) : lucide.createIcons();
}

/* Monta o Sonner toaster para a posição dada (idempotente) */
function _madToastMount(position = 'bottom-right') {
    const id = 'mad-sonner-toaster-' + position;
    if (document.getElementById(id)) return id;
    const [yPos, xPos] = position.split('-');
    const ol = document.createElement('ol');
    ol.id = id;
    ol.className = 'mad-toast-region';
    ol.setAttribute('data-y-position', yPos || 'bottom');
    ol.setAttribute('data-x-position', xPos || 'right');
    ol.setAttribute('x-data', `madSonnerToaster('${position}')`);
    ol.setAttribute('@mad-toast.window', 'onEvent($event.detail)');
    ol.setAttribute('@mouseenter', 'expanded = true');
    ol.setAttribute('@mousemove', 'expanded = true');
    ol.setAttribute('@mouseleave', 'if (!interacting) expanded = false');
    ol.setAttribute('@pointerdown', 'interacting = true');
    ol.setAttribute('@pointerup', 'interacting = false');
    ol.setAttribute(':data-expanded', 'expanded');
    ol.setAttribute(':style', `'--front-toast-height:' + frontHeight + 'px; --gap: ${_SONNER_GAP}px'`);
    ol.innerHTML = `<template x-for="(toast, idx) in toasts" :key="toast.id">
        <li class="mad-toast"
            :data-type="toast.type"
            :data-styled="true"
            :data-mounted="toast.mounted"
            :data-removed="toast.removed"
            :data-visible="idx < ${_SONNER_VISIBLE}"
            :data-y-position="_yPos"
            :data-x-position="_xPos"
            :data-front="idx === 0"
            :data-expanded="expanded"
            :data-swiping="toast.swiping"
            :data-swipe-out="toast.swipeOut"
            :data-swipe-direction="toast.swipeDir"
            :data-rich-colors="true"
            :style="toastStyle(idx)"
            x-effect="measureHeight(toast, $el)"
            @pointerdown="onPointerDown($event, toast, idx)"
            @pointermove="onPointerMove($event, toast)"
            @pointerup="onPointerUp($event, toast)">
            <button class="mad-toast-close" @click.stop.prevent="deleteToast(toast)" aria-label="Fechar">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
            <div class="mad-toast-icon" x-html="getIcon(toast.type)"></div>
            <div class="mad-toast-content">
                <div class="mad-toast-title" x-show="toast.title" x-text="toast.title"></div>
                <div class="mad-toast-desc" x-text="toast.message"></div>
            </div>
        </li>
    </template>`;
    document.body.appendChild(ol);
    if (typeof _madRewriteAlpineAttrs === 'function') _madRewriteAlpineAttrs(ol);
    if (window.Alpine) Alpine.initTree(ol);
    return id;
}

/* ── DB Blocks ── */
document.addEventListener('alpine:init', () => {
    Alpine.data('madDbBlocks', (cfg) => ({
        open: false,
        init() {
            window.addEventListener('mad-db-blocks-popover-close', (e) => {
                if (e.detail && e.detail.field === cfg.field) this.open = false;
            });
            // Reposiciona o popover (position:fixed) ao rolar/redimensionar.
            // capture=true pega scroll de containers internos (ex: sidebar com overflow).
            this._reposition = () => { if (this.open) this.positionPopover(); };
            window.addEventListener('scroll', this._reposition, true);
            window.addEventListener('resize', this._reposition);
        },
        openAdder() {
            if (cfg.addMode === 'modal') {
                window.dispatchEvent(new CustomEvent('madmodal', { detail: { name: 'db-blocks-' + cfg.field + '-modal', action: 'open' } }));
            } else if (cfg.addMode === 'drawer') {
                window.dispatchEvent(new CustomEvent('maddrawer', { detail: { name: 'db-blocks-' + cfg.field + '-drawer', action: 'open' } }));
            } else {
                this.open = !this.open;
                if (this.open) this.$nextTick(() => this.positionPopover());
            }
        },
        // Ancora o popover ao botao via position:fixed, escapando o clip de
        // qualquer ancestral com overflow (sidebar com overflow-y:auto).
        positionPopover() {
            const pop = this.$refs.popover;
            const btn = this.$refs.addBtn;
            if (!pop || !btn) return;
            const r = btn.getBoundingClientRect();
            pop.style.position = 'fixed';
            pop.style.margin = '0';
            const pw = pop.offsetWidth || 280;
            const ph = pop.offsetHeight || 0;
            let left = r.left;
            if (left + pw > window.innerWidth - 8) left = window.innerWidth - pw - 8;
            if (left < 8) left = 8;
            let top = r.bottom + 6;
            // Sem espaco abaixo? abre pra cima.
            if (top + ph > window.innerHeight - 8 && r.top - ph - 6 > 8) {
                top = r.top - ph - 6;
            }
            pop.style.top = top + 'px';
            pop.style.left = left + 'px';
        },
    }));
});

/* Re-inicializa após Alpine montar o DOM completo */
document.addEventListener('alpine:initialized', () => { _madLucide(); _madToastMount('bottom-right'); });

/* Magic $lucide(el?) — use dentro de x-init para conteúdo dinâmico
 *
 * Exemplos:
 *   x-init="$lucide()"       → re-processa a página toda
 *   x-init="$lucide($el)"    → re-processa só este elemento (recomendado em x-for)
 *   x-init="$nextTick(() => $lucide($el))"  → após Alpine renderizar o slot
 */
document.addEventListener('alpine:init', () => {
    Alpine.magic('lucide', (el) => (target) => {
        if (target === undefined) {
            _madLucide();
        } else {
            _madLucide(Array.isArray(target) ? target : [target]);
        }
    });
});

/* ═══════════════════════════════════════════════════════════════════
   HELPERS GLOBAIS (independentes do Alpine)
   ═══════════════════════════════════════════════════════════════════ */

/**
 * madToast(options) — dispara um toast Sonner-style.
 *
 * madToast({ message: 'Salvo!', type: 'success' })
 * madToast({ message: 'Atenção', type: 'warning', title: 'Aviso' })
 * madToast('Mensagem', 'success', 'Título', 5000)
 *
 * Atalhos:
 *   madToast.success('Salvo!', 'Título')
 *   madToast.danger('Erro!')
 *   madToast.warning('Atenção')
 *   madToast.info('Info')
 */
window.madToast = function (messageOrObj, type, title, duration) {
    let detail;
    if (typeof messageOrObj === 'object' && messageOrObj !== null) {
        detail = messageOrObj;
    } else {
        detail = {
            message: messageOrObj || '',
            type: type || 'info',
            title: title || '',
            duration: duration !== undefined ? duration : 4000,
        };
    }
    const position = detail.position || 'bottom-right';
    _madToastMount(position);
    setTimeout(() => window.dispatchEvent(new CustomEvent('mad-toast', { detail: { ...detail, position } })), 0);
};

madToast.success = (message, title = '') => madToast({ message, type: 'success', title });
madToast.danger  = (message, title = '') => madToast({ message, type: 'danger',  title });
madToast.error   = (message, title = '') => madToast({ message, type: 'danger',  title });
madToast.warning = (message, title = '') => madToast({ message, type: 'warning', title });
madToast.info    = (message, title = '') => madToast({ message, type: 'info',    title });


/* ═══════════════════════════════════════════════════════════════════
   FORM FIELDS — Alpine components para campos de formulário
   ═══════════════════════════════════════════════════════════════════ */

// Identificador de um arquivo NOVO de um campo de upload (Upload Múltiplo,
// célula Arquivos da Lista de itens). Viaja com o arquivo
// (`__mad_new_files[campo][]`) e volta na resposta do Salvar (op `files_saved`)
// com o lugar onde ele foi gravado: é por ele que o campo sabe QUAL arquivo
// deixou de ser novo — a lista pode ter mudado enquanto o Salvar corria.
var _madUploadSeq = 0;
function _madUploadUid() {
    return 'u' + Date.now().toString(36) + (++_madUploadSeq).toString(36) + Math.random().toString(36).slice(2, 8);
}

// ── Limites dos campos de upload (tamanho, tipo, quantidade) ───────────────
// O navegador avisa ANTES de enviar; quem faz a regra valer é o servidor, no
// Salvar (Mad\Form\MadUploadRules) — as duas leituras têm de ser a mesma.

// Extensões que nenhum upload grava. Espelha MadUploadRules::BLOCKED_EXTENSIONS.
var _MAD_UPLOAD_BLOCKED = ['php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'pht', 'phar', 'phpt', 'inc',
    'htaccess', 'htpasswd', 'cgi', 'pl', 'py', 'sh', 'rb', 'asp', 'aspx', 'jsp', 'jspx',
    'exe', 'bat', 'cmd', 'msi', 'com', 'scr', 'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'mathml', 'vtt'];

// Tipo pelo NOME do arquivo. Espelha MadUploadRules::EXTENSION_TYPES.
var _MAD_UPLOAD_TYPES = {
    png: 'image/png', jpg: 'image/jpeg', jpeg: 'image/jpeg', jfif: 'image/jpeg', pjpeg: 'image/jpeg',
    gif: 'image/gif', webp: 'image/webp', bmp: 'image/bmp', svg: 'image/svg+xml', svgz: 'image/svg+xml',
    avif: 'image/avif', heic: 'image/heic', heif: 'image/heif', tif: 'image/tiff', tiff: 'image/tiff',
    ico: 'image/x-icon',
    pdf: 'application/pdf', zip: 'application/zip', rar: 'application/vnd.rar', '7z': 'application/x-7z-compressed',
    json: 'application/json', xml: 'application/xml', rtf: 'application/rtf',
    doc: 'application/msword', docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    xls: 'application/vnd.ms-excel', xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ppt: 'application/vnd.ms-powerpoint', pptx: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    odt: 'application/vnd.oasis.opendocument.text', ods: 'application/vnd.oasis.opendocument.spreadsheet',
    txt: 'text/plain', csv: 'text/csv', html: 'text/html', htm: 'text/html', md: 'text/markdown',
    mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', oga: 'audio/ogg', m4a: 'audio/mp4',
    aac: 'audio/aac', flac: 'audio/flac', weba: 'audio/webm', opus: 'audio/opus',
    mp4: 'video/mp4', m4v: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime', avi: 'video/x-msvideo',
    mkv: 'video/x-matroska', mpeg: 'video/mpeg', mpg: 'video/mpeg', ogv: 'video/ogg', '3gp': 'video/3gpp',
};
var _MAD_UPLOAD_ALIASES = {
    'image/jpg': 'image/jpeg', 'image/pjpeg': 'image/jpeg', 'image/x-png': 'image/png',
    'audio/mp3': 'audio/mpeg', 'audio/x-wav': 'audio/wav', 'application/x-pdf': 'application/pdf',
    'application/x-zip-compressed': 'application/zip',
};

function _madUploadExt(name) {
    var m = /\.([^./\\]+)$/.exec(String(name || ''));
    return m ? m[1].toLowerCase() : '';
}

// "850 KB", "2,5 MB". Com `ceil`, arredonda para cima: o arquivo de 2,004 MB
// sai "2,01 MB", não "2 MB" igual ao limite.
function _madUploadSize(bytes, ceil) {
    bytes = Number(bytes) || 0;
    if (bytes < 1024) return bytes + ' B';
    var value = bytes / 1024, unit = 'KB';
    ['MB', 'GB'].forEach(function(next) { if (value >= 1024) { value /= 1024; unit = next; } });
    value = ceil ? Math.ceil(value * 100) / 100 : Math.round(value * 100) / 100;
    return String(value).replace('.', ',') + ' ' + unit;
}

// O arquivo é de um tipo que `accept` aceita? Extensões (`.pdf`), tipos
// (`application/pdf`) e famílias (`image/*`), separados por vírgula; vazio e
// `*` aceitam tudo. O tipo sai do NOME do arquivo; só quando a extensão não é
// conhecida vale o que o navegador informa.
function _madUploadAccepts(accept, file) {
    var tokens = String(accept || '').toLowerCase().split(',').map(function(t) { return t.trim(); }).filter(Boolean);
    if (!tokens.length) return true;
    var ext  = _madUploadExt(file && file.name);
    var type = _MAD_UPLOAD_TYPES[ext] || String((file && file.type) || '').toLowerCase();
    type = _MAD_UPLOAD_ALIASES[type] || type;
    return tokens.some(function(token) {
        if (token === '*' || token === '*/*') return true;
        if (token.indexOf('/') === -1) {
            // Extensões do mesmo tipo se equivalem (`.jpg` aceita `foto.jpeg`).
            var wanted = token.replace(/^\./, '');
            return ext !== '' && (wanted === ext || (!!_MAD_UPLOAD_TYPES[wanted] && _MAD_UPLOAD_TYPES[wanted] === _MAD_UPLOAD_TYPES[ext]));
        }
        token = _MAD_UPLOAD_ALIASES[token] || token;
        if (!type) return false;
        return /\/\*$/.test(token) ? type.indexOf(token.slice(0, -1)) === 0 : type === token;
    });
}

// `accept` para a mensagem: "PDF, imagens, DOCX".
function _madUploadAcceptLabel(accept) {
    var families = { 'image/*': 'imagens', 'audio/*': 'áudio', 'video/*': 'vídeo' };
    var labels = [];
    String(accept || '').toLowerCase().split(',').map(function(t) { return t.trim(); }).filter(Boolean).forEach(function(token) {
        var label = families[token];
        if (!label) {
            if (token.indexOf('/') === -1) {
                label = token.replace(/^\./, '').toUpperCase();
            } else {
                var wanted = _MAD_UPLOAD_ALIASES[token] || token;
                var ext = Object.keys(_MAD_UPLOAD_TYPES).filter(function(k) { return _MAD_UPLOAD_TYPES[k] === wanted; })[0];
                label = (ext || token.slice(token.indexOf('/') + 1)).toUpperCase();
            }
        }
        if (labels.indexOf(label) === -1) labels.push(label);
    });
    return labels.join(', ');
}

// Por que este arquivo não entra no campo — ou '' quando entra.
// rules: { accept, maxBytes, serverMax, stored } (stored = o campo grava o
// arquivo no servidor: as extensões bloqueadas valem).
function _madUploadProblem(file, rules) {
    rules = rules || {};
    var name = (file && file.name) || 'arquivo';
    var ext  = _madUploadExt(name);
    if (rules.stored && ext && _MAD_UPLOAD_BLOCKED.indexOf(ext) !== -1) {
        return 'O arquivo "' + name + '" não pode ser enviado: arquivos .' + ext + ' não são aceitos por segurança.';
    }
    if (rules.accept && !_madUploadAccepts(rules.accept, file)) {
        return 'O arquivo "' + name + '" não é de um tipo aceito neste campo (' + _madUploadAcceptLabel(rules.accept) + ').';
    }
    var size = Number(file && file.size) || 0;
    if (rules.maxBytes > 0 && size > rules.maxBytes) {
        return 'O arquivo "' + name + '" tem ' + _madUploadSize(size, true) + '; o limite deste campo é ' + _madUploadSize(rules.maxBytes) + '.';
    }
    if (rules.serverMax > 0 && size > rules.serverMax) {
        return 'O arquivo "' + name + '" tem ' + _madUploadSize(size, true) + '; o servidor aceita até ' + _madUploadSize(rules.serverMax) + ' por arquivo.';
    }
    return '';
}

function _madUploadWarn(message) {
    if (message && typeof madToast === 'function') madToast(message, 'warning');
}

document.addEventListener('alpine:init', () => {

    /* madSpinnerField — TSpinner */
    Alpine.data('madSpinnerField', (cfg = {}) => ({
        min:  cfg.min  ?? null,
        max:  cfg.max  ?? null,
        step: cfg.step ?? 1,

        getValue() {
            const v = parseFloat(this.$refs.input.value);
            return isNaN(v) ? 0 : v;
        },
        setValue(v) {
            if (this.min !== null) v = Math.max(this.min, v);
            if (this.max !== null) v = Math.min(this.max, v);
            this.$refs.input.value = v;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        increment() { this.setValue(this.getValue() + this.step); },
        decrement() { this.setValue(this.getValue() - this.step); },
        isAtMin()   { return this.min !== null && this.getValue() <= this.min; },
        isAtMax()   { return this.max !== null && this.getValue() >= this.max; },
    }));

    /* madColorField — input hex + swatch com Pickr popover */
    Alpine.data('madColorField', (initial = '#000000') => ({
        color: initial || '#000000',
        pickr: null,
        _syncingFromPickr: false,
        init() {
            if (typeof Pickr === 'undefined') {
                console.warn('madColorField: Pickr not loaded');
                return;
            }
            const self = this;
            this.pickr = Pickr.create({
                el: this.$refs.swatch,
                container: this.$el,
                useAsButton: true,
                theme: 'classic',
                default: this.color || '#000000',
                swatches: [
                    '#F44336','#E91E63','#9C27B0','#673AB7','#3F51B5','#2196F3',
                    '#03A9F4','#00BCD4','#009688','#4CAF50','#8BC34A','#CDDC39',
                    '#FFEB3B','#FFC107','#FF9800','#FF5722','#795548','#9E9E9E',
                    '#607D8B','#000000','#ffffff'
                ],
                components: {
                    preview: true,
                    opacity: false,
                    hue: true,
                    interaction: { hex: true, input: true, clear: true, save: true }
                },
                i18n: { 'btn:save': 'Salvar', 'btn:clear': 'Limpar' }
            });
            this.pickr.on('change', (c) => {
                self._syncingFromPickr = true;
                self.color = c.toHEXA().toString();
                self._syncingFromPickr = false;
            });
            this.pickr.on('save', (c) => {
                if (c) {
                    self._syncingFromPickr = true;
                    self.color = c.toHEXA().toString();
                    self._syncingFromPickr = false;
                }
                self.pickr.hide();
            });
            this.pickr.on('clear', () => {
                self._syncingFromPickr = true;
                self.color = '';
                self._syncingFromPickr = false;
            });
        },
        syncFromInput() {
            if (this._syncingFromPickr || !this.pickr) return;
            const v = (this.color || '').trim();
            if (/^#?[0-9a-fA-F]{3}([0-9a-fA-F]{3})?([0-9a-fA-F]{2})?$/.test(v)) {
                const hex = v.startsWith('#') ? v : '#' + v;
                try { this.pickr.setColor(hex, true); } catch (e) {}
            }
        },
        destroy() {
            if (this.pickr) { try { this.pickr.destroyAndRemove(); } catch(e){} }
        }
    }));

    /* madIconField — picker de icones Lucide com busca, recents, keyboard nav e paginacao */
    let _madIconCache = null;
    function _madBuildIconList() {
        if (_madIconCache) return _madIconCache;
        if (typeof lucide === 'undefined' || !lucide.icons) return [];
        const kebab = (s) => s
            .replace(/([a-z0-9])([A-Z])/g, '$1-$2')
            .replace(/([A-Z])([A-Z][a-z])/g, '$1-$2')
            .toLowerCase();
        const seen = new Set();
        const out = [];
        Object.keys(lucide.icons).forEach((k) => {
            const name = kebab(k);
            if (!seen.has(name)) { seen.add(name); out.push(name); }
        });
        out.sort();
        _madIconCache = out;
        return _madIconCache;
    }

    Alpine.data('madIconField', (initial = '', opts = {}) => ({
        selectedIcon: initial || '',
        allowClear: opts.allowClear !== false,
        open: false,
        query: '',
        tab: 'all',
        all: [],
        filtered: [],
        visible: [],
        pageSize: 120,
        recents: [],
        cursor: -1,
        _renderTimer: null,

        init() {
            this.all = _madBuildIconList();
            try {
                this.recents = JSON.parse(localStorage.getItem('mad-iconfield-recents') || '[]');
            } catch (e) { this.recents = []; }
            this.filter();
            this.$nextTick(() => this.renderIcons());
            this.$watch('selectedIcon', () => {
                this.$nextTick(() => this.renderIcons());
                // dispara change para mad:model coletar
                const inp = this.$el.querySelector('input[type="hidden"][name]');
                if (inp) inp.dispatchEvent(new Event('change', { bubbles: true }));
            });
        },

        toggle() {
            this.open ? this.close() : this.openPopover();
        },

        openPopover() {
            this.open = true;
            // se cache ainda vazio (lucide carregou tarde), tenta de novo
            if (!this.all.length) {
                this.all = _madBuildIconList();
                this.filter();
            }
            this.$nextTick(() => {
                if (this.$refs.searchInput) this.$refs.searchInput.focus();
                this.renderIcons();
                this.scrollSelectedIntoView();
            });
        },

        close() { this.open = false; this.cursor = -1; },

        filter() {
            const q = (this.query || '').trim().toLowerCase();
            const src = this.tab === 'recent' ? this.recents : this.all;
            this.filtered = q ? src.filter(n => n.indexOf(q) !== -1) : src.slice();
            this.visible = this.filtered.slice(0, this.pageSize);
            this.cursor = this.visible.length ? 0 : -1;
            if (this.$refs.grid) this.$refs.grid.scrollTop = 0;
            this.scheduleRender();
        },

        onScroll(e) {
            const el = e.target;
            if (el.scrollTop + el.clientHeight >= el.scrollHeight - 80
                && this.visible.length < this.filtered.length) {
                const next = this.visible.length + this.pageSize;
                this.visible = this.filtered.slice(0, next);
                this.scheduleRender();
            }
        },

        pick(icon) {
            this.selectedIcon = icon;
            this.pushRecent(icon);
            this.close();
        },

        clear() {
            this.selectedIcon = '';
            this.close();
        },

        pushRecent(icon) {
            if (!icon) return;
            const next = [icon].concat(this.recents.filter(i => i !== icon)).slice(0, 24);
            this.recents = next;
            try { localStorage.setItem('mad-iconfield-recents', JSON.stringify(next)); } catch (e) {}
        },

        moveCursor(delta) {
            if (!this.visible.length) return;
            const max = this.visible.length - 1;
            let next = this.cursor + delta;
            if (next < 0) next = 0;
            if (next > max) {
                // tenta carregar mais ao chegar no fim
                if (this.visible.length < this.filtered.length) {
                    this.visible = this.filtered.slice(0, this.visible.length + this.pageSize);
                    this.scheduleRender();
                    next = Math.min(this.visible.length - 1, this.cursor + delta);
                } else {
                    next = max;
                }
            }
            this.cursor = next;
            this.$nextTick(() => this.scrollCursorIntoView());
        },

        pickCursor() {
            if (this.cursor >= 0 && this.visible[this.cursor]) {
                this.pick(this.visible[this.cursor]);
            }
        },

        scrollCursorIntoView() {
            if (!this.$refs.grid) return;
            const cells = this.$refs.grid.querySelectorAll('.mad-iconfield-cell');
            const cell = cells[this.cursor];
            if (cell) cell.scrollIntoView({ block: 'nearest' });
        },

        scrollSelectedIntoView() {
            if (!this.selectedIcon || !this.$refs.grid) return;
            const idx = this.visible.indexOf(this.selectedIcon);
            if (idx === -1) return;
            this.cursor = idx;
            this.$nextTick(() => this.scrollCursorIntoView());
        },

        scheduleRender() {
            if (this._renderTimer) clearTimeout(this._renderTimer);
            this._renderTimer = setTimeout(() => this.renderIcons(), 30);
        },

        renderIcons() {
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                try {
                    lucide.createIcons({ nameAttr: 'data-lucide', root: this.$el });
                } catch (e) {}
            }
        },
    }));

    /* madPasswordField — input password com toggle de visibilidade + validação de força */
    Alpine.data('madPasswordField', (cfg = {}) => ({
        visible: false,
        showPopover: false,
        cfg: cfg,
        rules: cfg.rules || {},
        popoverTitle: cfg.popoverTitle || '',
        checks: {},

        init() {
            this.recompute();
            this.$nextTick(() => {
                if (window.lucide && typeof window.lucide.createIcons === 'function') {
                    window.lucide.createIcons();
                }
            });
        },

        get activeRules() {
            const out = {};
            for (const k in this.rules) {
                const r = this.rules[k];
                if (r && r.value !== false && r.value !== null && r.value !== undefined) {
                    out[k] = r;
                }
            }
            return out;
        },

        toggleVisible() {
            this.visible = !this.visible;
            const inp = this.$refs.input;
            if (inp) {
                const pos = inp.selectionStart;
                this.$nextTick(() => {
                    inp.focus();
                    try { inp.setSelectionRange(pos, pos); } catch (e) {}
                });
            }
            this.$nextTick(() => {
                if (window.lucide) window.lucide.createIcons();
            });
        },

        onInput(e) {
            this.recompute();
        },

        onFocus() {
            if (this.cfg.strongPassword) this.showPopover = true;
        },

        onBlur() {
            setTimeout(() => { this.showPopover = false; }, 150);
        },

        recompute() {
            if (!this.cfg.strongPassword) return;
            const v = this.$refs.input ? this.$refs.input.value : '';
            const r = this.rules;
            this.checks = {
                minLength:          r.minLength          ? v.length >= (r.minLength.value || 8) : true,
                requireNumbers:     r.requireNumbers     ? /[0-9]/.test(v)  : true,
                requireLowercase:   r.requireLowercase   ? /[a-z]/.test(v)  : true,
                requireUppercase:   r.requireUppercase   ? /[A-Z]/.test(v)  : true,
                requireSpecialChar: r.requireSpecialChar ? /[^\w]/.test(v)  : true,
            };
            this.$nextTick(() => { if (window.lucide) window.lucide.createIcons(); });
        },

        isValid() {
            if (!this.cfg.strongPassword) return true;
            for (const k in this.checks) {
                if (this.checks[k] === false) return false;
            }
            return true;
        },
    }));

    /* madChecklist — Lista de seleção múltipla com busca e counter */
    Alpine.data('madChecklist', (cfg = {}) => ({
        checkedIds: cfg.ids ? cfg.ids.map(String) : [],
        query: '',
        showCheckedOnly: false,
        items: cfg.items || [],
        idCol: cfg.idCol || 'id',
        cols:  cfg.cols  || [],

        // A linha deste item aparece com a busca e o filtro "somente
        // selecionados" de agora? A view desenha a lista INTEIRA e esconde
        // (x-show) o que não passa aqui: a linha escondida continua no DOM,
        // com a marca e os campos das colunas de transform / slot.
        isShown(item) {
            if (this.showCheckedOnly && !this.checkedIds.includes(String(item[this.idCol]))) return false;
            if (!this.query) return true;
            const q = this.query.toLowerCase();
            return Object.values(item).some(v => v !== null && v !== undefined && String(v).toLowerCase().includes(q));
        },

        // Só o que está à mostra: contador de vazio, "marcar todos" e o estado
        // da caixa do cabeçalho agem sobre isto.
        get filteredItems() {
            return this.items.filter(item => this.isShown(item));
        },

        // O que o campo ENVIA no Salvar: as marcas da lista inteira, na ordem
        // da lista — nunca só as da busca. Lido pelo MadWire
        // (_collectModelValues); a marca de um item que não está mais na lista
        // (items trocados por reload_checklist) fica de fora, como sempre ficou.
        selection() {
            const checked = new Set(this.checkedIds);
            return this.items.map(item => String(item[this.idCol])).filter(id => checked.has(id));
        },

        get checkedCount() { return this.checkedIds.length; },
        get totalCount()   { return this.items.length; },
        get allChecked() {
            const fi = this.filteredItems;
            if (fi.length === 0) return false;
            return fi.every(item => this.checkedIds.includes(String(item[this.idCol])));
        },

        isChecked(id) {
            return this.checkedIds.includes(String(id));
        },

        toggle(id) {
            id = String(id);
            const i = this.checkedIds.indexOf(id);
            i === -1 ? this.checkedIds.push(id) : this.checkedIds.splice(i, 1);
        },

        toggleAll(e) {
            const checked = e.target.checked;
            const filteredIds = this.filteredItems.map(item => String(item[this.idCol]));
            if (checked) {
                this.checkedIds = [...new Set([...this.checkedIds, ...filteredIds])];
            } else {
                const removeSet = new Set(filteredIds);
                this.checkedIds = this.checkedIds.filter(id => !removeSet.has(id));
            }
        },
    }));

    /* madMultiFile — TMultiFile */
    // ── Helpers de arquivo compartilhados ─────────────────────────────────
    var _madFileIcons = {
        pdf:  { icon: 'file-text',  color: 'var(--mad-danger-light, #fee2e2)' },
        doc:  { icon: 'file-text',  color: 'var(--mad-primary-light, #dbeafe)' },
        docx: { icon: 'file-text',  color: 'var(--mad-primary-light, #dbeafe)' },
        xls:  { icon: 'file-spreadsheet', color: 'var(--mad-success-light, #dcfce7)' },
        xlsx: { icon: 'file-spreadsheet', color: 'var(--mad-success-light, #dcfce7)' },
        csv:  { icon: 'file-spreadsheet', color: 'var(--mad-success-light, #dcfce7)' },
        ppt:  { icon: 'file-presentation', color: '#fef3c7' },
        pptx: { icon: 'file-presentation', color: '#fef3c7' },
        zip:  { icon: 'file-archive', color: '#f3e8ff' },
        rar:  { icon: 'file-archive', color: '#f3e8ff' },
        '7z': { icon: 'file-archive', color: '#f3e8ff' },
        mp4:  { icon: 'file-video',  color: '#ede9fe' },
        avi:  { icon: 'file-video',  color: '#ede9fe' },
        mov:  { icon: 'file-video',  color: '#ede9fe' },
        mp3:  { icon: 'file-audio',  color: '#fce7f3' },
        wav:  { icon: 'file-audio',  color: '#fce7f3' },
        txt:  { icon: 'file-text',   color: 'var(--mad-bg-muted, #f3f4f6)' },
        json: { icon: 'file-code',   color: 'var(--mad-bg-muted, #f3f4f6)' },
        xml:  { icon: 'file-code',   color: 'var(--mad-bg-muted, #f3f4f6)' },
    };

    function _madFileInfo(file) {
        var name    = file.name || 'arquivo';
        var ext     = name.split('.').pop().toLowerCase();
        var isImage = /^image\//.test(file.type);
        var isPdf   = ext === 'pdf';
        var meta    = _madFileIcons[ext] || { icon: 'file', color: 'var(--mad-bg-muted, #f3f4f6)' };
        var sizeText;
        if (file.size < 1024) sizeText = file.size + ' B';
        else if (file.size < 1024 * 1024) sizeText = (file.size / 1024).toFixed(1) + ' KB';
        else sizeText = (file.size / (1024 * 1024)).toFixed(1) + ' MB';

        var info = {
            raw: file, name: name, ext: ext, sizeText: sizeText,
            isImage: isImage, isPdf: isPdf,
            icon: meta.icon, color: meta.color, thumb: null,
            previewType: isImage ? 'image' : (isPdf ? 'pdf' : 'other'),
        };

        if (isImage) {
            info.thumb = URL.createObjectURL(file);
        }
        return info;
    }

    // ── madFileField (single file) ────────────────────────────────────────
    Alpine.data('madFileField', (cfg = {}) => ({
        file:      null,
        dragOver:  false,
        removed:   false,
        fieldName: cfg.fieldName || '',

        init() {
            // Arquivo existente (edição) — mostra thumbnail/ícone
            if (cfg.existingName) {
                var name = cfg.existingName;
                var url  = cfg.existingUrl || '';
                var ext  = name.split('.').pop().toLowerCase();
                var isImg = /^(jpg|jpeg|png|gif|webp|svg|bmp)$/.test(ext);
                var meta  = _madFileIcons[ext] || { icon: 'file', color: 'var(--mad-bg-muted, #f3f4f6)' };
                this.file = {
                    raw: null, name: name, ext: ext,
                    isImage: isImg, isPdf: ext === 'pdf',
                    icon: meta.icon, color: meta.color,
                    thumb: isImg && url ? url : null,
                    sizeText: 'Arquivo existente',
                    existing: true, existingUrl: url,
                    previewType: isImg ? 'image' : (ext === 'pdf' ? 'pdf' : 'other'),
                };
            }
        },
        onSelect(e) {
            var f = e.target.files[0];
            if (f && this._refuse(f, e.target)) return;
            this.file    = f ? this._newFile(f) : null;
            this.removed = false;
            if (f) { this._autoFillName(f); this._fireMadChange(); }
        },
        onDrop(e, input) {
            this.dragOver = false;
            var dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length) {
                // Arrastar e soltar não passa pelo `accept` do <input>: confere aqui.
                if (this._refuse(dt.files[0], null)) return;
                input.files = dt.files;
                this.file    = this._newFile(dt.files[0]);
                this.removed = false;
                this._autoFillName(dt.files[0]);
                this._fireMadChange();
            }
        },
        // Tamanho máximo e Tipos aceitos do campo (e o limite do servidor): o
        // arquivo que não passa não entra, e o campo fica como estava. `input`
        // é o <input type="file"> que já recebeu o arquivo recusado (escolha
        // pela janela): volta a ter o arquivo novo anterior, ou nenhum.
        _refuse(f, input) {
            var problem = _madUploadProblem(f, { accept: cfg.accept, maxBytes: cfg.maxBytes, serverMax: cfg.serverMax, stored: cfg.stored });
            if (!problem) return false;
            _madUploadWarn(problem);
            if (input) {
                input.value = '';
                var previous = this.file && !this.file.existing ? this.file.raw : null;
                if (previous && typeof DataTransfer !== 'undefined') {
                    try { var dt = new DataTransfer(); dt.items.add(previous); input.files = dt.files; } catch (_) {}
                }
            }
            return true;
        },
        // Arquivo escolhido agora: leva um identificador (vai no POST em
        // `__mad_new_files[campo]`), que a resposta do Salvar devolve quando o
        // arquivo é gravado — ver markSaved().
        _newFile(f) {
            var info = _madFileInfo(f);
            info.uid = _madUploadUid();
            return info;
        },
        // O servidor gravou o arquivo que este campo mandou (op `files_saved`):
        // ele passa a ser o arquivo EXISTENTE do campo. A tela não é
        // redesenhada depois do Salvar — sem isto o campo seguia com o arquivo
        // como "novo": mandava-o de novo a cada Salvar, e o Remover só
        // esvaziava o campo (o registro continuava com o arquivo). Casado pelo
        // identificador: o arquivo que o usuário escolheu enquanto o Salvar
        // corria continua novo.
        markSaved(saved) {
            var s = (saved || [])[0];
            var f = this.file;
            if (!s || !s.uid || !f || f.existing || f.uid !== s.uid) return;
            // Sem endereço de download: a prévia continua saindo do arquivo
            // que o navegador ainda tem.
            var local = (!s.url && f.raw && typeof URL !== 'undefined' && URL.createObjectURL) ? URL.createObjectURL(f.raw) : '';
            f.raw         = null;
            f.existing    = true;
            f.existingUrl = s.url || local;
            f.name        = s.name || f.name;
            f.sizeText    = 'Arquivo existente';
            var input = this.$refs ? this.$refs.fileInput : null;
            if (input) input.value = '';   // já gravado: não vai de novo no próximo Salvar
        },
        // auto-fill-name="campo": preenche o input de texto irmão com o nome do
        // arquivo (sem extensão) quando ele ainda está vazio — o usuário segue
        // livre pra editar depois. Dispara input+change p/ mad:model enxergar.
        _autoFillName(f) {
            var target = cfg.autoFillName;
            if (!target || !f || !f.name) return;
            var scope = this.$el.closest('form') || this.$el.closest('[mad-component]') || document;
            var input = scope.querySelector('input[name="' + target + '"], textarea[name="' + target + '"]');
            if (!input || String(input.value || '').trim() !== '') return;
            input.value = f.name.replace(/\.[^.\/\\]+$/, '');
            input.dispatchEvent(new Event('input',  { bubbles: true }));
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },
        _fireMadChange() {
            var action = cfg.madChangeAction;
            if (!action) return;
            var wrapper = this.$el.closest('[mad-component]');
            if (wrapper && typeof MadWire !== 'undefined') {
                MadWire.call(wrapper, action, [cfg.fieldName]);
            }
        },
        removeFile(input) {
            if (this.file && this.file.thumb && !this.file.existing) {
                URL.revokeObjectURL(this.file.thumb);
            }
            this.removed = !!(this.file && this.file.existing);
            this.file    = null;
            input.value  = '';
        },
        openPreview() {
            if (!this.file) return;
            var f   = this.file;
            var src;
            if (f.existing) {
                src = f.existingUrl;
            } else if (f.isImage) {
                src = f.thumb;
            } else if (f.raw) {
                src = URL.createObjectURL(f.raw);
            } else {
                src = null;
            }
            window.dispatchEvent(new CustomEvent('mad-file-preview', {
                detail: {
                    field: this.fieldName, src: src, name: f.name,
                    type: f.previewType,
                    downloadUrl: f.existing ? f.existingUrl : null,
                }
            }));
        },
    }));

    // ── madMultiFile (multi file com galeria) ─────────────────────────────
    Alpine.data('madMultiFile', (cfg = {}) => ({
        files:         [],
        existingFiles: [],  // paths existentes no servidor (mode=comma)
        dragOver:      false,
        maxFiles:      cfg.maxFiles  || 0,
        maxSize:       cfg.maxSize   || 0, // KB
        fieldName:     cfg.fieldName || '',

        init() {
            // Carrega arquivos existentes (edição)
            if (cfg.existingFiles && cfg.existingFiles.length) {
                var self = this;
                cfg.existingFiles.forEach(function(ef) {
                    var ext   = ef.name.split('.').pop().toLowerCase();
                    var isImg = /^(jpg|jpeg|png|gif|webp|svg|bmp)$/.test(ext);
                    var meta  = _madFileIcons[ext] || { icon: 'file', color: 'var(--mad-bg-muted, #f3f4f6)' };
                    var url   = ef.url || ef.path;
                    self.files.push({
                        raw: null, name: ef.name, ext: ext,
                        isImage: isImg, isPdf: ext === 'pdf',
                        icon: meta.icon, color: meta.color,
                        thumb: isImg ? url : null,
                        sizeText: 'Arquivo existente',
                        existing: true, existingPath: ef.path, existingUrl: url,
                        previewType: isImg ? 'image' : (ext === 'pdf' ? 'pdf' : 'other'),
                    });
                    self.existingFiles.push({ path: ef.path });
                });
            }
        },
        addFiles(list) {
            var added = false;
            var left  = [];   // o que ficou de fora, com o motivo
            var over  = 0;    // quantos passaram do Máximo de arquivos
            var rules = { accept: cfg.accept, maxBytes: this.maxSize > 0 ? this.maxSize * 1024 : 0, serverMax: cfg.serverMax, stored: cfg.stored };
            Array.from(list).forEach(f => {
                var problem = _madUploadProblem(f, rules);
                if (problem) { left.push(problem); return; }
                if (this.maxFiles > 0 && this.files.length >= this.maxFiles) { over++; return; }
                var info = _madFileInfo(f);
                info.uid = _madUploadUid();
                this.files.push(info);
                added = true;
            });
            if (over > 0) {
                left.push((over === 1 ? '1 arquivo não foi incluído' : over + ' arquivos não foram incluídos')
                    + ': o campo aceita no máximo ' + this.maxFiles + (this.maxFiles === 1 ? ' arquivo.' : ' arquivos.'));
            }
            // Um aviso só, com tudo o que não entrou (antes saíam da lista calados).
            if (left.length) _madUploadWarn(left.join(' '));
            // O <input> sempre volta a ter só os arquivos aceitos — inclusive
            // quando nenhum entrou (a janela de escolha já tinha posto os recusados lá).
            this.syncInput();
            if (added) {
                this._fireMadChange();
            }
        },
        // Os arquivos NOVOS (ainda não gravados), na ordem em que vão no POST: o
        // <input type="file"> (syncInput) e os identificadores
        // (`__mad_new_files[campo][]`, no Blade) saem os dois daqui.
        get newFiles() {
            return this.files.filter(function(f) { return f && f.raw && !f.existing; });
        },
        syncInput() {
            if (typeof DataTransfer === 'undefined' || !this.$refs.fileInput) return;
            var dt = new DataTransfer();
            this.newFiles.forEach(function(f) { dt.items.add(f.raw); });
            this.$refs.fileInput.files = dt.files;
        },
        // O servidor gravou estes arquivos (op `files_saved` da resposta do
        // Salvar): deixam de ser "novos". Sem isto o campo continuava com o
        // arquivo no <input> e o mandava de novo a cada Salvar — o servidor
        // apagava a linha do envio anterior e criava outra, com outra chave.
        //
        // Casado pelo identificador, não pela posição: o arquivo que o usuário
        // tirou da lista enquanto o Salvar corria não volta (o próximo Salvar o
        // remove do servidor, porque não vai entre os mantidos), e o que ele
        // acrescentou nesse meio tempo continua novo.
        markSaved(saved) {
            var self = this, changed = false;
            (saved || []).forEach(function(s) {
                if (!s || !s.uid || !s.key) return;
                var f = self.files.find(function(x) { return x && x.uid === s.uid && !x.existing; });
                if (!f) return;
                // Sem endereço de download (arquivo gravado no banco): a prévia
                // continua saindo do arquivo que o navegador ainda tem.
                var local = (!s.url && f.raw && typeof URL !== 'undefined' && URL.createObjectURL) ? URL.createObjectURL(f.raw) : '';
                f.raw          = null;
                f.existing     = true;
                f.existingPath = s.key;
                f.existingUrl  = s.url || local;
                f.name         = s.name || f.name;
                f.sizeText     = 'Arquivo existente';
                self.existingFiles.push({ path: s.key });
                changed = true;
            });
            if (changed) this.syncInput();
        },
        _fireMadChange() {
            var action = cfg.madChangeAction;
            if (!action) return;
            var wrapper = this.$el.closest('[mad-component]');
            if (wrapper && typeof MadWire !== 'undefined') {
                MadWire.call(wrapper, action, [cfg.fieldName]);
            }
        },
        removeFile(i) {
            var f = this.files[i];
            if (f && f.existing) {
                // Remove da lista de existentes (não será enviado no hidden)
                this.existingFiles = this.existingFiles.filter(function(ef) {
                    return ef.path !== f.existingPath;
                });
            } else if (f && f.thumb) {
                URL.revokeObjectURL(f.thumb);
            }
            this.files.splice(i, 1);
            this.syncInput();
        },
        onDrop(e) {
            this.dragOver = false;
            var dt = e.dataTransfer;
            if (dt && dt.files) this.addFiles(dt.files);
        },
        previewFile(f) {
            var src;
            if (f.existing) {
                src = f.existingUrl || f.existingPath;
            } else if (f.isImage) {
                src = f.thumb;
            } else if (f.isPdf && f.raw) {
                src = URL.createObjectURL(f.raw);
            } else {
                src = null;
            }
            window.dispatchEvent(new CustomEvent('mad-file-preview', {
                detail: {
                    field: this.fieldName, src: src, name: f.name,
                    type: f.previewType,
                    downloadUrl: f.existing ? (f.existingUrl || '') : '',
                }
            }));
        },
    }));

    // ── madFilePreview (modal de preview de arquivo) ────────────────────
    Alpine.data('madFilePreview', (fieldName) => ({
        open:        false,
        src:         '',
        fname:       '',
        ftype:       '',
        downloadUrl: '',
        init() {
            var self = this;
            window.addEventListener('mad-file-preview', function(e) {
                if (e.detail.field === fieldName) {
                    self.src         = e.detail.src || '';
                    self.fname       = e.detail.name || '';
                    self.ftype       = e.detail.type || 'other';
                    self.downloadUrl = e.detail.downloadUrl || '';
                    self.open        = true;
                }
            });
        },
        close() {
            this.open = false;
            this.src  = '';
        },
    }));

    /* madSortList — TSortList */
    Alpine.data('madSortList', (cfg = {}) => ({
        init() {
            if (typeof Sortable === 'undefined') return;
            Sortable.create(this.$refs.list || this.$el, {
                animation:   150,
                handle:      '.mad-sort-handle',
                ghostClass:  'sortable-ghost',
                chosenClass: 'sortable-chosen',
            });
            if (typeof lucide !== 'undefined') lucide.createIcons({ nodes: [this.$refs.list || this.$el] });
        },
    }));

    /* madHtmlEditor — TinyMCE 5 self-hosted */
    Alpine.data('madHtmlEditor', (cfg = {}) => ({
        editor: null,
        init() {
            if (typeof tinymce === 'undefined') return;
            const textarea = this.$refs.textarea;
            const self     = this;

            const opts = {
                target:      textarea,
                menubar:     cfg.menubar === false ? false : cfg.menubar,
                toolbar:     cfg.toolbar,
                plugins:     cfg.plugins,
                statusbar:   cfg.statusbar !== false,
                height:      cfg.height || 300,
                placeholder: cfg.placeholder || '',
                readonly:    !!cfg.disabled,
                branding:    false,
                skin:        cfg.skin || 'oxide',
                content_css: cfg.content_css || 'default',
                toolbar_mode: 'sliding',
                paste_data_images: true,
                image_advtab:      true,
                table_default_attributes: { border: '1' },
                convert_urls: false,
                setup(ed) {
                    self.editor = ed;
                    ed.on('init', function () {
                        if (cfg.initVal) ed.setContent(cfg.initVal);
                        textarea.value = ed.getContent();
                    });
                    const sync = function () {
                        textarea.value = ed.getContent();
                        textarea.dispatchEvent(new Event('input',  { bubbles: true }));
                        textarea.dispatchEvent(new Event('change', { bubbles: true }));
                    };
                    ed.on('change keyup undo redo SetContent blur ExecCommand', sync);
                },
            };

            // Language pack (pt_BR default, en e nativo). pt_PT/es baixados em /langs
            if (cfg.locale && cfg.locale !== 'en') {
                opts.language     = cfg.locale;
                opts.language_url = (tinymce.baseURL || '') + '/langs/' + cfg.locale + '.js';
            }

            tinymce.init(opts);

            // Garantia extra no submit do form
            const form = this.$el.closest('form');
            if (form) form.addEventListener('submit', function () {
                if (self.editor) textarea.value = self.editor.getContent();
            });
        },
        destroy() {
            if (this.editor) {
                try { this.editor.remove(); } catch (_) {}
                this.editor = null;
            }
        },
    }));

});


/* ═══════════════════════════════════════════════════════════════════
   MAD SELECT — multi-search, unique-search, multi-entry
   ═══════════════════════════════════════════════════════════════════ */

function _madInitMultiSelects(root) {
    var scope = root || document;
    /* multi-search, unique-search, multi-entry — todos via MAD Select */
    scope.querySelectorAll('select[data-mad-multiselect], select[data-mad-uniquesearch], select[data-mad-multientry]').forEach(function (el) {
        _madCreateSelect(el);
    });
}

document.addEventListener('DOMContentLoaded', function () {
    _madWatchMaskedInputs();
    setTimeout(function () { _madEnergizeFields(document); }, 350);
});

// O arquivo pode entrar DEPOIS do DOMContentLoaded (injecao tardia, tema que
// carrega o bundle sob demanda). Nesse caso o listener acima nunca dispara e o
// observer nunca liga — entao arma tambem no caminho "documento ja pronto".
if (document.readyState !== 'loading') {
    _madWatchMaskedInputs();
    setTimeout(function () { _madEnergizeFields(document); }, 350);
}

/* ═══════════════════════════════════════════════════════════════════
   DB Search Selects — MAD Select com AJAX (dbunique-search / dbmulti-search)
   ═══════════════════════════════════════════════════════════════════ */
function _madInitDbSearchSelects(root) {
    var scope = root || document;
    /* dbunique-search + dbmulti-search — AJAX via MAD Select */
    scope.querySelectorAll('select[data-mad-dbsearch], select[data-mad-dbmultisearch]').forEach(function (el) {
        _madCreateSelect(el);
    });
    /* Fallback: scope específico sem matches → varre o document (drawers/modais
       appendados ao body fora do container do casco). Guard via .mad-select-init. */
    if (scope !== document) {
        var docUnique = document.querySelectorAll('select[data-mad-dbsearch]:not(.mad-select-init)');
        var docMulti  = document.querySelectorAll('select[data-mad-dbmultisearch]:not(.mad-select-init)');
        if (docUnique.length > 0 || docMulti.length > 0) _madInitDbSearchSelects(document);
    }
}
window._madInitDbSearchSelects = _madInitDbSearchSelects;

/* ═══════════════════════════════════════════════════════════════════
   DB Entry — autocomplete AJAX para input text (dbentry-field)
   ═══════════════════════════════════════════════════════════════════ */
function _madInitDbEntry(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-mad-dbentry]').forEach(function (el) {
        if (el._madDbEntry) return;
        el._madDbEntry = true;
        var token    = el.dataset.madDbentryToken || '';
        var minLen   = parseInt(el.dataset.minLength || '2') || 2;
        var _debounce = null;
        var dropdown  = null;

        function removeDropdown() {
            if (dropdown) { dropdown.remove(); dropdown = null; }
        }

        el.addEventListener('input', function () {
            var q = el.value.trim();
            if (q.length < minLen) { removeDropdown(); return; }
            clearTimeout(_debounce);
            _debounce = setTimeout(function () {
                fetch(_madServiceUrl('db-entry', 'onSearch', { token: token, q: q }))
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        _madOptionsError(el, data.error);
                        _renderDbEntryDropdown(el, data.items || []);
                    })
                    .catch(function () { removeDropdown(); });
            }, 300);
        });

        el.addEventListener('blur', function () {
            setTimeout(removeDropdown, 200);
        });

        function _renderDbEntryDropdown(input, items) {
            removeDropdown();
            if (!items.length) return;
            dropdown = document.createElement('div');
            dropdown.className = 'mad-ac-dropdown';
            dropdown.style.cssText = 'position:absolute;z-index:var(--mad-z-float,9999);background:var(--mad-surface,#fff);border:1px solid var(--mad-border,#e2e8f0);border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.1);max-height:200px;overflow-y:auto;min-width:' + input.offsetWidth + 'px;';
            var rect = input.getBoundingClientRect();
            dropdown.style.top  = (rect.bottom + window.scrollY + 2) + 'px';
            dropdown.style.left = (rect.left + window.scrollX) + 'px';

            items.forEach(function (item) {
                var div = document.createElement('div');
                div.className = 'mad-ac-item';
                div.style.cssText = 'padding:6px 10px;cursor:pointer;font-size:13px;';
                div.textContent = item;
                div.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    input.value = item;
                    input.dispatchEvent(new Event('input', { bubbles: true }));
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                    removeDropdown();
                });
                div.addEventListener('mouseenter', function () { div.style.background = 'var(--mad-hover,#f1f5f9)'; });
                div.addEventListener('mouseleave', function () { div.style.background = ''; });
                dropdown.appendChild(div);
            });

            document.body.appendChild(dropdown);
        }
    });
}
window._madInitDbEntry = _madInitDbEntry;

/* ═══════════════════════════════════════════════════════════════════
   Select Check — multi-select com checkboxes no dropdown + display condensado
   (mad-select-check-field / mad-dbselect-check-field)
   ═══════════════════════════════════════════════════════════════════ */
function _madInitSelectCheck(root) {
    var scope = root || document;
    /* select-check / dbselect-check — checkbox no dropdown + display condensado.
       O modo 'check' do MAD Select já renderiza checkboxes no dropdown e o
       summary condensado (summaryText/maxDisplay) no control. */
    scope.querySelectorAll('select[data-mad-selectcheck]').forEach(function (el) {
        _madCreateSelect(el);
    });
}
window._madInitSelectCheck = _madInitSelectCheck;

/* ═══════════════════════════════════════════════════════════════════
   CEP / CNPJ Fields — máscara + busca automática + preenchimento
   ═══════════════════════════════════════════════════════════════════

   Os componentes <mad-cep-field> e <mad-cnpj-field> declaram um mapa
   fill-fields no Blade. Aqui aplicamos máscara, disparamos busca no blur
   (ou via botão de lupa) e preenchemos os campos-alvo do form com a
   resposta do servidor. estado_id é setado ANTES de cidade_id para que
   combos com depends-on tenham tempo de recarregar.
   ─────────────────────────────────────────────────────────────── */

function _madMaskCep(digits) {
    digits = String(digits || '').replace(/\D/g, '').slice(0, 8);
    if (digits.length > 5) return digits.slice(0, 5) + '-' + digits.slice(5);
    return digits;
}

/**
 * Mascara de CNPJ — aceita o formato ALFANUMERICO da Receita (julho/2026):
 * 12 posicoes em [0-9A-Z] (raiz + ordem) + 2 digitos verificadores numericos.
 *
 * Antes fazia replace(/\D/g,''), o que apagava as letras conforme o usuario
 * digitava: um CNPJ novo era impossivel de preencher no campo.
 */
function _madMaskCnpj(value) {
    var raw = String(value || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase();
    // 12 primeiras alfanumericas; as 2 ultimas (DV) so aceitam digito.
    var digits = raw.slice(0, 12) + raw.slice(12, 14).replace(/\D/g, '');
    var out = digits;
    if (digits.length > 2)  out = digits.slice(0, 2) + '.' + digits.slice(2);
    if (digits.length > 5)  out = digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5);
    if (digits.length > 8)  out = digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/' + digits.slice(8);
    if (digits.length > 12) out = digits.slice(0, 2) + '.' + digits.slice(2, 5) + '.' + digits.slice(5, 8) + '/' + digits.slice(8, 12) + '-' + digits.slice(12);
    return out;
}

/**
 * @deprecated Bloco LEGADO de CEP/CNPJ (_madCepCnpjSetField/_madCepCnpjApplyValues/
 * _madCepCnpjLookup/_madInitCepField/_madInitCnpjField): nenhum blade atual emite
 * data-mad-cep-field — o caminho vivo é o Alpine.data madCepField/madCnpjField
 * (_madApiFieldFactory). NÃO remover sem tratar os call sites vivos de
 * _madInitCepField: mad-ui.js:2449 e :3455 (sem guard de typeof — remoção =
 * ReferenceError) e mad.js:566/744/1871 (com guard). Remoção segura = follow-up.
 *
 * Preenche um único campo-alvo do form pelo nome. Ignora silenciosamente
 * se o target não existir. Dispara input+change para notificar
 * Alpine, MAD Select e handlers de depends-on.
 */
function _madCepCnpjSetField(fieldName, val) {
    if (!fieldName) return;
    var el = document.querySelector('[name="' + fieldName + '"]');
    if (!el) return;

    // Select normal / MAD Select
    if (el.tagName === 'SELECT') {
        if (el._madSelect) {
            try { el._madSelect.setValue(val, true); } catch (e) {}
        } else {
            el.value = val != null ? String(val) : '';
        }
    } else {
        el.value = val != null ? String(val) : '';
        // Alvo numeric/money (CNPJ preenchendo capital social, CEP preenchendo
        // número): o `name` é o HIDDEN e o texto vem do Alpine — sem isto o
        // valor entra no POST e a tela não muda.
        _madSyncMaskedField(el);
    }

    // Notifica listeners (Alpine mad:model, depends-on, etc.)
    try { el.dispatchEvent(new Event('input',  { bubbles: true })); } catch (e) {}
    try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}
}

/**
 * Preenche todos os campos do mapa, com estado_id antes de cidade_id
 * pra não atropelar cascata de dbcombo depends-on. Espera 120ms entre
 * o set de um campo "pai" (*_id relacionado a estado) e o "filho"
 * (cidade_id) pra o combo filho ter tempo de recarregar.
 */
function _madCepCnpjApplyValues(values) {
    if (!values || typeof values !== 'object') return;

    var cityKeys   = [];
    var otherKeys  = [];

    Object.keys(values).forEach(function (k) {
        // Heurística simples — qualquer chave contendo "cidade" é adiada
        if (/cidade/i.test(k)) cityKeys.push(k);
        else otherKeys.push(k);
    });

    otherKeys.forEach(function (k) {
        _madCepCnpjSetField(k, values[k]);
    });

    if (cityKeys.length) {
        setTimeout(function () {
            cityKeys.forEach(function (k) {
                _madCepCnpjSetField(k, values[k]);
            });
        }, 150);
    }
}

function _madCepCnpjSetLoading(btn, isLoading) {
    if (!btn) return;
    if (isLoading) {
        btn.disabled = true;
        btn._madOrigHtml = btn.innerHTML;
        btn.innerHTML = '<i data-lucide="loader-2" class="mad-spin"></i>';
    } else {
        btn.disabled = false;
        if (btn._madOrigHtml) btn.innerHTML = btn._madOrigHtml;
    }
    if (window.lucide && window.lucide.createIcons) {
        try { window.lucide.createIcons(); } catch (e) {}
    }
}

function _madCepCnpjLookup(input, endpoint, btn) {
    var token = input.dataset.madCepToken || input.dataset.madCnpjToken || '';
    // CNPJ pode ser ALFANUMERICO (Receita, julho/2026) — tirar nao-digitos
    // mandaria um valor mutilado pro servidor. CEP segue numerico.
    var raw   = endpoint === 'cnpj'
        ? String(input.value || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase()
        : String(input.value || '').replace(/\D/g, '');

    _madCepCnpjSetLoading(btn, true);

    var body = new URLSearchParams({ token: token, value: raw });

    fetch(_madServiceUrl(endpoint, 'onSearch'), {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    body.toString()
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        _madCepCnpjSetLoading(btn, false);
        if (data && data.ok) {
            _madCepCnpjApplyValues(data.values || {});
            if (typeof madToast === 'function' && data.values && Object.keys(data.values).length) {
                madToast('Endereço preenchido!', 'success');
            }
        } else {
            var msg = (data && data.error) || 'Falha na consulta.';
            if (typeof madToast === 'function') madToast(msg, 'warning');
        }
    })
    .catch(function (err) {
        _madCepCnpjSetLoading(btn, false);
        if (typeof madToast === 'function') madToast('Falha na comunicação com o servidor.', 'danger');
    });
}

function _madInitCepField(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-mad-cep-field]').forEach(function (el) {
        if (el._madCepInit) return;
        el._madCepInit = true;

        var btn = el.parentElement ? el.parentElement.querySelector('[data-mad-cep-trigger]') : null;

        // Máscara
        el.addEventListener('input', function () {
            var pos = el.selectionStart;
            var oldLen = el.value.length;
            el.value = _madMaskCep(el.value);
            var newLen = el.value.length;
            try { el.setSelectionRange(pos + (newLen - oldLen), pos + (newLen - oldLen)); } catch (e) {}
        });

        // Auto no blur
        el.addEventListener('blur', function () {
            if (el.dataset.madCepAuto !== '1') return;
            var digits = (el.value || '').replace(/\D/g, '');
            if (digits.length === 8) {
                _madCepCnpjLookup(el, 'cep', btn);
            }
        });

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                var digits = (el.value || '').replace(/\D/g, '');
                if (digits.length !== 8) {
                    if (typeof madToast === 'function') madToast('Informe um CEP válido (8 dígitos).', 'warning');
                    el.focus();
                    return;
                }
                _madCepCnpjLookup(el, 'cep', btn);
            });
        }
    });
}
window._madInitCepField = _madInitCepField;

function _madInitCnpjField(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-mad-cnpj-field]').forEach(function (el) {
        if (el._madCnpjInit) return;
        el._madCnpjInit = true;

        var btn = el.parentElement ? el.parentElement.querySelector('[data-mad-cnpj-trigger]') : null;

        // Máscara
        el.addEventListener('input', function () {
            el.value = _madMaskCnpj(el.value);
        });

        // Auto no blur. Conta caracteres ALFANUMERICOS: o CNPJ da Receita a
        // partir de julho/2026 tem letras nas 12 primeiras posicoes, e contar
        // digitos nunca chegaria a 14 (o lookup jamais disparava).
        function _cleanCnpj(v) {
            return String(v || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase();
        }

        el.addEventListener('blur', function () {
            if (el.dataset.madCnpjAuto !== '1') return;
            if (_cleanCnpj(el.value).length === 14) {
                _madCepCnpjLookup(el, 'cnpj', btn);
            }
        });

        if (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                if (_cleanCnpj(el.value).length !== 14) {
                    if (typeof madToast === 'function') madToast('Informe um CNPJ completo (14 caracteres).', 'warning');
                    el.focus();
                    return;
                }
                _madCepCnpjLookup(el, 'cnpj', btn);
            });
        }
    });
}
window._madInitCnpjField = _madInitCnpjField;

/* ═══════════════════════════════════════════════════════════════════
   Mascaras genericas — <mad-input-field mask="...">
   ───────────────────────────────────────────────────────────────────
   Aliases prontos: cpf, cnpj, cpfcnpj, cep, phone/tel/telefone/celular,
   date, time, datetime, placa, rg.

   Ou pattern literal:
     9 = digito, A = letra (uppercase), * = alfanumerico, demais = literal
     Ex: "999.999.999-99", "(99) 99999-9999", "AAA-9999"
   ─────────────────────────────────────────────────────────────── */

var _MAD_MASK_PATTERNS = {
    cpf:   '999.999.999-99',
    // CNPJ: 12 posicoes ALFANUMERICAS (token X) + 2 digitos verificadores
    // numericos. Formato da Receita a partir de julho/2026 — o pattern antigo
    // ('99.999.999/9999-99') apagava as letras enquanto o usuario digitava.
    cnpj:  'XX.XXX.XXX/XXXX-99',
    cep:   '99999-999',
    date:  '99/99/9999',
    time:  '99:99',
    datetime: '99/99/9999 99:99',
    placa: 'AAA-9999',
    rg:    '99.999.999-9'
};

/**
 * Aplica pattern com tokens 9/A/* a um valor cru. Caracteres do
 * pattern fora desses tokens sao literais e injetados automaticamente.
 */
function _madApplyMaskPattern(value, pattern) {
    var raw = String(value == null ? '' : value);
    // Mantem apenas caracteres relevantes (digitos + letras) — descarta acentos/espacos
    var clean = raw.replace(/[^0-9A-Za-z]/g, '');
    var out = '';
    var ci = 0;
    for (var i = 0; i < pattern.length; i++) {
        if (ci >= clean.length) break;
        var p = pattern.charAt(i);
        var c = clean.charAt(ci);
        if (p === '9') {
            if (/[0-9]/.test(c)) { out += c; ci++; }
            else { ci++; i--; }                          // pula char invalido, repete pattern
        } else if (p === 'A') {
            if (/[A-Za-z]/.test(c)) { out += c.toUpperCase(); ci++; }
            else { ci++; i--; }
        } else if (p === 'X') {
            // Alfanumerico (letra OU digito), sempre em maiuscula. Existe pelo
            // CNPJ alfanumerico da Receita (julho/2026), cujas 12 primeiras
            // posicoes aceitam [0-9A-Z] — o token 'A' recusaria os digitos e o
            // '9' recusaria as letras.
            if (/[0-9A-Za-z]/.test(c)) { out += c.toUpperCase(); ci++; }
            else { ci++; i--; }
        } else if (p === '*') {
            out += c;
            ci++;
        } else {
            // literal — injeta automaticamente
            out += p;
        }
    }
    return out;
}

/**
 * Resolve alias (estatico ou dinamico) para um pattern concreto
 * baseado na quantidade de digitos atual do input.
 */
function _madResolveMaskPattern(maskAttr, currentValue) {
    if (!maskAttr) return '';
    var key = String(maskAttr).toLowerCase().trim();
    var digits = String(currentValue || '').replace(/\D/g, '');
    var alnum  = String(currentValue || '').replace(/[^0-9A-Za-z]/g, '');

    // Aliases dinamicos
    if (key === 'cpfcnpj') {
        // Decide por caracteres ALFANUMERICOS, nao digitos: num CNPJ
        // alfanumerico (julho/2026) a contagem de digitos fica abaixo de 11 e
        // o resolver escolhia a mascara de CPF, travando o campo em 11 chars.
        // Qualquer letra presente ja descarta CPF (que e sempre numerico).
        if (alnum.length !== digits.length) return _MAD_MASK_PATTERNS.cnpj;
        return alnum.length <= 11 ? _MAD_MASK_PATTERNS.cpf : _MAD_MASK_PATTERNS.cnpj;
    }
    if (key === 'phone' || key === 'tel' || key === 'telefone' || key === 'celular') {
        return digits.length <= 10 ? '(99) 9999-9999' : '(99) 99999-9999';
    }

    // Aliases estaticos
    if (_MAD_MASK_PATTERNS[key]) return _MAD_MASK_PATTERNS[key];

    // Pattern literal (passou direto)
    return String(maskAttr);
}

/**
 * Conta separadores literais em um pattern ate uma posicao do output.
 * Usado para reposicionar o cursor apos re-mascarar.
 */
function _madMaskedCursor(pattern, rawCharsBeforeCursor) {
    var pos = 0, taken = 0;
    while (pos < pattern.length && taken < rawCharsBeforeCursor) {
        var p = pattern.charAt(pos);
        // 'X' entra junto com 9/A/*: e token consumidor de caractere desde o
        // CNPJ alfanumerico. Sem ele o cursor pulava pro fim do CNPJ a cada
        // tecla (as 12 primeiras posicoes contavam como literal).
        if (p === '9' || p === 'A' || p === 'X' || p === '*') { taken++; }
        pos++;
    }
    return pos;
}

// Exports pro mad-sheet.js (cellSetMasked): células virtualizadas nascem
// depois do DOMContentLoaded — o init global de mask não as cobre, então a
// planilha aplica mask/cursor/force-case no próprio handler @input.
window._madApplyMaskPattern   = _madApplyMaskPattern;
window._madResolveMaskPattern = _madResolveMaskPattern;
window._madMaskedCursor       = _madMaskedCursor;

/* Reavisa o Alpine depois de reescrever el.value.
   ───────────────────────────────────────────────────────────────────
   Mascara e force-case escrevem em el.value DIRETO, sem evento. Num
   <mad-input-field> isso basta (o POST le o input). Dentro de uma linha de
   <mad-field-list> o input tem x-model: o listener do Alpine ja rodou com o
   valor CRU, entao a linha guardaria "12345678901" enquanto a tela mostra
   "123.456.789-01" — e quem le a linha (compute, on-change, o save) veria o
   valor errado. Re-disparar 'input' sincroniza; a segunda passada e no-op
   porque formatted === oldVal, e o flag corta qualquer reentrancia. */
function _madSyncAlpineModel(el) {
    if (!el || !el.hasAttribute || !el.hasAttribute('x-model')) return;
    if (el._madSyncingModel) return;
    el._madSyncingModel = true;
    try { el.dispatchEvent(new Event('input', { bubbles: true })); }
    catch (e) {}
    el._madSyncingModel = false;
}
window._madSyncAlpineModel = _madSyncAlpineModel;

function _madInitMaskedInput(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-mad-mask]').forEach(function (el) {
        if (el._madMaskInit) return;
        el._madMaskInit = true;

        var alias = el.getAttribute('data-mad-mask');

        function reformat() {
            var pattern = _madResolveMaskPattern(alias, el.value);
            // Trunca cru pelo pattern para impedir excesso (especialmente aliases dinamicos).
            // A classe TEM que incluir 'X': o pattern do CNPJ e 'XX.XXX.XXX/XXXX-99',
            // entao contar so [9A*] dava maxRaw=2 e o 12o digito do `cpfcnpj` (o que
            // vira CNPJ) truncava o valor inteiro para 2 caracteres — o campo parecia
            // ter sido LIMPO no meio da digitacao.
            var clean = String(el.value || '').replace(/[^0-9A-Za-z]/g, '');
            var maxRaw = (pattern.match(/[9AX*]/g) || []).length;
            if (clean.length > maxRaw) clean = clean.slice(0, maxRaw);
            return _madApplyMaskPattern(clean, pattern);
        }

        el.addEventListener('input', function () {
            var oldVal  = el.value;
            var oldPos  = el.selectionStart || 0;
            // Conta digitos/letras antes do cursor para reposicionar depois
            var rawBefore = oldVal.slice(0, oldPos).replace(/[^0-9A-Za-z]/g, '').length;

            var formatted = reformat();
            if (formatted === oldVal) return;
            el.value = formatted;

            var pattern = _madResolveMaskPattern(alias, formatted);
            var newPos  = _madMaskedCursor(pattern, rawBefore);
            try { el.setSelectionRange(newPos, newPos); } catch (e) {}
            _madSyncAlpineModel(el);
        });

        // Formata valor inicial (quando vem populado do server)
        if (el.value) {
            var initFormatted = reformat();
            if (initFormatted !== el.value) {
                el.value = initFormatted;
                _madSyncAlpineModel(el);
            }
        }
    });
}
window._madInitMaskedInput = _madInitMaskedInput;

/* ═══════════════════════════════════════════════════════════════════
   Force case — <mad-input-field force-case="upper|lower|title">
   ───────────────────────────────────────────────────────────────────
   Aplica transformacao de caixa enquanto usuario digita. Roda no
   evento `input`, depois da mascara (mascara registra listener antes
   nas chamadas de init), entao o valor mascarado ja e lido.
   ─────────────────────────────────────────────────────────────── */
function _madApplyForceCase(value, mode) {
    if (mode === 'upper') return String(value || '').toUpperCase();
    if (mode === 'lower') return String(value || '').toLowerCase();
    if (mode === 'title') {
        return String(value || '').toLowerCase().replace(/(^|\s|[\.\-\/])([\p{L}])/gu, function (_, s, c) {
            return s + c.toUpperCase();
        });
    }
    return value;
}
// Export pro mad-sheet.js (cellSetMasked) — ver nota nos mask helpers acima.
window._madApplyForceCase = _madApplyForceCase;

function _madInitForceCase(root) {
    var scope = root || document;
    scope.querySelectorAll('input[data-mad-force]').forEach(function (el) {
        if (el._madForceInit) return;
        el._madForceInit = true;
        var mode = (el.getAttribute('data-mad-force') || '').toLowerCase();
        if (!mode) return;

        el.addEventListener('input', function () {
            var oldVal = el.value;
            var pos    = el.selectionStart;
            var newVal = _madApplyForceCase(oldVal, mode);
            if (newVal === oldVal) return;
            el.value = newVal;
            try { el.setSelectionRange(pos, pos); } catch (e) {}
            _madSyncAlpineModel(el);
        });

        // Aplica no valor inicial
        if (el.value) {
            var initVal = _madApplyForceCase(el.value, mode);
            if (initVal !== el.value) {
                el.value = initVal;
                _madSyncAlpineModel(el);
            }
        }
    });
}
window._madInitForceCase = _madInitForceCase;

/* ═══════════════════════════════════════════════════════════════════
   _madEnergizeFields — ponto UNICO de (re)init dos campos com JS
   ───────────────────────────────────────────────────────────────────
   Todo componente de campo que depende de listener registrado por
   varredura do DOM (select MAD, db-search, select-check, mascara,
   force-case) precisa ser re-inicializado sempre que HTML novo entra
   na pagina: drawer, modal, aba do MadTabs, troca de innerHTML,
   morph do MadWire, popover teleportado.

   Ate 5.40 cada um desses lugares tinha a SUA copia da sequencia de
   init — seis listas em cinco arquivos. Elas divergiram: quando a
   mascara (`mask="phone"`) e o `force-case` ganharam init proprio,
   so o DOMContentLoaded e o popover foram atualizados. Resultado: o
   campo mascarado funcionava ao abrir a URL direto e ficava MORTO ao
   abrir o mesmo form pelo drawer da listagem — o sintoma que ninguem
   liga a "falta uma linha numa das copias".

   Por isso a sequencia mora aqui e os seis lugares chamam esta funcao.
   Init novo de campo entra AQUI e vale em todos os caminhos de uma vez.

   Ordem importa: a mascara registra o listener de `input` ANTES do
   force-case, para que o force-case leia o valor ja mascarado.
   Cada init e idempotente (flag `_madXInit` / `.mad-select-init`),
   entao chamar de novo sobre DOM ja inicializado e no-op.
   ─────────────────────────────────────────────────────────────── */
function _madEnergizeFields(root) {
    var scope = root || document;
    if (typeof _madInitSelects === 'function')         _madInitSelects(scope);
    if (typeof _madInitMultiSelects === 'function')    _madInitMultiSelects(scope);
    if (typeof _madInitDbSearchSelects === 'function') _madInitDbSearchSelects(scope);
    if (typeof _madInitDbEntry === 'function')         _madInitDbEntry(scope);
    if (typeof _madInitSelectCheck === 'function')     _madInitSelectCheck(scope);
    if (typeof _madInitCepField === 'function')        _madInitCepField(scope);
    if (typeof _madInitCnpjField === 'function')       _madInitCnpjField(scope);
    if (typeof _madInitMaskedInput === 'function')     _madInitMaskedInput(scope);
    if (typeof _madInitForceCase === 'function')       _madInitForceCase(scope);
}
window._madEnergizeFields = _madEnergizeFields;

/* ═══════════════════════════════════════════════════════════════════
   _madWatchMaskedInputs — mascara/force-case param de depender do caller
   ───────────────────────────────────────────────────────────────────
   `_madEnergizeFields` conserta os caminhos de injecao CONHECIDOS. Mas
   quem injeta HTML no MAD nao e uma lista fechada: wrapper de drawer,
   aba, morph do MadWire, `MadResponse->html()`, `x-html` do Alpine,
   `innerHTML` de codigo do proprio app. Basta UM caminho esquecer a
   chamada e o campo volta a nascer sem mascara — sem erro, sem log, so
   um input que aceita `11987654321` cru.

   Mascara e force-case sao os unicos inits em que isso e barato de
   blindar: eles so PENDURAM listener e reescrevem `el.value` (property,
   nao atributo), entao re-processar nao muda o DOM e o observer nao se
   realimenta. Os demais inits (`_madCreateSelect` e cia.) CRIAM
   elementos — observa-los daria loop, e por isso ficam de fora daqui e
   continuam pelo `_madEnergizeFields`.

   Custo: um scan `input[data-mad-mask]` por frame em que nasceu no,
   coalescido via rAF. O guard `_madMaskInit` faz o segundo scan sobre o
   mesmo input ser no-op.
   ─────────────────────────────────────────────────────────────── */
function _madWatchMaskedInputs() {
    if (window._madMaskObserver || typeof MutationObserver !== 'function') return;

    var queued = false;
    function flush() {
        queued = false;
        _madInitMaskedInput(document);
        _madInitForceCase(document);
    }

    var obs = new MutationObserver(function (records) {
        if (queued) return;
        for (var i = 0; i < records.length; i++) {
            if (records[i].addedNodes && records[i].addedNodes.length) {
                queued = true;
                break;
            }
        }
        if (!queued) return;
        if (typeof requestAnimationFrame === 'function') requestAnimationFrame(flush);
        else setTimeout(flush, 16);
    });

    obs.observe(document.documentElement, { childList: true, subtree: true });
    window._madMaskObserver = obs;
}
window._madWatchMaskedInputs = _madWatchMaskedInputs;

/* ═══════════════════════════════════════════════════════════════════
   _madExtractJson — JSON parse tolerante a contaminacao HTML
   ───────────────────────────────────────────────────────────────────
   O runtime legado as vezes prefixa/sufixa a resposta de static calls com
   tags <script> de JS carregado pela pagina ou mensagens de permissao.
   Esta funcao tenta parsear o texto bruto primeiro; se falhar, busca
   o primeiro '{' e tenta balancear as chaves ate achar um objeto valido.
   Usada por CEP/CNPJ/DbCombo cascade.
   ─────────────────────────────────────────────────────────────── */
function _madExtractJson(text) {
    if (!text) return null;
    try { return JSON.parse(text); } catch (e) {}
    var start = text.indexOf('{');
    while (start !== -1) {
        var depth = 0, inStr = false, esc = false;
        for (var i = start; i < text.length; i++) {
            var ch = text[i];
            if (inStr) {
                if (esc)        { esc = false; continue; }
                if (ch === '\\'){ esc = true;  continue; }
                if (ch === '"') { inStr = false; }
                continue;
            }
            if (ch === '"') { inStr = true; continue; }
            if (ch === '{') depth++;
            else if (ch === '}') {
                depth--;
                if (depth === 0) {
                    var candidate = text.substring(start, i + 1);
                    try { return JSON.parse(candidate); } catch (e) {}
                    break;
                }
            }
        }
        start = text.indexOf('{', start + 1);
    }
    return null;
}
window._madExtractJson = _madExtractJson;

/* ═══════════════════════════════════════════════════════════════════
   Alpine components: madCepField / madCnpjField
   ───────────────────────────────────────────────────────────────────

   Registrados como Alpine.data para que o init rode automaticamente
   sempre que Alpine.initTree scan o DOM (primeira carga, navegacao
   Mad.go, Mad.overlay, carga de HTML legado, _reinit, etc.) — sem
   depender dos hooks de init externos.

   O template <mad-cep-field> / <mad-cnpj-field> declara:
     x-data="madCepField({ token, fillFields, auto })"
   e os handlers sao conectados dentro de init().
   ─────────────────────────────────────────────────────────────── */
document.addEventListener('alpine:init', () => {

    function _madApiFieldFactory(opts) {
        return (cfg = {}) => ({
            _token:      cfg.token || '',
            _auto:       cfg.auto !== false,
            _endpoint:   opts.endpoint,
            _maxDigits:  opts.maxDigits,
            _mask:       opts.mask,
            // Extrai do input o valor que vai pro servidor e que conta pro
            // comprimento. CEP e digitos; CNPJ e ALFANUMERICO desde
            // julho/2026, e um replace(/\D/g,'') o mutilaria.
            _clean:      opts.clean || function (v) { return String(v || '').replace(/\D/g, ''); },
            _lengthMsg:  opts.lengthMsg || ('Informe ' + opts.maxDigits + ' digitos.'),
            // name -> valor que ESTA consulta escreveu no campo (ver _setField).
            _filled:     {},

            init() {
                const root  = this.$el;
                const input = root.querySelector('input[name]');
                const btn   = root.querySelector('[data-mad-api-trigger]');
                if (!input) return;

                const self = this;

                // Valor vindo do servidor: com `strip-mask` o banco guarda só
                // os dígitos ('01310100'), e a edição abria o campo sem a
                // máscara. Formata na abertura, como o <mad-input-field> faz.
                // Sem evento de change — não dispara a busca automática.
                if (input.value) {
                    var initMasked = self._mask(input.value);
                    if (initMasked !== input.value) {
                        input.value = initMasked;
                        _madSyncAlpineModel(input);
                    }
                }

                // Mascara on input
                input.addEventListener('input', function () {
                    var pos = input.selectionStart;
                    var oldLen = input.value.length;
                    input.value = self._mask(input.value);
                    var newLen = input.value.length;
                    try {
                        var off = newLen - oldLen;
                        input.setSelectionRange(pos + off, pos + off);
                    } catch (e) {}
                });

                // Auto no blur + change (change captura autocomplete do browser)
                var _lastLookedUp = '';
                function _tryAutoLookup() {
                    if (!self._auto) return;
                    var clean = self._clean(input.value);
                    if (clean.length === self._maxDigits && clean !== _lastLookedUp) {
                        _lastLookedUp = clean;
                        self._lookup(input, btn);
                    }
                }
                input.addEventListener('blur', _tryAutoLookup);
                input.addEventListener('change', _tryAutoLookup);

                // Botao manual
                if (btn) {
                    btn.addEventListener('click', function (e) {
                        e.preventDefault();
                        var clean = self._clean(input.value);
                        if (clean.length !== self._maxDigits) {
                            if (typeof madToast === 'function') {
                                madToast(self._lengthMsg, 'warning');
                            }
                            input.focus();
                            return;
                        }
                        self._lookup(input, btn);
                    });
                }
            },

            _setLoading(btn, isLoading) {
                if (!btn) return;
                if (isLoading) {
                    btn.disabled = true;
                    btn._madOrigHtml = btn.innerHTML;
                    btn.innerHTML = '<i data-lucide="loader-2" class="mad-spin" style="width:18px;height:18px;"></i>';
                } else {
                    btn.disabled = false;
                    if (btn._madOrigHtml) btn.innerHTML = btn._madOrigHtml;
                }
                if (window.lucide && window.lucide.createIcons) {
                    try { window.lucide.createIcons(); } catch (e) {}
                }
            },

            _setField(fieldName, val, label) {
                if (!fieldName) return;
                // Escopo: mesmo chain do cascade de dbcombo (data-mad-depends) —
                // com dois forms na mesma pagina (mestre + drawer de detalhe,
                // ambos com cidade_id), o querySelector global acertava o
                // primeiro do documento, nao o do form deste campo.
                var scope = (this.$el && (this.$el.closest('[mad-component]') || this.$el.closest('form'))) || document;
                var el = scope.querySelector('[name="' + fieldName + '"]');
                if (!el && scope !== document) el = document.querySelector('[name="' + fieldName + '"]');
                if (!el) return;

                var sv = (val === null || val === undefined) ? '' : String(val);
                if (this._keepTyped(el, fieldName, sv)) return;   // '' não apaga o digitado

                // Mesmo valor que já está no campo: não regrava nem dispara
                // input/change. O CNPJ preenche o CEP, o CEP faz a própria busca
                // e devolve o MESMO estado — o change do estado recarregava a
                // cidade pelo depends-on, que piscava "Selecione..." até a
                // cidade ser escrita de novo.
                if (sv !== '' && String(el.value == null ? '' : el.value) === sv) {
                    this._rememberFill(el, fieldName, sv);
                    return;
                }

                if (el.tagName === 'SELECT') {
                    // Rotulo vindo do servidor (`_labels`): a cidade recem
                    // criada — ou qualquer id fora da lista carregada com a
                    // pagina — nao tem <option>. Sem injetar, o widget cai no
                    // proprio valor e a tela mostra o ID no lugar do nome; no
                    // <select> nativo o value nem "pega" (fica na 1a opcao).
                    if (label && sv !== '') {
                        if (el._madSelect && el._madSelect.addOption) {
                            try { el._madSelect.addOption({ value: sv, text: label }); } catch (e) {}
                        } else if (!el.querySelector('option[value="' + (window.CSS && CSS.escape ? CSS.escape(sv) : sv) + '"]')) {
                            try {
                                var opt = document.createElement('option');
                                opt.value = sv; opt.textContent = label;
                                opt.setAttribute('data-mad-synthetic', '');
                                el.appendChild(opt);
                            } catch (e) {}
                        }
                    }

                    if (el._madSelect) {
                        try { el._madSelect.setValue(val, true); } catch (e) {}
                    } else {
                        el.value = sv;
                    }
                } else {
                    el.value = sv;
                    // Alvo numeric/money (ex.: capital social vindo do CNPJ):
                    // o `name` é o HIDDEN, o texto vem do Alpine.
                    _madSyncMaskedField(el);
                }

                try { el.dispatchEvent(new Event('input',  { bubbles: true })); } catch (e) {}
                try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch (e) {}

                this._rememberFill(el, fieldName, sv);
            },

            /**
             * O servidor manda TODAS as chaves do fill-fields, com '' no que o
             * fornecedor nao tem (o CEP nao traz numero/complemento; o CNPJ pode
             * vir sem telefone). Gravar esse '' apagava o que o usuario ja tinha
             * digitado. Vazio so limpa o que a consulta ANTERIOR deste campo
             * escreveu (trocar de CEP nao deixa a rua do CEP velho na tela); o
             * resto fica como esta. true = nao mexer no campo.
             */
            _keepTyped(el, fieldName, sv) {
                if (sv !== '') return false;
                var cur = el.value == null ? '' : String(el.value);
                if (cur === '') return true;
                var filled = this._filled || {};
                return !Object.prototype.hasOwnProperty.call(filled, fieldName) || filled[fieldName] !== cur;
            },

            /**
             * Guarda o que ESTA consulta escreveu no campo. Lido DEPOIS dos
             * eventos: a mascara (telefone, CEP) reformata no `input` — e e o
             * valor formatado que vai estar la na proxima consulta.
             */
            _rememberFill(el, fieldName, sv) {
                var filled = this._filled || (this._filled = {});
                if (sv === '') delete filled[fieldName];
                else filled[fieldName] = el.value == null ? '' : String(el.value);
            },

            _applyValues(values, cascade, labels) {
                if (!values || typeof values !== 'object') return;
                const self = this;
                const lbl  = (labels && typeof labels === 'object') ? labels : {};

                // Campos que dependem do cascade estado->cidade (o combo de
                // cidade recarrega quando estado_id muda): setados com delay.
                // O servidor manda a lista exata em `_cascade` (derivada do
                // city-target do resolve) — cobre targets customizados tipo
                // municipio_id. Fallback: heuristica /cidade/i sobre o name
                // (servidores antigos sem _cascade).
                const cascadeSet = {};
                if (Array.isArray(cascade)) {
                    cascade.forEach(function (k) { cascadeSet[String(k)] = true; });
                }
                const useServerList = Array.isArray(cascade);

                const cityKeys  = [];
                const otherKeys = [];
                Object.keys(values).forEach(function (k) {
                    var deferred = useServerList ? !!cascadeSet[k] : /cidade/i.test(k);
                    if (deferred) cityKeys.push(k);
                    else otherKeys.push(k);
                });

                otherKeys.forEach(function (k) { self._setField(k, values[k], lbl[k]); });

                if (cityKeys.length) {
                    setTimeout(function () {
                        cityKeys.forEach(function (k) { self._setField(k, values[k], lbl[k]); });
                    }, 150);
                }
            },

            _lookup(input, btn) {
                const self = this;
                const raw  = this._clean(input.value);

                this._setLoading(btn, true);

                const body = new URLSearchParams({ token: this._token, value: raw });

                fetch(_madServiceUrl(this._endpoint, 'onSearch'), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body:    body.toString()
                })
                .then(function (r) { return r.text(); })
                .then(function (text) {
                    return _madExtractJson(text) || { ok: false, error: 'Resposta invalida do servidor.' };
                })
                .then(function (data) {
                    self._setLoading(btn, false);
                    if (data && data.ok) {
                        self._applyValues(data.values || {}, data._cascade, data._labels);
                        if (typeof madToast === 'function' && data.values && Object.keys(data.values).length) {
                            madToast('Dados preenchidos!', 'success');
                        }
                    } else {
                        var msg = (data && data.error) || 'Falha na consulta.';
                        if (typeof madToast === 'function') madToast(msg, 'warning');
                    }
                })
                .catch(function () {
                    self._setLoading(btn, false);
                    if (typeof madToast === 'function') madToast('Falha na comunicacao com o servidor.', 'danger');
                });
            },
        });
    }

    Alpine.data('madCepField', _madApiFieldFactory({
        endpoint:  'cep',
        maxDigits: 8,
        mask:      _madMaskCep,
    }));

    Alpine.data('madCnpjField', _madApiFieldFactory({
        endpoint:  'cnpj',
        maxDigits: 14,
        mask:      _madMaskCnpj,
        // CNPJ alfanumerico (Receita, julho/2026): 12 posicoes [0-9A-Z] + 2 DV.
        clean:     function (v) { return String(v || '').replace(/[^0-9A-Za-z]/g, '').toUpperCase(); },
        lengthMsg: 'Informe um CNPJ completo (14 caracteres).',
    }));
});

madToast.info    = (message, title = '', position) => madToast({ message, type: 'info',    title, icon: 'ℹ️', position });

/* ═══════════════════════════════════════════════════════════════════
   madConfirm — caixa de confirmação nativa (Promise-based)
   ═══════════════════════════════════════════════════════════════════

   Uso:
     const ok = await madConfirm('Excluir este registro?');
     if (ok) { ... }

   ─────────────────────────────────────────────────────────────── */
/**
 * madDeletePopover — popover de confirmação de exclusão posicionado junto ao botão.
 *
 * Cria o popover no <body>, posiciona com fixed relativo ao botão,
 * e mostra com um delay de 50ms para evitar "pulo" visual.
 *
 * Uso no Alpine/Blade:
 *   @click="madDeletePopover($event, () => deleteRow(idx))"
 *
 * @param {Event}    event    Evento de click do botão
 * @param {Function} onConfirm Callback executado ao confirmar
 * @param {string}   [message] Mensagem (default: tradução de 'mad.remove_item')
 */
window.madDeletePopover = function(event, onConfirm, message) {
    // Fecha qualquer popover aberto
    var existing = document.querySelector('.mad-df-delete-popover');
    if (existing) { existing.remove(); }

    var btn = event.currentTarget || event.target;
    var rect = btn.getBoundingClientRect();
    var msg = message || (typeof __ === 'function' ? __('mad.remove_item') : 'Remover este item?');
    var lblDelete = typeof __ === 'function' ? __('mad.delete') : 'Excluir';
    var lblCancel = typeof __ === 'function' ? __('mad.cancel') : 'Cancelar';

    // Cria o popover
    var pop = document.createElement('div');
    pop.className = 'mad-df-delete-popover';
    pop.style.position = 'fixed';
    /* z-index vem de `.mad-df-delete-popover` (var(--mad-z-float)) — não crava
       inline aqui, senão o rebase de camada morre. */
    pop.style.top = (rect.bottom + 4) + 'px';
    pop.style.left = rect.right + 'px';
    pop.style.transform = 'translateX(-100%)';
    pop.style.opacity = '0';
    pop.innerHTML =
        '<p class="mad-df-delete-msg">' + msg + '</p>' +
        '<div class="mad-df-delete-btns">' +
        '  <button type="button" class="mad-btn mad-btn-destructive mad-btn-sm" data-action="confirm">' +
        '    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg> ' +
        '    ' + lblDelete +
        '  </button>' +
        '  <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" data-action="cancel">' + lblCancel + '</button>' +
        '</div>';

    document.body.appendChild(pop);

    // Mostra com delay para posicionar antes
    setTimeout(function() {
        pop.style.transition = 'opacity 150ms ease';
        pop.style.opacity = '1';
    }, 50);

    // Handlers
    function close() {
        pop.style.opacity = '0';
        setTimeout(function() { pop.remove(); }, 150);
        document.removeEventListener('click', outsideHandler, true);
    }

    pop.querySelector('[data-action="confirm"]').addEventListener('click', function() {
        close();
        if (typeof onConfirm === 'function') onConfirm();
    });

    pop.querySelector('[data-action="cancel"]').addEventListener('click', close);

    // Fecha ao clicar fora (com delay para não fechar no mesmo click)
    function outsideHandler(e) {
        if (!pop.contains(e.target) && !btn.contains(e.target)) {
            close();
        }
    }
    setTimeout(function() {
        document.addEventListener('click', outsideHandler, true);
    }, 100);
};

/**
 * madConfirm — Modal de confirmação nativa MAD.
 * Retorna Promise<boolean>.
 *
 * Uso:
 *   madConfirm('Tem certeza?').then(ok => { if (ok) ... });
 *   madConfirm('Excluir?', { danger: true, confirmText: 'Excluir' });
 */
window.madConfirm = function (message, options) {
    options = options || {};
    return new Promise(function(resolve) {
        var overlay = document.createElement('div');
        overlay.className = 'mad-confirm-overlay';
        // Herdar tema dark: copiar classes de tema do container .mad-ui mais proximo
        var themeEl = document.querySelector('.mad-ui') || document.body;
        (themeEl.className || '').split(/\s+/).forEach(function(c) {
            if (c.match(/^mad-(dark|theme-)/)) overlay.classList.add(c);
        });

        var modal = document.createElement('div');
        modal.className = 'mad-confirm-modal';

        var iconWrap = document.createElement('div');
        iconWrap.className = 'mad-confirm-icon' + (options.danger ? ' mad-confirm-icon-danger' : '');
        iconWrap.innerHTML = '<i data-lucide="' + (options.danger ? 'triangle-alert' : 'help-circle') + '"></i>';

        var msg = document.createElement('div');
        msg.className = 'mad-confirm-message';
        msg.textContent = message;

        var btns = document.createElement('div');
        btns.className = 'mad-confirm-btns';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'mad-btn mad-btn-secondary mad-btn-sm';
        cancelBtn.textContent = options.cancelText || 'Cancelar';

        var confirmBtn = document.createElement('button');
        confirmBtn.className = 'mad-btn mad-btn-sm ' + (options.danger ? 'mad-btn-danger' : 'mad-btn-primary');
        confirmBtn.textContent = options.confirmText || 'Confirmar';

        btns.appendChild(cancelBtn);
        btns.appendChild(confirmBtn);
        modal.appendChild(iconWrap);
        modal.appendChild(msg);
        modal.appendChild(btns);
        overlay.appendChild(modal);
        document.body.appendChild(overlay);

        if (window.lucide) lucide.createIcons({ nodes: [iconWrap] });
        requestAnimationFrame(function() { overlay.classList.add('mad-confirm-visible'); });

        function close(result) {
            overlay.classList.remove('mad-confirm-visible');
            setTimeout(function() { overlay.remove(); }, 150);
            document.removeEventListener('keydown', onEsc);
            resolve(result);
        }

        function onEsc(e) { if (e.key === 'Escape') close(false); }
        confirmBtn.onclick = function() { close(true); };
        cancelBtn.onclick = function() { close(false); };
        overlay.addEventListener('click', function(e) { if (e.target === overlay) close(false); });
        document.addEventListener('keydown', onEsc);
        confirmBtn.focus();
    });
};

/**
 * madConfirmPopover — Popover de confirmação posicionado no botão.
 * Retorna Promise<boolean>.
 *
 * Uso:
 *   madConfirmPopover(btnElement, 'Excluir?', { danger: true });
 */
window.madConfirmPopover = function (btn, message, options) {
    options = options || {};
    return new Promise(function(resolve) {
        var old = document.querySelector('.mad-confirm-popover');
        if (old) old.remove();

        var pop = document.createElement('div');
        pop.className = 'mad-confirm-popover';

        var msg = document.createElement('div');
        msg.className = 'mad-confirm-popover-msg';
        msg.textContent = message;

        var btns = document.createElement('div');
        btns.className = 'mad-confirm-popover-btns';

        var cancelBtn = document.createElement('button');
        cancelBtn.className = 'mad-btn mad-btn-ghost mad-btn-sm';
        cancelBtn.textContent = options.cancelText || 'Cancelar';

        var confirmBtn = document.createElement('button');
        confirmBtn.className = 'mad-btn mad-btn-sm ' + (options.danger ? 'mad-btn-danger' : 'mad-btn-primary');
        confirmBtn.textContent = options.confirmText || 'Confirmar';

        btns.appendChild(cancelBtn);
        btns.appendChild(confirmBtn);
        pop.appendChild(msg);
        pop.appendChild(btns);
        document.body.appendChild(pop);

        // Posicionar abaixo do botão
        var rect = btn.getBoundingClientRect();
        var popW = pop.offsetWidth;
        var left = rect.left + rect.width / 2 - popW / 2;
        if (left < 8) left = 8;
        if (left + popW > window.innerWidth - 8) left = window.innerWidth - 8 - popW;
        pop.style.top = (rect.bottom + 6) + 'px';
        pop.style.left = left + 'px';

        requestAnimationFrame(function() { pop.classList.add('mad-confirm-popover-visible'); });

        var closed = false;
        function close(result) {
            if (closed) return;
            closed = true;
            pop.classList.remove('mad-confirm-popover-visible');
            setTimeout(function() { pop.remove(); }, 150);
            document.removeEventListener('click', outside, true);
            document.removeEventListener('keydown', onEsc);
            resolve(result);
        }

        function outside(e) { if (!pop.contains(e.target) && !btn.contains(e.target)) close(false); }
        function onEsc(e) { if (e.key === 'Escape') close(false); }

        confirmBtn.onclick = function(e) { e.stopPropagation(); close(true); };
        cancelBtn.onclick = function(e) { e.stopPropagation(); close(false); };

        setTimeout(function() {
            document.addEventListener('click', outside, true);
            document.addEventListener('keydown', onEsc);
        }, 10);
    });
};

/* ═══════════════════════════════════════════════════════════════════
   madDataGrid — Alpine component para MadDataGrid
   ═══════════════════════════════════════════════════════════════════

   Registrado automaticamente; instanciado pelo data-grid.blade.php
   via x-data="madDataGrid()".

   ─────────────────────────────────────────────────────────────── */
// Edição inline da listagem (edit-mode="inline"): o campo ainda mostra o valor
// que o servidor desenhou? `defaultValue` é o atributo `value` do HTML (ou o
// texto do <textarea>). Campo sem esse atributo (cor, MAD Select) nunca conta
// como intocado — grava como sempre.
function _inlineUntouched(el) {
    if (!el || typeof el.value !== 'string') return false;
    const tag = String(el.tagName || '').toUpperCase();
    if (tag === 'TEXTAREA') return el.defaultValue === el.value;
    if (tag !== 'INPUT' || typeof el.hasAttribute !== 'function' || !el.hasAttribute('value')) return false;
    return el.defaultValue === el.value;
}

document.addEventListener('alpine:init', () => {
    Alpine.data('madDataGrid', (cfg = {}) => {
        // ── Inicializa colVisibility ANTES de retornar os dados ───────
        // Necessário: Alpine processa x-model antes do init() rodar.
        // Se as keys não existirem neste momento, x-model recebe undefined → unchecked.
        const _cols = cfg.cols || [];
        const _colVisibility = {};
        _cols.forEach(c => { _colVisibility[c.field] = true; });
        const _sk = cfg.storageKey || '';
        // `not-hideable`: fora do seletor — escolha antiga guardada no
        // navegador (de quando a coluna era ocultável) não a esconde mais;
        // sem a caixa no seletor ela ficaria escondida para sempre.
        const _fixed = new Set(_cols.filter(c => c.hideable === false).map(c => c.field));
        if (_sk) {
            try {
                const saved = localStorage.getItem('mad-dg-cols:' + _sk);
                if (saved) JSON.parse(saved).forEach(f => { if (!_fixed.has(f)) _colVisibility[f] = false; });
            } catch(e) {}
        }

        // ── <mad-col hide-below="N"> ──────────────────────────────────
        // O "Ocultar coluna quando a largura da tela estiver abaixo de" do
        // 4.0: a coluna some em tela (viewport) mais estreita que N px.
        // Calculado ANTES do 1º render, como o colVisibility — no celular a
        // coluna já nasce escondida. `max-width: N - 0.02px` é "menor que N"
        // mesmo em viewport de largura fracionada (a conta do Bootstrap).
        const _narrowQ   = {};   // N → [fields]
        const _colNarrow = {};
        const _narrowMq  = n => (typeof window !== 'undefined' && typeof window.matchMedia === 'function')
            ? window.matchMedia('(max-width: ' + (Number(n) - 0.02) + 'px)') : null;
        _cols.forEach(c => {
            const n = parseInt(c.hideBelow, 10);
            if (n > 0) (_narrowQ[n] = _narrowQ[n] || []).push(c.field);
        });
        Object.keys(_narrowQ).forEach(n => {
            const q = _narrowMq(n);
            const on = !!(q && q.matches);
            _narrowQ[n].forEach(f => { _colNarrow[f] = on; });
        });

        // ── Edição na célula × respostas do servidor (framework#175) ──
        // A resposta chega depois do usuário: ele confirma uma célula e já
        // reabre o editor (ou digita na vizinha) antes de o servidor
        // responder. Três coisas davam errado, sem aviso:
        //   1. o editor reaberto partia do valor ANTIGO que a linha ainda
        //      mostrava, e confirmar regravava o valor antigo;
        //   2. a resposta trocava a <tr> com o editor aberto: o navegador
        //      dispara `blur` no campo arrancado do documento, e o blur
        //      gravava o que estivesse lá (o valor antigo, ou meia digitação);
        //   3. o salvamento que não chegava ao servidor não avisava ninguém.
        // Fora do objeto reativo de propósito (não entram no render):
        //   _saves     célula → { n, value }: salvamentos sem resposta e o
        //              último valor confirmado (o editor reaberto parte dele);
        //   _rowSaves  linha (data-row-id) → salvamentos em andamento;
        //   _held      linha → op manage_row que chegou com um editor aberto
        //              nela; espera o editor fechar (holdRowOp/_releaseIdle);
        //   _edit      o editor aberto: linha, valor de partida e o campo.
        const _saves    = {};
        const _rowSaves = {};
        const _held     = {};
        const _edit     = { rowKey: null, from: null, input: null, moved: false };
        let   _root     = null;
        let   _onFocusOut = null;
        const _fieldKey = f => String(f).replace(/[^a-zA-Z0-9_]/g, '_');
        const _cellKey  = (rowId, field) => String(rowId) + '\u0001' + String(field);
        const _cssId    = v => (typeof window._madCssId === 'function')
            ? window._madCssId(v)
            : String(v).replace(/["\\\]]/g, '\\$&');
        const _str      = v => (v === null || v === undefined) ? '' : String(v);
        const _sameVal  = (a, b) => _str(a) === _str(b);

        return {
        // ── Inline editing ───────────────────────────────────────────
        editingCell:    null,
        editValue:      '',
        _wrapper:       null,
        _cfg:           cfg,

        // ── Busca rápida server-side ─────────────────────────────────
        search:         cfg.search || '',

        // ── Column chooser ───────────────────────────────────────────
        cols:           _cols,
        colVisibility:  _colVisibility,
        colChooserOpen: false,
        // Colunas escondidas AGORA pelo hide-below (field → true).
        colNarrow:      _colNarrow,

        // ── View mode (table / card) ────────────────────────────────
        viewMode: 'table',

        // ── Seleção de linhas (<mad-grid selectable>) ────────────────
        // Marcar/desmarcar é só local; o campo oculto `__mad_grid_sel[...]`
        // (x-bind no JSON de `selected`) leva a seleção em TODA requisição do
        // grid, e o servidor a devolve no re-render (cfg.selected) — é assim
        // que ela sobrevive à paginação, busca, filtro e ordenação.
        selectable:     !!cfg.selectable,
        selected:       (cfg.selected || []).map(String),
        _pageIds:       (cfg.pageIds || []).map(String),

        // ── Init ─────────────────────────────────────────────────────
        init(el) {
            _root = el || this.$el;
            this._wrapper = (el || this.$el).closest('[mad-component]');

            // edit-mode="inline": o foco saiu de uma linha que tinha resposta
            // guardada (ver holdRowOp) — agora ela pode ser redesenhada.
            if (_root && typeof _root.addEventListener === 'function') {
                _onFocusOut = () => { if (Object.keys(_held).length) setTimeout(() => this._releaseIdle(), 0); };
                _root.addEventListener('focusout', _onFocusOut);
            }

            if (this._cfg.sticky) {
                this.$nextTick(() => this._initStickyGroups(el || this.$el));
            }

            // Card view: restore from localStorage
            if (this._cfg.cardView) {
                var sk = this._cfg.storageKey;
                var saved = sk ? localStorage.getItem('mad-dg-view:' + sk) : null;
                this.viewMode = (saved === 'card' || saved === 'table') ? saved
                              : this._cfg.cardDefault ? 'card' : 'table';
            }

            // Busca rápida: debounce controlado via handleSearch (chamado pelo @input do template)
            this._searchTimer = null;

            // Inline editors com MAD Select/Pickr (modo inline = sempre visivel)
            this.$nextTick(() => this._initInlineEditors(el || this.$el));

            // Volta de formulário (?_mad_return=ID): destaca a linha salva.
            this.$nextTick(() => this._focusReturnRow(el || this.$el));

            // hide-below: acompanha a largura da tela.
            this._watchNarrow();
        },

        destroy() {
            if (this._narrowOff) this._narrowOff();
            if (_root && _onFocusOut && typeof _root.removeEventListener === 'function') {
                _root.removeEventListener('focusout', _onFocusOut);
            }
            // A grade saiu da tela: resposta guardada não tem mais onde entrar.
            Object.keys(_held).forEach(k => { delete _held[k]; });
        },

        // Um listener por limiar: só CRUZAR o limiar dispara (girar o celular,
        // redimensionar a janela) — arrastar a borda não re-renderiza a grid.
        _watchNarrow() {
            const offs = [];
            Object.keys(_narrowQ).forEach(n => {
                const q = _narrowMq(n);
                if (!q) return;
                const onChange = (e) => {
                    _narrowQ[n].forEach(f => { this.colNarrow[f] = !!e.matches; });
                    this._refreshStickyHead();
                };
                // Safari < 14 só tem addListener.
                if (typeof q.addEventListener === 'function') {
                    q.addEventListener('change', onChange);
                    offs.push(() => q.removeEventListener('change', onChange));
                } else if (typeof q.addListener === 'function') {
                    q.addListener(onChange);
                    offs.push(() => q.removeListener(onChange));
                }
            });
            this._narrowOff = offs.length ? () => offs.forEach(off => off()) : null;
        },

        // ── Volta de formulário: linha salva visível + URL limpa ─────
        // O servidor marca o wrapper com data-mad-focus-row (só no render da
        // volta — ver MadDataGrid::RETURN_PARAM). Mesmo destaque do manage_row.
        _focusReturnRow(root) {
            const key = root && root.getAttribute('data-mad-focus-row');
            if (!key) return;
            root.removeAttribute('data-mad-focus-row');

            // F5 / link copiado não repetem a volta (nem o destaque).
            try {
                const url = new URL(window.location.href);
                if (url.searchParams.has('_mad_return')) {
                    url.searchParams.delete('_mad_return');
                    history.replaceState(history.state, '', url.pathname + url.search + url.hash);
                }
            } catch (e) {}

            const sel = typeof window._madCssId === 'function' ? window._madCssId(key) : key;
            const targets = [
                [root.querySelector('tr[data-row-id="' + sel + '"]'), 'mad-dg-row-highlight'],
                [root.querySelector('[data-card-id="' + sel + '"]'), 'mad-dg-card-highlight-anim'],
            ];
            targets.forEach(([node, cls]) => {
                if (!node) return;
                node.classList.add(cls);
                setTimeout(() => node.classList.remove(cls), 2500);
                // Só a vista ativa (tabela OU cards) rola até a linha.
                if (node.offsetParent !== null) node.scrollIntoView({ block: 'center' });
            });
        },

        // ── Clique na linha (<mad-grid row-click>) ───────────────────
        // O "clique padrão" do 4.0: clicar na linha (ou no card) executa a
        // 1ª ação dela. Delegado na raiz do grid (@click do data-grid.blade),
        // então vale para linha trocada pelo manage_row e após re-render.
        // Não chama método nenhum: CLICA o botão da própria ação, e navegar,
        // abrir drawer, pedir confirmação e o bloqueio do perfil seguem
        // idênticos ao clique no ícone. Ação de exclusão nunca é o clique
        // padrão; a 1ª ação restante desabilitada = linha sem clique (não
        // pula para a seguinte, que o usuário não escolheu).
        onRowClick(e) {
            if (!this._cfg.rowClick || !e || e.defaultPrevented) return;
            if (e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            const t = e.target;
            if (!t || typeof t.closest !== 'function') return;
            // O que já é clicável na linha tem precedência: botões de ação e
            // de grupo, checkbox de seleção, links, campos, células editáveis.
            if (t.closest('a, button, input, select, textarea, label, summary, [contenteditable], [role="button"], '
                + '.mad-dg-actions-cell, .mad-dg-select-cell, .mad-dg-card-select, .mad-dg-card-footer, '
                + '.mad-dg-edit-inline, .mad-dg-edit-click, .mad-dg-edit-dblclick, [data-mad-no-row-click]')) return;
            if (this.editingCell) return;
            // Arrastar para selecionar/copiar o texto da célula não é clique.
            try {
                const sel = window.getSelection && window.getSelection();
                if (sel && !sel.isCollapsed && String(sel).trim() !== '') return;
            } catch (_) {}

            let item = t.closest('tr.mad-dg-row, tr.mad-dg-row-detail, .mad-dg-card');
            // Grid dentro de grid: cada um responde só pelas próprias linhas.
            if (!item || (e.currentTarget && item.closest('.mad-dg-wrap') !== e.currentTarget)) return;
            // 2ª linha descritiva (row-detail) responde pela linha dela.
            if (item.classList.contains('mad-dg-row-detail')) {
                const prev = item.previousElementSibling;
                if (!prev || prev.getAttribute('data-row-id') !== item.getAttribute('data-detail-for')) return;
                item = prev;
            }
            const btn = this._rowClickAction(item);
            if (btn) btn.click();
        },

        // Botão do clique padrão da linha/card: a 1ª ação que não é de
        // exclusão. Menus "..." (grupos) ficam dentro de .mad-dg-dropdown-wrap
        // e não entram. null = linha sem clique padrão.
        _rowClickAction(item) {
            const btns = item.querySelectorAll('.mad-dg-actions > button, .mad-dg-card-actions > button');
            for (const b of btns) {
                if (b.classList.contains('mad-dg-action-danger') || b.classList.contains('mad-dg-card-action-danger')) continue;
                return (b.disabled || b.getAttribute('aria-disabled') === 'true') ? null : b;
            }
            return null;
        },

        // ── Init de MAD Select/Pickr nas celulas editaveis ────────────
        // Chamado no init() e no startEdit() para garantir MAD Select em
        // <select data-mad-select> e <select data-mad-dbsearch> dentro
        // do grid (idempotente — _madInit* checa instancia existente).
        _initInlineEditors(scope) {
            scope = scope || this.$el;
            try {
                if (typeof window._madInitSelects === 'function') {
                    window._madInitSelects(scope);
                }
                if (typeof window._madInitDbSearchSelects === 'function') {
                    window._madInitDbSearchSelects(scope);
                }
                if (window.lucide) lucide.createIcons();
            } catch (e) {
                console.warn('[MadDataGrid] _initInlineEditors error', e);
            }
        },

        setViewMode(mode) {
            this.viewMode = mode;
            var sk = this._cfg.storageKey;
            if (sk) localStorage.setItem('mad-dg-view:' + sk, mode);
            this.$nextTick(function() {
                if (window.lucide) lucide.createIcons();
            });
        },

        // ── Column chooser methods ───────────────────────────────────
        // Fora da tela: escolha do usuário no seletor OU hide-below. É o gate
        // de cabeçalho, células, totais, quebras e campos do cartão.
        isColHidden(field) {
            return this.colVisibility[field] === false || this.colNarrow[field] === true;
        },

        // Escondida pelo hide-below (o seletor mostra a coluna desabilitada).
        isColNarrow(field) {
            return this.colNarrow[field] === true;
        },

        // Colunas na tela (colspan). `chosen` = só a escolha do seletor, sem o
        // hide-below — é o que a exportação leva: exportar pelo celular sai
        // com as mesmas colunas que no computador.
        visibleColCount(chosen) {
            const hidden = this.cols.filter(c => chosen
                ? this.colVisibility[c.field] === false
                : this.isColHidden(c.field)).length;
            return (this._cfg.colCount || this.cols.length) - hidden;
        },

        toggleCol(field) {
            this.colVisibility[field] = !this.colVisibility[field];
            const hidden = Object.keys(this.colVisibility).filter(f => !this.colVisibility[f]);
            const sk = this._cfg.storageKey || '';
            if (sk) localStorage.setItem('mad-dg-cols:' + sk, JSON.stringify(hidden));
            this._refreshStickyHead();
        },

        // Invalida o proxy do sticky header para forçar rebuild com as novas larguras
        _refreshStickyHead() {
            this.$nextTick(() => {
                const proxy = document.querySelector('.mad-dg-thead-proxy');
                if (proxy) {
                    proxy.dataset.src = '';
                    window.dispatchEvent(new Event('scroll'));
                }
            });
        },

        // ── Sticky group headers (JS porque overflow-x quebra CSS sticky) ─
        
        // Encontra o ancestral com scroll vertical
        _getScrollParent(el) {
            let p = el.parentElement;
            while (p && p !== document.documentElement) {
                const oy = getComputedStyle(p).overflowY;
                if ((oy === 'auto' || oy === 'scroll') && p.scrollHeight > p.clientHeight) {
                    return p;
                }
                p = p.parentElement;
            }
            return window;
        },

        _initStickyGroups(root) {
            const scrollParent = this._getScrollParent(root);
            let groupProxies = {};   // level → div
            let theadProxy   = null;
            let ticking      = false;

            const setupProxyFilters = (tp, thead) => {
                tp.querySelectorAll('.mad-dg-filter-wrap[data-filter-field]').forEach((proxyWrap) => {
                    const proxyBtn = proxyWrap.querySelector('.mad-dg-filter-btn');
                    if (!proxyBtn) return;
                    const field    = proxyWrap.dataset.filterField;
                    const realWrap = thead.querySelector(`.mad-dg-filter-wrap[data-filter-field="${field}"]`);
                    if (!realWrap) return;
                    const realPop  = realWrap.querySelector('.mad-dg-filter-popover');
                    if (!realPop) return;

                    proxyBtn.onclick = (e) => {
                        e.stopPropagation();
                        e.preventDefault();
                        // Reutiliza o mesmo helper global do caso não-sticky
                        window._madPositionFilterPopover(proxyBtn, realWrap, realPop);
                    };
                });
            };

            const spRect = () => (scrollParent === window)
                ? { top: 0, left: 0, width: window.innerWidth, bottom: window.innerHeight }
                : scrollParent.getBoundingClientRect();

            const wrapRect = () => {
                const tw = root.querySelector('.mad-dg-table-wrap');
                return tw ? tw.getBoundingClientRect() : spRect();
            };

            // ── Thead proxy ───────────────────────────────────────────────────
            const ensureTheadProxy = () => {
                if (!theadProxy) {
                    const t = document.createElement('table');
                    t.className    = 'mad-table mad-dg-table mad-dg-thead-proxy';
                    t.style.cssText = 'position:fixed;display:none;z-index:202;table-layout:fixed;border-collapse:separate;border-spacing:0;';
                    // Cliques no proxy thead: trata botões de sort via data-sort-field
                    t.addEventListener('click', (e) => {
                        const sortBtn = e.target.closest('[data-sort-field]');
                        if (sortBtn) {
                            e.stopPropagation();
                            const field   = sortBtn.getAttribute('data-sort-field');
                            const wrapper = root.closest('[mad-component]');
                            if (wrapper && window.MadWire) MadWire.call(wrapper, 'onSort', [field]);
                            return;
                        }
                        // Fallback: data-proxy-fwd genérico
                        const btn = e.target.closest('[data-proxy-fwd]');
                        if (!btn) return;
                        e.stopPropagation();
                        const expr    = btn.getAttribute('data-proxy-fwd');
                        const match   = expr.match(/^(\w+)\((.*)\)$/);
                        const method  = match ? match[1] : expr;
                        const argsRaw = match ? match[2].trim() : '';
                        const args    = argsRaw
                            ? argsRaw.split(',').map(a => a.trim().replace(/^['"]|['"]$/g, ''))
                            : [];
                        const wrapper = root.closest('[mad-component]');
                        if (wrapper && window.MadWire) MadWire.call(wrapper, method, args);
                    });
                    document.body.appendChild(t);
                    theadProxy = t;
                }
                return theadProxy;
            };

            const syncTheadProxy = (thead, sp, wr) => {
                const tp = ensureTheadProxy();
                const theadTop = thead.getBoundingClientRect().top;

                if (theadTop >= sp.top) { tp.style.display = 'none'; return 0; }

                // Sincroniza conteúdo (atualiza ícones de sort ao ordenar)
                const src = thead.innerHTML + thead.offsetWidth;
                if (tp.dataset.src !== src) {
                    tp.dataset.src = src;
                    tp.innerHTML   = '<thead class="mad-dg-head">' + thead.innerHTML + '</thead>';
                    // Remove data-mad-click do proxy → MadWire não intercepta o clique;
                    // salva o valor em data-proxy-fwd para o nosso handler de forwarding
                    tp.querySelectorAll('[data-mad-click]').forEach(el => {
                        el.setAttribute('data-proxy-fwd', el.getAttribute('data-mad-click'));
                        el.removeAttribute('data-mad-click');
                    });
                    // Remove atributos Alpine do proxy (fora do scope x-data)
                    // e aplica classes/estilos diretamente para evitar erros de avaliação
                    tp.querySelectorAll('*').forEach(el => {
                        [...el.attributes].forEach(attr => {
                            if (attr.name.startsWith('x-') || attr.name.startsWith(':') || attr.name.startsWith('@')) {
                                el.removeAttribute(attr.name);
                            }
                        });
                    });
                    // Copia larguras exatas e visibilidade de cada th para alinhar colunas
                    const origThs  = thead.querySelectorAll('th');
                    const proxThs  = tp.querySelectorAll('th');
                    origThs.forEach((th, i) => {
                        if (proxThs[i]) {
                            // Replica classe mad-dg-col-hidden diretamente
                            proxThs[i].classList.toggle('mad-dg-col-hidden', th.classList.contains('mad-dg-col-hidden'));
                            const w = th.getBoundingClientRect().width;
                            proxThs[i].style.width    = w + 'px';
                            proxThs[i].style.minWidth = w + 'px';
                            proxThs[i].style.maxWidth = w + 'px';
                        }
                    });
                    if (window.lucide) window.lucide.createIcons({ nodes: [tp] });
                    setupProxyFilters(tp, thead);
                }

                tp.style.top    = sp.top + 'px';
                tp.style.left   = wr.left + 'px';
                tp.style.width  = wr.width + 'px';
                tp.style.display = 'table';
                return tp.offsetHeight;
            };

            // ── Group proxies ─────────────────────────────────────────────────
            const ensureGroupProxy = (lvl) => {
                if (!groupProxies[lvl]) {
                    const p = document.createElement('div');
                    p.className    = 'mad-dg-sticky-proxy mad-dg-group-level-' + lvl;
                    p.style.cssText = 'position:fixed;display:none;z-index:' + (200 - lvl) + ';';
                    document.body.appendChild(p);
                    groupProxies[lvl] = p;
                }
                return groupProxies[lvl];
            };

            const hideAll = () => {
                Object.values(groupProxies).forEach(p => (p.style.display = 'none'));
                if (theadProxy) theadProxy.style.display = 'none';
            };

            // ── Update on scroll ──────────────────────────────────────────────
            const update = () => {
                if (ticking) return;
                ticking = true;
                requestAnimationFrame(() => {
                    ticking = false;

                    const sp = spRect();
                    const wr = wrapRect();
                    const thead  = root.querySelector('thead');
                    // group-band="cells" fica FORA do sticky: a banda daquele
                    // modo e uma linha de celulas alinhadas as colunas, e o
                    // proxy flutuante (clone com colspan fixo) desalinharia o
                    // cabecalho inteiro. Relatorio abre com per-page=0 pra
                    // imprimir — nao ha rolagem longa pra proteger.
                    const groups = [...root.querySelectorAll('.mad-dg-group-row:not(.mad-dg-group-cells)')];

                    // Tabela fora da tela
                    if (!thead || wr.bottom <= sp.top || wr.top >= sp.bottom) { hideAll(); return; }

                    // Thead fixo — retorna altura ocupada para empilhar grupos abaixo
                    const theadProxyH = syncTheadProxy(thead, sp, wr);

                    if (!groups.length) return;

                    // Threshold para grupos = topo do container + altura do thead proxy
                    const threshold = sp.top + theadProxyH;
                    if (wr.bottom <= threshold) {
                        Object.values(groupProxies).forEach(p => (p.style.display = 'none'));
                        return;
                    }

                    const activeByLevel = {};
                    groups.forEach(g => {
                        const lvl = parseInt(g.dataset.level ?? '0');
                        if (g.getBoundingClientRect().top < threshold) activeByLevel[lvl] = g;
                    });

                    const levels = [...new Set(groups.map(g => parseInt(g.dataset.level ?? '0')))].sort();
                    let stackTop = threshold;

                    levels.forEach(lvl => {
                        const proxy  = ensureGroupProxy(lvl);
                        const active = activeByLevel[lvl];

                        proxy.style.left  = wr.left + 'px';
                        proxy.style.width = wr.width + 'px';
                        proxy.style.top   = stackTop + 'px';

                        if (active) {
                            const cell = active.querySelector('.mad-dg-group-cell');
                            if (cell) {
                                const src = lvl + active.innerText;
                                if (proxy.dataset.src !== src) {
                                    proxy.dataset.src = src;
                                    proxy.innerHTML   = cell.outerHTML;
                                    if (window.lucide) window.lucide.createIcons({ nodes: [proxy] });
                                }
                                proxy.style.display = 'flex';
                                stackTop += proxy.offsetHeight;
                            }
                        } else {
                            proxy.style.display = 'none';
                        }
                    });
                });
            };

            // Remove tudo ao destruir / re-render AJAX
            const cleanup = () => {
                // Recoloca qualquer popover teleportado antes de destruir
                root.querySelectorAll('.mad-dg-filter-popover').forEach(p => {
                    if (p._madOrigParent) _madReattachPopover(p);
                });
                Object.values(groupProxies).forEach(p => p.remove());
                groupProxies = {};
                if (theadProxy) { theadProxy.remove(); theadProxy = null; }
            };
            const reset = () => { cleanup(); this.$nextTick(update); };

            scrollParent.addEventListener('scroll', update, { passive: true });
            window.addEventListener('resize', update, { passive: true });
            root.addEventListener('mad-wire:updated', reset);

            update();
            this._stickyGroupCleanup = () => {
                scrollParent.removeEventListener('scroll', update);
                window.removeEventListener('resize', update);
                cleanup();
            };
        },

        // ── Busca rápida server-side (debounce 400ms) ────────────────
        handleSearch() {
            clearTimeout(this._searchTimer);
            this._searchTimer = setTimeout(() => {
                const w = this._wrapper || this.$el.closest('[mad-component]');
                if (w) MadWire.call(w, 'onSearchTerm', [this.search]);
            }, 400);
        },

        // ── Exportação com a seleção atual do column chooser ────────
        handleExport(format) {
            const w = this._wrapper || this.$el.closest('[mad-component]');
            // Só a escolha do seletor: o hide-below é da tela, não do arquivo.
            const hidden = Object.keys(this.colVisibility).filter(f => this.colVisibility[f] === false);
            if (w) return MadWire.call(w, 'onExport' + format, [hidden]);
        },

        // ── Paginação (via window event) ─────────────────────────────
        handlePage(e) {
            const w = this._wrapper || this.$el.closest('[mad-component]');
            if (w) MadWire.call(w, 'onPage', [e.detail.page]);
        },

        // ── Filtro (via window event despachado pelos filter-wraps) ──
        handleFilter(e) {
            const w = this._wrapper || this.$el.closest('[mad-component]');
            if (w) MadWire.call(w, 'onFilter', [e.detail.field, e.detail.value, e.detail.op ?? 'like']);
        },

        // ── Ações com dispatch (usado pelo confirmAction) ────────────
        handleCall(e) {
            const w = this._wrapper || this.$el.closest('[mad-component]');
            if (w) MadWire.call(w, e.detail.method, e.detail.params || []);
        },

        // ── Inline editing ───────────────────────────────────────────
        // `rowKey` (data-row-id) e `keep` só vêm do restoreEdit; o Blade chama
        // com os três primeiros e a linha é a do elemento clicado.
        startEdit(rowId, field, currentVal, rowKey, keep) {
            const host = (!rowKey && this.$el && typeof this.$el.closest === 'function')
                ? this.$el.closest('tr[data-row-id]') : null;
            let key = rowKey || ((host && _root && host.closest('.mad-dg-wrap') === _root)
                ? host.getAttribute('data-row-id') : null);
            if (key === null || key === undefined) key = this._findRowKey(rowId);
            const prevKey = this.editingCell ? _edit.rowKey : null;

            // Reaberto antes de o salvamento anterior responder: a linha na
            // tela ainda é a de antes. O editor parte do que o usuário
            // confirmou por último, não do valor antigo que ela mostra.
            if (!keep) {
                const pend = _saves[_cellKey(rowId, field)];
                if (pend && pend.n > 0) currentVal = pend.value;
            }

            this.editingCell = { rowId, field };
            this.editValue   = currentVal;
            _edit.rowKey = key;
            _edit.from   = keep ? keep.from : currentVal;
            _edit.input  = null;
            _edit.moved  = false;
            // Outro editor ficou aberto em outra linha (sem foco não há blur):
            // ele foi abandonado e a linha dele pode ser redesenhada.
            if (prevKey !== null && prevKey !== key) setTimeout(() => this._releaseIdle(), 0);

            this.$nextTick(() => {
                const root = _root || this.$el;
                // Re-init MAD Select/Pickr no editor recem-revelado (selects com data-mad-dg-edit)
                this._initInlineEditors(root);
                // O campo DESTA célula. Antes a busca caía no primeiro editor da
                // grade (o da 1ª linha, escondido) e o editor abria sem cursor.
                const sel = `.mad-dg-cell-input[data-edit-row-id="${rowId}"][data-edit-field="${field}"]`;
                const inp = this._cellInput(key, field)
                    || root.querySelector(sel)
                    || (key === null ? root.querySelector('.mad-dg-cell-input') : null);
                if (!this.isEditing(rowId, field)) return;
                _edit.input = inp || null;
                if (inp) {
                    // Se for campo money, exibe valor formatado pt-BR
                    if (inp.dataset && inp.dataset.editMoney !== undefined) {
                        inp.value = madNumFmt(currentVal, parseInt(inp.dataset.editDecimals || 2));
                    }
                    if (keep && keep.focus === false) return;
                    // Pode ser selects com MAD Select (sem .focus tradicional)
                    if (typeof inp.focus === 'function') inp.focus();
                    if (keep && keep.sel) {
                        // Editor devolvido depois de um redesenho: o cursor
                        // volta para onde estava, sem selecionar o texto.
                        try { inp.setSelectionRange(keep.sel[0], keep.sel[1]); } catch (e) {}
                    } else if (typeof inp.select === 'function') {
                        try { inp.select(); } catch (e) {}
                    }
                }
            });
        },

        cancelEdit() {
            this._closeEditor();
            this.editValue = '';
        },

        isEditing(rowId, field) {
            return this.editingCell
                && this.editingCell.rowId === rowId
                && this.editingCell.field === field;
        },

        commitEdit() {
            if (!this.editingCell) return;
            const { rowId, field } = this.editingCell;
            const rowKey = _edit.rowKey;
            const input  = _edit.input || this._cellInput(rowKey, field);
            const run = () => {
                // Entre o blur e aqui o editor pode ter sido fechado (Esc) ou
                // trocado por outro.
                if (!this.isEditing(rowId, field)) return;
                // O campo saiu do documento: quem disparou este blur foi a
                // troca do DOM (linha ou grade redesenhada), não o usuário.
                // Gravar aqui salvava o valor antigo ou meia digitação.
                if (input && input.isConnected === false) {
                    this._editorDropped();
                    return;
                }
                const value = this.editValue;
                const from  = _edit.from;
                this._closeEditor();
                // Confirmar sem mudar nada só fecha o editor.
                if (_sameVal(value, from)) return;
                this._saveCell(rowId, field, value, rowKey);
            };
            // O navegador dispara `blur` no campo que está sendo ARRANCADO do
            // documento, e nessa hora ele ainda consta como ligado. Só depois
            // que a troca termina (um microtask) dá para saber quem fechou.
            if (input && typeof input.isConnected === 'boolean') Promise.resolve().then(run);
            else run();
        },

        // Modo inline: salva direto sem estado editingCell (campo sempre visível)
        commitInline(rowId, field, value) {
            // Chamado do próprio campo (`@blur`/`@change`): `$el` é ele.
            const el = (this.$el && this.$el !== _root) ? this.$el : null;
            const tr = (el && typeof el.closest === 'function') ? el.closest('tr[data-row-id]') : null;
            const rowKey = (tr && _root && tr.closest('.mad-dg-wrap') === _root) ? tr.getAttribute('data-row-id') : null;
            const run = () => {
                // Campo arrancado do documento (linha ou grade redesenhada):
                // o blur não é do usuário — ver commitEdit.
                if (el && el.isConnected === false) return;
                // Passar pelo campo (Tab) sem mudar nada não grava: o valor é
                // o mesmo que o servidor desenhou.
                if (el && _inlineUntouched(el)) {
                    setTimeout(() => this._releaseIdle(), 0);
                    return;
                }
                this._saveCell(rowId, field, value, rowKey);
            };
            if (el && typeof el.isConnected === 'boolean') Promise.resolve().then(run);
            else run();
        },

        // ── Edição na célula × respostas do servidor (framework#175) ──
        // <tr> DESTA grade pela chave (data-row-id).
        _rowEl(rowKey) {
            if (!_root || rowKey === null || rowKey === undefined || typeof _root.querySelector !== 'function') return null;
            const tr = _root.querySelector('tr[data-row-id="' + _cssId(rowKey) + '"]');
            // Grade dentro de grade: cada uma responde só pelas próprias linhas.
            return (tr && (typeof tr.closest !== 'function' || tr.closest('.mad-dg-wrap') === _root)) ? tr : null;
        },

        _cellEl(rowKey, field) {
            const tr = this._rowEl(rowKey);
            return tr ? tr.querySelector('td[data-col="' + _cssId(_fieldKey(field)) + '"]') : null;
        },

        _cellInput(rowKey, field) {
            const td = this._cellEl(rowKey, field);
            return td ? td.querySelector('.mad-dg-cell-input') : null;
        },

        // startEdit chamado sem elemento (por código): acha a linha pelo fim
        // da chave — `Classe_<id>`.
        _findRowKey(rowId) {
            if (!_root || typeof _root.querySelectorAll !== 'function') return null;
            const id = String(rowId);
            for (const tr of _root.querySelectorAll('tr[data-row-id]')) {
                const k = tr.getAttribute('data-row-id') || '';
                if ((k === id || k.endsWith('_' + id)) && (typeof tr.closest !== 'function' || tr.closest('.mad-dg-wrap') === _root)) return k;
            }
            return null;
        },

        // Há um editor aberto nesta linha? Clique/duplo clique: o editor do
        // editingCell. Inline (campo sempre visível): o que está com o foco.
        _rowBusy(rowKey) {
            if (this.editingCell && _edit.rowKey !== null && _edit.rowKey === rowKey) return true;
            const a = (typeof document !== 'undefined') ? document.activeElement : null;
            if (a && typeof a.closest === 'function' && a.closest('.mad-dg-edit-inline')) {
                const tr = a.closest('tr[data-row-id]');
                if (tr && tr.getAttribute('data-row-id') === rowKey && tr.closest('.mad-dg-wrap') === _root) return true;
            }
            return false;
        },

        // mad.js pergunta antes de trocar uma <tr> desta grade (manage_row).
        // true = a linha tem um editor aberto: a op fica guardada (a mais nova
        // vence) e entra quando o editor fechar. Trocar a linha ali apagava o
        // que o usuário está digitando.
        holdRowOp(rowKey, op) {
            if (!this._rowBusy(rowKey)) {
                delete _held[rowKey];   // a que vai entrar agora é mais nova
                return false;
            }
            _held[rowKey] = op;
            return true;
        },

        // A linha saiu da grade (remove_row): nada a guardar nem a gravar nela.
        rowGone(rowKey) {
            delete _held[rowKey];
            if (this.editingCell && _edit.rowKey === rowKey) this._closeEditor();
        },

        // Solta as linhas guardadas que não têm mais editor aberto nem
        // salvamento a caminho (a resposta dele traz a linha mais nova).
        _releaseIdle() {
            const keys = Object.keys(_held);
            if (!keys.length) return;
            if (!_root || _root.isConnected === false) {
                keys.forEach(k => { delete _held[k]; });
                return;
            }
            keys.forEach(key => {
                if (this._rowBusy(key) || _rowSaves[key] > 0) return;
                const op = _held[key];
                delete _held[key];
                if (typeof Mad !== 'undefined' && Mad && typeof Mad.applyOps === 'function') {
                    Mad.applyOps([Object.assign({}, op, { _released: true })]);
                }
            });
        },

        _closeEditor() {
            this.editingCell = null;
            _edit.rowKey = null;
            _edit.from   = null;
            _edit.input  = null;
            _edit.moved  = false;
            // Fora do handler do próprio campo: soltar a linha guardada troca
            // a <tr> em que ele está.
            setTimeout(() => this._releaseIdle(), 0);
        },

        // O editor sumiu junto com a linha (redesenho): nada é gravado. Se
        // havia digitação e o editor não foi devolvido à tela nova
        // (captureEdit → restoreEdit), o usuário fica sabendo.
        _editorDropped() {
            const field = this.editingCell ? this.editingCell.field : '';
            const lost  = !_edit.moved && !_sameVal(this.editValue, _edit.from);
            this._closeEditor();
            if (lost) this._cellNotice(field, 'dropped', 'warning');
        },

        // Grava UMA célula. `rowKey` = data-row-id da linha (pode faltar).
        _saveCell(rowId, field, value, rowKey) {
            const w = this._wrapper || (_root && typeof _root.closest === 'function' ? _root.closest('[mad-component]') : null);
            // `MadWire` é const global do mad-livewire.js (não vira window.MadWire).
            if (!w || typeof MadWire === 'undefined') return;
            const ck = _cellKey(rowId, field);
            const s  = _saves[ck] || (_saves[ck] = { n: 0, value: value });
            s.n++;
            s.value = value;
            if (rowKey !== null && rowKey !== undefined) _rowSaves[rowKey] = (_rowSaves[rowKey] || 0) + 1;
            const mark = on => {
                const td = this._cellEl(rowKey, field);
                if (td && td.classList) td.classList.toggle('mad-dg-cell-saving', on);
            };
            mark(true);
            const done = res => {
                s.n--;
                if (s.n <= 0 && _saves[ck] === s) delete _saves[ck];
                if (rowKey !== null && rowKey !== undefined && --_rowSaves[rowKey] <= 0) delete _rowSaves[rowKey];
                // A linha redesenhada já veio sem a marca; se não foi
                // redesenhada (resposta guardada, falha), tira aqui.
                if (s.n <= 0) mark(false);
                // Sem resposta do servidor nada foi gravado — e nada avisava.
                if (res && res.ok === false && res.reason === 'network') this._cellNotice(field, 'lost', 'danger');
                this._releaseIdle();
            };
            let call;
            try { call = MadWire.call(w, 'onInlineSave', [rowId, field, value]); }
            catch (e) { call = Promise.reject(e); }
            Promise.resolve(call).then(done, () => done({ ok: false, reason: 'network' }));
        },

        // Aviso da própria grade (texto traduzido vem do Blade em cfg.editText).
        _cellNotice(field, kind, type) {
            const t = this._cfg.editText || {};
            const text = kind === 'lost'
                ? (t.lost || 'A alteração não foi gravada: o servidor não respondeu. Confira a conexão e tente de novo.')
                : (t.dropped || 'O que você estava digitando não foi gravado: a listagem foi atualizada antes da confirmação.');
            const fk  = _fieldKey(field);
            const col = (this.cols || []).find(c => c.field === fk);
            const label = col && col.label ? String(col.label).replace(/<[^>]*>/g, '').trim() : '';
            if (typeof window.madToast === 'function') {
                window.madToast({ message: (label ? label + ': ' : '') + text, type: type || 'warning' });
            }
        },

        // Redesenho completo da tela (paginar, buscar, ordenar, ação que
        // devolve a tela): o Alpine renasce e o editor aberto sumia — com o
        // blur gravando o que estivesse digitado. O mad.js fotografa aqui
        // ANTES da troca (Mad.captureUiState) e devolve à grade nova
        // (restoreEdit). null = nada a levar.
        captureEdit() {
            const saves = Object.keys(_saves).length ? _saves : null;
            if (!this.editingCell) return saves ? { saves } : null;
            const { rowId, field } = this.editingCell;
            const input = _edit.input || this._cellInput(_edit.rowKey, field);
            const focused = !!(input && typeof document !== 'undefined' && document.activeElement === input);
            let sel = null;
            if (focused) {
                try { if (input.selectionStart !== null && input.selectionStart !== undefined) sel = [input.selectionStart, input.selectionEnd]; } catch (e) {}
            }
            _edit.moved = true;
            return { rowId, field, rowKey: _edit.rowKey, value: this.editValue, from: _edit.from, focused, sel, saves };
        },

        restoreEdit(snap) {
            if (!snap) return;
            // Salvamentos que ainda vão responder: o editor reaberto na grade
            // nova continua partindo do último valor confirmado.
            if (snap.saves) Object.keys(snap.saves).forEach(k => { _saves[k] = snap.saves[k]; });
            if (snap.rowId === undefined || snap.field === undefined) return;
            // A linha (ou a coluna editável) não está na tela nova: a
            // digitação não tem onde continuar — e não é gravada.
            if (!this._cellInput(snap.rowKey, snap.field)) {
                if (!_sameVal(snap.value, snap.from)) this._cellNotice(snap.field, 'dropped', 'warning');
                return;
            }
            this.startEdit(snap.rowId, snap.field, snap.value, snap.rowKey,
                { from: snap.from, focus: snap.focused, sel: snap.sel });
        },

        // ── Seleção de linhas ────────────────────────────────────────
        isSelected(id) {
            return this.selected.indexOf(String(id)) !== -1;
        },

        toggleRow(id, on) {
            const key = String(id);
            const has = this.selected.indexOf(key) !== -1;
            const want = (on === undefined) ? !has : !!on;
            if (want && !has) this.selected = this.selected.concat([key]);
            else if (!want && has) this.selected = this.selected.filter(v => v !== key);
        },

        // Checkbox do cabeçalho: marca/desmarca as linhas DESTA página e
        // preserva o que foi marcado nas outras.
        togglePage(on) {
            if (on) {
                const add = this._pageIds.filter(id => this.selected.indexOf(id) === -1);
                if (add.length) this.selected = this.selected.concat(add);
            } else {
                const page = this._pageIds;
                this.selected = this.selected.filter(id => page.indexOf(id) === -1);
            }
        },

        pageAllSelected() {
            return this._pageIds.length > 0 && this._pageIds.every(id => this.selected.indexOf(id) !== -1);
        },

        pageSomeSelected() {
            return !this.pageAllSelected() && this._pageIds.some(id => this.selected.indexOf(id) !== -1);
        },

        clearSelection() {
            this.selected = [];
        },

        selCountLabel() {
            const t = this._cfg.selText || {};
            const n = this.selected.length;
            if (n === 0) return t.none || '';
            if (n === 1) return t.one || '1';
            return String(t.many || ':count').replace(':count', n);
        },

        // `[id => id]` (objeto) — o mesmo formato de $this->selectedIds().
        _selMap() {
            const out = {};
            this.selected.forEach(id => { out[id] = id; });
            return out;
        },

        // Ação em lote: `method` chama o método do grid com a seleção; `target`
        // passa pelo onBulkOpen (a requisição grava a seleção na sessão antes de
        // abrir a tela, que recebe `ids`).
        runBulk(index, event) {
            const b = (this._cfg.bulk || [])[index];
            if (!b) return;
            if (this.selected.length < (b.min === undefined ? 1 : b.min)) return;
            const go = () => {
                const w = this._wrapper || this.$el.closest('[mad-component]');
                // `MadWire` é const global do mad-livewire.js (não vira window.MadWire).
                if (!w || typeof MadWire === 'undefined') return;
                return b.m
                    ? MadWire.call(w, b.m, [this._selMap()])
                    : MadWire.call(w, 'onBulkOpen', [index]);
            };
            if (b.c) {
                this.confirmAction(String(b.c).replace(/:count/g, this.selected.length), go, event, null);
            } else {
                go();
            }
        },

        // ── Confirm helper ───────────────────────────────────────────
        confirmAction(message, callback, event, type) {
            var opts = { danger: true };
            if (type === 'popover' && event) {
                madConfirmPopover(event.currentTarget || event.target, message, opts)
                    .then(ok => { if (ok && typeof callback === 'function') callback(); });
            } else {
                madConfirm(message, opts)
                    .then(ok => { if (ok && typeof callback === 'function') callback(); });
            }
        },
        }; // fim do return
    }); // fim do Alpine.data
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madCustomFilter — "Filtro avançado" da listagem
// ═══════════════════════════════════════════════════════════════════════════
//
// <mad-custom-filters> dentro do <mad-grid>: o usuário final monta condições
// [Coluna][Operador][Valor], combinadas por "todas" (E) ou "qualquer uma" (OU).
// Markup: components/data-grid-custom-filters.blade.php.
//
//   • Rascunho 100% local — montar/editar não fala com o servidor. UMA chamada
//     do wire no Aplicar: onCustomFilterApply({match, rules:[{k,op,v,d}]}). O
//     servidor revalida cada regra contra as defs e redesenha a grid.
//   • O popover vive num <template x-teleport="body"> (escapa do overflow da
//     grid/página). As chamadas saem do HOST (dentro do wrapper), então o
//     MadWire acha o componente sem depender do rastro do teleporte.
//   • Contagem ao vivo ("Aplicar · N registros"): POST direto no endpoint do
//     wire (onCustomFilterCount) que só LÊ o op `grid_cf_count` da resposta —
//     sem morph, sem trocar o mad-state, sem o "carregando" da grid (mesmo
//     esquema do data-mad-fl-change). Qualquer falha só esconde o número.
//   • Ações que redesenham a grid com o popover aberto (salvar, excluir, ★,
//     limpar) guardam o rascunho em _madCfStash; a instância nova, criada pelo
//     redesenho, reabre no mesmo ponto.
//   • Ícones: <i data-lucide> + _madLucide(), como o resto do runtime. Linhas e
//     listas nascem depois do render (x-for/x-if), então um MutationObserver no
//     popover chama o hook de novo. Nunca há <i data-lucide> como raiz de
//     x-for/x-if: o Lucide troca o nó e o Alpine perderia a referência.
const _madCfStash = new Map();   // id do filtro → { at, draft, saved }

document.addEventListener('alpine:init', () => {
    Alpine.data('madCustomFilter', (cfg = {}) => {
        const C       = cfg && typeof cfg === 'object' ? cfg : {};
        const T       = C.i18n || {};
        const DEFS    = Array.isArray(C.defs) ? C.defs.filter(d => d && d.key) : [];
        const DEF     = {};
        DEFS.forEach(d => { DEF[d.key] = d; });
        const NOVAL   = Array.isArray(C.noValueOps) && C.noValueOps.length ? C.noValueOps : ['empty', 'not empty', 'is null', 'is not null'];
        const LISTOPS = Array.isArray(C.listOps) && C.listOps.length ? C.listOps : ['in', 'not in'];
        const PRESETS = Array.isArray(C.presets) ? C.presets : [];
        const LABELS  = C.labels && typeof C.labels === 'object' ? C.labels : {};
        const MAX     = Math.max(1, parseInt(C.max, 10) || 15);
        const ID      = String(C.id || 'mad-dg-cf');
        const TOTAL   = typeof C.total === 'number' ? C.total : null;
        const IS_MAC  = /Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent || '');
        const OPT_CAP = 300;   // itens desenhados numa lista (o resto aparece buscando)

        // Internos FORA do estado reativo: host, timers, observers, listeners,
        // rótulos vindos da busca remota, chaves da lista aberta.
        const _ = {
            host: null, seq: 0, keys: [], lbl: {}, off: [], offWrap: null, obs: null,
            raf: 0, iconRaf: 0, flashTimer: 0, countTimer: 0, countCtl: null,
            searchTimer: 0, searchCtl: null, appliedKey: '', appliedCountKey: '',
        };

        const isArr = Array.isArray;
        const clone = o => JSON.parse(JSON.stringify(o));
        const norm  = s => String(s == null ? '' : s).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
        const tr    = (k, rep) => {
            let s = String(T[k] != null ? T[k] : k);
            if (rep) {
                Object.keys(rep).sort((a, b) => b.length - a.length)
                    .forEach(n => { s = s.split(':' + n).join(String(rep[n])); });
            }
            return s;
        };
        const hasOp = (d, op) => !!d && (d.ops || []).some(o => o[0] === op);
        const fmtN  = n => {
            try { return new Intl.NumberFormat(document.documentElement.lang || undefined).format(n); }
            catch (e) { return String(n); }
        };

        // ── Semântica das regras (espelha _cfNormalizeRule do servidor) ──────
        function shape(k, op) {
            const d = DEF[k];
            if (!d || !op || NOVAL.includes(op)) return 'none';
            if (d.kind === 'bool') return 'bool';
            if (d.kind === 'select' || d.kind === 'dbcombo' || d.kind === 'dbsearch') {
                return LISTOPS.includes(op) ? 'opt-multi' : 'opt-single';
            }
            if (op === 'in' || op === 'not in') return 'tags';
            if (op === 'between') return 'range-number';
            if (op === 'date between') return 'range-date';
            if (op === 'date preset') return 'preset';
            if (d.kind === 'date') return 'date';
            if (d.kind === 'datetime') return op === 'date' ? 'date' : 'datetime';
            if (d.kind === 'number') return 'number';
            return 'text';
        }
        function emptyValue(sh, d) {
            switch (sh) {
                case 'tags': case 'opt-multi': return [];
                case 'range-number': case 'range-date': return ['', ''];
                case 'bool': return String(d && d.true != null ? d.true : '1');
                case 'preset': return PRESETS.some(p => p[0] === 'last_30_days') ? 'last_30_days' : (PRESETS[0] ? PRESETS[0][0] : '');
                default: return '';
            }
        }
        function complete(r) {
            if (!r || !r.k || !r.op || !DEF[r.k]) return false;
            const sh = shape(r.k, r.op);
            if (sh === 'none') return true;
            if (sh === 'bool' || sh === 'preset') return String(r.v == null ? '' : r.v) !== '';
            if (sh === 'tags' || sh === 'opt-multi') return isArr(r.v) && r.v.length > 0;
            if (sh === 'range-number' || sh === 'range-date') {
                return isArr(r.v) && (String(r.v[0] == null ? '' : r.v[0]).trim() !== '' || String(r.v[1] == null ? '' : r.v[1]).trim() !== '');
            }
            return String(r.v == null ? '' : r.v).trim() !== '';
        }
        // Regra do servidor ({k,op,v,d}) → linha do rascunho (com _id local).
        function toDraft(rules) {
            return (isArr(rules) ? rules : []).filter(r => r && DEF[r.k]).map(r => {
                const sh = shape(r.k, r.op);
                let v = r.v;
                if (sh === 'tags' || sh === 'opt-multi') {
                    v = isArr(v) ? v.map(String) : (v == null || v === '' ? [] : [String(v)]);
                } else if (sh === 'range-number' || sh === 'range-date') {
                    v = isArr(v) ? [String(v[0] == null ? '' : v[0]), String(v[1] == null ? '' : v[1])] : ['', ''];
                } else if (sh === 'none') {
                    v = '';
                } else {
                    v = v == null ? '' : String(v);
                }
                // datetime-local quer `YYYY-MM-DDTHH:MM`; o servidor guarda com espaço e segundos.
                if (sh === 'datetime' && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/.test(v)) v = v.slice(0, 16).replace(' ', 'T');
                const d = r.d ? String(r.d) : '';
                if (d && DEF[r.k].kind === 'dbsearch' && typeof v === 'string' && v !== '') {
                    (_.lbl[r.k] = _.lbl[r.k] || {})[v] = d;
                }
                return { _id: 'r' + (++_.seq), k: r.k, op: r.op, v, d };
            });
        }
        // Linha do rascunho → regra que o servidor recebe (sem _id).
        function toServer(r) {
            const sh = shape(r.k, r.op);
            let v = r.v;
            if (sh === 'none') v = null;
            else if (isArr(v)) v = v.map(x => String(x).trim());
            else v = String(v == null ? '' : v).trim();
            return { k: r.k, op: r.op, v, d: r.d || '' };
        }
        // Chave de comparação (sem `d`, que é só rótulo) — "alterado" e "nada mudou".
        function specKey(match, rules) {
            return JSON.stringify([match === 'any' ? 'any' : 'all',
                rules.filter(complete).map(toServer).map(x => [x.k, x.op, x.v])]);
        }
        function payloadOf(match, rules, all) {
            return {
                match: match === 'any' ? 'any' : 'all',
                rules: rules.filter(r => (all ? !!(r.k && DEF[r.k]) : complete(r))).map(toServer),
            };
        }
        function valueLabel(k, x) {
            const d = DEF[k], s = String(x);
            if (!d) return s;
            if (isArr(d.opts)) { const p = d.opts.find(o => o[0] === s); if (p) return p[1]; }
            if (_.lbl[k] && _.lbl[k][s] != null) return _.lbl[k][s];
            if (LABELS[k] && LABELS[k][s] != null) return String(LABELS[k][s]);
            return s;
        }

        // ── Datas (dica do período) — mesma regra do datePresetRange do PHP ──
        const isoOf = dt => dt.getFullYear() + '-' + String(dt.getMonth() + 1).padStart(2, '0')
                          + '-' + String(dt.getDate()).padStart(2, '0');
        const fmtIso = iso => {
            const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
            if (!m) return String(iso || '');
            return String(C.dateFormat || 'd/m/Y').replace(/[dmYy]/g, c => ({ d: m[3], m: m[2], Y: m[1], y: m[1].slice(2) })[c]);
        };
        function presetRange(id) {
            const now = new Date();
            const d0  = new Date(now.getFullYear(), now.getMonth(), now.getDate());
            const add = (d, n) => { const x = new Date(d); x.setDate(x.getDate() + n); return x; };
            const dow = (d0.getDay() + 6) % 7;   // semana começa na segunda
            const y = d0.getFullYear(), mo = d0.getMonth();
            const r = {
                today:        [d0, d0],
                yesterday:    [add(d0, -1), add(d0, -1)],
                this_week:    [add(d0, -dow), add(d0, 6 - dow)],
                last_week:    [add(d0, -dow - 7), add(d0, -dow - 1)],
                this_month:   [new Date(y, mo, 1), new Date(y, mo + 1, 0)],
                last_month:   [new Date(y, mo - 1, 1), new Date(y, mo, 0)],
                this_year:    [new Date(y, 0, 1), new Date(y, 11, 31)],
                last_year:    [new Date(y - 1, 0, 1), new Date(y - 1, 11, 31)],
                last_7_days:  [add(d0, -6), d0],
                last_30_days: [add(d0, -29), d0],
                last_90_days: [add(d0, -89), d0],
                next_7_days:  [d0, add(d0, 6)],
                next_30_days: [d0, add(d0, 29)],
            }[id];
            return r ? [isoOf(r[0]), isoOf(r[1])] : null;
        }

        // ── Sugestões do estado vazio, derivadas das colunas declaradas ──────
        const SUGGEST = [];
        (() => {
            const date = DEFS.find(d => (d.kind === 'date' || d.kind === 'datetime') && hasOp(d, 'date preset'));
            if (date && PRESETS.some(p => p[0] === 'last_30_days')) {
                SUGGEST.push({ id: 'recent', label: tr('suggest_recent', { label: date.label }),
                               rule: { k: date.key, op: 'date preset', v: 'last_30_days' } });
            }
            const bool = DEFS.find(d => d.kind === 'bool' && hasOp(d, '='));
            if (bool) {
                SUGGEST.push({ id: 'yes', label: tr('suggest_yes', { label: bool.label }),
                               rule: { k: bool.key, op: '=', v: String(bool.true != null ? bool.true : '1') } });
            }
            const choice = DEFS.find(d => ['select', 'dbcombo', 'dbsearch'].includes(d.kind) && hasOp(d, 'in'));
            if (choice) {
                SUGGEST.push({ id: 'in', label: tr('suggest_in', { label: choice.label }),
                               rule: { k: choice.key, op: 'in', v: [] }, openOpts: true });
            }
        })();

        // Estado EM VIGOR (o que a grid mostra agora) — base do "alterado", do
        // "nada mudou" no Aplicar e do total já conhecido.
        const APPLIED_MATCH = C.match === 'any' ? 'any' : 'all';
        const APPLIED_RULES = isArr(C.rules) ? C.rules : [];
        {
            const d = toDraft(APPLIED_RULES);
            _.appliedKey = specKey(APPLIED_MATCH, d);
            const p = payloadOf(APPLIED_MATCH, d, false);
            _.appliedCountKey = p.rules.length ? JSON.stringify(p) : '';
        }

        return {
            cfOpen:      false,
            sheet:       false,
            draft:       { match: APPLIED_MATCH, rules: [] },
            invalid:     {},
            flashId:     null,
            menu:        null,     // { type: 'field'|'opt', row: _id, q, active }
            remote:      { row: null, state: 'idle', items: [] },   // busca do dbsearch
            savedOpen:   false,
            saveForm:    false,
            saveName:    '',
            saveShare:   false,
            saveDefault: false,
            saveError:   '',
            countVal:    null,
            countFor:    '',
            viewId:      C.viewId == null ? null : Number(C.viewId),
            viewName:    String(C.viewName || ''),
            saved: {
                mine:   isArr(C.saved && C.saved.mine) ? C.saved.mine : [],
                shared: isArr(C.saved && C.saved.shared) ? C.saved.shared : [],
            },
            presets:     PRESETS,
            suggestions: SUGGEST,
            max:         MAX,
            kbd:         IS_MAC ? '⌘ Enter' : 'Ctrl Enter',

            init() {
                _.host = this.$el;
                // Chip da barra de filtros ativos → reabre com a condição em destaque.
                const wrap = this.$el.closest('.mad-dg-wrap');
                if (wrap) {
                    const onChip = e => this.openPop({ flashIndex: e.detail ? Number(e.detail.index) : -1 });
                    wrap.addEventListener('mad-dg-cf-open', onChip);
                    _.offWrap = () => wrap.removeEventListener('mad-dg-cf-open', onChip);
                }
                this.$watch('draft', () => { if (this.cfOpen) this._scheduleCount(); });
                // Redesenho pedido com o popover aberto (salvar/excluir/★/limpar).
                const st = _madCfStash.get(ID);
                if (st) {
                    _madCfStash.delete(ID);
                    if (Date.now() - st.at < 15000) {
                        this.$nextTick(() => this.openPop({ draft: st.draft, saved: !!st.saved, refocus: true }));
                    }
                }
            },

            destroy() {
                this._unbind(true);
                if (_.offWrap) _.offWrap();
                clearTimeout(_.flashTimer);
            },

            // ── Helpers do template ──────────────────────────────────────
            cfT(k, rep) { return tr(k, rep); },
            def(k) { return DEF[k] || { key: k, label: String(k || ''), kind: 'text', ops: [] }; },
            domId(p, r) { return ID + '-' + p + '-' + (r && r._id ? r._id : r); },
            shapeOf(r) { return shape(r.k, r.op); },
            opsOf(r) { return r.k && DEF[r.k] ? DEF[r.k].ops : []; },
            conjLabel(i) { return i === 0 ? tr('where') : (this.draft.match === 'any' ? tr('or') : tr('and')); },
            switchTitle() { return tr('switch_match', { conj: this.draft.match === 'any' ? tr('and') : tr('or') }); },
            kindIcon(d) {
                if (!d) return '';
                if (d.group) return '<span class="mad-dg-cf-kind is-rel"><i data-lucide="link"></i></span>';
                const ic = { number: 'hash', date: 'calendar', datetime: 'calendar-clock', bool: 'toggle-right',
                             select: 'list', dbcombo: 'database', dbsearch: 'database' }[d.kind];
                return ic ? '<span class="mad-dg-cf-kind"><i data-lucide="' + ic + '"></i></span>'
                          : '<span class="mad-dg-cf-kind">Aa</span>';
            },
            boolVal(r, yes) {
                const d = DEF[r.k] || {};
                return String(yes ? (d.true != null ? d.true : '1') : (d.false != null ? d.false : '0'));
            },
            inputType(r) {
                const s = shape(r.k, r.op);
                return s === 'date' ? 'date' : s === 'datetime' ? 'datetime-local' : s === 'number' ? 'number' : 'text';
            },
            valuePlaceholder(r) {
                const d = DEF[r.k];
                return d && shape(r.k, r.op) === 'text' ? (d.placeholder || tr('value_placeholder')) : '';
            },
            presetHint(v) { const rg = presetRange(v); return rg ? fmtIso(rg[0]) + ' – ' + fmtIso(rg[1]) : ''; },
            selectedVals(r) {
                if (shape(r.k, r.op) === 'opt-multi') return isArr(r.v) ? r.v.map(String) : [];
                return r.v === '' || r.v == null || isArr(r.v) ? [] : [String(r.v)];
            },
            valueLabel(r, x) { return valueLabel(r.k, x); },
            removeOpt(r, x) { r.v = (isArr(r.v) ? r.v : []).filter(y => String(y) !== String(x)); },
            clearInvalid(r) {
                if (!this.invalid[r._id]) return;
                const n = Object.assign({}, this.invalid);
                delete n[r._id];
                this.invalid = n;
            },
            hasComplete() { return this.draft.rules.some(complete); },
            modified() { return this.viewId !== null && specKey(this.draft.match, this.draft.rules) !== _.appliedKey; },

            // ── Abrir / fechar ───────────────────────────────────────────
            toggle() { this.cfOpen ? this.close() : this.openPop({}); },

            openPop(o) {
                o = o || {};
                const base = o.draft || { match: APPLIED_MATCH, rules: APPLIED_RULES };
                this.draft     = { match: base.match === 'any' ? 'any' : 'all', rules: toDraft(base.rules) };
                this.invalid   = {};
                this.menu      = null;
                this.saveForm  = false;
                this.saveError = '';
                this.savedOpen = !!o.saved && C.save !== 'off' && !!C.canSave;
                this.countVal  = null;
                this.countFor  = '';
                this.sheet     = window.innerWidth < 640;
                const flash    = o.flashIndex != null && o.flashIndex >= 0 ? (this.draft.rules[o.flashIndex] || null) : null;
                this.flashId   = flash ? flash._id : null;
                clearTimeout(_.flashTimer);
                if (flash) _.flashTimer = setTimeout(() => { this.flashId = null; }, 1000);
                this.cfOpen    = true;
                this._scheduleCount();
                // setTimeout depois do nextTick: aberto de DENTRO de outro nextTick
                // (reabertura pós-redesenho), o nextTick roda antes de o Alpine
                // aplicar o x-show — o popover ainda estaria oculto (sem medida,
                // sem foco).
                this.$nextTick(() => setTimeout(() => {
                    if (!this.cfOpen) return;
                    this._bind();
                    this._position();
                    this._icons();
                    const pick = () => {
                        const pop = this._pop();
                        if (!pop) return null;
                        let el = flash ? this._byId(this.domId('f', flash)) : null;
                        if (!el && this.savedOpen) el = pop.querySelector('.mad-dg-cf-saved-toggle');
                        if (!el) el = pop.querySelector('.mad-dg-cf-field') || pop.querySelector('.mad-dg-cf-suggest button') || pop.querySelector('.mad-dg-cf-add');
                        return el;
                    };
                    const focusIt = () => {
                        const el = pick();
                        if (!el) return null;
                        try { el.focus({ preventScroll: true }); } catch (e) { el.focus(); }
                        return el;
                    };
                    const el = focusIt();
                    if (flash && el) { const row = el.closest('.mad-dg-cf-row'); if (row) row.scrollIntoView({ block: 'nearest' }); }
                    // Reaberto por um redesenho do wire: o resto do morph (ops,
                    // estado de UI da página) ainda roda depois e o foco pode
                    // cair no <body> — devolve ao popover se ele seguir aberto.
                    if (o.refocus) {
                        setTimeout(() => {
                            const pop = this._pop();
                            if (this.cfOpen && pop && !pop.contains(document.activeElement)) focusIt();
                        }, 150);
                    }
                }, 0));
            },

            close(focusTrigger = true) {
                if (!this.cfOpen) return;
                this.cfOpen    = false;
                this.menu      = null;
                this.savedOpen = false;
                this.saveForm  = false;
                this.invalid   = {};
                this._unbind(true);
                _madCfStash.delete(ID);
                if (focusTrigger) { const b = this._btn(); if (b) b.focus(); }
            },

            onKey(e) {
                if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                    e.preventDefault();
                    if (this.saveForm && e.target.closest && e.target.closest('.mad-dg-cf-save-form')) this.submitSave();
                    else this.apply();
                    return;
                }
                if (e.key === 'Escape') {
                    // Fecha a camada MAIS interna: lista → filtros salvos → popover.
                    e.preventDefault();
                    e.stopPropagation();
                    if (this.menu) { this.closeMenu(true); return; }
                    if (this.savedOpen) {
                        this.savedOpen = false;
                        this.saveForm  = false;
                        this.$nextTick(() => { const b = this._pop() && this._pop().querySelector('.mad-dg-cf-saved-toggle'); if (b) b.focus(); });
                        return;
                    }
                    this.close();
                }
            },

            // ── Linhas ───────────────────────────────────────────────────
            setMatch(m) { this.draft.match = m === 'any' ? 'any' : 'all'; },
            toggleMatch() { this.setMatch(this.draft.match === 'any' ? 'all' : 'any'); },

            addRule(rule, openOpts) {
                if (this.draft.rules.length >= MAX) return;
                this.savedOpen = false;
                this.draft.rules.push(Object.assign({ _id: 'r' + (++_.seq), k: null, op: null, v: '', d: '' }, rule || {}));
                const r = this.draft.rules[this.draft.rules.length - 1];
                if (!rule) { this.openMenu('field', r); return; }
                if (openOpts) { this.openMenu('opt', r); return; }
                this.$nextTick(() => { this._focus(this.domId('v', r)) || this._focus(this.domId('o', r)); });
            },

            useSuggestion(s) { this.addRule(clone(s.rule), !!s.openOpts); },

            removeRow(r) {
                const i = this.draft.rules.findIndex(x => x._id === r._id);
                if (i < 0) return;
                if (this.menu && this.menu.row === r._id) this.menu = null;
                this.clearInvalid(r);
                this.draft.rules.splice(i, 1);
                this.$nextTick(() => {
                    const prev = this.draft.rules[Math.max(0, i - 1)];
                    if (prev) { this._focus(this.domId('f', prev)); return; }
                    const pop = this._pop();
                    const el = pop && (pop.querySelector('.mad-dg-cf-suggest button') || pop.querySelector('.mad-dg-cf-add'));
                    if (el) el.focus();
                });
            },

            setField(r, key) {
                const d = DEF[key];
                if (!d) return;
                const ops    = (d.ops || []).map(o => o[0]);
                const op     = r.op && ops.includes(r.op) ? r.op : ops[0];
                const before = r.k ? shape(r.k, r.op) : null;
                const after  = shape(key, op);
                // Mantém o valor só quando o tipo e o formato batem (e, em lista
                // de opções, só na MESMA coluna — ids de outra tabela não servem).
                const keep = before === after && r.k && DEF[r.k] && DEF[r.k].kind === d.kind
                          && after !== 'bool' && (after.indexOf('opt') !== 0 || r.k === key);
                r.k  = key;
                r.op = op;
                if (!keep) { r.v = emptyValue(after, d); r.d = ''; }
                this.clearInvalid(r);
                this.menu = null;
                this.$nextTick(() => {
                    const sel = this._byId(this.domId('o', r));
                    if (sel) sel.value = r.op;
                    if (after === 'none' || after === 'bool') this._focus(this.domId('o', r));
                    else this._focus(this.domId('v', r)) || this._focus(this.domId('o', r));
                });
            },

            setOp(r, op) {
                if (!r.k) return;
                const before = shape(r.k, r.op), after = shape(r.k, op);
                const v = r.v;
                r.op = op;
                if (before !== after) {
                    if (before === 'opt-single' && after === 'opt-multi') r.v = v === '' || v == null ? [] : [String(v)];
                    else if (before === 'opt-multi' && after === 'opt-single') r.v = isArr(v) && v.length ? String(v[0]) : '';
                    else if ((before === 'text' || before === 'number') && after === 'tags') r.v = String(v || '') !== '' ? [String(v)] : [];
                    else if (before === 'tags' && (after === 'text' || after === 'number')) r.v = isArr(v) && v.length ? String(v[0]) : '';
                    else if (before === 'number' && after === 'range-number') r.v = [String(v || ''), ''];
                    else if (before === 'date' && after === 'range-date') r.v = [String(v || ''), ''];
                    else if (before === 'date' && after === 'datetime') r.v = v ? String(v) + 'T00:00' : '';
                    else if (before === 'datetime' && after === 'date') r.v = v ? String(v).slice(0, 10) : '';
                    else r.v = emptyValue(after, DEF[r.k]);
                    r.d = '';
                }
                this.clearInvalid(r);
                if (this.menu && this.menu.row === r._id) this.menu = null;
                this.$nextTick(() => {
                    if (after === 'none' || after === 'bool') this._focus(this.domId('o', r));
                    else this._focus(this.domId('v', r)) || this._focus(this.domId('o', r));
                });
            },

            // ── Etiquetas ("é um dos": Enter, colar 1, 2, 3, Backspace) ──
            tagKey(e, r) {
                if (e.metaKey || e.ctrlKey) return;
                const inp = e.target;
                if (e.key === 'Enter') {
                    e.preventDefault();
                    if (inp.value.trim()) { this._addTags(r, inp.value); inp.value = ''; }
                } else if (e.key === 'Backspace' && inp.value === '' && isArr(r.v) && r.v.length) {
                    r.v.pop();
                }
            },
            tagInput(e, r) {
                const v = e.target.value;
                if (/[,;\n\t]/.test(v)) { this._addTags(r, v); e.target.value = ''; }
            },
            tagPaste(e, r) {
                const cd  = e.clipboardData || window.clipboardData;
                const txt = cd ? cd.getData('text') : '';
                if (/[,;\n\t]/.test(txt)) {
                    e.preventDefault();
                    this._addTags(r, e.target.value + txt);
                    e.target.value = '';
                }
            },
            tagBlur(e, r) {
                if (this.cfOpen && e.target.value.trim()) { this._addTags(r, e.target.value); e.target.value = ''; }
            },
            _addTags(r, raw) {
                const numeric = (DEF[r.k] || {}).kind === 'number';
                const cur = isArr(r.v) ? r.v.map(String) : [];
                String(raw).split(/[,;\n\t]+/).map(s => s.trim()).filter(Boolean).forEach(p => {
                    let v = p;
                    if (numeric) {
                        v = p.replace(/\s/g, '').replace(',', '.');
                        if (v === '' || isNaN(Number(v))) return;
                    }
                    if (!cur.some(x => norm(x) === norm(v))) cur.push(v);
                });
                r.v = cur;
                this.clearInvalid(r);
            },

            // ── Lista flutuante (coluna / valores) ───────────────────────
            menuIs(type, r) { return !!this.menu && this.menu.type === type && this.menu.row === r._id; },
            toggleMenu(type, r) { if (this.menuIs(type, r)) this.closeMenu(true); else this.openMenu(type, r); },

            openMenu(type, r) {
                if (type === 'opt' && !(r.k && DEF[r.k])) return;
                this.savedOpen = false;
                this.menu = { type, row: r._id, q: '', active: 0 };
                if (type === 'opt' && DEF[r.k].kind === 'dbsearch') this._remoteSearch(r, '');
                this.$nextTick(() => {
                    this._positionMenu();
                    this._icons();
                    const q = this._byId(ID + '-menuq');
                    if (this.menuHasSearch() && q) { q.value = ''; q.focus(); return; }
                    const menu = this._menuEl();
                    const first = menu && (menu.querySelector('.mad-dg-cf-opt[aria-selected="true"]') || menu.querySelector('.mad-dg-cf-opt'));
                    if (first) first.focus();
                });
            },

            closeMenu(refocus) {
                const m = this.menu;
                if (!m) return;
                this.menu = null;
                if (!refocus) return;
                const r = this._rule(m.row);
                if (r) this.$nextTick(() => this._focus(this.domId(m.type === 'field' ? 'f' : 'v', r)));
            },

            menuHasSearch() {
                const m = this.menu;
                if (!m) return false;
                if (m.type === 'field') return true;
                const r = this._rule(m.row), d = r && DEF[r.k];
                return !!d && (d.kind === 'dbcombo' || d.kind === 'dbsearch' || (d.opts || []).length > 6);
            },
            menuMulti() {
                const m = this.menu;
                if (!m || m.type !== 'opt') return false;
                const r = this._rule(m.row);
                return !!r && shape(r.k, r.op) === 'opt-multi';
            },

            // Grupos da lista aberta. Recalculado pelo render; guarda as chaves
            // em ordem (_.keys) para as setas/Enter — fora do estado reativo.
            menuGroups() {
                const m = this.menu;
                const keys = [];
                _.keys = keys;
                if (!m) return [];
                if (m.type === 'field') {
                    const q = norm(m.q);
                    const cur = (this._rule(m.row) || {}).k;
                    const groups = [], by = {};
                    DEFS.forEach(d => {
                        if (q && !norm(d.label).includes(q) && !norm(d.path || d.key).includes(q)) return;
                        const g = d.group || '';
                        if (!by[g]) { by[g] = { id: 'g:' + g, label: g, items: [] }; groups.push(by[g]); }
                        by[g].items.push({ key: d.key, label: d.label, meta: d.path || d.key, icon: this.kindIcon(d),
                                           selected: d.key === cur, multi: false });
                    });
                    // Colunas da própria tabela primeiro; relações agrupadas depois.
                    groups.sort((a, b) => (a.label === '' ? 0 : 1) - (b.label === '' ? 0 : 1));
                    let idx = 0;
                    groups.forEach(g => g.items.forEach(it => { it.idx = idx++; keys.push(it.key); }));
                    return groups;
                }
                const r = this._rule(m.row), d = r && DEF[r.k];
                if (!d) return [];
                const multi = shape(r.k, r.op) === 'opt-multi';
                const sel   = this.selectedVals(r);
                let list    = d.kind === 'dbsearch'
                    ? (this.remote.row === r._id ? this.remote.items : [])
                    : (isArr(d.opts) ? d.opts : []);
                const q = norm(m.q);
                if (q && d.kind !== 'dbsearch') list = list.filter(p => norm(p[1]).includes(q));
                const items = list.slice(0, OPT_CAP).map((p, i) => {
                    keys.push(String(p[0]));
                    return { key: String(p[0]), label: String(p[1]), meta: '', icon: '',
                             selected: sel.includes(String(p[0])), multi, idx: i };
                });
                return [{ id: 'o', label: '', items }];
            },

            menuEmptyText() {
                const m = this.menu;
                if (!m) return '';
                if (m.type === 'opt') {
                    const r = this._rule(m.row), d = r && DEF[r.k];
                    if (d && d.kind === 'dbsearch' && this.remote.row === r._id) {
                        if (this.remote.state === 'short') {
                            const n = d.minLength != null ? Number(d.minLength) : 2;
                            return tr(n === 1 ? 'min_chars_one' : 'min_chars', { n });
                        }
                        if (this.remote.state === 'loading') return tr('loading');
                    }
                }
                const n = this.menuGroups().reduce((a, g) => a + g.items.length, 0);
                return n ? '' : (m.type === 'field' ? tr('no_columns') : tr('nothing_found'));
            },

            menuFootText() {
                const m = this.menu;
                if (!m) return '';
                if (m.type === 'field') return tr('columns_count', { n: DEFS.length });
                const r = this._rule(m.row);
                const n = r && isArr(r.v) ? r.v.length : 0;
                return tr(n === 1 ? 'selected_one' : 'selected_many', { n });
            },

            menuSearch(q) {
                const m = this.menu;
                if (!m) return;
                m.q = q;
                m.active = 0;
                const r = this._rule(m.row);
                if (m.type === 'opt' && r && (DEF[r.k] || {}).kind === 'dbsearch') this._remoteSearch(r, q);
                this.$nextTick(() => { this._positionMenu(); this._icons(); });
            },

            menuKey(e) {
                const m = this.menu;
                if (!m) return;
                const inSearch = e.target && e.target.id === ID + '-menuq';
                const n = _.keys.length;
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (!n) return;
                    const cur = Math.min(Math.max(0, m.active), n - 1);
                    m.active = (cur + (e.key === 'ArrowDown' ? 1 : -1) + n) % n;
                    this.$nextTick(() => {
                        const menu = this._menuEl();
                        const a = menu && menu.querySelector('.mad-dg-cf-opt.is-active');
                        if (!a) return;
                        a.scrollIntoView({ block: 'nearest' });
                        if (!inSearch) a.focus();
                    });
                } else if (e.key === 'Enter' || (e.key === ' ' && !inSearch)) {
                    if (e.metaKey || e.ctrlKey) return;   // ⌘/Ctrl+Enter = Aplicar (popover)
                    e.preventDefault();
                    const k = _.keys[m.active];
                    if (k != null) this.pickKey(k);
                } else if (e.key === 'Tab') {
                    this.closeMenu(false);
                }
            },

            pickKey(key) {
                const m = this.menu;
                if (!m) return;
                const r = this._rule(m.row);
                if (!r) return;
                if (m.type === 'field') { this.setField(r, key); return; }
                const d = DEF[r.k], s = String(key);
                if (d && d.kind === 'dbsearch' && this.remote.row === r._id) {
                    const it = this.remote.items.find(x => x[0] === s);
                    if (it) (_.lbl[r.k] = _.lbl[r.k] || {})[s] = it[1];
                }
                if (shape(r.k, r.op) === 'opt-multi') {
                    const cur = (isArr(r.v) ? r.v : []).map(String);
                    r.v = cur.includes(s) ? cur.filter(x => x !== s) : cur.concat([s]);
                    this.clearInvalid(r);
                    this.$nextTick(() => this._positionMenu());
                    return;
                }
                r.v = s;
                r.d = d && d.kind === 'dbsearch' ? valueLabel(r.k, s) : '';
                this.clearInvalid(r);
                this.closeMenu(true);
            },

            // dbsearch: typeahead no MadDbSearchService com o token assinado da
            // def — o mesmo endpoint/contrato do filtro de coluna `dbsearch`.
            _remoteSearch(r, q) {
                const d = DEF[r.k];
                if (!d) return;
                clearTimeout(_.searchTimer);
                if (_.searchCtl) { try { _.searchCtl.abort(); } catch (e) {} _.searchCtl = null; }
                const min = d.minLength != null ? Number(d.minLength) : 2;
                q = String(q || '').trim();
                if (q.length < min) { this.remote = { row: r._id, state: 'short', items: [] }; return; }
                if (!d.search || typeof _madServiceUrl !== 'function') { this.remote = { row: r._id, state: 'done', items: [] }; return; }
                this.remote = { row: r._id, state: 'loading', items: this.remote.row === r._id ? this.remote.items : [] };
                _.searchTimer = setTimeout(() => {
                    const ctl = new AbortController();
                    _.searchCtl = ctl;
                    fetch(_madServiceUrl('db-search', 'onSearch', { token: d.search, q: q }), { signal: ctl.signal })
                        .then(res => res.json())
                        .then(data => {
                            if (_.searchCtl !== ctl) return;
                            const items = (data && isArr(data.results) ? data.results : [])
                                .map(x => [String(x.value), String(x.text)]);
                            const map = _.lbl[r.k] = _.lbl[r.k] || {};
                            items.forEach(x => { map[x[0]] = x[1]; });
                            this.remote = { row: r._id, state: 'done', items };
                            if (this.menu) this.menu.active = 0;
                            this.$nextTick(() => { this._positionMenu(); this._icons(); });
                        })
                        .catch(err => {
                            if (_.searchCtl === ctl && !(err && err.name === 'AbortError')) {
                                this.remote = { row: r._id, state: 'done', items: [] };
                            }
                        })
                        .finally(() => { if (_.searchCtl === ctl) _.searchCtl = null; });
                }, 300);
            },

            // ── Aplicar / limpar ─────────────────────────────────────────
            // Condição com coluna mas sem valor: vermelho + "Informe um valor" +
            // foco no primeiro campo. Linha sem coluna é descartada em silêncio.
            _validate() {
                const bad = this.draft.rules.filter(r => r.k && !complete(r));
                if (!bad.length) return true;
                const inv = {};
                bad.forEach(r => { inv[r._id] = true; });
                this.invalid = inv;
                this.menu = null;
                this.$nextTick(() => {
                    const el = this._byId(this.domId('v', bad[0]));
                    if (!el) return;
                    el.focus();
                    el.scrollIntoView({ block: 'nearest' });
                });
                return false;
            },

            apply() {
                if (!this.cfOpen) return;   // ⌘Enter num input dispara o do input e o do popover
                if (!this._validate()) return;
                if (specKey(this.draft.match, this.draft.rules) === _.appliedKey) { this.close(); return; }   // nada mudou
                const payload = payloadOf(this.draft.match, this.draft.rules, false);
                // Fecha ANTES: o foco volta ao botão (id estável) e o redesenho
                // do wire o devolve ao botão novo.
                this.close();
                this._call('onCustomFilterApply', [payload]);
            },

            clearAll() {
                this.invalid   = {};
                this.menu      = null;
                this.savedOpen = false;
                this.draft     = { match: this.draft.match, rules: [] };
                if (APPLIED_RULES.length) {
                    this._stash({ draft: { match: 'all', rules: [] } });
                    this._call('onCustomFilterClear', []);
                    return;
                }
                this.$nextTick(() => {
                    const pop = this._pop();
                    const el = pop && (pop.querySelector('.mad-dg-cf-suggest button') || pop.querySelector('.mad-dg-cf-add'));
                    if (el) el.focus();
                });
            },

            // ── Filtros salvos ───────────────────────────────────────────
            toggleSaved() {
                this.savedOpen = !this.savedOpen;
                this.saveForm  = false;
                this.saveError = '';
                this.menu      = null;
                if (!this.savedOpen) return;
                this.$nextTick(() => {
                    this._icons();
                    const pop = this._pop();
                    // Abre para BAIXO do rodapé quando cabe na tela: para cima ele
                    // cobria o "Nenhuma condição ainda". Sem espaço (painel no pé
                    // da tela, celular) continua abrindo para cima.
                    const menu = pop && pop.querySelector('.mad-dg-cf-saved');
                    const foot = pop && pop.querySelector('.mad-dg-cf-foot');
                    if (menu && foot) {
                        const room = window.innerHeight - foot.getBoundingClientRect().bottom;
                        menu.classList.toggle('is-below', room >= menu.offsetHeight + 12);
                    }
                    const el = pop && pop.querySelector('.mad-dg-cf-saved-item, .mad-dg-cf-save-new:not([disabled])');
                    if (el) el.focus();
                });
            },
            savedMeta(v) {
                const n = Number(v.count) || 0;
                const parts = [n === 1 ? tr('condition_one') : tr('active_count', { n })];
                if (v.isDefault) parts.push(tr('opens_with'));
                if (v.shared && v.own) parts.push(tr('shared_badge'));
                return parts.join(' · ');
            },
            applySaved(v) {
                this.close();
                this._call('onSavedFilterApply', [Number(v.id)]);
            },
            toggleDefault(v) {
                this._stash({ saved: true });
                this._call('onSavedFilterDefault', [Number(v.id)]);
            },
            deleteSaved(v) {
                this._stash({ saved: true });
                this._call('onSavedFilterDelete', [Number(v.id)]);
            },
            openSaveForm() {
                if (!this.hasComplete()) return;
                this.saveForm    = true;
                this.saveError   = '';
                this.saveName    = '';
                this.saveShare   = false;
                this.saveDefault = false;
                this.$nextTick(() => this._focus(ID + '-svname'));
            },
            // Salva o RASCUNHO (aplicando-o) numa ida só: onSavedFilterSave
            // recebe o payload como 4º argumento.
            submitSave() {
                if (!this.cfOpen) return;
                const name = String(this.saveName || '').replace(/\s+/g, ' ').trim();
                if (!name || name.length > 120) { this.saveError = tr('name_invalid'); this._focus(ID + '-svname'); return; }
                if (!this.hasComplete()) { this.saveError = tr('nothing_to_save'); return; }
                if (!this._validate()) { this.savedOpen = false; this.saveForm = false; return; }
                const payload = payloadOf(this.draft.match, this.draft.rules, false);
                this._stash({ draft: payload, saved: false });
                this._call('onSavedFilterSave', [name, !!this.saveShare && !!C.canShare, !!this.saveDefault, payload]);
            },

            // ── Contagem ao vivo ─────────────────────────────────────────
            _countKey() {
                const p = payloadOf(this.draft.match, this.draft.rules, false);
                return p.rules.length ? JSON.stringify(p) : '';
            },
            countShown() { return this.countVal !== null && this.countFor !== '' && this.countFor === this._countKey(); },
            countText() {
                const n = this.countVal;
                if (n === null) return '';
                return '· ' + tr(n === 1 ? 'records_one' : 'records_many', { n: fmtN(n) });
            },
            _scheduleCount() {
                clearTimeout(_.countTimer);
                if (!this.cfOpen) return;
                const key = this._countKey();
                if (key === '') { this.countVal = null; this.countFor = ''; return; }
                if (key === this.countFor) return;
                // Rascunho igual ao que está na tela: o total da grid já é a resposta.
                if (key === _.appliedCountKey && TOTAL !== null) { this.countVal = TOTAL; this.countFor = key; return; }
                _.countTimer = setTimeout(() => this._fetchCount(key), 400);
            },
            _fetchCount(key) {
                const wrapper = _.host && _.host.closest('[mad-component]');
                const endpoint = wrapper && wrapper.getAttribute('mad-endpoint');
                if (!endpoint || key !== this._countKey()) return;
                if (_.countCtl) { try { _.countCtl.abort(); } catch (e) {} }
                const ctl = new AbortController();
                _.countCtl = ctl;
                const timer = setTimeout(() => { try { ctl.abort(); } catch (e) {} }, 8000);

                const body = new FormData();
                body.append('mad_state', wrapper.getAttribute('mad-state') || '');
                body.append('mad_id', wrapper.getAttribute('mad-id') || '');
                body.append('mad_action', 'onCustomFilterCount');
                body.append('mad_params', JSON.stringify([JSON.parse(key)]));
                // Mesmos campos de controle que o MadWire manda (seleção, schema do form).
                wrapper.querySelectorAll('input[type="hidden"][name^="__mad_"]').forEach(inp => {
                    if (inp.value !== '' && !inp.closest('form[data-mad-submit]')) body.append(inp.name, inp.value);
                });
                fetch(endpoint, { method: 'POST', body, headers: _madWireHeaders(), signal: ctl.signal })
                    .then(res => (res.ok && res.headers.get('X-Mad-Offline') !== '1') ? res.text() : '')
                    .then(text => {
                        if (_.countCtl !== ctl) return;
                        let data = null;
                        try { data = JSON.parse(text); } catch (e) { data = null; }
                        const op = data && isArr(data.ops) ? data.ops.find(x => x && x.op === 'grid_cf_count') : null;
                        const n  = op && typeof op.count === 'number' ? op.count : null;
                        this.countVal = n;
                        this.countFor = n === null ? '' : key;
                    })
                    .catch(() => { if (_.countCtl === ctl) { this.countVal = null; this.countFor = ''; } })
                    .finally(() => { clearTimeout(timer); if (_.countCtl === ctl) _.countCtl = null; });
            },

            // ── Infra ────────────────────────────────────────────────────
            // Nós SEMPRE pelo clone teleportado DESTA instância, nunca por
            // getElementById: durante o redesenho do wire o clone antigo ainda
            // está no <body> (o Alpine o remove depois) com os MESMOS ids — o
            // primeiro do documento seria o velho, que some logo em seguida.
            _layer()  { const t = _.host && _.host.querySelector('template[x-teleport]'); return t && t._x_teleport ? t._x_teleport : null; },
            _pop()    { const l = this._layer(); return l ? l.querySelector('.mad-dg-cf-pop') : null; },
            _menuEl() { const l = this._layer(); return l ? l.querySelector('.mad-dg-cf-menu') : null; },
            _btn()    { return _.host ? _.host.querySelector('.mad-dg-cf-trigger') : null; },
            _byId(id) { const l = this._layer(); return l && id ? l.querySelector('[id="' + id + '"]') : null; },
            _rule(id) { return this.draft.rules.find(r => r._id === id) || null; },
            _focus(id) {
                const el = this._byId(id);
                if (!el || typeof el.focus !== 'function') return false;
                el.focus();
                return true;
            },
            _call(method, params) {
                // `MadWire` é const global do mad-livewire.js (não vira window.MadWire).
                if (!_.host || typeof MadWire === 'undefined') return;
                return MadWire.call(_.host, method, params || []);
            },
            _stash(extra) {
                _madCfStash.set(ID, Object.assign({
                    at: Date.now(),
                    draft: payloadOf(this.draft.match, this.draft.rules, true),
                    saved: false,
                }, extra || {}));
            },
            _icons() {
                cancelAnimationFrame(_.iconRaf);
                _.iconRaf = requestAnimationFrame(() => {
                    const pop = this._pop();
                    if (pop && pop.querySelector('i[data-lucide]') && typeof _madLucide === 'function') _madLucide();
                });
            },

            // Desktop: ancorado ao botão (abaixo, alinhado à direita; sobe se
            // não couber). Celular (< 640px): bottom sheet — o CSS posiciona.
            _position() {
                const pop = this._pop(), btn = this._btn();
                if (!pop) return;
                const s = pop.style;
                if (this.sheet || !btn) { s.top = ''; s.left = ''; s.bottom = ''; s.width = ''; s.maxHeight = ''; return; }
                const r  = btn.getBoundingClientRect();
                const vw = document.documentElement.clientWidth || window.innerWidth;
                const vh = window.innerHeight;
                const w  = Math.min(700, vw - 24);
                const below = vh - r.bottom - 18, above = r.top - 18;
                s.width = w + 'px';
                s.left  = Math.max(12, Math.min(r.right - w, vw - w - 12)) + 'px';
                if (below < 320 && above > below) {
                    s.top = 'auto';
                    s.bottom = (vh - r.top + 6) + 'px';
                    s.maxHeight = Math.min(560, above) + 'px';
                } else {
                    s.bottom = 'auto';
                    s.top = (r.bottom + 6) + 'px';
                    s.maxHeight = Math.min(560, Math.max(below, 220)) + 'px';
                }
            },

            _positionMenu() {
                const menu = this._menuEl(), pop = this._pop(), m = this.menu;
                if (!menu || !pop || !m) return;
                const r = this._rule(m.row);
                const anchor = r && this._byId(this.domId(m.type === 'field' ? 'f' : 'v', r));
                if (!anchor) return;
                const p  = pop.getBoundingClientRect(), a = anchor.getBoundingClientRect();
                const vh = window.innerHeight;
                const mh = menu.offsetHeight || 300;
                let top  = a.bottom - p.top + 4;
                if (a.bottom + 4 + mh > vh - 8 && a.top - 4 - mh > 8) top = a.top - p.top - mh - 4;
                menu.style.top = top + 'px';
                if (this.sheet) { menu.style.left = '8px'; menu.style.right = '8px'; menu.style.width = 'auto'; return; }
                const mw = Math.max(m.type === 'field' ? 290 : 240, Math.min(360, a.width));
                menu.style.width = mw + 'px';
                menu.style.right = 'auto';
                menu.style.left  = Math.max(8, Math.min(a.left - p.left, p.width - mw - 8)) + 'px';
            },

            _bind() {
                this._unbind();
                const inPop = t => { const pop = this._pop(); return !!(pop && t instanceof Node && pop.contains(t)); };
                const within = (el, t) => !!(el && el.contains(t));
                const onDown = e => {
                    if (!this.cfOpen) return;
                    const t = e.target;
                    if (inPop(t)) {
                        // Dentro do popover: fora da lista aberta (e da âncora dela)
                        // fecha a lista; fora do menu de filtros salvos, fecha o menu.
                        const pop = this._pop();
                        if (this.menu) {
                            const r = this._rule(this.menu.row);
                            const anchor = r && this._byId(this.domId(this.menu.type === 'field' ? 'f' : 'v', r));
                            if (!within(this._menuEl(), t) && !within(anchor, t)) this.menu = null;
                        }
                        if (this.savedOpen && !within(pop.querySelector('.mad-dg-cf-saved'), t)
                            && !within(pop.querySelector('.mad-dg-cf-saved-toggle'), t)) {
                            this.savedOpen = false;
                            this.saveForm  = false;
                        }
                        return;
                    }
                    // Clique fora fecha (desktop; no celular quem fecha é o scrim).
                    if (this.sheet || within(this._btn(), t)) return;
                    this.close(false);
                };
                // Esc com o foco fora do popover (no próprio botão, ou no body
                // depois de um redesenho): mesma ordem — a camada mais interna.
                const onKey = e => { if (e.key === 'Escape' && this.cfOpen && !inPop(e.target)) this.onKey(e); };
                const onResize = () => {
                    if (!this.cfOpen) return;
                    const sh = window.innerWidth < 640;
                    if (sh !== this.sheet) { this.sheet = sh; this.menu = null; }
                    this._lock();
                    this.$nextTick(() => { this._position(); this._positionMenu(); });
                };
                const onScroll = e => {
                    if (!this.cfOpen || this.sheet || inPop(e.target)) return;
                    cancelAnimationFrame(_.raf);
                    _.raf = requestAnimationFrame(() => { this._position(); this._positionMenu(); });
                };
                document.addEventListener('mousedown', onDown, true);
                document.addEventListener('touchstart', onDown, { capture: true, passive: true });
                document.addEventListener('keydown', onKey);
                window.addEventListener('resize', onResize);
                window.addEventListener('scroll', onScroll, true);
                _.off = [
                    () => document.removeEventListener('mousedown', onDown, true),
                    () => document.removeEventListener('touchstart', onDown, { capture: true }),
                    () => document.removeEventListener('keydown', onKey),
                    () => window.removeEventListener('resize', onResize),
                    () => window.removeEventListener('scroll', onScroll, true),
                ];
                // Linhas/listas nascem depois do render (x-for/x-if): religa os ícones.
                const pop = this._pop();
                if (pop && typeof MutationObserver !== 'undefined') {
                    _.obs = new MutationObserver(() => this._icons());
                    _.obs.observe(pop, { childList: true, subtree: true });
                }
                this._lock();
            },

            // `all` = fechou/destruiu: também cancela contagem e busca em voo.
            // Sem ele (religar listeners no _bind) a contagem agendada fica.
            _unbind(all) {
                (_.off || []).forEach(f => { try { f(); } catch (e) {} });
                _.off = [];
                if (_.obs) { _.obs.disconnect(); _.obs = null; }
                cancelAnimationFrame(_.raf);
                document.documentElement.classList.remove('mad-dg-cf-lock');
                if (!all) return;
                clearTimeout(_.countTimer);
                if (_.countCtl) { try { _.countCtl.abort(); } catch (e) {} _.countCtl = null; }
                clearTimeout(_.searchTimer);
                if (_.searchCtl) { try { _.searchCtl.abort(); } catch (e) {} _.searchCtl = null; }
            },

            // Bottom sheet aberto: a página de trás não rola.
            _lock() { document.documentElement.classList.toggle('mad-dg-cf-lock', this.cfOpen && this.sheet); },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madFieldList — field-list reativo (master-detail)
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('alpine:init', () => {
    Alpine.data('madFieldList', (cfg = {}) => {

        // Normaliza rows antes de retornar o objeto Alpine.
        // Alpine precisa dos dados na inicialização (antes de init()).
        const _uuid = () => typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : (Date.now().toString(36) + Math.random().toString(36).slice(2));

        const _rows = (cfg.rows || []).map(r => ({
            __id: r.__id || _uuid(),
            ...r,
        }));

        return {
            rows:      _rows,
            maxRows:   cfg.maxRows   || 0,
            removable: cfg.removable !== false,
            sortable:  cfg.sortable  || false,
            _columns:  cfg.columns   || [],
            _sortInst: null,

            // ── Eventos server-side (on-add / on-remove / on-totalize) ──────
            _onAdd:         cfg.onAdd      || '',
            _onRemove:      cfg.onRemove   || '',
            _onTotalize:    cfg.onTotalize || '',
            _flName:        cfg.name       || '',
            _ready:         false,
            _lastTotals:    {},
            _totalizeTimer: null,

            // ── Ciclo de vida ───────────────────────────────────────────────
            init() {
                if (this.sortable && typeof Sortable !== 'undefined') {
                    const body = this.$el.querySelector('.mad-fl-body');
                    if (body) {
                        this._sortInst = Sortable.create(body, {
                            animation:   150,
                            handle:      '.mad-fl-handle',
                            draggable:   '.mad-fl-row',
                            ghostClass:  'mad-fl-ghost',
                            chosenClass: 'mad-fl-chosen',
                            onEnd:       () => this._syncOrder(body),
                        });
                    }
                }
                // MAD Select e Lucide são inicializados via x-init em cada row

                // Marca ready após próximo tick para que onTotalize/onAdd
                // não disparem no load inicial.
                this.$nextTick(() => {
                    this._lastTotals = this._computeTotals();
                    this._ready = true;
                });

                // Observa mudanças nas rows (deep) e dispara onTotalize quando
                // os valores agregados (sum/count) mudarem. Debounce curto
                // coalesce digitações rápidas.
                if (this._onTotalize) {
                    this.$watch('rows', () => {
                        if (!this._ready) return;
                        clearTimeout(this._totalizeTimer);
                        this._totalizeTimer = setTimeout(() => {
                            const current = this._computeTotals();
                            if (JSON.stringify(current) === JSON.stringify(this._lastTotals)) return;
                            this._lastTotals = current;
                            this._fireFlEvent(this._onTotalize, [current]);
                        }, 150);
                    }, { deep: true });
                }
            },

            // ── Calcula totais das colunas com doSum/doCount ────────────────
            _computeTotals() {
                var totals = {};
                this._columns.forEach(c => {
                    if (c.doSum)   totals[c.field + '_sum']   = this.getSum(c.field);
                    if (c.doCount) totals[c.field + '_count'] = this.getCount();
                });
                return totals;
            },

            // ── Despacha evento para o backend (mesmo padrão do data-mad-fl-change)
            _fireFlEvent(action, params) {
                if (!action) return;
                const wrapper = _madOwnerComponent(this.$el);
                if (!wrapper) return;
                const endpoint = wrapper.getAttribute('mad-endpoint');
                if (!endpoint) return;

                // Em fila com os outros pedidos do componente (ver
                // _madWireQueued): estado e linhas da hora do envio.
                _madWireQueued(wrapper, (wrapper) => {
                const body = new FormData();
                body.append('mad_state',  wrapper.getAttribute('mad-state'));
                body.append('mad_id',     wrapper.getAttribute('mad-id'));
                body.append('mad_action', action);
                body.append('mad_params', JSON.stringify(params || []));

                // mad:model do wrapper (para getData() no PHP)
                wrapper.querySelectorAll('[data-mad-model], [data-mad-model-live]').forEach(function(mel) {
                    const prop = (mel.dataset.madModel || mel.dataset.madModelLive || '').trim();
                    if (prop) body.append('mad_model[' + prop + ']', mel.type === 'checkbox' ? (mel.checked ? '1' : '0') : mel.value);
                });

                const formToken = wrapper.querySelector('input[name="__mad_form"]');
                if (formToken) body.append('__mad_form', formToken.value);

                if (typeof _madCollectFieldLists === 'function') {
                    _madCollectFieldLists(wrapper, body);
                }

                return fetch(endpoint, { method: 'POST', body: body, headers: _madWireHeaders() })
                    .then(r => _madWireJson(r, { source: 'mad-fl-event', method: 'POST' }))
                    .then(data => {
                        if (!data) return;   // erro do servidor já exibido (MadErrorModal)
                        if (data.error) { console.error('[mad-fl-event]', data.error); return; }
                        _madSyncWireState(wrapper, data);
                        _madApplyWireOps(data.ops || [], wrapper);
                    })
                    .catch(err => console.error('[mad-fl-event] Erro de rede:', err));
                });
            },

            // ── Adicionar linha ─────────────────────────────────────────────
            addRow() {
                if (this.maxRows > 0 && this.rows.length >= this.maxRows) return;

                const row = { __id: _uuid() };
                this._columns.forEach(c => { row[c.field] = c.default || ''; });
                this.rows.push(row);

                // MAD Select e Lucide são inicializados via x-init em cada row
                this.$nextTick(() => {
                    const allRows = this.$el.querySelectorAll('.mad-fl-row');
                    const last    = allRows[allRows.length - 1];
                    if (last) {
                        const first = last.querySelector('input:not([type=hidden]):not(.mad-sel-input), select:not(.mad-select-init), textarea, .mad-sel-control');
                        if (first) first.focus();
                    }
                    if (this._ready && this._onAdd) {
                        this._fireFlEvent(this._onAdd, [row, this._flName]);
                    }
                });
            },

            // ── Remover linha (com confirmação) ─────────────────────────────
            removeRow(index) {
                madConfirm('Remover esta linha?').then(ok => {
                    if (!ok) return;
                    // Destroi o MAD Select (e seu dropdown teleportado) antes do Alpine remover o nó
                    const domRows = this.$el.querySelectorAll('.mad-fl-row');
                    if (domRows[index]) {
                        domRows[index].querySelectorAll('select.mad-sel-native').forEach(function(s) {
                            if (s._madSelect) s._madSelect.destroy();
                        });
                    }
                    const removed = this.rows[index];
                    this.rows.splice(index, 1);
                    if (this._onRemove) {
                        this._fireFlEvent(this._onRemove, [removed, index]);
                    }
                });
            },

            // ── Rodar acao customizada (<mad-field-list-action>) ──────────────
            // act:      { method, isNav, navClass, navMethod, navDrawer, navParams,
            //             icon, label, title, variant, confirm, params }
            // row:      dados da linha Alpine (objeto reativo)
            // rowIndex: indice da linha no array rows
            // event:    evento nativo do click (para stopPropagation etc)
            async _runAction(act, row, rowIndex, event) {
                if (event && typeof event.stopPropagation === 'function') {
                    event.stopPropagation();
                }

                // Resolve placeholders {campo} usando valores da row.
                // Ex: { produto_id: '{produto_id}', fixo: 'x' } -> { produto_id: row.produto_id, fixo: 'x' }
                const resolveParams = (params) => {
                    const out = {};
                    if (!params || typeof params !== 'object') return out;
                    for (const k in params) {
                        if (!Object.prototype.hasOwnProperty.call(params, k)) continue;
                        const v = params[k];
                        if (typeof v === 'string' && v.indexOf('{') !== -1) {
                            out[k] = v.replace(/\{(\w+)\}/g, function(_, f) {
                                return row && row[f] != null ? row[f] : '';
                            });
                        } else {
                            out[k] = v;
                        }
                    }
                    return out;
                };

                const run = () => {
                    if (act.isNav) {
                        // Params resolvidos (placeholders {campo} -> row.campo)
                        const resolved = resolveParams(act.navParams);
                        const params   = Object.keys(resolved).length
                                       ? resolved
                                       : { id: (row && row.id != null ? row.id : '') };
                        const navMethod = act.navMethod || 'show';

                        // O PHP assou um TEMPLATE de URL amigavel com
                        // placeholders __MAD_<key>__ — resolve com os params da row.
                        let friendlyUrl = null;
                        if (act.navUrl) {
                            friendlyUrl = act.navUrl;
                            for (const k in params) {
                                if (Object.prototype.hasOwnProperty.call(params, k)) {
                                    friendlyUrl = friendlyUrl.split('__MAD_' + k + '__')
                                                             .join(encodeURIComponent(params[k]));
                                }
                            }
                        }

                        // Overlay (DRAWER/MODAL ou drawer forcado) -> Mad.overlay
                        if (act.navIsOverlay) {
                            if (typeof Mad !== 'undefined' && typeof Mad.overlay === 'function') {
                                return Mad.overlay(act.navClass + '@' + navMethod, params, friendlyUrl);
                            }
                            console.warn('[madFieldList] Mad.overlay nao disponivel');
                            return;
                        }

                        // Pagina INTERNAL: Mad.go com URL amigavel (abre aba
                        // se MadTabs ativo). Sem friendlyUrl (aba antiga), o
                        // proprio Mad.go monta a forma generica /app/cls/method.
                        // static=1 apenas para classes nao-MadComponent (navStatic).
                        if (typeof Mad !== 'undefined' && typeof Mad.go === 'function') {
                            const goParams = act.navStatic
                                ? Object.assign({ static: '1' }, params)
                                : params;
                            return Mad.go(act.navClass, navMethod, goParams, friendlyUrl);
                        }
                        console.warn('[madFieldList] Mad.go nao disponivel');
                        return;
                    }

                    // Action (method) → Mad.call passa row inteira + params extras
                    if (!act.method) {
                        console.warn('[madFieldList] action sem method nem navigate', act);
                        return;
                    }
                    const extra    = resolveParams(act.params);
                    // Copia raza da row (sem o __id interno? mantemos — PHP pode usar pra identificar)
                    const rowCopy  = Object.assign({}, row || {});
                    const callArgs = Object.assign({}, rowCopy, extra);
                    if (typeof Mad !== 'undefined' && typeof Mad.call === 'function') {
                        return Mad.call(act.method, callArgs);
                    }
                    console.warn('[madFieldList] Mad.call nao disponivel');
                };

                // Confirm wrapping
                if (act.confirm) {
                    const opts = act.variant === 'danger' ? { danger: true } : {};
                    try {
                        const ok = await madConfirm(act.confirm, opts);
                        if (!ok) return;
                    } catch (e) {
                        // se madConfirm nao retornar promise, trata como sim
                    }
                }

                return run();
            },

            // ── Soma de coluna numérica ─────────────────────────────────────
            getSum(field) {
                const sum = this.rows.reduce((acc, row) => {
                    const v = parseFloat(String(row[field] || '').replace(',', '.'));
                    return acc + (isNaN(v) ? 0 : v);
                }, 0);
                return sum.toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });
            },

            // ── Contagem de linhas ────────────────────────────────────────────
            getCount() {
                return this.rows.length;
            },

            // ── Sincroniza ordem Alpine após drag Sortable.js ───────────────
            _syncOrder(body) {
                const ids = Array.from(body.querySelectorAll('.mad-fl-row'))
                                 .map(el => el.dataset.rowId);
                const map = {};
                this.rows.forEach(r => { map[r.__id] = r; });
                this.rows = ids.map(id => map[id]).filter(Boolean);
            },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madNumericField — input com máscara numérica pt-BR
// ═══════════════════════════════════════════════════════════════════════════
//
//  Uso:
//    <div x-data="madNumericField({ decimals: 2, min: 0, max: 99999 })">
//        <input type="text" x-ref="input" inputmode="decimal"
//               x-bind:value="display"
//               @focus="onFocus($event)"
//               @blur="onBlur($event)"
//               @keydown.enter="$event.target.blur()">
//        <input type="hidden" :value="rawValue" name="meu_campo">
//    </div>
//
document.addEventListener('alpine:init', () => {
    Alpine.data('madNumericField', (cfg = {}) => {
        const decimals = cfg.decimals ?? 2;
        const min      = cfg.min ?? null;
        const max      = cfg.max ?? null;

        function clamp(v) {
            if (min !== null && v < min) return min;
            if (max !== null && v > max) return max;
            return v;
        }

        // Vazio é estado próprio (fw#201): rawValue '' e display '', o hidden
        // posta '' e o servidor aplica o `empty-as`. Antes o vazio virava 0 —
        // o campo abria "0,00" e apagar gravava 0. Zero digitado continua 0.
        const blank = (v) => v === null || v === undefined || (typeof v === 'string' && v.trim() === '');

        return {
            display:  '',
            rawValue: '',

            init() {
                const inp = this.$refs.input || this.$el.querySelector('input[type="text"]');
                this.setValue(cfg.value ?? (inp ? inp.getAttribute('value') : null));
            },

            onFocus(e) {
                // Mostra o valor numérico cru para facilitar a digitação. O zero
                // aparece como "0" (e fica selecionado): mostrado vazio, sair do
                // campo sem digitar o transformava em vazio.
                e.target.value = this.rawValue === ''
                    ? ''
                    : String(this.rawValue).replace('.', ',');
                this.$nextTick(() => e.target.select());
            },

            // Valor vindo de FORA (op `val` do $this->form->set(), _syncFormInputs
            // do detail-form): atualiza cru, máscara e o input visível de uma vez.
            // Aceita número ou string ('1234.5' cru ou '1.234,50' mascarado).
            setValue(v) {
                if (blank(v)) {
                    this.rawValue = '';
                    this.display  = '';
                } else {
                    const num = typeof v === 'number' ? v : madNumParse(v);
                    this.rawValue = clamp(num);
                    this.display  = madNumFmt(this.rawValue, decimals);
                }
                const inp = this.$refs.input || this.$el.querySelector('input[type="text"]');
                if (inp) inp.value = this.display;
            },

            onBlur(e) {
                if (blank(e.target.value)) {
                    this.rawValue = '';
                    this.display  = '';
                } else {
                    this.rawValue = clamp(madNumParse(e.target.value));
                    this.display  = madNumFmt(this.rawValue, decimals);
                }
                e.target.value = this.display;
                // Dispara change no hidden para que detail-form/field-list capture
                this.$nextTick(() => {
                    const hidden = this.$el.querySelector('input[type="hidden"][name]');
                    if (hidden) {
                        hidden.value = this.rawValue;
                        hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            },
        };
    });

    // ── madMoneyCell — máscara monetária reversa para field-list ──────────
    //
    // Ao digitar, os números entram pela direita e vão empurrando para a esquerda,
    // como numa calculadora de caixa (igual a uma máscara numérica reversa).
    //   Digita "12345" → exibe "123,45" (2 decimais)
    //   Digita "1234567" → exibe "12.345,67"
    //
    Alpine.data('madMoneyCell', (cfg = {}) => {
        const decimals    = cfg.decimals ?? 2;
        const min         = cfg.min ?? null;
        const max         = cfg.max ?? null;
        const field       = cfg.field || '';
        const rowRef      = cfg.row || null;
        // Formatação customizável (mad-sheet): defaults preservam o
        // comportamento histórico do field-list (pt-BR, reverse-fill).
        const decimalSep  = cfg.decimalSep ?? ',';
        const thousandSep = (cfg.thousandSep === undefined || cfg.thousandSep === null) ? '.' : cfg.thousandSep;
        const fillDir     = cfg.fillDirection === 'left' ? 'left' : 'right';
        const allowNeg    = !!cfg.allowNegative;

        function clamp(v) {
            if (!allowNeg && v < 0) v = 0;
            if (min !== null && v < min) return min;
            if (max !== null && v > max) return max;
            return v;
        }

        function escRegex(s) {
            return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Formata inteiro (centavos) para string com separadores configurados
        function fmtFromInt(intVal) {
            var neg = intVal < 0;
            var abs = Math.abs(intVal);
            var str = String(abs).padStart(decimals + 1, '0');
            var intPart  = str.slice(0, str.length - decimals) || '0';
            var decPart  = decimals > 0 ? str.slice(str.length - decimals) : '';
            if (thousandSep) {
                intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
            }
            var result = decimals > 0 ? intPart + decimalSep + decPart : intPart;
            return neg ? '-' + result : result;
        }

        // Converte float para inteiro de centavos
        function floatToInt(v) {
            return Math.round(v * Math.pow(10, decimals));
        }

        // Parser pro modo "left" (digitação normal com separador) — madMoneyField
        function parseLeft(str) {
            if (!str) return 0;
            var s = String(str);
            var neg = allowNeg && s.indexOf('-') >= 0;
            if (thousandSep) s = s.split(thousandSep).join('');
            if (decimalSep !== '.') s = s.split(decimalSep).join('.');
            s = s.replace(/[^\d.]/g, '');
            var dotIdx = s.indexOf('.');
            if (dotIdx >= 0) {
                s = s.slice(0, dotIdx + 1) + s.slice(dotIdx + 1).replace(/\./g, '');
            }
            var v = parseFloat(s) || 0;
            return neg ? -v : v;
        }

        return {
            display: '',
            _intVal: 0, // valor em centavos (inteiro)

            init() {
                var initial = rowRef ? (parseFloat(rowRef[field]) || 0) : 0;
                this._intVal = floatToInt(clamp(initial));
                this.display = fmtFromInt(this._intVal);
                if (this.$refs.input) this.$refs.input.value = this.display;

                // Reage a mudanças externas (ex: x-effect de colunas computed)
                if (rowRef && field) {
                    this.$watch(() => rowRef[field], (val) => {
                        var newInt = floatToInt(clamp(parseFloat(val) || 0));
                        if (newInt !== this._intVal) {
                            this._intVal = newInt;
                            this.display = fmtFromInt(this._intVal);
                            if (this.$refs.input) this.$refs.input.value = this.display;
                        }
                    });
                }
            },

            _onInput(e) {
                if (fillDir === 'left') {
                    // Digitação normal com separador decimal (port do madMoneyField)
                    var val = e.target.value;
                    var allowed = '0-9' + escRegex(decimalSep);
                    if (allowNeg) allowed += '\\-';
                    var clean = val.replace(new RegExp('[^' + allowed + ']', 'g'), '');
                    if (allowNeg) {
                        var hasNeg = clean.charAt(0) === '-';
                        clean = clean.replace(/-/g, '');
                        if (hasNeg) clean = '-' + clean;
                    }
                    if (decimals > 0) {
                        var parts = clean.split(decimalSep);
                        if (parts.length > 2) {
                            clean = parts[0] + decimalSep + parts.slice(1).join('');
                            parts = clean.split(decimalSep);
                        }
                        if (parts[1] && parts[1].length > decimals) {
                            clean = parts[0] + decimalSep + parts[1].slice(0, decimals);
                        }
                    } else {
                        clean = clean.split(decimalSep).join('');
                    }
                    e.target.value = clean;
                    this.display = clean;
                    var f = clamp(parseLeft(clean));
                    this._intVal = floatToInt(f);
                    if (rowRef) rowRef[field] = f;
                    return;
                }

                // Modo right — calculadora reverse-fill (default)
                var typed = e.target.value;
                var raw, neg = false;
                if (allowNeg) {
                    neg = typed.indexOf('-') >= 0;
                    raw = typed.replace(/[^\d]/g, '');
                } else {
                    raw = typed.replace(/\D/g, '');
                }
                var intV = parseInt(raw, 10) || 0;
                if (neg) intV = -intV;
                this._intVal = intV;
                this.display = fmtFromInt(this._intVal);
                e.target.value = this.display;

                // Sincroniza valor float com o Alpine row
                var floatVal = this._intVal / Math.pow(10, decimals);
                if (rowRef) rowRef[field] = clamp(floatVal);
            },

            _onBlur() {
                // Aplica clamp final e reformata
                var floatVal = clamp(this._intVal / Math.pow(10, decimals));
                this._intVal = floatToInt(floatVal);
                this.display = fmtFromInt(this._intVal);
                if (this.$refs.input) this.$refs.input.value = this.display;
                if (rowRef) rowRef[field] = floatVal;

                // Dispara change no hidden para data-mad-fl-change (onChange PHP)
                var inp = this.$refs.input;
                if (inp) {
                    this.$nextTick(function() {
                        var h = inp.parentNode.querySelector('input[type="hidden"][data-mad-fl-change]');
                        if (h) h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
            },
        };
    });

    // ── madMoneyField — input monetário reverso standalone (form field) ─────
    //
    // Mesmo comportamento do madMoneyCell (dígitos entram pela direita),
    // mas como componente de formulário standalone com rawValue/display.
    //   Digita "12345" → exibe "123,45" (2 decimais)
    //   Digita "1234567" → exibe "12.345,67"
    //
    Alpine.data('madMoneyField', (cfg = {}) => {
        const decimals     = cfg.decimals ?? 2;
        const min          = cfg.min ?? null;
        const max          = cfg.max ?? null;
        const decimalSep   = cfg.decimalSep ?? ',';
        const thousandSep  = (cfg.thousandSep === undefined || cfg.thousandSep === null) ? '.' : cfg.thousandSep;
        const fillDir      = cfg.fillDirection === 'left' ? 'left' : 'right';
        const allowNeg     = !!cfg.allowNegative;

        function clamp(v) {
            if (!allowNeg && v < 0) v = 0;
            if (min !== null && v < min) return min;
            if (max !== null && v > max) return max;
            return v;
        }

        function escRegex(s) {
            return String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        // Formata inteiro (centavos) para string com separadores customizaveis
        function fmtFromInt(intVal) {
            var neg = intVal < 0;
            var abs = Math.abs(intVal);
            var str = String(abs).padStart(decimals + 1, '0');
            var intPart  = str.slice(0, str.length - decimals) || '0';
            var decPart  = decimals > 0 ? str.slice(str.length - decimals) : '';
            if (thousandSep) {
                intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSep);
            }
            var result = decimals > 0 ? intPart + decimalSep + decPart : intPart;
            return neg ? '-' + result : result;
        }

        // Converte float para inteiro (escala por casas decimais)
        function floatToInt(v) {
            return Math.round(v * Math.pow(10, decimals));
        }

        // Parser pra modo "left" — usuario digita normalmente com separador
        function parseLeft(str) {
            if (!str) return 0;
            var s = String(str);
            var neg = false;
            if (allowNeg && (s.charAt(0) === '-' || s.indexOf('-') >= 0)) {
                neg = true;
            }
            // Remove separadores de milhar
            if (thousandSep) {
                s = s.split(thousandSep).join('');
            }
            // Troca separador decimal por ponto pra parseFloat
            if (decimalSep !== '.') {
                s = s.split(decimalSep).join('.');
            }
            s = s.replace(/[^\d.]/g, '');
            // So o primeiro ponto e valido
            var dotIdx = s.indexOf('.');
            if (dotIdx >= 0) {
                s = s.slice(0, dotIdx + 1) + s.slice(dotIdx + 1).replace(/\./g, '');
            }
            var v = parseFloat(s) || 0;
            return neg ? -v : v;
        }

        // Vazio é estado próprio (fw#201): rawValue '' e display '' (o
        // placeholder "0,00" aparece), o hidden posta '' e o servidor aplica o
        // `empty-as`. Antes o vazio virava 0: o campo abria "0,00" e apagar
        // gravava 0. Zero digitado continua 0.
        function blank(v) {
            return v === null || v === undefined || (typeof v === 'string' && v.trim() === '');
        }

        return {
            display:  '',
            rawValue: '',
            _intVal:  0,

            init() {
                this.setValue(cfg.value);
            },

            _setBlank() {
                this.rawValue = '';
                this._intVal  = 0;
                this.display  = '';
            },

            // Valor vindo de FORA (op `val` do $this->form->set(), _syncFormInputs
            // do detail-form): recalcula centavos, máscara e o input visível.
            setValue(v) {
                if (blank(v)) {
                    this._setBlank();
                } else {
                    var f = typeof v === 'number' ? v : madNumParse(v);
                    this.rawValue = clamp(f);
                    this._intVal  = floatToInt(this.rawValue);
                    this.display  = fmtFromInt(this._intVal);
                }
                if (this.$refs.input) this.$refs.input.value = this.display;
            },

            _onInput(e) {
                var val = e.target.value;

                if (fillDir === 'left') {
                    // Modo digitacao normal — usuario digita com separador decimal
                    var allowed = '0-9' + escRegex(decimalSep);
                    if (allowNeg) allowed += '\\-';
                    var re = new RegExp('[^' + allowed + ']', 'g');
                    var clean = val.replace(re, '');

                    if (allowNeg) {
                        var hasNeg = clean.charAt(0) === '-';
                        clean = clean.replace(/-/g, '');
                        if (hasNeg) clean = '-' + clean;
                    }
                    if (decimals > 0) {
                        var parts = clean.split(decimalSep);
                        if (parts.length > 2) {
                            clean = parts[0] + decimalSep + parts.slice(1).join('');
                            parts = clean.split(decimalSep);
                        }
                        if (parts[1] && parts[1].length > decimals) {
                            clean = parts[0] + decimalSep + parts[1].slice(0, decimals);
                        }
                    } else {
                        // Sem decimais permitidos — descarta separador
                        clean = clean.split(decimalSep).join('');
                    }
                    e.target.value = clean;
                    if (clean.replace('-', '') === '') {
                        // Apagou tudo (ou só o sinal): vazio, não zero
                        this._setBlank();
                        this.display = clean;
                        return;
                    }
                    this.display  = clean;
                    this.rawValue = clamp(parseLeft(clean));
                    this._intVal  = floatToInt(this.rawValue);
                } else {
                    // Modo right — calculadora reverse-fill (default)
                    var raw, neg = false;
                    if (allowNeg) {
                        neg = val.indexOf('-') >= 0;
                        raw = val.replace(/[^\d]/g, '');
                    } else {
                        raw = val.replace(/\D/g, '');
                    }
                    var intV = parseInt(raw, 10) || 0;
                    // Sem dígito, ou apagando até zerar ("0,01" → backspace):
                    // o campo fica vazio. Digitar 0 no vazio continua zero.
                    var deleting = String(e.inputType || '').indexOf('delete') === 0;
                    if (raw === '' || (deleting && intV === 0)) {
                        this._setBlank();
                        e.target.value = '';
                        return;
                    }
                    if (neg) intV = -intV;
                    this._intVal  = intV;
                    this.display  = fmtFromInt(this._intVal);
                    e.target.value = this.display;
                    this.rawValue = clamp(this._intVal / Math.pow(10, decimals));
                }
            },

            // Calculadora: o dígito entra SEMPRE pela direita. O `_onInput`
            // relê todos os dígitos do campo, então a tecla que cai no meio
            // embaralha o valor. O clique desfaz o select() do focus e põe o
            // cursor onde o mouse caiu, que no `align="right"` é à esquerda
            // do texto: "5" entrava antes do "0,00" e 50012 virava 500.000,12.
            // Cursor sem seleção vai pro fim antes da tecla; seleção continua
            // sendo substituída pelo que se digita.
            _onKeydown(e) {
                if (fillDir !== 'right') return;
                var el = e.target;
                if (el.selectionStart !== el.selectionEnd) return;
                var len = el.value.length;
                if (el.selectionEnd !== len) el.setSelectionRange(len, len);
            },

            _onBlur() {
                if (this.rawValue === '') {
                    this._setBlank();
                } else {
                    var floatVal = clamp(this.rawValue);
                    this._intVal  = floatToInt(floatVal);
                    this.rawValue = floatVal;
                    this.display  = fmtFromInt(this._intVal);
                }
                if (this.$refs.input) this.$refs.input.value = this.display;

                // Dispara change no hidden para detail-form/field-list
                this.$nextTick(() => {
                    var hidden = this.$el.querySelector('input[type="hidden"][name]');
                    if (hidden) {
                        hidden.value = this.rawValue;
                        hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                });
            },
        };
    });

    // ── madNumericCell — input numérico com máscara pt-BR para field-list ──
    //
    // Permite digitar com vírgula como separador decimal.
    // No blur, formata com separador de milhar. No focus, mostra valor simples.
    //   "150,5"  → row[field] = 150.5
    //   "1.234,56" (blur) → row[field] = 1234.56
    //
    Alpine.data('madNumericCell', (cfg = {}) => {
        const maxDec = cfg.decimals ?? 2;
        const min    = cfg.min ?? null;
        const max    = cfg.max ?? null;
        const step   = (cfg.step != null && isFinite(cfg.step) && cfg.step > 0) ? cfg.step : null;
        const field  = cfg.field || '';
        const rowRef = cfg.row || null;

        function clamp(v) {
            if (min !== null && v < min) return min;
            if (max !== null && v > max) return max;
            return v;
        }

        // Snap ao múltiplo de step mais próximo (no blur; digitação livre)
        function snap(v) {
            if (step === null || !v) return v;
            return clamp(Math.round(v / step) * step);
        }

        // Formata float para pt-BR, removendo zeros desnecessários à direita
        function fmtNumeric(val) {
            if (val === 0) return '';
            var s = madNumFmt(val, maxDec);
            // Remove trailing zeros: "150,500" → "150,5"; "150,000" → "150"
            if (s.indexOf(',') !== -1) {
                s = s.replace(/0+$/, '').replace(/,$/, '');
            }
            return s;
        }

        return {
            display: '',

            init() {
                var initial = rowRef ? (parseFloat(rowRef[field]) || 0) : 0;
                initial = clamp(initial);
                this.display = fmtNumeric(initial);
                if (this.$refs.input) this.$refs.input.value = this.display;

                // Reage a mudanças externas (ex: x-effect de colunas computed
                // renderizadas como number no mad-sheet)
                if (rowRef && field) {
                    this.$watch(() => rowRef[field], (val) => {
                        var f = parseFloat(val) || 0;
                        var next = fmtNumeric(clamp(f));
                        if (next !== this.display && document.activeElement !== this.$refs.input) {
                            this.display = next;
                            if (this.$refs.input) this.$refs.input.value = next;
                        }
                    });
                }
            },

            _onInput(e) {
                var val = e.target.value;
                // Só permite dígitos e uma vírgula
                val = val.replace(/[^\d,]/g, '');
                var idx = val.indexOf(',');
                if (idx !== -1) {
                    val = val.slice(0, idx + 1) + val.slice(idx + 1).replace(/,/g, '');
                }
                // Limita casas decimais
                if (idx !== -1 && val.length - idx - 1 > maxDec) {
                    val = val.slice(0, idx + 1 + maxDec);
                }
                e.target.value = val;
                var floatVal = madNumParse(val);
                if (rowRef) rowRef[field] = floatVal;
            },

            _onFocus(e) {
                var val = rowRef ? (parseFloat(rowRef[field]) || 0) : 0;
                if (val !== 0) {
                    // Mostra com vírgula, sem separador de milhar
                    var s = val.toFixed(maxDec);
                    if (s.indexOf('.') !== -1) s = s.replace(/0+$/, '').replace(/\.$/, '');
                    e.target.value = s.replace('.', ',');
                } else {
                    e.target.value = '';
                }
                this.$nextTick(() => e.target.select());
            },

            _onBlur(e) {
                var parsed = snap(clamp(madNumParse(e.target.value)));
                this.display = fmtNumeric(parsed);
                e.target.value = this.display;
                if (rowRef) rowRef[field] = parsed;

                // Dispara change no hidden para data-mad-fl-change (onChange PHP)
                var inp = this.$refs.input;
                if (inp) {
                    this.$nextTick(function() {
                        var h = inp.parentNode.querySelector('input[type="hidden"][data-mad-fl-change]');
                        if (h) h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
            },
        };
    });

    // ── madDiscountCell — campo com máscara reversa + combo suffix ──────────
    //
    // Usado por type='discount' (combo %/R$) e type='combo_input' (combo genérica).
    // Máscara reversa: dígitos entram pela direita como numa máscara numérica reversa.
    //   Digita "1050" → exibe "10,50" (2 decimais)
    //
    // Config:
    //   isDiscount:   true → clamp automático 0–100 quando tipo='%'
    //   defaultOption: valor da 1ª opção do select (usado como fallback)
    //
    Alpine.data('madDiscountCell', (cfg = {}) => {
        const decimals    = cfg.decimals ?? 2;
        const min         = cfg.min ?? null;
        const max         = cfg.max ?? null;
        const field       = cfg.field || '';
        const typeField   = cfg.typeField || (field + '_tipo');
        const rowRef      = cfg.row || null;
        const isDiscount  = cfg.isDiscount ?? false;
        const defaultOpt  = cfg.defaultOption || '';

        function clamp(v, tipo) {
            var lo = min, hi = max;
            if (isDiscount && tipo === '%') {
                if (lo === null) lo = 0;
                if (hi === null) hi = 100;
            }
            if (lo !== null && v < lo) return lo;
            if (hi !== null && v > hi) return hi;
            return v;
        }

        function fmtFromInt(intVal) {
            var neg = intVal < 0;
            var abs = Math.abs(intVal);
            var str = String(abs).padStart(decimals + 1, '0');
            var intPart = str.slice(0, str.length - decimals) || '0';
            var decPart = decimals > 0 ? str.slice(str.length - decimals) : '';
            intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            var result = decimals > 0 ? intPart + ',' + decPart : intPart;
            return neg ? '-' + result : result;
        }

        function floatToInt(v) {
            return Math.round(v * Math.pow(10, decimals));
        }

        return {
            display: '',
            _intVal: 0,
            tipo: '',

            init() {
                if (rowRef && !rowRef[typeField]) rowRef[typeField] = defaultOpt;
                this.tipo = (rowRef && rowRef[typeField]) || defaultOpt;

                var initial = rowRef ? (parseFloat(rowRef[field]) || 0) : 0;
                initial = clamp(initial, this.tipo);
                this._intVal = floatToInt(initial);
                this.display = fmtFromInt(this._intVal);
                if (this.$refs.input) this.$refs.input.value = this.display;
            },

            _onInput(e) {
                var raw = e.target.value.replace(/\D/g, '');
                this._intVal = parseInt(raw, 10) || 0;
                this.display = fmtFromInt(this._intVal);
                e.target.value = this.display;
                var floatVal = this._intVal / Math.pow(10, decimals);
                if (rowRef) rowRef[field] = clamp(floatVal, this.tipo);
            },

            _onBlur() {
                var floatVal = clamp(this._intVal / Math.pow(10, decimals), this.tipo);
                this._intVal = floatToInt(floatVal);
                this.display = fmtFromInt(this._intVal);
                if (this.$refs.input) this.$refs.input.value = this.display;
                if (rowRef) rowRef[field] = floatVal;

                // Dispara change no hidden para data-mad-fl-change (onChange PHP)
                var inp = this.$refs.input;
                if (inp) {
                    this.$nextTick(function() {
                        var h = inp.parentNode.querySelector('input[type="hidden"][data-mad-fl-change]');
                        if (h) h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
            },

            _changeTipo(e) {
                this.tipo = e.target.value;
                if (rowRef) rowRef[typeField] = this.tipo;
                var current = rowRef ? (parseFloat(rowRef[field]) || 0) : 0;
                var clamped = clamp(current, this.tipo);
                if (clamped !== current) {
                    if (rowRef) rowRef[field] = clamped;
                    this._intVal = floatToInt(clamped);
                    this.display = fmtFromInt(this._intVal);
                    if (this.$refs.input) this.$refs.input.value = this.display;
                }

                // Dispara change para recalcular via PHP (mesmo campo, tipo mudou)
                var inp = this.$refs.input;
                if (inp) {
                    this.$nextTick(function() {
                        var h = inp.parentNode.querySelector('input[type="hidden"][data-mad-fl-change]');
                        if (h) h.dispatchEvent(new Event('change', { bubbles: true }));
                    });
                }
            },
        };
    });

    // ── madFileCell — upload de arquivo(s) dentro de field-list ────────────
    //
    // type='file'       → upload único
    // type='multifile'  → upload múltiplo
    //
    // row[field] armazena nome(s) dos arquivos (separados por '|' se multi).
    // File objects ficam em window.__madFlFiles[key] para coleta no submit.
    //
    Alpine.data('madFileCell', function(cfg) {
        cfg = cfg || {};
        var field     = cfg.field || '';
        var rowRef    = cfg.row || null;
        var multi     = cfg.multi || false;
        var maxSizeKB = cfg.maxSize || 0;

        return {
            _newFiles: [],   // File objects (uploads novos)
            _existing: [],   // nomes de arquivos já salvos (vindos do DB)

            init: function() {
                var val = rowRef ? String(rowRef[field] || '') : '';
                if (val) {
                    this._existing = val.split('|').filter(Boolean);
                }
                if (this._existing.length) {
                    this.$nextTick(function() { if (typeof _madLucide === 'function') _madLucide(); });
                }
            },

            allNames: function() {
                return this._existing.concat(this._newFiles.map(function(f) { return f.name; }));
            },

            onSelect: function(e) {
                var selected = Array.from(e.target.files || []);
                if (!selected.length) return;

                // Validar tamanho
                if (maxSizeKB > 0) {
                    for (var i = 0; i < selected.length; i++) {
                        if (selected[i].size > maxSizeKB * 1024) {
                            var maxLabel = maxSizeKB >= 1024
                                ? Math.round(maxSizeKB / 1024) + ' MB'
                                : maxSizeKB + ' KB';
                            if (typeof madToast === 'function') {
                                madToast('Arquivo "' + selected[i].name + '" excede ' + maxLabel, 'warning');
                            }
                            e.target.value = '';
                            return;
                        }
                    }
                }

                if (multi) {
                    this._newFiles = this._newFiles.concat(selected);
                } else {
                    this._newFiles = [selected[0]];
                    this._existing = [];
                }
                this._sync();
                e.target.value = '';
            },

            removeFile: function(index) {
                if (index < this._existing.length) {
                    this._existing.splice(index, 1);
                } else {
                    this._newFiles.splice(index - this._existing.length, 1);
                }
                this._sync();
            },

            _sync: function() {
                var names = this.allNames();
                if (rowRef) rowRef[field] = multi ? names.join('|') : (names[0] || '');

                // Armazena File objects globalmente para coleta no submit
                var flEl = this.$refs.fileInput
                    ? this.$refs.fileInput.closest('[data-mad-fl-name]')
                    : null;
                if (flEl && rowRef) {
                    var flName = flEl.getAttribute('data-mad-fl-name');
                    var key = flName + '__' + rowRef.__id + '__' + field;
                    window.__madFlFiles = window.__madFlFiles || {};
                    if (this._newFiles.length > 0) {
                        window.__madFlFiles[key] = this._newFiles.slice();
                    } else {
                        delete window.__madFlFiles[key];
                    }
                }

                // Re-inicializa ícones Lucide nos novos elementos
                this.$nextTick(function() { if (typeof _madLucide === 'function') _madLucide(); });
            }
        };
    });

    // ── madFlFileModal — célula type="files" do field-list: modal com upload de
    //    MÚLTIPLOS arquivos por linha, persistidos em tabela NETO (disk ou db).
    //    Novos arquivos → window.__madFlFiles[fl__rowId__field] (coletados no submit);
    //    existentes mantidos → hidden __mad_existing_files[fl__rowId__field][] (chave
    //    = path no disk, "id:<n>" no db) para o reconcile do save.
    Alpine.data('madFlFileModal', function(cfg) {
        cfg = cfg || {};
        var field       = cfg.field || '';
        var rowRef      = cfg.row || null;
        var flName      = cfg.flName || '';
        var maxSizeKB   = cfg.maxSize || 0;
        var existingKey = cfg.existingKey || '';

        // ── meta de arquivo p/ miniatura: extensão → isImage / ícone lucide ──
        var IMG_RE = /^(jpg|jpeg|png|gif|webp|svg|bmp|avif)$/i;
        var ICONS  = {
            pdf: 'file-text', doc: 'file-text', docx: 'file-text', txt: 'file-text',
            csv: 'file-spreadsheet', xls: 'file-spreadsheet', xlsx: 'file-spreadsheet',
            zip: 'file-archive', rar: 'file-archive', '7z': 'file-archive',
            mp4: 'file-video', mov: 'file-video', mp3: 'file-audio', wav: 'file-audio',
            json: 'file-code', xml: 'file-code', html: 'file-code',
        };
        function _ext(name) { return (String(name || '').split('.').pop() || '').toLowerCase(); }
        function _meta(name) {
            var ext = _ext(name);
            return { ext: ext, isImage: IMG_RE.test(ext), icon: ICONS[ext] || 'file' };
        }
        function _human(sz) {
            if (sz < 1024) return sz + ' B';
            if (sz < 1048576) return (sz / 1024).toFixed(1) + ' KB';
            return (sz / 1048576).toFixed(1) + ' MB';
        }
        // Arquivo escolhido → item da lista de novos. O identificador fica no
        // próprio File: é ele que está em window.__madFlFiles, e uma célula
        // recriada reencontra o arquivo com o mesmo identificador.
        function _novo(raw) {
            var m = _meta(raw.name);
            if (!raw.__madUid) { try { raw.__madUid = _madUploadUid(); } catch (e) {} }
            return {
                uid: raw.__madUid || _madUploadUid(),
                raw: raw, name: raw.name, ext: m.ext, isImage: m.isImage, icon: m.icon,
                thumb: m.isImage ? URL.createObjectURL(raw) : null,
                sizeText: _human(raw.size),
            };
        }

        return {
            open: false,
            storage: cfg.storage || 'disk',
            files: [],       // novos: { uid, raw(File), name, ext, isImage, thumb, icon, sizeText }
            existing: [],    // já gravados: { id, name, key, url, ext, isImage, thumb, icon }

            init: function() {
                var ex = (rowRef && existingKey && Array.isArray(rowRef[existingKey])) ? rowRef[existingKey] : [];
                this.existing = ex.map(function(e) {
                    var m = _meta(e.name);
                    return {
                        id: e.id, name: e.name, key: e.key, url: e.url || '',
                        ext: m.ext, isImage: m.isImage, icon: m.icon,
                        // imagem existente: usa a própria URL de download como miniatura
                        thumb: (m.isImage && e.url) ? e.url : null,
                    };
                });
                // Célula RECRIADA (a tela foi redesenhada inteira): os arquivos
                // novos desta linha continuam em window.__madFlFiles e vão no
                // próximo Salvar — a célula volta a mostrá-los, com o mesmo
                // identificador. Sem isto ela abria sem eles e o envio era
                // invisível (e o files_saved não tinha de quem tirar o arquivo).
                var pend = (window.__madFlFiles || {})[this.keyFor()];
                if (Array.isArray(pend) && pend.length) {
                    this.files = pend.map(function(raw) { return _novo(raw); });
                }
                this._syncDisplay();
            },

            count: function() { return this.existing.length + this.files.length; },

            keyFor: function() { return flName + '__' + (rowRef ? rowRef.__id : '') + '__' + field; },

            openModal: function() {
                this.open = true;
                this.$nextTick(function() { if (typeof _madLucide === 'function') _madLucide(); });
            },

            onSelect: function(e) {
                var sel = Array.from(e.target.files || []);
                for (var i = 0; i < sel.length; i++) {
                    var f = sel[i];
                    if (maxSizeKB > 0 && f.size > maxSizeKB * 1024) {
                        if (typeof madToast === 'function') {
                            madToast('Arquivo "' + f.name + '" excede o limite', 'warning');
                        }
                        continue;
                    }
                    this.files.push(_novo(f));
                }
                e.target.value = '';
                this._sync();
                this.$nextTick(function() { if (typeof _madLucide === 'function') _madLucide(); });
            },

            removeNew: function(i) {
                var f = this.files[i];
                if (f && f.thumb) { try { URL.revokeObjectURL(f.thumb); } catch (e) {} }
                this.files.splice(i, 1);
                this._sync();
            },
            removeExisting: function(i) { this.existing.splice(i, 1); this._syncDisplay(); },

            // O servidor gravou estes arquivos (op `files_saved` da resposta do
            // Salvar): saem dos "novos" e entram nos já gravados, com a chave
            // que o servidor deu. Sem isto a célula os mandava de novo a cada
            // Salvar — o servidor apagava a linha do envio anterior e criava
            // outra, com outra chave.
            //
            // Casado pelo identificador: o arquivo que o usuário tirou da célula
            // enquanto o Salvar corria não volta — o próximo Salvar o remove do
            // servidor, porque não vai entre os mantidos — e o que ele
            // acrescentou nesse meio tempo continua novo.
            markSaved: function(saved) {
                var self = this, changed = false;
                (saved || []).forEach(function(s) {
                    if (!s || !s.uid || !s.key) return;
                    var i = self.files.findIndex(function(f) { return f && f.uid === s.uid; });
                    if (i === -1) return;
                    self.files.splice(i, 1);
                    changed = true;
                    // Célula redesenhada: o servidor já a entregou com o arquivo gravado.
                    if (self.existing.some(function(e) { return e.key === s.key; })) return;
                    var m = _meta(s.name);
                    self.existing.push({
                        id: s.id, name: s.name, key: s.key, url: s.url || '',
                        ext: m.ext, isImage: m.isImage, icon: m.icon,
                        thumb: (m.isImage && s.url) ? s.url : null,
                    });
                });
                if (!changed) return;
                this._sync();
                this.$nextTick(function() { if (typeof _madLucide === 'function') _madLucide(); });
            },

            _sync: function() {
                window.__madFlFiles = window.__madFlFiles || {};
                var key = this.keyFor();
                if (this.files.length) {
                    window.__madFlFiles[key] = this.files.map(function(f) { return f.raw; });
                } else {
                    delete window.__madFlFiles[key];
                }
                this._syncDisplay();
            },

            // Valor de exibição da célula (também evita a linha ser filtrada como vazia).
            _syncDisplay: function() {
                if (rowRef) rowRef[field] = this.count() ? (this.count() + ' arquivo(s)') : '';
            },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madDbSeek — campo de busca com modal de listagem
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('alpine:init', () => {
    Alpine.data('madDbSeek', (cfg) => ({
        name: cfg.name || '',
        selectedId: cfg.value || '',
        displayText: cfg.text || '',
        auxiliaries: cfg.auxiliaries || {},
        create: cfg.create || null,
        modalOpen: false,

        init() {
            var self = this;
            // Registro cadastrado pelo "Novo" (`create`): o form alvo salva e o
            // returnToCombo() dele manda o op combo_add_option, que o Mad.applyOps
            // entrega AQUI (evento no próprio campo) quando o alvo é um seek.
            this.$el.addEventListener('mad-seek-created', function(e) {
                var d = e.detail || {};
                self.applyCreated(d.id, d.label);
            });
            window.addEventListener('mad:seek-selected', function(e) {
                if (e.detail.name !== self.name) return;
                self.selectedId  = String(e.detail.id || '');
                self.displayText = e.detail.display || '';
                self.modalOpen   = false;

                // Preenche campos auxiliares (auxValues resolvidos pelo PHP)
                var auxValues = e.detail.auxValues || {};
                Object.keys(auxValues).forEach(function(targetField) {
                    var val = auxValues[targetField] !== undefined ? String(auxValues[targetField]) : '';
                    // Escopo + máscara, mesmo contrato do `<fill>`: campo auxiliar
                    // de dbseek é tipicamente preço/valor (money) e o seek pode
                    // estar dentro do sub-form de um detalhe, ao lado de um campo
                    // homônimo do mestre.
                    var el  = _madScopedField(self.$el, targetField)
                           || document.getElementById(targetField);
                    if (el) {
                        if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                            el.value = val;
                            _madSyncMaskedField(el);
                            el.dispatchEvent(new Event('input', { bubbles: true }));
                        } else {
                            el.textContent = val;
                        }
                    }
                });

                // Dispara change no hidden input para mad:model
                self.$nextTick(function() {
                    var hidden = self.$el.querySelector('input[type="hidden"][name="' + self.name + '"]');
                    if (hidden) hidden.dispatchEvent(new Event('change', { bubbles: true }));
                    // Re-inicializa ícones Lucide
                    if (typeof _madLucide === 'function') _madLucide();
                });
            });
        },

        openModal() {
            this.modalOpen = true;
            // Re-inicializa ícones Lucide dentro da modal
            var self = this;
            this.$nextTick(function() {
                if (window.lucide) try { lucide.createIcons(); } catch(e) {}
            });
        },

        close() {
            this.modalOpen = false;
        },

        // "Novo": abre o cadastro por cima da tela com a origem ASSINADA pelo PHP
        // (MadNoResultsHelper::createAction) — o mesmo contrato do "Sem
        // resultados → Cadastrar novo" dos combos (fireCreate). O modal de busca
        // fecha antes (o 4.0 fechava a janela de busca ao abrir o cadastro).
        openCreate() {
            var c = this.create;
            if (!c || !window.Mad || typeof Mad.go !== 'function') return;
            var params = { _field_name: this.name };
            if (c.token) params._mad_origin = c.token;
            var cmp = this.$root && this.$root.closest ? this.$root.closest('[mad-component]') : null;
            if (cmp) params._mad_origin_cmp = cmp.getAttribute('mad-id') || '';
            this.modalOpen = false;
            var url = (c.url && typeof _madUrlWithParams === 'function') ? _madUrlWithParams(c.url, params) : undefined;
            Mad.go(c.class, c.method || 'show', params, url);
        },

        // O registro novo volta selecionado: chave + texto na hora e, pelo mesmo
        // "Selecionar" do grid do modal (onSelect), o texto da coluna de exibição
        // e os <mad-fill>.
        applyCreated(id, label) {
            if (id === undefined || id === null || String(id) === '') return;
            var self = this;
            this.selectedId  = String(id);
            this.displayText = label ? String(label) : String(id);
            this.modalOpen   = false;
            this.$nextTick(function() {
                var hidden = self.$el.querySelector('input[type="hidden"][name="' + self.name + '"]');
                if (hidden) hidden.dispatchEvent(new Event('change', { bubbles: true }));
            });
            var grid = this.$el.querySelector('.mad-modal-body [mad-component]');
            if (grid && typeof MadWire !== 'undefined' && MadWire && typeof MadWire.call === 'function') {
                try { MadWire.call(grid, 'onSelect', [String(id)]); } catch (e) {}
            }
        },

        clear() {
            this.selectedId  = '';
            this.displayText = '';

            // Limpa campos auxiliares (chaves = nome do campo na tela)
            var _root = this.$el;
            Object.keys(this.auxiliaries).forEach(function(targetField) {
                var el = _madScopedField(_root, targetField)
                      || document.getElementById(targetField);
                if (el) {
                    if (el.tagName === 'INPUT' || el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') {
                        el.value = '';
                        _madSyncMaskedField(el);   // money/numeric volta a 0,00 na tela
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                    } else {
                        el.textContent = '';
                    }
                }
            });

            // Dispara change no hidden input
            var hidden = this.$el.querySelector('input[type="hidden"][name="' + this.name + '"]');
            if (hidden) hidden.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }));
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madDetailForm — sub-formulário detalhe com listagem
// ═══════════════════════════════════════════════════════════════════════════
//
//  Similar ao field-list, mas com formulário dedicado + listagem (grid).
//  Reutiliza data-mad-fl-name para coleta via _madCollectFieldLists.
//
//  Modos do form: inline | modal | drawer
//  Dados: rows[] sincronizado com MadForm->fields do pai via _madCollectFieldLists.
//
document.addEventListener('alpine:init', () => {
    Alpine.data('madDetailForm', (cfg = {}) => {

        const _uuid = () => typeof crypto.randomUUID === 'function'
            ? crypto.randomUUID()
            : (Date.now().toString(36) + Math.random().toString(36).slice(2));

        // `field` de coluna e TEMPLATE DE EXIBICAO (`{col}`, `{rel->col}`,
        // `"{a}/{b}"`), nao chave de linha. A linha carregada do servidor traz o
        // template resolvido na propria chave (GridRenderHelpers::normalizeRows /
        // MadForm::autoLoadDetailRows); a linha criada AQUI so tem as chaves dos
        // inputs do sub-form — o `name` do campo, nu. Por isso a ESCRITA (edicao
        // inline, semente do form, coluna calculada) usa a chave nua; a LEITURA da
        // celula tenta as duas, numa expressao que o Blade emite inline.
        // Gemeo PHP: GridRenderHelpers::rowDataKey / rowValueJs.
        const _isChain  = (f) => String(f).indexOf('->') !== -1;
        const _dataKey  = (f) => {
            const s = String(f || '').trim();
            if (!s || s.indexOf('{') === -1 || _isChain(s)) return f;
            const m = /^\{([^{}]+)\}$/.exec(s);
            return m ? m[1].trim() : f;
        };

        const _rows = (cfg.rows || []).map(r => ({
            __id: r.__id || _uuid(),
            ...r,
        }));

        return {
            rows:      _rows,
            editIndex: -1,             // -1 = novo, >= 0 = editando row[index]
            formData:  {},             // valores atuais do sub-form
            columns:   cfg.columns || [],
            mode:      cfg.mode || 'inline',
            name:      cfg.name || 'detail',
            beforeAdd:    cfg.beforeAdd || '',    // método PHP para validação server-side
            beforeDelete: cfg.beforeDelete || '', // método PHP para validação server-side ao deletar
            perPage:      cfg.perPage || 0,
            page:      1,
            _bound:    false,
            _wrapper:  null,

            init() {
                // Wrapper do MadComponent resolvido AQUI, onde `$el` e a raiz do
                // detail-form ([data-mad-df-name]) e ainda esta dentro do
                // [mad-component]. Em `mode="drawer"` o <x-drawer> teleporta o
                // sub-form pro <body>, entao dentro do @click do botao "Adicionar"
                // o `$el` e o BOTAO — fora do wrapper — e todo closest() volta
                // null. Mesmo idioma ja usado no madCalendar (`_wrapper` no init).
                this._wrapper = this._ownerOf(this.$el);
                this._resetForm();
                // Bind pode precisar aguardar modal/drawer renderizar
                this.$nextTick(() => this._bindFormInputs());
            },

            // [mad-component] dono de `el`. O detail-form pode morar num painel
            // TELEPORTADO da tela (<mad-drawer>/<mad-modal>): ali o closest()
            // morre na raiz do clone no <body>, e o MadWire atravessa o
            // teleport de volta até o componente (mesmo critério do mad:click).
            _ownerOf(el) { return _madOwnerComponent(el); },

            // Wrapper do componente, resiliente ao teleport do drawer.
            _getComponentWrapper() {
                if (this._wrapper && this._wrapper.isConnected) return this._wrapper;
                const root = document.querySelector('[data-mad-df-name="' + this.name + '"]');
                this._wrapper = root ? this._ownerOf(root) : null;
                return this._wrapper;
            },

            // Chave de DADOS de uma coluna (a leitura da celula e emitida inline
            // pelo Blade — GridRenderHelpers::rowValueJs).
            dataKey(field) { return _dataKey(field); },

            // Agregado do rodape (<mad-col total="…">) — gemeo do
            // GridRenderHelpers::computeTotals: valor nao-numerico conta como 0
            // (entra na media, como na grid). `read` e a leitura da celula que o
            // Blade emite (rowValueJs), entao chain e template resolvem igual.
            _agg(fn, read) {
                if (fn === 'count') return this.rows.length;
                const nums = this.rows.map(r => { const n = parseFloat(read(r)); return isNaN(n) ? 0 : n; });
                if (fn === 'sum') return nums.reduce((s, n) => s + n, 0);
                if (!nums.length) return 0;
                if (fn === 'avg') return nums.reduce((s, n) => s + n, 0) / nums.length;
                if (fn === 'min') return Math.min.apply(null, nums);
                if (fn === 'max') return Math.max.apply(null, nums);
                return 0;
            },

            // ── Colunas que SO o servidor resolve ───────────────────────
            // Caminho de relacionamento (`{produto->nome}`) e template composto
            // (`{a} - {b}`): o navegador nao percorre relacao. A linha vinda do
            // banco ja traz o template resolvido na propria chave; a criada aqui,
            // nao — a celula ficava VAZIA (e STALE na edicao, quando a FK muda).
            // Token simples fica de fora: esse ja chega na chave nua.
            // Chain NUA (`produto->nome`, sem chaves) tambem entra: a linha do
            // banco ja a resolvia (detectRenderFields), a criada aqui ficava
            // com a celula "Produto" vazia ate salvar.
            _displayCols() {
                return (this.columns || [])
                    .map(c => c.field)
                    .filter(f => (String(f).indexOf('{') !== -1 || _isChain(f)) && _dataKey(f) === f);
            },

            // Pede ao servidor os valores de apresentacao de UMA linha.
            // Resposta assincrona: op `df_display`, casada por __id.
            _resolveDisplay(row) {
                const cols = this._displayCols();
                if (!cols.length || !row) return;

                const w = this._getComponentWrapper();
                if (!w || typeof MadWire === 'undefined') return;

                // Limpa o valor herdado do editRow ANTES do round-trip: mostrar o
                // produto antigo depois de trocar a FK e pior que mostrar vazio.
                // DEPOIS do guard de proposito: sem round-trip possivel, apagar
                // deixa a celula VAZIA em vez de apenas desatualizada — foi
                // exatamente o que a 5.68.0 fez na edicao em mode="drawer".
                cols.forEach(f => { delete row[f]; });

                try {
                    MadWire.call(w, '_madDfResolveDisplay', [{
                        detail: this.name,
                        row_id: row.__id,
                        row:    { ...row },
                    }]);
                } catch (e) {
                    console.error('[madDetailForm] _resolveDisplay:', e);
                }
            },

            // ── Auto-bind: conecta inputs [name] ao formData ────────────

            _bindFormInputs() {
                if (this._bound) return;
                const container = this._getFieldsContainer();
                if (!container) return;
                this._bound = true;

                container.querySelectorAll('[name]').forEach(inp => {
                    const field = inp.getAttribute('name');
                    if (!field || field.startsWith('__')) return;

                    // Listener bidirecional: input → formData
                    const sync = () => {
                        if (inp.type === 'checkbox') {
                            this.formData[field] = inp.checked ? (inp.value || '1') : '';
                        } else {
                            this.formData[field] = inp.value;
                        }
                    };
                    inp.addEventListener('input', sync);
                    inp.addEventListener('change', sync);
                });
            },

            _getFieldsContainer() {
                // Procura primeiro no próprio elemento, depois em modais/drawers
                let c = this.$el.querySelector('[data-df-fields]');
                if (c) return c;
                // Modal/drawer podem estar fora do $el (portaled)
                c = document.querySelector('[data-df-fields][data-df-name="' + this.name + '"]');
                return c;
            },

            // Popula inputs do form a partir do formData (edit/reset)
            _syncFormInputs() {
                const container = this._getFieldsContainer();
                if (!container) return;

                container.querySelectorAll('[name]').forEach(inp => {
                    const field = inp.getAttribute('name');
                    if (!field || field.startsWith('__')) return;

                    const val = this.formData[field] ?? '';

                    // ── madNumericField: hidden input com rawValue/display no Alpine
                    if (inp.type === 'hidden') {
                        const wrap = inp.closest('[x-data]');
                        if (wrap && window.Alpine) {
                            try {
                                const ad = Alpine.$data(wrap);

                                // madNumericField / madMoneyField — cada um sabe
                                // formatar com as SUAS casas decimais (antes aqui
                                // era 2 fixo, e campo com :decimals="3" voltava
                                // arredondado ao reabrir a linha pra editar).
                                if (ad && typeof ad.setValue === 'function' && 'rawValue' in ad) {
                                    ad.setValue(val);
                                    return;
                                }

                                // madDbSeek
                                if (ad && 'selectedId' in ad) {
                                    ad.selectedId = String(val);
                                    // displayText precisa ser passado separadamente se disponível
                                    const displayField = field.replace(/_id$/, '_nome')
                                                      || field.replace(/_id$/, '_name');
                                    if (this.formData[displayField]) {
                                        ad.displayText = this.formData[displayField];
                                    }
                                    return;
                                }
                            } catch(e) {}
                        }
                        // Hidden genérico: apenas seta valor
                        inp.value = val;
                        inp.dispatchEvent(new Event('change', { bubbles: true }));
                        return;
                    }

                    // ── MAD Select (select, dbcombo, multi-search, unique-search)
                    if (inp._madSelect) {
                        inp._madSelect.setValue(val, true);
                        return;
                    }

                    // ── TinyMCE (html-editor-field)
                    if (inp.tagName === 'TEXTAREA' && typeof tinymce !== 'undefined') {
                        const ed = tinymce.get(inp.id);
                        if (ed) {
                            ed.setContent(val || '');
                            inp.value = val || '';
                            return;
                        }
                    }

                    // ── Checkbox / Switch
                    if (inp.type === 'checkbox') {
                        inp.checked = !!val && val !== '0' && val !== '';
                    // ── Select padrão
                    } else if (inp.tagName === 'SELECT') {
                        inp.value = val;
                    // ── Inputs comuns (text, number, date, email, etc.)
                    } else {
                        inp.value = val;
                        // Date/datetime: o `name` está no input VISÍVEL e a
                        // linha guarda a data do BANCO. Sem avisar o picker, o
                        // `input` abaixo remascarava `2026-09-11` como
                        // `20/26/0911` — e o picker adotava essa data.
                        _madSyncMaskedField(inp);
                    }
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                });
            },

            _resetForm() {
                this.formData = {};
                this.columns.forEach(c => {
                    const k = _dataKey(c.field);
                    // Coluna de chain e display-only: nao e campo do sub-form.
                    if (String(k).indexOf('{') !== -1) return;
                    this.formData[k] = c.default ?? '';
                });
                this.editIndex = -1;
                this.$nextTick(() => { this._syncFormInputs(); this._clearFileInputs(); });
            },

            // ── Collect: lê valores atuais de todos os inputs do form ──
            // Necessário porque alguns campos (madNumericField) só atualizam
            // o formData no blur, e o usuário pode clicar "Adicionar" sem sair do campo.
            _collectFormData() {
                const container = this._getFieldsContainer();
                if (!container) return;

                container.querySelectorAll('[name]').forEach(inp => {
                    const field = inp.getAttribute('name');
                    if (!field || field.startsWith('__')) return;

                    // Inputs de arquivo: o valor (.value = "C:\fakepath\…") não serve.
                    // O File real é capturado em addOrUpdate (_captureRowFiles); aqui
                    // preservamos o formData existente (nome do arquivo na edição).
                    if (inp.type === 'file') return;

                    // madNumericField: lê rawValue do Alpine
                    if (inp.type === 'hidden') {
                        const wrap = inp.closest('[x-data]');
                        if (wrap && window.Alpine) {
                            try {
                                const ad = Alpine.$data(wrap);
                                if (ad && 'rawValue' in ad) {
                                    // Força o onBlur se o input visível está focado
                                    const vis = wrap.querySelector('input[type="text"]');
                                    if (vis && document.activeElement === vis) {
                                        vis.blur();
                                    }
                                    this.formData[field] = ad.rawValue;
                                    return;
                                }
                            } catch(e) {}
                        }
                    }

                    if (inp.type === 'checkbox') {
                        this.formData[field] = inp.checked ? (inp.value || '1') : '';
                    } else if (inp._madSelect) {
                        this.formData[field] = inp._madSelect.getValue();
                    } else {
                        this.formData[field] = inp.value;
                    }
                });
            },

            // ── Evaluate: computa campos calculados client-side ────

            _applyEvaluates(row) {
                this.columns.forEach(col => {
                    if (!col.evaluate) return;
                    let expr = col.evaluate.replace(/\{([^}]+)\}/g, (whole, key) => {
                        key = key.trim();
                        const raw = (key in row) ? row[key] : row[whole];
                        const v = parseFloat(raw);
                        return isNaN(v) ? 0 : v;
                    });
                    // Sanitiza: só dígitos, ponto, operadores, parênteses, espaços
                    expr = expr.replace(/[^0-9.+\-*/() ]/g, '');
                    try {
                        const result = Function('"use strict"; return (' + expr + ')')();
                        row[_dataKey(col.field)] = (typeof result === 'number' && isFinite(result)) ? result : 0;
                    } catch(e) { row[_dataKey(col.field)] = 0; }
                });
            },

            // ── Operações CRUD ──────────────────────────────────────

            addOrUpdate() {
                // Limpa erros de validação anteriores do detail-form.
                // mode=drawer teleporta o sub-form pro body — limpa também o
                // fields-container resolvido (pode estar fora do $el).
                [this.$el, this._getFieldsContainer()].forEach(c => {
                    if (!c) return;
                    c.querySelectorAll('.mad-input-error').forEach(el => el.classList.remove('mad-input-error'));
                    c.querySelectorAll('.mad-error[data-field-error]').forEach(el => { el.textContent = ''; el.classList.remove('mad-error'); });
                });

                this._collectFormData();
                const row = { ...this.formData };
                this._applyEvaluates(row);

                // Se tem before-add handler, faz AJAX para validação server-side
                if (this.beforeAdd) {
                    // Mesmo teleport do drawer: com `$el` = botao fora do wrapper,
                    // o `w` vinha null e o `return` DESCARTAVA a linha em silencio
                    // — nada era adicionado e nenhum request saia.
                    const w = this._getComponentWrapper();
                    if (w) {
                        MadWire.call(w, this.beforeAdd, [row, this.editIndex]);
                        return; // A resposta vem via op df_add
                    }
                    console.error('[madDetailForm] before-add="' + this.beforeAdd
                        + '": wrapper [mad-component] nao encontrado no detail "'
                        + this.name + '" — inserindo client-side (fail-open).');
                }

                // Sem before-add: insere direto (comportamento padrão client-side)
                const rowId = this.editIndex >= 0 ? this.rows[this.editIndex].__id : _uuid();
                row.__id = rowId;
                // Captura File objects dos inputs de arquivo do sub-form, chaveados
                // por linha → coletados no submit como mad_fl_files[df__rowId__field].
                this._captureRowFiles(row, rowId);
                let slot;
                if (this.editIndex >= 0) {
                    this.rows[this.editIndex] = row;
                    slot = this.rows[this.editIndex];
                } else {
                    this.rows.push(row);
                    slot = this.rows[this.rows.length - 1];
                }
                // Coluna de outra tabela: so o servidor resolve. A linha ja esta
                // na listagem; o patch chega depois pelo op `df_display`. Usa o
                // elemento do array (o proxy reativo do Alpine), nao o literal —
                // senao o delete/patch nao repinta a celula.
                this._resolveDisplay(slot);
                this._resetForm();
                this._closeFormOverlay();
                this.$nextTick(() => { if (typeof _madLucide === 'function') _madLucide(); });
            },

            // Harvest dos <input type="file"> do sub-form para window.__madFlFiles,
            // chaveado por (detail__rowId__field). Espelha madFileCell do field-list,
            // permitindo que MadForm->save() persista o arquivo por-linha.
            _captureRowFiles(row, rowId) {
                const container = this._getFieldsContainer();
                if (!container) return;
                const dfName = this.name;
                container.querySelectorAll('input[type="file"]').forEach(inp => {
                    let field = inp.getAttribute('name') || '';
                    if (!field) return;
                    field = field.replace(/\[\]$/, '');
                    if (field.startsWith('__')) return;
                    const files = inp.files ? Array.from(inp.files) : [];
                    if (!files.length) return; // sem arquivo novo → mantém valor existente
                    const multi = !!inp.multiple;
                    window.__madFlFiles = window.__madFlFiles || {};
                    window.__madFlFiles[dfName + '__' + rowId + '__' + field] = multi ? files.slice() : [files[0]];
                    row[field] = multi ? files.map(f => f.name).join('|') : files[0].name;
                    inp.value = ''; // evita re-post no $_FILES do master / vazamento p/ próxima linha
                });
            },

            // Limpa inputs de arquivo + estado Alpine do madFileField após reset.
            _clearFileInputs() {
                const container = this._getFieldsContainer();
                if (!container) return;
                container.querySelectorAll('input[type="file"]').forEach(inp => {
                    inp.value = '';
                    const wrap = inp.closest('[x-data]');
                    if (wrap && window.Alpine) {
                        try {
                            const ad = Alpine.$data(wrap);
                            if (ad && 'file' in ad) { ad.file = null; ad.removed = false; }
                        } catch(e) {}
                    }
                });
            },

            editRow(index) {
                this.editIndex = index;
                this.formData = { ...this.rows[index] };
                this.$nextTick(() => {
                    this._bindFormInputs(); // garante bind em modal/drawer lazy
                    this._syncFormInputs();
                    this._openFormOverlay();
                    // Foca primeiro input
                    const container = this._getFieldsContainer();
                    if (container) {
                        const first = container.querySelector('input:not([type="hidden"]), select, textarea');
                        if (first) first.focus();
                    }
                });
            },

            deleteRow(index) {
                // Se tem before-delete handler, faz AJAX para validação server-side
                if (this.beforeDelete) {
                    const row = this.rows[index] || {};
                    // Mesmo teleport do before-add: `$el` dentro de uma gaveta
                    // não acha o wrapper com closest() e a exclusão sumia calada.
                    const w = this._getComponentWrapper();
                    if (w) MadWire.call(w, this.beforeDelete, [row, index]);
                    else console.error('[madDetailForm] before-delete="' + this.beforeDelete
                        + '": wrapper [mad-component] nao encontrado no detail "' + this.name + '".');
                    return; // A resposta vem via op df_delete
                }

                // Sem before-delete: remove direto
                this.rows.splice(index, 1);
                if (this.editIndex === index) this._resetForm();
                else if (this.editIndex > index) this.editIndex--;
            },

            cancelEdit() {
                this._resetForm();
                this._closeFormOverlay();
            },

            // ── Modal/Drawer helpers ────────────────────────────────

            _openFormOverlay() {
                if (this.mode === 'modal') {
                    window.dispatchEvent(new CustomEvent('madmodal',
                        { detail: { name: 'df_' + this.name, action: 'open' } }));
                } else if (this.mode === 'drawer') {
                    window.dispatchEvent(new CustomEvent('maddrawer',
                        { detail: { name: 'df_' + this.name, action: 'open' } }));
                }
            },

            _closeFormOverlay() {
                if (this.mode === 'modal') {
                    window.dispatchEvent(new CustomEvent('madmodal',
                        { detail: { name: 'df_' + this.name, action: 'close' } }));
                } else if (this.mode === 'drawer') {
                    window.dispatchEvent(new CustomEvent('maddrawer',
                        { detail: { name: 'df_' + this.name, action: 'close' } }));
                }
            },

            openNew() {
                this._resetForm();
                this.$nextTick(() => {
                    this._bindFormInputs();
                    this._openFormOverlay();
                    const container = this._getFieldsContainer();
                    if (container) {
                        const first = container.querySelector('input:not([type="hidden"]), select, textarea');
                        if (first) setTimeout(() => first.focus(), 150);
                    }
                });
            },

            // ── Paginação client-side ───────────────────────────────

            get totalPages() {
                if (!this.perPage || this.perPage <= 0) return 1;
                return Math.ceil(this.rows.length / this.perPage);
            },

            get pagedRows() {
                if (!this.perPage || this.perPage <= 0) return this.rows;
                const start = (this.page - 1) * this.perPage;
                return this.rows.slice(start, start + this.perPage);
            },

            goToPage(p) {
                if (p >= 1 && p <= this.totalPages) this.page = p;
            },

            // ── Custom actions (chamam método no MadComponent pai) ──

            callAction(method, row, index) {
                const w = this.$el.closest('[mad-component]');
                if (w) MadWire.call(w, method, [row, index]);
            },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madFullCalendar — FullCalendar v5.5.1 reativo
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('alpine:init', () => {
    Alpine.data('madFullCalendar', (cfg = {}) => {

        // Formata Date → 'YYYY-MM-DD HH:mm:ss'
        function _fmtDate(d) {
            if (!d) return '';
            const dt = (d instanceof Date) ? d : new Date(d);
            const pad = (n) => String(n).padStart(2, '0');
            return dt.getFullYear() + '-' + pad(dt.getMonth() + 1) + '-' + pad(dt.getDate())
                 + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes()) + ':' + pad(dt.getSeconds());
        }

        let _calendar = null;
        let _dragFlag  = false;
        let _resizeFlag = false;
        let _overlayCloseHandler = null;
        let _refetchTimer = null;

        return {
            init() {
                const el      = this.$el;
                const calEl   = this.$refs.calendarEl;
                const wrapper = el.closest('[mad-component]');

                // ── Opções base ────────────────────────────────────
                const opts = {
                    initialView:  cfg.defaultView || 'dayGridMonth',
                    initialDate:  cfg.currentDate || undefined,
                    locale:       cfg.locale || 'pt-br',
                    editable:     cfg.editable || false,
                    eventStartEditable:  cfg.movable !== false,
                    eventDurationEditable: cfg.resizable !== false,
                    slotMinTime:  cfg.minTime || '00:00:00',
                    slotMaxTime:  cfg.maxTime || '24:00:00',
                    hiddenDays:   cfg.hiddenDays || [],
                    allDaySlot:   false,
                    // 'standard', não 'bootstrap': o tema bootstrap desenha
                    // anterior/próximo com ícones Font Awesome (que o app não
                    // carrega — viravam quadrados vazios) e pinta os botões com
                    // o azul do .btn-primary em vez da cor do tema. O standard
                    // usa a fonte de ícones do próprio FullCalendar e as classes
                    // .fc-button-primary, que o mad-ui.css liga ao --mad-primary.
                    themeSystem:  'standard',
                    headerToolbar: {
                        left:   'prev,next today' + (cfg.addEventMethod && wrapper ? ' madNew' : ''),
                        center: 'title',
                        right:  'timeGridDay,timeGridWeek,dayGridMonth,listWeek',
                    },
                    buttonText: {
                        today: 'Hoje',
                        month: 'Mês',
                        week:  'Semana',
                        day:   'Dia',
                        list:  'Lista',
                    },
                };

                // ── Eventos ────────────────────────────────────────
                if (cfg.eventsUrl) {
                    opts.events = { url: cfg.eventsUrl };
                } else if (cfg.events && cfg.events.length) {
                    opts.events = cfg.events;
                }

                // ── Botão "Novo" (event-form) ──────────────────────
                if (cfg.addEventMethod && wrapper) {
                    opts.customButtons = {
                        madNew: {
                            text: cfg.addEventLabel || 'Novo',
                            click: () => MadWire.call(wrapper, cfg.addEventMethod, []),
                        },
                    };
                }

                // ── Altura ─────────────────────────────────────────
                if (cfg.height > 0) {
                    opts.height = cfg.height;
                } else if (cfg.fullHeight) {
                    opts.height = 'auto';
                }

                // ── Day click ──────────────────────────────────────
                if (cfg.dayClickMethod && wrapper) {
                    opts.dateClick = (info) => {
                        if (_dragFlag) { _dragFlag = false; return; }
                        const date = _fmtDate(info.date);
                        const view = info.view.type;
                        MadWire.call(wrapper, cfg.dayClickMethod, [date, view]);
                    };
                }

                // ── Event click ────────────────────────────────────
                if (cfg.eventClickMethod && wrapper) {
                    opts.eventClick = (info) => {
                        if (_dragFlag || _resizeFlag) return;
                        info.jsEvent.preventDefault();
                        const ev   = info.event;
                        const view = info.view.type;
                        MadWire.call(wrapper, cfg.eventClickMethod, [ev.id, ev.title, view]);
                    };
                }

                // ── Event drag (move) ──────────────────────────────
                if (cfg.eventUpdateMethod && wrapper) {
                    opts.eventDrop = (info) => {
                        _dragFlag = true;
                        setTimeout(() => { _dragFlag = false; }, 200);
                        const ev    = info.event;
                        const start = _fmtDate(ev.start);
                        const end   = _fmtDate(ev.end || ev.start);
                        MadWire.call(wrapper, cfg.eventUpdateMethod, [ev.id, start, end]);
                    };
                }

                // ── Event resize ───────────────────────────────────
                if (cfg.eventUpdateMethod && wrapper) {
                    opts.eventResize = (info) => {
                        _resizeFlag = true;
                        setTimeout(() => { _resizeFlag = false; }, 200);
                        const ev    = info.event;
                        const start = _fmtDate(ev.start);
                        const end   = _fmtDate(ev.end || ev.start);
                        MadWire.call(wrapper, cfg.eventUpdateMethod, [ev.id, start, end]);
                    };
                }

                // ── Opções extras (pass-through) ───────────────────
                if (cfg.extraOptions && typeof cfg.extraOptions === 'object') {
                    Object.assign(opts, cfg.extraOptions);
                }

                // ── Cria calendário ────────────────────────────────
                _calendar = new FullCalendar.Calendar(calEl, opts);
                _calendar.render();

                // ── ResizeObserver para responsividade ──────────────
                if (typeof ResizeObserver !== 'undefined') {
                    const ro = new ResizeObserver(() => {
                        if (_calendar) _calendar.updateSize();
                    });
                    ro.observe(el);
                }

                // ── Formulário fechou → recarrega os eventos ────────
                // O formulário do evento (event-form, click-target) abre em
                // gaveta/modal e fecha com closeDrawer()/closeModal() depois de
                // salvar ou excluir. Recarregar aqui mostra a mudança sem
                // re-render da tela: o usuário continua na semana em que estava.
                _overlayCloseHandler = (e) => {
                    if (!e || !e.detail || e.detail.action !== 'close') return;
                    if (!el.isConnected) return;
                    clearTimeout(_refetchTimer);
                    _refetchTimer = setTimeout(() => {
                        if (_calendar) _calendar.refetchEvents();
                    }, 150);
                };
                window.addEventListener('maddrawer', _overlayCloseHandler);
                window.addEventListener('madmodal', _overlayCloseHandler);
            },

            destroy() {
                if (_overlayCloseHandler) {
                    window.removeEventListener('maddrawer', _overlayCloseHandler);
                    window.removeEventListener('madmodal', _overlayCloseHandler);
                    _overlayCloseHandler = null;
                }
                clearTimeout(_refetchTimer);
            },

            /** Acesso ao objeto FullCalendar (para uso avançado via Alpine) */
            getCalendar() { return _calendar; },

            /** Recarrega eventos do calendário */
            refetchEvents() { if (_calendar) _calendar.refetchEvents(); },

            /** Navega para uma data */
            goToDate(date) { if (_calendar) _calendar.gotoDate(date); },

            /** Muda a view */
            changeView(view) { if (_calendar) _calendar.changeView(view); },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Alpine component: madResourceTimeline — Vertical Resource Grid
// Layout: horas no eixo Y, (dia × recurso) no eixo X
// ═══════════════════════════════════════════════════════════════════════════
document.addEventListener('alpine:init', () => {
    Alpine.data('madResourceTimeline', (cfg = {}) => {

        const _pad = (n) => String(n).padStart(2, '0');

        function _timeToMin(t) {
            if (!t) return 0;
            const p = t.split(':');
            return parseInt(p[0], 10) * 60 + parseInt(p[1] || '0', 10);
        }

        function _parseDt(s) {
            if (!s) return { date: '', minutes: 0 };
            return { date: s.slice(0, 10), minutes: _timeToMin(s.length > 10 ? s.slice(11, 16) : '00:00') };
        }

        function _addDays(ds, n) {
            const d = new Date(ds + 'T12:00:00');
            d.setDate(d.getDate() + n);
            return d.getFullYear() + '-' + _pad(d.getMonth() + 1) + '-' + _pad(d.getDate());
        }

        function _dayOfWeek(ds) { return new Date(ds + 'T12:00:00').getDay(); }

        function _fmtDayShort(ds) {
            const d = new Date(ds + 'T12:00:00');
            const names = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
            return names[d.getDay()] + ' ' + ds.slice(8, 10) + '/' + ds.slice(5, 7);
        }

        function _fmtDateBR(ds) {
            return ds.slice(8, 10) + '/' + ds.slice(5, 7) + '/' + ds.slice(0, 4);
        }

        const _today = new Date().toISOString().slice(0, 10);
        const _minMin = _timeToMin(cfg.minTime || '06:00');
        const _maxMin = _timeToMin(cfg.maxTime || '20:00');
        const _slotMin = _timeToMin(cfg.slotDuration || '01:00');
        const _defNumDays = cfg.extraOptions?.numDays || 4;
        let _wrapper = null;

        return {
            resources:      cfg.resources || [],
            events:         [],
            currentDate:    cfg.currentDate || _today,
            numDays:        (cfg.defaultView === 'resourceTimelineDay' || cfg.defaultView === 'day') ? 1 : _defNumDays,
            defaultNumDays: _defNumDays,
            days:           [],
            timeSlots:      [],
            columns:        [],
            periodLabel:    '',

            init() {
                _wrapper = this.$el.closest('[mad-component]');
                this._build();
                this._loadEvents();
            },

            // ── Build grid structure ───────────────────────────────
            _build() {
                // Days
                const days = [];
                let d = this.currentDate;
                let added = 0;
                const enabledDays = cfg.enabledDays || [0,1,2,3,4,5,6];
                while (added < this.numDays) {
                    if (enabledDays.includes(_dayOfWeek(d))) {
                        days.push({ date: d, label: _fmtDayShort(d), isToday: d === _today });
                        added++;
                    }
                    d = _addDays(d, 1);
                }
                this.days = days;

                // Columns: flat array day × resource
                const cols = [];
                for (const day of days) {
                    for (const res of this.resources) {
                        cols.push({ day, resource: res });
                    }
                }
                this.columns = cols;

                // Time slots (vertical)
                const slots = [];
                for (let m = _minMin; m < _maxMin; m += _slotMin) {
                    slots.push({
                        startMin: m,
                        endMin: Math.min(m + _slotMin, _maxMin),
                        label: _pad(Math.floor(m / 60)) + ':' + _pad(m % 60),
                    });
                }
                this.timeSlots = slots;

                // Period label
                if (this.numDays === 1) {
                    this.periodLabel = _fmtDayShort(this.currentDate) + ' — ' + _fmtDateBR(this.currentDate);
                } else {
                    const last = days[days.length - 1];
                    this.periodLabel = _fmtDateBR(days[0].date) + ' — ' + _fmtDateBR(last.date);
                }
            },

            // ── Load events ────────────────────────────────────────
            async _loadEvents() {
                if (cfg.eventsUrl) {
                    const start = this.days[0]?.date || this.currentDate;
                    const end = _addDays(this.days[this.days.length - 1]?.date || this.currentDate, 1);
                    const sep = cfg.eventsUrl.includes('?') ? '&' : '?';
                    const url = cfg.eventsUrl + sep + 'start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
                    try {
                        const res = await fetch(url);
                        this.events = await res.json();
                    } catch (e) {
                        console.error('[madResourceTimeline] fetch error:', e);
                    }
                } else {
                    this.events = cfg.events || [];
                }
            },

            // ── CSS Grid style ─────────────────────────────────────
            gridStyle() {
                const nCols = this.columns.length;
                return 'grid-template-columns: 60px repeat(' + nCols + ', minmax(100px, 1fr));';
            },

            // ── Events for a specific cell (slot row) ──────────────
            // Only returns events that START within this time slot
            getEventsStartingInSlot(dayDate, resourceId, slot) {
                return this.events.filter(e => {
                    if (String(e.resourceId) !== String(resourceId)) return false;
                    const dt = _parseDt(e.start);
                    if (dt.date !== dayDate) return false;
                    return dt.minutes >= slot.startMin && dt.minutes < slot.endMin;
                });
            },

            // ── Event style (vertical: top + height relative to cell) ──
            eventStyle(ev) {
                const totalMin = _maxMin - _minMin;
                if (totalMin <= 0) return 'display:none';

                const evS = _parseDt(ev.start);
                const evE = _parseDt(ev.end || ev.start);
                const startMin = Math.max(evS.minutes, _minMin);
                const endMin = Math.min(evE.minutes || startMin + 30, _maxMin);

                // Position relative to the slot where the event starts
                const slotStart = evS.minutes - (evS.minutes % _slotMin) + (_minMin % _slotMin ? _minMin % _slotMin : 0);
                // Actually compute from the slot's startMin
                const slotIdx = Math.floor((startMin - _minMin) / _slotMin);
                const slotBase = _minMin + slotIdx * _slotMin;
                const offsetInSlot = startMin - slotBase;

                // Height spans from event start to event end, in units of slot height
                const durationMin = endMin - startMin;
                const slotCount = this.timeSlots.length;
                const topPx = (offsetInSlot / _slotMin) * 100;
                const heightPx = (durationMin / _slotMin) * 100;

                const color = ev.color || '#3b82f6';
                return 'top:' + topPx + '%;height:' + heightPx + '%;background:' + color + ';';
            },

            fmtTimeRange(ev) {
                const s = (ev.start || '').slice(11, 16);
                const e = (ev.end || '').slice(11, 16);
                if (s && e) return s + ' - ' + e;
                return s || '';
            },

            // ── Navigation ─────────────────────────────────────────
            prev() {
                this.currentDate = _addDays(this.currentDate, -this.numDays);
                this._build();
                this._loadEvents();
            },
            next() {
                this.currentDate = _addDays(this.currentDate, this.numDays);
                this._build();
                this._loadEvents();
            },
            goToday() {
                this.currentDate = _today;
                this._build();
                this._loadEvents();
            },
            switchView(n) {
                this.numDays = n;
                this._build();
                this._loadEvents();
            },

            // ── Callbacks ──────────────────────────────────────────
            onCellClick(slot, col) {
                const method = cfg.slotClickMethod || cfg.dayClickMethod;
                if (!method || !_wrapper) return;
                const h = Math.floor(slot.startMin / 60);
                const m = slot.startMin % 60;
                const date = col.day.date + ' ' + _pad(h) + ':' + _pad(m) + ':00';
                MadWire.call(_wrapper, method, [date, col.resource.id, col.resource.title]);
            },
            onEventClick(ev) {
                const method = cfg.eventClickMethod;
                if (!method || !_wrapper) return;
                MadWire.call(_wrapper, method, [ev.id, ev.title, ev.resourceId || '']);
            },
            refetchEvents() { this._loadEvents(); },
        };
    });

    // ── Kanban scrollbar superior (sincroniza com bottom nativo) ───────
    Alpine.data('madKanbanScrollSync', () => ({
        _syncing: false,
        _ro: null,
        _mo: null,

        init() {
            const top      = this.$el.querySelector('.mad-kanban-top-scroll');
            const topInner = this.$el.querySelector('.mad-kanban-top-scroll-inner');
            const board    = this.$el.querySelector('.mad-kanban-board');
            if (!top || !topInner || !board) return;

            const update = () => { topInner.style.width = board.scrollWidth + 'px'; };
            update();
            this._ro = new ResizeObserver(update);
            this._ro.observe(board);
            this._mo = new MutationObserver(update);
            this._mo.observe(board, { childList: true, subtree: true });

            // Listeners são element-scoped (morrem com o elemento); os
            // observers NÃO — sem disconnect() eles seguram o board destacado
            // após cada re-render do wire (leak).
            top.addEventListener('scroll', (e) => {
                if (this._syncing) return;
                this._syncing = true;
                board.scrollLeft = e.target.scrollLeft;
                requestAnimationFrame(() => { this._syncing = false; });
            });
            board.addEventListener('scroll', (e) => {
                if (this._syncing) return;
                this._syncing = true;
                top.scrollLeft = e.target.scrollLeft;
                requestAnimationFrame(() => { this._syncing = false; });
            });
        },

        destroy() {
            if (this._ro) { this._ro.disconnect(); this._ro = null; }
            if (this._mo) { this._mo.disconnect(); this._mo = null; }
        },
    }));

    // ── Kanban Board ────────────────────────────────────────────────────

    // Escape p/ seletor CSS (ids de card/stage vêm do DB) — espelha o
    // window._madCssId do mad.js.
    const _kbCss = (v) => (window.CSS && CSS.escape)
        ? CSS.escape(String(v))
        : String(v).replace(/["\\]/g, '\\$&');

    // Re-ancora o menu de actions do card com position:fixed no open — o
    // overflow-y da coluna (.mad-kanban-cards) clipa menus position:absolute
    // em cards no fim da lista (z-index não escapa overflow). Clamp no
    // viewport; abre pra cima quando não cabe embaixo. Fecha no primeiro
    // scroll da coluna/board (senão o menu fixed fica flutuando fora do card)
    // via evento que o wrap Alpine escuta (@mad-kanban-menus-close.window).
    window._madKanbanAnchorMenu = function (menuEl, btnEl) {
        if (!menuEl || !btnEl) return;
        const r = btnEl.getBoundingClientRect();
        menuEl.style.position = 'fixed';
        menuEl.style.zIndex   = 'var(--mad-z-float,9999)';
        menuEl.style.right    = 'auto';
        menuEl.style.bottom   = 'auto';
        const mw = menuEl.offsetWidth  || 160;
        const mh = menuEl.offsetHeight || 10;
        const left = Math.max(8, Math.min(r.right - mw, window.innerWidth - mw - 8));
        let   top  = r.bottom + 4;
        if (top + mh > window.innerHeight - 8) top = Math.max(8, r.top - mh - 4);
        menuEl.style.left = left + 'px';
        menuEl.style.top  = top + 'px';

        const close    = () => window.dispatchEvent(new CustomEvent('mad-kanban-menus-close'));
        const scroller = btnEl.closest('.mad-kanban-cards');
        const board    = btnEl.closest('.mad-kanban-board');
        if (scroller) scroller.addEventListener('scroll', close, { once: true, passive: true });
        if (board)    board.addEventListener('scroll', close, { once: true, passive: true });
    };

    Alpine.data('madKanban', (cfg = {}) => {
        let _wrapper = null;
        // Raiz do board, guardada no init: dentro de um método chamado por um
        // handler FILHO (@drop do .mad-kanban-cards) o `this.$el` do Alpine é o
        // elemento do handler, não o board — o contador otimista não achava o
        // [data-stage-count] e ficava parado, e o rollback de um move recusado
        // "desfazia" um ajuste que nunca aconteceu (a tela ficava 3/1 com 2/2).
        let _board = null;

        return {
            dragging: null,        // { cardId, fromStageId }
            draggingStage: null,   // { stageId, before: [ids] } — arrasto de coluna
            dragOverStage: null,
            loadingMore: {},       // { stageId: bool }
            offsets: {},           // { stageId: int }
            exhausted: {},         // { stageId: bool } — servidor disse hasMore=false
            _indicator: null,
            _pendingMoves: {},     // { cardId: {container,nextSibling,stageId} } p/ rollback

            init() {
                _board   = this.$el;
                _wrapper = this.$el.closest('[mad-component]');
                (cfg.stages || []).forEach(s => {
                    this.offsets[s.id] = s.count > cfg.cardsPerLoad ? cfg.cardsPerLoad : s.count;
                    // seed inicial; depois o hasMore do servidor é a autoridade
                    if (s.count <= cfg.cardsPerLoad) this.exhausted[s.id] = true;
                });

                // Ops do mad.js (append_cards / kanban_move_failed) despacham
                // eventos que BORBULHAM do container até o board — listener no
                // próprio $el: escopado por board (multi-board ok) e morre com
                // o elemento no re-render (sem leak/closure stale em window).
                this._onLoaded = (e) => {
                    const sid = String(e.detail.stageId || '');
                    this.loadingMore[sid] = false;
                    if (typeof e.detail.appended === 'number') {
                        this.offsets[sid] = (this.offsets[sid] || 0) + e.detail.appended;
                    }
                    if (e.detail.hasMore === false) this.exhausted[sid] = true;
                };
                this._onMoveFailed = (e) => this._rollbackMove(String(e.detail.cardId || ''));
                this.$el.addEventListener('mad-kanban-loaded', this._onLoaded);
                this.$el.addEventListener('mad-kanban-move-failed', this._onMoveFailed);
            },

            destroy() {
                this.$el.removeEventListener('mad-kanban-loaded', this._onLoaded);
                this.$el.removeEventListener('mad-kanban-move-failed', this._onMoveFailed);
            },

            // ── Drag & Drop ──────────────────────────────────────────

            onDragStart(e, cardId, stageId) {
                if (!cfg.draggable) { e.preventDefault(); return; }
                this.dragging = { cardId: String(cardId), fromStageId: String(stageId) };
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', String(cardId));
                requestAnimationFrame(() => {
                    const el = e.target.closest('.mad-kanban-card');
                    if (el) el.classList.add('mad-kanban-card--dragging');
                });
            },

            onDragEnd(e) {
                const el = e.target.closest('.mad-kanban-card');
                if (el) el.classList.remove('mad-kanban-card--dragging');
                this.dragging = null;
                this.dragOverStage = null;
                this._removeIndicator();
            },

            onDragOver(e, stageId) {
                if (!this.dragging) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                this.dragOverStage = String(stageId);
                this._positionIndicator(e, e.currentTarget);
            },

            onDragLeave(e, stageId) {
                if (!e.currentTarget.contains(e.relatedTarget)) {
                    if (this.dragOverStage === String(stageId)) {
                        this.dragOverStage = null;
                    }
                    this._removeIndicator();
                }
            },

            // Nota: drop só é aceito sobre .mad-kanban-cards (área de cards).
            // Soltar no header/gap da coluna faz snap-back silencioso — o
            // indicador de drop já comunica a zona válida.
            onDrop(e, toStageId) {
                e.preventDefault();
                if (!this.dragging) return;

                toStageId = String(toStageId);
                const { cardId, fromStageId } = this.dragging;

                // 1. Move card optimistically in DOM
                const cardEl = document.querySelector('[data-card-id="' + _kbCss(cardId) + '"]');
                const container = e.currentTarget;
                if (cardEl && container) {
                    // Posição original guardada p/ rollback (op kanban_move_failed)
                    this._pendingMoves[String(cardId)] = {
                        container:   cardEl.parentElement,
                        nextSibling: cardEl.nextElementSibling,
                        stageId:     cardEl.dataset.stageId,
                    };
                    const insertBefore = this._getInsertTarget(e, container);
                    container.insertBefore(cardEl, insertBefore);
                    cardEl.dataset.stageId = toStageId;
                    cardEl.classList.remove('mad-kanban-card--dragging');
                    // Contador otimista (servidor sobrescreve na resposta)
                    if (fromStageId !== toStageId) {
                        this._bumpCount(fromStageId, -1);
                        this._bumpCount(toStageId, +1);
                    }
                }

                // 2. Collect ordered IDs in target column
                const orderedIds = [];
                container.querySelectorAll('[data-card-id]').forEach(el => {
                    orderedIds.push(el.dataset.cardId);
                });

                // 3. AJAX persist
                if (_wrapper) {
                    // cardId vai como veio do data-card-id: parseInt() zerava
                    // (NaN) qualquer PK de texto (UUID/ULID/código) e o move
                    // nunca achava o registro no servidor.
                    MadWire.call(_wrapper, 'onCardMove', [
                        cardId, toStageId, JSON.stringify(orderedIds)
                    ]);
                }

                // Cleanup
                this.dragging = null;
                this.dragOverStage = null;
                this._removeIndicator();
            },

            /** Desfaz o move otimista quando o servidor recusa (op kanban_move_failed). */
            _rollbackMove(cardId) {
                const p = this._pendingMoves[cardId];
                delete this._pendingMoves[cardId];
                if (!p || !p.container || !p.container.isConnected) return;

                const cardEl = document.querySelector('[data-card-id="' + _kbCss(cardId) + '"]');
                if (!cardEl) return;

                const cur    = cardEl.parentElement;
                const anchor = (p.nextSibling && p.nextSibling.parentNode === p.container)
                    ? p.nextSibling
                    : p.container.querySelector('.mad-kanban-loading');
                p.container.insertBefore(cardEl, anchor);
                if (cur && cur !== p.container) {
                    this._bumpCount(String(cur.dataset.stageId || ''), -1);
                    this._bumpCount(String(p.stageId || ''), +1);
                }
                cardEl.dataset.stageId = p.stageId;
            },

            /** Ajusta o contador do header da coluna (escopado ao board). */
            _bumpCount(stageId, delta) {
                if (!stageId) return;
                const root = _board || this.$el;
                const el = root.querySelector('[data-stage-count="' + _kbCss(stageId) + '"]');
                if (!el) return;
                const n = parseInt(el.textContent, 10);
                if (!isNaN(n)) el.textContent = String(Math.max(0, n + delta));
            },

            _positionIndicator(e, container) {
                if (!this._indicator) {
                    this._indicator = document.createElement('div');
                    this._indicator.className = 'mad-kanban-drop-indicator';
                }

                const cards = Array.from(container.querySelectorAll('.mad-kanban-card:not(.mad-kanban-card--dragging)'));
                let insertBefore = null;

                for (const card of cards) {
                    const rect = card.getBoundingClientRect();
                    const midY = rect.top + rect.height / 2;
                    if (e.clientY < midY) {
                        insertBefore = card;
                        break;
                    }
                }

                if (insertBefore) {
                    container.insertBefore(this._indicator, insertBefore);
                } else {
                    container.appendChild(this._indicator);
                }
            },

            _getInsertTarget(e, container) {
                const cards = Array.from(container.querySelectorAll('.mad-kanban-card:not(.mad-kanban-card--dragging)'));
                for (const card of cards) {
                    const rect = card.getBoundingClientRect();
                    if (e.clientY < rect.top + rect.height / 2) {
                        return card;
                    }
                }
                return null;
            },

            _removeIndicator() {
                if (this._indicator && this._indicator.parentNode) {
                    this._indicator.parentNode.removeChild(this._indicator);
                }
            },

            // ── Reordenar colunas (stages-reorderable) ───────────────
            // O cabeçalho é a alça; a coluna muda de lugar ao vivo durante o
            // arrasto e, ao soltar, o servidor grava a ordem nova (onStageMove).
            // Recusa do servidor redesenha o board na ordem do banco.

            onStageDragStart(e) {
                if (!cfg.stagesReorderable) return;
                // Arrasto que nasce num botão de ação da coluna não é reorder.
                if (e.target.closest && e.target.closest('button, a, input, select, textarea')) {
                    e.preventDefault();
                    return;
                }
                const col = e.currentTarget.closest('.mad-kanban-col');
                if (!col) return;
                this.draggingStage = { stageId: String(col.dataset.stageId), before: this._stageOrder() };
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', 'stage:' + col.dataset.stageId);
                requestAnimationFrame(() => col.classList.add('mad-kanban-col--dragging'));
            },

            onStageDragOver(e) {
                if (!this.draggingStage) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                const board   = _board || this.$el;
                const dragged = this._stageCol(this.draggingStage.stageId);
                const over    = e.currentTarget;
                if (!dragged || over === dragged || over.parentNode !== board) return;
                const r = over.getBoundingClientRect();
                const after = e.clientX > r.left + r.width / 2;
                const ref = after ? over.nextElementSibling : over;
                if (ref !== dragged && dragged.nextElementSibling !== ref) board.insertBefore(dragged, ref);
            },

            onStageDrop(e) {
                if (!this.draggingStage) return;
                e.preventDefault();
                const { stageId, before } = this.draggingStage;
                this._endStageDrag();
                const after = this._stageOrder();
                if (after.join('\u0000') === before.join('\u0000')) return;
                if (_wrapper) {
                    MadWire.call(_wrapper, 'onStageMove', [stageId, JSON.stringify(after)]);
                }
            },

            onStageDragEnd() {
                // Soltou fora de uma coluna (ou Esc): volta à ordem do início.
                if (this.draggingStage) this._restoreStageOrder(this.draggingStage.before);
                this._endStageDrag();
            },

            _endStageDrag() {
                const col = this.draggingStage ? this._stageCol(this.draggingStage.stageId) : null;
                if (col) col.classList.remove('mad-kanban-col--dragging');
                this.draggingStage = null;
            },

            _stageCol(stageId) {
                const board = _board || this.$el;
                return Array.from(board.children).find(c =>
                    c.classList.contains('mad-kanban-col') && String(c.dataset.stageId) === String(stageId)) || null;
            },

            _stageOrder() {
                const board = _board || this.$el;
                return Array.from(board.children)
                    .filter(c => c.classList.contains('mad-kanban-col'))
                    .map(c => String(c.dataset.stageId));
            },

            _restoreStageOrder(ids) {
                const board = _board || this.$el;
                (ids || []).forEach(id => {
                    const col = this._stageCol(id);
                    if (col) board.appendChild(col);
                });
            },

            // ── Infinite scroll ──────────────────────────────────────

            onScrollCards(e, stageId) {
                stageId = String(stageId);
                // exhausted vem do hasMore do servidor (evento mad-kanban-loaded)
                // — não do count inicial do cfg, que fica stale.
                if (this.loadingMore[stageId] || this.exhausted[stageId]) return;

                const el = e.target;
                if (el.scrollTop + el.clientHeight < el.scrollHeight - 50) return;

                this.loadingMore[stageId] = true;

                if (_wrapper) {
                    // offset só avança no evento de load concluído (appended real)
                    MadWire.call(_wrapper, 'onLoadMore', [
                        stageId, this.offsets[stageId] || 0
                    ]);
                } else {
                    this.loadingMore[stageId] = false;
                }
            },

            // ── Card click ───────────────────────────────────────────

            onCardClick(e, cardId) {
                // Ignore clicks on actions, drag handle
                if (e.target.closest('[data-card-actions]') ||
                    e.target.closest('[data-drag-handle]') ||
                    e.target.closest('button')) return;

                if (cfg.clickTarget) {
                    const fwd = cfg.forwardParams || {};
                    const params = { id: cardId, ...fwd };
                    // Propaga forward params com prefixo para o destino continuar propagando
                    Object.entries(fwd).forEach(([k, v]) => { params['_forward_param_' + k] = v; });
                    // cfg.clickUrl é o TEMPLATE amigável assado no PHP (com
                    // __MAD_id__). Sem ele, o Mad.go monta a forma genérica.
                    const url = Mad._resolveUrlTemplate(cfg.clickUrl, params);
                    Mad.go(cfg.clickTarget, cfg.clickMethod || 'onShow', params, url);
                }
            },
        };
    });

    // ╔══════════════════════════════════════════════════════════════════╗
    // ║  madScanner — Barcode / QR code scanner (Html5Qrcode)          ║
    // ╚══════════════════════════════════════════════════════════════════╝

    Alpine.data('madScanner', (cfg = {}) => ({
        // State
        scanning: false,
        detected: false,
        detectedType: '',
        value: cfg.value || '',
        scanList: [],         // for continuous mode
        _scanner: null,       // Html5Qrcode instance
        _name: cfg.name || '',
        _type: cfg.type || 'both',        // 'barcode', 'qr', 'both'
        _continuous: cfg.continuous || false,
        _beep: cfg.beep !== false,
        _activeFilter: 'all', // 'all', 'qr', 'barcode'

        init() {
            // Sync initial value to input if set
            const inp = this.$el.querySelector('input[name="' + this._name + '"]');
            if (inp && this.value) inp.value = this.value;
        },

        toggle() {
            this.scanning ? this.close() : this.startScanning();
        },

        async startScanning() {
            if (typeof Html5Qrcode === 'undefined') {
                console.error('html5-qrcode not loaded');
                return;
            }
            this.scanning = true;
            this.detected = false;
            await this.$nextTick();

            const cameraEl = this.$refs.camera;
            if (!cameraEl) return;

            // Html5Qrcode needs a unique id on the element
            const scannerId = 'mad-scanner-' + this._name + '-' + Date.now();
            cameraEl.id = scannerId;

            this._scanner = new Html5Qrcode(scannerId);
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 150 },
                aspectRatio: 1.5,
                formatsToSupport: this._getFormats(),
            };

            try {
                await this._scanner.start(
                    { facingMode: 'environment' },
                    config,
                    (text, result) => this.onScanSuccess(text, result),
                    () => {} // silent: no code found yet
                );
            } catch (err) {
                if (typeof madToast === 'function') {
                    madToast('Camera nao disponivel. Use HTTPS ou digite o codigo manualmente.', 'warning');
                }
                try { this._scanner.clear(); } catch(e) {}
                this._scanner = null;
                this.scanning = false;
            }
        },

        onScanSuccess(text, result) {
            const formatName = result?.result?.format?.formatName || 'Unknown';
            this.value = text;
            this.detectedType = formatName;
            this.detected = true;

            // Update input element
            const inp = this.$el.querySelector('input[name="' + this._name + '"]');
            if (inp) {
                inp.value = text;
                inp.dispatchEvent(new Event('input', { bubbles: true }));
                inp.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (this._beep) this.playBeep();

            if (this._continuous) {
                this.scanList.unshift({ code: text, type: formatName });
                if (this.scanList.length > 50) this.scanList.pop();
                this._fireMadAction(text);
            } else {
                this._fireMadAction(text);
                this.close();
                setTimeout(() => { this.detected = false; }, 2000);
            }
        },

        _fireMadAction(value) {
            const wrapper = this.$el.closest('[data-mad-id]');
            const actionAttr = this.$el.dataset.madScanAction;
            if (wrapper && actionAttr && typeof MadWire !== 'undefined') {
                MadWire.call(wrapper, actionAttr, [value]);
            }
        },

        close() {
            if (this._scanner) {
                try { this._scanner.stop().catch(() => {}); } catch(e) {}
                try { this._scanner.clear(); } catch(e) {}
                this._scanner = null;
            }
            this.scanning = false;
        },

        setFilter(filter) {
            this._activeFilter = filter;
            if (this.scanning) {
                this.close();
                this.$nextTick(() => this.startScanning());
            }
        },

        _getFormats() {
            if (typeof Html5QrcodeSupportedFormats === 'undefined') return undefined;
            const F = Html5QrcodeSupportedFormats;
            const bar = [F.EAN_13, F.EAN_8, F.CODE_128, F.CODE_39, F.UPC_A, F.UPC_E, F.ITF, F.CODABAR];
            const qr  = [F.QR_CODE];
            if (this._activeFilter === 'qr') return qr;
            if (this._activeFilter === 'barcode') return bar;
            if (this._type === 'qr') return qr;
            if (this._type === 'barcode') return bar;
            return [...bar, ...qr];
        },

        playBeep() {
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 1800;
                gain.gain.value = 0.15;
                osc.start();
                osc.stop(ctx.currentTime + 0.1);
            } catch(e) {}
        },

        destroy() {
            if (this._scanner) {
                try { this._scanner.stop().catch(() => {}); } catch(e) {}
                try { this._scanner.clear(); } catch(e) {}
                this._scanner = null;
            }
        },
    }));

    // ╔══════════════════════════════════════════════════════════════════╗
    // ║  madAvatarField — Circular avatar with upload button           ║
    // ╚══════════════════════════════════════════════════════════════════╝

    Alpine.data('madAvatarField', (cfg = {}) => ({
        preview: cfg.preview || '',
        removed: false,
        _name: cfg.name || '',
        _maxBytes: cfg.maxBytes || 2 * 1024 * 1024,
        _accept: cfg.accept || 'image/png,image/jpeg',

        onSelect(e) {
            var file = e.target.files[0];
            if (!file) return;

            // Validate type
            var types = this._accept.split(',').map(function(t) { return t.trim(); });
            if (!types.some(function(t) { return file.type === t || (t.endsWith('/*') && file.type.startsWith(t.replace('/*', '/'))); })) {
                if (typeof madToast === 'function') madToast('Formato não permitido: ' + file.name, 'danger');
                e.target.value = '';
                return;
            }

            // Validate size
            if (file.size > this._maxBytes) {
                if (typeof madToast === 'function') madToast('Arquivo muito grande. Máximo: ' + cfg.maxSizeLabel, 'danger');
                e.target.value = '';
                return;
            }

            // Preview
            var self = this;
            var reader = new FileReader();
            reader.onload = function(ev) { self.preview = ev.target.result; };
            reader.readAsDataURL(file);
            this.removed = false;

            // Fire mad:change callback
            this._fireMadChange();
        },

        remove() {
            this.preview = '';
            this.removed = true;
            var input = this.$el.querySelector('input[type="file"]');
            if (input) input.value = '';
        },

        _fireMadChange() {
            var action = cfg.madChangeAction;
            if (!action) return;
            var wrapper = this.$el.closest('[mad-component]');
            if (wrapper && typeof MadWire !== 'undefined') {
                MadWire.call(wrapper, action, [cfg.name]);
            }
        },
    }));

    // ╔══════════════════════════════════════════════════════════════════╗
    // ║  madImageField — Image upload, camera capture, crop & rotate   ║
    // ╚══════════════════════════════════════════════════════════════════╝

    Alpine.data('madImageField', (cfg = {}) => ({
        // State
        hasImage: false,
        previewSrc: '',
        imageData: cfg.value || '',
        cropSrc: '',
        cropping: false,
        cameraActive: false,
        dragover: false,
        removed: false,
        _cropper: null,        // Cropper.js instance
        _stream: null,         // MediaStream
        _facingMode: 'environment',
        _name: cfg.name || '',
        _crop: cfg.crop || false,
        _aspectRatio: cfg.aspectRatio || null,
        // O Blade entrega o Tamanho máximo em `maxBytes` (antes só `maxSize` era
        // lido, e o limite ficava sempre em 5 MB).
        _maxSize: cfg.maxBytes || cfg.maxSize || 5 * 1024 * 1024,
        _accept: cfg.accept || 'image/png,image/jpeg,image/gif,image/webp',
        _output: cfg.output || 'base64',
        _originalSrc: '',

        init() {
            if (this.imageData) {
                this.previewSrc = this.imageData;
                this.hasImage = true;
            }
        },

        onFileSelect(e) {
            const file = e.target.files?.[0];
            const taken = file ? this._processFile(file) : false;
            if (!cfg.storage) { e.target.value = ''; return; }
            // Com storage o <input> é o que vai no Salvar: o arquivo recusado
            // não pode ficar nele. Volta a imagem que o campo já tinha, se era nova.
            if (file && !taken) {
                e.target.value = '';
                if (this.imageData && this.imageData.indexOf('data:') === 0) this._injectFile();
            }
        },

        onDrop(e) {
            this.dragover = false;
            const file = e.dataTransfer?.files?.[0];
            if (file) this._processFile(file);
        },

        _processFile(file) {
            // Tipos aceitos (`image/*`, `image/png`, `.png`) e Tamanho máximo do
            // campo; com storage, também o limite do servidor.
            const problem = _madUploadProblem(file, { accept: this._accept, maxBytes: this._maxSize, serverMax: cfg.serverMax, stored: !!cfg.storage });
            if (problem) {
                _madUploadWarn(problem);
                return false;
            }
            const reader = new FileReader();
            reader.onload = (ev) => {
                this.previewSrc = ev.target.result;
                this._originalSrc = ev.target.result;
                this.imageData = ev.target.result;
                this.hasImage = true;
                this.removed = false;
                this._syncHidden();
            };
            reader.readAsDataURL(file);
            return true;
        },

        // Imagem que o próprio campo produz (câmera, recorte, giro): sai num
        // tipo que o campo aceita e, se passar do Tamanho máximo, com a
        // qualidade reduzida até caber. Devolve '' quando nem assim cabe.
        _exportCanvas(canvas) {
            const fits = (url) => Math.floor((url.length - url.indexOf(',') - 1) * 3 / 4) <= this._maxSize;
            const type = ['image/jpeg', 'image/png', 'image/webp'].find(t => _madUploadAccepts(this._accept, { name: 'imagem.' + t.slice(6), type: t })) || 'image/jpeg';
            let quality = 0.9;
            let url = canvas.toDataURL(type, quality);
            while (!fits(url) && type !== 'image/png' && quality > 0.5) {
                quality -= 0.1;
                url = canvas.toDataURL(type, quality);
            }
            if (!fits(url)) {
                _madUploadWarn('A imagem editada passa do limite deste campo (' + _madUploadSize(this._maxSize) + ') e não foi alterada.');
                return '';
            }
            return url;
        },

        // Camera
        async openCamera() {
            try {
                this._stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: this._facingMode },
                    audio: false,
                });
                this.cameraActive = true;
                await this.$nextTick();
                const video = this.$refs.video;
                if (video) video.srcObject = this._stream;
            } catch (err) {
                if (typeof madToast === 'function') madToast('Câmera não disponível', 'danger');
            }
        },

        async switchCamera() {
            this._facingMode = this._facingMode === 'environment' ? 'user' : 'environment';
            this.closeCamera();
            await this.openCamera();
        },

        capture() {
            const video = this.$refs.video;
            const canvas = this.$refs.canvas;
            if (!video || !canvas) return;
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            canvas.getContext('2d').drawImage(video, 0, 0);
            const dataUrl = this._exportCanvas(canvas);
            if (!dataUrl) { this.closeCamera(); return; }
            this.previewSrc = dataUrl;
            this._originalSrc = dataUrl;
            this.imageData = dataUrl;
            this.hasImage = true;
            this.removed = false;
            this.closeCamera();
            this._syncHidden();
        },

        closeCamera() {
            if (this._stream) {
                this._stream.getTracks().forEach(t => t.stop());
                this._stream = null;
            }
            this.cameraActive = false;
        },

        // Crop (modal)
        startCrop() {
            if (!this._crop || typeof Cropper === 'undefined') return;
            this._destroyCropper();
            this.cropSrc = this.previewSrc;
            this.cropping = true;
            this.$nextTick(() => {
                var img = this.$refs.cropImage;
                if (!img) return;
                var ratio = NaN;
                if (this._aspectRatio) {
                    var parts = String(this._aspectRatio).split(':');
                    if (parts.length === 2) ratio = parseFloat(parts[0]) / parseFloat(parts[1]);
                }
                this._cropper = new Cropper(img, {
                    aspectRatio: isNaN(ratio) ? NaN : ratio,
                    viewMode: 1,
                    dragMode: 'move',
                    autoCropArea: 0.8,
                    responsive: true,
                    background: false,
                });
            });
        },

        confirmCrop() {
            if (!this._cropper) return;
            var canvas = this._cropper.getCroppedCanvas();
            if (canvas) {
                var dataUrl = this._exportCanvas(canvas);
                if (dataUrl) {
                    this.previewSrc = dataUrl;
                    this._originalSrc = dataUrl;
                    this.imageData = dataUrl;
                    this._syncHidden();
                }
            }
            this._destroyCropper();
            this.cropping = false;
        },

        cancelCrop() {
            this._destroyCropper();
            this.cropping = false;
        },

        rotateLeft() {
            if (this._cropper) { this._cropper.rotate(-90); return; }
            this._rotateCanvas(-90);
        },

        rotateRight() {
            if (this._cropper) { this._cropper.rotate(90); return; }
            this._rotateCanvas(90);
        },

        zoomIn()  { if (this._cropper) this._cropper.zoom(0.1); },
        zoomOut() { if (this._cropper) this._cropper.zoom(-0.1); },

        _rotateCanvas(degrees) {
            const img = new Image();
            img.onload = () => {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                if (Math.abs(degrees) === 90 || Math.abs(degrees) === 270) {
                    canvas.width = img.height;
                    canvas.height = img.width;
                } else {
                    canvas.width = img.width;
                    canvas.height = img.height;
                }
                ctx.translate(canvas.width / 2, canvas.height / 2);
                ctx.rotate((degrees * Math.PI) / 180);
                ctx.drawImage(img, -img.width / 2, -img.height / 2);
                const dataUrl = this._exportCanvas(canvas);
                if (!dataUrl) return;
                this.previewSrc = dataUrl;
                this._originalSrc = dataUrl;
                this.imageData = dataUrl;
                this._syncHidden();
            };
            img.src = this.previewSrc;
        },

        remove() {
            this._destroyCropper();
            this.closeCamera();
            this.removed = true;
            this.hasImage = false;
            this.previewSrc = '';
            this._originalSrc = '';
            this.imageData = '';
            this.cropping = false;
            // Limpa o file input
            if (this.$refs.fileInput) this.$refs.fileInput.value = '';
            this._syncHidden();
        },

        _syncHidden() {
            // Atualiza o hidden (para preview / mad:model)
            var hiddens = this.$el.querySelectorAll('input[type="hidden"]');
            for (var h = 0; h < hiddens.length; h++) {
                if (hiddens[h].getAttribute('mad:model') === this._name
                 || hiddens[h].getAttribute('data-mad-model') === this._name) {
                    hiddens[h].value = this.imageData;
                    break;
                }
            }
            // Se tem storage, injeta File no input[type=file] para $_FILES
            if (cfg.storage && this.imageData) {
                this._injectFile();
            }
            this._fireMadChange();
        },
        _injectFile() {
            var b64 = this.imageData;
            var match = b64.match(/^data:(image\/\w+);base64,(.+)$/);
            if (!match) return;
            var mime = match[1];
            var ext = mime.split('/')[1] || 'jpg';
            var bin = atob(match[2]);
            var arr = new Uint8Array(bin.length);
            for (var k = 0; k < bin.length; k++) arr[k] = bin.charCodeAt(k);
            var file = new File([arr], this._name + '.' + ext, { type: mime });
            var fileInput = this.$refs.fileInput;
            if (fileInput) {
                var dt = new DataTransfer();
                dt.items.add(file);
                fileInput.files = dt.files;
            }
        },
        _fireMadChange() {
            var action = cfg.madChangeAction;
            if (!action || !this.imageData) return;
            var wrapper = this.$el.closest('[mad-component]');
            if (!wrapper || typeof MadWire === 'undefined') return;

            // Converte base64 → File e injeta num input[type=file] temporário
            // para que _request() colete via $_FILES (mesmo fluxo do file-field)
            var b64 = this.imageData;
            var match = b64.match(/^data:(image\/\w+);base64,(.+)$/);
            if (!match) return;
            var mime = match[1];
            var ext = mime.split('/')[1] || 'jpg';
            var bin = atob(match[2]);
            var arr = new Uint8Array(bin.length);
            for (var k = 0; k < bin.length; k++) arr[k] = bin.charCodeAt(k);
            var file = new File([arr], cfg.name + '.' + ext, { type: mime });

            // Cria/reutiliza input file temporário
            var tmpId = '__mad_img_file_' + cfg.name;
            var tmpInput = wrapper.querySelector('#' + tmpId);
            if (!tmpInput) {
                tmpInput = document.createElement('input');
                tmpInput.type = 'file';
                tmpInput.id = tmpId;
                tmpInput.name = cfg.name;
                tmpInput.style.display = 'none';
                wrapper.appendChild(tmpInput);
            }
            var dt = new DataTransfer();
            dt.items.add(file);
            tmpInput.files = dt.files;

            MadWire.call(wrapper, action, [cfg.name]);
        },

        _destroyCropper() {
            if (this._cropper) { this._cropper.destroy(); this._cropper = null; }
        },

        destroy() {
            this._destroyCropper();
            this.closeCamera();
        },
    }));

    /* ═══════════════════════════════════════════════════════════════════
       DATE RANGE PICKER — Pure Alpine.js date range picker
       Two side-by-side calendars, presets sidebar, auto-apply
       ═══════════════════════════════════════════════════════════════════ */

    // ── Date helpers (module-scoped) ──────────────────────────────────
    var _drpMonths = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho',
                      'Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];
    var _drpWeekdays = ['D','S','T','Q','Q','S','S'];

    function _drpParseISO(str) {
        if (!str || typeof str !== 'string') return null;
        // Accept yyyy-mm-dd or dd/mm/yyyy
        var m = str.match(/^(\d{4})-(\d{2})-(\d{2})/);
        if (m) return new Date(+m[1], +m[2] - 1, +m[3]);
        m = str.match(/^(\d{2})\/(\d{2})\/(\d{4})/);
        if (m) return new Date(+m[3], +m[2] - 1, +m[1]);
        return null;
    }

    function _drpToISO(d) {
        if (!d) return '';
        var y = d.getFullYear();
        var m = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + dd;
    }

    function _drpFormat(d) {
        if (!d) return '';
        var dd = String(d.getDate()).padStart(2, '0');
        var mm = String(d.getMonth() + 1).padStart(2, '0');
        return dd + '/' + mm + '/' + d.getFullYear();
    }

    // Format a Date using a mask with tokens: yyyy / yy / mm (month) / dd / hh / HH (hour 0-23) /
    //   ii (minute, picker convention) / MM (minute, db convention) / ss / SS (seconds).
    // Non-token chars are emitted as literals.
    function _drpFormatWithMask(d, mask) {
        if (!d) return '';
        var y  = d.getFullYear();
        var mo = String(d.getMonth() + 1).padStart(2, '0');
        var dd = String(d.getDate()).padStart(2, '0');
        var hh = String(d.getHours()).padStart(2, '0');
        var mi = String(d.getMinutes()).padStart(2, '0');
        var ss = String(d.getSeconds()).padStart(2, '0');
        var y4 = String(y).padStart(4, '0');   // ano < 1000 tem que sair '0999', nao '999'
        var yy = y4.slice(-2);
        var out = '';
        var i = 0;
        while (i < mask.length) {
            if      (mask.substr(i, 4) === 'yyyy') { out += y4; i += 4; }
            else if (mask.substr(i, 2) === 'yy')   { out += yy; i += 2; }
            else if (mask.substr(i, 2) === 'mm')   { out += mo; i += 2; }
            else if (mask.substr(i, 2) === 'dd')   { out += dd; i += 2; }
            else if (mask.substr(i, 2) === 'HH')   { out += hh; i += 2; }
            else if (mask.substr(i, 2) === 'hh')   { out += hh; i += 2; }
            else if (mask.substr(i, 2) === 'MM')   { out += mi; i += 2; }
            else if (mask.substr(i, 2) === 'ii')   { out += mi; i += 2; }
            else if (mask.substr(i, 2) === 'SS')   { out += ss; i += 2; }
            else if (mask.substr(i, 2) === 'ss')   { out += ss; i += 2; }
            else { out += mask.charAt(i); i += 1; }
        }
        return out;
    }

    // Parse a string using a mask with the same tokens as _drpFormatWithMask.
    // Returns null on mismatch. Time tokens are optional — falls back to 00:00:00
    // when the mask has no time component.
    function _drpParseWithMask(str, mask) {
        if (!str || typeof str !== 'string') return null;
        var y = null, mo = null, d = null;
        var hh = 0, mi = 0, ss = 0;
        var ki = 0, si = 0;
        while (ki < mask.length && si < str.length) {
            if (mask.substr(ki, 4) === 'yyyy') {
                var yp = str.substr(si, 4);
                if (!/^\d{4}$/.test(yp)) return null;
                y = +yp; ki += 4; si += 4;
            } else if (mask.substr(ki, 2) === 'yy') {
                var yp2 = str.substr(si, 2);
                if (!/^\d{2}$/.test(yp2)) return null;
                y = 2000 + +yp2; ki += 2; si += 2;
            } else if (mask.substr(ki, 2) === 'mm') {
                var mp = str.substr(si, 2);
                if (!/^\d{2}$/.test(mp)) return null;
                mo = +mp - 1; ki += 2; si += 2;
            } else if (mask.substr(ki, 2) === 'dd') {
                var dp = str.substr(si, 2);
                if (!/^\d{2}$/.test(dp)) return null;
                d = +dp; ki += 2; si += 2;
            } else if (mask.substr(ki, 2) === 'HH' || mask.substr(ki, 2) === 'hh') {
                var hp = str.substr(si, 2);
                if (!/^\d{2}$/.test(hp)) return null;
                hh = +hp; ki += 2; si += 2;
            } else if (mask.substr(ki, 2) === 'MM' || mask.substr(ki, 2) === 'ii') {
                var ip = str.substr(si, 2);
                if (!/^\d{2}$/.test(ip)) return null;
                mi = +ip; ki += 2; si += 2;
            } else if (mask.substr(ki, 2) === 'SS' || mask.substr(ki, 2) === 'ss') {
                var sp = str.substr(si, 2);
                if (!/^\d{2}$/.test(sp)) return null;
                ss = +sp; ki += 2; si += 2;
            } else {
                if (mask.charAt(ki) !== str.charAt(si)) return null;
                ki += 1; si += 1;
            }
        }
        if (y == null || mo == null || d == null) return null;
        return new Date(y, mo, d, hh, mi, ss);
    }

    function _drpSameDay(a, b) {
        if (!a || !b) return false;
        return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate();
    }

    function _drpStartOfMonth(d) { return new Date(d.getFullYear(), d.getMonth(), 1); }
    function _drpEndOfMonth(d)   { return new Date(d.getFullYear(), d.getMonth() + 1, 0); }

    function _drpAddMonths(d, n) {
        var r = new Date(d.getFullYear(), d.getMonth() + n, 1);
        return r;
    }

    function _drpSubDays(d, n) {
        var r = new Date(d);
        r.setDate(r.getDate() - n);
        return r;
    }

    function _drpDiffDays(a, b) {
        return Math.round(Math.abs((a - b) / 86400000));
    }

    function _drpStartOfQuarter(d) {
        var q = Math.floor(d.getMonth() / 3);
        return new Date(d.getFullYear(), q * 3, 1);
    }

    function _drpEndOfQuarter(d) {
        var q = Math.floor(d.getMonth() / 3);
        return new Date(d.getFullYear(), q * 3 + 3, 0);
    }

    function _drpBuildDefaultPresets() {
        var today = new Date(); today.setHours(0,0,0,0);
        var yesterday = _drpSubDays(today, 1);
        return [
            { label: 'Hoje',            start: new Date(today),    end: new Date(today) },
            { label: 'Ontem',           start: yesterday,          end: new Date(yesterday) },
            { label: 'Últimos 7 dias',  start: _drpSubDays(today, 6),  end: new Date(today) },
            { label: 'Últimos 30 dias', start: _drpSubDays(today, 29), end: new Date(today) },
            { label: 'Este mês',        start: _drpStartOfMonth(today), end: _drpEndOfMonth(today) },
            { label: 'Mês passado',     start: _drpStartOfMonth(_drpAddMonths(today, -1)), end: _drpEndOfMonth(_drpAddMonths(today, -1)) },
            { label: 'Este trimestre',  start: _drpStartOfQuarter(today), end: _drpEndOfQuarter(today) },
            { label: 'Este ano',        start: new Date(today.getFullYear(), 0, 1), end: new Date(today.getFullYear(), 11, 31) },
        ];
    }

    function _drpBuildGrid(year, month) {
        var firstDay = new Date(year, month, 1);
        var startWeekday = firstDay.getDay(); // 0=Sunday
        var daysInMonth = new Date(year, month + 1, 0).getDate();
        var prevMonthDays = new Date(year, month, 0).getDate();
        var cells = [];

        // Previous month fill
        for (var i = startWeekday - 1; i >= 0; i--) {
            cells.push({ date: new Date(year, month - 1, prevMonthDays - i), day: prevMonthDays - i, currentMonth: false });
        }
        // Current month
        for (var d = 1; d <= daysInMonth; d++) {
            cells.push({ date: new Date(year, month, d), day: d, currentMonth: true });
        }
        // Next month fill (complete 42 cells = 6 rows)
        var remaining = 42 - cells.length;
        for (var j = 1; j <= remaining; j++) {
            cells.push({ date: new Date(year, month + 1, j), day: j, currentMonth: false });
        }
        return cells;
    }

    Alpine.data('madDateRangePicker', function(cfg) {
        cfg = cfg || {};
        // Masks: db = formato persistido nos hiddens / model; display = trigger e custom presets.
        var _databaseMask = cfg.databaseMask || 'yyyy-mm-dd';
        var _displayMask  = cfg.displayMask  || 'dd/mm/yyyy';
        // Parser tolerante: tenta databaseMask, cai no parse ISO/BR legado se falhar.
        var parseDb = function(s) { return _drpParseWithMask(s, _databaseMask) || _drpParseISO(s); };
        return {
            isOpen: false,
            startDate: null,
            endDate: null,
            hoveredDate: null,
            selectingEnd: false,
            leftMonth: null,
            rightMonth: null,
            presets: [],
            _minDate: null,
            _maxDate: null,
            _maxSpan: cfg.maxSpan || 0,
            _placeholder: cfg.placeholder || 'Selecione o período',
            _nameStart: cfg.nameStart || '',
            _nameEnd: cfg.nameEnd || '',
            _databaseMask: _databaseMask,
            _displayMask: _displayMask,

            init: function() {
                // Parse initial values via databaseMask (com fallback legado)
                this.startDate = parseDb(cfg.startValue);
                this.endDate = parseDb(cfg.endValue);
                this._minDate = parseDb(cfg.min);
                this._maxDate = parseDb(cfg.max);

                // Build presets
                var allPresets = [];
                if (cfg.defaultPresets) {
                    allPresets = _drpBuildDefaultPresets();
                }
                if (cfg.customPresets && cfg.customPresets.length) {
                    for (var i = 0; i < cfg.customPresets.length; i++) {
                        var cp = cfg.customPresets[i];
                        allPresets.push({ label: cp[0], start: parseDb(cp[1]), end: parseDb(cp[2]) });
                    }
                }
                this.presets = allPresets;

                // Position months
                if (this.startDate) {
                    this.leftMonth = _drpStartOfMonth(this.startDate);
                    if (this.endDate && this.endDate.getMonth() !== this.startDate.getMonth() || this.endDate && this.endDate.getFullYear() !== this.startDate.getFullYear()) {
                        this.rightMonth = _drpStartOfMonth(this.endDate);
                    } else {
                        this.rightMonth = _drpAddMonths(this.leftMonth, 1);
                    }
                } else {
                    var now = new Date();
                    this.leftMonth = _drpStartOfMonth(now);
                    this.rightMonth = _drpAddMonths(this.leftMonth, 1);
                }

                this._ensureMonthOrder();
            },

            get displayText() {
                if (this.startDate && this.endDate) {
                    return _drpFormatWithMask(this.startDate, this._displayMask) + '  →  ' + _drpFormatWithMask(this.endDate, this._displayMask);
                }
                if (this.startDate && this.selectingEnd) {
                    return _drpFormatWithMask(this.startDate, this._displayMask) + '  →  ...';
                }
                return this._placeholder;
            },

            get hasValue() {
                return this.startDate !== null;
            },

            // Hidden inputs / model bind: emit no formato do databaseMask
            // (nome legado mantido por compatibilidade com a Blade).
            get isoStart() { return _drpFormatWithMask(this.startDate, this._databaseMask); },
            get isoEnd()   { return _drpFormatWithMask(this.endDate,   this._databaseMask); },

            // Public automation contract: ISO calendar dates, independent of the
            // display/database masks. Validate the whole range before changing it.
            getRange: function() {
                return {
                    start: _drpFormatWithMask(this.startDate, 'yyyy-mm-dd'),
                    end: _drpFormatWithMask(this.endDate, 'yyyy-mm-dd')
                };
            },

            setRange: function(range) {
                if (cfg.disabled) throw new Error('daterange is disabled');
                if (range === null || (range && range.start === '' && range.end === '')) {
                    this.clear();
                    this.isOpen = false;
                    return this.getRange();
                }
                var parse = function(value) {
                    var m = typeof value === 'string' && value.match(/^(\d{4})-(\d{2})-(\d{2})$/);
                    if (!m || +m[1] < 1) throw new Error('daterange requires start/end in YYYY-MM-DD');
                    var d = new Date(0);
                    d.setFullYear(+m[1], +m[2] - 1, +m[3]);
                    d.setHours(0, 0, 0, 0);
                    if (d.getFullYear() !== +m[1] || d.getMonth() !== +m[2] - 1 || d.getDate() !== +m[3]) {
                        throw new Error('daterange contains an invalid calendar date');
                    }
                    return d;
                };
                var start = parse(range && range.start), end = parse(range && range.end);
                if (start > end) throw new Error('daterange start must be before or equal to end');
                if ((this._minDate && start < this._minDate) || (this._maxDate && end > this._maxDate)) {
                    throw new Error('daterange is outside min/max bounds');
                }
                if (this._maxSpan > 0 && _drpDiffDays(end, start) > this._maxSpan) {
                    throw new Error('daterange exceeds maxSpan');
                }
                this.startDate = start;
                this.endDate = end;
                this.selectingEnd = false;
                this.hoveredDate = null;
                this._apply();
                return this.getRange();
            },

            get leftMonthLabel() {
                if (!this.leftMonth) return '';
                return _drpMonths[this.leftMonth.getMonth()] + ' ' + this.leftMonth.getFullYear();
            },

            get rightMonthLabel() {
                if (!this.rightMonth) return '';
                return _drpMonths[this.rightMonth.getMonth()] + ' ' + this.rightMonth.getFullYear();
            },

            get leftDays() {
                if (!this.leftMonth) return [];
                return this._buildDays(this.leftMonth.getFullYear(), this.leftMonth.getMonth());
            },

            get rightDays() {
                if (!this.rightMonth) return [];
                return this._buildDays(this.rightMonth.getFullYear(), this.rightMonth.getMonth());
            },

            _buildDays: function(year, month) {
                var cells = _drpBuildGrid(year, month);
                var today = new Date(); today.setHours(0,0,0,0);
                var self = this;
                return cells.map(function(c) {
                    c.isToday = _drpSameDay(c.date, today);
                    c.disabled = self._isDisabled(c.date);
                    return c;
                });
            },

            _isDisabled: function(d) {
                if (this._minDate && d < this._minDate) return true;
                if (this._maxDate && d > this._maxDate) return true;
                if (this.selectingEnd && this.startDate && this._maxSpan > 0) {
                    if (_drpDiffDays(d, this.startDate) > this._maxSpan) return true;
                }
                return false;
            },

            _ensureMonthOrder: function() {
                // Left must be strictly before right
                if (this.leftMonth >= this.rightMonth) {
                    this.rightMonth = _drpAddMonths(this.leftMonth, 1);
                }
            },

            open: function() {
                if (this.isOpen) return;
                this.isOpen = true;
                // Re-position months if we have selection
                if (this.startDate && !this.selectingEnd) {
                    this.leftMonth = _drpStartOfMonth(this.startDate);
                    if (this.endDate && !_drpSameDay(_drpStartOfMonth(this.startDate), _drpStartOfMonth(this.endDate))) {
                        this.rightMonth = _drpStartOfMonth(this.endDate);
                    } else {
                        this.rightMonth = _drpAddMonths(this.leftMonth, 1);
                    }
                    this._ensureMonthOrder();
                }
                var self = this;
                this.$nextTick(function() {
                    // Re-initialize Lucide icons inside popup
                    var popup = self.$el.querySelector('.mad-drp-popup');
                    if (popup && window.lucide) lucide.createIcons({ nodes: [popup] });
                });
            },

            close: function() {
                if (!this.isOpen) return;
                // If we were selecting end, cancel
                if (this.selectingEnd) {
                    this.selectingEnd = false;
                    // Restore previous end or clear start
                    if (!this.endDate) {
                        this.startDate = null;
                    }
                }
                this.hoveredDate = null;
                this.isOpen = false;
            },

            selectDate: function(d) {
                if (!d || this._isDisabled(d)) return;

                if (!this.selectingEnd) {
                    // First click: set start
                    this.startDate = new Date(d);
                    this.endDate = null;
                    this.selectingEnd = true;
                    this.hoveredDate = null;
                } else {
                    // Second click: set end
                    var end = new Date(d);
                    if (end < this.startDate) {
                        // Swap if end is before start
                        this.endDate = new Date(this.startDate);
                        this.startDate = end;
                    } else {
                        this.endDate = end;
                    }
                    this.selectingEnd = false;
                    this.hoveredDate = null;
                    this._apply();
                }
            },

            applyPreset: function(p) {
                this.startDate = new Date(p.start);
                this.endDate = new Date(p.end);
                this.selectingEnd = false;
                this.hoveredDate = null;
                this._apply();
            },

            isPresetActive: function(p) {
                if (!this.startDate || !this.endDate) return false;
                return _drpSameDay(this.startDate, p.start) && _drpSameDay(this.endDate, p.end);
            },

            clear: function() {
                this.startDate = null;
                this.endDate = null;
                this.selectingEnd = false;
                this.hoveredDate = null;
                this._syncHiddens();
                this._dispatchChange();
            },

            _apply: function() {
                this._syncHiddens();
                this._dispatchChange();
                this.isOpen = false;
            },

            _syncHiddens: function() {
                var el = this.$el;
                var s = el.querySelector('input[name="' + this._nameStart + '"]');
                var e = el.querySelector('input[name="' + this._nameEnd + '"]');
                if (s) { s.value = this.isoStart; s.dispatchEvent(new Event('change', { bubbles: true })); }
                if (e) { e.value = this.isoEnd;   e.dispatchEvent(new Event('change', { bubbles: true })); }
            },

            _dispatchChange: function() {
                // Trigger change event on start hidden input — MadWire detects data-mad-change on it
                var inp = this.$el.querySelector('input[name="' + this._nameStart + '"]');
                if (inp) {
                    inp.dispatchEvent(new Event('input', { bubbles: true }));
                }
            },

            isInRange: function(d) {
                if (!d || !this.startDate) return false;
                var end = this.endDate;
                // While selecting, use hovered date as temporary end
                if (this.selectingEnd && !end && this.hoveredDate) {
                    end = this.hoveredDate;
                }
                if (!end) return false;
                var lo = this.startDate, hi = end;
                if (lo > hi) { var tmp = lo; lo = hi; hi = tmp; }
                return d > lo && d < hi;
            },

            isStart: function(d) { return _drpSameDay(d, this.startDate); },
            isEnd: function(d) {
                if (this.endDate) return _drpSameDay(d, this.endDate);
                if (this.selectingEnd && this.hoveredDate) return _drpSameDay(d, this.hoveredDate);
                return false;
            },

            dayClasses: function(day) {
                var cls = {};
                cls['mad-drp-day--other'] = !day.currentMonth;
                cls['mad-drp-day--today'] = day.isToday;
                cls['mad-drp-day--disabled'] = day.disabled;
                cls['mad-drp-day--start'] = day.currentMonth && this.isStart(day.date);
                cls['mad-drp-day--end'] = day.currentMonth && this.isEnd(day.date);
                cls['mad-drp-day--in-range'] = day.currentMonth && this.isInRange(day.date);
                return cls;
            },

            prevMonth: function(side) {
                if (side === 'left') {
                    this.leftMonth = _drpAddMonths(this.leftMonth, -1);
                } else {
                    this.rightMonth = _drpAddMonths(this.rightMonth, -1);
                }
                this._ensureMonthOrder();
            },

            nextMonth: function(side) {
                if (side === 'left') {
                    this.leftMonth = _drpAddMonths(this.leftMonth, 1);
                } else {
                    this.rightMonth = _drpAddMonths(this.rightMonth, 1);
                }
                this._ensureMonthOrder();
            },
        };
    });

    /* ═══════════════════════════════════════════════════════════════════
       SINGLE-DATE PICKER — Pure Alpine.js (replaces jquery bootstrap-datepicker)
       Hybrid: typeable input + popup calendar. Used by mad-date-field,
       mad-datetime-field, MadDataGrid inline/click edit, and field-list cells.
       ═══════════════════════════════════════════════════════════════════ */

    // Strip non-digits from a value and re-apply the mask's literal separators.
    // Works for any mask combining yyyy/mm/dd/hh/ii tokens. Returns the masked
    // string truncated to the mask's digit-capacity.
    function _dpApplyMaskTyping(raw, mask) {
        if (raw == null) return '';
        var digits = String(raw).replace(/\D/g, '');
        // Count how many digit-slots the mask has so we don't overrun.
        var digitSlots = 0;
        for (var k = 0; k < mask.length; k++) {
            var t4 = mask.substr(k, 4);
            var t2 = mask.substr(k, 2);
            if (t4 === 'yyyy') { digitSlots += 4; k += 3; continue; }
            if (t2 === 'yy' || t2 === 'mm' || t2 === 'dd' ||
                t2 === 'hh' || t2 === 'HH' || t2 === 'ii' ||
                t2 === 'MM' || t2 === 'ss' || t2 === 'SS') {
                digitSlots += 2; k += 1; continue;
            }
        }
        digits = digits.substring(0, digitSlots);
        var out = '';
        var di = 0; // index in digits
        var i = 0;  // index in mask
        while (i < mask.length && di < digits.length) {
            var t4 = mask.substr(i, 4);
            var t2 = mask.substr(i, 2);
            if (t4 === 'yyyy') {
                out += digits.substring(di, di + 4);
                di += 4; i += 4;
            } else if (t2 === 'yy' || t2 === 'mm' || t2 === 'dd' ||
                       t2 === 'hh' || t2 === 'HH' || t2 === 'ii' ||
                       t2 === 'MM' || t2 === 'ss' || t2 === 'SS') {
                out += digits.substring(di, di + 2);
                di += 2; i += 2;
            } else {
                out += mask.charAt(i);
                i += 1;
            }
        }
        return out;
    }

    // Core picker — used for both date-only (madDatePicker) and date+time
    // (madDateTimePicker, which wires the time selects). The withTime flag
    // toggles the hh:ii panel inside the popup; mask configuration handles
    // formatting/parsing transparently.
    function _madDatePickerImpl(cfg, withTime) {
        cfg = cfg || {};
        var _db = cfg.databaseMask || (withTime ? 'yyyy-mm-dd HH:MM:SS' : 'yyyy-mm-dd');
        var _dp = cfg.displayMask  || (withTime ? 'dd/mm/yyyy hh:ii'    : 'dd/mm/yyyy');
        var parseAny = function(s) {
            if (!s) return null;
            return _drpParseWithMask(s, _dp)
                || _drpParseWithMask(s, _db)
                || _drpParseISO(s);
        };
        return {
            isOpen: false,
            selectedDate: null,
            currentMonth: null,
            hh: 0,
            mi: 0,
            _minDate: null,
            _maxDate: null,
            _databaseMask: _db,
            _displayMask: _dp,
            _withTime: !!withTime,
            _onCommit: typeof cfg.onCommit === 'function' ? cfg.onCommit : null,
            _maskingInput: false,

            init: function() {
                var inp = this._getInput();
                // Inicializa a partir do value visivel (Blade ja reformatou pro display mask)
                // ou do initialValue passado na cfg (formato display ou db ou ISO).
                this.selectedDate = parseAny(inp ? inp.value : '') || parseAny(cfg.initialValue || '');
                this._minDate = parseAny(cfg.min || '');
                this._maxDate = parseAny(cfg.max || '');
                this.currentMonth = _drpStartOfMonth(this.selectedDate || new Date());
                if (this.selectedDate) {
                    this.hh = this.selectedDate.getHours();
                    this.mi = this.selectedDate.getMinutes();
                }
                if (inp) {
                    // Popula input quando veio so via cfg.initialValue (Blade nao pre-formatou)
                    if (this.selectedDate && !inp.value) {
                        inp.value = _drpFormatWithMask(this.selectedDate, this._displayMask);
                    }
                    var self = this;
                    inp.addEventListener('input', function(e) { self._onInput(e); });
                    inp.addEventListener('blur',  function(e) { self._onBlur(e);  });
                }
            },

            _getInput: function() {
                // $root (component root), NOT $el — quando chamado de um @click numa
                // celula do popup, $el e o botao clicado, nao o .mad-input-group.
                return this.$root.querySelector('input[type="text"]');
            },

            get monthLabel() {
                if (!this.currentMonth) return '';
                return _drpMonths[this.currentMonth.getMonth()] + ' ' + this.currentMonth.getFullYear();
            },

            get days() {
                if (!this.currentMonth) return [];
                var cells = _drpBuildGrid(this.currentMonth.getFullYear(), this.currentMonth.getMonth());
                var today = new Date(); today.setHours(0,0,0,0);
                var self = this;
                return cells.map(function(c) {
                    c.isToday    = _drpSameDay(c.date, today);
                    c.isSelected = _drpSameDay(c.date, self.selectedDate);
                    c.disabled   = self._isDisabled(c.date);
                    return c;
                });
            },

            _isDisabled: function(d) {
                if (!d) return true;
                if (this._minDate) {
                    var dd = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                    var mn = new Date(this._minDate.getFullYear(), this._minDate.getMonth(), this._minDate.getDate());
                    if (dd < mn) return true;
                }
                if (this._maxDate) {
                    var dd2 = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                    var mx  = new Date(this._maxDate.getFullYear(), this._maxDate.getMonth(), this._maxDate.getDate());
                    if (dd2 > mx) return true;
                }
                return false;
            },

            toggle: function() { this.isOpen ? this.close() : this.open(); },

            open: function() {
                if (this.isOpen) return;
                this.isOpen = true;
                if (this.selectedDate) {
                    this.currentMonth = _drpStartOfMonth(this.selectedDate);
                }
                var self = this;
                this.$nextTick(function() {
                    var pop = self.$root.querySelector('.mad-drp-popup');
                    if (pop && window.lucide) lucide.createIcons({ nodes: [pop] });
                });
            },

            close: function() {
                if (!this.isOpen) return;
                this.isOpen = false;
            },

            prevMonth: function() { this.currentMonth = _drpAddMonths(this.currentMonth, -1); },
            nextMonth: function() { this.currentMonth = _drpAddMonths(this.currentMonth,  1); },

            // Setter EXTERNO — o contrato que `_madSyncMaskedField` procura
            // (mesmo nome do madNumericField/madMoneyField).
            //
            // Aqui o `[name]` é o input VISÍVEL, então quem preenche o campo de
            // fora (`_syncFormInputs` ao reabrir a linha do detalhe, `<fill>`,
            // dbseek, op `val` do wire) escrevia a data do banco crua e disparava
            // `input` — e o `_onInput` aplicava a máscara de DIGITAÇÃO por cima:
            // `2026-09-11` virava `20/26/0911` na tela E era PARSEADO (dia 20,
            // mês 26 → ano 913), virando a data que o próximo save gravaria.
            //
            // Aceita display (dd/mm/yyyy), máscara de banco e ISO — tudo que o
            // parseAny cobre. Não dispara onCommit: quem chama já notifica.
            setValue: function(v) {
                var d = parseAny(v || '');
                this.selectedDate = d;
                if (d) {
                    this.currentMonth = _drpStartOfMonth(d);
                    if (this._withTime) {
                        this.hh = d.getHours();
                        this.mi = d.getMinutes();
                    }
                }
                var inp = this._getInput();
                if (inp) {
                    // Segura o `_onInput` do evento que o chamador dispara logo
                    // depois — senão ele remascara o texto que acabamos de pôr.
                    this._maskingInput = true;
                    inp.value = d ? _drpFormatWithMask(d, this._displayMask) : '';
                    var self = this;
                    this.$nextTick(function() { self._maskingInput = false; });
                }
            },

            // Mesma coisa, com guarda de igualdade: é o que o `$watch` do
            // field-list e do mad-sheet chama a cada repaint da linha, e recriar
            // o Date a cada passada realimentaria o watch.
            setIso: function(v) {
                var d = parseAny(v || '');
                var same = (d && this.selectedDate)
                    ? (d.getTime() === this.selectedDate.getTime())
                    : (d === this.selectedDate);
                if (same) return;
                this.setValue(v);
            },

            selectDate: function(d) {
                if (!d || this._isDisabled(d)) return;
                // Preserva hora atual quando withTime; senao zera para meia-noite.
                var hh = this._withTime ? this.hh : 0;
                var mi = this._withTime ? this.mi : 0;
                this.selectedDate = new Date(d.getFullYear(), d.getMonth(), d.getDate(), hh, mi, 0);
                this._writeInput();
                if (!this._withTime) this.close();
            },

            selectToday: function() {
                var t = new Date();
                if (!this._withTime) t.setHours(0, 0, 0, 0);
                if (this._isDisabled(t)) return;
                this.selectedDate = t;
                this.currentMonth = _drpStartOfMonth(t);
                if (this._withTime) {
                    this.hh = t.getHours();
                    this.mi = t.getMinutes();
                }
                this._writeInput();
                this.close();
            },

            apply: function() {
                if (this.selectedDate) this._writeInput();
                this.close();
            },

            clear: function() {
                this.selectedDate = null;
                this._writeInput();
                this.close();
            },

            // Time control (datetime mode)
            setHour: function(v) {
                var n = parseInt(v, 10);
                if (isNaN(n)) n = 0;
                if (n < 0) n = 0; if (n > 23) n = 23;
                this.hh = n;
                this._syncTimeIntoSelectedDate();
            },
            setMinute: function(v) {
                var n = parseInt(v, 10);
                if (isNaN(n)) n = 0;
                if (n < 0) n = 0; if (n > 59) n = 59;
                this.mi = n;
                this._syncTimeIntoSelectedDate();
            },
            _syncTimeIntoSelectedDate: function() {
                if (!this.selectedDate) return;
                this.selectedDate = new Date(
                    this.selectedDate.getFullYear(),
                    this.selectedDate.getMonth(),
                    this.selectedDate.getDate(),
                    this.hh, this.mi, 0
                );
                this._writeInput();
            },

            _writeInput: function() {
                var inp = this._getInput();
                if (inp) {
                    this._maskingInput = true; // evita re-mask no _onInput
                    inp.value = this.selectedDate
                        ? _drpFormatWithMask(this.selectedDate, this._displayMask)
                        : '';
                    var self = this;
                    this.$nextTick(function() { self._maskingInput = false; });
                    inp.dispatchEvent(new Event('input',  { bubbles: true }));
                    inp.dispatchEvent(new Event('change', { bubbles: true }));
                }
                if (this._onCommit) {
                    var iso = this.selectedDate
                        ? _drpFormatWithMask(this.selectedDate, this._databaseMask)
                        : '';
                    try { this._onCommit.call(this, iso, this.selectedDate); } catch (e) {}
                }
            },

            _onInput: function(e) {
                if (this._maskingInput) return;
                var inp = e.target;
                var pos = inp.selectionStart;
                var before = inp.value;
                var masked = _dpApplyMaskTyping(before, this._displayMask);
                if (masked !== before) {
                    inp.value = masked;
                    // Mantem cursor onde estava (ajusta se inseriu separador antes dele)
                    var delta = masked.length - before.length;
                    var newPos = Math.max(0, Math.min(masked.length, pos + (delta > 0 ? delta : 0)));
                    try { inp.setSelectionRange(newPos, newPos); } catch (err) {}
                }
                // Re-posiciona o mes se completou e parseou OK
                if (masked.length === this._displayMask.length) {
                    var d = _drpParseWithMask(masked, this._displayMask);
                    if (d) {
                        this.selectedDate = d;
                        this.currentMonth = _drpStartOfMonth(d);
                        if (this._withTime) {
                            this.hh = d.getHours();
                            this.mi = d.getMinutes();
                        }
                        if (this._onCommit) {
                            var iso = _drpFormatWithMask(d, this._databaseMask);
                            try { this._onCommit.call(this, iso, d); } catch (err) {}
                        }
                    }
                }
            },

            _onBlur: function(e) {
                var v = e.target.value || '';
                if (!v) {
                    if (this.selectedDate !== null) {
                        this.selectedDate = null;
                        if (this._onCommit) { try { this._onCommit.call(this, '', null); } catch (err) {} }
                    }
                    return;
                }
                if (v.length < this._displayMask.length) {
                    // Incompleto — limpa
                    this._maskingInput = true;
                    e.target.value = '';
                    var self = this;
                    this.$nextTick(function() { self._maskingInput = false; });
                    this.selectedDate = null;
                    e.target.dispatchEvent(new Event('change', { bubbles: true }));
                    if (this._onCommit) { try { this._onCommit.call(this, '', null); } catch (err) {} }
                }
            },

            dayClasses: function(day) {
                return {
                    'mad-drp-day--other':    !day.currentMonth,
                    'mad-drp-day--today':    day.isToday,
                    'mad-drp-day--selected': day.isSelected && day.currentMonth,
                    'mad-drp-day--disabled': day.disabled,
                };
            },
        };
    }

    console.log('[mad-ui] registering madDatePicker / madDateTimePicker');
    Alpine.data('madDatePicker',     function(cfg) { return _madDatePickerImpl(cfg, false); });
    Alpine.data('madDateTimePicker', function(cfg) { return _madDatePickerImpl(cfg, true);  });

    /* ── OTP Field ── */
    Alpine.data('madOtpField', (cfg = {}) => {
        const length     = cfg.length ?? 6;
        const mode       = cfg.mode ?? 'numeric';
        const isPrivate  = cfg.private ?? false;
        const autoSubmit = cfg.autoSubmit ?? false;
        const madAction  = cfg.madAction ?? '';

        const patterns = {
            numeric:      /^[0-9]$/,
            alpha:        /^[a-zA-Z]$/,
            alphanumeric: /^[a-zA-Z0-9]$/,
        };

        return {
            digits: Array(length).fill(''),
            value: '',

            init() {
                var initial = cfg.value || '';
                if (initial) {
                    var chars = initial.split('');
                    for (var i = 0; i < Math.min(chars.length, length); i++) {
                        this.digits[i] = chars[i];
                        var ref = this.$refs['d' + i];
                        if (ref) ref.value = chars[i];
                    }
                    this.value = this.digits.join('');
                }
            },

            _isValid(ch) {
                return patterns[mode] ? patterns[mode].test(ch) : false;
            },

            _normalize(ch) {
                return mode !== 'numeric' ? ch.toUpperCase() : ch;
            },

            onInput(idx, e) {
                var raw = e.target.value;
                var ch = raw.slice(-1);
                if (!ch || !this._isValid(ch)) {
                    e.target.value = this.digits[idx];
                    return;
                }
                ch = this._normalize(ch);
                this.digits[idx] = ch;
                e.target.value = ch;
                this.value = this.digits.join('');

                if (idx < length - 1) {
                    var next = this.$refs['d' + (idx + 1)];
                    if (next) next.focus();
                }
                this._checkComplete();
            },

            onKeydown(idx, e) {
                if (e.key === 'Backspace') {
                    e.preventDefault();
                    if (this.digits[idx]) {
                        this.digits[idx] = '';
                        e.target.value = '';
                        this.value = this.digits.join('');
                    } else if (idx > 0) {
                        this.digits[idx - 1] = '';
                        var prev = this.$refs['d' + (idx - 1)];
                        if (prev) { prev.value = ''; prev.focus(); }
                        this.value = this.digits.join('');
                    }
                } else if (e.key === 'ArrowLeft' && idx > 0) {
                    var p = this.$refs['d' + (idx - 1)];
                    if (p) p.focus();
                } else if (e.key === 'ArrowRight' && idx < length - 1) {
                    var n = this.$refs['d' + (idx + 1)];
                    if (n) n.focus();
                } else if (e.key === 'Delete') {
                    e.preventDefault();
                    this.digits[idx] = '';
                    e.target.value = '';
                    this.value = this.digits.join('');
                }
            },

            onPaste(e) {
                e.preventDefault();
                var text = (e.clipboardData || window.clipboardData).getData('text').trim();
                var i = 0;
                for (var c = 0; c < text.length && i < length; c++) {
                    if (this._isValid(text[c])) {
                        var ch = this._normalize(text[c]);
                        this.digits[i] = ch;
                        var ref = this.$refs['d' + i];
                        if (ref) ref.value = ch;
                        i++;
                    }
                }
                this.value = this.digits.join('');

                var nextEmpty = this.digits.indexOf('');
                var focusIdx = nextEmpty >= 0 ? nextEmpty : length - 1;
                var focusRef = this.$refs['d' + focusIdx];
                if (focusRef) focusRef.focus();

                this._checkComplete();
            },

            _checkComplete() {
                if (!this.digits.every(function(d) { return d !== ''; })) return;

                // Update hidden input immediately
                var hidden = this.$el.querySelector('input[type="hidden"]');
                if (hidden) hidden.value = this.value;

                // mad:change -> MadWire call
                if (madAction) {
                    var wrapper = this.$el.closest('[data-mad-id]');
                    if (wrapper && typeof MadWire !== 'undefined') {
                        MadWire.call(wrapper, madAction, [this.value]);
                    }
                }

                // auto-submit
                if (autoSubmit) {
                    var form = this.$el.closest('[data-mad-submit]');
                    if (form) {
                        setTimeout(function() {
                            form.dispatchEvent(new Event('submit', { bubbles: true }));
                        }, 50);
                    }
                }
            },
        };
    });

    /* ═══════════════════════════════════════════════════════════════════
       SIGNATURE FIELD — Draw / Type / Upload with fullscreen mobile
       Uses SignaturePad.js (already bundled in bsignaturedrawcapture.js)
       ═══════════════════════════════════════════════════════════════════ */

    Alpine.data('madSignatureField', function(cfg) {
        if (!cfg) cfg = {};
        var _modesArr   = cfg.modes || ['draw'];
        var _fontsArr   = cfg.fonts || ['Dancing Script', 'Great Vibes', 'Courier Prime'];
        var _colorsArr  = cfg.penColors || ['#000000', '#1e40af', '#dc2626'];
        var _widthsArr  = cfg.penWidths || [1, 2, 4];
        var _maxSize    = cfg.maxBytes || 5 * 1024 * 1024;
        var _accept     = cfg.accept || 'image/png,image/jpeg';
        var _heightPx   = parseInt(cfg.height, 10) || 200;
        var _fontsLoaded = false;
        // Celular: desenhar é na tela cheia, e ela só abre por ação do usuário.
        var _isPhone    = function() { return window.innerWidth <= 768; };

        return {
            // ── State ──
            mode: '',
            hasSignature: false,
            signatureData: cfg.value || '',
            editing: false,
            removed: false,
            isFullscreen: false,
            dragover: false,

            // Draw
            penColor: cfg.penColor || '#000000',
            penWidth: cfg.penWidth || 2,
            _pad: null,
            _fsPad: null,
            _undoData: [],

            // Type
            typedText: '',
            selectedFont: _fontsArr[0] || 'Dancing Script',

            // ── Lifecycle ──
            init: function() {
                var self = this;

                if (this.signatureData) {
                    this.hasSignature = true;
                }

                // No celular o campo abre no "Clique para assinar" e o toque leva
                // à tela cheia (startEditing). Abrir a tela cheia aqui, no init,
                // empilhava uma por campo da página ao carregar — inclusive a de um
                // campo dentro de gaveta/modal FECHADA, já que a tela cheia vive
                // num x-teleport no <body> — e o usuário ficava preso atrás delas.
                if (!this.hasSignature && !_isPhone()) {
                    this.mode = _modesArr[0];
                    this.editing = true;
                }

                this.$nextTick(function() {
                    if (self.mode === 'draw') {
                        self._initPad();
                    }
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons({ nodes: [self.$el] });
                    }
                });

                if (_modesArr.indexOf('type') !== -1) {
                    this._loadFonts();
                }
            },

            // ── Mode switching ──
            switchMode: function(newMode) {
                if (this.mode === 'draw') {
                    this._destroyPad();
                }
                this.mode = newMode;
                this.editing = true;

                var self = this;
                this.$nextTick(function() {
                    if (newMode === 'draw') {
                        self._initPad();
                        if (_isPhone()) {
                            self.enterFullscreen();
                        }
                    }
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons({ nodes: [self.$el] });
                    }
                });
            },

            startEditing: function() {
                this.mode = _modesArr[0];
                this.editing = true;
                var self = this;
                this.$nextTick(function() {
                    if (self.mode === 'draw') {
                        self._initPad();
                        // Toque no "Clique para assinar": no celular, desenha em tela cheia.
                        if (_isPhone()) {
                            self.enterFullscreen();
                        }
                    }
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons({ nodes: [self.$el] });
                    }
                });
            },

            edit: function() {
                this.editing = true;
                var self = this;
                this.$nextTick(function() {
                    if (self.mode === 'draw') {
                        self._initPad();
                    }
                });
            },

            // ── Draw mode ──
            _initPad: function() {
                var canvas = this.$refs.canvas;
                if (!canvas || typeof SignaturePad === 'undefined') return;

                // Match the drawing buffer to the canvas's real rendered size
                // (CSS px × devicePixelRatio) BEFORE creating the pad, so pointer
                // coords map 1:1 into the buffer and strokes land under the cursor.
                this._fitCanvas(canvas);

                this._pad = new SignaturePad(canvas, {
                    backgroundColor: 'rgb(255, 255, 255)',
                    penColor: this.penColor,
                    minWidth: Math.max(this.penWidth * 0.5, 0.5),
                    maxWidth: this.penWidth * 1.5,
                });

                this._undoData = [];
                var self = this;
                this._pad.onEnd = function() {
                    self._undoData = self._pad.toData().slice();
                };

                // The canvas can be measured while its container is still sizing
                // (collapsed tab/accordion, modal/drawer animation, flex not yet
                // settled). Baking the buffer then leaves it smaller than the
                // displayed canvas, so strokes land offset (~2× to the right).
                // Two independent safety nets, both timing-proof:
                //  1) refit right before each stroke starts (capture phase, runs
                //     before SignaturePad's own mousedown/touchstart handler), so
                //     the buffer is always correct at the moment of drawing;
                //  2) a ResizeObserver for live viewport/layout changes.
                this._padGetter = function() { return self._pad; };
                this._refitBeforeStroke = function() { self._fitCanvas(canvas, self._pad); };
                canvas.addEventListener('mousedown', this._refitBeforeStroke, true);
                canvas.addEventListener('touchstart', this._refitBeforeStroke, true);
                this._refitCanvas = canvas;
                this._ro = this._observeResize(canvas, this._padGetter);
            },

            // Size the canvas buffer to its displayed size scaled by the device
            // pixel ratio, and scale the 2D context so all drawing happens in CSS
            // pixel coordinates. Idempotent: if the buffer already matches the
            // displayed size it does nothing (so it never clears strokes on a
            // no-op call). When a pad is passed and a resize IS needed, current
            // strokes are preserved across it.
            _fitCanvas: function(canvas, pad) {
                var ratio = Math.max(window.devicePixelRatio || 1, 1);
                var w = canvas.offsetWidth || (_heightPx * 2);
                var h = canvas.offsetHeight || _heightPx;
                if (w <= 0 || h <= 0) return;            // not laid out yet
                var bw = Math.round(w * ratio);
                var bh = Math.round(h * ratio);
                if (canvas.width === bw && canvas.height === bh) return; // already correct
                var data = (pad && !pad.isEmpty()) ? pad.toData() : null;
                canvas.width = bw;
                canvas.height = bh;
                canvas.getContext('2d').scale(ratio, ratio);
                if (pad) {
                    pad.clear();
                    if (data) pad.fromData(data);
                }
            },

            // Refit the buffer whenever the canvas's displayed size changes.
            // _fitCanvas is idempotent, so the initial synchronous callback and
            // our own buffer-attr writes are harmless no-ops (changing the buffer
            // attrs does not alter the CSS layout box, so this never loops).
            _observeResize: function(canvas, getPad) {
                if (typeof ResizeObserver === 'undefined') return null;
                var self = this;
                var ro = new ResizeObserver(function() {
                    self._fitCanvas(canvas, getPad());
                });
                ro.observe(canvas);
                return ro;
            },

            _destroyPad: function() {
                if (this._ro) {
                    this._ro.disconnect();
                    this._ro = null;
                }
                if (this._refitCanvas && this._refitBeforeStroke) {
                    this._refitCanvas.removeEventListener('mousedown', this._refitBeforeStroke, true);
                    this._refitCanvas.removeEventListener('touchstart', this._refitBeforeStroke, true);
                    this._refitCanvas = null;
                    this._refitBeforeStroke = null;
                }
                if (this._pad) {
                    this._pad.off();
                    this._pad = null;
                }
                this._undoData = [];
            },

            setPenColor: function(color) {
                this.penColor = color;
                if (this._pad) this._pad.penColor = color;
                if (this._fsPad) this._fsPad.penColor = color;
            },

            setPenWidth: function(w) {
                this.penWidth = w;
                if (this._pad) {
                    this._pad.minWidth = Math.max(w * 0.5, 0.5);
                    this._pad.maxWidth = w * 1.5;
                }
                if (this._fsPad) {
                    this._fsPad.minWidth = Math.max(w * 0.5, 0.5);
                    this._fsPad.maxWidth = w * 1.5;
                }
            },

            undo: function() {
                var pad = this.isFullscreen ? this._fsPad : this._pad;
                if (!pad) return;
                var data = pad.toData();
                if (data.length > 0) {
                    data.pop();
                    pad.fromData(data);
                    this._undoData = data.slice();
                }
            },

            clearPad: function() {
                var pad = this.isFullscreen ? this._fsPad : this._pad;
                if (pad) {
                    pad.clear();
                    this._undoData = [];
                }
            },

            confirmDraw: function() {
                var pad = this._pad;
                if (!pad || pad.isEmpty()) {
                    if (typeof madToast === 'function') madToast('Desenhe sua assinatura primeiro', 'warning');
                    return;
                }
                this.signatureData = pad.toDataURL('image/png');
                this.hasSignature = true;
                this.editing = false;
                this._destroyPad();
                this._syncHidden();
            },

            // ── Fullscreen ──
            enterFullscreen: function() {
                this.isFullscreen = true;
                var self = this;

                this.$nextTick(function() {
                    var fsCanvas = self.$refs.fullscreenCanvas;
                    if (!fsCanvas || typeof SignaturePad === 'undefined') return;

                    // Size buffer to real rendered size × DPR before creating the
                    // pad so strokes land under the cursor (flex sizing makes the
                    // displayed size differ from parent dimensions).
                    self._fitCanvas(fsCanvas);

                    self._fsPad = new SignaturePad(fsCanvas, {
                        backgroundColor: 'rgb(255, 255, 255)',
                        penColor: self.penColor,
                        minWidth: Math.max(self.penWidth * 0.5, 0.5),
                        maxWidth: self.penWidth * 1.5,
                    });

                    if (self._pad && !self._pad.isEmpty()) {
                        self._fsPad.fromData(self._pad.toData());
                    }

                    self._fsPad.onEnd = function() {
                        self._undoData = self._fsPad.toData().slice();
                    };

                    // Refit on viewport/orientation change while in fullscreen.
                    self._roFs = self._observeResize(fsCanvas, function() { return self._fsPad; });

                    if (typeof lucide !== 'undefined') {
                        var overlay = fsCanvas.closest('.mad-signature-fullscreen');
                        if (overlay) lucide.createIcons({ nodes: [overlay] });
                    }
                });
            },

            _destroyFsPad: function() {
                if (this._roFs) {
                    this._roFs.disconnect();
                    this._roFs = null;
                }
                if (this._fsPad) {
                    this._fsPad.off();
                    this._fsPad = null;
                }
            },

            exitFullscreen: function() {
                if (this._fsPad && this._pad) {
                    var data = this._fsPad.toData();
                    if (data.length > 0) {
                        this._pad.fromData(data);
                        this._undoData = data.slice();
                    }
                }
                this._destroyFsPad();
                this.isFullscreen = false;
            },

            confirmFullscreen: function() {
                var pad = this._fsPad;
                if (!pad || pad.isEmpty()) {
                    if (typeof madToast === 'function') madToast('Desenhe sua assinatura primeiro', 'warning');
                    return;
                }
                this.signatureData = pad.toDataURL('image/png');
                this.hasSignature = true;
                this.editing = false;
                this._destroyFsPad();
                this._destroyPad();
                this.isFullscreen = false;
                this._syncHidden();
            },

            // ── Type mode ──
            _loadFonts: function() {
                if (_fontsLoaded) return;
                _fontsLoaded = true;
                var families = _fontsArr.map(function(f) { return f.replace(/ /g, '+'); }).join('&family=');
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = 'https://fonts.googleapis.com/css2?family=' + families + '&display=swap';
                document.head.appendChild(link);
            },

            onTextInput: function() {
                // Reactive — Alpine x-model handles text binding
            },

            selectFont: function(font) {
                this.selectedFont = font;
            },

            confirmType: function() {
                var text = this.typedText.trim();
                if (!text) return;

                var canvas = document.createElement('canvas');
                var ctx = canvas.getContext('2d');
                var fontSize = 48;
                var font = fontSize + 'px "' + this.selectedFont + '", cursive';

                ctx.font = font;
                var metrics = ctx.measureText(text);
                var textWidth = metrics.width;

                canvas.width = Math.max(textWidth + 40, 200);
                canvas.height = 80;

                // White background
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // Draw text
                ctx.font = font;
                ctx.fillStyle = this.penColor;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(text, canvas.width / 2, canvas.height / 2);

                this.signatureData = canvas.toDataURL('image/png');
                this.hasSignature = true;
                this.editing = false;
                this._syncHidden();
            },

            // ── Upload mode ──
            onUploadSelect: function(e) {
                var file = e.target.files && e.target.files[0];
                if (file) this._processUploadFile(file);
                if (!cfg.storage) e.target.value = '';
            },

            onUploadDrop: function(e) {
                this.dragover = false;
                var file = e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
                if (file) this._processUploadFile(file);
            },

            _processUploadFile: function(file) {
                // Tipos aceitos (`image/*`, `image/png`, `.png`) e tamanho, como no campo Imagem.
                var problem = _madUploadProblem(file, { accept: _accept, maxBytes: _maxSize });
                if (problem) {
                    _madUploadWarn(problem);
                    return;
                }
                var self = this;
                var reader = new FileReader();
                reader.onload = function(ev) {
                    self.signatureData = ev.target.result;
                    self.hasSignature = true;
                    self.editing = false;
                    self.removed = false;
                    self._syncHidden();
                };
                reader.readAsDataURL(file);
            },

            // ── Common ──
            clear: function() {
                this._destroyPad();
                this.removed = true;
                this.hasSignature = false;
                this.signatureData = '';
                this.typedText = '';
                this.editing = true;
                this.mode = _modesArr[0];
                if (this.$refs.fileInput) this.$refs.fileInput.value = '';
                this._syncHidden();
                var self = this;
                this.$nextTick(function() {
                    if (self.mode === 'draw') self._initPad();
                    if (typeof lucide !== 'undefined') {
                        lucide.createIcons({ nodes: [self.$el] });
                    }
                });
            },

            _syncHidden: function() {
                var hiddens = this.$el.querySelectorAll('input[type="hidden"]');
                for (var h = 0; h < hiddens.length; h++) {
                    if (hiddens[h].getAttribute('mad:model') === cfg.name
                     || hiddens[h].getAttribute('data-mad-model') === cfg.name) {
                        hiddens[h].value = this.signatureData;
                        break;
                    }
                }
                if (cfg.storage && this.signatureData) {
                    this._injectFile();
                }
                this._fireMadChange();
            },

            _injectFile: function() {
                var b64 = this.signatureData;
                var match = b64.match(/^data:(image\/\w+);base64,(.+)$/);
                if (!match) return;
                var mime = match[1];
                var ext = mime.split('/')[1] || 'png';
                var bin = atob(match[2]);
                var arr = new Uint8Array(bin.length);
                for (var k = 0; k < bin.length; k++) arr[k] = bin.charCodeAt(k);
                var file = new File([arr], cfg.name + '.' + ext, { type: mime });
                var fileInput = this.$refs.fileInput;
                if (fileInput) {
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    fileInput.files = dt.files;
                }
            },

            _fireMadChange: function() {
                var action = cfg.madChangeAction;
                if (!action || !this.signatureData) return;
                var wrapper = this.$el.closest('[mad-component]');
                if (!wrapper || typeof MadWire === 'undefined') return;

                var b64 = this.signatureData;
                var match = b64.match(/^data:(image\/\w+);base64,(.+)$/);
                if (!match) return;
                var mime = match[1];
                var ext = mime.split('/')[1] || 'png';
                var bin = atob(match[2]);
                var arr = new Uint8Array(bin.length);
                for (var k = 0; k < bin.length; k++) arr[k] = bin.charCodeAt(k);
                var file = new File([arr], cfg.name + '.' + ext, { type: mime });

                var tmpId = '__mad_sig_file_' + cfg.name;
                var tmpInput = wrapper.querySelector('#' + tmpId);
                if (!tmpInput) {
                    tmpInput = document.createElement('input');
                    tmpInput.type = 'file';
                    tmpInput.id = tmpId;
                    tmpInput.name = cfg.name;
                    tmpInput.style.display = 'none';
                    wrapper.appendChild(tmpInput);
                }
                var dt = new DataTransfer();
                dt.items.add(file);
                tmpInput.files = dt.files;

                MadWire.call(wrapper, action, [cfg.name]);
            },

            destroy: function() {
                this._destroyPad();
                if (this._fsPad) {
                    this._fsPad.off();
                    this._fsPad = null;
                }
            },
        };
    });
});

// -- madTreeView -------------------------------------------------------

document.addEventListener("alpine:init", function () {
    Alpine.data('madTreeView', function (cfg) {
        return {
            name: cfg.name || '',
            active: String(cfg.active || ''),
            expanded: new Set((cfg.expanded || []).map(String)),
            persist: cfg.persist || false,
            click: cfg.click || '',
            _icon: cfg.icon || 'file',
            _activeIcon: cfg.activeIcon || '',
            _expandedIcon: cfg.expandedIcon || '',

            init: function() {
                if (this.persist) {
                    this._restoreState();
                }
            },

            toggle: function(id) {
                id = String(id);
                if (this.expanded.has(id)) {
                    this.expanded.delete(id);
                } else {
                    this.expanded.add(id);
                }
                this._refreshIcon(id);
                if (this.persist) this._saveState();
            },

            select: function(id) {
                id = String(id);
                var prevActive = this.active;
                this.active = id;
                // Refresh icons on old and new active nodes
                if (prevActive) this._refreshIcon(prevActive);
                this._refreshIcon(id);
                if (this.click) {
                    var wrapper = this.$el.closest('[mad-component]');
                    if (wrapper) MadWire.call(wrapper, this.click, [id]);
                }
            },

            isExpanded: function(id) {
                return this.expanded.has(String(id));
            },

            isActive: function(id) {
                return this.active === String(id);
            },

            // ── Persistence ────────────────────────────────────────────────

            _refreshIcon: function(id) {
                if (!this._activeIcon && !this._expandedIcon) return;
                var node = this.$el.querySelector('[data-tree-id="' + id + '"]');
                if (!node) return;
                var iconEl = node.querySelector(':scope > .mad-context, :scope > .mad-tree-node-content');
                if (!iconEl) return;
                iconEl = iconEl.querySelector('.mad-tree-icon');
                if (!iconEl) return;
                var newIcon = this._icon;
                if (this._activeIcon && this.active === String(id)) {
                    newIcon = this._activeIcon;
                } else if (this._expandedIcon && this.expanded.has(String(id))) {
                    newIcon = this._expandedIcon;
                }
                // Replace the icon by swapping the SVG
                var svg = iconEl.tagName === 'svg' ? iconEl : iconEl.querySelector('svg');
                if (svg) {
                    var newI = document.createElement('i');
                    newI.setAttribute('data-lucide', newIcon);
                    newI.className = 'mad-tree-icon';
                    newI.style.cssText = 'width:14px;height:14px;flex-shrink:0;';
                    svg.parentNode.replaceChild(newI, svg);
                    if (window.lucide) lucide.createIcons({ nodes: [newI] });
                } else if (iconEl.tagName === 'I') {
                    iconEl.setAttribute('data-lucide', newIcon);
                    if (window.lucide) lucide.createIcons({ nodes: [iconEl] });
                }
            },

            _saveState: function() {
                try {
                    localStorage.setItem('mad-tree-' + this.name, JSON.stringify(Array.from(this.expanded)));
                } catch(e) {}
            },

            _restoreState: function() {
                try {
                    var saved = JSON.parse(localStorage.getItem('mad-tree-' + this.name));
                    if (Array.isArray(saved) && saved.length > 0) {
                        this.expanded = new Set(saved.map(String));
                    }
                } catch(e) {}
            },

            // ── MadResponse op handlers ────────────────────────────────────

            addNode: function(node) {
                var tree = this.$el;
                var parentId = node.parent_id ? String(node.parent_id) : '';
                var nid = String(node.id);
                var label = node.label || node.name || '';
                var nodeIcon = node.icon || 'file';
                var count = node.count || 0;

                // Calculate depth
                var depth = 0;
                if (parentId) {
                    var parentEl = tree.querySelector('[data-tree-id="' + parentId + '"]');
                    if (parentEl) {
                        var parentContent = parentEl.querySelector('.mad-tree-node-content');
                        if (parentContent) {
                            depth = Math.round((parseInt(parentContent.style.paddingLeft) - 4) / 16) + 1;
                        }
                    }
                }

                var pad = 4 + (depth * 16);

                // Build node HTML
                var menuTpl = document.querySelector('script[data-tree-menu="' + this.name + '"]');
                var menuHtml = '';
                if (menuTpl) {
                    menuHtml = menuTpl.textContent
                        .replace(/\{id\}/g, nid)
                        .replace(/\{name\}/g, this._escHtml(label))
                        .replace(/\{label\}/g, this._escHtml(label));
                }

                var html = '<div class="mad-tree-node mad-tree-node-highlight" data-tree-id="' + nid + '" data-tree-parent="' + parentId + '">';

                if (menuHtml) {
                    html += '<div class="mad-context" x-data="madContextMenu()" @contextmenu.prevent="openAt($event)" @click.outside="close()" @keydown.escape.window="close()">';
                }

                html += '<div class="mad-tree-node-content" style="padding-left:' + pad + 'px;"';
                html += " @click=\"select('" + nid + "')\"";
                html += " :class=\"isActive('" + nid + "') ? 'active' : ''\">";
                html += '<span class="mad-tree-toggle-spacer"></span>';
                html += '<i data-lucide="' + nodeIcon + '" class="mad-tree-icon" style="width:14px;height:14px;flex-shrink:0;"></i>';
                html += '<span class="mad-tree-label">' + this._escHtml(label) + '</span>';
                if (count > 0) {
                    html += '<span class="mad-tree-badge">' + count + '</span>';
                }
                html += '</div>';

                if (menuHtml) {
                    html += '<div class="mad-context-menu" x-show="open" x-cloak @click="close()" role="menu">';
                    html += menuHtml;
                    html += '</div></div>';
                }

                html += '</div>';

                // Insert into DOM
                var container;
                if (parentId) {
                    var parentNode = tree.querySelector('[data-tree-id="' + parentId + '"]');
                    if (parentNode) {
                        container = parentNode.querySelector(':scope > .mad-tree-children');
                        if (!container) {
                            // Parent had no children — create container and add toggle
                            container = document.createElement('div');
                            container.className = 'mad-tree-children';
                            parentNode.appendChild(container);

                            // Add toggle button to parent
                            var parentContent = parentNode.querySelector('.mad-tree-node-content');
                            var spacer = parentContent ? parentContent.querySelector('.mad-tree-toggle-spacer') : null;
                            if (spacer) {
                                var btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'mad-tree-toggle';
                                btn.setAttribute('@click.stop', "toggle('" + parentId + "')");
                                btn.innerHTML = '<i data-lucide="chevron-right" style="width:12px;height:12px;transition:transform 0.15s ease;" :style="isExpanded(\'' + parentId + '\') ? \'transform:rotate(90deg)\' : \'\'"></i>';
                                spacer.replaceWith(btn);
                            }
                        }
                        // Expand parent
                        this.expanded.add(parentId);
                    }
                }

                if (!container) {
                    container = tree.querySelector('.mad-tree-nodes');
                }

                if (container) {
                    container.insertAdjacentHTML('beforeend', html);
                    var newNode = container.querySelector('[data-tree-id="' + nid + '"]');
                    if (newNode) {
                        if (window.lucide) lucide.createIcons({ nodes: [newNode] });
                        if (typeof _madRewriteAlpineAttrs === 'function') _madRewriteAlpineAttrs(newNode);
                        if (window.Alpine) Alpine.initTree(newNode);
                        setTimeout(function() {
                            newNode.classList.remove('mad-tree-node-highlight');
                        }, 1500);
                    }
                }

                if (this.persist) this._saveState();
            },

            removeNode: function(id) {
                id = String(id);
                var tree = this.$el;
                var node = tree.querySelector('[data-tree-id="' + id + '"]');
                if (!node) return;

                var parentId = node.dataset.treeParent;

                // Animate out
                node.style.transition = 'opacity 0.2s ease, max-height 0.2s ease';
                node.style.opacity = '0';
                node.style.maxHeight = node.offsetHeight + 'px';
                node.style.overflow = 'hidden';

                var self = this;
                setTimeout(function() {
                    node.style.maxHeight = '0';
                    setTimeout(function() {
                        node.remove();

                        // Check if parent now has no children
                        if (parentId) {
                            var parentNode = tree.querySelector('[data-tree-id="' + parentId + '"]');
                            if (parentNode) {
                                var childContainer = parentNode.querySelector(':scope > .mad-tree-children');
                                if (childContainer && childContainer.children.length === 0) {
                                    childContainer.remove();
                                    // Replace toggle with spacer
                                    var toggle = parentNode.querySelector('.mad-tree-toggle');
                                    if (toggle) {
                                        var spacer = document.createElement('span');
                                        spacer.className = 'mad-tree-toggle-spacer';
                                        toggle.replaceWith(spacer);
                                    }
                                }
                            }
                        }

                        // Remove from expanded
                        self.expanded.delete(id);
                        if (self.active === id) self.active = '';
                        if (self.persist) self._saveState();
                    }, 200);
                }, 10);
            },

            updateNode: function(id, data) {
                id = String(id);
                var tree = this.$el;
                var node = tree.querySelector('[data-tree-id="' + id + '"]');
                if (!node) return;

                var content = node.querySelector('.mad-tree-node-content');
                if (!content) return;

                // Update label
                if (data.label || data.name) {
                    var labelEl = content.querySelector('.mad-tree-label');
                    if (labelEl) labelEl.textContent = data.label || data.name;
                }

                // Update icon
                if (data.icon) {
                    var iconEl = content.querySelector('.mad-tree-icon');
                    if (iconEl) {
                        iconEl.setAttribute('data-lucide', data.icon);
                        if (window.lucide) lucide.createIcons({ nodes: [iconEl] });
                    }
                }

                // Update badge count
                if (data.count !== undefined) {
                    var badge = content.querySelector('.mad-tree-badge');
                    if (data.count > 0) {
                        if (badge) {
                            badge.textContent = data.count;
                            badge.style.display = '';
                        } else {
                            content.insertAdjacentHTML('beforeend', '<span class="mad-tree-badge">' + data.count + '</span>');
                        }
                    } else if (badge) {
                        badge.style.display = 'none';
                    }
                }

                // Flash highlight
                node.classList.add('mad-tree-node-highlight');
                setTimeout(function() {
                    node.classList.remove('mad-tree-node-highlight');
                }, 1500);
            },

            setActive: function(id) {
                id = String(id);
                this.active = id;

                // Auto-expand ancestors
                var tree = this.$el;
                var node = tree.querySelector('[data-tree-id="' + id + '"]');
                if (node) {
                    var parent = node.parentElement;
                    while (parent && !parent.classList.contains('mad-tree')) {
                        if (parent.classList.contains('mad-tree-node')) {
                            var pid = parent.dataset.treeId;
                            if (pid) this.expanded.add(pid);
                        }
                        parent = parent.parentElement;
                    }
                    if (this.persist) this._saveState();
                }
            },

            _escHtml: function(str) {
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            },
        };
    });
});

// ═══════════════════════════════════════════════════════════════════════════
// Tooltip global MAD — substitui o Bootstrap tooltip do front-end legado.
// Event delegation em [title] / [data-mad-title]. Usa as classes .mad-tooltip*
// do mad-ui.css, posicionado via getBoundingClientRect + .mad-tooltip-floating.
// Compatibilidade: respeita o atributo `titside` (top|bottom|left|right).
// ═══════════════════════════════════════════════════════════════════════════
(function () {
    var DELAY_SHOW = 200;
    var VALID_SIDES = { top: 1, bottom: 1, left: 1, right: 1 };

    var tipEl = null;
    var currentTarget = null;
    var showTimer = null;

    function ensureTipEl() {
        if (tipEl && document.body.contains(tipEl)) return tipEl;
        tipEl = document.createElement('div');
        tipEl.className = 'mad-tooltip mad-tooltip-floating mad-tooltip-top';
        tipEl.setAttribute('role', 'tooltip');
        tipEl.style.display = 'none';
        document.body.appendChild(tipEl);
        return tipEl;
    }

    function hasTooltipText(el) {
        if (!el || el.nodeType !== 1) return false;
        var t = el.getAttribute('data-mad-title');
        if (t !== null && t !== '') return true;
        t = el.getAttribute('title');
        return t !== null && t !== '';
    }

    function findTooltipEl(target) {
        var el = target;
        while (el && el !== document.body && el.nodeType === 1) {
            if (hasTooltipText(el)) return el;
            el = el.parentElement;
        }
        return null;
    }

    function getTooltipText(el) {
        var t = el.getAttribute('data-mad-title');
        if (t !== null && t !== '') return t;
        return el.getAttribute('title') || '';
    }

    function stashTitle(el) {
        var t = el.getAttribute('title');
        if (t !== null && t !== '') {
            if (!el.hasAttribute('data-mad-title')) {
                el.setAttribute('data-mad-title', t);
            }
            // Botão/link só de ícone: o title era o NOME que o leitor de tela
            // lê ("Excluir", "Sem permissão para excluir"). Sem ele depois do
            // primeiro hover, o controle ficava mudo — o nome passa pro aria-label.
            if (el.matches && el.matches('button, a[href], [role="button"]')
                && !el.hasAttribute('aria-label') && !el.hasAttribute('aria-labelledby')
                && !(el.textContent || '').trim()) {
                el.setAttribute('aria-label', t);
            }
            // Remove native title para suprimir tooltip do browser.
            el.removeAttribute('title');
        }
    }

    function placementFor(el) {
        var side = el.getAttribute('titside');
        return (side && VALID_SIDES[side]) ? side : 'top';
    }

    function positionTip(el, side) {
        var rect = el.getBoundingClientRect();
        var tip = tipEl;
        // Aplica classes antes de medir (os estilos definem padding etc).
        tip.className = 'mad-tooltip mad-tooltip-floating mad-tooltip-' + side;
        // Mede visivel-mas-oculto para pegar largura/altura corretas.
        tip.style.visibility = 'hidden';
        tip.style.display = 'block';
        tip.style.top = '0px';
        tip.style.left = '0px';
        var tipRect = tip.getBoundingClientRect();

        var top, left;
        switch (side) {
            case 'bottom':
                top = rect.bottom + 6;
                left = rect.left + (rect.width - tipRect.width) / 2;
                break;
            case 'left':
                top = rect.top + (rect.height - tipRect.height) / 2;
                left = rect.left - tipRect.width - 6;
                break;
            case 'right':
                top = rect.top + (rect.height - tipRect.height) / 2;
                left = rect.right + 6;
                break;
            default: // top
                top = rect.top - tipRect.height - 6;
                left = rect.left + (rect.width - tipRect.width) / 2;
        }

        // Clamp na viewport (margem 4px).
        var m = 4;
        var vw = window.innerWidth;
        var vh = window.innerHeight;
        if (left < m) left = m;
        if (left + tipRect.width > vw - m) left = vw - tipRect.width - m;
        if (top < m) top = m;
        if (top + tipRect.height > vh - m) top = vh - tipRect.height - m;

        tip.style.top = top + 'px';
        tip.style.left = left + 'px';
        tip.style.visibility = 'visible';
    }

    function show(el) {
        if (!el) return;
        var text = getTooltipText(el);
        if (!text) return;
        stashTitle(el);
        ensureTipEl();
        // html:true compat (o front-end legado permitia tags no title).
        tipEl.innerHTML = text;
        positionTip(el, placementFor(el));
        currentTarget = el;
    }

    function hide() {
        if (showTimer) { clearTimeout(showTimer); showTimer = null; }
        if (tipEl) tipEl.style.display = 'none';
        currentTarget = null;
    }

    function scheduleShow(el) {
        if (showTimer) { clearTimeout(showTimer); showTimer = null; }
        showTimer = setTimeout(function () { show(el); }, DELAY_SHOW);
    }

    document.addEventListener('mouseover', function (e) {
        var el = findTooltipEl(e.target);
        if (!el) return;
        if (el === currentTarget) return;
        hide();
        scheduleShow(el);
    }, true);

    document.addEventListener('mouseout', function (e) {
        if (showTimer) {
            // Ainda pendente — se o mouse saiu do alvo antes de abrir, cancela.
            var rel = e.relatedTarget;
            var el = findTooltipEl(e.target);
            if (el && rel && el.contains(rel)) return;
            clearTimeout(showTimer);
            showTimer = null;
        }
        if (!currentTarget) return;
        var related = e.relatedTarget;
        if (related && currentTarget.contains(related)) return;
        hide();
    }, true);

    document.addEventListener('focusin', function (e) {
        var el = findTooltipEl(e.target);
        if (!el) return;
        hide();
        show(el);
    });

    document.addEventListener('focusout', function () {
        hide();
    });

    // Esconder em interacoes que "mudam contexto".
    document.addEventListener('click', hide, true);
    window.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);

    // Ação recusada pelo perfil ("Ações sem permissão" = desabilitados, com
    // dica): o botão vem com aria-disabled + data-mad-deny, NUNCA `disabled` —
    // com `disabled` o tema tira os eventos de mouse (pointer-events:none) e a
    // dica nunca aparecia, nem pelo teclado. O clique (mouse, Enter/Espaço,
    // toque) é barrado aqui, na captura, ANTES de qualquer handler — Alpine,
    // MadWire (data-mad-click), onclick, submit, link — e a dica aparece, que
    // é o que o toque no celular (sem hover) precisa.
    window.addEventListener('click', function (e) {
        var t = e.target;
        var el = t && t.closest ? t.closest('[data-mad-deny][aria-disabled="true"]') : null;
        if (!el) return;
        e.preventDefault();
        e.stopImmediatePropagation();
        hide();
        show(el);
    }, true);
})();

/* ═══════════════════════════════════════════════════════════════════════════
   Mad Lightbox — visualizador de imagem das células de mídia do grid
   (transformadores file-avatar / file-thumb / file-gallery).

   Delegação global em [data-mad-lightbox]: funciona em células re-renderizadas
   por AJAX sem re-init. Grupo (data-mad-lightbox-group) vira galeria navegável
   (setas / ← → / swipe-less). Esc e clique no backdrop fecham. Zero deps.
   ═══════════════════════════════════════════════════════════════════════════ */
(function () {
    'use strict';

    var state = { items: [], index: 0, overlay: null };

    function collect(el) {
        var group = el.getAttribute('data-mad-lightbox-group');
        var nodes = group
            ? document.querySelectorAll('[data-mad-lightbox][data-mad-lightbox-group="' + CSS.escape(group) + '"]')
            : [el];
        var items = [];
        var index = 0;
        Array.prototype.forEach.call(nodes, function (n, i) {
            if (n === el) index = i;
            items.push({
                url:  n.getAttribute('data-mad-lightbox'),
                name: n.getAttribute('data-mad-lightbox-name') || '',
                dl:   n.getAttribute('data-mad-lightbox-dl') || ''
            });
        });
        return { items: items, index: index };
    }

    function render() {
        var it = state.items[state.index];
        if (!it) return;
        var img = state.overlay.querySelector('.mad-lightbox-img');
        var cap = state.overlay.querySelector('.mad-lightbox-caption');
        var dl  = state.overlay.querySelector('.mad-lightbox-dl');
        var cnt = state.overlay.querySelector('.mad-lightbox-count');
        img.src = it.url;
        img.alt = it.name;
        cap.textContent = it.name;
        if (it.dl) { dl.href = it.dl; dl.style.display = ''; } else { dl.style.display = 'none'; }
        cnt.textContent = state.items.length > 1 ? (state.index + 1) + ' / ' + state.items.length : '';
    }

    function nav(delta) {
        var n = state.items.length;
        if (n < 2) return;
        state.index = (state.index + delta + n) % n;
        render();
    }

    function close() {
        if (!state.overlay) return;
        state.overlay.remove();
        state.overlay = null;
        document.removeEventListener('keydown', onKey);
    }

    function onKey(e) {
        if (e.key === 'Escape') close();
        else if (e.key === 'ArrowRight') nav(1);
        else if (e.key === 'ArrowLeft') nav(-1);
    }

    function open(items, index) {
        close();
        state.items = items;
        state.index = index;

        var multi = items.length > 1;
        var ov = document.createElement('div');
        ov.className = 'mad-lightbox-overlay';
        ov.innerHTML =
            '<button type="button" class="mad-lightbox-close" aria-label="Fechar">&#10005;</button>' +
            (multi ? '<button type="button" class="mad-lightbox-prev" aria-label="Anterior">&#10094;</button>' : '') +
            '<figure class="mad-lightbox-figure">' +
                '<img class="mad-lightbox-img" alt="">' +
                '<figcaption class="mad-lightbox-bar">' +
                    '<span class="mad-lightbox-caption"></span>' +
                    '<span class="mad-lightbox-count"></span>' +
                    '<a class="mad-lightbox-dl" target="_blank" rel="noopener">Baixar</a>' +
                '</figcaption>' +
            '</figure>' +
            (multi ? '<button type="button" class="mad-lightbox-next" aria-label="Próxima">&#10095;</button>' : '');

        ov.addEventListener('click', function (e) {
            if (e.target === ov) close();
        });
        ov.querySelector('.mad-lightbox-close').addEventListener('click', close);
        if (multi) {
            ov.querySelector('.mad-lightbox-prev').addEventListener('click', function () { nav(-1); });
            ov.querySelector('.mad-lightbox-next').addEventListener('click', function () { nav(1); });
        }

        document.body.appendChild(ov);
        state.overlay = ov;
        document.addEventListener('keydown', onKey);
        render();
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest ? e.target.closest('[data-mad-lightbox]') : null;
        if (trigger) {
            e.preventDefault();
            e.stopPropagation();
            var c = collect(trigger);
            open(c.items, c.index);
            return;
        }
        // "+N" da galeria: abre o grupo inteiro a partir do primeiro item.
        var more = e.target.closest ? e.target.closest('[data-mad-lightbox-open]') : null;
        if (more) {
            e.preventDefault();
            e.stopPropagation();
            var group = more.getAttribute('data-mad-lightbox-open');
            var first = document.querySelector('[data-mad-lightbox][data-mad-lightbox-group="' + CSS.escape(group) + '"]');
            if (first) {
                var g = collect(first);
                open(g.items, 0);
            }
        }
    }, true);

    // Enter/Espaço abrem quando o thumb está focado (role="button").
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        var el = document.activeElement;
        if (el && el.hasAttribute && el.hasAttribute('data-mad-lightbox')) {
            e.preventDefault();
            var c = collect(el);
            open(c.items, c.index);
        }
    });
})();
