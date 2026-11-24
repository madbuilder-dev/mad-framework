<?php

namespace Mad\Ui;

/**
 * MadErrorPage — as telas de erro que o USUÁRIO FINAL pode ver.
 *
 * Existe porque o framework tinha duas saídas de erro e as duas eram para o
 * desenvolvedor:
 *
 *  • `MadErrorRenderer::render()` — caminho do arquivo, TRECHO DO CÓDIGO FONTE
 *    e stack trace. Era emitido pelo `MadComponent::_renderError()` sem
 *    consultar `app.debug`: com `APP_DEBUG=false` o cliente de um app publicado
 *    recebia a árvore de diretórios do servidor por abrir um favorito velho.
 *  • `'<h1>403 — Permission denied</h1>'` — HTML cru, em inglês, sem casco e
 *    sem caminho de volta, num app cuja interface é pt-BR.
 *
 * Aqui ficam as duas versões "de produção": traduzidas nos quatro idiomas,
 * dentro da mesma linguagem visual do kit (tokens `--mad-*`, com valores de
 * reserva para o caso do CSS não ter carregado) e SEMPRE com uma saída — sem
 * botão de volta, uma tela de erro é um beco.
 *
 * O detalhe técnico não desaparece: vai para o log pelo `report()` do Laravel
 * e, com o MadTrace ligado, para o Mad Trace (campo `error_id`), e o usuário
 * recebe um IDENTIFICADOR CURTO que o suporte usa para achar a linha do log ou
 * o evento do Mad Trace (`errorId()`). É o mesmo contrato do Ignition/Flare,
 * sem expor nada.
 *
 * Retrocompatibilidade: com `app.debug` ligado nada muda — quem chama continua
 * recebendo a tela rica. Esta classe só entra quando o debug está desligado.
 */
class MadErrorPage
{
    /**
     * Letras e números do código, sem os que se confundem ao ler em voz alta
     * ou ao digitar (0/O, 1/I/L, U/V).
     */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTWXYZ';

    /**
     * Identificador curto de UMA ocorrência: `E-7K2Q9` (fw#642).
     *
     * Curto de propósito: ele vai ser lido em voz alta no telefone com o
     * suporte, que o acha no log (Logs › Laravel Log, busca) junto com a hora
     * e o usuário. O mesmo formato na tela de erro, no diálogo do erro técnico
     * e no erro não tratado de uma ação.
     */
    public static function errorId(?\Throwable $e = null): string
    {
        $max = strlen(self::CODE_ALPHABET) - 1;
        try {
            $code = '';
            for ($i = 0; $i < 5; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $max)];
            }
        } catch (\Throwable $ignored) {
            // Sem fonte de aleatoriedade: deriva do erro + horário. Pior
            // identificador, ainda melhor do que nenhum.
            $hash = md5(($e ? $e->getFile() . $e->getLine() : '') . microtime());
            $code = '';
            for ($i = 0; $i < 5; $i++) {
                $code .= self::CODE_ALPHABET[hexdec(substr($hash, $i * 2, 2)) % ($max + 1)];
            }
        }

        return 'E-' . $code;
    }

    /**
     * Quem e onde, para a linha do log do erro (fw#642): o usuário da sessão
     * (login e id) e a rota (método, caminho e, numa ação do wire, a ação
     * pedida). Vazio fora de uma requisição. Nunca lança.
     *
     * @return array{user?: string, route?: string}
     */
    public static function requestContext(): array
    {
        $out = [];
        try {
            if (function_exists('app') && app()->bound('session') && app('session')->isStarted()) {
                $login = trim((string) session('login'));
                $id    = session('userid');
                if ($login !== '' || ($id !== null && $id !== '')) {
                    $out['user'] = trim($login . ($id !== null && $id !== '' ? ' (id ' . $id . ')' : ''));
                }
            }
        } catch (\Throwable $ignored) {
            // sem sessão: o log sai sem o usuário
        }
        try {
            if (function_exists('app') && app()->bound('request')) {
                $request = app('request');
                $route   = $request->getMethod() . ' /' . ltrim($request->path(), '/');
                $action  = $request->input('mad_action');
                if (is_string($action) && $action !== '' && strlen($action) <= 120) {
                    $route .= ' → ' . $action;
                }
                $out['route'] = $route;
            }
        } catch (\Throwable $ignored) {
            // fora de requisição: o log sai sem a rota
        }

        return $out;
    }

    /**
     * Erro NÃO TRATADO de uma ação (o catch do wire, fw#642): o caminho de
     * sempre (`report()` do Laravel — log, Mad Trace e os `report` do app) e,
     * por cima, a linha do código `[mad-error E-…]` com o usuário e a rota,
     * sem segredo. O evento do Mad Trace que o `report()` criou ganha o mesmo
     * código (o Mad Trace junta o mesmo erro da mesma requisição). Nunca lança.
     */
    public static function reportUnhandled(\Throwable $e, string $errorId, array $context = []): void
    {
        try {
            if (function_exists('report')) {
                report($e);
            }
        } catch (\Throwable $ignored) {
            // segue: a linha do código ainda vai para o log
        }

        $context = self::cleanContext($context);
        self::log($e, $errorId, $context);

        try {
            if (class_exists('MadTrace') && \MadTrace::isInstalled()) {
                // Sem trocar a mensagem: é o que casa com o evento do report().
                \MadTrace::captureException($e, $context + ['error_id' => $errorId]);
            }
        } catch (\Throwable $ignored) {
            // silêncio deliberado
        }
    }

    /** Nome de classe anônima sem o byte NUL (ver report()). */
    private static function cleanContext(array $context): array
    {
        if (isset($context['component']) && is_string($context['component'])) {
            $context['component'] = explode("\0", $context['component'])[0];
        }

        return $context;
    }

    /**
     * Manda a exceção para o log do Laravel com o identificador colado e, com o
     * MadTrace ligado, para o Mad Trace com o MESMO código.
     *
     * A tela de erro captura a exceção ela mesma, então o manipulador global do
     * Laravel (que alimenta o Mad Trace) nunca a via: o usuário lia o código na
     * tela e a busca no Mad Trace não achava nada. Agora o evento nasce aqui, com
     * o código no campo `error_id`.
     *
     * FAIL-SAFE: esta função é chamada de dentro de um `catch` que já está
     * tratando um erro; se o próprio log ou o Mad Trace falhar, ela não pode
     * lançar e derrubar a página de erro. Um não depende do outro.
     */
    public static function report(\Throwable $e, string $errorId, array $context = []): void
    {
        // O nome de uma classe anônima do PHP leva um byte NUL (`Classe@anonymous\0/caminho`):
        // o jsonb do PostgreSQL recusa e o log fica ilegível. Fica só o que vem antes.
        $context = self::cleanContext($context);

        self::log($e, $errorId, $context);
        self::trace($e, $errorId, $context);
    }

    private static function log(\Throwable $e, string $errorId, array $context): void
    {
        try {
            if (function_exists('logger')) {
                // Sem segredo (MadErrorRedactor): a mensagem de um erro de banco
                // traz o SQL com os valores — o hash da senha, num INSERT de
                // usuário — e `getTraceAsString()` escreve os argumentos de
                // cada chamada.
                // Usuário e rota (fw#642): o suporte acha a linha pelo código e
                // vê quem, onde e — pela data do log — quando.
                logger()->error('[mad-error ' . $errorId . '] ' . MadErrorRedactor::describe($e), $context + self::requestContext() + [
                    'error_id' => $errorId,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                    'trace'    => MadErrorRedactor::trace($e),
                ]);

                return;
            }
            if (function_exists('report')) {
                report($e);
            }
        } catch (\Throwable $ignored) {
            // silêncio deliberado
        }
    }

    /**
     * Evento do Mad Trace com o código da tela. Só com o MadTrace instalado
     * (`mad.trace.enabled`); desligado, nada muda e o log segue como sempre.
     * A mensagem sai pelo mesmo redator do log (sem valor de SQL sensível); o
     * trace do MadTrace já não leva argumentos.
     */
    private static function trace(\Throwable $e, string $errorId, array $context): void
    {
        try {
            if (class_exists('MadTrace') && \MadTrace::isInstalled()) {
                \MadTrace::captureException($e, $context + ['error_id' => $errorId], MadErrorRedactor::describe($e));
            }
        } catch (\Throwable $ignored) {
            // silêncio deliberado
        }
    }

    /**
     * Falha interna, versão de produção: o que aconteceu, o que fazer e o
     * código para o suporte. Nenhum caminho, nenhuma classe, nenhum trace.
     */
    public static function internal(string $errorId): string
    {
        $title = self::esc(self::t('mad.error_page_title', 'Algo deu errado nesta tela'));
        $body  = self::esc(self::t('mad.error_page_body', 'A falha foi registrada. Tente de novo em instantes.'));
        $label = self::esc(self::t('mad.error_page_code', 'Código do erro'));
        $retry = self::esc(self::t('mad.error_page_retry', 'Tentar de novo'));
        $home  = self::esc(self::t('mad.forbidden_home', 'Ir para o início'));
        $id    = self::esc($errorId);
        $url   = self::esc(self::homeUrl());

        return self::card('triangle-alert', '#d97706', $title, $body, <<<HTML
            <div style="display:inline-flex;align-items:center;gap:8px;margin:0 0 18px;padding:7px 12px;
                        border:1px solid var(--mad-border,#e4e4e7);border-radius:8px;
                        background:var(--mad-surface-2,#fafafa);">
                <span style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;
                             color:var(--mad-text-muted,#71717a);">{$label}</span>
                <code style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;
                             color:var(--mad-text,#18181b);">{$id}</code>
            </div>
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                <button type="button" onclick="location.reload()"
                        class="mad-btn mad-btn-primary">{$retry}</button>
                <a href="{$url}" class="mad-btn mad-btn-secondary">{$home}</a>
            </div>
            HTML, 'internal');
    }

    /**
     * Recusa de acesso (403), versão do usuário final.
     *
     * Com `$reason` (ver {@see MadForbidden::explainUsing()}) a página diz o
     * MOTIVO e o próximo passo no lugar do texto genérico, e as ações dele
     * viram botões antes do "Ir para o início". Cada botão leva
     * `data-mad-denied-action`: é por ele que o diálogo de quem abriu a tela em
     * cortina (mad.js, fw#308) monta os mesmos botões.
     *
     * @param  array{title: string, body?: string, icon?: string,
     *               actions?: list<array{label: string, href: string, target?: string}>}|null  $reason
     */
    public static function forbidden(?array $reason = null): string
    {
        return self::forbiddenShortTitle() . self::forbiddenCard($reason);
    }

    /**
     * Título curto da tela de bloqueio para o casco (breadcrumb, rótulo da
     * aba): sem ele o topo herdava o rótulo de um item do menu, e a tela
     * negada aparecia como "Logout" (fw#502). Fica fora da vista: o cartão
     * já tem o título longo.
     */
    private static function forbiddenShortTitle(): string
    {
        $short = self::esc(self::t('mad.forbidden_short', 'Sem acesso'));

        return '<span class="mad-page-title" data-mad-tab-label style="display:none">' . $short . '</span>';
    }

    private static function forbiddenCard(?array $reason): string
    {
        $home = self::esc(self::t('mad.forbidden_home', 'Ir para o início'));
        $url  = self::esc(self::homeUrl());

        if ($reason === null || trim((string) ($reason['title'] ?? '')) === '') {
            $title = self::esc(self::t('mad.forbidden_title', 'Você não tem acesso a esta tela'));
            $body  = self::esc(self::t('mad.forbidden_body', 'Peça a liberação a quem administra o sistema.'));

            return self::card('lock', '#dc2626', $title, $body, <<<HTML
                <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                    <a href="{$url}" class="mad-btn mad-btn-primary">{$home}</a>
                </div>
                HTML);
        }

        $buttons = '';
        foreach ((array) ($reason['actions'] ?? []) as $i => $action) {
            $label = self::esc((string) ($action['label'] ?? ''));
            $href  = self::esc((string) ($action['href'] ?? ''));
            if ($label === '' || $href === '') {
                continue;
            }
            $class = $i === 0 ? 'mad-btn mad-btn-primary' : 'mad-btn mad-btn-secondary';

            if (($action['target'] ?? 'app') === 'external') {
                $buttons .= '<a href="' . $href . '" target="_blank" rel="noopener" class="' . $class
                    . '" data-mad-denied-action="external">' . $label . '</a>';
                continue;
            }

            // Dentro do sistema: navega pelo Mad (sem recarregar o casco); o
            // href fica de reserva para quem abrir em outra aba.
            $buttons .= '<a href="' . $href . '" class="' . $class . '" data-mad-denied-action="app"'
                . ' onclick="if(window.Mad&amp;&amp;Mad.navigate){Mad.navigate(this.getAttribute(\'href\'));return false;}">'
                . $label . '</a>';
        }

        $homeClass = $buttons === '' ? 'mad-btn mad-btn-primary' : 'mad-btn mad-btn-secondary';
        $icon      = preg_match('/^(tabler:)?[a-z0-9-]+$/', (string) ($reason['icon'] ?? '')) ? (string) $reason['icon'] : 'lock';

        return self::card(
            $icon,
            '#d97706',
            self::esc((string) $reason['title']),
            self::esc((string) ($reason['body'] ?? '')),
            <<<HTML
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                {$buttons}
                <a href="{$url}" class="{$homeClass}">{$home}</a>
            </div>
            HTML
        );
    }

    /** Corpo do 404 de ação inexistente: o `Mad.exec` mostra o título e o texto. */
    public static function actionNotFound(): string
    {
        return '<h1>' . self::esc(self::t('mad.action_not_found_title', 'Ação não encontrada')) . '</h1>'
            . '<p>' . self::esc(self::t('mad.action_not_found_body', 'Esta tela não tem a ação pedida. Atualize a página e tente de novo.')) . '</p>';
    }

    /**
     * Registro que não existe — ou que um escopo/trava esconde de quem abriu —
     * pedido pelo método de entrada da tela (`/…/onEdit?id=N`, o botão Editar da
     * listagem). Não é falha: não tem código de erro nem vai para o log, e o
     * texto é o mesmo nos dois casos (não diz se o registro existe).
     *
     * @param bool $inLayer a tela abre em cortina lateral/janela: o botão fecha a
     *                      camada (a listagem continua por trás). Em página
     *                      inteira, volta para a tela anterior.
     */
    public static function notFound(bool $inLayer = false): string
    {
        $title = self::esc(self::t('mad.record_not_found_title', 'Registro não encontrado'));
        $body  = self::esc(self::t('mad.record_not_found_body', 'Ele pode ter sido excluído, ou você não tem acesso a ele.'));

        if ($inLayer) {
            $close = self::esc(self::t('mad.record_not_found_close', 'Fechar'));

            return self::card('search-x', '#d97706', $title, $body, <<<HTML
                <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                    <button type="button" class="mad-btn mad-btn-primary"
                            onclick="var o=this.closest('[data-mad-overlay]');if(o&&typeof MadOverlayEsc!=='undefined'){MadOverlayEsc.close(o);}">{$close}</button>
                </div>
                HTML);
        }

        $back = self::esc(self::t('mad.record_not_found_back', 'Voltar'));
        $home = self::esc(self::t('mad.forbidden_home', 'Ir para o início'));
        $url  = self::esc(self::homeUrl());

        // Voltar = o voltar do navegador. A tela que o app abriu direto pelo
        // endereço (a listagem de onde veio o clique) não tem estado de
        // navegação para o Mad redesenhar: nesse caso ela é recarregada.
        $onBack = "window.addEventListener('popstate',function(e){if(!(e.state&&e.state.url)){location.reload();}},{once:true});history.back();";

        return self::card('search-x', '#d97706', $title, $body, <<<HTML
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                <button type="button" onclick="{$onBack}"
                        class="mad-btn mad-btn-primary">{$back}</button>
                <a href="{$url}" class="mad-btn mad-btn-secondary">{$home}</a>
            </div>
            HTML);
    }

    // ── internos ───────────────────────────────────────────────────────────

    /**
     * O casco visual comum. Sem `<html>`: estas telas entram no conteúdo do
     * shell (o `Mad.bootShell()` refaz o pedido com `X-Mad-Partial` e injeta o
     * fragmento), e um documento completo aqui quebraria a página.
     */
    private static function card(string $icon, string $accent, string $title, string $body, string $actions, string $errorKind = ''): string
    {
        // Erro de tela (não a recusa de acesso) leva a marca que o front usa para
        // mostrar o fragmento num diálogo quando ele chega com status 200 numa
        // requisição que não troca a tela inteira (Mad.get de gaveta/modal).
        $marker = $errorKind !== '' ? ' data-mad-error-page="' . self::esc($errorKind) . '"' : '';

        return <<<HTML
        <div class="mad-ui mad-error-page" role="alert"{$marker}
             style="max-width:520px;margin:48px auto;padding:0 16px;text-align:center;
                    font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
            <div style="width:56px;height:56px;border-radius:14px;margin:0 auto 18px;
                        display:flex;align-items:center;justify-content:center;
                        background:color-mix(in srgb, {$accent} 12%, transparent);">
                <i data-lucide="{$icon}" style="width:26px;height:26px;color:{$accent};"></i>
            </div>
            <h1 style="font-size:20px;font-weight:600;margin:0 0 10px;color:var(--mad-text,#18181b);">
                {$title}
            </h1>
            <p style="font-size:14px;line-height:1.6;margin:0 0 22px;color:var(--mad-text-muted,#71717a);">
                {$body}
            </p>
            {$actions}
        </div>
        HTML;
    }

    /**
     * Início do app.
     *
     * O slug sai do `lang/{locale}/routes.php` pelo `trans()` (API pública,
     * já no idioma do usuário) — e não do `MadRoutes::seg()`, que é privado.
     * Sem o slug, a base do app já leva a algum lugar navegável.
     */
    private static function homeUrl(): string
    {
        $base = '';
        try {
            $base = \Mad\Routing\RoutingDriver::appBase();
        } catch (\Throwable $e) {
            $base = '';
        }

        $slug = null;
        try {
            if (function_exists('trans')) {
                $routes = trans('routes');
                if (is_array($routes) && isset($routes['welcome']['slug']) && is_string($routes['welcome']['slug'])) {
                    $slug = $routes['welcome']['slug'];
                }
            }
        } catch (\Throwable $e) {
            $slug = null;
        }

        if ($slug !== null && $slug !== '') {
            return ($base !== '' ? $base : '') . '/' . ltrim($slug, '/');
        }

        return $base !== '' ? $base : '/';
    }

    /**
     * Tradução com reserva embutida.
     *
     * A chave pode não existir num app publicado antes desta versão (o
     * `mad.php` de `lang` dele é mais velho que o pacote). Nesse caso o
     * `__()` do Laravel devolve a própria chave — e "mad.forbidden_title" na
     * tela seria só outra forma de vazar interno. O texto de reserva é pt-BR,
     * que é o default da plataforma.
     */
    private static function t(string $key, string $fallback): string
    {
        try {
            if (! function_exists('__')) {
                return $fallback;
            }
            $value = __($key);

            return (is_string($value) && $value !== $key && $value !== '') ? $value : $fallback;
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    /** `app.debug` ligado? (config quando há Laravel; env como reserva) */
    public static function debugEnabled(): bool
    {
        try {
            if (function_exists('config')) {
                return (bool) config('app.debug', false);
            }
        } catch (\Throwable $e) {
            // container fora do ar: cai no env
        }

        $env = getenv('APP_DEBUG');

        return $env !== false && filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }
}
