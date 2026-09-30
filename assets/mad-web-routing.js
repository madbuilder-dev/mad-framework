/**
 * mad-web-routing.js — borda AJAX do roteamento web.
 *
 * Carregado por index.php. Reescreve, no client, URLs AJAX em formato de
 * query (…?class=X&method=Y) para as rotas limpas /app/X/Y — cobre widgets
 * antigos que ainda montam a URL nesse formato.
 *
 * Três chokepoints cobrem a navegação/upload AJAX admin:
 *   1.  window.fetch         → mad.js (Mad.load/post/exec, redirect op) + qualquer fetch
 *   1b. XMLHttpRequest       → uploaders (widgets de arquivo/imagem)
 *                              e qualquer XHR cru com ?class=...
 *   2.  jQuery.ajaxPrefilter → runtime antigo ($.get/$.post de __mad_load_page/post_data)
 *
 * O endpoint reativo (mad-livewire) já aponta para /app/_mad-wire via
 * MadComponent::_wireEndpoint — não passa por aqui (não tem ?class=).
 *
 * Com o shim de XMLHttpRequest (1b), os uploaders — que usam XHR cru — passam
 * a trafegar por /app/* (ex.: /app/servicos/upload), já que o dispatch por
 * ?class= direto é bloqueado (403).
 *
 * Config injetada por index.php:
 *   window.MadShell.routingDriver = 'web'
 *   window.MadShell.appBase       = '<basePath>/app'
 */
(function () {
    'use strict';

    if (!window.MadShell) window.MadShell = {};
    if (window.MadShell.routingDriver !== 'web') return;
    if (window.__madWebRoutingInstalled) return;
    window.__madWebRoutingInstalled = true;

    var APP_BASE = (window.MadShell.appBase || '/app').replace(/\/+$/, '');

    // ── Guard de ambiente adormecido (Teste Online) ───────────────────────────
    // Header carimbado pelo nginx de fallback quando o container do projeto esta
    // parado/ocioso. Vale para TODA resposta — inclusive AJAX. Sem isto, um
    // consumidor que faca innerHTML da resposta pinta a tela de repouso por cima
    // do app (CSS/scripts dela vazam no documento vivo).
    var OFFLINE_HEADER = 'X-Mad-Offline';
    var _reloading = false;

    function madOfflineReload() {
        if (_reloading) return;
        _reloading = true;
        try { window.location.reload(); } catch (e) { /* noop */ }
    }

    // res: Response (fetch). Retorna true quando o ambiente esta adormecido.
    function madOffline(res) {
        try {
            if (res && res.headers && res.headers.get(OFFLINE_HEADER) === '1') {
                madOfflineReload();
                return true;
            }
        } catch (e) { /* fail-open */ }
        return false;
    }
    window.__madOfflineReload = madOfflineReload;

    /**
     * Converte uma URL em formato de query (…?class=X&method=Y&…) em
     * <appBase>/X/Y?…. Só age quando há o parâmetro `class` — qualquer outra
     * URL (rotas /app já limpas, /app/_mad-wire, assets) passa intacta.
     */
    function toAppUrl(url) {
        try {
            if (typeof url !== 'string' || url === '') return url;

            var qIdx = url.indexOf('?');
            if (qIdx < 0) return url; // sem query → não é navegação class/method

            var params = new URLSearchParams(url.substring(qIdx + 1));
            var cls = params.get('class');
            if (!cls) return url; // não tem class= → não mexe

            var method = params.get('method') || '';
            params.delete('class');
            params.delete('method');

            // SEGURANÇA: o mapa classe->rota amigável NÃO é exportado pro
            // client (enumerava toda a superfície admin). A navegação de UI já vem
            // com a rota /app/slug ASSADA no servidor (MadAction/menu/MadResponse),
            // então não passa por aqui. O que ainda chega aqui é só URL em formato
            // de query montada por widget antigo (uploader/multisearch/auto-
            // complete/db-seek) — reescrita genericamente pra /app/{Classe}/{metodo},
            // cujo destino são as rotas exposeClass() declaradas no web.php.
            var out = APP_BASE + '/' + encodeURIComponent(cls);
            if (method) out += '/' + encodeURIComponent(method);

            var rest = params.toString();
            if (rest) out += '?' + rest;

            return out;
        } catch (e) {
            return url;
        }
    }

    // Exposto para debug e para overrides custom (ex.: <mad-tab-bar>).
    window.MadWebRoute = toAppUrl;

    // Header que distingue um LOAD AJAX (carimbado aqui) de uma navegação
    // DIRETA do browser (URL bar / bookmark, sem este header). O servidor
    // (AppRouteResolver) usa isso: sem o header → renderiza o shell completo
    // (casco+menu+tema); com o header → devolve só o fragmento de conteúdo.
    var PARTIAL_HEADER = 'X-Mad-Partial';

    // 1) window.fetch — cobre mad.js e mad-livewire (este último no-op).
    if (typeof window.fetch === 'function') {
        var _fetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            try {
                var rewrote = false;
                if (typeof input === 'string') {
                    var n = toAppUrl(input);
                    rewrote = (n !== input);
                    input = n;
                } else if (input && typeof input.url === 'string') {
                    var n2 = toAppUrl(input.url);
                    if (n2 !== input.url) {
                        input = new Request(n2, input);
                        rewrote = true;
                    }
                }
                if (rewrote) {
                    init = Object.assign({}, init || {});
                    var h = new Headers((init.headers) || {});
                    h.set(PARTIAL_HEADER, '1');
                    init.headers = h;
                }
            } catch (e) { /* fail-open */ }
            return _fetch(input, init).then(function (res) {
                // Container do Teste Online em repouso: o nginx de fallback devolve
                // a pagina "em repouso" INTEIRA (200 + X-Mad-Offline:1) para
                // QUALQUER requisicao. Quem injeta a resposta (innerHTML) encaixa
                // aquele casco dentro do app. Aborta a promise (nunca resolve) e
                // recarrega — o host adormecido serve a tela de repouso como
                // documento, limpa.
                if (madOffline(res)) return new Promise(function () {});
                return res;
            });
        };
    }

    // 1b) XMLHttpRequest — cobre os uploaders (widgets de arquivo/imagem)
    //     e QUALQUER XHR cru que poste com ?class=... Esses widgets usam
    //     `new XMLHttpRequest().open('POST', uri)` direto, escapando dos
    //     patches de fetch/jQuery acima. Sem este shim, uploads com URL em
    //     formato de query tomam 403. Aqui a URL é reescrita pra rota
    //     declarada (ex.: /app/servicos/upload) — sem porta dos fundos.
    if (typeof window.XMLHttpRequest === 'function') {
        var _open = XMLHttpRequest.prototype.open;
        var _send = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function (method, url) {
            var rest = [].slice.call(arguments, 2);   // async, user, password
            try {
                if (typeof url === 'string') {
                    var n = toAppUrl(url);
                    if (n !== url) {
                        this.__madRewrote = true;     // carimba header no send()
                        url = n;
                    }
                }
            } catch (e) { /* fail-open */ }
            return _open.apply(this, [method, url].concat(rest));
        };

        XMLHttpRequest.prototype.send = function (body) {
            try {
                if (this.__madRewrote) {
                    // setRequestHeader só vale entre open() e send() — ok aqui.
                    this.setRequestHeader(PARTIAL_HEADER, '1');
                }
                // Ambiente adormecido → recarrega (ver madOffline acima).
                this.addEventListener('load', function () {
                    try {
                        if (this.getResponseHeader(OFFLINE_HEADER) === '1') madOfflineReload();
                    } catch (e) { /* fail-open */ }
                });
            } catch (e) { /* fail-open */ }
            return _send.apply(this, arguments);
        };
    }

    // (Removido o shim de __mad_goto_page: os redirects full do app agora são
    //  100% MAD via MadResponse::redirect()/redirectUrl()/emit() → op 'redirect'
    //  (Mad.applyOps), que já reescreve pra /app/* via MadWebRoute. Não há mais
    //  gotoPage legado no código de app.)

    // 1d) __mad_load_page — runtimes de tema antigos chamam isto no clique
    //     de links de menu. Pra uma ROTA AMIGÁVEL (/app/slug, sem class=)
    //     roteamos pro Mad.navigate (SPA: fetch com X-Mad-Partial + inject no
    //     #mad_main + pushState) — 100% MAD. URLs em formato de query
    //     (class=/index.php) seguem o original. Cobre TODOS os temas sem
    //     mexer no menu builder.
    function _isFriendlyAppUrl(u) {
        return typeof u === 'string'
            && u.indexOf(APP_BASE + '/') === 0
            && u.indexOf('class=')     === -1
            && u.indexOf('index.php')  === -1;
    }
    function installLoadPageShim() {
        var cur = window.__mad_load_page;
        if (cur && cur.__madWrapped) return;
        var orig = (typeof cur === 'function') ? cur : null;
        var wrapped = function (page, callback) {
            try {
                if (_isFriendlyAppUrl(page) && window.Mad && window.Mad.navigate) {
                    return window.Mad.navigate(page, callback);
                }
            } catch (e) { /* fail-open */ }
            if (orig) { return orig.apply(window, arguments); }
        };
        wrapped.__madWrapped = true;
        window.__mad_load_page = wrapped;
    }
    installLoadPageShim();
    document.addEventListener('DOMContentLoaded', installLoadPageShim);
    window.addEventListener('load', installLoadPageShim);

    // 2) jQuery.ajaxPrefilter — cobre o runtime legado ($.get/$.post).
    function installJqueryPrefilter() {
        if (window.jQuery && typeof window.jQuery.ajaxPrefilter === 'function') {
            window.jQuery.ajaxPrefilter(function (options) {
                try {
                    if (options && typeof options.url === 'string') {
                        var orig = options.url;
                        var rewritten = toAppUrl(orig);
                        options.url = rewritten;
                        if (rewritten !== orig) {
                            options.headers = options.headers || {};
                            options.headers[PARTIAL_HEADER] = '1';
                        }
                    }
                } catch (e) { /* fail-open */ }
            });
            // Ambiente adormecido → recarrega (o runtime legado injeta a resposta
            // crua no DOM; o reload devolve a tela de repouso como documento).
            window.jQuery(document).ajaxComplete(function (_e, jqXHR) {
                try {
                    if (jqXHR && jqXHR.getResponseHeader
                        && jqXHR.getResponseHeader(OFFLINE_HEADER) === '1') {
                        madOfflineReload();
                    }
                } catch (e) { /* fail-open */ }
            });
            return true;
        }
        return false;
    }

    if (!installJqueryPrefilter()) {
        // jQuery ainda não carregou — tenta de novo no ready/load.
        document.addEventListener('DOMContentLoaded', installJqueryPrefilter);
        window.addEventListener('load', installJqueryPrefilter);
    }
})();
