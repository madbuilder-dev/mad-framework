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
     * Quem explica a recusa, quando ela tem um motivo que não é "seu perfil não
     * tem esta tela" — ver {@see explainUsing()}.
     *
     * @var (callable(?string): ?array)|null
     */
    private static $explainer = null;

    /**
     * Registra quem explica a recusa (uma vez, no boot da aplicação).
     *
     * O pacote só sabe que o acesso foi negado. A APLICAÇÃO pode saber POR QUÊ:
     * a licença da unidade venceu e trancou todas as telas (fw#321). Dizer
     * "peça a liberação a quem administra o sistema" a quem administra é mandar
     * a pessoa pedir a si mesma. O explicador devolve `null` (recusa comum) ou:
     *
     *   [
     *     'title'   => 'A licença desta unidade expirou em 11/10/2026.',
     *     'body'    => 'Renove a assinatura para voltar a usar o sistema.',
     *     'heading' => 'Licença expirada',            // título curto (canal reativo)
     *     'icon'    => 'calendar-x',                  // opcional (lucide)
     *     'actions' => [                              // opcional
     *        ['label' => 'Renovar assinatura', 'href' => '/app/...', 'target' => 'app'],
     *        ['label' => 'Enviar e-mail', 'href' => 'mailto:...', 'target' => 'external'],
     *     ],
     *   ]
     *
     * `target`: `app` navega dentro do sistema; `external` abre fora (e-mail,
     * WhatsApp). `null` desliga o explicador.
     *
     * O explicador recebe a TELA negada (nome da classe, ou `null` quando a
     * recusa não é de uma tela conhecida): é o que deixa a aplicação separar
     * "a licença tirou esta tela" de "seu perfil não tem esta tela" (fw#327).
     * Quem registrou um explicador sem parâmetro continua valendo.
     *
     * @param  (callable(?string): ?array)|null  $explainer
     */
    public static function explainUsing(?callable $explainer): void
    {
        self::$explainer = $explainer;
    }

    /**
     * O motivo da recusa, já saneado, ou `null` para a recusa comum.
     *
     * Fail-safe: explicador ausente, que estoura ou que devolve algo sem título
     * vira a recusa de sempre. Uma recusa nunca pode virar erro 500.
     *
     * @param  string|null  $screen  a tela negada, quando quem recusa sabe qual é
     * @return array{title: string, body: string, heading: string, icon: string,
     *               actions: list<array{label: string, href: string, target: string}>}|null
     */
    public static function reason(?string $screen = null): ?array
    {
        if (self::$explainer === null) {
            return null;
        }

        $screen = $screen !== null && trim($screen) !== '' ? trim($screen) : null;

        try {
            $raw = (self::$explainer)($screen);
        } catch (\Throwable $e) {
            return null;
        }

        if (! is_array($raw) || trim((string) ($raw['title'] ?? '')) === '') {
            return null;
        }

        $actions = [];
        foreach ((array) ($raw['actions'] ?? []) as $action) {
            $label = is_array($action) ? trim((string) ($action['label'] ?? '')) : '';
            $href  = is_array($action) ? trim((string) ($action['href'] ?? '')) : '';
            if ($label === '' || $href === '') {
                continue;
            }
            $actions[] = [
                'label'  => $label,
                'href'   => $href,
                'target' => ($action['target'] ?? 'app') === 'external' ? 'external' : 'app',
            ];
        }

        return [
            'title'   => trim((string) $raw['title']),
            'body'    => trim((string) ($raw['body'] ?? '')),
            'heading' => trim((string) ($raw['heading'] ?? '')),
            'icon'    => trim((string) ($raw['icon'] ?? '')),
            'actions' => $actions,
        ];
    }

    /**
     * Resposta 403 pronta.
     *
     * @param  Request|null  $request  quando ausente, cai no `request()` global
     * @param  string|null   $screen   a tela negada (classe), repassada a quem explica a recusa
     */
    public static function response(?Request $request = null, ?string $screen = null): Response
    {
        $request = $request ?? (function_exists('request') ? request() : null);

        if (self::wantsShell($request)) {
            $shell = self::shell();
            if ($shell instanceof Response) {
                // Mesmo corpo do casco, status de recusa.
                return $shell->setStatusCode(403);
            }
        }

        return new Response(MadErrorPage::forbidden(self::reason($screen)), 403, [
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
     * Corpo JSON da recusa no canal do wire (status 403 vai no cabeçalho).
     *
     * `title` é o título do aviso: sem ele o `mad-livewire.js` abria um diálogo
     * de ERRO intitulado "Exceção" — a regra de acesso do perfil parecia pane do
     * sistema. `forbidden` diz ao cliente que é recusa, não falha.
     *
     * @return array{error: string, title: string, forbidden: true, actions?: list<array{label: string, href: string, target: string}>}
     */
    public static function wirePayload(?string $key = null): array
    {
        // Recusa com motivo próprio (licença vencida): vale para a tela e para
        // a ação — as ações foram zeradas pelo mesmo motivo, e "Sem permissão
        // para excluir" esconderia a causa.
        $reason = self::reason();
        if ($reason !== null) {
            return [
                'error'     => trim($reason['title'] . ' ' . $reason['body']),
                'title'     => $reason['heading'] !== '' ? $reason['heading'] : self::heading(),
                'forbidden' => true,
                'actions'   => $reason['actions'],
            ];
        }

        return [
            'error'     => self::actionMessage($key),
            'title'     => self::heading(),
            'forbidden' => true,
        ];
    }

    /** Título curto do aviso de recusa: "Sem permissão". */
    public static function heading(): string
    {
        try {
            $t = \Mad\I18n\MadLang::t('mad.no_permission_heading');
            if (is_string($t) && $t !== '' && $t !== 'mad.no_permission_heading') {
                return $t;
            }
        } catch (\Throwable) {
            // cai na reserva
        }

        return 'Sem permissão';
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
