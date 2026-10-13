/* ==========================================================================
   site.js — o pouco de comportamento que o site público precisa.

   Duas coisas, e só:
     1. o menu do celular abre e fecha;
     2. a tabela de planos alterna entre mensal e anual.

   Sem dependências (nada de Alpine, jQuery ou MadWire). Tudo que é essencial
   na página continua funcionando com o JavaScript desligado: os links do menu
   existem no HTML, e a tabela de planos nasce mostrando o preço mensal.
   ========================================================================== */
(function () {
  'use strict';

  /* ── Menu do celular ───────────────────────────────────────────────────── */

  function paraCadaMenu(fn) {
    document.querySelectorAll('[data-site-nav-toggle]').forEach(function (botao) {
      var nav = botao.closest('.site-nav') || document;
      var alvo = nav.querySelector('.site-nav__links');
      if (alvo) fn(botao, alvo);
    });
  }

  function alternarMenu(botao, alvo) {
    var aberto = alvo.getAttribute('data-open') === 'true';
    alvo.setAttribute('data-open', aberto ? 'false' : 'true');
    botao.setAttribute('aria-expanded', aberto ? 'false' : 'true');
  }

  function ligarMenu() {
    paraCadaMenu(function (botao, alvo) {
      if (botao.dataset.siteNavReady === '1') return;
      botao.dataset.siteNavReady = '1';
      botao.setAttribute('aria-expanded', 'false');
      if (!alvo.id) alvo.id = 'site-nav-links-' + Math.random().toString(36).slice(2, 8);
      botao.setAttribute('aria-controls', alvo.id);

      botao.addEventListener('click', function (e) {
        e.preventDefault();
        alternarMenu(botao, alvo);
      });

      // Escolher um destino fecha o menu — senão a lista cobre a página
      // inteira depois do clique em telas pequenas.
      alvo.addEventListener('click', function (e) {
        if (e.target.closest('a')) {
          alvo.setAttribute('data-open', 'false');
          botao.setAttribute('aria-expanded', 'false');
        }
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key !== 'Escape') return;
      paraCadaMenu(function (botao, alvo) {
        if (alvo.getAttribute('data-open') === 'true') {
          alvo.setAttribute('data-open', 'false');
          botao.setAttribute('aria-expanded', 'false');
          botao.focus();
        }
      });
    });
  }

  /* ── Mensal × anual ────────────────────────────────────────────────────── */

  function trocarCicloNoLink(link, ciclo) {
    var href = link.getAttribute('href');
    if (!href) return;

    if (/([?&])cycle=[^&#]*/.test(href)) {
      link.setAttribute('href', href.replace(/([?&])cycle=[^&#]*/, '$1cycle=' + ciclo));
    } else {
      link.setAttribute('href', href + (href.indexOf('?') === -1 ? '?' : '&') + 'cycle=' + ciclo);
    }
  }

  function aplicarCiclo(escopo, ciclo) {
    var mensal = ciclo !== 'yearly';

    escopo.querySelectorAll('[data-price-monthly]').forEach(function (el) {
      el.hidden = !mensal;
    });
    escopo.querySelectorAll('[data-price-yearly]').forEach(function (el) {
      el.hidden = mensal;
    });
    escopo.querySelectorAll('[data-plan-cta]').forEach(function (link) {
      trocarCicloNoLink(link, ciclo);
    });
    escopo.querySelectorAll('[data-site-cycle]').forEach(function (botao) {
      botao.setAttribute('aria-pressed', botao.getAttribute('data-site-cycle') === ciclo ? 'true' : 'false');
    });
  }

  function ligarCiclo() {
    document.querySelectorAll('[data-site-cycle]').forEach(function (botao) {
      if (botao.dataset.siteCycleReady === '1') return;
      botao.dataset.siteCycleReady = '1';

      botao.addEventListener('click', function (e) {
        e.preventDefault();
        var escopo = botao.closest('.site-section') || document;
        aplicarCiclo(escopo, botao.getAttribute('data-site-cycle') || 'monthly');
      });
    });
  }

  /* ── Cadastro: plano × período ─────────────────────────────────────────── */

  // O formulário de cadastro oferece os planos e, ao lado, o período. Nem todo
  // plano tem preço anual: ao trocar de plano, o período que ele não oferece
  // fica desligado e a escolha cai no primeiro que ele oferece. Sem JavaScript
  // nada disso roda e o servidor recusa a combinação com a mensagem no campo.
  function ajustarPeriodos(form) {
    var marcado = form.querySelector('input[name="plan_id"][data-cycles]:checked');
    if (!marcado) return;

    var aceitos = (marcado.getAttribute('data-cycles') || '').split(' ');
    var primeiro = null;
    var atualVale = false;

    form.querySelectorAll('input[type="radio"][name="cycle"]').forEach(function (periodo) {
      var vale = aceitos.indexOf(periodo.value) !== -1;
      periodo.disabled = !vale;
      if (vale && !primeiro) primeiro = periodo;
      if (vale && periodo.checked) atualVale = true;
    });

    if (!atualVale && primeiro) primeiro.checked = true;
  }

  function ligarPlanoDoCadastro() {
    document.querySelectorAll('[data-site-signup-plans]').forEach(function (bloco) {
      var form = bloco.closest('form');
      if (!form || form.dataset.sitePlanReady === '1') return;
      form.dataset.sitePlanReady = '1';

      bloco.addEventListener('change', function () {
        ajustarPeriodos(form);
      });
      ajustarPeriodos(form);
    });
  }

  function iniciar() {
    ligarMenu();
    ligarCiclo();
    ligarPlanoDoCadastro();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  window.MadSite = window.MadSite || {};
  window.MadSite.refresh = iniciar;
})();
