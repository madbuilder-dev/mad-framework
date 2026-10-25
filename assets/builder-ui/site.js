/* ==========================================================================
   site.js — o pouco de comportamento que o site público precisa.

   Três coisas, e só:
     1. o menu do celular abre e fecha;
     2. a tabela de planos alterna entre mensal e anual;
     3. o link `href="#secao"` rola até a seção da página aberta.

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

  /* ── Máscara de telefone ───────────────────────────────────────────────── */

  // `data-site-mask="(99) 9999-9999|(99) 99999-9999"`: 9 = dígito; com várias,
  // vale a mais curta em que os dígitos digitados cabem (fixo e celular no
  // mesmo campo). Só arruma o que a pessoa digitou: quem valida é o servidor.
  function digitosDa(mascara) {
    return (mascara.match(/9/g) || []).length;
  }

  function mascarar(valor, mascaras) {
    var digitos = String(valor || '').replace(/\D+/g, '');
    if (!digitos) return '';

    var escolhida = mascaras[mascaras.length - 1];
    for (var i = 0; i < mascaras.length; i++) {
      if (digitosDa(mascaras[i]) >= digitos.length) {
        escolhida = mascaras[i];
        break;
      }
    }

    // Literal só ENTRE dígitos: "(11" e não "(11) " — senão o apagar trava
    // no ") " que a máscara devolve.
    var saida = '';
    var d = 0;
    for (var j = 0; j < escolhida.length && d < digitos.length; j++) {
      var c = escolhida.charAt(j);
      saida += c === '9' ? digitos.charAt(d++) : c;
    }
    return saida;
  }

  function ligarMascaras() {
    document.querySelectorAll('input[data-site-mask]').forEach(function (campo) {
      if (campo.dataset.siteMaskReady === '1') return;
      campo.dataset.siteMaskReady = '1';

      var mascaras = (campo.getAttribute('data-site-mask') || '')
        .split('|')
        .filter(function (m) { return digitosDa(m) > 0; })
        .sort(function (a, b) { return digitosDa(a) - digitosDa(b); });
      if (!mascaras.length) return;

      var aplicar = function () {
        var novo = mascarar(campo.value, mascaras);
        if (novo !== campo.value) campo.value = novo;
      };
      campo.addEventListener('input', aplicar);
      aplicar(); // valor que voltou do servidor (erro em outro campo)
    });
  }

  /* ── Link para uma seção da própria página ─────────────────────────────── */

  // O casco do site emite `<base href="raiz do app/">` (os assets relativos da
  // página pendem dele). Com `<base>`, o navegador resolve `href="#secao"`
  // contra a base, não contra a página aberta: em `/loja/x?mesa=1` o link ia
  // para `/#secao`, a inicial, e o caminho e a query se perdiam.
  //
  // No instante do clique o link passa a apontar para a página aberta e, logo
  // depois que o navegador o segue, volta ao `#secao` que a página escreveu
  // (script que lê o `href` continua vendo o que sempre viu). Só quando o alvo
  // existe NESTA página: menu `href="#recursos"` repetido em todas as páginas
  // segue levando para a seção da inicial, como sempre levou.
  function alvoNestaPagina(hash) {
    if (hash === '#' || hash.toLowerCase() === '#top') return true;
    var id = hash.slice(1);
    try {
      id = decodeURIComponent(id);
    } catch (e) {
      return false;
    }
    return !!(document.getElementById(id) || document.getElementsByName(id)[0]);
  }

  function ligarAncoras() {
    var raiz = document.documentElement;
    if (!raiz || raiz.dataset.siteAnchorsReady === '1') return;
    raiz.dataset.siteAnchorsReady = '1';

    // No `window` (fase de bolha): roda DEPOIS de todo ouvinte do `document`.
    // O script da própria página (rolagem suave, `replaceState`, foco do "Ir
    // para o conteúdo") vê o `href="#…"` original e, tratando o clique
    // (`preventDefault`), tira o framework de cena (fw#346).
    window.addEventListener('click', function (e) {
      if (e.defaultPrevented || !e.target || !e.target.closest) return;
      var link = e.target.closest('a[href^="#"]');
      if (!link) return;

      var hash = link.getAttribute('href') || '';
      if (hash.charAt(0) !== '#' || !alvoNestaPagina(hash)) return;

      link.setAttribute('href', location.pathname + location.search + hash);
      setTimeout(function () {
        link.setAttribute('href', hash);
      }, 0);
    });
  }

  function iniciar() {
    ligarMenu();
    ligarCiclo();
    ligarPlanoDoCadastro();
    ligarMascaras();
    ligarAncoras();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciar);
  } else {
    iniciar();
  }

  window.MadSite = window.MadSite || {};
  window.MadSite.refresh = iniciar;
  window.MadSite.mask = mascarar;
})();
