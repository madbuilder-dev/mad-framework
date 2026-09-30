<?php

namespace Mad\Site;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mad\Component\MadComponent;
use Mad\View\MadBlade;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * MadSitePage — base das páginas PÚBLICAS do sistema (o site do produto:
 * inicial, planos, sobre, contato, blog, cadastro).
 *
 * Cada subclasse é um MadComponent completo, mas com três diferenças que valem
 * para tudo que fica ANTES do login:
 *
 *   - sem chrome de painel (`$wrapper = INTERNAL`);
 *   - o ciclo reativo aponta para `POST /public/_mad-wire`, que só executa as
 *     ações declaradas em {@see publicActions()} (ver {@see MadSiteWireController});
 *   - {@see showPage()} embrulha o resultado no layout do site (`site.layout`),
 *     que monta o cabeçalho de busca (título, descrição, endereço canônico,
 *     redes sociais, dados estruturados) a partir de {@see seo()}.
 *
 * Por padrão a página carrega SÓ o CSS/JS do site (~poucos KB). Os componentes
 * do painel (`<mad-form>`, `<mad-btn>`, Alpine) ficam de fora — declare
 * `protected static bool $useMadUi = true` na subclasse quando precisar deles.
 *
 * Uso típico:
 *
 *   class HomePage extends MadSitePage {
 *       protected static string $siteTitle       = 'Controle de ordens de serviço';
 *       protected static string $siteDescription = 'Organize atendimentos, equipe e cobrança num lugar só.';
 *
 *       public function mount(array $params = []): void { ... }
 *
 *       protected function view(): string|array { return 'site.home'; }
 *   }
 *
 *   // routes/modules/generated.php
 *   MadRoutes::exposeSite('', HomePage::class, ['home' => true]);
 *
 * O título/descrição podem ser decididos em tempo de execução (um post do blog
 * tem o título do post): escreva em `$this->pageTitle` / `$this->pageDescription`
 * / `$this->pageSeo` dentro do `mount()` — a instância vence o estático.
 */
abstract class MadSitePage extends MadComponent
{
    /** Páginas de site nunca vêm com wrapper admin (modal/drawer). */
    protected static string $wrapper = self::INTERNAL;

    /** Título SEO padrão da página (vai pro <title> via layout). */
    protected static string $siteTitle = '';

    /** Descrição SEO padrão (vai pra meta description). */
    protected static string $siteDescription = '';

    /**
     * Carregar os componentes do painel (mad-ui.css + Alpine + MadWire)?
     * Falso por padrão: o site é HTML + `mad-site.css` e nada mais.
     */
    protected static bool $useMadUi = false;

    /** View Blade do layout wrapper — pode ser sobrescrita por subclasses. */
    protected static string $siteLayout = 'site.layout';

    /** Título decidido em tempo de execução (vence o estático quando preenchido). */
    protected string $pageTitle = '';

    /** Descrição decidida em tempo de execução (vence a estática quando preenchida). */
    protected string $pageDescription = '';

    /**
     * Resto do cabeçalho de busca, decidido em tempo de execução:
     *   og_image  (string) imagem de compartilhamento
     *   og_type   (string) tipo do conteúdo para as redes sociais
     *                      ('website' é o padrão; 'article' num texto do blog)
     *   noindex   (bool)   pedir aos buscadores para não listar
     *   canonical (string) endereço canônico alternativo
     *   jsonld    (array)  dados estruturados extras (ex.: BlogPosting)
     *
     * @var array<string, mixed>
     */
    protected array $pageSeo = [];

    /**
     * Ações que o visitante ANÔNIMO pode disparar nesta página pelo canal
     * reativo. Vazio (o padrão) = nenhuma ação: o canal reativo não aceita
     * POST desta página. (Formulário HTML comum é outra porta: {@see acceptsPost()}.)
     *
     * Allowlist explícita de propósito — método público de um componente não
     * vira endpoint só por existir.
     *
     * @return array<int, string>
     */
    public static function publicActions(): array
    {
        return [];
    }

    /**
     * A página recebe ENVIO DE FORMULÁRIO do visitante — `POST` no próprio
     * endereço? Falso por padrão: a página é só de leitura e um `POST` nela
     * responde 405 (Método não permitido), como sempre respondeu.
     *
     * Ligue quando a página grava o que o visitante preenche: pedido/checkout,
     * orçamento, agendamento, inscrição.
     *
     *   class CheckoutPage extends MadSitePage
     *   {
     *       public static function acceptsPost(): bool { return true; }
     *
     *       public function mount(array $params = []): void
     *       {
     *           if (request()->isMethod('post')) {
     *               // $params['nome'], $params['itens'] … vieram do formulário
     *           }
     *       }
     *   }
     *
     *   <form method="post" action="{{ site_url('/checkout') }}">
     *       @csrf
     *       …
     *   </form>
     *
     * Como o envio é tratado:
     *   - roda o MESMO ciclo do GET (boot → mount → página no layout do site);
     *     o mount() recebe os campos do formulário somados aos parâmetros da
     *     rota e da query (a rota vence; o token do CSRF fica de fora). Arquivo
     *     enviado: `request()->file('campo')`;
     *   - `@csrf` no formulário é OBRIGATÓRIO: sem o token o envio é recusado
     *     (419) antes de a página ser montada;
     *   - há limite de envios por visitante (limitador `site-post`: 20 por
     *     minuto por IP; o app troca com `RateLimiter::for('site-post', …)`).
     *
     * É o `<form>` HTML de sempre — não depende de Alpine nem do MadWire, que a
     * página de site não carrega. Ação reativa é {@see publicActions()}.
     */
    public static function acceptsPost(): bool
    {
        return false;
    }

    /**
     * Cabeçalho de busca já resolvido (instância vence estático).
     *
     * @return array{title: string, description: string, og_image: string, og_type: string, noindex: bool, canonical: string, jsonld: array}
     */
    public function seo(): array
    {
        $extra = $this->pageSeo;

        return [
            'title'       => $this->pageTitle !== '' ? $this->pageTitle : static::$siteTitle,
            'description' => $this->pageDescription !== '' ? $this->pageDescription : static::$siteDescription,
            'og_image'    => trim((string) ($extra['og_image'] ?? '')),
            'og_type'     => trim((string) ($extra['og_type'] ?? '')) !== ''
                ? trim((string) $extra['og_type'])
                : 'website',
            'noindex'     => (bool) ($extra['noindex'] ?? false),
            'canonical'   => trim((string) ($extra['canonical'] ?? '')),
            'jsonld'      => is_array($extra['jsonld'] ?? null) ? $extra['jsonld'] : [],
        ];
    }

    /**
     * Declara a página como um TEXTO (artigo), não como um site.
     *
     * É o que faz o link virar card de artigo — com autor e data — quando
     * alguém compartilha o endereço no WhatsApp, no LinkedIn ou no Facebook.
     * Sem isso, todo post do blog era compartilhado como se fosse a página
     * inicial do site.
     *
     * Chame no `mount()` da página de post:
     *
     *   public function mount(array $params = []): void
     *   {
     *       $this->seoArticle();
     *   }
     */
    protected function seoArticle(): void
    {
        $this->pageSeo['og_type'] = 'article';
    }

    /**
     * Endereço pedido não existe (post despublicado, slug errado): 404 de
     * verdade, para o buscador não guardar a página.
     */
    protected function notFound(): never
    {
        abort(404);
    }

    /**
     * GET (e POST, com opt-in) — entry point estático das rotas públicas (ver
     * `MadRoutes::exposeSite`).
     *
     * Instancia o componente, roda boot()+mount(), renderiza e envolve no
     * layout do site.
     *
     * O mount() recebe os parâmetros DA ROTA somados aos da query string: sem
     * isso, `/blog/{slug}` chegava sem o slug e a página do post não tinha como
     * saber qual post carregar. No POST entram também os campos do formulário
     * ({@see acceptsPost()}).
     *
     * Renderiza via Mad\View\MadBlade::render() direto (não pelo helper global
     * `view()`/Mad\Web\ViewResponse): este app usa o Router nativo do Laravel
     * (illuminate/routing, ver routes/web.php), que não normaliza
     * Mad\Web\ViewResponse para HTTP — e o helper global `view()` colide com
     * Illuminate\Foundation\helpers.php (carrega antes via composer "files",
     * então a versão do Laravel sempre vence). Retornar um
     * Illuminate\Http\Response evita os dois problemas.
     */
    public static function showPage(Request $request): Response
    {
        // A rota POST existe para TODA página de site: perguntar ao registrar
        // exigiria carregar a classe, e uma página com erro derrubaria o app
        // inteiro (ver MadRoutes::exposeSite). A porta é aqui, antes de montar
        // qualquer coisa — sem opt-in, o mesmo 405 que o roteador sempre deu.
        $isPost = $request->isMethod('post');
        if ($isPost && ! static::acceptsPost()) {
            throw new MethodNotAllowedHttpException(
                ['GET', 'HEAD'],
                'Esta página do site não recebe envio de formulário (POST). '
                . 'Para receber, a página declara acceptsPost() retornando true.'
            );
        }

        MadSiteAssets::enableSite();
        if (static::$useMadUi) {
            MadSiteAssets::enableMadUi();
        }

        /** @var static $instance */
        $instance = new static();
        $instance->boot();

        // Rota > formulário > query: o endereço (`blog/{slug}`) identifica a
        // página e um campo de mesmo nome não o troca; o formulário vence a
        // query, como no `request()->input()`. O token do CSRF não é dado da
        // página — fica de fora (senão ia parar num `Model::create($params)`).
        $posted = $isPost ? array_diff_key($request->request->all(), ['_token' => true]) : [];
        $params = ($request->route()?->parameters() ?? []) + $posted + $request->query->all();
        $instance->_resolveAndCall('mount', $params);

        $body = $instance->_renderWrapped();
        $seo  = $instance->seo();

        $html = MadBlade::render(static::$siteLayout, [
            '_madSite'        => true,
            'seo'             => $seo,
            'body'            => $body,
            'nav'             => SiteRegistry::navItems(),
            // Compat com layouts antigos do portal de documentação.
            'title'           => $seo['title'],
            'siteTitle'       => $seo['title'],
            'siteDescription' => $seo['description'],
            'componentHtml'   => $body,
        ]);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * URL do endpoint reativo (mad-endpoint do wrapper). Subclasses podem
     * sobrescrever para apontar a outro handler.
     */
    protected function _wireEndpoint(string $class): string
    {
        return site_url('/public/_mad-wire');
    }
}
