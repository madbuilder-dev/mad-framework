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
       variant     string  pills | underline

     Gotchas de integração:
       - pushState SEM chave `url` no state (o handler global de popstate do
         mad.js dispara Mad.load quando `e.state.url` existe).
       - após exibir um pane, dispara 'shown.bs.tab' (+60ms): o <mad-db-chart>
         escuta esse evento e chama resize() — charts inicializados em pane
         display:none ficariam com largura 0.
       - antes de reinjetar HTML num pane, chama window.__madDisposeChartsIn
         (db-chart.blade.php) pra não vazar instâncias ECharts/observers. --}}
@php
    $name      = $name      ?? 'dashboard';
    $tabs      = is_array($tabs ?? null) ? array_values($tabs) : [];
    $active    = (string) ($active ?? ($tabs[0]['name'] ?? ''));
    $sig       = (string) ($sig ?? '');
    $model     = (string) ($model ?? '');
    $urlParam  = (string) ($urlParam ?? '');
    $history   = in_array(($history ?? 'push'), ['push', 'replace', 'off'], true) ? $history : 'push';
    $prefetch  = in_array(($prefetch ?? 'none'), ['none', 'next', 'all'], true) ? $prefetch : 'none';
    $keepAlive = filter_var($keepAlive ?? true,  FILTER_VALIDATE_BOOL);
    $remember  = filter_var($remember  ?? false, FILTER_VALIDATE_BOOL);
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
            <div class="mad-dbtabs-pane"
                 role="tabpanel"
                 id="{{ $name }}-pane-{{ $tName }}"
                 aria-labelledby="{{ $name }}-tab-{{ $tName }}"
                 data-tab="{{ $tName }}"
                 style="display:none"></div>
        @endforeach
    </div>
</div>

<script>
if (typeof window.madDashboardTabs !== 'function') {
window.madDashboardTabs = function (cfg) {
    cfg = cfg || {};
    return {
        active: '',
        loaded: {},      // tab -> sig com que foi carregada
        loading: {},     // tab -> bool
        _aborts: {},     // tab -> AbortController
        _popHandler: null,

        get storageKey() { return '__mad_dbtabs_' + (cfg.name || 'dashboard'); },

        tabByName(n) { return (cfg.tabs || []).find(t => t.name === n) || null; },

        init() {
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
                    if (st && st.name === cfg.name && st.tab && this.tabByName(st.tab)) {
                        this.go(st.tab, { fromPop: true });
                    }
                };
                window.__madDbTabsPop[cfg.name] = this._popHandler;
                window.addEventListener('popstate', this._popHandler);
            }

            this.go(initial, { replace: true });
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
            const state = { madTab: { name: cfg.name, tab: tab } };
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
            this._aborts[tab] = ac;
            this.loading[tab] = true;

            if (!opts.silent) {
                this.disposeCharts(pane);
                pane.innerHTML = '<div class="mad-dbtabs-loading"><span class="mad-dbtabs-spinner"></span> Carregando…</div>';
            }

            try {
                const res = await fetch(url, {
                    headers: { 'X-Mad-Partial': '1' },
                    credentials: 'same-origin',
                    signal: ac.signal,
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const html = await res.text();
                this.inject(tab, html);
                this.loaded[tab] = cfg.sig;
                if (this.active === tab) this.afterShow(tab);
            } catch (e) {
                if (e && e.name === 'AbortError') return;
                console.error('[mad-dashboard-tabs] falha ao carregar aba ' + tab, e);
                pane.innerHTML = '<div class="mad-dbtabs-error">Não foi possível carregar esta aba.'
                    + ' <button type="button" class="mad-btn mad-btn-sm" @click="reload(\'' + tab.replace(/'/g, '') + '\')">Tentar de novo</button></div>';
                try { if (window.Alpine) Alpine.initTree(pane); } catch (e2) {}
            } finally {
                this.loading[tab] = false;
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
        hostWire(action, params) {
            const wrapper = this.$el.closest('[mad-component]');
            if (wrapper && window.MadWire) window.MadWire.call(wrapper, action, params || []);
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
