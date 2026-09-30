@php
    // <mad-tab-bar appName="..." enabled="0|1">
    // Browser-like internal tabs for INTERNAL pages.
    //
    // Quando `enabled=1`, intercepta Mad.go() / Mad.load() e abre cada tela
    // como uma aba interna. Identidade por classe (mesma classe = mesma tab),
    // persistência em localStorage (chaves __mad_tabs_<app> / __mad_tabs_active_<app>),
    // panes nunca destruídos (toggle display) — preserva form state.
    //
    // Label resolution (em ordem):
    //   1. argumento `label` explícito do caller
    //   2. params.mad_tab_name (legacy menu-builder compat)
    //   3. extraída do HTML retornado (.mad-page-title, [data-mad-tab-label], h1)
    //   4. humanização da classe (PedidoVendaList → Pedido Venda List)

    $appName  = $appName  ?? 'app';
    $enabled  = (string)($enabled  ?? '0') === '1';
    // store=0: abas não sobrevivem ao F5 (config store_tabs / MAD_STORE_TABS).
    $store    = (string)($store ?? '1') !== '0';
    $maxTabs  = (int)($maxTabs ?? 20);
    $contentId = $contentId ?? 'mad_main';
@endphp

<div class="mad-tab-bar"
     data-app="{{ $appName }}"
     data-enabled="{{ $enabled ? '1' : '0' }}"
     x-data="madTabBar({ app: '{{ $appName }}', enabled: {{ $enabled ? 'true' : 'false' }}, store: {{ $store ? 'true' : 'false' }}, maxTabs: {{ $maxTabs }} })"
     x-init="init()">

    {{-- Tab strip (visivel quando ha tabs e enabled) --}}
    <div class="mad-tab-strip" x-show="enabled && tabs.length > 0" x-cloak>
        <button type="button" class="mad-tab-scroll-btn mad-tab-scroll-left"
                @click="scrollStrip(-1)" title="Scroll left">
            <i data-lucide="chevron-left"></i>
        </button>
        <div class="mad-tab-strip-inner" x-ref="strip">
            <template x-for="t in tabs" :key="t.id">
                <div role="button"
                     tabindex="0"
                     class="mad-tab-item"
                     :class="{ 'mad-tab-item-active': t.id === activeId }"
                     @click="activate(t.id)"
                     @keydown.enter.prevent="activate(t.id)"
                     @keydown.space.prevent="activate(t.id)"
                     :title="t.label">
                    <span class="mad-tab-item-label" x-text="t.label"></span>
                    <span class="mad-tab-item-close"
                          role="button"
                          tabindex="0"
                          @click.stop="close(t.id)"
                          @keydown.enter.stop.prevent="close(t.id)"
                          title="Fechar"
                          aria-label="Fechar aba">
                        <i data-lucide="x"></i>
                    </span>
                </div>
            </template>
        </div>
        <button type="button" class="mad-tab-scroll-btn mad-tab-scroll-right"
                @click="scrollStrip(1)" title="Scroll right">
            <i data-lucide="chevron-right"></i>
        </button>
    </div>

    {{-- Container de panes: tambem serve como #mad_main quando enabled=0 (passthrough) --}}
    {{-- Classe mad-tab-panes so quando enabled=1 — evita aplicar estilos de card no passthrough --}}
    <div class="{{ $enabled ? 'mad-tab-panes' : '' }}" id="{{ $contentId }}" x-ref="panes"></div>
</div>

<script>
if (typeof window.madTabBar === 'function') {
    /* ja registrado por uma instancia anterior — no-op */
} else {
/**
 * Alpine factory `madTabBar` — controla o estado das tabs internas.
 *
 * Estado:
 *   - tabs[]: [{ id, cls, method, params, label, labelExplicit, dirty }]
 *   - activeId: string|null
 *
 * API exposta em window.MadTabs (singleton — primeira instancia ganha):
 *   - enabled (bool)
 *   - open(cls, method, params, label, html?)
 *   - close(id)
 *   - activate(id)
 *   - reload(id)
 *   - purgeInactive()
 */
window.madTabBar = function (opts) {
    opts = opts || {};
    return {
        app: opts.app || 'app',
        enabled: !!opts.enabled,
        store: opts.store !== false,
        maxTabs: opts.maxTabs || 20,
        tabs: [],
        activeId: null,

        // v2: passou a persistir a rota amigavel (tab.url) por aba no modo web.
        // O sufixo invalida abas antigas (sem url), que reconstruiriam a URL antiga
        // ?class= e dariam 404 no modo ESTRITO apos o deploy.
        get storageKey()  { return '__mad_tabs_v2_' + this.app; },
        get activeKey()   { return '__mad_tabs_active_v2_' + this.app; },

        init() {
            // Alpine pode re-inicializar a arvore (initTree num ancestral apos
            // injecao AJAX) — sem a trava o restore() rodava duas vezes e cada
            // aba aparecia em dobro na strip.
            if (this._inited) return;
            this._inited = true;
            // Expoe API global (so o primeiro mad-tab-bar do DOM ganha o singleton)
            const self = this;
            if (!window.MadTabs || !window.MadTabs._bound) {
                window.MadTabs = {
                    _bound: true,
                    get enabled()        { return self.enabled; },
                    // open() aceita objeto {url, cls, method, params, label, html}
                    // (modo web) ou a forma posicional legada (cls, method, params, label, html, url).
                    open: (...a)         => self.open(...a),
                    navigate: (p)        => self.navigate(p),
                    close: (id)          => self.close(id),
                    activate: (id)       => self.activate(id),
                    reload: (id)         => self.reload(id),
                    purgeInactive: ()    => self.purgeInactive(),
                    activePane: ()       => self.activePane(),
                    listTabs: ()         => self.tabs.map(t => ({ id: t.id, cls: t.cls, url: t.url, label: t.label })),
                    activeTab: ()        => self.tabs.find(t => t.id === self.activeId) || null,
                };
            }
            if (this.enabled && this.store) {
                this.restore();
            }
        },

        // ─── Normalizacao dos argumentos (objeto ou posicional legado) ───
        _args(cls, method, params, label, html, url) {
            if (cls && typeof cls === 'object') {
                const o = cls;
                return {
                    cls: o.cls || '', method: o.method || 'show', params: o.params || {},
                    label: o.label || null, html: o.html || null, url: o.url || null,
                };
            }
            return { cls: cls || '', method: method || 'show', params: params || {}, label: label || null, html: html || null, url: url || null };
        },

        // Identidade da aba: a ROTA AMIGAVEL de abertura (/app/usuarios) — e o
        // que o item de menu representa. Sem URL (fluxo legado ?class=), a classe.
        _tabKey(url, cls) {
            const path = this._pathOf(url);
            if (path) return path;
            return cls || '';
        },

        _pathOf(url) {
            if (!url || typeof url !== 'string') return '';
            let path = url;
            try { path = new URL(url, window.location.href).pathname; } catch (e) { path = url.split('?')[0].split('#')[0]; }
            const base = ((window.MadShell && window.MadShell.appBase) || '/app').replace(/\/+$/, '');
            if (base && path.indexOf(base + '/') === 0) path = path.slice(base.length);
            try { path = decodeURIComponent(path); } catch (e) {}
            return path.replace(/\/+$/, '') || '/';
        },

        // ─── Abertura de tab ─────────────────────────────────────────────
        async open(cls, method, params, label, html, url) {
            const a = this._args(cls, method, params, label, html, url);
            cls = a.cls; method = a.method; params = a.params; label = a.label; html = a.html; url = a.url;
            if (!cls && !url) return;

            // Classes/rotas de autenticacao NUNCA viram tab — login/logout
            // invalidam o estado da sessao, entao limpamos as tabs e injetamos
            // direto no container principal (#mad_main) preservando o fluxo
            // legacy de redirect/reload do framework.
            if (this._isAuthClass(cls) || this._isAuthUrl(url)) {
                this._clearAllTabs();
                this._renderInMainContainer(html);
                return;
            }

            const key = this._tabKey(url, cls);
            const id = 'mtab-' + key;
            // Reaproveita: mesma identidade OU uma aba que navegou por dentro ate
            // esta mesma rota (ex.: F5 em /programas restaura a aba "Grupos" que
            // estava mostrando Programas — nao abre uma segunda).
            const path = this._pathOf(url);
            const existing = this.tabs.find(t => t.id === id)
                || (path ? this.tabs.find(t => t.url && this._pathOf(t.url) === path) : null);
            if (existing) {
                // Menu clicado de novo na mesma tela: so reativa (nao recarrega).
                // Se veio HTML e o pane ainda esta vazio (restore sem fetch), aproveita.
                const pane = this.$refs.panes
                    ? this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(existing.id) + '"]')
                    : null;
                if (html && pane && pane.innerHTML.trim() === '') {
                    this._injectIntoPane(existing.id, html, url);
                    existing.dirty = false;
                    if (!existing.labelExplicit) {
                        const refined = label || this._extractLabel(html);
                        if (refined) existing.label = refined;
                    }
                }
                this.activate(existing.id);
                return;
            }

            // Reentrant guard — evita criar a mesma tab duas vezes em
            // chamadas concorrentes (race de eventos do framework).
            this._opening = this._opening || {};
            if (this._opening[key]) return;
            this._opening[key] = true;

            try {
                if (this.tabs.length >= this.maxTabs) {
                    if (typeof madToast === 'function') {
                        madToast('Limite de ' + this.maxTabs + ' abas atingido.', 'warning');
                    } else {
                        console.warn('[MadTabs] tab limit reached');
                    }
                    return;
                }

                const labelExplicit = !!label;
                const tab = {
                    id,
                    cls,
                    method,
                    params,
                    url: url || null,   // modo web: rota amigavel /app/slug pro reload do pane
                    label: label || this._resolveLabel(cls, params, html),
                    labelExplicit,
                    dirty: false,
                };
                this.tabs.push(tab);
                this.activeId = id;
                this._createPane(id);

                if (html) {
                    this._injectIntoPane(id, html, url);
                    if (!labelExplicit) {
                        const refined = this._extractLabel(html);
                        if (refined) tab.label = refined;
                    }
                } else {
                    await this._loadPane(tab);
                }
                this._hideOtherPanes();
                this._syncUrl(tab, false);
                this.persist();
                this._emit('mad:tab-activated', tab);
                this.$nextTick(() => this._lucide());
            } finally {
                delete this._opening[key];
            }
        },

        // ─── Navegacao DENTRO da aba ativa (Novo/Editar/voltar) ─────────
        // Comportamento do Beto v4: a aba e o item de menu; o que a tela abre
        // por dentro troca o conteudo da aba (URL e rotulo acompanham).
        navigate(p) {
            const a = this._args(p);
            const t = this.tabs.find(x => x.id === this.activeId);
            if (!t) return this.open(a);
            if (this._isAuthClass(a.cls) || this._isAuthUrl(a.url)) {
                this._clearAllTabs();
                this._renderInMainContainer(a.html);
                return;
            }
            if (a.url) t.url = a.url;
            if (a.cls) t.cls = a.cls;
            if (a.html) this._injectIntoPane(t.id, a.html, a.url);
            const refined = a.label || this._extractLabel(a.html);
            if (refined) { t.label = refined; t.labelExplicit = !!a.label; }
            t.dirty = false;
            this._syncUrl(t, true);
            this.persist();
            this._emit('mad:tab-activated', t);
            this.$nextTick(() => this._lucide());
        },

        activePane() {
            if (!this.activeId || !this.$refs.panes) return null;
            return this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(this.activeId) + '"]');
        },

        // URL do browser acompanha a aba (push ao navegar, replace ao trocar de aba).
        _syncUrl(tab, push) {
            if (!tab || !tab.url || !window.history) return;
            let target = tab.url;
            try {
                const u = new URL(tab.url, window.location.href);
                // Dicas legadas do menu (mad_open_tab/mad_tab_name) nao vao pra
                // barra de endereco — sao instrucao pro runtime, nao rota.
                u.searchParams.delete('mad_open_tab');
                u.searchParams.delete('mad_tab_name');
                target = u.pathname + (u.search || '');
            } catch (e) {}
            if (/register_state=false/.test(target)) return;
            const cur = window.location.pathname + window.location.search;
            if (cur === target) return;
            try {
                if (push) history.pushState({ url: target }, '', target);
                else history.replaceState({ url: target }, '', target);
            } catch (e) {}
        },

        _emit(name, tab) {
            try {
                window.dispatchEvent(new CustomEvent(name, {
                    detail: { id: tab.id, url: tab.url, cls: tab.cls, label: tab.label, pane: this.activePane() },
                }));
            } catch (e) {}
        },

        // ─── Ativar tab existente ───────────────────────────────────────
        async activate(id) {
            const t = this.tabs.find(x => x.id === id);
            if (!t) return;
            this.activeId = id;
            this._hideOtherPanes();
            // Fallback: se pane do tab esta vazio (state inconsistente apos
            // _clearAllTabs ou restore sem fetch), refetch independente de dirty.
            const pane = this.activePane();
            const isEmpty = !pane || !pane.innerHTML || pane.innerHTML.trim() === '';
            if (t.dirty || isEmpty) {
                await this._loadPane(t);
            }
            this._syncUrl(t, false);
            this.persist();
            this._emit('mad:tab-activated', t);
        },

        // ─── Fechar tab ─────────────────────────────────────────────────
        close(id) {
            const idx = this.tabs.findIndex(x => x.id === id);
            if (idx < 0) return;

            const pane = this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(id) + '"]');
            if (pane) pane.remove();

            const wasActive = this.activeId === id;
            this.tabs.splice(idx, 1);

            if (wasActive) {
                // Fallback para tab anterior; senao primeira; senao null
                const fallback = this.tabs[idx - 1] || this.tabs[0] || null;
                this.activeId = fallback ? fallback.id : null;
                if (this.activeId) this.activate(this.activeId);
            }
            this.persist();
        },

        // ─── Forcar reload de uma tab ───────────────────────────────────
        async reload(id) {
            const t = this.tabs.find(x => x.id === id);
            if (!t) return;
            t.dirty = true;
            await this._loadPane(t);
        },

        // ─── Fechar todas inativas ──────────────────────────────────────
        purgeInactive() {
            const keepId = this.activeId;
            if (!keepId) return;
            this.tabs.filter(t => t.id !== keepId).forEach(t => {
                const pane = this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(t.id) + '"]');
                if (pane) pane.remove();
            });
            this.tabs = this.tabs.filter(t => t.id === keepId);
            this.persist();
        },

        // ─── Scroll horizontal do strip ─────────────────────────────────
        scrollStrip(dir) {
            const el = this.$refs.strip;
            if (!el) return;
            el.scrollBy({ left: dir * 200, behavior: 'smooth' });
        },

        // ─── Persistencia ───────────────────────────────────────────────
        persist() {
            if (!this.store) return;
            try {
                // Dedup por id: garante que uma classe nunca aparece 2x no
                // storage mesmo se o state tiver inconsistencia momentanea.
                const seen = new Set();
                const serializable = [];
                this.tabs.forEach(t => {
                    if (!t || !t.id || seen.has(t.id)) return;
                    seen.add(t.id);
                    serializable.push({
                        id: t.id, cls: t.cls, method: t.method, url: t.url || null,
                        params: t.params, label: t.label, labelExplicit: !!t.labelExplicit,
                    });
                });
                localStorage.setItem(this.storageKey, JSON.stringify(serializable));
                if (this.activeId) localStorage.setItem(this.activeKey, this.activeId);
                else localStorage.removeItem(this.activeKey);
            } catch (e) {
                // localStorage cheio ou desabilitado — ignora silenciosamente
            }
        },

        async restore() {
            let stored = [];
            let activeId = null;
            try {
                const raw = localStorage.getItem(this.storageKey);
                if (raw) stored = JSON.parse(raw);
                activeId = localStorage.getItem(this.activeKey);
            } catch (e) {
                return;
            }
            if (!Array.isArray(stored) || !stored.length) return;

            // 1. Filtra classes auth (nunca devem ressuscitar como tab)
            // 2. Filtra entries invalidas (sem id ou sem cls)
            // 3. Dedup por id — primeiro vencedor
            const seen = new Set();
            stored = stored.filter(t => {
                if (!t || !t.id || !t.cls) return false;
                if (this._isAuthClass(t.cls)) return false;
                if (seen.has(t.id)) return false;
                seen.add(t.id);
                return true;
            });

            if (!stored.length) {
                try {
                    localStorage.removeItem(this.storageKey);
                    localStorage.removeItem(this.activeKey);
                } catch (e) {}
                return;
            }

            // Rebuild tabs sem fetch — todas dirty. Ignora o que ja existe no
            // state/DOM (boot pode ter aberto a aba da URL atual antes do restore).
            stored.forEach(t => {
                if (this.tabs.some(x => x.id === t.id)) return;
                if (this.$refs.panes && this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(t.id) + '"]')) return;
                this.tabs.push({
                    id: t.id,
                    cls: t.cls,
                    method: t.method || 'show',
                    params: t.params || {},
                    url: t.url || null,
                    label: t.label || this._humanize(t.cls),
                    labelExplicit: !!t.labelExplicit,
                    dirty: true,
                });
                this._createPane(t.id);
            });

            // Re-persiste imediatamente para descartar duplicatas/auth do storage
            this.persist();

            const toActivate = (activeId && this.tabs.find(t => t.id === activeId))
                ? activeId
                : this.tabs[0].id;
            await this.activate(toActivate);
            this.$nextTick(() => this._lucide());
        },

        // ─── Helpers de auth / fluxo nao-tabbable ───────────────────────
        _isAuthClass(cls) {
            if (!cls || typeof cls !== 'string') return false;
            // Classes nao-tabbable: login, logout, change unit, mobile auth, etc.
            return /^(Login|Logout|SystemLogin|SystemLogout|SystemChangeUnit|LoginFormMobile|TokenLoginForm|Reset)/.test(cls);
        },

        // Rotas amigaveis de auth (modo web nao carrega a classe na URL).
        _isAuthUrl(url) {
            if (!url || typeof url !== 'string') return false;
            return /\/app\/(login|cadastro|senha)(\/|\?|$)/i.test(url) || /\/onLogout(\/|\?|$)/i.test(url);
        },

        _clearAllTabs() {
            const container = this.$refs.panes;
            if (container) {
                container.querySelectorAll('.mad-tab-pane').forEach(p => p.remove());
            }
            this.tabs = [];
            this.activeId = null;
            try {
                localStorage.removeItem(this.storageKey);
                localStorage.removeItem(this.activeKey);
            } catch (e) {}
        },

        _renderInMainContainer(html) {
            if (!html) return;
            // Delega para Mad._injectFull que sabe como atualizar
            // #mad_main sem destruir a estrutura do componente.
            // Esse caminho substitui innerHTML do panes container mas como
            // ja chamamos _clearAllTabs, nenhum pane existia para perder.
            var M = (typeof Mad !== 'undefined') ? Mad
                  : (typeof window.Mad !== 'undefined') ? window.Mad : null;
            if (M && typeof M._injectFull === 'function') {
                M._injectFull(html, '');
                return;
            }
            // Fallback minimo
            const target = document.getElementById('mad_main');
            if (target) target.innerHTML = html;
        },

        // ─── Loader privado ─────────────────────────────────────────────
        async _loadPane(tab) {
            const url = this._buildUrl(tab);
            try {
                if (window.MadLoader) MadLoader.show();
                // X-Mad-Partial: pane e sempre fragmento. No modo web, rota amigavel
                // (/app/slug) sem este header faria o AppRouteResolver devolver o
                // casco inteiro.
                const res  = await fetch(url, { headers: { 'X-Mad-Partial': '1' } });
                const html = await res.text();
                if (!res.ok) {
                    // Rota morta/sem permissao: nao vira aba "404" pendurada na
                    // strip — fecha e avisa (o casco mostra o erro pelo Mad).
                    console.warn('[MadTabs] HTTP ' + res.status + ' em ' + url);
                    this.close(tab.id);
                    if (typeof madToast === 'function') madToast('Tela indisponivel (' + res.status + ').', 'warning');
                    return;
                }
                this._injectIntoPane(tab.id, html, tab.url);
                tab.dirty = false;
                // Metadado do servidor (MadAppController): classe pro fallback de
                // identidade/breadcrumb e titulo declarado (static $title).
                const hCls = res.headers && res.headers.get('X-Mad-Class');
                if (hCls && !tab.cls) tab.cls = hCls;
                if (!tab.labelExplicit) {
                    let refined = this._extractLabel(html);
                    const hTitle = res.headers && res.headers.get('X-Mad-Title');
                    if (!refined && hTitle) { try { refined = decodeURIComponent(hTitle); } catch (e) {} }
                    // Aba aberta so pela URL: a classe chega agora (header) — melhor
                    // "Message List" que o placeholder "Aba".
                    if (!refined && hCls && (!tab.label || tab.label === 'Aba')) refined = this._humanize(hCls);
                    if (refined) {
                        tab.label = refined;
                        this.persist();
                    }
                }
                this._emit('mad:tab-activated', tab);
            } catch (e) {
                console.error('[MadTabs] load failed for ' + tab.cls, e);
            } finally {
                if (window.MadLoader) MadLoader.hide();
            }
        },

        _buildUrl(tab) {
            // O pane ja foi aberto com a rota amigavel (/app/slug) assada
            // pelo PHP — reusa direto (com X-Mad-Partial via fetch shim/_loadPane).
            if (tab.url) return tab.url;
            // Fallback (aba antiga persistida sem url): forma generica
            // /app/{cls}/{method} — mesma reescrita que o MadWebRoute faria.
            // Importante: NAO adicionar static=1 (mudaria o response para
            // "partial sem layout").
            const base = (window.MadShell && window.MadShell.appBase) || '/app';
            const params = Object.assign({}, tab.params || {});
            const qs = new URLSearchParams();
            Object.keys(params).forEach(k => {
                if (params[k] !== undefined && params[k] !== null) qs.set(k, params[k]);
            });
            const method = tab.method || 'show';
            let out = base.replace(/\/+$/, '') + '/' + encodeURIComponent(tab.cls);
            if (method !== 'show') out += '/' + encodeURIComponent(method);
            const rest = qs.toString();
            return out + (rest ? '?' + rest : '');
        },

        _createPane(id) {
            const container = this.$refs.panes;
            if (!container) return null;
            // Esconde panes existentes
            container.querySelectorAll('.mad-tab-pane').forEach(p => p.style.display = 'none');
            const pane = document.createElement('div');
            pane.className = 'mad-tab-pane';
            pane.dataset.tabId = id;
            pane.style.display = '';
            container.appendChild(pane);
            return pane;
        },

        _hideOtherPanes() {
            const container = this.$refs.panes;
            if (!container) return;
            const active = this.activeId;
            container.querySelectorAll('.mad-tab-pane').forEach(p => {
                p.style.display = (p.dataset.tabId === active) ? '' : 'none';
            });
        },

        _injectIntoPane(id, html, url) {
            const pane = this.$refs.panes.querySelector('.mad-tab-pane[data-tab-id="' + this._escape(id) + '"]');
            if (!pane) return;

            // Mesma sequencia de boot do #mad_main (scripts, loading, lucide,
            // Alpine, energize) — um caminho so, senao a aba diverge da tela.
            var M = (typeof Mad !== 'undefined') ? Mad : window.Mad;
            if (M && typeof M._injectInto === 'function') {
                M._injectInto(pane, html, url || null);
                return;
            }

            pane.innerHTML = html;

            // Re-executa scripts inline (innerHTML nao executa <script>)
            pane.querySelectorAll('script').forEach(old => {
                const s = document.createElement('script');
                [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                s.textContent = old.textContent;
                old.replaceWith(s);
            });

            // Bootstrap helpers MAD — sequencia unica em _madEnergizeFields
            // (mad-ui.js). Copiar a lista aqui foi o que deixou a mascara e o
            // force-case sem init na aba: ver a nota naquela funcao.
            try { if (window.Alpine) Alpine.initTree(pane); } catch (e) {}
            this._lucide();
            this._callOpt('_madEnergizeFields', pane);
        },

        _callOpt(name, arg) {
            try { if (typeof window[name] === 'function') window[name](arg); } catch (e) {}
        },

        _lucide() {
            try {
                if (typeof window._madLucide === 'function') window._madLucide();
                else if (window.lucide && typeof window.lucide.createIcons === 'function') window.lucide.createIcons();
            } catch (e) {}
        },

        _resolveLabel(cls, params, html) {
            if (params && params.mad_tab_name && params.mad_tab_name !== '*' && params.mad_tab_name !== '') {
                return params.mad_tab_name;
            }
            if (html) {
                const refined = this._extractLabel(html);
                if (refined) return refined;
            }
            return this._humanize(cls);
        },

        _extractLabel(html) {
            if (!html) return null;
            try {
                const tmp = document.createElement('template');
                tmp.innerHTML = html;
                const root = tmp.content;
                const el = root.querySelector('[data-mad-tab-label]')
                        || root.querySelector('.mad-page-title')
                        || root.querySelector('.mad-page-header h1')
                        || root.querySelector('h1');
                if (el) {
                    const txt = (el.textContent || '').trim().replace(/\s+/g, ' ');
                    if (txt) return txt.length > 60 ? txt.substring(0, 57) + '...' : txt;
                }
            } catch (e) {}
            return null;
        },

        _humanize(cls) {
            return (cls || '').replace(/([a-z])([A-Z])/g, '$1 $2').trim() || 'Aba';
        },

        _escape(s) {
            return String(s).replace(/"/g, '\\"');
        },
    };
};

/* ──────────────────────────────────────────────────────────────────────────
 * Override de __mad_load_page (legacy) para roteamento via MadTabs.
 *
 * Menus legados usam <a generator="classic">, que sao
 * interceptados pelo runtime legado e chamam __mad_load_page(href). Esse
 * fluxo legacy NAO passa por Mad.go nem Mad.load, entao precisamos hookar
 * aqui para delegar a MadTabs quando habilitado.
 * ────────────────────────────────────────────────────────────────────────── */
(function () {
    /* Mad eh declarado com `const` no escopo global do script (mad.js), entao
     * NAO esta acessivel como window.Mad — usar referencia direta. */
    var madRef = function () {
        if (typeof Mad !== 'undefined') return Mad;
        if (typeof window.Mad !== 'undefined') return window.Mad;
        return null;
    };

    var isAuthUrl = function (url) {
        return /(^|[?&])class=(Login|Logout|SystemLogin|SystemLogout|SystemChangeUnit|LoginFormMobile|TokenLoginForm|Reset)/.test(url);
    };

    var hookLoadPage = function () {
        if (typeof window.__mad_load_page !== 'function') return false;
        if (window.__mad_load_page._madTabsHooked) return true;

        var __legacy = window.__mad_load_page;
        window.__mad_load_page = function (page, callback) {
            try {
                var M = madRef();
                if (window.MadTabs && window.MadTabs.enabled
                    && typeof page === 'string'
                    && page.indexOf('class=') >= 0
                    && !isAuthUrl(page)
                    && M && typeof M.load === 'function') {
                    return M.load(page, callback);
                }
            } catch (e) {
                console.error('[MadTabs] override __mad_load_page falhou', e);
            }
            return __legacy.apply(this, arguments);
        };
        window.__mad_load_page._madTabsHooked = true;
        return true;
    };

    /* Hook __mad_load_html removido: era redundante com __mad_load_page
     * (que ja delega via Mad.load) e gerava duplo trigger no fluxo de menu —
     * resposta do menu chamava load_html internamente, que disparava MadTabs.open
     * uma segunda vez na mesma navegacao, causando loop ate o limite de 20 tabs. */

    hookLoadPage();
    if (typeof document !== 'undefined' && document.readyState !== 'complete') {
        document.addEventListener('DOMContentLoaded', hookLoadPage);
    }
})();
}
</script>
