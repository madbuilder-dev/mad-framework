/**
 * mad-livewire.js — Sistema de componentes reativos (estilo Livewire).
 *
 * No Blade, o desenvolvedor usa mad:click, mad:model etc.
 * O PHP (MadComponent::_processDirectives) converte para data-mad-* antes
 * de enviar ao browser — sem problemas com parsers HTML e innerHTML do front-end legado.
 *
 * Diretivas (escritas no Blade):
 *   mad:click="metodo"           → chama o método ao clicar
 *   mad:click="metodo(1, 'x')"   → chama com parâmetros literais
 *   mad:click="$set('prop', v)"  → seta prop e re-renderiza (sem método PHP)
 *   mad:click="$set({...})"      → seta várias props de uma vez
 *   mad:click="$refresh"         → envia models atuais e re-renderiza
 *   mad:model="prop"             → sincroniza no próximo action
 *   mad:model.live="prop"        → sincroniza imediatamente (debounced)
 *   mad:submit="metodo"          → chama ao enviar o form
 *   mad:change="metodo"          → chama ao mudar valor (select/input)
 *   mad:loading                  → visível apenas durante requisição
 *   mad:loading.remove           → escondido durante requisição
 */
/* ─────────────────────────────────────────────────────────────────────────────
 * MadErrorModal — visualizador de erro de servidor.
 *
 * Antes: um POST do wire (ou qualquer fetch do Mad.*) que voltasse 500 com a
 * página de erro HTML do Laravel caía no ramo "HTML legado" — os scripts
 * rodavam, NADA aparecia na tela e o dev só descobria abrindo o DevTools.
 *
 * Agora: qualquer resposta de erro abre um modal 90vw × 90vh com
 *   • a página de erro renderizada (iframe sandbox, fidelidade total),
 *   • o texto puro extraído (copiável),
 *   • um Markdown pronto pro agente de IA (contexto + exception + trace).
 *
 * API:
 *   MadErrorModal.show({ status, statusText, url, method, body, exception, context })
 *   MadErrorModal.showResponse(res, bodyText, context)  // res = Response do fetch
 *   MadErrorModal.isErrorPage(text)                     // heurística p/ HTML de erro
 *   MadErrorModal.buildMarkdown(info)                   // string markdown
 *   MadErrorModal.close()
 * ────────────────────────────────────────────────────────────────────────── */
(function () {
    'use strict';

    if (window.MadErrorModal) return;

    // Limite do dump de texto no markdown (o agente não precisa de 200 KB).
    const MAX_TEXT = 16000;
    // Chaves de campo que NUNCA vão pro markdown com valor.
    const SECRET_RE = /pass|senha|secret|token|csrf|api[_-]?key|authorization/i;

    let _el   = null;   // backdrop atual
    let _info = null;   // info do erro atual
    let _prevOverflow = '';

    // ── CSS (injetado uma vez; usa tokens --mad-* → dark automático) ─────────

    function _injectCss() {
        if (document.getElementById('mad-errm-css')) return;
        const css = `
.mad-errm-backdrop{position:fixed;inset:0;z-index:2147483000;background:rgba(9,9,11,.62);
 display:flex;align-items:center;justify-content:center;
 font-family:ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.mad-errm{width:90vw;height:90vh;display:flex;flex-direction:column;overflow:hidden;
 background:var(--mad-bg,#fff);color:var(--mad-text,#18181b);
 border:1px solid var(--mad-border,#e5e7eb);border-radius:12px;
 box-shadow:0 24px 64px rgba(0,0,0,.45)}
.mad-errm-head{display:flex;align-items:flex-start;gap:12px;padding:14px 16px;
 border-bottom:1px solid var(--mad-border,#e5e7eb);background:var(--mad-bg-subtle,#fafafa)}
.mad-errm-badge{flex:none;font:600 12px/1 ui-monospace,SFMono-Regular,Menlo,monospace;
 padding:6px 8px;border-radius:6px;background:#dc2626;color:#fff;letter-spacing:.5px}
.mad-errm-htitle{flex:1;min-width:0}
.mad-errm-type{font-size:15px;font-weight:700;display:block}
.mad-errm-msg{display:block;margin-top:2px;font-size:13px;color:var(--mad-text-muted,#52525b);
 word-break:break-word;max-height:3.2em;overflow:auto}
.mad-errm-x{flex:none;border:0;background:transparent;cursor:pointer;font-size:22px;line-height:1;
 padding:2px 8px;border-radius:6px;color:var(--mad-text-muted,#52525b)}
.mad-errm-x:hover{background:var(--mad-bg-muted,#f4f4f5)}
.mad-errm-sub{padding:8px 16px;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace;
 color:var(--mad-text-muted,#52525b);border-bottom:1px solid var(--mad-border,#e5e7eb);
 white-space:nowrap;overflow:auto}
.mad-errm-bar{display:flex;align-items:center;gap:6px;padding:8px 12px;flex-wrap:wrap;
 border-bottom:1px solid var(--mad-border,#e5e7eb);background:var(--mad-bg-subtle,#fafafa)}
.mad-errm-bar button{border:1px solid var(--mad-border,#e5e7eb);background:var(--mad-bg,#fff);
 color:var(--mad-text,#18181b);border-radius:7px;padding:6px 11px;font-size:13px;cursor:pointer}
.mad-errm-bar button:hover{background:var(--mad-bg-muted,#f4f4f5)}
.mad-errm-bar button.is-active{background:var(--mad-text,#18181b);color:var(--mad-bg,#fff);
 border-color:var(--mad-text,#18181b)}
.mad-errm-spacer{flex:1}
.mad-errm-bar button.mad-errm-cta{background:#4f46e5;border-color:#4f46e5;color:#fff;font-weight:600}
.mad-errm-bar button.mad-errm-cta:hover{background:#4338ca}
.mad-errm-body{flex:1;min-height:0;position:relative;background:var(--mad-bg,#fff)}
.mad-errm-pane{position:absolute;inset:0;width:100%;height:100%;border:0;margin:0;box-sizing:border-box}
pre.mad-errm-pane,textarea.mad-errm-pane{overflow:auto;padding:14px 16px;white-space:pre-wrap;
 word-break:break-word;font:12px/1.6 ui-monospace,SFMono-Regular,Menlo,monospace;
 background:var(--mad-bg,#fff);color:var(--mad-text,#18181b);resize:none}
textarea.mad-errm-pane{outline:0}
.mad-errm-pane[hidden]{display:none}
.mad-errm-toast{position:absolute;bottom:18px;left:50%;transform:translateX(-50%);
 background:#18181b;color:#fff;padding:8px 14px;border-radius:8px;font-size:13px;
 opacity:0;transition:opacity .18s;pointer-events:none;z-index:5}
.mad-errm-toast.is-on{opacity:1}
@media (max-width:640px){.mad-errm{width:96vw;height:94vh}}
`;
        const style = document.createElement('style');
        style.id = 'mad-errm-css';
        style.textContent = css;
        document.head.appendChild(style);
    }

    // ── Heurística: o corpo é uma página de erro (Laravel/PHP)? ──────────────

    function _isErrorPage(text) {
        if (!text) return false;
        // Erro de tela renderizado pelo framework (MadComponent::_renderError,
        // MadErrorRenderer, MadErrorPage::internal): fragmento SEM <html> que
        // pode chegar com status 200. A marca vem do servidor.
        if (_errorFragmentKind(text)) return true;
        const head = String(text).slice(0, 6000);
        if (!/<!doctype html|<html[\s>]/i.test(head)) {
            // Fatal do PHP com display_errors, sem casco HTML
            return /(Fatal error|Parse error|Uncaught \w*(Error|Exception))/i.test(head);
        }
        return /(Whoops|Server Error|Internal Server Error|Stack trace|Illuminate\\|Exception|Fatal error|Parse error|vendor\/laravel)/i.test(head);
    }

    /** 'overlay' | 'debug' | 'internal' quando o HTML é um erro de tela marcado pelo servidor. */
    function _errorFragmentKind(text) {
        const m = /\bdata-mad-error-page="(overlay|debug|internal)"/.exec(String(text || ''));
        return m ? m[1] : '';
    }

    // ── Extração de dados da página de erro HTML ─────────────────────────────

    function _extractFromHtml(html) {
        const out = { title: '', type: '', message: '', file: '', line: '', text: '' };
        let doc = null;
        try { doc = new DOMParser().parseFromString(String(html), 'text/html'); } catch (e) {}

        if (!doc || !doc.body) {
            out.text = String(html).replace(/<[^>]*>/g, ' ').replace(/\s{2,}/g, ' ').trim();
            return out;
        }

        doc.querySelectorAll('script,style,svg,noscript,link,iframe').forEach(n => n.remove());
        out.title = (doc.title || '').trim();

        // textContent gruda blocos vizinhos ("TypeError" + "vendor/…php:639" viram
        // uma palavra só e nenhum regex casa). Como o doc é destacado (sem layout,
        // sem innerText), quebramos linha na marra nos elementos de bloco.
        const BLOCKS = 'address,article,aside,blockquote,dd,div,dl,dt,fieldset,figcaption,'
                     + 'figure,footer,form,h1,h2,h3,h4,h5,h6,header,hr,li,main,nav,ol,p,pre,'
                     + 'section,table,tbody,tfoot,thead,tr,ul';
        doc.querySelectorAll('br').forEach(br => br.replaceWith(doc.createTextNode('\n')));
        doc.querySelectorAll('td,th').forEach(cell => cell.appendChild(doc.createTextNode('\t')));
        doc.querySelectorAll(BLOCKS).forEach(el => {
            el.insertBefore(doc.createTextNode('\n'), el.firstChild);
            el.appendChild(doc.createTextNode('\n'));
        });

        out.text = (doc.body.textContent || '')
            .replace(/\r/g, '')
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .replace(/[ \t]{3,}/g, '  ')
            .trim();

        const mType = out.text.match(/\b([A-Z][A-Za-z0-9_]*(?:Error|Exception))\b/);
        if (mType) out.type = mType[1];

        const mFile = out.text.match(/(?:^|[\s"'(])([\w./\\-]+\.php)[:( ](\d+)/);
        if (mFile) { out.file = mFile[1]; out.line = mFile[2]; }

        // No error page do Laravel o <title> é a própria mensagem.
        if (out.title && !/^(server error|internal server error|whoops|error|erro)$/i.test(out.title)) {
            out.message = out.title;
        } else if (out.type) {
            const after = out.text.split(out.type)[1] || '';
            out.message = after.replace(/^[\s:—-]+/, '').split('\n')[0].slice(0, 400).trim();
        }

        return out;
    }

    // ── Clipboard (com fallback p/ contexto não-seguro) ──────────────────────

    function _copy(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(() => _copyFallback(text));
        }
        return Promise.resolve(_copyFallback(text));
    }

    function _copyFallback(text) {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;top:-9999px;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (e) {}
        ta.remove();
    }

    // ── Markdown pro agente de IA ────────────────────────────────────────────

    function _fence(body, lang) {
        return '```' + (lang || '') + '\n' + String(body || '').trim() + '\n```';
    }

    function _truncate(text, max) {
        const t = String(text || '');
        if (t.length <= max) return t;
        return t.slice(0, max) + '\n\n… [truncado: ' + (t.length - max) + ' caracteres omitidos]';
    }

    function _buildMarkdown(info) {
        info = info || {};
        const ex     = info.exception || {};
        const ctx    = info.context   || {};
        const parsed = info.parsed    || {};
        const type   = ex.type    || parsed.type    || '';
        const msg    = ex.message || parsed.message || info.statusText || '';
        const file   = ex.file    || parsed.file    || '';
        const line   = ex.line    || parsed.line    || '';
        const L      = [];

        L.push('# Bug — ' + (info.status ? 'HTTP ' + info.status : 'falha de requisição')
               + (type ? ' · ' + type : ''));
        L.push('');
        L.push('_Relatório gerado pelo MAD Framework (MadErrorModal) para um agente de IA._');
        L.push('');

        L.push('## Requisição');
        L.push('- **Endpoint:** `' + (info.method || 'GET') + ' ' + (info.url || '-') + '`');
        L.push('- **Status:** `' + (info.status || 0) + (info.statusText ? ' ' + info.statusText : '') + '`');
        L.push('- **Página aberta:** `' + location.href + '`');
        if (ctx.source)    L.push('- **Origem no front:** `' + ctx.source + '`');
        if (ctx.component) L.push('- **Componente:** `' + ctx.component + '`');
        if (ctx.action)    L.push('- **Ação (mad_action):** `' + ctx.action + '`');
        if (ctx.params && (Array.isArray(ctx.params) ? ctx.params.length : Object.keys(ctx.params || {}).length)) {
            L.push('- **Params:** `' + JSON.stringify(ctx.params) + '`');
        }
        L.push('- **Quando:** `' + new Date().toISOString() + '`');
        L.push('');

        const models = ctx.models || null;
        if (models && Object.keys(models).length) {
            L.push('## Campos enviados (mad_model)');
            L.push('');
            L.push('| campo | valor |');
            L.push('| --- | --- |');
            Object.keys(models).forEach(k => {
                let v = SECRET_RE.test(k) ? '«omitido»' : String(models[k]);
                if (v.length > 160) v = v.slice(0, 160) + '…';
                L.push('| `' + k + '` | ' + v.replace(/\|/g, '\\|').replace(/\n/g, ' ') + ' |');
            });
            L.push('');
        }

        L.push('## Exception');
        L.push('- **Tipo:** `' + (type || 'desconhecido') + '`');
        L.push('- **Mensagem:** ' + (msg || '_(não extraída)_'));
        if (file) L.push('- **Origem:** `' + file + (line ? ':' + line : '') + '`');
        if (ex.php)     L.push('- **PHP:** `' + ex.php + '`');
        if (ex.laravel) L.push('- **Laravel:** `' + ex.laravel + '`');
        if (ex.framework) L.push('- **mad-framework:** `' + ex.framework + '`');
        L.push('');

        if (ex.snippet) {
            L.push('## Trecho do código (origem)');
            L.push(_fence(ex.snippet, 'php'));
            L.push('');
        }

        if (ex.trace && ex.trace.length) {
            L.push('## Stack trace');
            L.push(_fence(ex.trace.join('\n')));
            L.push('');
        }

        const dump = parsed.text || info.body || '';
        if (dump) {
            L.push('## Página de erro (texto extraído)');
            L.push(_fence(_truncate(dump, MAX_TEXT)));
            L.push('');
        }

        L.push('## Tarefa');
        L.push('1. Aponte a causa raiz exata (arquivo:linha) e explique por que quebra neste fluxo.');
        L.push('2. Corrija na origem. Arquivos em `vendor/mad/framework/**` são cópia do pacote:');
        L.push('   a correção definitiva vai em `packages/mad-framework/**` (mesmo caminho relativo).');
        L.push('   Código do app corrige em `app/**`.');
        L.push('3. Cubra o caso com teste (ou explique por que não dá).');
        L.push('4. Liste os arquivos alterados no final.');

        return L.join('\n');
    }

    // ── Modal ────────────────────────────────────────────────────────────────

    function close() {
        if (!_el) return;
        _el.remove();
        _el = null;
        document.body.style.overflow = _prevOverflow;
        document.removeEventListener('keydown', _onKey, true);
    }

    function _onKey(e) {
        if (e.key === 'Escape') { e.stopPropagation(); close(); }
    }

    function _toast(msg) {
        if (!_el) return;
        const t = _el.querySelector('.mad-errm-toast');
        if (!t) return;
        t.textContent = msg;
        t.classList.add('is-on');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.classList.remove('is-on'), 1800);
    }

    function show(info) {
        info = info || {};
        _injectCss();
        close();

        const html   = info.body || '';
        const parsed = info.parsed || (typeof html === 'string' && html ? _extractFromHtml(html) : {});
        info.parsed  = parsed;
        _info = info;

        const ex    = info.exception || {};
        const type  = ex.type    || parsed.type    || 'Erro do servidor';
        const msg   = ex.message || parsed.message || info.statusText || '';
        const file  = ex.file    || parsed.file    || '';
        const line  = ex.line    || parsed.line    || '';
        const isHtml = /<!doctype html|<html[\s>]/i.test(String(html).slice(0, 2000));

        const sub = [
            (info.method || 'GET') + ' ' + (info.url || ''),
            file ? file + (line ? ':' + line : '') : '',
            ex.laravel ? 'Laravel ' + ex.laravel : '',
            ex.php ? 'PHP ' + ex.php : '',
        ].filter(Boolean).join('  ·  ');

        const back = document.createElement('div');
        back.className = 'mad-errm-backdrop';
        back.setAttribute('role', 'dialog');
        back.setAttribute('aria-modal', 'true');
        back.innerHTML = `
<div class="mad-errm">
  <div class="mad-errm-head">
    <span class="mad-errm-badge">${info.status || 'ERR'}</span>
    <div class="mad-errm-htitle">
      <span class="mad-errm-type"></span>
      <span class="mad-errm-msg"></span>
    </div>
    <button type="button" class="mad-errm-x" aria-label="Fechar">&times;</button>
  </div>
  <div class="mad-errm-sub"></div>
  <div class="mad-errm-bar">
    <button type="button" data-tab="page"${isHtml ? ' class="is-active"' : ''}>Página do erro</button>
    <button type="button" data-tab="text"${isHtml ? '' : ' class="is-active"'}>Texto</button>
    <button type="button" data-tab="md">Markdown</button>
    <span class="mad-errm-spacer"></span>
    <button type="button" class="mad-errm-cta" data-act="md">Gerar Markdown p/ IA</button>
    <button type="button" data-act="copy">Copiar</button>
    <button type="button" data-act="download">Baixar .md</button>
    <button type="button" data-act="close">Fechar</button>
  </div>
  <div class="mad-errm-body">
    <iframe class="mad-errm-pane" data-pane="page" sandbox="allow-scripts" referrerpolicy="no-referrer"></iframe>
    <pre class="mad-errm-pane" data-pane="text"></pre>
    <textarea class="mad-errm-pane" data-pane="md" spellcheck="false" readonly></textarea>
    <div class="mad-errm-toast"></div>
  </div>
</div>`;

        back.querySelector('.mad-errm-type').textContent = type;
        back.querySelector('.mad-errm-msg').textContent  = msg;
        back.querySelector('.mad-errm-sub').textContent  = sub;
        back.querySelector('[data-pane="text"]').textContent =
            parsed.text || (typeof html === 'string' ? html : '') || _plainDump(info) || '(resposta vazia)';

        const frame = back.querySelector('[data-pane="page"]');
        if (isHtml) {
            frame.setAttribute('srcdoc', html);
        } else {
            // Sem página HTML (payload JSON do wire): a aba "Página do erro"
            // ficaria em branco — some com ela.
            frame.remove();
            const pageTab = back.querySelector('[data-tab="page"]');
            if (pageTab) pageTab.remove();
        }

        function setTab(name) {
            back.querySelectorAll('[data-tab]').forEach(b => {
                b.classList.toggle('is-active', b.dataset.tab === name);
            });
            back.querySelectorAll('.mad-errm-pane').forEach(p => {
                p.hidden = p.dataset.pane !== name;
            });
            if (name === 'md') _ensureMarkdown(back);
        }

        setTab(isHtml ? 'page' : 'text');

        back.addEventListener('click', (e) => {
            if (e.target === back) { close(); return; }
            const tabBtn = e.target.closest('[data-tab]');
            if (tabBtn) { setTab(tabBtn.dataset.tab); return; }
            const act = e.target.closest('[data-act]');
            if (!act) return;
            const kind = act.dataset.act;
            if (kind === 'close') { close(); return; }
            if (kind === 'md') {
                setTab('md');
                _copy(_ensureMarkdown(back)).then(() => _toast('Markdown copiado — cole no agente de IA'));
                return;
            }
            if (kind === 'copy') {
                const active = back.querySelector('.mad-errm-pane:not([hidden])');
                const pane   = active ? active.dataset.pane : 'text';
                const txt = pane === 'page' ? String(html)
                          : pane === 'md'   ? _ensureMarkdown(back)
                          : (parsed.text || String(html));
                _copy(txt).then(() => _toast('Copiado'));
                return;
            }
            if (kind === 'download') {
                const md   = _ensureMarkdown(back);
                const blob = new Blob([md], { type: 'text/markdown;charset=utf-8' });
                const a    = document.createElement('a');
                a.href     = URL.createObjectURL(blob);
                a.download = 'mad-erro-' + (info.status || 'x') + '-' + Date.now() + '.md';
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
                _toast('Arquivo .md baixado');
            }
        });

        back.querySelector('.mad-errm-x').addEventListener('click', close);

        document.body.appendChild(back);
        _prevOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', _onKey, true);
        _el = back;

        try { console.error('[MadErrorModal]', info.status, info.url, type, msg); } catch (e) {}
        return back;
    }

    /** Texto legível do payload JSON (quando não veio página HTML). */
    function _plainDump(info) {
        const ex = (info && info.exception) || null;
        if (!ex) return '';

        const L = [ex.type + ': ' + ex.message];
        if (ex.file)     L.push('em ' + ex.file + (ex.line ? ':' + ex.line : ''));
        if (ex.previous) L.push('anterior: ' + ex.previous);
        if (ex.snippet)  L.push('', ex.snippet);
        if (ex.trace && ex.trace.length) L.push('', 'Stack trace:', ex.trace.join('\n'));
        if (ex.stray_output) L.push('', 'Output capturado:', ex.stray_output);

        return L.join('\n');
    }

    function _ensureMarkdown(root) {
        const ta = root.querySelector('[data-pane="md"]');
        if (!ta) return '';
        if (!ta.value) ta.value = _buildMarkdown(_info);
        return ta.value;
    }

    /**
     * Atalho pra respostas de fetch: MadErrorModal.showResponse(res, text, ctx).
     * `res` pode ser um Response real ou um objeto { status, statusText, url }.
     */
    function showResponse(res, bodyText, context) {
        let exception = null;
        const text = bodyText == null ? '' : String(bodyText);

        // Payload JSON estruturado do handler do wire ({ error, _exception })
        if (text.trimStart().startsWith('{')) {
            try {
                const j = JSON.parse(text);
                if (j && j._exception) exception = j._exception;
                else if (j && j.error)  exception = { type: 'Erro', message: String(j.error) };
            } catch (e) {}
        }

        return show({
            status:     res && res.status ? res.status : 0,
            statusText: (res && res.statusText) || '',
            url:        (res && res.url) || (context && context.url) || '',
            method:     (context && context.method) || 'GET',
            // Com payload estruturado o corpo é o próprio JSON — mostrar o JSON
            // cru não ajuda ninguém; o modal renderiza os campos de `_exception`.
            body:       exception ? '' : text,
            exception:  exception,
            context:    context || {},
        });
    }

    /**
     * Substitui `res.json()` em fetches que esperam JSON do wire.
     *
     * Resolve com o objeto JSON quando a resposta é sã; quando é erro
     * (500/HTML/`_exception`) abre o modal e resolve `null` — o chamador só
     * precisa de um `if (!data) return;`.
     */
    function guardJson(res, context) {
        return res.text().then(function (text) {
            let data = null;
            try { data = JSON.parse(text); } catch (e) {}

            if (data === null || typeof data !== 'object') {
                // Esperava JSON e veio outra coisa (HTML de erro com status 200,
                // corpo vazio, página de outro sistema…). Antes, com status 200,
                // ia só pro console e quem chamou saía calado: a ação "sumia".
                showResponse(
                    (!res.ok || _isErrorPage(text))
                        ? res
                        : { status: res.status, statusText: 'Resposta inesperada do servidor', url: res.url },
                    text, context);
                return null;
            }

            if (data._exception) {
                show({
                    status:     res.status,
                    statusText: res.statusText,
                    url:        res.url || (context && context.url) || '',
                    method:     (context && context.method) || 'POST',
                    body:       '',
                    exception:  data._exception,
                    context:    context || {},
                });
                return null;
            }

            return data;
        });
    }

    window.MadErrorModal = {
        show:          show,
        showResponse:  showResponse,
        guardJson:     guardJson,
        close:         close,
        isErrorPage:   _isErrorPage,
        errorFragmentKind: _errorFragmentKind,
        buildMarkdown: _buildMarkdown,
        _extract:      _extractFromHtml,
    };
})();

/**
 * Listas (field-list / detail-form) de um componente, inclusive as que moram
 * num painel teleportado (`<mad-drawer>`/`<mad-modal>` dentro da tela) — ver
 * MadWire.ownedNodes. Sem o MadWire carregado, só o que está no wrapper.
 */
function _madListNodes(wrapper, sel) {
    if (typeof MadWire !== 'undefined' && MadWire && typeof MadWire.ownedNodes === 'function') {
        return MadWire.ownedNodes(wrapper, sel);
    }
    return Array.prototype.slice.call(wrapper.querySelectorAll(sel));
}

/**
 * Coleta dados de todos os mad-field-lists dentro de um wrapper.
 * Lê o Alpine `rows` de cada field-list e serializa como JSON no FormData.
 * Disponível globalmente para uso em mad-livewire.js e mad-ui.js.
 */
/**
 * Coleta dados de todos os mad-detail-forms dentro de um wrapper.
 * Lê o Alpine `rows` de cada detail-form e serializa como JSON no FormData.
 */
function _madCollectDetailForms(wrapper, body) {
    var result = {};
    _madListNodes(wrapper, '[data-mad-df-name]').forEach(function(df) {
        var name = df.getAttribute('data-mad-df-name');
        if (!name) return;
        try {
            var alpineData = (window.Alpine && Alpine.$data) ? Alpine.$data(df) : null;
            if (alpineData && alpineData.rows) {
                result[name] = JSON.parse(JSON.stringify(alpineData.rows));
            }
        } catch(e) {}
    });
    if (Object.keys(result).length > 0) {
        body.append('mad_detail_forms', JSON.stringify(result));
    }
}

function _madCollectFieldLists(wrapper, body) {
    var result = {};
    _madListNodes(wrapper, '[data-mad-fl-name]').forEach(function(fl) {
        var name = fl.getAttribute('data-mad-fl-name');
        if (!name) return;
        try {
            var alpineData = (window.Alpine && Alpine.$data) ? Alpine.$data(fl) : null;
            if (alpineData && alpineData.rows) {
                result[name] = JSON.parse(JSON.stringify(alpineData.rows));
            }
        } catch(e) {}
    });
    if (Object.keys(result).length > 0) {
        body.append('mad_field_lists', JSON.stringify(result));
    }

    // Coleta File objects de campos file/multifile do field-list
    if (window.__madFlFiles) {
        Object.keys(window.__madFlFiles).forEach(function(key) {
            var files = window.__madFlFiles[key];
            if (Array.isArray(files)) {
                for (var i = 0; i < files.length; i++) {
                    body.append('mad_fl_files[' + key + '][' + i + ']', files[i], files[i].name);
                }
            }
        });
    }
}

const MadWire = (() => {

    const CONFIG = { debounceDelay: 300 };

    // ── Utilitários ──────────────────────────────────────────────────────────

    function _debounce(fn, delay) {
        let timer;
        return (...args) => { clearTimeout(timer); timer = setTimeout(() => fn(...args), delay); };
    }

    /**
     * <template> de origem do ramo teleportado que contém `el` (ou null).
     *
     * O Alpine, ao processar `x-teleport`, CLONA o conteúdo do template pro
     * alvo (`body`) e deixa `_x_teleportBack` no nó clonado apontando de volta
     * pro <template> — que continua no lugar original do DOM.
     */
    function _teleportOrigin(el) {
        for (let n = el; n; n = n.parentElement) {
            if (n._x_teleportBack) return n._x_teleportBack;
        }
        return null;
    }

    /**
     * `closest(sel)` que ATRAVESSA teleports: quando o ramo clonado acaba sem
     * casar, continua a subida a partir do <template> de origem. Fora de
     * teleport é exatamente `el.closest(sel)`.
     */
    function _closestAcross(el, sel) {
        for (let node = el, hops = 0; node && node.closest && hops < 10; hops++) {
            const hit = node.closest(sel);
            if (hit) return hit;
            const tpl = _teleportOrigin(node);
            if (!tpl) return null;
            node = tpl.parentElement;
        }
        return null;
    }

    /**
     * Raízes DOM de um componente: o wrapper + os ramos que ele TELEPORTOU.
     *
     * `<mad-drawer>` (e com ele o `style="drawer"` de `<mad-grid-filters>`,
     * `<mad-dash-filters>`, kanban e calendário) põe o painel num
     * `<template x-teleport="body">`: o Alpine clona o conteúdo pro <body> e
     * guarda o clone em `template._x_teleport`. O formulário do filtro fica
     * FORA do wrapper — e a coleta, que só olhava o wrapper, mandava o POST sem
     * os campos nem o token `__mad_form`: o grid recarregava sem filtro.
     */
    function _componentRoots(wrapper) {
        const roots = [wrapper];
        if (!wrapper || !wrapper.querySelectorAll) return roots;
        for (let i = 0; i < roots.length; i++) {
            roots[i].querySelectorAll('template[x-teleport], template[data-teleport-template]').forEach(tpl => {
                const clone = tpl._x_teleport;
                if (!clone || !clone.isConnected || roots.includes(clone)) return;
                // Teleport para alvo DENTRO de uma raiz já listada: contaria duas vezes.
                if (roots.some(r => r.contains && r.contains(clone))) return;
                roots.push(clone);
            });
        }
        return roots;
    }

    /**
     * Nós dos ramos teleportados que pertencem a ESTE componente — sem os de
     * componente aninhado (outro wire) e sem os do editor de detail-form /
     * field-list (esses viajam em bucket próprio, ver _collectModelValues).
     */
    function _teleportedNodes(wrapper, sel) {
        const out = [];
        _componentRoots(wrapper).slice(1).forEach(root => {
            root.querySelectorAll(sel).forEach(n => {
                if (_closestAcross(n, '[mad-component]') !== wrapper) return;
                if (_closestAcross(n, '[data-mad-df-name]') || _closestAcross(n, '[data-mad-fl-name]')) return;
                out.push(n);
            });
        });
        return out;
    }

    /** `wrapper.querySelectorAll(sel)` + o que o componente teleportou. */
    function _componentNodes(wrapper, sel) {
        return [...wrapper.querySelectorAll(sel), ..._teleportedNodes(wrapper, sel)];
    }

    /**
     * Listas do componente (`[data-mad-df-name]` / `[data-mad-fl-name]`),
     * inclusive as que moram num painel TELEPORTADO (`<mad-drawer>` /
     * `<mad-modal>` dentro da tela). Diferente do _teleportedNodes, aqui o nó
     * da lista é justamente o que se procura — ele não pode ser filtrado como
     * "editor de detail-form".
     *
     * Sem isso o `wrapper.querySelector` não enxergava o detail-form da gaveta:
     * a resposta do before-add (`df_add`) caía no vazio — a linha não entrava,
     * sem erro — e o salvar mandava o formulário sem as linhas do detalhe.
     */
    function _ownedNodes(wrapper, sel) {
        if (!wrapper || !wrapper.querySelectorAll) return [];
        const out = [...wrapper.querySelectorAll(sel)];
        _componentRoots(wrapper).slice(1).forEach(root => {
            root.querySelectorAll(sel).forEach(n => {
                if (!out.includes(n) && _closestAcross(n, '[mad-component]') === wrapper) out.push(n);
            });
        });
        return out;
    }

    /** Primeiro nó do componente que casa `sel` (ver _ownedNodes). */
    function _ownedNode(wrapper, sel) {
        return _ownedNodes(wrapper, sel)[0] || null;
    }

    function _getWrapper(el) {
        // Sobe até o [mad-component] dono ATRAVESSANDO teleports: `mode="drawer"`
        // / `mode="modal"` do detail-form (e todo <x-drawer>/<x-modal>) move o
        // sub-form pro <body>, então closest() morre na raiz teleportada e caía
        // no fallback abaixo — que acertava a LISTAGEM atrás do formulário, sem
        // o método do mad:change ("Método não permitido: onChangeX").
        const owner = _closestAcross(el, '[mad-component]');
        if (owner) return owner;

        // Fallback pro componente principal quando o elemento foi portaleado pra
        // fora do wrapper sem rastro de teleport (ex.: #mad-cc-drawer movido pro
        // <body> pelo madCC.boot() via appendChild) — mesmo contrato do Mad.call.
        return document.querySelector('#mad_main [mad-component]')
            || document.querySelector('main [mad-component]')
            || document.querySelector('[mad-component]');
    }

    /**
     * Editor de um <mad-detail-form> que contém `el` — `[data-df-fields]`, nos
     * três modos (inline/modal/drawer). Devolve `{ name, values }` com os campos
     * nomeados do sub-form, ou null quando o elemento não está num detail.
     *
     * Os campos do editor NÃO viajam em `mad_model` (ver _collectModelValues:
     * seriam clobber de campo homônimo do master) — vão neste bucket próprio, e
     * o servidor os expõe em `$this->form` só durante a action.
     */
    function _dfEditScope(el) {
        const box = el.closest('[data-df-fields]');
        if (!box) return null;
        const name = box.getAttribute('data-df-name') || '';
        if (!name) return null;

        const values = {};
        box.querySelectorAll('input[name], select[name], textarea[name]').forEach(inp => {
            const field = inp.getAttribute('name').replace(/\[\]$/, '');
            if (!field || field.startsWith('__')) return;
            if (inp.type === 'checkbox' && !inp.checked) { values[field] = ''; return; }
            if (inp.type === 'radio'    && !inp.checked) return;
            values[field] = inp.value;
        });
        return { name, values };
    }

    /**
     * Hidden do campo mascarado (numeric/money) quando `el` é o input VISÍVEL.
     *
     * Nesses campos o `name` mora no hidden: o visível só mostra a máscara. O
     * valor que vale — cru, com ponto decimal — é o do hidden.
     */
    function _maskedHidden(el) {
        if (el.getAttribute('name')) return null;
        const box = el.closest('.mad-field');
        return box ? box.querySelector('input[type="hidden"][name]') : null;
    }

    /**
     * Divide a lista de argumentos no nível de TOPO: vírgula dentro de string,
     * array, objeto ou parênteses não separa.
     */
    function _splitTopLevelArgs(src) {
        const out = [];
        let depth = 0, quote = '', cur = '';
        for (let i = 0; i < src.length; i++) {
            const c = src[i];
            if (quote) {
                cur += c;
                if (c === '\\') { cur += (src[i + 1] || ''); i++; continue; }
                if (c === quote) quote = '';
                continue;
            }
            if (c === '"' || c === "'") { quote = c; cur += c; continue; }
            if (c === '[' || c === '{' || c === '(') depth++;
            else if (c === ']' || c === '}' || c === ')') depth--;
            else if (c === ',' && depth === 0) { out.push(cur); cur = ''; continue; }
            cur += c;
        }
        out.push(cur);
        return out;
    }

    /**
     * Último fallback do _parseAction: argumento a argumento, e o token que não
     * for JSON válido vale como STRING.
     *
     * Sem isto uma PK de texto — `onDelete(0198f1e2-a4b1-73c2-9f10-...)` — fazia
     * os dois JSON.parse falharem e o parser devolvia `params: []`: o servidor
     * recebia a action SEM o id (ArgumentCountError) e o botão Excluir não
     * excluía nada, calado. Token JSON válido continua passando pelo caminho de
     * cima — este só entra quando o raw já não parseava.
     */
    function _looseArgs(src) {
        return _splitTopLevelArgs(src).map(tok => {
            const t = tok.trim();
            if (t === '') return '';
            try { return JSON.parse(t); } catch { /* não é JSON */ }
            try { return JSON.parse(t.replace(/'/g, '"')); } catch { /* nem com aspas duplas */ }
            // Token nu (UUID, ULID, código): string literal.
            return t.replace(/^['"]/, '').replace(/['"]$/, '');
        });
    }

    function _parseAction(raw) {
        const match = raw.match(/^([\w$]+)\s*\((.+)\)\s*$/s);
        if (!match) return { action: raw.trim(), params: [] };
        let params;
        try {
            // Tenta JSON nativo (aspas duplas)
            params = JSON.parse('[' + match[2] + ']');
        } catch {
            try {
                // Fallback: converte aspas simples → duplas (ex: onSort('campo'))
                const normalized = match[2].replace(/'/g, '"');
                params = JSON.parse('[' + normalized + ']');
            } catch {
                // Último recurso: argumento a argumento, token nu vira string.
                params = _looseArgs(match[2]);
            }
        }
        return { action: match[1].trim(), params };
    }

    /**
     * Detecta chamadas $set no raw do mad:click.
     *
     * Formas suportadas:
     *   $set('prop', valor)                        → seta uma prop
     *   $set({'prop1': valor1, 'prop2': valor2})   → seta várias props
     *
     * Retorna objeto { chave: valor } ou null se não for $set.
     */
    function _parseSet(raw) {
        const match = raw.match(/^\$set\s*\(\s*(.+)\s*\)$/s);
        if (!match) return null;
        try {
            const parsed = JSON.parse('[' + match[1] + ']');

            // $set({'periodo': 'dia', 'categoria': 'todas'})
            if (parsed.length === 1 && typeof parsed[0] === 'object' && !Array.isArray(parsed[0])) {
                return parsed[0];
            }

            // $set('periodo', 'dia')
            if (parsed.length >= 2) {
                return { [String(parsed[0])]: parsed[1] };
            }
        } catch {}
        return null;
    }

    // ── Coleta valores de data-mad-model ─────────────────────────────────────

    function _collectModelValues(wrapper, sourceForm = null) {
        const result = {};
        // _componentNodes: inclui os campos do painel TELEPORTADO (drawer) do
        // componente — os `closest` abaixo atravessam o teleport pelo mesmo motivo.
        _componentNodes(wrapper, '[data-mad-model], [data-mad-model-live]').forEach(el => {
            const prop = (el.dataset.madModel || el.dataset.madModelLive || '').trim();
            if (!prop) return;

            // Skip inputs inside a detail-form/field-list editor. Their values are
            // serialized as rows (mad_detail_forms / mad_field_lists), not as master
            // model values — otherwise the (often empty) inline editor field clobbers
            // a master field that shares the same name (ex.: master e item com "descricao").
            if (_closestAcross(el, '[data-mad-df-name]') || _closestAcross(el, '[data-mad-fl-name]')) return;

            // Campo desabilitado por "Desabilitar quando" (`disabled-when`) não
            // vai no Salvar — é o que a dica do painel e o changelog 5.89
            // prometem, e o servidor trata chave ausente como "não mexer". O
            // `disabled` fixo do HTML não tem voto e continua indo.
            if (_whenDisabled(el)) return;

            // Skip models de MadComponents ANINHADOS (ex.: panes de dashboard
            // injetados dentro de um container): pertencem ao wire do filho,
            // não ao do wrapper que está submetendo.
            if (_closestAcross(el, '[mad-component]') !== wrapper) return;

            // When a sourceForm is provided, skip elements inside OTHER forms
            // (prevents duplicate model names from overwriting each other)
            if (sourceForm) {
                const elForm = _closestAcross(el, 'form[data-mad-submit]');
                if (elForm && elForm !== sourceForm) return;
            }

            // Checklist: coleta IDs dos checkboxes marcados como JSON array
            // + coleta outros inputs nomeados dentro do checklist (ex: slot columns)
            //
            // As marcas vêm do ESTADO do componente (madChecklist.selection():
            // a lista inteira), não das caixas desenhadas: com a busca
            // preenchida ou o filtro "somente selecionados" ligado, o que está
            // à mostra é só um pedaço da lista, e mandar só ele fazia o Salvar
            // desmarcar tudo o que a busca escondia. As caixas do DOM ficam de
            // reserva para a tela sem Alpine.
            if (el.classList.contains('mad-checklist')) {
                let state = null;
                try {
                    const ad = (window.Alpine && typeof Alpine.$data === 'function') ? Alpine.$data(el) : null;
                    if (ad && typeof ad.selection === 'function') state = ad.selection();
                } catch (e) { /* nó sem escopo Alpine */ }
                const fromDom = !Array.isArray(state);
                const checked = fromDom ? [] : state.map(String);
                const expectedName = prop + '[]';
                el.querySelectorAll('input[type="checkbox"][name]').forEach(cb => {
                    if (cb.name === expectedName) {
                        if (fromDom && cb.checked) checked.push(cb.value);
                    } else if (cb.checked) {
                        // Slot inputs: agrupa por name para envio direto no POST
                        const n = cb.name.replace(/\[\]$/, '');
                        if (!result.__clExtras) result.__clExtras = {};
                        if (!result.__clExtras[n]) result.__clExtras[n] = [];
                        result.__clExtras[n].push(cb.value);
                    }
                });
                result[prop] = JSON.stringify(checked);
                return;
            }

            // Checkbox group: coleta IDs dos checkboxes marcados como JSON array
            if (el.classList.contains('mad-checkbox-group')) {
                const checked = [];
                el.querySelectorAll('input[type="checkbox"]:checked').forEach(cb => {
                    if (cb.value) checked.push(cb.value);
                });
                result[prop] = JSON.stringify(checked);
                return;
            }

            // Radio group: coleta o valor do radio marcado
            if (el.dataset.madRadioGroup) {
                const checked = el.querySelector('input[type="radio"]:checked');
                result[prop] = checked ? checked.value : '';
                return;
            }

            // Multi-select: coleta todos os valores selecionados como JSON array.
            // O <select> nativo é a fonte da verdade (o MAD Select escreve sempre
            // nele), então lemos selectedOptions direto — sem modelo paralelo.
            if (el.tagName === 'SELECT' && el.multiple) {
                const selected = [];
                for (const opt of el.selectedOptions) {
                    if (opt.value) selected.push(opt.value);
                }
                result[prop] = JSON.stringify(selected);
                return;
            }

            result[prop] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
        });
        return result;
    }

    // Voto de disabled-when (x-mad-when:disabled, mad-ui.js) no controle — ou,
    // num grupo (rádio, checkbox, checklist), no primeiro controle de dentro.
    // Mesmo critério de _madWhen.disabledByWhen; repetido aqui para a coleta
    // não depender da ordem em que os scripts carregam.
    function _whenDisabled(el) {
        const voted = (c) => !!(c && c._madWhen && c._madWhen.disabled && c._madWhen.disabled.enforcing);
        if (voted(el)) return true;
        if (el.matches && el.matches('input, select, textarea')) return false;
        return voted(el.querySelector ? el.querySelector('input, select, textarea') : null);
    }

    // ── Executa scripts contidos em HTML (ex: erros do código legado) ─────────────

    function _execHtmlScripts(html) {
        const div = document.createElement('div');
        div.innerHTML = html;
        div.querySelectorAll('script').forEach(src => {
            const s = document.createElement('script');
            s.textContent = src.textContent;
            document.body.appendChild(s).remove();
        });
    }

    // ── Erro de servidor (500 / página HTML do Laravel) ──────────────────────

    function _madErrorPage(text) {
        return !!(window.MadErrorModal && window.MadErrorModal.isErrorPage(text));
    }

    /** Contexto do componente pro relatório de erro (MadErrorModal). */
    function _errContext(wrapper, action, params, models) {
        return {
            source:    'MadWire',
            method:    'POST',
            component: wrapper ? (wrapper.getAttribute('mad-component') || '') : '',
            action:    action || '',
            params:    params || [],
            models:    models || {},
        };
    }

    // ── Ops de lista (field-list / detail-form) ──────────────────────────────

    /**
     * fl_rows, fl_drop, df_add, df_delete, df_display e df_field_error — implementação
     * ÚNICA para os dois caminhos da resposta: o parcial (aqui no _requestNow)
     * e o redesenho completo (Mad.applyOps, em mad.js, depois do morph).
     *
     * Antes só o parcial os conhecia. Quando a ação do `before-add` mexia numa
     * prop array/objeto, o servidor redesenhava o componente inteiro; o df_add
     * ia para o Mad.applyOps, que não sabia o que era — a linha não entrava e
     * nada avisava.
     *
     * Devolve true quando o op é de lista (tratado aqui), false nos demais.
     */
    function _applyListOp(op, wrapper) {
        if (op.op === 'fl_rows') {
            // Substitui todas as rows de um field-list ou detail-form via Alpine
            const fl = _ownedNode(wrapper, `[data-mad-fl-name="${op.target}"]`)
                    || _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (fl && window.Alpine) {
                try {
                    const ad = Alpine.$data(fl);
                    if (ad) {
                        let rows = op.rows || [];
                        // Field-list: as células de dinheiro / número / desconto /
                        // arquivo guardam o OBJETO da linha do momento em que nasceram.
                        // Linha que volta do servidor com o MESMO __id é reaproveitada
                        // pelo x-for (mesma chave) e a célula fica presa ao objeto
                        // antigo: mostrava o valor velho e o que se digitava ia para
                        // uma linha que já não estava na lista (sumia no salvar).
                        // Chave nova = a linha renasce com as células ligadas a ela.
                        if (fl.hasAttribute('data-mad-fl-name')) {
                            const atuais = new Set((ad.rows || []).map(r => r && r.__id));
                            rows = rows.map(r => (r && atuais.has(r.__id))
                                ? { ...r, __id: Date.now().toString(36) + Math.random().toString(36).slice(2) }
                                : r);
                        }
                        ad.rows = rows;
                    }
                } catch(e) { console.error('[MadWire] fl_rows error:', e); }
            }
        } else if (op.op === 'fl_drop') {
            // Tira da Lista de itens / Detail Form as linhas que o Salvar não
            // regravou porque outra aba (ou outra pessoa) já as tinha removido.
            // Casadas por __id. Sem isto a linha ficava na tela até o F5, e cada
            // Salvar repetia o aviso "Itens já removidos".
            const el = _ownedNode(wrapper, `[data-mad-fl-name="${op.target}"]`)
                    || _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (el && window.Alpine) {
                try {
                    const ad  = Alpine.$data(el);
                    const ids = new Set((op.ids || []).map(String));
                    if (ad && Array.isArray(ad.rows) && ids.size) {
                        // Detail Form: a linha aberta no editor é seguida pelo __id.
                        const hasEdit   = typeof ad.editIndex === 'number' && ad.editIndex >= 0;
                        const editingId = hasEdit && ad.rows[ad.editIndex] ? ad.rows[ad.editIndex].__id : null;
                        ad.rows = ad.rows.filter(r => !(r && ids.has(String(r.__id))));
                        if (hasEdit) {
                            const at = ad.rows.findIndex(r => r && r.__id === editingId);
                            if (at >= 0) ad.editIndex = at;
                            else if (typeof ad._resetForm === 'function') ad._resetForm();
                        }
                    }
                } catch(e) { console.error('[MadWire] fl_drop error:', e); }
            }
        } else if (op.op === 'df_add') {
            // Insere/atualiza uma row no detail-form (resposta do before-add)
            const df = _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (!df) {
                console.warn('[MadWire] df_add: detail-form "' + op.target + '" não encontrado no componente — a linha não foi inserida.');
            } else if (window.Alpine) {
                try {
                    const ad = Alpine.$data(df);
                    if (ad) {
                        const row = { ...op.row };
                        if (op.editIndex >= 0 && op.editIndex < ad.rows.length) {
                            row.__id = ad.rows[op.editIndex].__id;
                            ad.rows[op.editIndex] = row;
                        } else {
                            row.__id = row.__id || (Date.now().toString(36) + Math.random().toString(36).slice(2));
                            ad.rows.push(row);
                        }
                        ad._resetForm();
                        ad._closeFormOverlay();
                        if (typeof _madLucide === 'function') _madLucide();
                    }
                } catch(e) { console.error('[MadWire] df_add error:', e); }
            }
        } else if (op.op === 'df_delete') {
            // Remove uma row do detail-form (resposta do before-delete)
            const df = _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (!df) {
                console.warn('[MadWire] df_delete: detail-form "' + op.target + '" não encontrado no componente.');
            } else if (window.Alpine) {
                try {
                    const ad = Alpine.$data(df);
                    if (ad && op.index >= 0 && op.index < ad.rows.length) {
                        ad.rows.splice(op.index, 1);
                        if (ad.editIndex === op.index) ad._resetForm();
                        else if (ad.editIndex > op.index) ad.editIndex--;
                    }
                } catch(e) { console.error('[MadWire] df_delete error:', e); }
            }
        } else if (op.op === 'df_display') {
            // Preenche as colunas de APRESENTAÇÃO de uma row já
            // inserida — caminho de relacionamento ({produto->nome})
            // e template composto, que só o servidor resolve.
            // Casado por __id, não por índice: o usuário pode ter
            // mexido na lista durante o round-trip.
            const df = _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (df && window.Alpine) {
                try {
                    const ad = Alpine.$data(df);
                    const r  = ad && Array.isArray(ad.rows)
                        ? ad.rows.find(x => x && x.__id === op.rowId)
                        : null;
                    if (r) {
                        const vals = op.values || {};
                        Object.keys(vals).forEach(k => { r[k] = vals[k]; });
                    }
                } catch(e) { console.error('[MadWire] df_display error:', e); }
            }
        } else if (op.op === 'df_field_error') {
            // Erro de campo scoped ao detail-form (não afeta campos master com mesmo name).
            // mode=drawer: o sub-form é teleportado pro body (x-teleport do <x-drawer>)
            // e sai de dentro de [data-mad-df-name] — o fallback global por
            // [data-df-fields][data-df-name] acha o container teleportado
            // (mesma estratégia do _getFieldsContainer no madDetailForm).
            const df = _ownedNode(wrapper, `[data-mad-df-name="${op.target}"]`);
            if (df) {
                const scope = df.querySelector('[data-df-fields]')
                    || document.querySelector(`[data-df-fields][data-df-name="${op.target}"]`)
                    || df;
                const errEl = scope.querySelector(`[data-field-error="${op.field}"]`);
                if (errEl) { errEl.innerHTML = op.message; errEl.classList.add('mad-error'); }
                const inp = scope.querySelector(`#${op.field}`) || scope.querySelector(`[name="${op.field}"]`);
                if (inp) inp.classList.add('mad-input-error');
            }
        } else {
            return false;
        }
        return true;
    }

    /**
     * Ops de uma resposta PARCIAL do wire, no escopo do componente: `bind` (os
     * <span> do @madBind e as props do @madWire), ops de lista, `fl_combo`,
     * `close_overlay`; o resto vai pro Mad.applyOps com o componente de escopo.
     *
     * Usado pelo _requestNow e — via MadWire.applyOps — pelos POSTs que o
     * mad-ui.js faz direto (eventos on-add / on-remove / on-totalize e cascata
     * do <mad-field-list>). Antes esses mandavam tudo para o Mad.applyOps, que
     * não conhece `bind`: o resumo de um on-totalize (@madBind) não mudava.
     */
    function _applyWireOps(ops, wrapper, componentId = '') {
        const data = { id: componentId || (wrapper && wrapper.getAttribute ? wrapper.getAttribute('mad-id') : '') };
        // As ops vão uma a uma para o Mad.applyOps: o lote inteiro (toast +
        // redirect do Após salvar) só é visto aqui.
        if (typeof Mad !== 'undefined' && Mad._flashBeforeLeaving) Mad._flashBeforeLeaving(ops);
        (ops || []).forEach(op => {
            if (op.op === 'bind') {
                // Atualiza só o span gerado por @madBind('prop')
                _componentNodes(wrapper, `[data-mad-bind="${op.prop}"]`).forEach(el => {
                    el.innerHTML = op.content;
                });
                // Tambem atualiza Alpine state em qualquer [x-data] do wrapper
                // que contenha a prop (gerado por @madWire).
                if (window.Alpine) {
                    const _coerce = (cur, next) => {
                        if (typeof cur === 'number') {
                            const n = Number(next);
                            return Number.isNaN(n) ? next : n;
                        }
                        if (typeof cur === 'boolean') {
                            return next === true || next === 'true' || next === '1' || next === 1;
                        }
                        return next;
                    };
                    const _patch = (el) => {
                        try {
                            const ad = Alpine.$data(el);
                            if (ad && Object.prototype.hasOwnProperty.call(ad, op.prop)) {
                                ad[op.prop] = _coerce(ad[op.prop], op.content);
                            }
                        } catch (e) { /* el sem Alpine scope */ }
                    };
                    _patch(wrapper);
                    wrapper.querySelectorAll('[x-data]').forEach(_patch);
                }
            } else if (_applyListOp(op, wrapper)) {
                // fl_rows / df_add / df_delete / df_display / df_field_error
                // — tratados em _applyListOp (mesmo código do redesenho completo).
            } else if (op.op === 'fl_combo') {
                // setItems('campo[]') — aplica em TODAS as rows dos field-lists
                if (typeof _madFlApplyComboAll === 'function') {
                    _madFlApplyComboAll(wrapper, op.target, op.options);
                }
            } else if (op.op === 'close_overlay') {
                // Componente anexado à linha da grid (row-attach):
                // "fechar" = remover a <tr> de attach, não há drawer.
                const attachTr = wrapper.closest && wrapper.closest('tr.mad-dg-attach-row');
                if (attachTr) {
                    attachTr.remove();
                } else {
                    const evName = op.type === 'modal' ? 'madmodal' : 'maddrawer';
                    window.dispatchEvent(new CustomEvent(evName, {
                        detail: { name: data.id, action: 'close' }
                    }));
                }
            } else if (typeof Mad !== 'undefined' && Mad.applyOps) {
                // Escopo = este componente: erro de campo e set() acham o
                // campo DELE antes de um homônimo da página (ver _opTarget).
                Mad.applyOps([op], wrapper);
            }
        });
    }

    // ── Loading ──────────────────────────────────────────────────────────────

    function _setLoading(wrapper, active, sourceForm = null) {
        _componentNodes(wrapper, '[data-mad-loading]').forEach(el => {
            el.style.display = active ? '' : 'none';
        });
        _componentNodes(wrapper, '[data-mad-loading-remove]').forEach(el => {
            el.style.display = active ? 'none' : '';
        });
        _componentNodes(wrapper, '[data-mad-click]').forEach(el => {
            el.disabled = active;
        });
        // Submit buttons: if a sourceForm was provided (real submit event),
        // mark only its submits; otherwise mark all submits inside any
        // data-mad-submit form within this wrapper (defensive default).
        const formsToTreat = sourceForm
            ? [sourceForm]
            : _componentNodes(wrapper, 'form[data-mad-submit]');
        formsToTreat.forEach(form => {
            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
                btn.disabled = active;
                btn.classList.toggle('mad-btn-loading-active', active);
            });
        });
    }

    function _initLoadingElements(wrapper) {
        wrapper.querySelectorAll('[data-mad-loading]').forEach(el => {
            el.style.display = 'none';
        });
    }

    // ── Requisição HTTP ──────────────────────────────────────────────────────

    // Fila por componente: um clique durante request em voo lia o mad-state
    // ANTIGO do wrapper e a ultima resposta clobberava a primeira (drill-down
    // de chart perdido durante auto-refresh, por ex.). Serializa: cada request
    // espera o anterior do mesmo wrapper e re-resolve o elemento pelo mad-id
    // caso o morph tenha trocado o node.
    const _inflightByWrapper = new WeakMap();

    // callOnce(): pedidos pendentes (na fila ou em voo) por componente e chave.
    const _onceByWrapper = new WeakMap();

    function _request(wrapper, action = '', params = [], extraModels = {}, sourceForm = null, opts = {}) {
        const madId = wrapper.getAttribute('mad-id');
        wrapper.setAttribute('data-mad-busy', '1');
        const clearBusy = () => {
            wrapper.removeAttribute('data-mad-busy');
            if (madId) {
                document.querySelectorAll(`[mad-id="${madId}"]`).forEach(el => el.removeAttribute('data-mad-busy'));
            }
        };
        const prev = _inflightByWrapper.get(wrapper) || Promise.resolve();
        const run  = prev.then(() => {
            let target = wrapper;
            if (!target.isConnected && madId) {
                target = document.querySelector(`[mad-id="${madId}"]`) || target;
            }
            return _requestNow(target, action, params, extraModels, sourceForm, opts);
        }).then(v => { clearBusy(); return v; }, err => { clearBusy(); throw err; });
        _inflightByWrapper.set(wrapper, run.then(() => {}, () => {}));
        return run;
    }

    async function _requestNow(wrapper, action = '', params = [], extraModels = {}, sourceForm = null, opts = {}) {
        const state    = wrapper.getAttribute('mad-state');
        const id       = wrapper.getAttribute('mad-id');
        const endpoint = wrapper.getAttribute('mad-endpoint');
        const models   = { ..._collectModelValues(wrapper, sourceForm), ...extraModels };

        if (!endpoint) {
            console.error('[MadWire] mad-endpoint não encontrado no wrapper', wrapper);
            return { ok: false, reason: 'config' };
        }

        _setLoading(wrapper, true, sourceForm);

        const body = new FormData();
        body.append('mad_state', state);
        body.append('mad_id',    id);
        if (action)        body.append('mad_action', action);
        // params pode ser array (posicional) ou objeto (resolvido por nome no PHP)
        const _hasParams = Array.isArray(params)
            ? params.length > 0
            : (params && typeof params === 'object' && Object.keys(params).length > 0);
        if (_hasParams) body.append('mad_params', JSON.stringify(params));
        // Extras de checklists (slot inputs) vão direto no POST, não dentro de mad_model
        const clExtras = models.__clExtras || {};
        delete models.__clExtras;
        Object.entries(models).forEach(([k, v]) => body.append(`mad_model[${k}]`, v));
        for (const [n, vals] of Object.entries(clExtras)) {
            vals.forEach(v => body.append(`${n}[]`, v));
        }

        // Inclui o token do schema de formulário se presente no componente
        // (necessário para MadForm.getData() transformar datas, checkboxes, etc.)
        // O do formulário que disparou vem primeiro: o de um painel teleportado
        // não está no wrapper, e o primeiro do wrapper é o de OUTRO form.
        const tokenForm = sourceForm || opts.tokenForm || null;
        const formToken = (tokenForm && tokenForm.querySelector('input[name="__mad_form"]'))
            || wrapper.querySelector('input[name="__mad_form"]')
            || _teleportedNodes(wrapper, 'input[name="__mad_form"]')[0]
            || null;
        if (formToken) body.append('__mad_form', formToken.value);

        // Coleta labels dos campos para usar em mensagens de validação.
        //
        // A chave TEM que ser o NOME do campo: é por ele que o
        // `MadForm::validate()` acha o rótulo (`$attrs[$field]`). Este primeiro
        // laço keia pelo `for`, que nos `*-field` do kit é um id ALEATÓRIO
        // (`mad_nome_4821`) — nunca casava, e a mensagem saía com o nome da
        // coluna ("O campo name é obrigatório") em todo formulário do produto.
        // Fica por retrocompatibilidade: componente de terceiro que use
        // `for="<nome>"` continua funcionando.
        _componentNodes(wrapper, 'label.mad-label[for]').forEach(lbl => {
            const field = lbl.getAttribute('for');
            if (field) {
                const text = lbl.textContent.replace(/\s*\*\s*$/, '').trim();
                if (text) body.append(`mad_labels[${field}]`, text);
            }
        });

        // Laço AUTORITATIVO (depois do de cima de propósito: em `mad_labels[x]`
        // repetido o PHP fica com o último). Pega o rótulo pelo wrapper
        // `.mad-field` e o nome pelo `data-mad-field` — ou, quando o componente
        // não o declara, pelo `[name]` de dentro.
        _componentNodes(wrapper, '.mad-field').forEach(field => {
            const lbl = field.querySelector('.mad-label');
            if (!lbl) return;

            const text = lbl.textContent.replace(/\s*\*\s*$/, '').trim();
            if (!text) return;

            let name = field.getAttribute('data-mad-field');
            if (!name) {
                const input = field.querySelector('[name]');
                name = input ? (input.getAttribute('name') || '') : '';
            }
            // Grupos (checkbox-group, multi-file) postam `campo[]`; a regra de
            // validação é sobre `campo`.
            name = name.replace(/\[\]$/, '');
            if (!name) return;

            body.append(`mad_labels[${name}]`, text);
        });

        // Editor do detail-form que disparou o mad:change — bucket próprio, fora
        // do mad_model (ver _dfEditScope). O PHP expõe em $this->form durante a
        // action e devolve os set() como `val` mirado no sub-form.
        if (opts.dfScope && opts.dfScope.name) {
            body.append('mad_df_scope', opts.dfScope.name);
            body.append('mad_df_edit',  JSON.stringify(opts.dfScope.values || {}));
        }

        // Coleta dados de field-lists (rows Alpine) se houver
        _madCollectFieldLists(wrapper, body);

        // Coleta dados de detail-forms (rows Alpine) se houver
        _madCollectDetailForms(wrapper, body);

        // Coleta arquivos de file-fields (mad-file-field, mad-multi-file-field)
        _componentNodes(wrapper, 'input[type="file"]').forEach(function(input) {
            if (input.files && input.files.length > 0) {
                for (var i = 0; i < input.files.length; i++) {
                    body.append(input.name, input.files[i], input.files[i].name);
                }
            }
        });

        // Coleta hidden inputs de controle de arquivos (__mad_file_removed, __mad_existing_files)
        // E também __mad_db_blocks_state, etc.
        // IMPORTANTE: pula inputs que estao dentro de OUTRO <form data-mad-submit>
        // que nao seja o sourceForm (evita coletar state de outros mad-db-blocks
        // na mesma pagina). Sem sourceForm, coleta tudo (comportamento legado).
        _componentNodes(wrapper, 'input[type="hidden"][name^="__mad_"]').forEach(function(input) {
            var val = input.value;
            if (val === undefined || val === '') return;
            // Se o input esta dentro de um form data-mad-submit, so usa se for o sourceForm
            var parentForm = _closestAcross(input, 'form[data-mad-submit]');
            if (parentForm && sourceForm && parentForm !== sourceForm) return;
            if (parentForm && !sourceForm) return; // ignora forms se nao foi submit
            body.append(input.name, val);
        });

        // CSRF: token do <meta name="csrf-token"> (injetado no full-load).
        // Enviado no header X-CSRF-TOKEN; o entry point do wire valida.
        const _csrfMeta  = document.querySelector('meta[name="csrf-token"]');
        const _csrfToken = _csrfMeta ? (_csrfMeta.getAttribute('content') || '') : '';
        const _headers   = { 'X-Requested-With': 'XMLHttpRequest' };
        if (_csrfToken) _headers['X-CSRF-TOKEN'] = _csrfToken;
        // Componente que mora num <mad-transporter>: o redesenho dele também
        // sai sem o casco de página (MadTransporter::embedHeader), como no GET.
        const _embedTp = wrapper.closest ? wrapper.closest('.mad-transporter') : null;
        if (_embedTp) _headers['X-Mad-Embed'] = _embedTp.getAttribute('data-mad-embed-header') || 'compact';

        // Desfecho da chamada, devolvido a quem chamou (MadWire.call): `ok` =
        // o servidor respondeu e a resposta foi aplicada; senão `reason` diz
        // por quê — 'network' (sem resposta), 'server' (página de erro, já
        // mostrada), 'error' (recusa, já avisada), 'client' (a resposta chegou
        // e falhou ao ser aplicada). Quem grava em silêncio (edição na célula
        // da listagem) usa isto para avisar quando o valor NÃO foi gravado.
        let _answered = false;
        try {
            const res  = await fetch(endpoint, { method: 'POST', body, headers: _headers });
            _answered = true;

            // Container do Teste Online em repouso: o nginx de fallback devolve a
            // pagina "em repouso" INTEIRA (200 + header X-Mad-Offline:1) para
            // QUALQUER requisicao — inclusive este POST do wire. Sem este guard
            // o corpo cai no ramo "HTML legado" abaixo e os scripts/estilos da
            // tela de repouso rodam dentro do app (layout destruido). Recarrega:
            // o host adormecido devolve a tela de repouso como documento.
            if (res.headers.get('X-Mad-Offline') === '1') {
                window.location.reload();
                return { ok: false, reason: 'offline' };
            }

            const text = await res.text();

            // Resposta HTML (ex: erro do código legado com __mad_error) — executa os scripts
            let data;
            try {
                data = JSON.parse(text);
            } catch (_) {
                _setLoading(wrapper, false, sourceForm);
                // 413: o envio passou do limite do servidor (do PHP ou do
                // servidor web, antes de chegar ao app). Aviso ao usuário, não a
                // página de erro crua.
                // O app responde em JSON com o tamanho enviado e o limite; com
                // `display_errors` ligado o PHP escreve um aviso dele ANTES do
                // JSON — por isso a resposta é procurada no fim do corpo.
                if (res.status === 413) {
                    let notice = null;
                    const at = text.lastIndexOf('{"error"');
                    if (at !== -1) { try { notice = JSON.parse(text.slice(at)); } catch (_) {} }
                    const tooBig = (notice && notice.error)
                        || 'O envio passou do limite que o servidor aceita de uma vez. Nada foi salvo: envie arquivos menores ou em menos arquivos por vez.';
                    if (typeof __mad_warning === 'function') __mad_warning((notice && notice.title) || 'Envio acima do limite', tooBig);
                    else alert(tooBig);
                    return { ok: false, reason: 'error' };
                }
                const errCtx = _errContext(wrapper, action, params, models);
                // Página de erro do Laravel/PHP (500 com HTML) — antes os scripts
                // rodavam e NADA aparecia: o dev só via no DevTools. Agora abre o
                // modal 90×90 com a página, o texto e o markdown pro agente de IA.
                // Vale também para o erro de tela marcado pelo framework, que
                // pode vir com status 200 (ver _isErrorPage).
                if ((!res.ok || _madErrorPage(text)) && window.MadErrorModal) {
                    window.MadErrorModal.showResponse(res, text, errCtx);
                    return { ok: false, reason: 'server' };
                }
                // Legado: HTML com <script> (ex.: __mad_error(...) de código antigo)
                // — os scripts são a resposta, rodam como sempre.
                if (/<script\b/i.test(text)) {
                    _execHtmlScripts(text);
                    return { ok: false, reason: 'server' };
                }
                // Nem JSON, nem script (ex.: ação que ecoou uma página e encerrou,
                // corpo vazio): antes isto era descartado e a ação "sumia" — o
                // usuário ficava esperando um resultado que nunca aparecia.
                if (window.MadErrorModal) {
                    window.MadErrorModal.showResponse(
                        { status: res.status, statusText: 'Resposta inesperada do servidor', url: res.url || endpoint },
                        text, errCtx);
                } else if (typeof __mad_error === 'function') {
                    __mad_error('Exceção', 'Resposta inesperada do servidor.');
                } else {
                    alert('Resposta inesperada do servidor.');
                }
                return { ok: false, reason: 'server' };
            }

            // Envia dados de debug para o console (se ativo)
            if (data._debug && typeof System !== 'undefined' && System.addDebug) {
                data._debug.forEach(d => System.addDebug(d));
            }

            if (data.error) {
                console.error('[MadWire] Erro do servidor:', data.error);
                _setLoading(wrapper, false, sourceForm);
                // Exception não tratada com payload de debug (APP_DEBUG=true):
                // modal grande com trace + markdown, em vez do alert de 1 linha.
                if (data._exception && window.MadErrorModal) {
                    window.MadErrorModal.show({
                        status:     res.status,
                        statusText: res.statusText,
                        url:        endpoint,
                        method:     'POST',
                        body:       '',
                        exception:  data._exception,
                        context:    _errContext(wrapper, action, params, models),
                    });
                    return { ok: false, reason: 'error' };
                }
                // Sessão expirada / acesso negado no modo web: o servidor pode
                // mandar uma URL de redirect (ex.: login). Navega em vez de
                // deixar o usuário preso num popup de erro.
                if (data.redirect) {
                    window.location.href = (typeof window.MadWebRoute === 'function')
                        ? window.MadWebRoute(data.redirect) : data.redirect;
                    return { ok: false, reason: 'error' };
                }
                // Recusa por permissão (403) é regra do perfil, não pane: aviso
                // com o título que o servidor manda ("Sem permissão"). Antes
                // saía um diálogo de ERRO intitulado "Exceção".
                // Idem para a recusa que é aviso ao usuário (`warning`): o envio
                // acima do limite do servidor diz o tamanho e o limite.
                if (data.warning && typeof __mad_warning === 'function') {
                    __mad_warning(data.title || 'Aviso', data.error);
                } else if ((res.status === 403 || data.forbidden) && typeof __mad_warning === 'function') {
                    __mad_warning(data.title || 'Sem permissão', data.error);
                } else if (typeof __mad_error === 'function') {
                    __mad_error('Exceção', data.error);
                } else {
                    alert('Erro: ' + data.error);
                }
                return { ok: false, reason: 'error' };
            }

            if (data.partial) {
                // Atualiza o estado encriptado no wrapper (para a próxima ação)
                wrapper.setAttribute('mad-state', data.mad_state);
                // Aplica ops — 'bind' é scoped ao wrapper; outros vão via Mad.applyOps
                _applyWireOps(data.ops || [], wrapper, data.id);
                _setLoading(wrapper, false, sourceForm);
                return { ok: true };
            }

            const fresh = _morph(wrapper, data.html) || wrapper;

            // Aplica ops tambem em full-render (ex: handler injeta um
            // script via _prependStrayOutputModal ou componente quer
            // disparar toast/dialog junto com o re-render).
            if (Array.isArray(data.ops) && data.ops.length && typeof Mad !== 'undefined' && Mad.applyOps) {
                try { await Mad.applyOps(data.ops, fresh); } catch (e) { console.error('[MadWire] ops error:', e); }
            }
            return { ok: true };
        } catch (err) {
            console.error('[MadWire] Erro de rede:', err);
            _setLoading(wrapper, false, sourceForm);
            if (!_answered && !opts.quietNetwork) _networkNotice();
            return { ok: false, reason: _answered ? 'client' : 'network' };
        }
    }

    // Queda de rede numa ação (Salvar, clique de botão, filtro): o erro ia só
    // para o console e o usuário ficava sem saber se a ação valeu. Um aviso
    // por vez — as ações que estavam na fila caem juntas. Quem já avisa do seu
    // jeito (edição na célula da listagem, Planilha) chama com quietNetwork.
    const _NETWORK_TEXT = {
        pt: ['Sem resposta do servidor', 'Não foi possível falar com o servidor. Confira a conexão e tente de novo.'],
        en: ['No answer from the server', 'Could not reach the server. Check your connection and try again.'],
        es: ['Sin respuesta del servidor', 'No fue posible comunicarse con el servidor. Revise la conexión e inténtelo de nuevo.'],
    };
    let _networkNoticeAt = 0;

    function _networkNotice() {
        const now = Date.now();
        if (now - _networkNoticeAt < 5000) return;
        _networkNoticeAt = now;
        const lang = String((document.documentElement && document.documentElement.lang) || '').slice(0, 2).toLowerCase();
        const [title, text] = _NETWORK_TEXT[lang] || _NETWORK_TEXT.pt;
        if (typeof window.madToast === 'function') {
            window.madToast({ message: text, type: 'danger', duration: 6000 });
        } else if (typeof __mad_warning === 'function') {
            __mad_warning(title, text);
        }
    }

    // ── DOM Morph ────────────────────────────────────────────────────────────

    function _execScripts(el) {
        // Mesmo caminho da navegacao (Mad._rerunScripts): copia todos os
        // atributos e desce no <template> de uma cortina teleportada.
        if (typeof Mad !== 'undefined' && Mad._rerunScripts) return Mad._rerunScripts(el);
        el.querySelectorAll('script').forEach(old => {
            const s = document.createElement('script');
            [...old.attributes].forEach(a => s.setAttribute(a.name, a.value));
            s.textContent = old.textContent;
            old.replaceWith(s);
        });
    }

    function _morph(oldWrapper, newHtml) {
        const temp = document.createElement('div');
        temp.innerHTML = newHtml;
        const newWrapper = temp.firstElementChild;
        if (!newWrapper) return;

        const focused     = document.activeElement;
        const focusedId   = focused?.id   || null;
        const focusedName = focused?.name || null;
        const focusedClass = focused?.classList?.contains('mad-dg-search') ? 'mad-dg-search' : null;
        const selStart    = focused?.selectionStart ?? null;
        const selEnd      = focused?.selectionEnd   ?? null;
        const focusedVal  = focused?.value ?? null;

        // Aba ativa (de toda <mad-tabs>, inclusive das telas embutidas) e
        // rolagem: o replace recria o Alpine e as abas voltariam à `default`.
        const uiState = (typeof Mad !== 'undefined' && Mad.captureUiState) ? Mad.captureUiState(oldWrapper) : null;

        oldWrapper.replaceWith(newWrapper);
        _initLoadingElements(newWrapper);
        newWrapper.querySelectorAll('form[data-mad-submit]').forEach(f => f.setAttribute('novalidate', ''));
        _execScripts(newWrapper);

        let refocus = null;
        if (focusedId)        refocus = document.getElementById(focusedId);
        else if (focusedName) refocus = newWrapper.querySelector(`[name="${focusedName}"]`);
        else if (focusedClass) refocus = newWrapper.querySelector(`.${focusedClass}`);

        if (refocus) {
            refocus.focus();
            if (focusedVal !== null) refocus.value = focusedVal;
            if (selStart !== null) try { refocus.setSelectionRange(selStart, selEnd); } catch {}
        }

        // Reinicializa componentes JS após morph
        if (typeof _madRewriteAlpineAttrs === 'function') _madRewriteAlpineAttrs(newWrapper);
        if (window.Alpine) try { Alpine.initTree(newWrapper); } catch {}
        if (typeof _madLucide === 'function')               _madLucide();
        // Sequencia unica de init de campo — ver _madEnergizeFields (mad-ui.js).
        // O morph troca os nos: os flags `_madMaskInit` / `_madForceInit` morrem
        // junto, entao a mascara precisa ser religada aqui.
        if (typeof _madEnergizeFields === 'function')       _madEnergizeFields(newWrapper);

        // Depois do initTree: o <mad-tabs> novo já tem o activeTab do Alpine.
        if (uiState) try { Mad.restoreUiState(newWrapper, uiState); } catch (e) { console.error('[MadWire] ui state:', e); }

        // O wrapper antigo saiu do DOM: quem aplica ops depois do morph precisa
        // do NOVO pra escopar os alvos (Mad.applyOps(ops, wrapper)).
        return newWrapper;
    }

    // ── Event delegation ─────────────────────────────────────────────────────

    const _debouncedHandlers = new WeakMap();

    document.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-mad-click]');
        if (!el) return;
        const wrapper = _getWrapper(el);
        if (!wrapper) return;
        e.preventDefault();

        // data-mad-confirm="msg" → confirma antes de disparar (usa madConfirm
        // se disponivel; fallback nativo). Re-click após ok evita loop pelo flag.
        const confirmMsg = el.getAttribute('data-mad-confirm');
        if (confirmMsg && !el.dataset.madConfirmed) {
            const ok = typeof window.madConfirm === 'function'
                ? await window.madConfirm(confirmMsg)
                : window.confirm(confirmMsg);
            if (!ok) return;
            el.dataset.madConfirmed = '1';
            setTimeout(() => { delete el.dataset.madConfirmed; }, 0);
        }

        const raw = (el.dataset.madClick || '').trim();

        // $refresh → envia models atuais e re-renderiza, sem action
        if (raw === '$refresh') {
            await _request(wrapper);
            return;
        }

        // $set('prop', valor) ou $set({...}) → envia como model values, sem action
        const setValues = _parseSet(raw);
        if (setValues) {
            await _request(wrapper, '', [], setValues);
            return;
        }

        // Botão de um formulário TELEPORTADO (ex.: "Atualizar" no drawer de
        // filtros): o token de schema é o desse form, que não está no wrapper.
        const clickForm = el.closest('form[data-mad-submit]');
        const tokenForm = clickForm && !wrapper.contains(clickForm) ? clickForm : null;

        // Botão DENTRO do editor de um <mad-detail-form> (`<mad-btn mad:click>`
        // entre os campos do sub-form): manda a linha em edição no mesmo bucket
        // do mad:change (ver _dfEditScope) — sem isso a action lia em
        // $this->form os campos do master, e a linha digitada não chegava.
        // Fora do editor _dfEditScope devolve null e nada muda.
        const { action, params } = _parseAction(raw);
        await _request(wrapper, action, params, {}, null, { tokenForm, dfScope: _dfEditScope(el) });
    });

    document.addEventListener('submit', async (e) => {
        const form = e.target;
        const raw  = form.dataset.madSubmit || '';
        if (!raw) return;
        const wrapper = _getWrapper(form);
        if (!wrapper) return;
        e.preventDefault();
        e.stopPropagation();
        // Obrigatório (`required` e `required-when`) vazio segura o Salvar e
        // avisa no próprio campo — o form é `novalidate`, então sem isto o
        // asterisco era só desenho (mad-ui.js, _madWhen.blockSubmit).
        if (window._madWhen && typeof window._madWhen.blockSubmit === 'function'
            && window._madWhen.blockSubmit(form)) return;
        const { action, params } = _parseAction(raw);
        await _request(wrapper, action, params, {}, form);
    });

    document.addEventListener('change', async (e) => {
        const el  = e.target;
        const raw = el.dataset.madChange || '';
        if (!raw) return;
        // Op do servidor que reescreveu o campo com o MESMO valor (lista do
        // combo trocada por setItems, `val` repetido): depends-on e Alpine
        // recebem o `change`, o On Change não — o valor não mudou (fw#139).
        if (e.madSameValue) return;
        const wrapper = _getWrapper(el);
        if (!wrapper) return;
        const { action, params } = _parseAction(raw);

        // Campo mascarado (numeric/money): o browser dispara `change` no input
        // VISÍVEL ANTES do @blur do Alpine gravar o hidden — ler agora manda o
        // valor ANTERIOR pro servidor. Um tick de macrotask basta (o @blur e o
        // $nextTick dele rodam antes de qualquer setTimeout).
        const hidden = _maskedHidden(el);
        if (hidden) await new Promise(r => setTimeout(r, 0));

        // Sempre adiciona o valor do elemento como ULTIMO parametro.
        // Permite tanto data-mad-change="onMethod" (so value) quanto
        // data-mad-change="onMethod(id1, id2)" (extra args + value).
        params.push(hidden ? hidden.value : el.value);
        await _request(wrapper, action, params, {}, null, { dfScope: _dfEditScope(el) });
    });

    document.addEventListener('input', (e) => {
        const el = e.target;
        if (!el.dataset.madModelLive) return;
        const wrapper = _getWrapper(el);
        if (!wrapper) return;
        if (!_debouncedHandlers.has(el)) {
            _debouncedHandlers.set(el, _debounce(w => _request(w, '', []), CONFIG.debounceDelay));
        }
        _debouncedHandlers.get(el)(wrapper);
    });

    // ── Init ─────────────────────────────────────────────────────────────────

    function _initAll() {
        document.querySelectorAll('[mad-component]').forEach(_initLoadingElements);
        // Forms MAD não usam validação HTML nativa: o servidor valida, e o
        // obrigatório vazio é segurado no submit (_madWhen.blockSubmit)
        document.querySelectorAll('form[data-mad-submit]').forEach(f => f.setAttribute('novalidate', ''));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _initAll);
    } else {
        _initAll();
    }

    // ── API pública ──────────────────────────────────────────────────────────

    return {
        configure(options = {}) { Object.assign(CONFIG, options); },

        /**
         * Wrapper [mad-component] dono de um elemento — atravessa teleports
         * (drawer/modal). Exposto pro Mad.call usar o mesmo critério.
         */
        resolveWrapper(el) { return el ? _getWrapper(el) : null; },

        /**
         * `closest('[mad-component]')` que atravessa teleports, SEM o fallback
         * "primeiro componente da página" do resolveWrapper — null quando o
         * elemento não pertence a componente nenhum.
         */
        closestComponent(el) { return el ? _closestAcross(el, '[mad-component]') : null; },

        /**
         * Raízes DOM do componente: o wrapper + os painéis que ele teleportou
         * pro <body> (drawer). Usado pelo Mad.applyOps para escopar alvos.
         */
        componentRoots(wrapper) { return wrapper ? _componentRoots(wrapper) : []; },

        /** Nós do componente que casam `sel`, incluindo os teleportados. */
        componentNodes(wrapper, sel) { return wrapper ? _componentNodes(wrapper, sel) : []; },

        /**
         * Listas do componente (`[data-mad-df-name]`, `[data-mad-fl-name]`),
         * inclusive as de um painel teleportado. Usado pelos coletores
         * (_madCollectDetailForms / _madCollectFieldLists).
         */
        ownedNodes(wrapper, sel) { return _ownedNodes(wrapper, sel); },

        /**
         * Aplica um op de lista (fl_rows / df_add / df_delete / df_display /
         * df_field_error) no componente. Usado pelo Mad.applyOps no redesenho
         * completo — o mesmo código do caminho parcial. True = era op de lista.
         */
        applyListOp(op, wrapper) { return wrapper ? _applyListOp(op, wrapper) : false; },

        /**
         * Ops de uma resposta do wire recebida FORA do _request (POSTs diretos
         * do mad-ui.js): mesmas regras do caminho parcial — `bind` incluído.
         */
        applyOps(ops, wrapper) { if (wrapper) _applyWireOps(ops, wrapper); },

        /**
         * Chama a ação do componente. `models` ({nome: valor}) vai junto dos
         * campos da tela em `mad_model` e vence o de mesmo nome — é por onde
         * os campos do diálogo (MadConfirm::field) chegam ao MadForm da ação.
         *
         * Resolve com o desfecho da chamada (`{ ok, reason }`, ver _requestNow)
         * depois que a resposta foi aplicada; `undefined` sem componente.
         *
         * Sem resposta do servidor o wire avisa o usuário; `options.quietNetwork`
         * cala esse aviso para quem dá o seu próprio a partir do desfecho.
         */
        async call(idOrEl, action = '', params = [], models = {}, options = {}) {
            const wrapper = typeof idOrEl === 'string'
                ? document.querySelector(`[mad-id="${idOrEl}"]`)
                : (_getWrapper(idOrEl) || idOrEl);
            const extra = models && typeof models === 'object' && !Array.isArray(models) ? models : {};
            const opts  = options && options.quietNetwork ? { quietNetwork: true } : {};
            if (wrapper) return await _request(wrapper, action, params, extra, null, opts);
        },

        /**
         * call() que não se repete: enquanto um pedido com a mesma `key` está
         * na fila ou em voo neste componente, o repetido é descartado e quem
         * chamou recebe a promise do pendente. Para clique que abre formulário
         * (agenda, Gantt): o duplo clique mandava dois pedidos, a fila rodava
         * os dois e cada resposta abria uma cortina lateral. Quando a resposta
         * chega, o Mad.overlay já pôs o "Aguarde" por cima da tela.
         */
        callOnce(idOrEl, action = '', params = [], key = action) {
            const wrapper = typeof idOrEl === 'string'
                ? document.querySelector(`[mad-id="${idOrEl}"]`)
                : (_getWrapper(idOrEl) || idOrEl);
            if (!wrapper) return Promise.resolve();
            let pending = _onceByWrapper.get(wrapper);
            if (!pending) { pending = new Map(); _onceByWrapper.set(wrapper, pending); }
            if (pending.has(key)) return pending.get(key);
            const run = _request(wrapper, action, params, {});
            const release = () => { if (pending.get(key) === run) pending.delete(key); };
            run.then(release, release);
            pending.set(key, run);
            return run;
        },

        /**
         * Põe `job(wrapper)` na fila do componente — a MESMA do call() / set()
         * / refresh(). Para quem fala com o wire por conta própria (eventos e
         * cascata do <mad-field-list>, em mad-ui.js): sem a fila, dois pedidos
         * corriam em paralelo e valia a resposta que chegasse por último, não
         * a do último pedido. `job` roda quando os pedidos anteriores do
         * componente terminaram, recebe o wrapper atual (o morph pode ter
         * trocado o nó) e devolve uma promise; o seguinte espera ela.
         */
        enqueue(idOrEl, job) {
            const wrapper = typeof idOrEl === 'string'
                ? document.querySelector(`[mad-id="${idOrEl}"]`)
                : idOrEl;
            if (!wrapper || typeof job !== 'function') return Promise.resolve();
            const madId = wrapper.getAttribute ? wrapper.getAttribute('mad-id') : null;
            const prev  = _inflightByWrapper.get(wrapper) || Promise.resolve();
            const run   = prev.then(() => {
                let target = wrapper;
                if (target.isConnected === false && madId) {
                    target = document.querySelector(`[mad-id="${madId}"]`) || target;
                }
                return job(target);
            });
            _inflightByWrapper.set(wrapper, run.then(() => {}, () => {}));
            return run;
        },

        /**
         * Seta prop(s) programaticamente e re-renderiza.
         *   MadWire.set(el, 'periodo', 'dia')
         *   MadWire.set(el, { periodo: 'dia', categoria: 'todas' })
         */
        async set(idOrEl, propOrObj, value) {
            const wrapper = typeof idOrEl === 'string'
                ? document.querySelector(`[mad-id="${idOrEl}"]`)
                : (_getWrapper(idOrEl) || idOrEl);
            if (!wrapper) return;
            const models = typeof propOrObj === 'object' ? propOrObj : { [propOrObj]: value };
            await _request(wrapper, '', [], models);
        },

        /**
         * Envia models atuais e re-renderiza o componente.
         * MadWire.refresh(el)
         */
        async refresh(idOrEl) {
            const wrapper = typeof idOrEl === 'string'
                ? document.querySelector(`[mad-id="${idOrEl}"]`)
                : (_getWrapper(idOrEl) || idOrEl);
            if (wrapper) await _request(wrapper);
        },

        /**
         * Reesconde [data-mad-loading] (e mostra [data-mad-loading-remove]) sob
         * `root` — chamar depois de injetar HTML novo via innerHTML (SPA nav,
         * Mad._reinit) que NUNCA passou pelo morph/_setLoading desta engine.
         * Sem isso o "Gerando…" (mad:loading) fica visível junto com o label
         * normal (mad:loading.remove) na primeira renderização da página.
         */
        initLoadingElements(root) { _initLoadingElements(root || document); },
    };

})();