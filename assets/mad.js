/**
 * mad.js — Motor AJAX moderno. Substituto do runtime legado.
 *
 * Usa fetch() + DOM nativo. Sem dependência de jQuery para AJAX.
 * Mantém funções __mad_* para compatibilidade com código legado.
 *
 * Novidades vs runtime legado:
 *   - Mad.exec('Classe@metodo', dados)   → chama método estático PHP
 *   - Mad.applyOps(ops)                  → aplica MadResponse no DOM
 *   - data-mad-action="Classe@metodo"    → field binding automático
 *   - data-mad-trigger="change|blur|input"
 *   - data-mad-target="#seletor"         → atualiza só esse elemento
 *
 * Inclua ANTES do Alpine.js e DEPOIS do Lucide.
 */

// ─── madTabsScroller — fábrica Alpine do strip de abas (<mad-tabs>) ───────────
// Definida AQUI (asset global, carregado ANTES do Alpine) além do <script>
// inline de components/tabs-list.blade.php. Motivo: quando o <mad-tabs> é
// renderizado dentro de um drawer/modal injetado por AJAX, o Alpine podia
// avaliar x-data="madTabsScroller()" ANTES do <script> inline do fragmento
// rodar → "ReferenceError: madTabsScroller is not defined" (setas de scroll
// das abas falhavam). Com a definição global carregada no boot, a função já
// existe quando qualquer drawer abre, em qualquer ordem de inicialização. O
// guard `||` preserva uma definição anterior idêntica (inline = fallback).
window.madTabsScroller = window.madTabsScroller || function () {
    return {
        canLeft: false,
        canRight: false,

        init() {
            const list = this.$refs.list;
            if (!list) return;
            this.update();

            // Recalcula fade/setas quando o strip ou o conteúdo muda de tamanho
            if (typeof ResizeObserver === 'function') {
                this._ro = new ResizeObserver(() => this.update());
                this._ro.observe(list);
            }

            // Aba ativa (estado do pai) entra na vista ao trocar
            try {
                this.$watch('activeTab', () => this.$nextTick(() => this.scrollActiveIntoView()));
            } catch (e) { /* sem activeTab no escopo — ignora */ }

            this.$nextTick(() => {
                this.update();
                this.scrollActiveIntoView();
                if (typeof window._madLucide === 'function') window._madLucide();
            });
        },

        update() {
            const el = this.$refs.list;
            if (!el) return;
            const max = el.scrollWidth - el.clientWidth;
            this.canLeft  = el.scrollLeft > 1;
            this.canRight = el.scrollLeft < max - 1;
            el.style.setProperty('--mad-fade-l', this.canLeft  ? '32px' : '0px');
            el.style.setProperty('--mad-fade-r', this.canRight ? '32px' : '0px');
        },

        page(dir) {
            const el = this.$refs.list;
            if (!el) return;
            el.scrollBy({ left: dir * Math.max(180, el.clientWidth * 0.7), behavior: 'smooth' });
        },

        onWheel(e) {
            const el = this.$refs.list;
            if (!el || el.scrollWidth <= el.clientWidth) return;
            // Só sequestra o wheel quando o gesto é predominantemente vertical
            if (Math.abs(e.deltaY) > Math.abs(e.deltaX)) {
                el.scrollLeft += e.deltaY;
                e.preventDefault();
            }
        },

        scrollActiveIntoView() {
            const el = this.$refs.list;
            if (!el) return;
            const active = el.querySelector('.mad-tab-active, .mad-tab[aria-selected="true"]');
            if (!active) return;
            const pad = 32;
            const er = el.getBoundingClientRect();
            const ar = active.getBoundingClientRect();
            if (ar.left < er.left + pad) {
                el.scrollBy({ left: ar.left - er.left - pad, behavior: 'smooth' });
            } else if (ar.right > er.right - pad) {
                el.scrollBy({ left: ar.right - er.right + pad, behavior: 'smooth' });
            }
        },
    };
};

// CSS-escape de ids em seletores [data-row-id]/[data-card-id]. Os ids do grid
// carregam o FQCN da classe como prefixo (ex. "App\Control\Contab\Foo_1"), e a
// barra invertida é caractere de escape no CSS — sem escapar, o querySelector
// NÃO acha a linha: remove_row deixava linha fantasma e manage_row inseria
// duplicata em vez de substituir (issue #6).
// Elemento de UM grid específico (op.gridKey = classe do grid, vinda de
// manageRow/removeRow). Sem chave, ou grid ausente na página, cai no primeiro
// que casar — o comportamento histórico, que só acertava em tela com um grid.
window._madGridEl = function (gridKey, selector) {
    if (gridKey) {
        const wrap = document.querySelector('.mad-dg-wrap[data-grid-key="' + _madCssId(gridKey) + '"]');
        if (wrap) {
            const el = wrap.querySelector(selector);
            if (el) return el;
        }
    }
    return document.querySelector(selector);
};

window._madCssId = function (id) {
    id = String(id);
    return (window.CSS && CSS.escape) ? CSS.escape(id) : id.replace(/["\\\]:.#>+~*^$=\s'/()\[]/g, '\\$&');
};

// manage_row: a linha salva/inserida pode estar fora da tela (grid longo, o
// usuário rolou até o fim antes de abrir a gaveta). Só a vista ativa rola —
// tabela e cards coexistem no DOM e a oculta tem offsetParent null.
function _madRevealRow(el) {
    if (el && el.offsetParent !== null && typeof el.scrollIntoView === 'function') {
        el.scrollIntoView({ block: 'nearest' });
    }
}

// <mad-grid row-detail>: a 2a <tr> descritiva de um registro. Ela e irma da
// linha, entao remove_row/manage_row precisam apaga-la explicitamente — senao
// a descricao antiga sobrevive a exclusao ou duplica na edicao.
window._madRemoveRowDetail = function (rowId) {
    document
        .querySelectorAll('tr[data-detail-for="' + _madCssId(rowId) + '"]')
        .forEach(function (el) { el.remove(); });
};

// Rodapé da grid ("1–15 de 40") depois que o remove_row tirou UMA linha da
// tela. Sem isto a Fila de Aprovação dizia "1–1 de 1" com a lista já vazia.
// Pura (tests/js/grid-remove-row-footer.test.mjs): recebe o texto atual do
// rodapé, quantas linhas sobraram NA PÁGINA e o tamanho da página; devolve:
//   { text }   ainda há linhas nesta página: fim do intervalo e total, um a menos
//   { empty }  era o último registro: o rodapé vira o "nenhum registro"
//   { page }   a página esvaziou mas há registros em outra: recarregar a página N
//   null       rodapé sem os três números (lista adiada, filtro obrigatório…)
window._madRangeAfterRemove = function (text, remaining, perPage) {
    const s = String(text || '');
    const nums = [];
    const re = /\d+(?:[.,]\d{3})*/g;
    let m;
    while ((m = re.exec(s)) !== null) {
        nums.push({ at: m.index, raw: m[0], n: parseInt(m[0].replace(/[.,]/g, ''), 10) });
    }
    if (nums.length !== 3) return null;
    const from = nums[0].n, to = nums[1].n, total = nums[2].n;
    const newTotal = Math.max(0, total - 1);
    if (newTotal === 0) return { empty: true };
    if (remaining <= 0) {
        const per = perPage > 0 ? perPage : Math.max(1, to - from + 1);
        const page = Math.floor((from - 1) / per) + 1;
        // Esvaziou a ÚLTIMA página → a anterior; senão a mesma (os registros
        // da página seguinte sobem para ela).
        return { page: (from - 1) >= newTotal ? Math.max(1, page - 1) : page };
    }
    // Troca só os NÚMEROS, no lugar — o resto do texto é a tradução do app.
    let out = '';
    let last = 0;
    [[nums[1], Math.max(from, to - 1)], [nums[2], newTotal]].forEach(function (pair) {
        out += s.slice(last, pair[0].at) + String(pair[1]);
        last = pair[0].at + pair[0].raw.length;
    });
    return { text: out + s.slice(last) };
};

// Par do _madRangeAfterRemove para o manage_row que INSERE uma linha (#75):
// "Após salvar" punha a linha nova na grid e o rodapé seguia "1–1 de 1" com 2
// linhas na tela. Pura (tests/js/grid-manage-row-footer.test.mjs): texto atual
// do rodapé, linhas NA PÁGINA depois da inserção e o modelo traduzido do
// rodapé (`data-range-tpl`, com __F__/__T__/__N__) para a lista que estava vazia.
window._madRangeAfterInsert = function (text, rowsOnPage, tpl) {
    const s = String(text || '');
    const nums = [];
    const re = /\d+(?:[.,]\d{3})*/g;
    let m;
    while ((m = re.exec(s)) !== null) {
        nums.push({ at: m.index, raw: m[0], n: parseInt(m[0].replace(/[.,]/g, ''), 10) });
    }
    if (nums.length === 3) {
        const from = nums[0].n, to = nums[1].n, total = nums[2].n;
        const newTotal = total + 1;
        const newTo = Math.min(newTotal, Math.max(to + 1, from + rowsOnPage - 1));
        let out = '';
        let last = 0;
        [[nums[1], newTo], [nums[2], newTotal]].forEach(function (pair) {
            out += s.slice(last, pair[0].at) + String(pair[1]);
            last = pair[0].at + pair[0].raw.length;
        });
        return { text: out + s.slice(last) };
    }
    if (tpl && rowsOnPage > 0) {
        return { text: String(tpl).replace('__F__', '1').replace('__T__', String(rowsOnPage)).replace('__N__', String(rowsOnPage)) };
    }
    return null;
};

// Aplica o _madRangeAfterInsert no rodapé de UMA grid (`.mad-dg-wrap`).
window._madGridFooterAfterInsert = function (wrap) {
    if (!wrap) return;
    const info = wrap.querySelector('.mad-dg-info');
    if (!info) return;
    const rows = wrap.querySelectorAll('.mad-dg-body tr[data-row-id]').length;
    const act = _madRangeAfterInsert(info.textContent.trim(), rows, info.getAttribute('data-range-tpl') || '');
    if (act && act.text !== undefined) info.textContent = act.text;
};

// Aplica o _madRangeAfterRemove no rodapé de UMA grid (`.mad-dg-wrap`).
window._madGridFooterAfterRemove = function (wrap) {
    if (!wrap) return;
    const info = wrap.querySelector('.mad-dg-info');
    if (!info) return;
    const remaining = wrap.querySelectorAll('.mad-dg-body tr[data-row-id]').length;
    const perSel = wrap.querySelector('.mad-dg-per-page select');
    const act = _madRangeAfterRemove(info.textContent, remaining, perSel ? (parseInt(perSel.value, 10) || 0) : 0);
    if (!act) return;
    if (act.text !== undefined) {
        info.textContent = act.text;
        return;
    }
    if (act.empty) {
        // O "nenhum registro" já vem traduzido na linha vazia da própria grid.
        const msg = wrap.querySelector('.mad-dg-empty-row .mad-dg-empty-inner > span');
        info.textContent = msg ? msg.textContent.trim() : '';
        const nav = wrap.querySelector('.mad-pagination');
        if (nav) nav.style.display = 'none';
        return;
    }
    if (act.page) {
        wrap.dispatchEvent(new CustomEvent('mad-dg-page', { detail: { page: act.page } }));
    }
};

// A detail carrega `:colspan="visibleColCount()"` (Alpine). Sem o initTree ela
// entraria com colspan 1 e a linha descritiva sairia espremida na 1a coluna.
window._madInitRowDetail = function (rowId) {
    if (!window.Alpine) return;
    document
        .querySelectorAll('tr[data-detail-for="' + _madCssId(rowId) + '"]')
        .forEach(function (el) { Alpine.initTree(el); });
};

// Localiza o <select> de um campo pelo `name`. Dois detalhes que um
// querySelector ingênuo erra:
//  - seleção múltipla renderiza name="X[]" (select-check, dbmulti-search…);
//  - com dois componentes abertos (drawer sobre a tela), o mesmo name pode
//    existir duas vezes — `scope` é o mad-id de quem deve receber a operação.
window._madFindSelect = function (name, scope) {
    const n = _madCssId(name);
    const sel = `select[name="${n}"], select[name="${n}[]"]`;
    if (scope) {
        const host = document.querySelector(`[mad-id="${_madCssId(scope)}"]`);
        const hit  = host && host.querySelector(sel);
        if (hit) return hit;
    }
    return document.querySelector(sel);
};

// Campo `<mad-seek>` (.mad-dbseek-field) dono do hidden `name` — alvo do
// combo_add_option quando o "Novo" do seek abriu o cadastro. Mesmo critério de
// escopo do _madFindSelect.
window._madFindSeek = function (name, scope) {
    const sel = `.mad-dbseek-field input[type="hidden"][name="${_madCssId(name)}"]`;
    let hit = null;
    if (scope) {
        const host = document.querySelector(`[mad-id="${_madCssId(scope)}"]`);
        hit = host && host.querySelector(sel);
    }
    hit = hit || document.querySelector(sel);
    return hit ? hit.closest('.mad-dbseek-field') : null;
};

// ─── Namespace principal ─────────────────────────────────────────────────────

const Mad = {

    // ── Configuração ──────────────────────────────────────────────────────────

    contentTarget : '#mad_main',
    onlineTarget  : '#mad_partial',
    debug         : false,
    language      : 'pt',
    waitMessage   : 'Aguarde...',

    // ── Abas internas (<mad-tab-bar>) ─────────────────────────────────────────
    // Com use_tabs ligado, TODA tela vai pro MadTabs: link de menu/boot abre
    // (ou reativa) uma aba; navegacao de dentro da tela (Novo/Editar/voltar)
    // troca o conteudo da aba ativa. Desligado, nada muda: #mad_main direto.

    _tabsOn() {
        return !!(window.MadTabs && window.MadTabs.enabled);
    },

    /** Fragmento e um overlay (drawer/modal)? Esses nunca viram aba. */
    _isOverlayHtml(html) {
        if (!html || html.indexOf('data-mad-wrapper') === -1) return false;
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const w = tmp.querySelector('[data-mad-wrapper]');
        return !!(w && (w.dataset.madWrapper === 'drawer' || w.dataset.madWrapper === 'modal'));
    },

    /** Metadado da tela no fragmento (MadAppController): classe e titulo declarado. */
    _tabMeta(res) {
        const h = res && res.headers;
        let cls = '', title = '';
        if (h) {
            cls = h.get('X-Mad-Class') || '';
            title = h.get('X-Mad-Title') || '';
            if (title) { try { title = decodeURIComponent(title); } catch (e) { title = ''; } }
        }
        return { cls, label: title || null };
    },

    /**
     * Entrega um fragmento de TELA: aba nova (opts.tab — menu/boot) ou
     * conteudo principal (#mad_main, ou a aba ativa quando as abas estao
     * ligadas). Overlays seguem o fluxo de sempre do chamador.
     */
    _deliver(html, url, res, opts) {
        opts = opts || {};
        if (this._tabsOn() && !this._isOverlayHtml(html)) {
            const meta = this._tabMeta(res);
            const payload = { url: url || null, cls: opts.cls || meta.cls, label: meta.label, html };
            if (opts.tab) window.MadTabs.open(payload);
            else          window.MadTabs.navigate(payload);
            return;
        }
        this._injectFull(html, url, { history: !!opts.history });
    },

    /** Injeta e reinicializa um fragmento num container (aba ou #mad_main). */
    _injectInto(el, html, url) {
        if (!el) return;
        // Tela NOVA: aba pendente de um redesenho anterior não vale para ela.
        this._uiPendingTabs = [];
        el.innerHTML = html;
        this._reinit(el);
        try {
            window.dispatchEvent(new CustomEvent('mad:navigated', { detail: { el, url: url || null } }));
        } catch (e) {}
    },

    // ── Navegação completa ────────────────────────────────────────────────────

    /**
     * Carrega uma página completa via AJAX.
     * Equivale a __mad_load_page().
     */
    /**
     * Navegação SPA para uma ROTA AMIGÁVEL (/app/slug). Busca o fragmento
     * com X-Mad-Partial (senão o resolver devolve o casco inteiro),
     * injeta no #mad_main e atualiza a URL (pushState). 100% MAD —
     * roteia os links de menu sem runtime legado.
     */
    /**
     * Container do Teste Online em repouso: o nginx de fallback devolve a
     * pagina "em repouso" INTEIRA (200 + header X-Mad-Offline:1) para
     * qualquer fetch — inclusive os parciais (X-Mad-Partial). Injetar esse
     * casco no #mad_main encaixaria a tela de repouso dentro do app. Detecta
     * o header e recarrega a pagina toda. Retorna true se offline (o chamador
     * deve abortar a injecao).
     */
    _offline(res) {
        if (res && res.headers && res.headers.get('X-Mad-Offline') === '1') {
            window.location.reload();
            return true;
        }
        return false;
    },

    /**
     * Resposta de erro do servidor (500 com a página de erro do Laravel, fatal
     * do PHP, 4xx com HTML). Antes esse corpo era injetado no #mad_main e
     * destruía o layout — ou sumia. Agora abre o MadErrorModal (90vw × 90vh)
     * com a página renderizada, o texto e o markdown pro agente de IA.
     *
     * Retorna true se o erro foi tratado (o chamador deve abortar a injeção).
     */
    _httpError(res, text, ctx) {
        if (!res) return false;
        const body = String(text || '');
        // 200 com fatal do PHP (display_errors=on) também é erro pra nós.
        const fatal200 = res.ok
            && /^\s*(<br\s*\/?>)?\s*(Fatal error|Parse error)\s*:/i.test(body.slice(0, 400));
        const excPage = !!(window.MadErrorModal && window.MadErrorModal.isErrorPage(body));
        // 4xx "normal" (403 do guard, 404) continua no fluxo antigo — só 5xx e
        // página de exception viram modal de erro.
        if (!fatal200 && res.status < 500 && !(res.status >= 400 && excPage)) return false;
        if (!window.MadErrorModal) {
            console.error('[Mad] HTTP', res.status, res.url, text);
            MadDialog.show({ type: 'error', title: 'Erro ' + res.status, message: 'A requisição falhou no servidor.' });
            return true;
        }
        window.MadErrorModal.showResponse(res, text, Object.assign({ source: 'Mad', method: 'GET' }, ctx || {}));
        return true;
    },

    async navigate(url, callback, opts) {
        MadLoader.show();
        try {
            const res  = await fetch(url, { headers: { 'X-Mad-Partial': '1' } });
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'GET', source: 'Mad.navigate' })) return;
            // opts.tab: veio de link de menu → aba nova (quando as abas estao ligadas).
            this._deliver(html, url, res, opts);
            if (typeof callback === 'function') callback(html);
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    /**
     * opts.history: a navegação veio do voltar/avançar do NAVEGADOR (popstate).
     * O pedido leva X-Mad-History — a listagem reabre na página, ordem, busca
     * e filtros em que o usuário a deixou (MadDataGrid::HISTORY_HEADER) — e a
     * URL não é empilhada de novo: o navegador já está nessa entrada.
     */
    async load(url, callback, opts) {
        opts = opts || {};
        // Rota amigável (/app/...) vai direto com X-Mad-Partial (senão o
        // resolver devolve o casco). URL em formato de query (?class=) é
        // reescrita pra /app/* pelo shim de fetch (mad-web-routing.js).
        const friendly = this._isFriendlyUrl(url);
        const headers = {};
        if (friendly)     headers['X-Mad-Partial'] = '1';
        if (opts.history) headers['X-Mad-History'] = '1';

        MadLoader.show();
        try {
            const res  = await fetch(url, Object.keys(headers).length ? { headers } : undefined);
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'GET', source: 'Mad.load' })) return;

            // Mad.load = clique de menu (runtime legado / ?class=): com as abas
            // ligadas vira aba nova. A classe vem do header X-Mad-Class; no
            // formato ?class= ainda da pra extrair da propria URL.
            const parsed = url.indexOf('class=') >= 0 ? this._parseClassMethodParams(url) : null;
            this._deliver(html, url, res, { tab: true, cls: parsed && parsed.cls ? parsed.cls : '', history: !!opts.history });
            if (typeof callback === 'function') callback(html);
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    /**
     * Boot do conteúdo no shell em modo web (rota amigável).
     *
     * Diferente de Mad.load()/__mad_load_page: NÃO transforma a URL em
     * index.php?class=... e NÃO faz pushState. Busca a URL ATUAL (amigável,
     * ex.: /app/usuarios) com o header X-Mad-Partial e injeta o fragmento no
     * #mad_main — a URL amigável permanece intacta na barra.
     *
     * Usado pelo shell renderizado por AppRouteResolver::renderShell (web mode).
     */
    async bootShell(url) {
        url = url || (window.location.pathname + window.location.search);
        MadLoader.show();
        try {
            const res  = await fetch(url, { headers: { 'X-Mad-Partial': '1' } });
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'GET', source: 'Mad.bootShell' })) return;
            if (this._tabsOn() && !this._isOverlayHtml(html)) {
                // Tela aberta por URL/F5 vira aba (a URL ja e a atual: sem pushState).
                const meta = this._tabMeta(res);
                window.MadTabs.open({ url, cls: meta.cls, label: meta.label, html });
                return;
            }
            // url=null → _injectFull NÃO faz pushState → mantém a URL amigável.
            this._injectFull(html, null);
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    /**
     * Extrai { cls, method, params } de uma URL em formato de query ?class=X&method=Y&...
     * Retorna null se a URL nao parece uma navegacao MAD.
     */
    _parseClassMethodParams(url) {
        try {
            const qIdx = url.indexOf('?');
            if (qIdx < 0) return null;
            const qs = new URLSearchParams(url.substring(qIdx + 1));
            const cls = qs.get('class');
            if (!cls) return null;
            const method = qs.get('method') || 'show';
            const params = {};
            qs.forEach((v, k) => {
                if (k === 'class' || k === 'method' || k === 'static') return;
                params[k] = v;
            });
            return { cls, method, params };
        } catch (e) {
            return null;
        }
    },

    // ── Roteamento web (driver=web) ───────────────────────────────────────────

    /** Base das rotas amigáveis (/app), sem barra final. Null fora do modo web. */
    _appBase() {
        return (window.MadShell && window.MadShell.appBase)
            ? String(window.MadShell.appBase).replace(/\/+$/, '')
            : null;
    },

    /** A URL já é uma rota amigável /app/... (não é URL do dispatcher class=)? */
    _isFriendlyUrl(url) {
        const b = this._appBase();
        return !!b && typeof url === 'string'
            && url.indexOf(b + '/') === 0
            && url.indexOf('class=') === -1;
    },

    /**
     * Monta a rota amigável /app/{cls}/{method}. `extra` são pares de query
     * (ex.: { static: 1 }).
     */
    _classMethodUrl(cls, method, extra = {}) {
        const b = this._appBase() || '/app';
        const qs = new URLSearchParams(extra).toString();
        return b + '/' + encodeURIComponent(cls) + '/' + encodeURIComponent(method)
             + (qs ? '?' + qs : '');
    },

    /**
     * Resolve um TEMPLATE de URL amigável assado no servidor: troca cada
     * `__MAD_<key>__` pelo valor de params[key].
     *
     * O mapa de rotas não vive no client, então o PHP assa a URL mesmo quando
     * parte dela só é conhecida no clique (o id do card, da linha…). O
     * placeholder cobre inclusive id no PATH (`/app/clientes/__MAD_id__/editar`),
     * que uma query string não resolveria.
     *
     * Sem template (null/undefined) devolve null — quem chama cai no fallback
     * genérico, preservando o comportamento de payload antigo em cache.
     */
    /**
     * Anexa params na query de uma URL já montada, preservando o que ela já tem
     * (a rota amigável pode vir com ?static=1, ?_forward_param_*, …).
     * Chave repetida: a última vence no parse do PHP — é assim que um param
     * dinâmico sobrescreve o valor assado no servidor.
     */
    _urlWithParams(url, params = {}) {
        const u  = String(url == null ? '' : url);
        // Sem URL base não há o que anexar — devolve vazio (falsy) para quem
        // chama cair no fallback genérico, em vez de forjar um '?x=1' órfão.
        if (!u) return u;
        const qs = new URLSearchParams(params || {}).toString();
        if (!qs) return u;
        return u + (u.indexOf('?') >= 0 ? '&' : '?') + qs;
    },

    _resolveUrlTemplate(tpl, params = {}) {
        if (!tpl) return null;
        let url = String(tpl);
        for (const k in params) {
            if (Object.prototype.hasOwnProperty.call(params, k)) {
                url = url.split('__MAD_' + k + '__').join(encodeURIComponent(params[k]));
            }
        }
        return url;
    },

    /**
     * Envia um formulário via AJAX.
     * Equivale ao post-data legado.
     */
    async post(formId, action) {
        const form = document.getElementById(formId);
        if (!form) return;

        // Validação nativa do browser
        if (!form.hasAttribute('novalidate') && !form.checkValidity()) {
            form.reportValidity();
            return;
        }

        let url = action;
        let friendly = this._isFriendlyUrl(url);
        if (!friendly && url.substring(0, 5) === 'class') {
            // Action em formato de query crua ('class=X&method=Y&...') —
            // monta a rota amigável direto.
            const parsed = this._parseClassMethodParams('?' + url);
            if (parsed) {
                url = this._classMethodUrl(parsed.cls, parsed.method, parsed.params);
                friendly = true;
            }
        }

        MadLoader.show();
        try {
            const data = new FormData(form);
            const res  = await fetch(url, {
                method: 'POST',
                body: data,
                headers: friendly ? { 'X-Mad-Partial': '1' } : undefined,
            });
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'POST', source: 'Mad.postForm' })) return;

            if (friendly || url.indexOf('static=1') >= 0) {
                this._parsePartial(html, res, { url: url, method: 'POST', source: 'Mad.postForm' });
            } else {
                this._injectFull(html, url);
            }
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    // ── Chamadas de métodos estáticos (novo sistema) ──────────────────────────

    /**
     * Chama um método estático PHP e aplica o resultado.
     *
     * @param {string} classMethod  'MinhaClasse@meuMetodo'
     * @param {object} data         Dados extras a enviar (além do form)
     * @param {string} target       Seletor CSS do elemento a atualizar (opcional)
     * @param {string} friendlyUrl  URL amigável assada no PHP (opcional)
     *
     * Se o PHP retornar um JSON array de ops (MadResponse), aplica as ops.
     * Se retornar HTML puro, injeta no target ou no container principal.
     */
    async exec(classMethod, data = {}, target = null, friendlyUrl = null) {
        const [cls, method] = classMethod.split('@');
        // Com friendlyUrl, o PHP já resolveu a rota (e decidiu o static=1 olhando
        // se o método é REALMENTE estático). Sem ela, a forma genérica
        // /app/cls/method?static=1 — que só existe p/ classe com exposeClass().
        const url = friendlyUrl || this._classMethodUrl(cls, method, { static: 1 });

        MadLoader.show();
        try {
            const body = new URLSearchParams(data);
            const res  = await fetch(url, { method: 'POST', body });
            if (this._offline(res)) return;
            const text = await res.text();
            if (this._httpError(res, text, { url: url, method: 'POST', source: 'Mad.exec' })) return;

            // Tenta interpretar como MadResponse (JSON array de ops ou objeto com _debug)
            if (text.trimStart().startsWith('[') || text.trimStart().startsWith('{')) {
                try {
                    const parsed = JSON.parse(text);

                    // Formato com debug: { ops: [...], _debug: [...] }
                    if (parsed && parsed.ops && Array.isArray(parsed.ops)) {
                        if (parsed._debug && typeof System !== 'undefined' && System.addDebug) {
                            parsed._debug.forEach(d => System.addDebug(d));
                        }
                        await this.applyOps(parsed.ops);
                        return;
                    }
                    // Formato simples: [...]
                    if (Array.isArray(parsed)) {
                        await this.applyOps(parsed);
                        return;
                    }
                } catch (_) {}
            }

            // Fallback: HTML puro
            if (target) {
                const el = (target instanceof HTMLElement) ? target : document.querySelector(target);
                if (el) {
                    el.innerHTML = text;
                    this._reinit(el);
                }
            } else {
                this._parsePartial(text, res, { url: url, method: 'POST', source: 'Mad.exec' });
            }
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    /**
     * GET estático (sem body). Usa para carregar pedaços sem dados de form.
     */
    async get(classMethod, params = {}, target = null, friendlyUrl = null) {
        const [cls, method] = classMethod.split('@');
        // PHP entrega a URL amigavel pronta (params ja embutidos) — fetch
        // direto com X-Mad-Partial. Sem friendlyUrl, monta a forma generica /app/cls/method.
        const url = friendlyUrl || this._classMethodUrl(cls, method, params);
        const fetchInit = { headers: { 'X-Mad-Partial': '1' } };
        // Alvo dentro de um <mad-transporter>: a tela vem sem o casco de página
        // (cabeçalho/breadcrumb repetidos) — MadTransporter::embedHeader().
        const embed = target ? this._embedMode(target) : '';
        if (embed) fetchInit.headers['X-Mad-Embed'] = embed;

        MadLoader.show();
        try {
            const res  = await fetch(url, fetchInit);
            if (this._offline(res)) return;
            const text = await res.text();
            if (this._httpError(res, text, { url: url, method: 'GET', source: 'Mad.get' })) return;

            if (text.trimStart().startsWith('[') || text.trimStart().startsWith('{')) {
                try {
                    const parsed = JSON.parse(text);
                    if (parsed && parsed.ops && Array.isArray(parsed.ops)) {
                        if (parsed._debug && typeof System !== 'undefined' && System.addDebug) {
                            parsed._debug.forEach(d => System.addDebug(d));
                        }
                        await this.applyOps(parsed.ops);
                        return;
                    }
                    if (Array.isArray(parsed)) { await this.applyOps(parsed); return; }
                } catch (_) {}
            }

            if (target) {
                const el = (target instanceof HTMLElement) ? target : document.querySelector(target);
                if (el) {
                    // Recarga no lugar (energize do transporter): aba e rolagem ficam.
                    const ui = this.captureUiState(el);
                    el.innerHTML = text;
                    this._reinit(el);
                    this.restoreUiState(el, ui);
                }
            } else {
                this._parsePartial(text, res, { url: url, method: 'GET', source: 'Mad.get' });
            }
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    // ── Overlay — carrega componente drawer/modal sem afetar a página ───────

    /**
     * Carrega um MadComponent (drawer/modal) como overlay sobre a página atual.
     * Diferente de Mad.get(), NÃO altera o conteúdo principal nem o history.
     *
     * Uso: Mad.overlay('DocComposicaoForm@show', { item_id: 123 })
     *
     * @param {string} classMethod  'Classe@metodo'
     * @param {object} params       Parâmetros da query string
     */
    async overlay(classMethod, params = {}, friendlyUrl = null) {
        const [cls, method] = classMethod.split('@');
        const url = friendlyUrl || this._classMethodUrl(cls, method || 'show', params);
        const fetchInit = { headers: { 'X-Mad-Partial': '1' } };

        MadLoader.show();
        try {
            const res  = await fetch(url, fetchInit);
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'GET', source: 'Mad.overlay' })) return;
            if (this._errorFragment(html, res, { url: url, method: 'GET', source: 'Mad.overlay' })) return;

            // Cria container temporário no body
            const container = document.createElement('div');
            container.innerHTML = html;
            document.body.appendChild(container);

            // Executa scripts (o wrapper drawer/modal tem <script> que move para o body e abre)
            container.querySelectorAll('script').forEach(old => {
                const s = document.createElement('script');
                [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                s.textContent = old.textContent;
                old.replaceWith(s);
            });

            // Inicializa componentes (sequencia unica — ver _madEnergizeFields em mad-ui.js)
            if (window.Alpine) try { Alpine.initTree(container); } catch {}
            if (typeof _madLucide === 'function') _madLucide();
            if (typeof _madEnergizeFields === 'function') _madEnergizeFields(container);

        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    // ── Row-attach — form de edição rápida anexado à linha da grid ──────────

    /**
     * Abre um MadComponent (form) ANEXADO à linha da grid: insere uma <tr>
     * extra logo abaixo da linha clicada, com o componente cru dentro
     * (header X-Mad-Row-Attach faz o backend pular o chrome drawer/modal).
     *
     * Comportamentos: toggle (clicar de novo fecha), single-open por tbody,
     * fallback para Mad.get (drawer padrão) quando não há <tr> ancestral
     * (ex.: card-view). O closeDrawer() do onSave fecha a attach-row via
     * interceptação do close_overlay no mad-livewire.js.
     *
     * @param {HTMLElement} el     Botão clicado (âncora para achar a <tr>)
     * @param {string} classMethod 'Classe@metodo'
     * @param {object} params      Parâmetros da query string
     * @param {string} friendlyUrl URL amigável pronta (assada no PHP)
     */
    async rowAttach(el, classMethod, params = {}, friendlyUrl = null) {
        const tr = el && el.closest ? el.closest('tr.mad-dg-row') : null;
        if (!tr) return this.get(classMethod, params, null, friendlyUrl);

        const rowId = tr.dataset.rowId || '';
        const tbody = tr.closest('tbody') || tr.parentElement;

        // Toggle: já aberta para esta linha → fecha e sai
        const existing = tbody.querySelector(`tr.mad-dg-attach-row[data-attach-for="${_madCssId(rowId)}"]`);
        if (existing) { this.rowDetach(existing); return; }

        // Single-open por tbody
        tbody.querySelectorAll('tr.mad-dg-attach-row').forEach(t => t.remove());

        const [cls, method] = classMethod.split('@');
        const url = friendlyUrl || this._classMethodUrl(cls, method || 'show', params);
        const fetchInit = { headers: { 'X-Mad-Partial': '1', 'X-Mad-Row-Attach': '1' } };

        MadLoader.show();
        try {
            const res  = await fetch(url, fetchInit);
            if (this._offline(res)) return;
            const text = await res.text();
            if (this._httpError(res, text, { url: url, method: 'GET', source: 'Mad.rowAttach' })) return;

            // Envelope JSON de ops (erro/permissão/MadMessage) → applyOps, igual Mad.get
            if (text.trimStart().startsWith('[') || text.trimStart().startsWith('{')) {
                try {
                    const parsed = JSON.parse(text);
                    if (parsed && parsed.ops && Array.isArray(parsed.ops)) { await this.applyOps(parsed.ops); return; }
                    if (Array.isArray(parsed)) { await this.applyOps(parsed); return; }
                } catch (_) {}
            }

            const attach = document.createElement('tr');
            attach.className = 'mad-dg-attach-row';
            attach.setAttribute('data-attach-for', rowId);
            const td = document.createElement('td');
            td.colSpan = tr.children.length;
            td.innerHTML = '<div class="mad-dg-attach-wrap">'
                + '<button type="button" class="mad-dg-attach-close" onclick="Mad.rowDetach(this)" aria-label="Fechar">'
                + '<i data-lucide="x"></i></button>'
                + '<div class="mad-dg-attach-body">' + text + '</div></div>';
            attach.appendChild(td);
            tr.after(attach);

            // Reescreve mad-* → x-* ANTES do initTree (o MutationObserver roda
            // em microtask; o initTree síncrono do _reinit passaria antes)
            if (typeof _madRewriteAlpineAttrs === 'function') _madRewriteAlpineAttrs(td);
            this._reinit(td);
            attach.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    /** Fecha a attach-row que contém (ou é) o elemento dado. */
    rowDetach(el) {
        const tr = (el instanceof HTMLElement && !el.matches('tr.mad-dg-attach-row'))
            ? el.closest('tr.mad-dg-attach-row')
            : el;
        if (tr) tr.remove();
    },

    // ── Mad.call — chama método do MadComponent atual via wire ──────────────

    /**
     * Chama um método público do MadComponent ativo na página, reaproveitando
     * todo o pipeline do MadWire (estado, mad:model, ops parciais, dehydrate).
     *
     * Diferente de Mad.exec (que faz POST estático cru), Mad.call envia o
     * mad_state corrente, então o backend tem acesso a todas as props públicas
     * do componente — exatamente como um botão `mad:click` faria.
     *
     * @param {string} method  Nome do método PHP (ex: 'onSearch', 'onFiltrar')
     * @param {object|array} params  Parâmetros — objeto resolve por nome de
     *                               argumento, array resolve posicional.
     * @param {HTMLElement} [context]  Elemento de origem opcional. Se omitido,
     *                                 usa o primeiro [mad-component] na página.
     *
     * Uso:
     *   Mad.call('onSearch', { q: 'drawer' })   // resolve $q por nome
     *   Mad.call('onAprovar', [42])              // resolve $id por posição
     *   Mad.call('onRecarregar')                 // sem args
     */
    async call(method, params = {}, context = null) {
        if (typeof MadWire === 'undefined' || !MadWire.call) {
            console.error('[Mad.call] MadWire não está disponível');
            return;
        }
        let wrapper = null;
        if (context instanceof HTMLElement) {
            // resolveWrapper atravessa teleport (drawer/modal) — closest() puro
            // devolvia null pra elemento dentro de sub-form portaleado.
            wrapper = MadWire.resolveWrapper
                ? MadWire.resolveWrapper(context)
                : context.closest('[mad-component]');
        }
        if (!wrapper) {
            // Pega o componente principal — preferir INTERNAL na área de conteúdo
            wrapper = document.querySelector('#mad_main [mad-component]')
                   || document.querySelector('main [mad-component]')
                   || document.querySelector('[mad-component]');
        }
        if (!wrapper) {
            console.error('[Mad.call] nenhum [mad-component] encontrado na página');
            return;
        }
        return MadWire.call(wrapper, method, params || {});
    },

    // ── "+" ao lado do combo (`create` do <mad-dbcombo-field>) ─────────────
    // Abre o cadastro com a origem ASSINADA pelo PHP (MadNoResultsHelper::
    // createButton) — o mesmo contrato do "Sem resultados → Cadastrar novo"
    // (fireCreate) e do "Novo" do <mad-seek> (openCreate): o returnToCombo()
    // do form alvo devolve a option nova selecionada neste combo.
    comboCreate(btn) {
        let c = null;
        try { c = JSON.parse(btn.getAttribute('data-mad-combo-create') || 'null'); } catch (e) { c = null; }
        if (!c || !c.class) return;
        const params = { _field_name: c.field || '' };
        if (c.token) params._mad_origin = c.token;
        // Escopo do combo de origem: o drawer aberto por cima pode ter um
        // <select> homônimo — a option volta para o desta tela.
        const cmp = btn.closest ? btn.closest('[mad-component]') : null;
        if (cmp) params._mad_origin_cmp = cmp.getAttribute('mad-id') || '';
        const url = c.url ? this._urlWithParams(c.url, params) : undefined;
        return this.go(c.class, c.method || 'show', params, url);
    },

    // ── Navegação inteligente: detecta página vs drawer/modal ──────────────

    async go(cls, method, params, friendlyUrl) {
        method = method || 'show';
        params = params || {};
        // Clique DENTRO de uma gaveta/modal que abre uma tela inteira (botão
        // `navigate` de "Voltar para a lista"): a tela de trás era trocada e a
        // camada ficava aberta por cima, escondendo a troca. Lida aqui, antes
        // do primeiro await — window.event só vale durante o despacho. Cadastro
        // aberto a partir de um combo (_mad_origin/_field_name) devolve a option
        // ao combo de origem: ele precisa continuar vivo, então não fecha nada.
        const fromLayer = (params._mad_origin || params._field_name) ? null : this._triggerLayer();
        // O PHP (MadAction) ja entrega a URL amigavel pronta (/app/slug)
        // — usamos ela direto pro fetch (com X-Mad-Partial pra receber fragmento,
        // nao o casco). A identidade da aba (cls) continua vindo separada, so pro
        // MadTabs. Sem friendlyUrl, monta a forma generica /app/cls/method.
        const url = friendlyUrl || this._classMethodUrl(cls, method, { static: 1, ...params });
        const fetchInit = { headers: { 'X-Mad-Partial': '1' } };

        MadLoader.show();
        try {
            const res  = await fetch(url, fetchInit);
            if (this._offline(res)) return;
            const html = await res.text();
            if (this._httpError(res, html, { url: url, method: 'GET', source: 'Mad.go' })) return;

            // Detecta wrapper type no response
            const tmp = document.createElement('div');
            tmp.innerHTML = html;
            const wrapper = tmp.querySelector('[data-mad-wrapper]');

            if (wrapper && (wrapper.dataset.madWrapper === 'drawer' || wrapper.dataset.madWrapper === 'modal')) {
                // Overlay: injeta no body (mesmo fluxo do overlay())
                const container = document.createElement('div');
                container.innerHTML = html;
                document.body.appendChild(container);
                container.querySelectorAll('script').forEach(old => {
                    const s = document.createElement('script');
                    [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                    s.textContent = old.textContent;
                    old.replaceWith(s);
                });
                if (window.Alpine) try { Alpine.initTree(container); } catch {}
                if (typeof _madLucide === 'function') _madLucide();
                if (typeof _madEnergizeFields === 'function') _madEnergizeFields(container);
            } else {
                // Pagina INTERNA aberta de DENTRO de uma tela (botao Novo/Editar,
                // voltar): com as abas ligadas troca o conteudo da aba ATIVA
                // (mad_tab_name explicito = aba propria, compat legado); sem abas,
                // substitui o conteudo principal (comportamento original).
                const tabName = params && params.mad_tab_name && params.mad_tab_name !== '*' ? params.mad_tab_name : null;
                if (tabName && this._tabsOn()) {
                    window.MadTabs.open({ url: friendlyUrl || url, cls, method, params, label: tabName, html });
                } else {
                    this._deliver(html, friendlyUrl || url, res, { cls });
                }
                // Tela inteira entregue: a camada de onde partiu o clique sai
                // (mesmo caminho do Esc — o wrapper remove o DOM e o z-index).
                if (fromLayer && fromLayer.isConnected) MadOverlayEsc.close(fromLayer);
            }
        } catch (e) {
            this._onError(e);
        } finally {
            MadLoader.hide();
        }
    },

    // ── Atalhos de overlay (drawer/modal por nome) ────────────────────────────

    openDrawer(name)  { window.dispatchEvent(new CustomEvent('maddrawer', { detail: { name, action: 'open'  } })); },
    closeDrawer(name) { window.dispatchEvent(new CustomEvent('maddrawer', { detail: { name, action: 'close' } })); },
    openModal(name)   { window.dispatchEvent(new CustomEvent('madmodal',  { detail: { name, action: 'open'  } })); },
    closeModal(name)  { window.dispatchEvent(new CustomEvent('madmodal',  { detail: { name, action: 'close' } })); },

    /**
     * Fecha a camada (gaveta/modal) que CONTÉM o elemento — é o que o
     * `close-drawer` / `close-modal` SEM valor do <mad-btn> compila
     * (onclick="Mad.closeOverlayOf(this, 'drawer')"), o "Cancelar" de um
     * formulário aberto em gaveta. Sem nome não há o que casar no evento
     * (closeDrawer('') não acha a gaveta do Mad.go, cujo nome é o id da tela),
     * então a camada é achada subindo o DOM pelas marcas data-mad-overlay* e
     * fechada pelo MESMO caminho do Esc/closeDrawer(nome).
     *
     * `kind` ('drawer'|'modal') prefere a camada desse tipo; sem ela, qualquer
     * camada (formulário aberto em modal com o botão escrito close-drawer).
     * Componente anexado à linha da grid (row-attach) não tem camada: fechar =
     * remover a <tr>, igual ao close_overlay do servidor. Fora de camada: no-op.
     * Devolve true quando fechou algo.
     */
    closeOverlayOf(el, kind) {
        if (!el || !el.closest) return false;
        const ROW = 'tr.mad-dg-attach-row';
        const typed = (kind === 'drawer' || kind === 'modal')
            ? el.closest('[data-mad-overlay="' + kind + '"], ' + ROW)
            : null;
        const layer = typed || el.closest('[data-mad-overlay], ' + ROW);
        if (!layer) return false;
        if (layer.matches(ROW)) { this.rowDetach(layer); return true; }
        MadOverlayEsc.close(layer);
        return true;
    },

    /**
     * Camada (gaveta/modal) que contém o elemento cujo CLIQUE está sendo
     * despachado agora, ou null. Os chamadores do go() (onclick do MadAction,
     * ações da grid, Alpine) não passam o elemento; window.event, sim.
     */
    _triggerLayer() {
        const ev = window.event;
        if (!ev || ev.type !== 'click') return null;
        let t = ev.target;
        if (t && t.nodeType !== 1) t = t.parentElement;
        return t && t.closest ? t.closest('[data-mad-overlay]') : null;
    },

    // ── Dump Modal (debug overlay mad_dump_modal / mdm) ───────────────────────

    openDumpModal(dumps, meta) {
        if (!Array.isArray(dumps) || !dumps.length) return;

        const esc = (s) => String(s == null ? '' : s).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c]));

        let root = document.getElementById('mad-dump-modal-root');
        let panelDumps, panelRequest, panelSql;

        if (!root) {
            root = document.createElement('div');
            root.id = 'mad-dump-modal-root';
            root.style.cssText = 'position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;background:rgba(2,6,23,.72);backdrop-filter:blur(4px);font-family:ui-monospace,SFMono-Regular,"JetBrains Mono",Menlo,Consolas,monospace;font-size:13px;color:#e2e8f0;';
            root.tabIndex = -1;

            const card = document.createElement('div');
            card.style.cssText = 'width:min(960px,92vw);max-height:86vh;display:flex;flex-direction:column;background:#0b1220;border:1px solid #1e293b;border-radius:12px;box-shadow:0 30px 80px rgba(0,0,0,.6),0 0 0 1px rgba(99,102,241,.15);overflow:hidden;';

            const header = document.createElement('div');
            header.style.cssText = 'display:flex;align-items:center;gap:12px;padding:14px 18px;background:linear-gradient(180deg,#111827,#0b1220);border-bottom:1px solid #1e293b;';
            header.innerHTML = '<span style="font-size:18px;">🪲</span>'
                + '<div style="font-weight:600;color:#f1f5f9;letter-spacing:.3px;">MAD Debug</div>'
                + '<div style="flex:1;"></div>'
                + '<button type="button" data-act="copy" title="Copiar JSON" style="background:#1e293b;color:#cbd5e1;border:1px solid #334155;border-radius:6px;padding:5px 10px;cursor:pointer;font:inherit;font-size:11px;">📋 Copy</button>'
                + '<button type="button" data-act="clear" title="Limpar dumps" style="background:#1e293b;color:#cbd5e1;border:1px solid #334155;border-radius:6px;padding:5px 10px;cursor:pointer;font:inherit;font-size:11px;">🗑 Clear</button>'
                + '<button type="button" data-act="close" title="Fechar (Esc)" style="background:#7f1d1d;color:#fecaca;border:1px solid #991b1b;border-radius:6px;padding:5px 10px;cursor:pointer;font:inherit;font-size:11px;">✕ Close</button>';

            // Tabs bar
            const tabs = document.createElement('div');
            tabs.id = 'mad-dump-modal-tabs';
            tabs.style.cssText = 'display:flex;gap:2px;padding:0 18px;background:#0a0f1a;border-bottom:1px solid #1e293b;';

            const mkTab = (key, label, badgeColor) =>
                '<button type="button" data-tab="' + key + '" style="background:none;color:#94a3b8;border:none;border-bottom:2px solid transparent;padding:10px 14px;cursor:pointer;font:inherit;font-size:12px;display:flex;align-items:center;gap:6px;">'
                + '<span>' + label + '</span>'
                + '<span data-count="' + key + '" style="background:' + badgeColor + ';color:#0b1220;font-size:10px;font-weight:700;padding:1px 6px;border-radius:999px;min-width:18px;text-align:center;">0</span>'
                + '</button>';

            tabs.innerHTML = mkTab('dumps', '🪲 Dumps', '#6366f1')
                           + mkTab('request', '🌐 Request', '#10b981')
                           + mkTab('sql', '🗄 SQL', '#a78bfa');

            // Body with 3 panels (only one visible at a time)
            const body = document.createElement('div');
            body.style.cssText = 'flex:1;overflow:hidden;background:#0b1220;display:flex;';

            panelDumps = document.createElement('div');
            panelDumps.id = 'mad-dump-panel-dumps';
            panelDumps.style.cssText = 'flex:1;overflow:auto;padding:14px 18px;';

            panelRequest = document.createElement('div');
            panelRequest.id = 'mad-dump-panel-request';
            panelRequest.style.cssText = 'flex:1;overflow:auto;padding:14px 18px;display:none;';

            panelSql = document.createElement('div');
            panelSql.id = 'mad-dump-panel-sql';
            panelSql.style.cssText = 'flex:1;overflow:auto;padding:14px 18px;display:none;';

            body.appendChild(panelDumps);
            body.appendChild(panelRequest);
            body.appendChild(panelSql);

            const footer = document.createElement('div');
            footer.style.cssText = 'padding:8px 18px;background:#0a0f1a;border-top:1px solid #1e293b;color:#64748b;font-size:11px;display:flex;justify-content:space-between;align-items:center;';
            footer.innerHTML = '<span>Dev tool — mad_dump_modal() / mdm()</span><span style="opacity:.7;">Esc to close</span>';

            card.appendChild(header);
            card.appendChild(tabs);
            card.appendChild(body);
            card.appendChild(footer);
            root.appendChild(card);
            document.body.appendChild(root);

            const close = () => { root.remove(); document.removeEventListener('keydown', onKey); };
            const onKey = (e) => { if (e.key === 'Escape') close(); };
            document.addEventListener('keydown', onKey);

            // Tab switching
            const selectTab = (key) => {
                root._activeTab = key;
                tabs.querySelectorAll('[data-tab]').forEach(b => {
                    const active = b.dataset.tab === key;
                    b.style.color = active ? '#f1f5f9' : '#94a3b8';
                    b.style.borderBottomColor = active ? '#6366f1' : 'transparent';
                });
                panelDumps.style.display   = key === 'dumps'   ? 'block' : 'none';
                panelRequest.style.display = key === 'request' ? 'block' : 'none';
                panelSql.style.display     = key === 'sql'     ? 'block' : 'none';
            };
            root._selectTab = selectTab;
            selectTab('dumps');

            root.addEventListener('click', (e) => {
                if (e.target === root) close();
                const act = e.target.closest('[data-act]')?.dataset.act;
                if (act === 'close') close();
                if (act === 'clear') {
                    panelDumps.innerHTML = '';
                    root._dumpCache = [];
                    const badge = tabs.querySelector('[data-count="dumps"]');
                    if (badge) badge.textContent = '0';
                }
                if (act === 'copy') {
                    navigator.clipboard?.writeText(JSON.stringify({ dumps: root._dumpCache || [], meta: root._meta || null }, null, 2));
                    e.target.textContent = '✓ Copied';
                    setTimeout(() => { e.target.textContent = '📋 Copy'; }, 1200);
                }
                const tab = e.target.closest('[data-tab]')?.dataset.tab;
                if (tab) selectTab(tab);
            });

            root._dumpCache = [];
            root._meta = null;
        } else {
            panelDumps   = root.querySelector('#mad-dump-panel-dumps');
            panelRequest = root.querySelector('#mad-dump-panel-request');
            panelSql     = root.querySelector('#mad-dump-panel-sql');
        }

        const tabsBar = root.querySelector('#mad-dump-modal-tabs');
        const setBadge = (key, n) => {
            const el = tabsBar?.querySelector('[data-count="' + key + '"]');
            if (el) el.textContent = String(n);
        };

        // ── Renderiza Request tab (apenas primeira vez ou quando meta chega) ──
        if (meta && !panelRequest.dataset.rendered) {
            panelRequest.dataset.rendered = '1';
            root._meta = meta;

            const verb = (meta.method || '?').toUpperCase();
            const verbColor = { GET:'#60a5fa', POST:'#a78bfa', PUT:'#fbbf24', DELETE:'#f87171', PATCH:'#fb923c' }[verb] || '#94a3b8';

            const pill = (label, value, color) => value == null || value === '' ? '' :
                '<div style="display:flex;align-items:center;gap:6px;padding:4px 10px;background:#0a0f1a;border:1px solid #1e293b;border-radius:4px;">'
                + '<span style="color:#64748b;font-size:10px;text-transform:uppercase;letter-spacing:.5px;">' + label + '</span>'
                + '<span style="color:' + (color || '#e2e8f0') + ';">' + esc(value) + '</span>'
                + '</div>';

            const headLine = '<div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #1e293b;">'
                + '<span style="font-weight:700;color:' + verbColor + ';font-size:12px;padding:4px 10px;background:#0a0f1a;border:1px solid ' + verbColor + ';border-radius:4px;">' + verb + '</span>'
                + '<span style="color:#cbd5e1;font-size:12px;word-break:break-all;flex:1;">' + esc(meta.uri) + '</span>'
                + (meta.ajax ? '<span style="color:#10b981;font-size:10px;background:#022c22;border:1px solid #064e3b;padding:2px 6px;border-radius:4px;">AJAX</span>' : '')
                + '</div>';

            const pills = '<div style="display:flex;flex-wrap:wrap;gap:6px;font-size:11px;margin-bottom:14px;">'
                + pill('class', meta.class, '#f472b6')
                + pill('action', meta.action, '#fbbf24')
                + pill('user', meta.session_user, '#86efac')
                + pill('ip', meta.ip)
                + pill('php', meta.php)
                + pill('mem', meta.memory, '#60a5fa')
                + pill('peak', meta.memory_peak, '#60a5fa')
                + pill('duration', meta.duration_ms + ' ms', '#a78bfa')
                + pill('session', (meta.session_id || '').substring(0, 8))
                + pill('req', (meta.request_id || '').substring(0, 8))
                + '</div>';

            const renderKv = (obj) => {
                if (!obj || !Object.keys(obj).length) return '<span style="color:#475569;font-style:italic;">empty</span>';
                return Object.entries(obj).map(([k, v]) => {
                    const val = typeof v === 'object' ? JSON.stringify(v) : String(v);
                    return '<div style="padding:3px 0;"><span style="color:#fbbf24;">' + esc(k) + '</span> <span style="color:#64748b;">=&gt;</span> <span style="color:#86efac;">' + esc(val) + '</span></div>';
                }).join('');
            };

            const kvBlock = (title, obj) =>
                '<div style="background:#0a0f1a;border:1px solid #1e293b;border-radius:6px;padding:10px 12px;">'
                + '<div style="color:#94a3b8;font-size:11px;margin-bottom:6px;font-weight:600;">' + title + ' (' + Object.keys(obj || {}).length + ')</div>'
                + '<div>' + renderKv(obj) + '</div>'
                + '</div>';

            const grid = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;font-size:11px;margin-bottom:12px;">'
                + kvBlock('$_GET', meta.get)
                + kvBlock('$_POST', meta.post)
                + '</div>';

            const referer = meta.referer
                ? '<div style="color:#64748b;font-size:11px;word-break:break-all;padding:8px 12px;background:#0a0f1a;border:1px solid #1e293b;border-radius:4px;">↩ <span style="color:#94a3b8;">referer:</span> ' + esc(meta.referer) + '</div>'
                : '';

            const uaLine = meta.user_agent
                ? '<div style="color:#64748b;font-size:11px;word-break:break-all;padding:8px 12px;background:#0a0f1a;border:1px solid #1e293b;border-radius:4px;margin-top:8px;">🖥 <span style="color:#94a3b8;">user-agent:</span> ' + esc(meta.user_agent) + '</div>'
                : '';

            panelRequest.innerHTML = headLine + pills + grid + referer + uaLine;

            // Badge request
            let reqCount = 0;
            if (meta.class) reqCount++;
            if (meta.action) reqCount++;
            reqCount += Object.keys(meta.get || {}).length;
            reqCount += Object.keys(meta.post || {}).length;
            setBadge('request', reqCount);
        }

        // ── Renderiza SQL tab ──
        if (meta && !panelSql.dataset.rendered) {
            panelSql.dataset.rendered = '1';

            const typeColor = { SELECT:'#60a5fa', INSERT:'#86efac', UPDATE:'#fbbf24', DELETE:'#f87171', BEGIN:'#94a3b8', COMMIT:'#94a3b8' };
            const sqlHighlight = (s) => esc(s)
                .replace(/\b(SELECT|FROM|WHERE|AND|OR|JOIN|LEFT|RIGHT|INNER|OUTER|ON|GROUP BY|ORDER BY|LIMIT|OFFSET|INSERT INTO|VALUES|UPDATE|SET|DELETE FROM|AS|IN|NOT|NULL|IS|BETWEEN|LIKE|CASE|WHEN|THEN|ELSE|END|HAVING|UNION|DISTINCT|COUNT|SUM|AVG|MIN|MAX)\b/gi, '<span style="color:#a78bfa;font-weight:600;">$1</span>')
                .replace(/'([^']*)'/g, '<span style="color:#86efac;">\'$1\'</span>')
                .replace(/\b(\d+)\b/g, '<span style="color:#60a5fa;">$1</span>');

            const summary = '<div style="display:flex;gap:10px;align-items:center;margin-bottom:12px;padding-bottom:10px;border-bottom:1px solid #1e293b;font-size:11px;">'
                + '<span style="color:#94a3b8;">Total:</span>'
                + '<span style="color:#e2e8f0;font-weight:600;">' + (meta.sql_count || 0) + ' queries</span>'
                + (meta.sql_total_ms ? '<span style="color:#475569;">·</span><span style="color:#a78bfa;">' + meta.sql_total_ms + ' ms</span>' : '')
                + '</div>';

            const sqlList = (meta.sql_queries || []).map((q, i) => {
                const c = typeColor[q.type] || '#94a3b8';
                const dur = q.duration_ms != null ? q.duration_ms + ' ms' : '';
                return '<div style="padding:10px 12px;margin-bottom:8px;background:#0a0f1a;border:1px solid #1e293b;border-left:3px solid ' + c + ';border-radius:4px;font-size:11px;">'
                    + '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;color:#64748b;">'
                    +   '<span style="color:#475569;">#' + (i + 1) + '</span>'
                    +   '<span style="color:' + c + ';font-weight:600;">' + esc(q.type || '?') + '</span>'
                    +   '<span style="color:#475569;">·</span>'
                    +   '<span>' + esc(q.db || '') + '</span>'
                    +   (dur ? '<span style="color:#475569;">·</span><span>' + esc(dur) + '</span>' : '')
                    +   '<span style="color:#475569;">·</span>'
                    +   '<span>' + esc(q.time || '') + '</span>'
                    + '</div>'
                    + '<div style="font-family:ui-monospace,monospace;color:#cbd5e1;line-height:1.5;word-break:break-word;white-space:pre-wrap;">' + sqlHighlight(q.sql) + '</div>'
                    + '</div>';
            }).join('');

            const empty = '<div style="text-align:center;padding:32px;color:#475569;font-style:italic;">no SQL queries captured</div>';

            panelSql.innerHTML = summary + (sqlList || empty);

            setBadge('sql', meta.sql_count || 0);
        }

        // ── Renderiza Dumps tab (append) ──
        for (const dump of dumps) {
            root._dumpCache.push(dump);
            const card = document.createElement('div');
            card.style.cssText = 'margin-bottom:14px;padding:12px 14px;background:#0a0f1a;border:1px solid #1e293b;border-left:3px solid #6366f1;border-radius:6px;';

            const head = document.createElement('div');
            head.style.cssText = 'display:flex;align-items:center;gap:10px;margin-bottom:8px;color:#94a3b8;font-size:11px;';
            head.innerHTML = '<span style="color:#a5b4fc;font-weight:600;">📄 ' + esc(dump.file || '?') + ':' + esc(dump.line || 0) + '</span>'
                + '<span style="color:#475569;">·</span>'
                + '<span>' + esc(dump.time || '') + '</span>'
                + '<span style="color:#475569;">·</span>'
                + '<span>' + (dump.items?.length || 0) + ' var(s)</span>';
            card.appendChild(head);

            for (const item of (dump.items || [])) {
                const row = document.createElement('div');
                row.style.cssText = 'padding:6px 0;border-top:1px dashed #1e293b;';
                row.innerHTML = '<div style="color:#64748b;font-size:10px;margin-bottom:3px;">#' + item.index + ' · ' + esc(item.type || '') + '</div>'
                    + '<div style="line-height:1.5;">' + (item.html || '') + '</div>';
                card.appendChild(row);
            }
            panelDumps.appendChild(card);
        }

        setBadge('dumps', root._dumpCache.length);

        // Se nova mensagem chegou com aba ativa dumps, auto-scroll
        if (root._activeTab === 'dumps') {
            panelDumps.scrollTop = panelDumps.scrollHeight;
        }
        try { root.focus(); } catch (e) {}
    },

    // ── Aplicação de operações (MadResponse) ─────────────────────────────────

    /**
     * Campo mascarado (numeric/money): o `[name]` é o input HIDDEN, e o texto
     * que o usuário vê é `display` no Alpine. Setar só o hidden (op `val`, de
     * `$this->form->set('total', …)`) trocava o valor do POST mas deixava a
     * tela mostrando o número velho.
     */
    _syncMaskedDisplay(el) {
        // Implementação única em mad-ui.js (_madSyncMaskedField), onde moram os
        // componentes mascarados. Fallback local pro caso do mad-ui (defer)
        // ainda não ter carregado.
        if (typeof _madSyncMaskedField === 'function') {
            _madSyncMaskedField(el);
            return;
        }
        if (!window.Alpine || el.type !== 'hidden') return;
        const box = el.closest('[x-data]');
        if (!box) return;
        try {
            const ad = Alpine.$data(box);
            if (ad && typeof ad.setValue === 'function') {
                ad.setValue(el.value);
            }
        } catch (e) { /* nó sem escopo Alpine */ }
    },

    /**
     * Aplica uma lista de operações retornadas pelo MadResponse::send().
     *
     * `scope` (opcional) = wrapper [mad-component] de quem fez a requisição —
     * o MadWire sempre passa. Com ele, o alvo do op é procurado primeiro no
     * componente (ver _opTarget).
     */
    async applyOps(ops, scope = null) {
        for (const op of ops) {
            const el = op.target ? this._opTarget(op.target, scope) : null;

            switch (op.op) {

                case 'html':
                    if (el) {
                        el.innerHTML = op.content;
                        this._reinit(el);
                        // fieldError() em campo dentro de aba inativa (x-show):
                        // o slot existe no DOM mas fica invisível — ativa a aba.
                        if (op.content && op.target && op.target.indexOf('data-field-error') !== -1) {
                            this._revealFieldError(el);
                        }
                    }
                    break;

                case 'val':
                    if (el) {
                        if (op.onlyEmpty && el.value) break;
                        // MAD Select: o widget só re-projeta pelo setValue (o
                        // `change` não sincroniza). Com `el.value` cru, o
                        // form->set('uf', 'PR') gravava no <select> nativo e a
                        // tela continuava em "Selecione...". Silent: o change
                        // sai logo abaixo, uma vez só.
                        if (el._madSelect) el._madSelect.setValue(op.content, true);
                        else el.value = op.content;
                        this._syncMaskedDisplay(el);
                        el.dispatchEvent(new Event('input', { bubbles: true }));
                        el.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                    break;

                case 'attr':
                    if (el) el.setAttribute(op.attr, op.content);
                    break;

                case 'addClass':
                    if (el) el.classList.add(...op.content.split(' '));
                    break;

                case 'removeClass':
                    if (el) el.classList.remove(...op.content.split(' '));
                    break;

                case 'show':
                    if (el) el.style.display = '';
                    break;

                case 'hide':
                    if (el) el.style.display = 'none';
                    break;

                case 'toast':
                    if (window.madToast) {
                        madToast({ message: op.message, type: op.type || 'info', title: op.title || '', position: op.position || 'top-right' });
                    }
                    break;

                case 'dump_modal':
                    Mad.openDumpModal(op.dumps || [], op.meta || null);
                    break;

                case 'alert':
                    MadDialog.show({ type: op.type, title: op.title, message: op.message });
                    break;

                case 'reload':
                    // Chama método PHP e injeta resultado no target
                    await this.exec(`${op.class}@${op.method}`, op.params || {}, op.target);
                    break;

                case 'redirect': {
                    // Navegação COMPLETA (full page), não SPA — carrega o casco
                    // do destino (ex.: shell admin pós-login). Web-aware: usa o
                    // rewrite do mad-web-routing (MadWebRoute) p/ virar /app/* no
                    // modo web; no modo legado vai a URL crua. 100% MAD.
                    const _dest = (typeof window.MadWebRoute === 'function')
                        ? window.MadWebRoute(op.url) : op.url;
                    if (op.delay) {
                        setTimeout(() => { window.location.href = _dest; }, op.delay);
                    } else {
                        window.location.href = _dest;
                    }
                    break;
                }

                case 'script': {
                    // O escopo fica visível ao script durante o eval: um
                    // MadResponse::emit() de dentro de uma ação (saída do PHP que
                    // o handler vira op 'script') mira o MESMO componente.
                    const prevScope = this._opScope;
                    this._opScope = scope;
                    // eslint-disable-next-line no-eval
                    try { eval(op.content); } catch (e) { console.error('Mad script op:', e); }
                    finally { this._opScope = prevScope; }
                    break;
                }

                case 'reload_completion':
                    window[op.var] = op.items;
                    break;

                case 'mad_hide': {
                    const sel = this._madScopeSelector(op.scope, op.name);
                    this._opTargets(sel, scope).forEach(n => n.classList.add('mad-hidden'));
                    break;
                }

                case 'mad_show': {
                    const sel = this._madScopeSelector(op.scope, op.name);
                    this._opTargets(sel, scope).forEach(n => n.classList.remove('mad-hidden'));
                    break;
                }

                case 'mad_readonly':
                    this._madApplyReadonly(op.name, !!op.readonly);
                    break;

                case 'focus':
                    this._madFocusField(op.name, scope);
                    break;

                case 'mad_disabled': {
                    const safe = CSS.escape ? CSS.escape(op.name) : String(op.name).replace(/"/g, '\\"');
                    document.querySelectorAll(`[data-mad-btn="${safe}"]`).forEach(el => {
                        if (el.tagName === 'BUTTON') {
                            el.disabled = !!op.disabled;
                        } else {
                            // <a> renderizado pelo <mad-btn href=...>
                            if (op.disabled) {
                                el.setAttribute('aria-disabled', 'true');
                                el.setAttribute('tabindex', '-1');
                                el.style.opacity = '.45';
                                el.style.pointerEvents = 'none';
                            } else {
                                el.removeAttribute('aria-disabled');
                                el.removeAttribute('tabindex');
                                el.style.opacity = '';
                                el.style.pointerEvents = '';
                            }
                        }
                    });
                    break;
                }

                case 'fl_combo': {
                    // setItems('campo[]') — aplica em TODAS as rows dos field-lists
                    if (typeof _madFlApplyComboAll === 'function') {
                        // Escopo ao wrapper do componente atual (se disponível)
                        const w = document.querySelector('[mad-component]');
                        _madFlApplyComboAll(w || document, op.target, op.options);
                    }
                    break;
                }

                case 'fl_rows':
                case 'df_add':
                case 'df_delete':
                case 'df_display':
                case 'df_field_error': {
                    // Ops de lista (field-list / detail-form). Chegam aqui quando o
                    // servidor redesenha o componente inteiro (html + ops) — ex.: a
                    // ação do before-add mexeu numa prop array. O código é o MESMO
                    // do caminho parcial (MadWire.applyListOp); antes o df_add caía
                    // no default deste switch e a linha não entrava, sem aviso.
                    const live = scope && scope.isConnected !== false ? scope : null;
                    const w = live || document.querySelector('[mad-component]');
                    if (w && typeof MadWire !== 'undefined' && MadWire && typeof MadWire.applyListOp === 'function') {
                        MadWire.applyListOp(op, w);
                    } else if (op.op === 'fl_rows') {
                        // Sem o MadWire carregado: só o fl_rows, como sempre.
                        const root = w || document;
                        const fl = root.querySelector(`[data-mad-fl-name="${op.target}"]`)
                                || root.querySelector(`[data-mad-df-name="${op.target}"]`);
                        if (fl && window.Alpine) {
                            try {
                                const ad = Alpine.$data(fl);
                                if (ad) ad.rows = op.rows || [];
                            } catch (e) { console.error('Mad fl_rows:', e); }
                        }
                    }
                    break;
                }

                case 'reload_combo': {
                    const sel = _madFindSelect(op.name, op.scope);
                    if (!sel) break;
                    // Limpa options atuais
                    sel.innerHTML = '';
                    // Placeholder (opcional)
                    if (op.placeholder !== null && op.placeholder !== undefined) {
                        const ph = document.createElement('option');
                        ph.value = '';
                        ph.textContent = op.placeholder;
                        sel.appendChild(ph);
                    }
                    // Monta novas options
                    (op.items || []).forEach(item => {
                        const o = document.createElement('option');
                        o.value = item.value;
                        o.textContent = item.label;
                        if (op.selected !== null && op.selected !== undefined && String(op.selected) === String(item.value)) {
                            o.selected = true;
                        }
                        sel.appendChild(o);
                    });
                    // Notifica listeners (Alpine mad:model, depends-on, etc)
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    // MAD Select (mad-dbcombo/unique-search): re-projeta a UI a partir
                    // do <select> nativo (já reconstruído acima — fonte da verdade).
                    if (sel._madSelect) {
                        sel._madSelect.refreshOptions(true);
                    }
                    break;
                }

                // Acrescenta UMA option e seleciona — sem recarregar a lista.
                // É o retorno do formulário aberto por "Sem resultados →
                // Cadastrar novo" (MadComponent::returnToCombo).
                // SEM await aqui: em mad-livewire.js o `close_overlay` é tratado
                // inline no forEach enquanto ops desconhecidos caem num
                // Mad.applyOps NÃO aguardado — um await inverteria a ordem e o
                // drawer fecharia antes da option entrar.
                case 'combo_add_option': {
                    // <mad-seek create>: o campo de busca não tem <select> — a
                    // chave vai para o hidden e o seek resolve texto + <mad-fill>.
                    const seek = _madFindSeek(op.name, op.scope);
                    if (seek) {
                        seek.dispatchEvent(new CustomEvent('mad-seek-created', { detail: { id: op.value, label: op.label } }));
                        break;
                    }
                    const sel = _madFindSelect(op.name, op.scope);
                    if (!sel) {
                        console.warn('[Mad] combo_add_option: <select> não encontrado para', op.name);
                        break;
                    }
                    if (sel._madSelect) {
                        // MAD Select: escreve no <select> nativo (fonte da
                        // verdade) e re-projeta. addItem() já dispara o change.
                        sel._madSelect.addOption({ value: op.value, text: op.label });
                        if (op.select !== false) sel._madSelect.addItem(op.value, false);
                        sel._madSelect.refreshOptions(false);
                        sel._madSelect.close();
                    } else {
                        let opt = Array.from(sel.options).find(o => o.value === String(op.value));
                        if (!opt) {
                            opt = document.createElement('option');
                            opt.value = op.value;
                            opt.textContent = op.label;
                            sel.appendChild(opt);
                        }
                        if (op.select !== false) {
                            opt.selected = true;
                            sel.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                    break;
                }

                case 'reload_radio': {
                    const wrap = document.querySelector(`[data-mad-radio-group="${op.name}"]`);
                    if (!wrap) break;
                    const isButton = wrap.classList.contains('mad-radio-btn-group');
                    wrap.innerHTML = '';
                    (op.items || []).forEach(item => {
                        const oId = `${op.name}_${String(item.value).replace(/[^a-zA-Z0-9_]/g, '_')}`;
                        const isChecked = op.selected !== null && op.selected !== undefined && String(op.selected) === String(item.value);

                        if (isButton) {
                            const label = document.createElement('label');
                            label.className = 'mad-radio-btn' + (isChecked ? ' is-active' : '');
                            label.setAttribute('for', oId);

                            const input = document.createElement('input');
                            input.type = 'radio';
                            input.id = oId;
                            input.name = op.name;
                            input.value = item.value;
                            input.className = 'mad-radio-btn-input';
                            if (isChecked) input.checked = true;

                            const txt = document.createElement('span');
                            txt.className = 'mad-radio-btn-label';
                            txt.textContent = item.label;

                            label.appendChild(input);
                            label.appendChild(txt);
                            wrap.appendChild(label);
                        } else {
                            const label = document.createElement('label');
                            label.className = 'mad-radio-wrap';
                            label.setAttribute('for', oId);

                            const input = document.createElement('input');
                            input.type  = 'radio';
                            input.id    = oId;
                            input.name  = op.name;
                            input.value = item.value;
                            input.className = 'mad-radio';
                            if (isChecked) input.checked = true;

                            const circle = document.createElement('span');
                            circle.className = 'mad-radio-circle';

                            const txt = document.createElement('span');
                            txt.className = 'mad-radio-label';
                            txt.textContent = item.label;

                            label.appendChild(input);
                            label.appendChild(circle);
                            label.appendChild(txt);
                            wrap.appendChild(label);
                        }
                    });
                    wrap.dispatchEvent(new Event('change', { bubbles: true }));
                    break;
                }

                case 'reload_checkbox_group': {
                    const wrap = document.querySelector(`.mad-checkbox-group[data-mad-model="${op.name}"]`);
                    if (!wrap) break;
                    const sel = (op.selected || []).map(String);
                    // break-items: os espaçadores de quebra são filhos do container
                    // e morrem no innerHTML=''. O blade grava o N em data-mad-break
                    // para que a reconstrução os recoloque.
                    const brk = parseInt(wrap.dataset.madBreak || '0', 10) || 0;
                    const total = (op.items || []).length;
                    wrap.innerHTML = '';
                    (op.items || []).forEach((item, idx) => {
                        const chkId = `${op.name}_${item.value}`;
                        const label = document.createElement('label');
                        label.className = 'mad-checkbox-wrap';
                        label.setAttribute('for', chkId);

                        const input = document.createElement('input');
                        input.type  = 'checkbox';
                        input.id    = chkId;
                        input.name  = `${op.name}[]`;
                        input.value = item.value;
                        input.className = 'mad-checkbox';
                        if (sel.includes(String(item.value))) input.checked = true;

                        const box = document.createElement('span');
                        box.className = 'mad-checkbox-box';

                        const txt = document.createElement('span');
                        txt.className = 'mad-checkbox-label';
                        txt.textContent = item.label;

                        label.appendChild(input);
                        label.appendChild(box);
                        label.appendChild(txt);
                        wrap.appendChild(label);

                        if (brk > 0 && (idx + 1) % brk === 0 && idx + 1 < total) {
                            const spacer = document.createElement('div');
                            spacer.className = 'mad-checkbox-break';
                            wrap.appendChild(spacer);
                        }
                    });
                    wrap.dispatchEvent(new Event('change', { bubbles: true }));
                    break;
                }

                case 'reload_multi_entry': {
                    const sel = document.querySelector(`select[name="${op.name}[]"][data-mad-multientry]`);
                    if (!sel) break;
                    const selected = (op.selected || []).map(String);

                    // Reconstrói as options no <select> nativo (fonte da verdade)
                    sel.innerHTML = '';
                    (op.items || []).forEach(item => {
                        const o = document.createElement('option');
                        o.value = item.value;
                        o.textContent = item.label;
                        if (selected.includes(String(item.value))) o.selected = true;
                        sel.appendChild(o);
                    });
                    sel.dispatchEvent(new Event('change', { bubbles: true }));
                    // MAD Select: re-projeta a UI
                    if (sel._madSelect) sel._madSelect.refreshOptions(true);
                    break;
                }

                case 'reload_sort_list': {
                    const wrap = document.querySelector(`[data-mad-sort-list="${op.name}"]`);
                    if (!wrap) break;
                    const selected = (op.selected || []).map(String);
                    const itemsMap = new Map();
                    (op.items || []).forEach(i => itemsMap.set(String(i.value), i.label));

                    // Ordena: selecionados primeiro (na ordem informada), resto depois
                    const ordered = [];
                    selected.forEach(k => { if (itemsMap.has(k)) ordered.push({ value: k, label: itemsMap.get(k) }); });
                    itemsMap.forEach((label, value) => {
                        if (!selected.includes(value)) ordered.push({ value, label });
                    });

                    wrap.innerHTML = '';
                    ordered.forEach(item => {
                        const div = document.createElement('div');
                        div.className = 'mad-sort-item';
                        div.dataset.key = item.value;

                        const handle = document.createElement('span');
                        handle.className = 'mad-sort-handle';
                        handle.innerHTML = '<i data-lucide="grip-vertical"></i>';

                        const lbl = document.createElement('span');
                        lbl.className = 'mad-sort-label';
                        lbl.textContent = item.label;

                        const hidden = document.createElement('input');
                        hidden.type  = 'hidden';
                        hidden.name  = `${op.name}[]`;
                        hidden.value = item.value;

                        div.appendChild(handle);
                        div.appendChild(lbl);
                        div.appendChild(hidden);
                        wrap.appendChild(div);
                    });

                    // Reinicializa lucide icons
                    if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                    wrap.dispatchEvent(new Event('change', { bubbles: true }));
                    break;
                }

                case 'reload_checklist': {
                    const wrap = document.querySelector(`[data-mad-checklist="${op.name}"]`);
                    if (!wrap) break;
                    // Acessa o x-data="madChecklist(...)" via Alpine e substitui items/ids
                    if (window.Alpine && typeof Alpine.$data === 'function') {
                        const data = Alpine.$data(wrap);
                        if (data) {
                            data.items = op.items || [];
                            data.ids   = (op.selected || []).map(String);
                        }
                    }
                    break;
                }

                case 'remove_row': {
                    const row = document.querySelector(`tr[data-row-id="${_madCssId(op.rowId)}"]`);
                    // Grid DESTA linha — o rodapé dela é acertado no fim do case.
                    const rowWrap = row ? row.closest('.mad-dg-wrap') : null;
                    if (row) row.remove();
                    // row-detail: a 2a linha descritiva do registro. Sem isto
                    // ela sobrevivia sozinha a exclusao da linha.
                    _madRemoveRowDetail(op.rowId);
                    // Also remove card view counterpart
                    const cardRm = document.querySelector(`[data-card-id="${_madCssId(op.rowId)}"]`);
                    if (cardRm) cardRm.remove();
                    // Restaura o empty-state quando a ULTIMA linha/card sai
                    // (tabela E cards) — "Nenhum registro" volta sem reload.
                    const dgBody = _madGridEl(op.gridKey, '.mad-dg-body');
                    if (dgBody && !dgBody.querySelector('tr[data-row-id]')) {
                        const emptyTr = dgBody.querySelector('.mad-dg-empty-row');
                        if (emptyTr) emptyTr.style.display = '';
                    }
                    const dgCards = _madGridEl(op.gridKey, '.mad-dg-cards');
                    if (dgCards && !dgCards.querySelector('.mad-dg-card')) {
                        const cardsEmpty = _madGridEl(op.gridKey, '.mad-dg-cards-empty');
                        if (cardsEmpty) cardsEmpty.style.display = '';
                    }
                    // "1–1 de 1" com a lista vazia: o rodapé acompanha a remoção.
                    if (rowWrap) _madGridFooterAfterRemove(rowWrap);
                    break;
                }

                case 'gantt_patch_task':
                case 'gantt_patch_tasks': {
                    const list = op.op === 'gantt_patch_task'
                        ? [{ id: op.taskId, start: op.start, end: op.end }]
                        : (op.tasks || []);
                    const selector = op.selector || '.mad-gantt';
                    const ganttRoots = document.querySelectorAll(selector);
                    ganttRoots.forEach((el) => {
                        if (!el || !el._x_dataStack) return;
                        // Alpine.$data() resolves the component instance regardless of nested stacks
                        const data = (window.Alpine && Alpine.$data) ? Alpine.$data(el) : null;
                        if (!data) return;
                        list.forEach((entry) => {
                            const t = data._getTask && data._getTask(entry.id);
                            if (!t) return;
                            // `undefined` = não mexe; null/'' = limpa a data
                            if (entry.start !== undefined) t.start = entry.start;
                            if (entry.end   !== undefined) t.end   = entry.end;
                        });
                        // Recompute geometry + repatch all bars in the list
                        if (data._fullRecompute) data._fullRecompute();
                        if (data._renderAll)    data._renderAll();
                    });
                    break;
                }

                case 'gantt_upsert_task': {
                    const selector = op.selector || '.mad-gantt';
                    document.querySelectorAll(selector).forEach((el) => {
                        const data = (window.Alpine && Alpine.$data) ? Alpine.$data(el) : null;
                        if (!data || !Array.isArray(data.tasks)) return;
                        // Normalização canônica (id string, milestone via type,
                        // progress em fração) — igual ao ingest do init; sem
                        // ela o upsert quebrava a invariante do componente.
                        const norm = (window.MadGantt && MadGantt.util && MadGantt.util.normalizeTask)
                            ? MadGantt.util.normalizeTask(op.task || {}, data.progressScale)
                            : (op.task || {});
                        const id = String(norm.id || '');
                        if (!id) return;
                        const idx = data.tasks.findIndex(t => String(t.id) === id);
                        if (idx >= 0) {
                            // merge — preserva chaves internas (expanded, resourceIds, ...)
                            data.tasks[idx] = Object.assign({}, data.tasks[idx], norm);
                        } else {
                            data.tasks.push(norm);
                        }
                        if (data._fullRecompute) data._fullRecompute();
                        if (data._renderAll)    data._renderAll();
                    });
                    break;
                }

                case 'gantt_remove_task': {
                    const selector = op.selector || '.mad-gantt';
                    const ids = new Set([String(op.taskId), ...((op.alsoRemove || []).map(String))]);
                    document.querySelectorAll(selector).forEach((el) => {
                        const data = (window.Alpine && Alpine.$data) ? Alpine.$data(el) : null;
                        if (!data || !Array.isArray(data.tasks)) return;
                        data.tasks = data.tasks.filter(t => !ids.has(String(t.id)));
                        if (Array.isArray(data.dependencies)) {
                            data.dependencies = data.dependencies.filter(
                                d => !ids.has(String(d.from)) && !ids.has(String(d.to)));
                        }
                        if (data._fullRecompute) data._fullRecompute();
                        if (data._renderAll)    data._renderAll();
                    });
                    break;
                }

                case 'manage_row': {
                    const existing = document.querySelector(`tr[data-row-id="${_madCssId(op.rowId)}"]`);
                    if (existing) {
                        if (!op.html) {
                            // renderSingleRow não achou o registro (escopo/tenant/
                            // conexão): trocar a linha por '' a APAGAVA em silêncio.
                            // Mantém a linha velha e avisa — o banco está salvo, só a
                            // tela não reflete até recarregar.
                            console.warn('[mad] manage_row sem HTML para', op.rowId, '- linha mantida');
                            break;
                        }
                        // A detail antiga e irma da <tr>: o outerHTML abaixo nao
                        // a alcanca, e o html novo ja traz a sua — sem remover,
                        // a descricao velha ficava duplicada logo abaixo.
                        _madRemoveRowDetail(op.rowId);
                        existing.outerHTML = op.html;
                        const updated = document.querySelector(`tr[data-row-id="${_madCssId(op.rowId)}"]`);
                        if (updated) {
                            updated.classList.add('mad-dg-row-highlight');
                            if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                            if (window.Alpine) Alpine.initTree(updated);
                            _madInitRowDetail(op.rowId);
                            setTimeout(() => updated.classList.remove('mad-dg-row-highlight'), 2500);
                            _madRevealRow(updated);
                        }
                    } else {
                        const tbody = _madGridEl(op.gridKey, '.mad-dg-body');
                        if (tbody) {
                            // Esconde (nao remove) o empty-state — remove_row o
                            // mostra de novo quando a lista esvazia.
                            const emptyTr = tbody.querySelector('.mad-dg-empty-row');
                            if (emptyTr) emptyTr.style.display = 'none';
                            tbody.insertAdjacentHTML('afterbegin', op.html);
                            const newRow = tbody.querySelector(`tr[data-row-id="${_madCssId(op.rowId)}"]`);
                            if (newRow) _madGridFooterAfterInsert(newRow.closest('.mad-dg-wrap'));
                            if (newRow) {
                                newRow.classList.add('mad-dg-row-highlight');
                                if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                                if (window.Alpine) Alpine.initTree(newRow);
                                _madInitRowDetail(op.rowId);
                                setTimeout(() => newRow.classList.remove('mad-dg-row-highlight'), 2500);
                                _madRevealRow(newRow);
                            }
                        }
                    }
                    // Card view counterpart
                    if (op.cardHtml) {
                        const existingCard = document.querySelector(`[data-card-id="${_madCssId(op.rowId)}"]`);
                        if (existingCard) {
                            existingCard.outerHTML = op.cardHtml;
                            const updatedCard = document.querySelector(`[data-card-id="${_madCssId(op.rowId)}"]`);
                            if (updatedCard) {
                                updatedCard.classList.add('mad-dg-card-highlight-anim');
                                if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                                if (window.Alpine) Alpine.initTree(updatedCard);
                                setTimeout(() => updatedCard.classList.remove('mad-dg-card-highlight-anim'), 2500);
                                _madRevealRow(updatedCard);
                            }
                        } else {
                            const cardsWrap = _madGridEl(op.gridKey, '.mad-dg-cards');
                            if (cardsWrap) {
                                // Lista estava vazia: esconde o empty-state irmao
                                // (remove_row o mostra de novo ao esvaziar).
                                const cardsEmpty = _madGridEl(op.gridKey, '.mad-dg-cards-empty');
                                if (cardsEmpty) cardsEmpty.style.display = 'none';
                                cardsWrap.insertAdjacentHTML('afterbegin', op.cardHtml);
                                const newCard = cardsWrap.querySelector(`[data-card-id="${_madCssId(op.rowId)}"]`);
                                if (newCard) {
                                    newCard.classList.add('mad-dg-card-highlight-anim');
                                    if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                                    if (window.Alpine) Alpine.initTree(newCard);
                                    setTimeout(() => newCard.classList.remove('mad-dg-card-highlight-anim'), 2500);
                                    _madRevealRow(newCard);
                                }
                            }
                        }
                    }
                    break;
                }

                // ── Kanban ops ──────────────────────────────
                case 'manage_card': {
                    const existingCard = document.querySelector('[data-card-id="' + _madCssId(op.cardId) + '"]');
                    if (existingCard) {
                        existingCard.outerHTML = op.html;
                        const updatedCard = document.querySelector('[data-card-id="' + _madCssId(op.cardId) + '"]');
                        if (updatedCard) {
                            updatedCard.classList.add('mad-kanban-card--highlight');
                            if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                            if (window.Alpine) Alpine.initTree(updatedCard);
                            setTimeout(() => updatedCard.classList.remove('mad-kanban-card--highlight'), 2500);
                        }
                    } else {
                        // Insert at top of matching stage
                        const tmp = document.createElement('div');
                        tmp.innerHTML = op.html;
                        const newCard = tmp.firstElementChild;
                        if (newCard) {
                            const stageId = newCard.dataset.stageId;
                            const container = document.querySelector('.mad-kanban-cards[data-stage-id="' + _madCssId(stageId) + '"]');
                            if (container) {
                                container.prepend(newCard);
                                newCard.classList.add('mad-kanban-card--highlight');
                                if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                                if (window.Alpine) Alpine.initTree(newCard);
                                setTimeout(() => newCard.classList.remove('mad-kanban-card--highlight'), 2500);
                            }
                        }
                    }
                    break;
                }

                case 'remove_card': {
                    const cardToRemove = document.querySelector('[data-card-id="' + _madCssId(op.cardId) + '"]');
                    if (cardToRemove) cardToRemove.remove();
                    break;
                }

                case 'append_cards': {
                    const cardsContainer = document.querySelector('.mad-kanban-cards[data-stage-id="' + _madCssId(op.stageId) + '"]');
                    let appended = 0;
                    if (cardsContainer && op.html) {
                        // Alpine.initTree SÓ nos cards realmente novos (slice a
                        // partir do count anterior) — o filtro antigo
                        // :not([x-data-initialized]) checava um atributo que
                        // nunca era setado e re-inicializava TODOS os cards a
                        // cada load-more (menus duplo-bindados).
                        const before = cardsContainer.querySelectorAll('.mad-kanban-card').length;
                        cardsContainer.insertAdjacentHTML('beforeend', op.html);
                        if (window.lucide) lucide.createIcons({ attrs: { class: ['lucide'] }, nameAttr: 'data-lucide' });
                        const all = Array.from(cardsContainer.querySelectorAll('.mad-kanban-card'));
                        all.slice(before).forEach(el => {
                            // dedupe: card pode já estar no board (movido por
                            // drag entre páginas do offset) — descarta a cópia
                            const dup = document.querySelectorAll('[data-card-id="' + _madCssId(el.dataset.cardId) + '"]');
                            if (dup.length > 1) { el.remove(); return; }
                            if (window.Alpine) Alpine.initTree(el);
                            appended++;
                        });
                    }
                    // Sinaliza o board Alpine: reseta loading, avança offset e
                    // marca exhausted quando o servidor manda hasMore=false.
                    // Borbulha do container → listener fica escopado por board.
                    const kbDetail = {
                        stageId:  op.stageId,
                        appended: appended,
                        hasMore:  op.hasMore !== undefined ? !!op.hasMore : !!(op.html),
                    };
                    if (cardsContainer) {
                        cardsContainer.dispatchEvent(new CustomEvent('mad-kanban-loaded', { bubbles: true, detail: kbDetail }));
                    } else {
                        window.dispatchEvent(new CustomEvent('mad-kanban-loaded', { detail: kbDetail }));
                    }
                    break;
                }

                case 'kanban_move_failed': {
                    // Servidor recusou o onCardMove — avisa o board (bubbles a
                    // partir do card) p/ desfazer o move otimista do drag.
                    const failedCard = document.querySelector('[data-card-id="' + _madCssId(op.cardId) + '"]');
                    if (failedCard) {
                        failedCard.dispatchEvent(new CustomEvent('mad-kanban-move-failed', {
                            bubbles: true,
                            detail: { cardId: String(op.cardId) },
                        }));
                    }
                    break;
                }

                case 'close_overlay':
                    this._closeOverlayOp(op, scope);
                    break;

                case 'refresh_screen':
                    this._refreshScreens(op, scope);
                    break;

                // ── Tree View ops ──────────────────────────────
                case 'tree_add': {
                    const treeEl = document.querySelector('[data-mad-tree="' + op.tree + '"]');
                    if (treeEl && window.Alpine) {
                        const treeData = Alpine.$data(treeEl);
                        if (treeData && treeData.addNode) treeData.addNode(op.node);
                    }
                    break;
                }

                case 'tree_remove': {
                    const treeRm = document.querySelector('[data-mad-tree="' + op.tree + '"]');
                    if (treeRm && window.Alpine) {
                        const treeRmData = Alpine.$data(treeRm);
                        if (treeRmData && treeRmData.removeNode) treeRmData.removeNode(op.id);
                    }
                    break;
                }

                case 'tree_update': {
                    const treeUp = document.querySelector('[data-mad-tree="' + op.tree + '"]');
                    if (treeUp && window.Alpine) {
                        const treeUpData = Alpine.$data(treeUp);
                        if (treeUpData && treeUpData.updateNode) treeUpData.updateNode(op.id, op.data);
                    }
                    break;
                }

                case 'tree_active': {
                    const treeAct = document.querySelector('[data-mad-tree="' + op.tree + '"]');
                    if (treeAct && window.Alpine) {
                        const treeActData = Alpine.$data(treeAct);
                        if (treeActData && treeActData.setActive) treeActData.setActive(op.id);
                    }
                    break;
                }
            }
        }
    },

    // ── Field binding (data-mad-action) ──────────────────────────────────────

    /**
     * Manipulador interno chamado quando um campo com data-mad-action muda.
     */
    async _handleFieldAction(e) {
        const el = e.target;
        if (!el.dataset || !el.dataset.madAction) return;

        // Verifica se o trigger bate com o evento
        const trigger = el.dataset.madTrigger || 'change';
        if (e.type !== trigger) return;

        const action = el.dataset.madAction;   // ex: 'ClienteForm@onExitCep'
        const target = el.dataset.madTarget || null;
        // URL amigável assada pelo <mad-input-field> quando a classe resolve.
        const url    = el.dataset.madActionUrl || null;

        // Coleta dados do formulário pai + campo atual
        const data = {};
        const form = el.closest('form');
        if (form) {
            new FormData(form).forEach((v, k) => { data[k] = v; });
        }
        data[el.name] = el.value;

        await Mad.exec(action, data, target, url);
    },

    // ── Injeção de HTML ───────────────────────────────────────────────────────

    _injectFull(html, url, opts) {
        // Abas ligadas: o "conteudo principal" e a aba ativa — qualquer caminho
        // que chegue aqui (redirect de MadResponse, callbacks legados) troca o
        // conteudo dela e mantem URL/rotulo em dia. Overlays nunca passam aqui.
        if (this._tabsOn() && !this._isOverlayHtml(html)) {
            window.MadTabs.navigate({ url: url || null, html });
            return;
        }
        const el = document.querySelector(this.contentTarget);
        if (el) this._injectInto(el, html, url);

        // Atualiza a URL do browser — menos no voltar/avançar do navegador
        // (opts.history): ele já está na entrada. Empilhar de novo apagava o
        // "avançar" e prendia o "voltar" na mesma tela.
        if (url && !(opts && opts.history) && !url.includes('register_state=false') && history.pushState) {
            // Roteia URL em formato de query (?class=...) pra rota amigavel
            // (/app/slug) ANTES do pushState — senao a barra mostra a query
            // crua mesmo com a rota declarada.
            const webRoute = (typeof window.MadWebRoute === 'function') ? window.MadWebRoute : null;
            const browserUrl = webRoute ? webRoute(url) : url;
            history.pushState({ url }, '', browserUrl);
        }
    },

    /**
     * Erro de TELA que o servidor renderizou com status 200 (MadComponent::
     * _renderError → MadErrorRenderer com debug, MadErrorPage::internal sem
     * debug — os dois marcados com `data-mad-error-page`). Quem abre gaveta/
     * modal (Mad.get, Mad.overlay) jogava esse HTML no #mad_partial, no fim da
     * página — fora da vista, ou embaixo de uma listagem comprida: a tela não
     * abria e nada dizia por quê.
     *
     * Mostra o erro num diálogo e devolve true (o chamador aborta a injeção).
     * O conteúdo é o que o servidor mandou: sem debug ele não tem código fonte.
     */
    _errorFragment(html, res = null, ctx = null) {
        const kind = (window.MadErrorModal && typeof window.MadErrorModal.errorFragmentKind === 'function')
            ? window.MadErrorModal.errorFragmentKind(html)
            : ((/\bdata-mad-error-page="(overlay|debug|internal)"/.exec(String(html || '')) || [])[1] || '');
        if (!kind) return false;

        // Camada pronta (debug com Ignition): já é um overlay fixo — basta pôr no body.
        if (kind === 'overlay') {
            const box = document.createElement('div');
            box.innerHTML = html;
            document.body.appendChild(box);
            return true;
        }
        if (window.MadErrorModal) {
            window.MadErrorModal.showResponse(
                { status: 500, statusText: 'Erro ao abrir a tela', url: (res && res.url) || (ctx && ctx.url) || '' },
                html,
                Object.assign({ source: 'Mad' }, ctx || {}));
            return true;
        }
        MadDialog.show({ type: 'error', title: 'Erro', message: 'A tela não pôde ser aberta. A falha foi registrada.' });
        return true;
    },

    _parsePartial(html, res = null, ctx = null) {
        if (this._errorFragment(html, res, ctx)) return;
        html = html.trim()
            .replace(/window\.opener\./g, '')
            .replace(/window\.close\(\);/g, '');

        const target = document.querySelector(this.onlineTarget);

        if (target) {
            // Remove scripts antigos antes de inserir novos
            // target.querySelectorAll('script').forEach(s => s.remove());
            target.insertAdjacentHTML('beforeend', html);
            this._reinit(target);
        }
    },

    // ── UI ops (hide/show/readonly) ──────────────────────────────────────────

    /**
     * Resolve o seletor CSS para um scope+nome do MadForm.
     * scope ∈ 'field' | 'tab' | 'row'.
     */
    /**
     * Elemento-alvo de um op (`html`/`val`/`addClass`…).
     *
     * `fieldError()` mira `[data-field-error="campo"]` e `form->set()` mira
     * `[name="campo"]`. Com `document.querySelector` o PRIMEIRO da página
     * vencia: numa listagem com filtro `cliente_id` e o formulário "Novo" em
     * drawer/modal com o mesmo campo, o erro de validação ia parar no filtro
     * da listagem e o campo do formulário ficava limpo.
     *
     * Com `scope`, procura primeiro no componente que fez a requisição —
     * wrapper + painéis que ele teleportou pro <body> — e só então na página
     * (alvo de fora do componente, ex. um contador no cabeçalho, continua
     * funcionando). Sem `scope`, é o comportamento de sempre.
     */
    _opTarget(sel, scope = null) {
        // Wrapper já trocado pelo morph (desconectado) não serve de escopo.
        if (scope && scope.querySelector && scope.isConnected !== false) {
            const roots = (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.componentRoots === 'function')
                ? MadWire.componentRoots(scope)
                : [scope];
            for (const root of roots) {
                if (root.matches && root.matches(sel)) return root;
                const hit = root.querySelector(sel);
                if (hit) return hit;
            }
        }
        return document.querySelector(sel);
    },

    /**
     * Todos os alvos de um op de lista (`mad_hide`/`mad_show`): os do
     * componente quando há `scope` e ele tem algum; senão os da página.
     * Sem isso `form->hide('btnSalvar', 'btn')` numa gaveta escondia TAMBÉM o
     * botão homônimo da listagem por trás.
     */
    _opTargets(sel, scope = null) {
        const nodes = this._scopeNodes(sel, scope);
        return nodes.length ? nodes : [...document.querySelectorAll(sel)];
    },

    /** Só os alvos de DENTRO do componente (o próprio incluído); sem escopo vivo, nenhum. */
    _scopeNodes(sel, scope = null) {
        if (!scope || !scope.querySelector || scope.isConnected === false) return [];
        const nodes = (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.componentNodes === 'function')
            ? MadWire.componentNodes(scope, sel)
            : [...scope.querySelectorAll(sel)];
        if (scope.matches && scope.matches(sel) && !nodes.includes(scope)) nodes.unshift(scope);
        return nodes;
    },

    /**
     * Ops de um `MadResponse::emit()` (o `<script>` que ele ecoa).
     *
     * Na ABERTURA de uma tela o emit sai do mount(), ANTES do HTML do
     * componente, e o script rodava na hora em que o fragmento era injetado:
     * numa gaveta/modal o conteúdo ainda estava dentro do `<template
     * x-teleport>` (inerte, invisível ao querySelector), então `hide('#btn')`,
     * `html()`, `attr()`… acertavam a página de FUNDO — ou nada.
     *
     * Aqui o script se localiza (document.currentScript), acha o componente que
     * vem logo depois dele no fragmento e espera o resto do fragmento rodar (o
     * script do wrapper põe a gaveta no body e o Alpine clona o teleport) para
     * aplicar os ops com escopo NESSE componente. Dentro de uma ação (op
     * 'script' do MadWire) não há currentScript: vale o escopo da requisição.
     */
    emitOps(ops, script = null) {
        const anchor = this._emitAnchor(script);
        if (!anchor) return this.applyOps(ops, this._opScope || null);
        return new Promise((resolve) => {
            let tries = 0;
            const run = () => {
                const live = this._liveComponent(anchor);
                // Teleport ainda não processado: tenta de novo (até ~300ms).
                if (!live && tries++ < 10) { setTimeout(run, 30); return; }
                resolve(this.applyOps(ops, live || (anchor.isConnected ? anchor : null)));
            };
            setTimeout(run, 0);
        });
    },

    /** Componente a que o `<script>` do emit pertence: o que o contém ou o que vem depois dele. */
    _emitAnchor(script) {
        if (!script || !script.isConnected) return null;
        const own = script.parentElement && script.parentElement.closest
            ? script.parentElement.closest('[mad-component]') : null;
        if (own) return own;
        for (let n = script.nextElementSibling; n; n = n.nextElementSibling) {
            if (n.matches && n.matches('[data-mad-wrapper], [mad-component]')) return n;
            const inner = n.querySelector ? n.querySelector('[data-mad-wrapper], [mad-component]') : null;
            if (inner) return inner;
        }
        return null;
    },

    /**
     * `[mad-component]` VIVO do âncora: ele mesmo, um descendente, ou o que a
     * gaveta/modal teleportou para o body (`template._x_teleport`, o clone que
     * o Alpine guarda no <template> de origem). null = teleport ainda pendente.
     */
    _liveComponent(anchor) {
        if (!anchor || !anchor.isConnected) return null;
        if (anchor.matches && anchor.matches('[mad-component]')) return anchor;
        const direct = anchor.querySelector('[mad-component]');
        if (direct) return direct;
        for (const tpl of anchor.querySelectorAll('template')) {
            const clone = tpl._x_teleport;
            if (!clone || !clone.isConnected) continue;
            if (clone.matches && clone.matches('[mad-component]')) return clone;
            const hit = clone.querySelector ? clone.querySelector('[mad-component]') : null;
            if (hit) return hit;
        }
        return null;
    },

    /**
     * Op `close_overlay` — `MadResponse::closeDrawer()` / `closeModal()`.
     *
     * Sem `name` ele quer dizer "a gaveta/modal DESTE componente". Quando a
     * ação DEVOLVE a resposta, o MadWire resolve isso sozinho (mad-livewire.js
     * usa o id do componente). Pelo `emit()` — ação que não devolve a resposta,
     * ou o mount() — o op chega aqui só com o escopo e o evento saía com nome
     * vazio: nenhuma gaveta casava e a tela ficava aberta.
     *
     * Com escopo: componente anexado à linha da grid → remove a <tr> (igual ao
     * MadWire); senão fecha a camada do tipo pedido que CONTÉM o componente
     * (`data-mad-overlay*`, as mesmas marcas que o Esc usa). Com `name`, sem
     * escopo, ou com escopo fora de qualquer camada: como antes.
     */
    _closeOverlayOp(op, scope = null) {
        const kind = op.type === 'modal' ? 'modal' : 'drawer';
        let name = op.name || '';
        if (!name && scope && scope.closest && scope.isConnected !== false) {
            const attachTr = scope.closest('tr.mad-dg-attach-row');
            if (attachTr) { attachTr.remove(); return; }
            const layer = scope.closest('[data-mad-overlay="' + kind + '"]');
            if (layer) name = layer.getAttribute('data-mad-overlay-name') || '';
        }
        window.dispatchEvent(new CustomEvent(kind === 'modal' ? 'madmodal' : 'maddrawer', {
            detail: { name: name, action: 'close' }
        }));
    },

    /**
     * Op `refresh_screen` — `MadResponse::refreshScreen(...$telas)`.
     *
     * A gaveta/modal que gravou algo pede que a tela-mãe mostre o valor novo
     * (o total do pedido, a linha do tempo embutida). Cada `[mad-component]`
     * pedido que está na página é redesenhado pelo servidor com o estado que
     * tem (`MadWire.refresh`, o mesmo do `$refresh`): relê o banco.
     *
     * Com nomes: as telas dessas classes (nome curto ou completo), onde
     * estiverem — principal, embutida num <mad-transporter>, outra camada.
     * Sem nomes: as telas POR TRÁS da camada (gaveta/modal) do componente que
     * respondeu; fora de camada não há tela de trás e nada acontece.
     * Nunca o próprio componente; de uma tela embutida dentro de outra que
     * também será redesenhada, só a de fora (redesenhar a de fora recarrega o
     * <mad-transporter> junto). Devolve os componentes recarregados.
     */
    _refreshScreens(op, scope = null) {
        if (typeof MadWire === 'undefined' || !MadWire || typeof MadWire.refresh !== 'function') return [];
        const live = scope && scope.isConnected !== false && scope.closest ? scope : null;
        const own = live ? (live.matches && live.matches('[mad-component]') ? live : live.closest('[mad-component]')) : null;
        const shortName = (cls) => String(cls || '').split('\\').pop();
        const wanted = (Array.isArray(op.screens) ? op.screens : [])
            .map((s) => String(s || '').replace(/^\\+/, '').trim()).filter(Boolean);
        const layer = own ? own.closest('[data-mad-overlay]') : null;
        if (!wanted.length && !layer) return [];

        let found = [...document.querySelectorAll('[mad-component]')].filter((el) => {
            if (el === own || el.isConnected === false) return false;
            if (!wanted.length) {
                return !layer.contains(el);
            }
            const cls = el.getAttribute('mad-component') || '';
            return wanted.some((w) => w === cls || shortName(w) === shortName(cls));
        });
        found = found.filter((el) => !found.some((other) => other !== el && other.contains(el)));
        found.forEach((el) => {
            try { MadWire.refresh(el); } catch (e) { console.error('[Mad] refresh_screen:', e); }
        });
        return found;
    },

    _madScopeSelector(scope, name) {
        const safe = CSS.escape ? CSS.escape(name) : String(name).replace(/"/g, '\\"');
        if (scope === 'tab') return `[data-mad-tab="${safe}"]`;
        if (scope === 'row') return `[data-row-id="${safe}"]`;
        if (scope === 'btn') return `[data-mad-btn="${safe}"]`;
        return `[data-mad-field="${safe}"]`;
    },

    /**
     * Aplica/remove modo read-only num campo. Lida com input/textarea, select
     * (com ou sem MAD Select) e wrappers de checkbox/radio/switch.
     */
    _madApplyReadonly(name, on) {
        const safe = CSS.escape ? CSS.escape(name) : String(name).replace(/"/g, '\\"');
        const wrap = document.querySelector(`[data-mad-field="${safe}"]`);
        document.querySelectorAll(`[name="${safe}"]`).forEach(el => {
            const tag = el.tagName.toLowerCase();
            if (tag === 'input' || tag === 'textarea') {
                el.readOnly = on;
            } else if (tag === 'select') {
                if (on) el.classList.add('mad-readonly-select');
                else    el.classList.remove('mad-readonly-select');
                if (el._madSelect) {
                    if (on) el._madSelect.lock();
                    else    el._madSelect.unlock();
                }
            }
        });
        if (wrap) {
            if (on) wrap.classList.add('mad-readonly');
            else    wrap.classList.remove('mad-readonly');
        }
    },

    /**
     * Op `focus` — `MadResponse::focus()` / `$this->form->focus()`.
     *
     * Na abertura da tela o op chega antes de o campo poder receber o cursor:
     * a cortina ainda está aparecendo, o select ainda não virou MAD Select.
     * Tenta de novo por ~2s; campo que não aparece (aba fechada, escondido)
     * fica sem foco, sem erro.
     *
     * No MAD Select o `<select>` nativo é invisível — foco nele não aparece
     * para ninguém. O combo abre e o cursor vai para a busca dele, como num
     * clique. Não pelo `onControlClick()`: o foco dele sai num `$nextTick`, e
     * com a tela abrindo outro componente libera a fila do Alpine antes de a
     * busca ficar visível (o foco se perdia). Aqui a busca entra na mesma
     * espera dos outros campos.
     */
    _madFocusField(name, scope = null) {
        let tries = 0;
        const attempt = () => {
            let el = this._madFocusTarget(name, scope);
            if (el && el._madSelect) {
                el._madSelect.openDropdown();
                el = el._madSelect.control_input;
            }
            // Select que ainda vai virar MAD Select: espera, senão o foco fica no nativo.
            const ready = el && el.getClientRects().length > 0
                && !(typeof _MAD_SEL_SELECTOR === 'string' && el.matches(_MAD_SEL_SELECTOR));
            if (!ready && tries++ < 40) { setTimeout(attempt, 50); return; }
            if (el) el.focus();
        };
        attempt();
    },

    /**
     * Controle que recebe o cursor para o campo `name`, ou null se ainda não há.
     *
     * Dentro do componente primeiro: numa cortina, o filtro homônimo da
     * listagem por trás não rouba o foco. Fora dele (ou sem escopo) vale o
     * ÚLTIMO da página — a camada de cima entra por último no DOM.
     */
    _madFocusTarget(name, scope = null) {
        const safe = CSS.escape ? CSS.escape(name) : String(name).replace(/"/g, '\\"');
        const named = `[name="${safe}"], [name="${safe}[]"]`;
        const sel = `${named}, [data-mad-field="${safe}"]`;
        const inside = this._scopeNodes(sel, scope);
        const pool = inside.length ? inside : [...document.querySelectorAll(sel)].reverse();
        // O controle que tem o `name` antes do invólucro do campo.
        pool.sort((a, b) => b.matches(named) - a.matches(named));
        for (const node of pool) {
            const el = this._madFocusable(node);
            if (el) return el;
        }
        return null;
    },

    /**
     * O nó do campo, se ele mesmo recebe cursor; senão o controle visível do
     * mesmo campo — em moeda, data e afins o `name` fica num input hidden.
     */
    _madFocusable(node) {
        const ok = (el) => !el.disabled && el.type !== 'hidden' && el.getClientRects().length > 0;
        if (node.matches('input, select, textarea') && ok(node)) return node;
        const box = node.closest('[data-mad-field], .mad-field');
        if (!box) return null;
        const pick = (q) => [...box.querySelectorAll(q)].find(ok) || null;
        return pick('input, select, textarea, [contenteditable="true"]')
            || pick('button, [tabindex]:not([tabindex="-1"])');
    },

    // ── Reinicialização após injeção de HTML ──────────────────────────────────

    _reinit(root) {
        // Reexecuta scripts no HTML injetado. SO scripts JS reais: pula
        // templates/JSON (type custom) e conteudo que claramente nao e JS
        // (comeca com '<'). Sem esse guard, inserir um script desses dispara a
        // execucao e lanca "SyntaxError: Unexpected token '<'", poluindo o
        // console (e abortando o resto do reinit).
        root.querySelectorAll('script').forEach(old => {
            const type = (old.type || '').toLowerCase();
            const isJs = type === '' || type === 'text/javascript'
                || type === 'application/javascript' || type === 'module';
            if (!isJs) return;
            if ((old.textContent || '').replace(/^\s+/, '').charAt(0) === '<') return;
            try {
                const script = document.createElement('script');
                if (old.type) script.type = old.type;
                script.textContent = old.textContent;
                old.replaceWith(script);
            } catch (e) { /* script invalido no fragmento — ignora p/ nao poluir o console */ }
        });

        // Reesconde [data-mad-loading] no fragmento injetado — innerHTML nunca
        // passa pelo morph/_setLoading da mad-livewire, entao sem isso o botao
        // mostra o label normal E o "Gerando..." ao mesmo tempo na 1a renderizacao.
        if (typeof MadWire !== 'undefined' && MadWire.initLoadingElements) MadWire.initLoadingElements(root);

        // Reinicia Lucide icons
        if (window.lucide) window.lucide.createIcons();

        // Reinicia Alpine.js na área atualizada
        if (window.Alpine) window.Alpine.initTree(root);

        // Reinicia MAD Select, mascara, force-case e demais campos com init JS
        // (sequencia unica — ver _madEnergizeFields em mad-ui.js)
        if (typeof _madEnergizeFields === 'function') _madEnergizeFields(root);

        // Aba que o usuário tinha aberta numa tela embutida redesenhada junto
        // com a de fora: o HTML dela só chega agora (ver restoreUiState).
        this._applyPendingTabs(root);
    },

    /**
     * Erro de campo dentro de <mad-tab-panel> inativa fica invisível
     * (x-show="activeTab===…" esconde via display:none) — o usuário só vê o
     * toast. Ativa a aba que contém o slot do erro. Primeira aba com erro
     * vence: um lock curto no root das tabs impede que erros seguintes (na
     * mesma resposta) fiquem trocando de aba.
     */
    _revealFieldError(el) {
        const panel = el.closest('[data-mad-tab]');
        if (!panel || panel.offsetParent !== null) return; // sem aba, ou já visível
        const tabs = panel.closest('.mad-tabs');
        if (!tabs || !window.Alpine) return;
        if (tabs._madErrTabLock) return;
        tabs._madErrTabLock = true;
        setTimeout(() => { tabs._madErrTabLock = false; }, 100);
        try {
            const ad = Alpine.$data(tabs);
            if (ad && 'activeTab' in ad) ad.activeTab = panel.getAttribute('data-mad-tab');
        } catch (e) { /* tabs sem Alpine init — ignora */ }
    },

    _onError(e) {
        if (this.debug) console.error('[Mad]', e);
        MadDialog.show({ type: 'error', title: 'Erro', message: 'Requisição falhou. Verifique a conexão.' });
    },

    // ── Transporter — energize (refresh por nome) ────────────────────────────

    energize(name, params) {
        window.dispatchEvent(new CustomEvent('mad:energize', {
            detail: { name, params: params || {} }
        }));
    },

    /**
     * Liga um <mad-transporter> (x-init do transporter.blade.php).
     *
     *   o.load()       busca a tela embutida (Mad.get no alvo do transporter);
     *   o.energize(p)  busca de novo com params extras (evento mad:energize);
     *   o.name         nome do energize;
     *   o.lazy         só busca quando o transporter fica VISÍVEL. Numa aba
     *                  fechada (x-show → display:none) ele não tem caixa; o
     *                  ResizeObserver avisa quando ganha uma (a aba abriu).
     *                  Sem ResizeObserver, carrega na hora, como sem lazy.
     *
     * O energize carrega mesmo escondido: quem pediu quer o dado novo.
     */
    transporter(el, o) {
        if (!el || !o || typeof o.load !== 'function') return;
        let done = false;
        const stopWatching = () => {
            if (!el._madLazyObserver) return;
            try { el._madLazyObserver.disconnect(); } catch (e) { /* já solto */ }
            el._madLazyObserver = null;
        };
        const load = () => {
            if (done) return;
            done = true;
            stopWatching();
            o.load();
        };
        if (o.name && !el._madEnergizeBound) {
            el._madEnergizeBound = true;
            const onEnergize = (e) => {
                // Transporter que saiu da página (tela redesenhada): solta o ouvinte.
                if (el.isConnected === false) { window.removeEventListener('mad:energize', onEnergize); return; }
                if (!e.detail || e.detail.name !== o.name) return;
                done = true;
                stopWatching();
                if (typeof o.energize === 'function') o.energize(e.detail.params || {});
                else o.load();
            };
            window.addEventListener('mad:energize', onEnergize);
        }
        if (!o.lazy || typeof ResizeObserver !== 'function') { load(); return; }
        const ro = new ResizeObserver(() => {
            if (el.isConnected !== false && el.getClientRects().length > 0) load();
        });
        el._madLazyObserver = ro;
        ro.observe(el);
    },

    /**
     * Modo de cabeçalho a pedir ao servidor quando o alvo de um Mad.get mora
     * num <mad-transporter> ('' = fora de transporter). Vai no header
     * X-Mad-Embed — ver MadTransporter::embedHeader().
     */
    _embedMode(target) {
        let el = null;
        try { el = typeof target === 'string' ? document.querySelector(target) : target; } catch (e) { el = null; }
        const tp = el && el.closest ? el.closest('.mad-transporter') : null;
        return tp ? (tp.getAttribute('data-mad-embed-header') || 'compact') : '';
    },

    // ── Estado de UI no redesenho (aba ativa, rolagem) ───────────────────────

    /**
     * Redesenhar uma tela troca o HTML inteiro — `MadWire.refresh`/`$refresh`,
     * `refreshScreen()`, ação que devolve a tela, `energize` de transporter.
     * O Alpine renasce e cada <mad-tabs> voltava para a aba `default`: salvar
     * um item na gaveta aberta pela aba "Produtos" devolvia a tela na primeira
     * aba. A rolagem também pulava enquanto as telas embutidas recarregavam.
     *
     * captureUiState(raiz) ANTES da troca; restoreUiState(novaRaiz, snap)
     * DEPOIS do Alpine.initTree. A aba é identificada pela tela dona
     * (`[mad-component]`) + os nomes das abas (+ a posição, se repetido). A de
     * uma tela embutida, que ainda vai ser buscada, fica pendente: o _reinit
     * aplica quando o HTML dela chega. Pendência vence em 15s.
     */
    _uiPendingTabs: [],

    captureUiState(root) {
        const snap = { tabs: [], scroll: [], height: 0 };
        if (!root || !root.querySelectorAll) return snap;
        this._tabsBlocks(root).forEach((t) => {
            const active = this._tabsActive(t);
            if (active) snap.tabs.push({ key: this._tabsKey(t), active, def: t.getAttribute('data-mad-tabs-default') });
        });
        let p = root.parentElement;
        while (p && p !== document.body && p !== document.documentElement) {
            if (p.scrollTop || p.scrollLeft) snap.scroll.push({ el: p, top: p.scrollTop, left: p.scrollLeft });
            p = p.parentElement;
        }
        const se = document.scrollingElement;
        if (se && (se.scrollTop || se.scrollLeft)) snap.scroll.push({ el: se, top: se.scrollTop, left: se.scrollLeft });
        snap.height = root.offsetHeight || 0;
        return snap;
    },

    restoreUiState(root, snap) {
        if (!root || !snap) return;
        const now = Date.now();
        const fresh = (snap.tabs || []).map((s) => ({ key: s.key, active: s.active, def: s.def, exp: now + 15000 }));
        this._uiPendingTabs = (this._uiPendingTabs || [])
            .filter((p) => p.exp > now && !fresh.some((f) => f.key === p.key))
            .concat(fresh);
        this._applyPendingTabs(root);

        if (!snap.scroll || !snap.scroll.length) return;
        // As telas embutidas voltam como "Carregando..." (mais baixas que o
        // conteúdo): segura a altura antiga até elas chegarem, senão a página
        // encolhe e a rolagem restaurada é cortada. Solta em até 5s.
        if (snap.height && root.style && this._hasVisibleLoading(root)) {
            const prev = root.style.minHeight;
            root.style.minHeight = snap.height + 'px';
            const t0 = Date.now();
            const release = () => {
                if (root.isConnected === false || !this._hasVisibleLoading(root) || Date.now() - t0 > 5000) {
                    root.style.minHeight = prev;
                    return;
                }
                setTimeout(release, 120);
            };
            setTimeout(release, 120);
        }
        snap.scroll.forEach((s) => {
            try {
                if (s.el.isConnected === false) return;
                s.el.scrollTop = s.top;
                s.el.scrollLeft = s.left;
            } catch (e) { /* elemento sem rolagem — ignora */ }
        });
    },

    _hasVisibleLoading(root) {
        const list = root.querySelectorAll ? root.querySelectorAll('.mad-transporter-loading') : [];
        for (const el of list) if (el.getClientRects && el.getClientRects().length > 0) return true;
        return false;
    },

    _applyPendingTabs(root) {
        if (!root || !root.querySelectorAll || !this._uiPendingTabs || !this._uiPendingTabs.length) return;
        const now = Date.now();
        this._uiPendingTabs = this._uiPendingTabs.filter((p) => p.exp > now);
        this._tabsBlocks(root).forEach((t) => {
            const key = this._tabsKey(t);
            const i = this._uiPendingTabs.findIndex((p) => p.key === key);
            if (i < 0) return;
            const [p] = this._uiPendingTabs.splice(i, 1);
            // O servidor trocou a aba inicial (`default` novo, ex.: a ação
            // manda para a próxima etapa): vale a dele, não a do usuário.
            const def = t.getAttribute('data-mad-tabs-default');
            if (p.def != null && def != null && def !== p.def) return;
            this._tabsSelect(t, p.active);
        });
    },

    /** <mad-tabs> sob `root` (inclusive), na ordem do documento. */
    _tabsBlocks(root) {
        const out = [];
        if (root.matches && root.matches('.mad-tabs')) out.push(root);
        root.querySelectorAll('.mad-tabs').forEach((t) => out.push(t));
        return out;
    },

    /** Nomes das abas DESTE <mad-tabs> (não das de um <mad-tabs> aninhado). */
    _tabNames(tabs) {
        const out = [];
        tabs.querySelectorAll('.mad-tab[data-mad-tab]').forEach((b) => {
            if (b.closest('.mad-tabs') === tabs) out.push(b.getAttribute('data-mad-tab'));
        });
        return out;
    },

    _tabsKey(tabs) {
        const comp = tabs.closest('[mad-component]');
        const cls  = comp ? (comp.getAttribute('mad-component') || '') : '';
        const sig  = this._tabNames(tabs).join(',');
        let nth = 0;
        if (comp) {
            for (const other of comp.querySelectorAll('.mad-tabs')) {
                if (other === tabs) break;
                if (other.closest('[mad-component]') === comp && this._tabNames(other).join(',') === sig) nth++;
            }
        }
        return cls + '|' + sig + '#' + nth;
    },

    _tabsActive(tabs) {
        try {
            if (window.Alpine && tabs._x_dataStack) {
                const d = window.Alpine.$data(tabs);
                if (d && typeof d.activeTab === 'string') return d.activeTab;
            }
        } catch (e) { /* sem Alpine — cai no DOM */ }
        const on = [...tabs.querySelectorAll('.mad-tab.mad-tab-active')].find((b) => b.closest('.mad-tabs') === tabs);
        return on ? (on.getAttribute('data-mad-tab') || '') : '';
    },

    _tabsSelect(tabs, name) {
        if (!name || !window.Alpine || !tabs._x_dataStack) return;
        const btn = [...tabs.querySelectorAll('.mad-tab[data-mad-tab]')]
            .find((b) => b.closest('.mad-tabs') === tabs && b.getAttribute('data-mad-tab') === name);
        // A aba sumiu, ficou oculta (permissão/hide) ou desabilitada: fica a default.
        if (!btn || btn.disabled || (btn.classList && btn.classList.contains('mad-hidden'))) return;
        try {
            const d = window.Alpine.$data(tabs);
            if (d && 'activeTab' in d && d.activeTab !== name) d.activeTab = name;
        } catch (e) { /* tabs sem Alpine init — ignora */ }
    },

    // ── Confirmação ──────────────────────────────────────────────────────────

    /**
     * Promise<boolean> de confirmação. Usa o modal MAD (madConfirm, de
     * mad-ui.js) quando disponível; cai no confirm() nativo se a página não
     * carregou o mad-ui (fragmento isolado, tela de login, etc).
     */
    confirm(message, options) {
        return (typeof window.madConfirm === 'function')
            ? window.madConfirm(message, options || {})
            : Promise.resolve(window.confirm(message));
    },

    /**
     * Gate de confirmação para elemento SEM onclick próprio (submit, reset,
     * <a href>, data-mad-click). Cancela o evento, pergunta, e re-dispara o
     * clique original se confirmado — o flag no dataset evita o loop.
     * Uso: onclick="return Mad.confirmGate(event, 'Tem certeza?')"
     */
    confirmGate(event, message, options) {
        const el = event.currentTarget || event.target;
        if (el.dataset.madConfirmed) {
            delete el.dataset.madConfirmed;
            return true;
        }
        event.preventDefault();
        event.stopPropagation();
        Mad.confirm(message, options).then(ok => {
            if (!ok) return;
            el.dataset.madConfirmed = '1';
            el.click();
        });
        return false;
    },
};

// ─── Loader (substitui $.blockUI) ────────────────────────────────────────────

const MadLoader = {
    _count: 0,
    _el: null,

    show(message) {

        this._count++;
        if (!this._el) {
            this._el = document.createElement('div');
            this._el.id = 'mad-loader-overlay';
            this._el.innerHTML = `
                <div class="mad-loader-box">
                    <div class="mad-spinner mad-spinner-sm mad-loader-spinner"></div>
                    <span>${message || Mad.waitMessage}</span>
                </div>`;
            document.body.appendChild(this._el);
        }
        this._el.classList.add('mad-loader-visible');
    },

    hide(force) {
        this._count = force ? 0 : Math.max(0, this._count - 1);
        if (this._count === 0 && this._el) {
            this._el.classList.remove('mad-loader-visible');
        }
    },
};

// ─── Sistema de diálogos (substitui bootbox) ─────────────────────────────────

const MadDialog = {
    /**
     * Stack de overlays abertos. Cada item e o proprio element DOM.
     * O ultimo do array e o que esta no topo (z-index maior, fica visivel
     * por cima dos demais).
     */
    _stack: [],
    _baseZ: 99998,

    /**
     * Cria e retorna um NOVO overlay (nao reusa). Cada show() empilha.
     * Isso permite multiplas caixas abertas simultaneamente (varios erros
     * encadeados em uma so request, por ex).
     */
    _createOverlay() {
        const z = this._baseZ + this._stack.length * 10;
        const el = document.createElement('div');
        el.className = 'mad-dialog-overlay';
        // z-index dinamico (stack) continua inline; o resto vem do mad-ui.css
        el.style.zIndex = z;
        el.innerHTML = `
            <div class="mad-dialog-box">
                <div class="mad-dialog-header">
                    <i class="mad-dialog-icon" data-lucide=""></i>
                    <strong class="mad-dialog-title"></strong>
                </div>
                <div class="mad-dialog-body"></div>
                <div class="mad-dialog-footer"></div>
            </div>`;
        document.body.appendChild(el);

        // Click fora do box fecha APENAS este overlay (nao afeta os abaixo)
        el.addEventListener('click', e => {
            if (e.target === el) this._closeOverlay(el);
        });

        this._stack.push(el);
        this._ensureEscHandler();
        return el;
    },

    /**
     * Handler ESC global (registrado so uma vez). Fecha o dialog do topo
     * em cada pressionamento.
     */
    _ensureEscHandler() {
        if (this._escHandlerBound) return;
        this._escHandlerBound = true;
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            if (this._stack.length === 0) return;
            // Fecha apenas o do topo
            this._closeOverlay(this._stack[this._stack.length - 1]);
        });
    },

    /**
     * Remove um overlay especifico do stack e do DOM.
     */
    _closeOverlay(el) {
        const idx = this._stack.indexOf(el);
        if (idx === -1) return;
        this._stack.splice(idx, 1);
        if (el.parentNode) el.parentNode.removeChild(el);
    },

    show({ type = 'info', title = '', message = '', buttons = null, icon = '', fields = null } = {}) {
        const el = this._createOverlay();

        const icons  = { info: 'info', success: 'check-circle', warning: 'alert-triangle', error: 'x-circle' };
        const colors = { info: 'var(--mad-info,#3b82f6)', success: 'var(--mad-success,#22c55e)',
                         warning: 'var(--mad-warning,#f59e0b)', error: 'var(--mad-danger,#ef4444)' };

        el.querySelector('.mad-dialog-icon').setAttribute('data-lucide', icon || icons[type] || 'info');
        el.querySelector('.mad-dialog-icon').style.color = colors[type] || colors.info;
        el.querySelector('.mad-dialog-title').textContent = title;
        el.querySelector('.mad-dialog-body').innerHTML = message;

        // Campos do diálogo (MadConfirm::field — o TInputDialog do Adianti):
        // o botão recebe os valores digitados ({nome: valor}) no callback.
        const inputs = this._renderFields(el.querySelector('.mad-dialog-body'), fields);

        // Botoes — cada click fecha APENAS este overlay (nao todos)
        const footer = el.querySelector('.mad-dialog-footer');
        footer.innerHTML = '';
        const btns = buttons || [{ label: 'OK', type: 'primary', callback: null }];
        btns.forEach(b => {
            const btn = document.createElement('button');
            btn.className = `mad-btn mad-btn-${b.type || 'secondary'}`;
            btn.textContent = b.label;
            btn.onclick = () => {
                // Botão de ação (submit) não sai com obrigatório vazio.
                if (b.submit && !this._fieldsValid(inputs)) return;
                const values = this._fieldValues(inputs);
                this._closeOverlay(el);
                if (typeof b.callback === 'function') {
                    if (inputs.length) b.callback(values); else b.callback();
                }
            };
            footer.appendChild(btn);
        });

        if (window.lucide) window.lucide.createIcons();
        if (inputs.length && typeof inputs[0].control.focus === 'function') inputs[0].control.focus();

        return el; // permite close programatico: MadDialog.close(el)
    },

    /**
     * Desenha os campos do diálogo no corpo, com DOM API (nunca innerHTML: o
     * rótulo e as opções podem vir de dado do usuário).
     * field = {name, label, type, options:[{value,label}], value, required, required_msg}
     * Devolve [{field, control, hint}] na ordem.
     */
    _renderFields(body, fields) {
        if (!Array.isArray(fields) || !fields.length || !body) return [];
        const box = document.createElement('div');
        box.className = 'mad-dialog-fields';
        box.style.cssText = 'display:flex;flex-direction:column;gap:12px;margin-top:12px';
        const inputTypes = { text: 'text', number: 'number', date: 'date', datetime: 'datetime-local',
                             time: 'time', password: 'password', hidden: 'hidden' };
        const out = [];
        fields.forEach((f, i) => {
            if (!f || !f.name) return;
            const type = f.type || 'text';
            let control;
            if (type === 'select') {
                control = document.createElement('select');
                const blank = document.createElement('option');
                blank.value = '';
                blank.textContent = '';
                control.appendChild(blank);
                (f.options || []).forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = String(o.value);
                    opt.textContent = String(o.label);
                    control.appendChild(opt);
                });
            } else if (type === 'textarea') {
                control = document.createElement('textarea');
                control.rows = 3;
            } else {
                control = document.createElement('input');
                control.type = inputTypes[type] || 'text';
                if (type === 'number') control.step = 'any';
            }
            control.className = 'mad-input';
            control.name = f.name;
            control.id = 'mad_dlg_' + f.name + '_' + i + '_' + this._stack.length;
            if (f.value !== undefined && f.value !== null) {
                control.value = type === 'datetime' ? String(f.value).replace(' ', 'T') : String(f.value);
            }
            if (type === 'hidden') {
                box.appendChild(control);
                out.push({ field: f, control, hint: null });
                return;
            }
            const wrap = document.createElement('div');
            wrap.className = 'mad-field';
            const label = document.createElement('label');
            label.className = 'mad-label';
            label.htmlFor = control.id;
            label.textContent = f.label || f.name;
            if (f.required) {
                const star = document.createElement('span');
                star.className = 'mad-required';
                star.textContent = ' *';
                label.appendChild(star);
            }
            const hint = document.createElement('p');
            hint.className = 'mad-field-hint mad-error';
            hint.style.display = 'none';
            wrap.appendChild(label);
            wrap.appendChild(control);
            wrap.appendChild(hint);
            box.appendChild(wrap);
            out.push({ field: f, control, hint });
        });
        body.appendChild(box);
        return out;
    },

    /** {nome: valor} dos campos do diálogo (datetime-local "T" → espaço). */
    _fieldValues(inputs) {
        const values = {};
        (inputs || []).forEach(({ field, control }) => {
            const v = control.value == null ? '' : String(control.value);
            values[field.name] = field.type === 'datetime' ? v.replace('T', ' ') : v;
        });
        return values;
    },

    /** Obrigatório vazio: marca o campo, mostra a mensagem e segura o diálogo. */
    _fieldsValid(inputs) {
        let ok = true;
        (inputs || []).forEach(({ field, control, hint }) => {
            const empty = String(control.value == null ? '' : control.value).trim() === '';
            const bad = !!field.required && empty;
            if (control.classList) control.classList.toggle('mad-input-error', bad);
            if (hint) {
                hint.textContent = bad ? (field.required_msg || '') : '';
                hint.style.display = bad ? '' : 'none';
            }
            if (bad && ok && typeof control.focus === 'function') control.focus();
            if (bad) ok = false;
        });
        return ok;
    },

    confirm({ title = 'Confirmar', message = '', labelYes = 'Confirmar', labelNo = 'Cancelar',
              typeYes = 'primary', onYes, onNo } = {}) {
        return this.show({
            type: 'warning', title, message,
            buttons: [
                { label: labelNo,  type: 'secondary',  callback: onNo },
                { label: labelYes, type: typeYes,       callback: onYes },
            ],
        });
    },

    /**
     * close() sem argumento: fecha o dialog do TOPO (comportamento legado).
     * close(el): fecha um overlay especifico retornado por show().
     * closeAll(): fecha todos os dialogs abertos.
     */
    close(el) {
        if (el) {
            this._closeOverlay(el);
            return;
        }
        if (this._stack.length === 0) return;
        this._closeOverlay(this._stack[this._stack.length - 1]);
    },

    closeAll() {
        while (this._stack.length > 0) {
            this._closeOverlay(this._stack[this._stack.length - 1]);
        }
    },
};

// ── Diálogos MAD (substitui bootbox) ─────────────────────────────────────────

function __mad_error(title, message, callback) {
    MadDialog.show({ type: 'error', title, message,
        buttons: [{ label: 'OK', type: 'primary', callback }] });
}

function __mad_warning(title, message, callback) {
    MadDialog.show({ type: 'warning', title, message,
        buttons: [{ label: 'OK', type: 'primary', callback }] });
}

function __mad_question(title, message, cb_yes, cb_no, label_yes, label_no) {
    MadDialog.confirm({
        title, message,
        labelYes: label_yes || 'Sim',
        labelNo:  label_no  || 'Não',
        onYes: cb_yes,
        onNo:  cb_no,
    });
}


function __mad_dialog(options) {
    MadDialog.show({
        type:    options.type || 'info',
        title:   options.title || '',
        message: options.message || '',
        buttons: [{ label: 'OK', type: 'primary', callback: options.callback }],
    });
}

// ── Toast (usa madToast se disponível, senão alerta) ─────────────────────────


// ── Loader ────────────────────────────────────────────────────────────────────

function __mad_block_ui(message)  { MadLoader.show(message); }
function __mad_unblock_ui(force)  { MadLoader.hide(force === true); }

// ── Utilitários ───────────────────────────────────────────────────────────────



// ── Objetos globais legados (compat) ─────────────────────────────────────────
// Declarados com var ou guard para evitar conflito com o bundle legado

// ── Esc fecha a gaveta / modal do TOPO ───────────────────────────────────────
// <mad-drawer> e <mad-modal> (e a tela aberta com $wrapper DRAWER/MODAL, que
// usa os dois) só fechavam pelo X ou pelo clique fora — Esc não fazia nada. O
// overlay traz `data-mad-overlay` + nome + se é dispensável; aqui o Esc fecha
// SÓ o de cima, disparando o mesmo evento do X.
//
// Capture no window: decide com o estado de ANTES de qualquer outro handler
// (o MadDialog e o madConfirm se removem no próprio Esc — depois deles não
// daria mais para saber que havia um diálogo por cima). Quem está aberto por
// cima da gaveta fica com o Esc: diálogo, confirmação, dropdown de combo,
// calendário/seletor aberto dentro da gaveta, edição de célula.
const MadOverlayEsc = {
    /** Camadas que, abertas, são donas do Esc (ficam acima da gaveta). */
    BLOCKERS: '.mad-dialog-overlay, .mad-confirm-overlay, .mad-confirm-popover, .mad-quick-popover, '
            + '.mad-lightbox-overlay, .mad-df-delete-popover, #mad-dump-modal-root, .mad-sel-dropdown',

    _visible(el) {
        if (!el || !el.isConnected) return false;
        const cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (cs && (cs.display === 'none' || cs.visibility === 'hidden')) return false;
        return !el.getClientRects || el.getClientRects().length > 0;
    },

    _z(el) {
        const cs = window.getComputedStyle ? window.getComputedStyle(el) : null;
        const z = parseInt(cs ? cs.zIndex : (el.style && el.style.zIndex), 10);
        return isNaN(z) ? 0 : z;
    },

    /** Overlay visível mais alto (z-index; empate → o que veio depois no DOM). */
    top(doc) {
        let best = null, bestZ = -Infinity;
        (doc || document).querySelectorAll('[data-mad-overlay]').forEach((el) => {
            if (!this._visible(el)) return;
            const z = this._z(el);
            if (z >= bestZ) { best = el; bestZ = z; }
        });
        return best;
    },

    /**
     * Algum popup aberto DENTRO da camada (calendário, seletor de ícone,
     * menu de exportação…): o Esc é dele. Olha só o dado PRÓPRIO de cada
     * x-data (_x_dataStack[0]) — o merge do Alpine traria o `open` da própria
     * gaveta pelo escopo do teleport.
     */
    _popupOpen(root) {
        const nodes = root.querySelectorAll ? root.querySelectorAll('[x-data]') : [];
        for (const el of nodes) {
            const own = el._x_dataStack && el._x_dataStack[0];
            if (!own) continue;
            for (const k of Object.keys(own)) {
                if (own[k] === true && /^open|Open$/.test(k)) return true;
            }
        }
        return false;
    },

    /** Camada que o Esc deve fechar agora, ou null. */
    target(e, doc) {
        doc = doc || document;
        const t = e && e.target;
        // Campo com Esc próprio (edição de célula do grid, opt-in explícito).
        if (t && t.closest && t.closest('.mad-dg-cell-input, [data-mad-esc-local]')) return null;
        for (const b of doc.querySelectorAll(this.BLOCKERS)) {
            if (this._visible(b)) return null;
        }
        const top = this.top(doc);
        if (!top || top.getAttribute('data-mad-dismissible') === '0') return null;
        if (this._popupOpen(top)) return null;
        return top;
    },

    close(el) {
        const kind = el.getAttribute('data-mad-overlay') === 'modal' ? 'madmodal' : 'maddrawer';
        const name = el.getAttribute('data-mad-overlay-name') || '';
        window.dispatchEvent(new CustomEvent(kind, { detail: { name, action: 'close' } }));
    },

    onKeydown(e) {
        if (e.key !== 'Escape' || e.isComposing || e.defaultPrevented) return;
        const el = MadOverlayEsc.target(e);
        if (!el) return;
        e.preventDefault();
        MadOverlayEsc.close(el);
    },

    bind() {
        if (window.__madOverlayEscBound) return;
        window.__madOverlayEscBound = true;
        window.addEventListener('keydown', MadOverlayEsc.onKeydown, true);
    },
};
MadOverlayEsc.bind();
// ── fim Esc ──

// ── Clique fora fecha a gaveta / modal (só onde é permitido) ─────────────────
// O overlay marca `data-mad-close-on-backdrop="1"` quando o clique na área
// escurecida pode fechar — `<mad-drawer>`/`<mad-modal>` avulsos, por padrão. A
// tela aberta com $wrapper DRAWER/MODAL e o formulário do <mad-detail-form>
// marcam "0": o clique fora fechava o formulário e perdia o que foi digitado.
//
// Fecha só se o botão DESCEU e SUBIU na própria área escura. O `@click.self`
// de antes não distinguia: quem arrasta a seleção do texto de um campo e solta
// fora gera o click no ancestral comum — o overlay — e a gaveta fechava. Com
// um diálogo, confirmação ou dropdown aberto no mousedown, o clique é dele (a
// gaveta fica). Capture no window: decide antes do handler que fecha o
// dropdown no mousedown do document.
const MadOverlayBackdrop = {
    _down: null,   // overlay em que o botão desceu (e podia fechar)
    _up:   null,   // overlay em que o botão subiu, se for o mesmo

    /** O próprio overlay (a área escura) que aceita fechar no clique fora, ou null. */
    _layer(t) {
        if (!t || !t.getAttribute || t.getAttribute('data-mad-overlay') === null) return null;
        return t.getAttribute('data-mad-close-on-backdrop') === '1'
            && t.getAttribute('data-mad-dismissible') !== '0' ? t : null;
    },

    _blocked(doc) {
        for (const b of (doc || document).querySelectorAll(MadOverlayEsc.BLOCKERS)) {
            if (MadOverlayEsc._visible(b)) return true;
        }
        return false;
    },

    onDown(e) {
        const el = (e.button === undefined || e.button === 0) ? MadOverlayBackdrop._layer(e.target) : null;
        MadOverlayBackdrop._down = el && !MadOverlayBackdrop._blocked() ? el : null;
        MadOverlayBackdrop._up = null;
    },

    onUp(e) {
        const down = MadOverlayBackdrop._down;
        MadOverlayBackdrop._up = down && e.target === down ? down : null;
    },

    onClick(e) {
        const el = MadOverlayBackdrop._down;
        const close = !!el && MadOverlayBackdrop._up === el && e.target === el;
        MadOverlayBackdrop._down = MadOverlayBackdrop._up = null;
        if (close) MadOverlayEsc.close(el);
    },

    bind() {
        if (window.__madOverlayBackdropBound) return;
        window.__madOverlayBackdropBound = true;
        window.addEventListener('mousedown', MadOverlayBackdrop.onDown, true);
        window.addEventListener('mouseup', MadOverlayBackdrop.onUp, true);
        window.addEventListener('click', MadOverlayBackdrop.onClick, true);
    },
};
MadOverlayBackdrop.bind();
// ── fim clique fora ──

// ── Expor Mad em window para acesso de scripts em outros escopos ──────────────
// `const Mad` cria binding global, mas NAO propriedade em window. Scripts que
// usam `window.Mad` (tipo o override de __mad_load_page em <mad-tab-bar>)
// precisam dessa atribuicao explicita.
window.Mad = Mad;
window.MadLoader = MadLoader;

// ── number_format global (ex-builder.js, legado) ──────────────────────────
// Usado por formatters de chart (EChart emite chamadas number_format(...) no
// client). Realocado pra cá pra permitir remover o builder.js legado (jQuery).
window.number_format = function (number, decimals, decPoint, thousandsSep) {
    number = (number + '').replace(/[^0-9+\-Ee.]/g, '');
    var n = !isFinite(+number) ? 0 : +number;
    var prec = !isFinite(+decimals) ? 0 : Math.abs(decimals);
    var sep = (typeof thousandsSep === 'undefined') ? ',' : thousandsSep;
    var dec = (typeof decPoint === 'undefined') ? '.' : decPoint;
    var s = '';
    var toFixedFix = function (n, prec) {
        var k = Math.pow(10, prec);
        return '' + (Math.round(n * k) / k).toFixed(prec);
    };
    s = (prec ? toFixedFix(n, prec) : '' + Math.round(n)).split('.');
    if (s[0].length > 3) {
        s[0] = s[0].replace(/\B(?=(?:\d{3})+(?!\d))/g, sep);
    }
    if ((s[1] || '').length < prec) {
        s[1] = s[1] || '';
        s[1] += new Array(prec - s[1].length + 1).join('0');
    }
    return s.join(dec);
};

// ── Clipboard (copia robusta) ────────────────────────────────────────────────
// navigator.clipboard so existe em CONTEXTO SEGURO (https ou localhost). Em
// http://host (LAN, IP, .local) ele e undefined e `navigator.clipboard.writeText`
// lanca "Cannot read properties of undefined". madCopy() tenta a Clipboard API
// quando disponivel e cai no fallback legado (textarea + execCommand) caso
// contrario. Retorna Promise<boolean> (true = copiou).
window.madCopy = function (text) {
    text = String(text == null ? '' : text);

    var legacy = function () {
        try {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.setAttribute('readonly', '');
            ta.style.position = 'fixed';
            ta.style.top = '-9999px';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            ta.setSelectionRange(0, ta.value.length);
            var ok = document.execCommand('copy');
            document.body.removeChild(ta);
            return !!ok;
        } catch (e) {
            return false;
        }
    };

    if (window.isSecureContext && navigator.clipboard && navigator.clipboard.writeText) {
        return navigator.clipboard.writeText(text)
            .then(function () { return true; })
            .catch(function () { return legacy(); });
    }
    return Promise.resolve(legacy());
};

// ── Navegação via popstate (browser back/forward) ─────────────────────────────

window.addEventListener('popstate', (e) => {
    if (e.state?.url) Mad.load(e.state.url, null, { history: true });
});

// ── Event listeners globais ───────────────────────────────────────────────────


// Field binding — escuta change, blur e input em elementos com data-mad-action
['change', 'blur', 'input'].forEach(ev => {
    document.addEventListener(ev, Mad._handleFieldAction.bind(Mad), true);
});

// ── Roteamento de links de menu (generator="mad") ─────────────────────────────
// Substitui o interceptor do runtime legado ([generator legado]). Os links
// de menu emitidos por BuilderMenuThemeBuilder usam generator="mad" e sao
// roteados via Mad.load (AJAX → MadTabs/_injectFull), SEM full reload.
//
// Tambem blinda os botoes "switcher" de modulo (menu-target / top-menu-target),
// que usam href="#": o default do browser e cancelado para nao recarregar a
// pagina (a troca de submenu fica a cargo do MadTemplate.js do tema).
(function () {
    var _isAuthUrl = function (url) {
        // Formato legado (?class=Login...) E rota amigavel (/app/login/onLogout).
        // O menu lateral emite href amigavel (rewriteFriendlyHrefs), sem class=,
        // entao a navegacao AJAX injetava o login no #mad_content em vez de sair
        // de fato. Logout/troca-de-unidade PRECISAM de navegacao real.
        return /(^|[?&])class=(Login|Logout|SystemLogin|SystemLogout|SystemChangeUnit|LoginFormMobile|TokenLoginForm|Reset)/.test(url)
            || /\/app\/login(\/|\?|$)/i.test(url)
            || /\/onLogout(\/|\?|$)/i.test(url);
    };

    document.addEventListener('click', function (e) {
        var a = e.target.closest && e.target.closest('a');
        if (!a) return;

        // 1) Link de menu MAD → navegacao AJAX
        if (a.getAttribute('generator') === 'mad') {
            e.preventDefault();
            var href = a.getAttribute('href') || '';
            if (!href || href === '#') return;
            // URLs de auth (logout/troca de unidade) precisam de navegacao real
            if (_isAuthUrl(href)) { window.location.href = href; return; }

            var M = window.Mad;
            if (!M) { window.location.href = href; return; }

            // Rotas amigaveis (/app/grupos) vao via Mad.navigate (fetch
            // X-Mad-Partial + inject). URLs em formato de query (?class=)
            // continuam via Mad.load (MadTabs + reescrita pelo mad-web-routing).
            var isDispatcher = /[?&]class=/.test(href)
                || href.indexOf('index.php') >= 0;

            // Link de MENU (rail, submenu, menu superior, mega-menu) → aba nova
            // quando as abas estao ligadas. Fora do menu, generator="mad" segue
            // navegando na tela/aba corrente.
            var fromMenu = !!a.closest('.container-submenu, .container-menu, .notch-topmenu, .notch-megamenu, .builder-menu, [data-mad-tab]')
                || /[?&]mad_open_tab=1(&|$)/.test(href);   // dica legada emitida pelo menu builder
            if (!isDispatcher && typeof M.navigate === 'function') {
                M.navigate(href, null, { tab: fromMenu });
            } else if (typeof M.load === 'function') {
                M.load(href);
            } else {
                window.location.href = href;
            }
            return;
        }

        // 2) Botao switcher de modulo (href="#") → nunca navega
        if (a.hasAttribute('menu-target') || a.hasAttribute('top-menu-target')) {
            var h = a.getAttribute('href');
            if (!h || h === '#') e.preventDefault();
        }
    });
})();
