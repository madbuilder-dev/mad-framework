/* ============================================================
   MadMail — classe JS fina do Correio interno.
   FONTE: packages/mad-framework/assets/mad-mail.js
   Servido em public/lib/mad/mad-mail.js (composer mad:sync) e carregado UMA vez
   em libraries.html (um <script src> injetado via AJAX seria ignorado por
   mad.js::_reinit; a view só roda um boot inline MadMail.init()).

   Responsabilidades (resto é server-driven via mad:click/mad:model):
     · janela de composição flutuante (abrir/min/expandir/fechar)
     · sincronizar data-theme do app no .mad-mailbox
     · realtime: assinar canal privado (Reverb/Echo) → badge + refresh da lista
     · toasts client-side de mensagens recebidas
   ============================================================ */
(function () {
    'use strict';

    if (window.MadMail) { /* re-define idempotente abaixo via init() */ }

    var S = {
        cfg: null,
        echoBound: null,   // nome do canal já assinado (evita assinatura dupla)
        themeObserver: null,
    };

    function $(id) { return document.getElementById(id); }
    function mailbox() { return $('madMailbox'); }
    function compose() { return $('madCompose'); }

    function syncTheme() {
        var t = document.documentElement.getAttribute('data-theme') || 'light';
        var box = mailbox();
        if (box) box.setAttribute('data-theme', t);
        var croot = $('madComposeRoot'); // shell global do compose
        if (croot) croot.setAttribute('data-theme', t);
    }

    function relucide() {
        if (window.lucide && lucide.createIcons) {
            try { lucide.createIcons(); } catch (e) {}
        }
    }

    var MadMail = {

        /** Chamado pelo boot inline a cada carga da tela (idempotente). */
        init: function (cfg) {
            S.cfg = cfg || S.cfg || {};
            syncTheme();

            // Observa troca de tema do app e espelha no mailbox.
            if (!S.themeObserver) {
                S.themeObserver = new MutationObserver(syncTheme);
                S.themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
            }

            this.setupEcho();
            relucide();
        },

        // ── composição flutuante (shell global #madCompose em layout.html) ─────

        openCompose: function (opts) {
            opts = opts || {};
            var el = compose();           // #madCompose vive no shell global (uma vez)
            if (!el) return;

            var title = 'Nova mensagem';
            var params = {};
            if (opts.forwardId) { title = 'Encaminhar'; params.forwardId = opts.forwardId; }
            else if (opts.draftId) { title = 'Rascunho'; params.draftId = opts.draftId; }

            var ttl = $('madComposeTitle');
            if (ttl) ttl.textContent = title;

            el.classList.remove('min', 'max');
            el.removeAttribute('hidden');

            // Carrega o form direto no host (sem transporter → sem listener
            // mad:energize acumulado a cada _morph da tela).
            if (window.Mad && Mad.get) {
                Mad.get('MessageForm@onCompose', params, '#madComposeHost');
            }
            relucide();
        },

        closeCompose: function () {
            var el = compose();
            if (el) el.setAttribute('hidden', '');
        },

        isComposeOpen: function () {
            var el = compose();
            return el && !el.hasAttribute('hidden');
        },

        toggleMin: function () {
            var el = compose();
            if (el) { el.classList.toggle('min'); el.classList.remove('max'); }
        },

        toggleMax: function () {
            var el = compose();
            if (el) { el.classList.toggle('max'); el.classList.remove('min'); }
        },

        /** Pós-envio/rascunho: fecha a janela e atualiza a lista. */
        afterSend: function () {
            this.closeCompose();
            this.refreshList();
        },

        // ── lista / badge ─────────────────────────────────────────────────────

        // Debounce + serialização: colapsa rajadas de eventos realtime num só
        // refresh e nunca dispara dois em paralelo (mitiga a corrida com um
        // mad:click do usuário em voo — o _morph de nó destacado descartaria um
        // dos re-renders). MadWire é binding léxico GLOBAL (não está em window).
        refreshList: function () {
            var self = this;
            if (S.refreshTimer) return;
            S.refreshTimer = setTimeout(function () {
                S.refreshTimer = null;
                var box = mailbox();
                if (!box || typeof MadWire === 'undefined' || !MadWire.refresh) return;
                if (S.refreshing) { self.refreshList(); return; } // já em voo → reagenda
                S.refreshing = true;
                try {
                    Promise.resolve(MadWire.refresh(box))
                        .catch(function () {})
                        .then(function () { S.refreshing = false; });
                } catch (e) { S.refreshing = false; }
            }, 350);
        },

        bumpBadge: function () {
            // Atualiza o dropdown/badge do header (mesma rota do correio antigo).
            if (typeof MadTemplate !== 'undefined' && MadTemplate.updateMessagesMenu) {
                try { MadTemplate.updateMessagesMenu(); } catch (e) {}
            }
            var badge = document.getElementById('mail-badge') || document.querySelector('#envelope_messages .badge');
            if (badge) {
                var n = parseInt(badge.textContent || '0', 10) || 0;
                badge.textContent = n + 1;
                badge.classList.remove('hidden');
                badge.classList.add('pop');
                setTimeout(function () { badge.classList.remove('pop'); }, 360);
            }
        },

        // ── toast client-side (realtime) ──────────────────────────────────────

        toast: function (text) {
            var box = mailbox();
            if (!box) return;
            var wrap = box.querySelector('.m-toast-wrap');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.className = 'm-toast-wrap';
                box.appendChild(wrap);
            }
            var t = document.createElement('div');
            t.className = 'm-toast';
            t.innerHTML = '<span class="tx"></span>';
            t.querySelector('.tx').textContent = text;
            wrap.appendChild(t);
            setTimeout(function () {
                t.classList.add('out');
                setTimeout(function () { t.remove(); }, 260);
            }, 4200);
        },

        // ── realtime (Reverb/Echo) ────────────────────────────────────────────

        setupEcho: function () {
            var state = $('madRealtimeState');
            var rv = S.cfg && S.cfg.reverb;

            // window.Echo é a CLASSE (build IIFE do laravel-echo); instanciamos uma vez.
            if (!rv || !rv.key || typeof window.Echo !== 'function') {
                if (state) state.textContent = '· offline';
                return;
            }
            var channel = S.cfg.channel;
            if (S.echoBound === channel) {            // já assinado nesta sessão
                if (state) state.textContent = '· conectado';
                return;
            }

            try {
                if (!S.echo) {
                    S.echo = new window.Echo({
                        broadcaster: 'reverb',
                        key: rv.key,
                        wsHost: rv.host,
                        wsPort: rv.port,
                        wssPort: rv.port,
                        forceTLS: (rv.scheme === 'https'),
                        enabledTransports: ['ws', 'wss'],
                        namespace: 'App.Events',
                        authEndpoint: rv.authEndpoint || '/broadcasting/auth',
                        auth: { headers: {
                            'X-CSRF-TOKEN': (S.cfg && S.cfg.csrf) || '',
                            'X-Requested-With': 'XMLHttpRequest',
                        } },
                    });
                    window.MadMailEcho = S.echo;
                }

                var self = this;
                S.echo.private(channel).listen('.MensagemRecebida', function (e) {
                    self.bumpBadge();
                    self.toast((e && e.fromName ? e.fromName + ': ' : '') + ((e && e.subject) || 'Nova mensagem'));
                    if (!self.isComposeOpen()) self.refreshList();
                });
                S.echoBound = channel;
                if (state) state.textContent = '· conectado';
            } catch (err) {
                if (state) state.textContent = '· offline';
            }
        },

        // ── rótulos: rename inline (chama blockUpdate via Mad.call) ─────────────

        /** Entra em modo edição: troca o label por um input focado. */
        renameStart: function (btn) {
            var row = btn && btn.closest('.mf-tag');
            if (!row) return;
            var input = row.querySelector('.mf-tag-edit');
            var lbl   = row.querySelector('.mf-lbl');
            if (!input || !lbl) return;
            input.value = (lbl.textContent || '').trim();
            lbl.setAttribute('hidden', '');
            input.removeAttribute('hidden');
            row.classList.add('editing');
            input.focus();
            input.select();
        },

        /** Sai do modo edição sem salvar (Esc / blur / valor inalterado). */
        renameCancel: function (input) {
            var row = input && input.closest('.mf-tag');
            if (!row) return;
            var lbl = row.querySelector('.mf-lbl');
            input.setAttribute('hidden', '');
            if (lbl) lbl.removeAttribute('hidden');
            row.classList.remove('editing');
        },

        /** Enter confirma (blockUpdate via Mad.call), Esc cancela. */
        renameKey: function (event, input, id, state) {
            if (event.key === 'Enter') {
                event.preventDefault();
                var val = (input.value || '').trim();
                var row = input.closest('.mf-tag');
                var lbl = row && row.querySelector('.mf-lbl');
                var cur = lbl ? (lbl.textContent || '').trim() : '';
                if (val === '' || val === cur) { this.renameCancel(input); return; }
                // Sucesso → MadWire troca o HTML do row (nome novo). Erro (duplicata)
                // → toast amigável e o row fica intacto. Em ambos voltamos pro view.
                if (window.Mad && Mad.call) {
                    Mad.call('blockUpdate', [Number(id), 'name', val, state], input);
                }
                this.renameCancel(input);
            } else if (event.key === 'Escape') {
                event.preventDefault();
                this.renameCancel(input);
            }
        },
    };

    window.MadMail = MadMail;
})();
