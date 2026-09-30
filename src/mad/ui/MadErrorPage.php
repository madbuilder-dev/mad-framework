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
 * O detalhe técnico não desaparece: vai para o log pelo `report()` do Laravel,
 * e o usuário recebe um IDENTIFICADOR CURTO que o suporte usa para achar a
 * linha certa (`errorId()`). É o mesmo contrato do Ignition/Flare, sem expor
 * nada.
 *
 * Retrocompatibilidade: com `app.debug` ligado nada muda — quem chama continua
 * recebendo a tela rica. Esta classe só entra quando o debug está desligado.
 */
class MadErrorPage
{
    /**
     * Identificador curto e estável de UMA ocorrência.
     *
     * Curto de propósito: ele vai ser lido em voz alta no telefone com o
     * suporte. 12 hex dão colisão irrelevante para esse uso.
     */
    public static function errorId(?\Throwable $e = null): string
    {
        try {
            return substr(bin2hex(random_bytes(6)), 0, 12);
        } catch (\Throwable $ignored) {
            // Sem fonte de aleatoriedade: deriva do erro + horário. Pior
            // identificador, ainda melhor do que nenhum.
            return substr(md5(($e ? $e->getFile() . $e->getLine() : '') . microtime()), 0, 12);
        }
    }

    /**
     * Manda a exceção para o log do Laravel com o identificador colado.
     *
     * FAIL-SAFE: esta função é chamada de dentro de um `catch` que já está
     * tratando um erro; se o próprio log falhar, ela não pode lançar e derrubar
     * a página de erro.
     */
    public static function report(\Throwable $e, string $errorId, array $context = []): void
    {
        try {
            if (function_exists('logger')) {
                logger()->error('[mad-error ' . $errorId . '] ' . get_class($e) . ': ' . $e->getMessage(), $context + [
                    'error_id' => $errorId,
                    'file'     => $e->getFile(),
                    'line'     => $e->getLine(),
                    'trace'    => $e->getTraceAsString(),
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
            HTML);
    }

    /** Recusa de acesso (403), versão do usuário final. */
    public static function forbidden(): string
    {
        $title = self::esc(self::t('mad.forbidden_title', 'Você não tem acesso a esta tela'));
        $body  = self::esc(self::t('mad.forbidden_body', 'Peça a liberação a quem administra o sistema.'));
        $home  = self::esc(self::t('mad.forbidden_home', 'Ir para o início'));
        $url   = self::esc(self::homeUrl());

        return self::card('lock', '#dc2626', $title, $body, <<<HTML
            <div style="display:flex;gap:8px;justify-content:center;flex-wrap:wrap;">
                <a href="{$url}" class="mad-btn mad-btn-primary">{$home}</a>
            </div>
            HTML);
    }

    // ── internos ───────────────────────────────────────────────────────────

    /**
     * O casco visual comum. Sem `<html>`: estas telas entram no conteúdo do
     * shell (o `Mad.bootShell()` refaz o pedido com `X-Mad-Partial` e injeta o
     * fragmento), e um documento completo aqui quebraria a página.
     */
    private static function card(string $icon, string $accent, string $title, string $body, string $actions): string
    {
        return <<<HTML
        <div class="mad-ui mad-error-page" role="alert"
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
