<?php

namespace Mad\Ui;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * MadForbidden — a resposta 403 das rotas de conteúdo.
 *
 * Ponto ÚNICO dos três lugares que recusavam acesso (o middleware
 * `MadProgramPermission` e os dois gates do `MadAppController`), porque três
 * cópias de `'<h1>403 — Permission denied</h1>'` eram três chances de a recusa
 * divergir — e as três estavam erradas do mesmo jeito: HTML cru, em inglês, sem
 * o casco do app e sem nenhum caminho de volta, num produto cuja interface é
 * pt-BR.
 *
 * Duas formas, uma decisão:
 *
 *  • **navegação direta de quem está logado** → o CASCO do app (o mesmo
 *    `ShellController` que qualquer tela usa). O `Mad.bootShell()` refaz o
 *    pedido com `X-Mad-Partial`, cai no mesmo gate e recebe o fragmento, que
 *    ele injeta no conteúdo. Resultado: a recusa aparece DENTRO do app, com
 *    menu e com o botão de início — em vez de uma página branca que substitui
 *    o sistema inteiro.
 *  • **qualquer outro caso** (fragmento, AJAX, usuário não logado, app sem
 *    `ShellController`) → só o fragmento.
 *
 * O status HTTP é **403 nos dois caminhos**: quem monitora não pode perder a
 * recusa, e o navegador renderiza o corpo de um 403 normalmente.
 */
class MadForbidden
{
    /**
     * Resposta 403 pronta.
     *
     * @param  Request|null  $request  quando ausente, cai no `request()` global
     */
    public static function response(?Request $request = null): Response
    {
        $request = $request ?? (function_exists('request') ? request() : null);

        if (self::wantsShell($request)) {
            $shell = self::shell();
            if ($shell instanceof Response) {
                // Mesmo corpo do casco, status de recusa.
                return $shell->setStatusCode(403);
            }
        }

        return new Response(MadErrorPage::forbidden(), 403, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /** Mensagem curta e traduzida para o canal JSON (wire). */
    public static function message(): string
    {
        try {
            if (function_exists('__')) {
                $value = __('mad.forbidden_title');
                if (is_string($value) && $value !== 'mad.forbidden_title' && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // cai na reserva
        }

        return 'Você não tem acesso a esta tela';
    }

    /**
     * Mensagem do canal JSON para a recusa de uma AÇÃO.
     *
     * Recusar `onDelete` com "Você não tem acesso a esta tela" é dizer uma
     * coisa que a pessoa acabou de ver ser falsa — ela ESTÁ na tela. Com a
     * chave negada em mãos ({@see \Mad\Security\PermissionGate::deniedActionKey()})
     * o recado passa a ser o mesmo da dica do botão cinza: "Sem permissão para
     * excluir". Sem chave (recusa da tela inteira) volta a mensagem de sempre.
     *
     * @param  string|null  $key  chave do vocabulário (`delete`) ou nome do
     *                            método numa ação própria (`onAprovar`)
     */
    public static function actionMessage(?string $key): string
    {
        if ($key === null || trim($key) === '') {
            return self::message();
        }

        return \Mad\Security\ActionVocab::denyTitle(trim($key));
    }

    /**
     * É uma navegação direta do navegador, de alguém logado?
     *
     * Espelha o `MadAppController::isDirectNavigation()`: GET sem
     * `X-Mad-Partial`. Um POST, um fetch do wire ou o próprio re-fetch do shell
     * querem o FRAGMENTO, não outro casco (senão o shell carregaria a si mesmo
     * dentro de si).
     */
    private static function wantsShell(?Request $request): bool
    {
        if (! $request instanceof Request) {
            return false;
        }
        if (! $request->isMethod('GET') || $request->headers->has('X-Mad-Partial')) {
            return false;
        }
        if ($request->ajax() || $request->wantsJson()) {
            return false;
        }

        try {
            return (bool) session('logged');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * O casco do app, ou null.
     *
     * FAIL-SAFE por retrocompatibilidade: `ShellController` é classe da
     * APLICAÇÃO gerada. App publicado com esqueleto anterior pode não ter (ou
     * ter uma assinatura diferente), e uma recusa de acesso não pode virar
     * erro 500 — nesse caso volta o fragmento, que é a forma antiga melhorada.
     */
    private static function shell(): ?Response
    {
        $class = '\App\Http\Controllers\ShellController';
        if (! class_exists($class) || ! function_exists('app')) {
            return null;
        }

        try {
            $shell = app($class);
            if (! method_exists($shell, 'renderLayout')) {
                return null;
            }
            $response = $shell->renderLayout();

            return $response instanceof Response ? $response : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
