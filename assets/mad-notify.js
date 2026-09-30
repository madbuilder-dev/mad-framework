/* ============================================================
   MadNotify — classe JS fina das notificações (sino do notch header).
   FONTE: packages/mad-framework/assets/mad-notify.js
   Servido em public/lib/mad/mad-notify.js (composer mad:sync) e carregado UMA vez
   em libraries.html. Auto-boot a partir de window.__NOTIFY_BOOT__ (injetado no
   layout pelo ShellViewModel::notifyBoot).

   Responsabilidades (o resto é server-driven):
     · realtime: assinar canal privado notifications.{id} (Reverb/Echo) → badge +
       toast + refresh do dropdown do sino
     · open(id): abrir o detalhe (NotificationView) e reatualizar o sino
     · markAllRead(): POST /app/notifications/mark-all-read + refresh

   Reverb DESLIGADO (ou Echo ausente) → só o polling do MadTemplate atualiza o
   sino (degradação graciosa: "funciona com Reverb e/ou polling").
   ============================================================ */
(function () {
    'use strict';

    var S = {
        cfg: null,
        echo: null,
        echoBound: null,   // canal já assinado (evita assinatura dupla)
    };

    function refreshMenu() {
        if (typeof MadTemplate !== 'undefined' && MadTemplate.updateNotificationsMenu) {
            try { MadTemplate.updateNotificationsMenu(); } catch (e) {}
        }
    }

    function badgeEl() {
        return document.querySelector('#envelope_notifications .ball-notice');
    }

    var MadNotify = {

        /** Chamado pelo auto-boot (idempotente). */
        init: function (cfg) {
            S.cfg = cfg || S.cfg || {};
            this.setupEcho();
        },

        /** Abre o detalhe de uma notificação e reatualiza o sino (abrir = ler). */
        open: function (id) {
            if (window.Mad && Mad.go) {
                Mad.go('NotificationView', 'onOpen', { id: id });
            }
            setTimeout(refreshMenu, 450); // após marcar lida no servidor
        },

        /** Marca todas como lidas (link do header do dropdown). */
        markAllRead: function () {
            var csrf = (S.cfg && S.cfg.csrf)
                || (document.querySelector('meta[name="csrf-token"]') || {}).content
                || '';
            // Resolve contra o <base> do documento (nunca "/" fixo): sob
            // `Alias /projeto` o app roda em subpath e a rota absoluta cairia
            // fora dele. Na raiz o resultado e identico ao anterior.
            var url = new URL('app/notifications/mark-all-read', document.baseURI).href;
            fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            }).then(function () { refreshMenu(); }).catch(function () {});
            return false;
        },

        /** Atualização otimista do badge (o refresh do menu traz a contagem autoritativa). */
        bumpBadge: function (count) {
            var b = badgeEl();
            if (!b) return;
            if (typeof count === 'number') {
                b.textContent = count;
                b.setAttribute('data-count', count);
            }
            b.classList.add('pop');
            setTimeout(function () { b.classList.remove('pop'); }, 360);
        },

        // ── toast client-side (realtime) ──────────────────────────────────────

        toast: function (text) {
            if (!text) return;
            var wrap = document.getElementById('madNotifyToasts');
            if (!wrap) {
                wrap = document.createElement('div');
                wrap.id = 'madNotifyToasts';
                wrap.style.cssText = 'position:fixed;top:16px;right:16px;z-index:99999;display:flex;flex-direction:column;gap:8px;';
                document.body.appendChild(wrap);
            }
            var t = document.createElement('div');
            t.style.cssText = 'background:var(--mad-surface,#fff);color:var(--mad-text,#111);border:1px solid var(--mad-border,#e5e7eb);box-shadow:0 6px 24px rgba(0,0,0,.12);border-radius:10px;padding:10px 14px;font-size:13px;max-width:320px;opacity:0;transform:translateY(-6px);transition:all .2s;';
            t.textContent = text;
            wrap.appendChild(t);
            requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'none'; });
            setTimeout(function () {
                t.style.opacity = '0';
                setTimeout(function () { t.remove(); }, 220);
            }, 4500);
        },

        // ── realtime (Reverb/Echo) ────────────────────────────────────────────

        setupEcho: function () {
            var rv = S.cfg && S.cfg.reverb;

            // window.Echo é a CLASSE (build IIFE do laravel-echo); instanciamos uma vez.
            if (!rv || !rv.key || typeof window.Echo !== 'function' || !S.cfg.meId) {
                return; // sem Reverb → só polling
            }

            var channel = 'notifications.' + S.cfg.meId;
            if (S.echoBound === channel) return; // já assinado nesta sessão

            try {
                if (!S.echo) {
                    // Reusa a instância do correio/chat se já existir (mesma app Reverb),
                    // senão cria uma dedicada com o endpoint de auth das notificações.
                    if (window.MadMailEcho) {
                        S.echo = window.MadMailEcho;
                    } else if (window.Echo && typeof window.Echo.private === 'function') {
                        S.echo = window.Echo;
                    } else {
                        S.echo = new window.Echo({
                            broadcaster: 'reverb',
                            key: rv.key,
                            wsHost: rv.host,
                            wsPort: rv.port,
                            wssPort: rv.port,
                            forceTLS: (rv.scheme === 'https'),
                            enabledTransports: ['ws', 'wss'],
                            namespace: 'App.Events',
                            authEndpoint: rv.authEndpoint || '/broadcasting/auth-notifications',
                            auth: { headers: {
                                'X-CSRF-TOKEN': (S.cfg && S.cfg.csrf) || '',
                                'X-Requested-With': 'XMLHttpRequest',
                            } },
                        });
                    }
                    window.MadNotifyEcho = S.echo;
                }

                var self = this;
                S.echo.private(channel).listen('.MadNotificationReceived', function (e) {
                    self.bumpBadge(e && typeof e.unread_count === 'number' ? e.unread_count : undefined);
                    self.toast((e && e.title) ? e.title : '');
                    refreshMenu();
                });
                S.echoBound = channel;
            } catch (err) {
                // Reverb indisponível → segue só no polling.
            }
        },
    };

    window.MadNotify = MadNotify;

    // Auto-boot a partir do blob global injetado no layout.
    function boot() {
        if (window.__NOTIFY_BOOT__) {
            MadNotify.init(window.__NOTIFY_BOOT__);
        }
    }
    if (document.readyState !== 'loading') boot();
    else document.addEventListener('DOMContentLoaded', boot);
})();
