{{-- <mad-dashboard-tabs> — abas de dashboard com panes LAZY (X-Mad-Partial).

     Cada aba aponta para uma TELA MAD própria (MadRoutes::screen). O pane é
     buscado por fetch com o header X-Mad-Partial (fragmento, sem casco) e
     injetado com re-init (scripts, Alpine, lucide, selects). keep-alive
     preserva panes já carregados; a prop `sig` (assinatura dos filtros do
     host) invalida todos os panes quando muda — o host re-renderiza este
     componente via wire e o Alpine renasce com sig novo.

     Props:
       name        string  namespace (ids, localStorage)                [req]
       :tabs       array   [['name','label','url','icon'?,'badge'?,
                             'disabled'?,'title'?], ...]                [req]
       active      string  aba inicial resolvida no servidor
       sig         string  assinatura dos filtros (cache-buster do pane)
       model       string  ''=off; nome da prop: emite hidden
                           data-mad-model pro wire do HOST saber a aba
       url-param   string  ''=off; querystring sincronizada (?tab=...)
       history     string  push | replace | off
       prefetch    string  none | next | all
       keep-alive  bool    panes ficam no DOM (display:none)
       remember    bool    última aba no localStorage
       storage-key string  chave do localStorage da última aba (padrão
                           `__mad_dbtabs_<name>`); com ela, `remember` liga
                           sozinho (`remember="false"` desliga). Ex.: uma
                           chave por usuário.
       embed       string  ''=off; a aba vem como tela EMBUTIDA (header
                           X-Mad-Embed, como o <mad-transporter header>):
                             row  — só a barra de ações da tela (sem título,
                                    cabeçalho nem barra de filtros);
                             body — só o conteúdo (sem a barra de ações também).
                           Aceita os outros modos do transporter (compact,
                           title, full). Os redesenhos (wire) da aba repetem.
       forward-query bool|string  ''=off; a URL da aba leva a query da página
                           (os filtros da casca): nu/`true` = todos os
                           parâmetros; lista `projeto,periodo` = só esses. O
                           parâmetro do `url-param` não vai; o que a `url` da aba
                           já tem vence.
       variant     string  pills | underline

     Recarregar a aba ativa (a casca mudou os filtros), sem trocar de aba:
       window.dispatchEvent(new CustomEvent('mad-dashboard-tabs:reload',
           { detail: { name: 'atividades', query: { projeto: 3 } } }));
     `name` (opcional) escolhe o componente; `query` (opcional: objeto, string
     ou URLSearchParams) passa a ser a query repassada às abas — sem ela vale a
     da URL atual. As outras abas recarregam quando forem abertas. Do PHP:
     `$response->script("window.dispatchEvent(new CustomEvent('mad-dashboard-tabs:reload'))")`.

     Gotchas de integração:
       - pushState SEM chave `url` no state (o handler global de popstate do
         mad.js dispara Mad.load quando `e.state.url` existe). O state leva o
         caminho da casca (`madTab.path`): voltar de OUTRA tela para uma
         entrada de aba carrega a casca (Mad._dbTabsGone, fw#633).
       - após exibir um pane, dispara 'shown.bs.tab' (+60ms): o <mad-db-chart>
         escuta esse evento e chama resize() — charts inicializados em pane
         display:none ficariam com largura 0.
       - antes de reinjetar HTML num pane, chama window.__madDisposeChartsIn
         (db-chart.blade.php) pra não vazar instâncias ECharts/observers.
       - aviso ao sair (fw#632, `confirm-leave`): o voltar que troca de aba
         chega com o state sem `url` e não pergunta — continua na mesma tela;
         o voltar para a casca a partir de outra tela pergunta. --}}
@php
    $name      = $name      ?? 'dashboard';
    $tabs      = is_array($tabs ?? null) ? array_values($tabs) : [];
    $active    = (string) ($active ?? ($tabs[0]['name'] ?? ''));
    $sig       = (string) ($sig ?? '');
    $model     = (string) ($model ?? '');
    $urlParam  = (string) ($urlParam ?? '');
    $history   = (string) ($history ?? 'push');
    $history   = in_array($history, ['push', 'replace', 'off'], true) ? $history : 'push';
    $prefetch  = (string) ($prefetch ?? 'none');
    $prefetch  = in_array($prefetch, ['none', 'next', 'all'], true) ? $prefetch : 'none';
    $keepAlive = filter_var($keepAlive ?? true,  FILTER_VALIDATE_BOOL);
    // fw#633 — chave do lembrete, aba embutida e query repassada. Sem as três
    // props, a config e o HTML são os de antes.
    $storageKey = trim((string) ($storageKey ?? ''));
    $remember  = filter_var($remember  ?? ($storageKey !== ''), FILTER_VALIDATE_BOOL);
    $embed     = strtolower(trim((string) ($embed ?? '')));
    if (!in_array($embed, \Mad\Ui\MadTransporter::EMBED_MODES, true)) {
        $embed = '';
    }
    $forwardQuery = $forwardQuery ?? false;
    if (is_string($forwardQuery)) {
        $forwardQuery = filter_var($forwardQuery, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? array_values(array_filter(array_map('trim', explode(',', $forwardQuery)), 'strlen'));
    } elseif (is_array($forwardQuery)) {
        $forwardQuery = array_values(array_filter(array_map(fn ($v) => trim((string) $v), $forwardQuery), 'strlen'));
    } else {
        $forwardQuery = (bool) $forwardQuery;
    }
    if ($forwardQuery === []) {
        $forwardQuery = false;
    }
    $variant   = ($variant ?? 'pills') === 'underline' ? 'underline' : 'pills';
    $class     = $class ?? '';
    $style     = $style ?? '';

    $cfg = [
        'name'      => $name,
        'tabs'      => array_map(fn ($t) => [
            'name'     => (string) ($t['name'] ?? ''),
            'url'      => (string) ($t['url'] ?? ''),
            'disabled' => (bool) ($t['disabled'] ?? false),
        ], $tabs),
        'active'    => $active,
        'sig'       => $sig,
        'model'     => $model,
        'urlParam'  => $urlParam,
        'history'   => $history,
        'prefetch'  => $prefetch,
        'keepAlive' => $keepAlive,
        'remember'  => $remember,
    ];
    if ($storageKey !== '') {
        $cfg['storageKey'] = $storageKey;
    }
    if ($embed !== '') {
        $cfg['embed'] = $embed;
    }
    if ($forwardQuery !== false) {
        $cfg['forwardQuery'] = $forwardQuery;
    }
    // Pane de aba embutida = `.mad-transporter` com o modo (como o corpo do
    // Mad.rowAttach): o mad-livewire.js repete o X-Mad-Embed nos redesenhos.
    // Texto para DENTRO do <script> (o @js do MAD escapa para atributo:
    // `&quot;` num <script> é erro de sintaxe e o componente não nascia).
    $_jsText = static fn (string $key): string => json_encode(
        mad_t($key),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
    );
    $_paneClass = $embed !== '' ? ' mad-transporter' : '';
    $_paneEmbed = $embed !== '' ? ' data-mad-embed-header="' . e($embed) . '"' : '';
@endphp
<div class="mad-dbtabs mad-dbtabs-{{ $variant }} {{ $class }}" @if($style) style="{{ $style }}" @endif
     x-data="madDashboardTabs(@js($cfg))" x-init="init()">

    <div class="mad-dbtabs-strip" role="tablist" aria-label="{{ $name }}">
        @foreach($tabs as $t)
            @php
                $tName     = (string) ($t['name'] ?? '');
                $tDisabled = (bool) ($t['disabled'] ?? false);
            @endphp
            <button type="button" class="mad-dbtabs-tab"
                    role="tab"
                    id="{{ $name }}-tab-{{ $tName }}"
                    aria-controls="{{ $name }}-pane-{{ $tName }}"
                    :aria-selected="active === @js($tName) ? 'true' : 'false'"
                    :class="active === @js($tName) ? 'is-active' : ''"
                    :tabindex="active === @js($tName) ? 0 : -1"
                    @if($tDisabled) disabled @endif
                    @if(!empty($t['title'])) title="{{ $t['title'] }}" @endif
                    @click="go(@js($tName))"
                    @keydown.arrow-right.prevent="focusNext(1)"
                    @keydown.arrow-left.prevent="focusNext(-1)"
                    @keydown.home.prevent="focusEdge(0)"
                    @keydown.end.prevent="focusEdge(-1)">
                @if(!empty($t['icon']))<i data-lucide="{{ $t['icon'] }}" class="mad-dbtabs-icon"></i>@endif
                <span>{{ $t['label'] ?? $tName }}</span>
                @if(!empty($t['badge']))<span class="mad-dbtabs-badge">{{ $t['badge'] }}</span>@endif
            </button>
        @endforeach
    </div>

    @if($model !== '')
        {{-- o wire do HOST coleta este model: troca de filtro re-renderiza o
             container já sabendo a aba ativa (zero round-trip extra) --}}
        <input type="hidden" data-mad-model="{{ $model }}" x-ref="model" value="{{ $active }}">
    @endif

    <div class="mad-dbtabs-panes" x-ref="panes">
        @foreach($tabs as $t)
            @php $tName = (string) ($t['name'] ?? ''); @endphp
            <div class="mad-dbtabs-pane{{ $_paneClass }}"
                 role="tabpanel"
                 id="{{ $name }}-pane-{{ $tName }}"
                 aria-labelledby="{{ $name }}-tab-{{ $tName }}"
                 data-tab="{{ $tName }}"{!! $_paneEmbed !!}
                 style="display:none"></div>
        @endforeach
    </div>
</div>

<script>
if (typeof window.madDashboardTabs !== 'function') {
window.madDashboardTabs = function (cfg) {
    cfg = cfg || {};
    // Raiz deste componente (.mad-dbtabs), guardada no init: chamado de dentro
    // de um pane, `$el` é o botão do pane.
    let hostRoot = null;
    // Caminho da tela (casca) em que o componente nasceu: as entradas do
    // histórico levam junto, para o voltar distinguir aba desta tela de aba de
    // outra tela com o mesmo `name`.
    let pagePath = '';
    return {
        active: '',
        loaded: {},      // tab -> sig com que foi carregada
        loading: {},     // tab -> bool
        _aborts: {},     // tab -> AbortController
        _popHandler: null,
        _query: null,    // query repassada às abas (evento reload); null = a da URL
        _gen: 0,         // geração das cargas: o reload descarta resposta antiga

        get storageKey() { return cfg.storageKey || ('__mad_dbtabs_' + (cfg.name || 'dashboard')); },

        tabByName(n) { return (cfg.tabs || []).find(t => t.name === n) || null; },

        init() {
            hostRoot = this.$el;
            pagePath = location.pathname;
            let initial = cfg.active || '';
            // precedência: ?param da URL > localStorage (remember) > prop active
            if (cfg.urlParam) {
                const q = new URLSearchParams(location.search).get(cfg.urlParam);
                if (q && this.tabByName(q)) initial = q;
            }
            if (cfg.remember && (!cfg.urlParam || !new URLSearchParams(location.search).get(cfg.urlParam))) {
                try {
                    const saved = localStorage.getItem(this.storageKey);
                    if (saved && this.tabByName(saved)) initial = saved;
                } catch (e) {}
            }
            if (!initial && cfg.tabs && cfg.tabs.length) initial = cfg.tabs[0].name;

            if (cfg.history !== 'off') {
                // 1 listener por `name`: o morph do host re-instancia o
                // componente — remove o handler da instância anterior
                // (Alpine v3 não emite evento de destroy por elemento).
                window.__madDbTabsPop = window.__madDbTabsPop || {};
                if (window.__madDbTabsPop[cfg.name]) {
                    window.removeEventListener('popstate', window.__madDbTabsPop[cfg.name]);
                }
                this._popHandler = (e) => {
                    const st = e.state && e.state.madTab;
                    // Aba de OUTRA tela, ou esta saiu da página: o popstate
                    // global do mad.js carrega a casca (Mad._dbTabsGone).
                    if (hostRoot && hostRoot.isConnected === false) return;
                    if (st && st.path && st.path !== pagePath) return;
                    if (st && st.name === cfg.name && st.tab && this.tabByName(st.tab)) {
                        this.go(st.tab, { fromPop: true });
                    }
                };
                window.__madDbTabsPop[cfg.name] = this._popHandler;
                window.addEventListener('popstate', this._popHandler);
                window.__madDbTabsRoot = window.__madDbTabsRoot || {};
                window.__madDbTabsRoot[cfg.name] = { root: hostRoot, path: pagePath };
            }

            // `mad-dashboard-tabs:reload` (fw#633): a casca mudou os filtros —
            // recarrega a aba ativa com a query nova. 1 listener por `name`,
            // como o do popstate.
            window.__madDbTabsReload = window.__madDbTabsReload || {};
            if (window.__madDbTabsReload[cfg.name]) {
                window.removeEventListener('mad-dashboard-tabs:reload', window.__madDbTabsReload[cfg.name]);
            }
            const onReload = (e) => {
                const d = (e && e.detail) || {};
                if (d.name && d.name !== cfg.name) return;
                if (hostRoot && hostRoot.isConnected === false) return;
                this.refresh(d.query);
            };
            window.__madDbTabsReload[cfg.name] = onReload;
            window.addEventListener('mad-dashboard-tabs:reload', onReload);

            this.go(initial, { replace: true });
        },

        // Recarrega a aba ativa (sem trocar de aba) e marca as outras para
        // recarregar quando forem abertas. `query` (objeto, string ou
        // URLSearchParams) passa a ser a query repassada; sem ela, vale a da URL.
        refresh(query) {
            this._query = query === undefined || query === null ? null : this.normQuery(query);
            this._gen++;
            Object.keys(this._aborts).forEach(n => { try { this._aborts[n].abort(); } catch (e) {} });
            this._aborts = {};
            this.loading = {};
            this.loaded = {};
            if (this.active) this.load(this.active);
            this.schedulePrefetch();
        },

        normQuery(q) {
            if (typeof q === 'string') return q.replace(/^\?/, '');
            if (typeof URLSearchParams !== 'undefined' && q instanceof URLSearchParams) return q.toString();
            if (typeof q === 'object') {
                const p = new URLSearchParams();
                Object.keys(q).forEach(k => {
                    const v = q[k];
                    if (v === null || v === undefined) return;
                    (Array.isArray(v) ? v : [v]).forEach(x => p.append(k, String(x)));
                });
                return p.toString();
            }
            return null;
        },

        // Parâmetros da página que vão para a aba (`forward-query`).
        forwarded() {
            if (!cfg.forwardQuery) return null;
            const src = new URLSearchParams(this._query !== null ? this._query : location.search);
            const only = Array.isArray(cfg.forwardQuery) ? cfg.forwardQuery : null;
            const out = new URLSearchParams();
            src.forEach((v, k) => {
                if (k === cfg.urlParam || k === 'fsig') return;
                if (only && only.indexOf(k) < 0 && only.indexOf(k.replace(/\[\]$/, '')) < 0) return;
                out.append(k, v);
            });
            return out;
        },

        go(tab, opts) {
            opts = opts || {};
            const t = this.tabByName(tab);
            if (!t || t.disabled) return;
            this.active = tab;

            if (cfg.model && this.$refs.model) this.$refs.model.value = tab;
            if (cfg.remember) { try { localStorage.setItem(this.storageKey, tab); } catch (e) {} }

            this.syncUrl(tab, opts);
            this.showPane(tab);

            if (this.loaded[tab] !== cfg.sig) {
                this.load(tab);
            } else {
                this.afterShow(tab);
            }
            this.schedulePrefetch();
        },

        syncUrl(tab, opts) {
            if (cfg.history === 'off' || !cfg.urlParam || opts.fromPop) return;
            const url = new URL(location.href);
            url.searchParams.set(cfg.urlParam, tab);
            // state SEM chave `url`: o popstate global do mad.js só age quando
            // e.state.url existe — não queremos um Mad.load() full no Back.
            const state = { madTab: { name: cfg.name, tab: tab, path: pagePath } };
            try {
                if (opts.replace || cfg.history === 'replace') history.replaceState(state, '', url);
                else history.pushState(state, '', url);
            } catch (e) {}
        },

        showPane(tab) {
            const panes = this.$refs.panes;
            if (!panes) return;
            panes.querySelectorAll('.mad-dbtabs-pane').forEach(p => {
                const on = p.dataset.tab === tab;
                p.style.display = on ? '' : 'none';
                if (!on && !cfg.keepAlive && p.innerHTML !== '') {
                    this.disposeCharts(p);
                    p.innerHTML = '';
                    delete this.loaded[p.dataset.tab];
                }
            });
        },

        pane(tab) {
            return this.$refs.panes
                ? this.$refs.panes.querySelector('.mad-dbtabs-pane[data-tab="' + CSS.escape(tab) + '"]')
                : null;
        },

        buildUrl(tab) {
            const t = this.tabByName(tab);
            if (!t || !t.url) return null;
            const url = new URL(t.url, location.origin);
            const fwd = this.forwarded();
            if (fwd) {
                // O que a `url` da aba já traz vence o da página.
                const own = new Set(Array.from(url.searchParams.keys()));
                fwd.forEach((v, k) => { if (!own.has(k)) url.searchParams.append(k, v); });
            }
            if (cfg.sig) url.searchParams.set('fsig', cfg.sig);
            return url.toString();
        },

        async load(tab, opts) {
            opts = opts || {};
            const pane = this.pane(tab);
            const url = this.buildUrl(tab);
            if (!pane || !url || this.loading[tab]) return;

            if (this._aborts[tab]) { try { this._aborts[tab].abort(); } catch (e) {} }
            const ac = new AbortController();
            const gen = this._gen;
            this._aborts[tab] = ac;
            this.loading[tab] = true;

            if (!opts.silent) {
                this.disposeCharts(pane);
                pane.innerHTML = '<div class="mad-dbtabs-loading"><span class="mad-dbtabs-spinner"></span> ' + {!! $_jsText('mad.ui.loading') !!} + '</div>';
            }

            try {
                const headers = { 'X-Mad-Partial': '1' };
                // Aba embutida (`embed`): a tela vem sem o casco de página.
                if (cfg.embed) headers['X-Mad-Embed'] = cfg.embed;
                const res = await fetch(url, {
                    headers: headers,
                    credentials: 'same-origin',
                    signal: ac.signal,
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const html = await res.text();
                if (gen !== this._gen) return;   // reload no meio: resposta velha
                this.inject(tab, html);
                this.loaded[tab] = cfg.sig;
                if (this.active === tab) this.afterShow(tab);
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                console.error('[mad-dashboard-tabs] falha ao carregar aba ' + tab, e);
                pane.innerHTML = '<div class="mad-dbtabs-error">' + {!! $_jsText('mad.ui.tab_load_failed') !!}
                    + ' <button type="button" class="mad-btn mad-btn-sm" @click="reload(\'' + tab.replace(/'/g, '') + '\')">' + {!! $_jsText('mad.ui.try_again') !!} + '</button></div>';
                try { if (window.Alpine) Alpine.initTree(pane); } catch (e2) {}
            } finally {
                if (this._aborts[tab] === ac) {
                    this.loading[tab] = false;
                    delete this._aborts[tab];
                }
            }
        },

        reload(tab) {
            delete this.loaded[tab];
            this.load(tab);
        },

        inject(tab, html) {
            const pane = this.pane(tab);
            if (!pane) return;
            this.disposeCharts(pane);
            pane.innerHTML = html;

            // innerHTML não executa <script> — re-cria cada um
            pane.querySelectorAll('script').forEach(old => {
                const s = document.createElement('script');
                [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
                s.textContent = old.textContent;
                old.replaceWith(s);
            });

            try { if (window.Alpine) Alpine.initTree(pane); } catch (e) {}
            try {
                if (typeof window._madLucide === 'function') window._madLucide();
                else if (window.lucide && window.lucide.createIcons) window.lucide.createIcons();
            } catch (e) {}
            ['_madInitSelects', '_madInitMultiSelects', '_madInitDbSearchSelects',
             '_madInitDbEntry', '_madInitSelectCheck', '_madInitCepField', '_madInitCnpjField']
                .forEach(fn => { try { if (typeof window[fn] === 'function') window[fn](pane); } catch (e) {} });
        },

        afterShow(tab) {
            // resize dos ECharts que nasceram em display:none
            this.$nextTick(() => setTimeout(() => document.dispatchEvent(new Event('shown.bs.tab')), 60));
        },

        disposeCharts(root) {
            try { if (typeof window.__madDisposeChartsIn === 'function') window.__madDisposeChartsIn(root); } catch (e) {}
        },

        schedulePrefetch() {
            if (cfg.prefetch === 'none') return;
            const idle = window.requestIdleCallback || (fn => setTimeout(fn, 1500));
            idle(() => {
                const names = (cfg.tabs || []).filter(t => !t.disabled).map(t => t.name);
                let targets = [];
                if (cfg.prefetch === 'all') {
                    targets = names;
                } else {
                    const i = names.indexOf(this.active);
                    if (i >= 0 && i + 1 < names.length) targets = [names[i + 1]];
                }
                targets.forEach(n => {
                    if (this.loaded[n] !== cfg.sig && !this.loading[n] && n !== this.active) {
                        this.load(n, { silent: true });
                    }
                });
            });
        },

        // Wire no HOST (container) a partir de um pane — ex.: drill que aplica
        // filtro global: $dispatch não cruza componentes MAD, isto cruza.
        // Sobe a partir da RAIZ das abas, não do botão: num pane que é tela
        // MAD, o componente mais próximo do botão é o do pane.
        hostWire(action, params) {
            const root = hostRoot || (this.$el && this.$el.closest('.mad-dbtabs')) || this.$el;
            const wrapper = root ? root.closest('[mad-component]') : null;
            // `MadWire` é const global do mad-livewire.js (`window.MadWire` não existia — fw#361).
            if (wrapper && typeof MadWire !== 'undefined') MadWire.call(wrapper, action, params || []);
        },

        focusNext(dir) {
            const btns = [...this.$el.querySelectorAll('.mad-dbtabs-tab:not([disabled])')];
            const i = btns.indexOf(document.activeElement);
            const next = btns[(i + dir + btns.length) % btns.length];
            if (next) { next.focus(); next.click(); }
        },
        focusEdge(idx) {
            const btns = [...this.$el.querySelectorAll('.mad-dbtabs-tab:not([disabled])')];
            const b = idx === 0 ? btns[0] : btns[btns.length - 1];
            if (b) { b.focus(); b.click(); }
        },
    };
};
}
</script>
