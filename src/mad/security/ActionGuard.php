<?php

namespace Mad\Security;

/**
 * ActionGuard — o ponto ÚNICO onde um botão pergunta "o perfil deixa?".
 *
 * O servidor já recusa a ação (ver {@see PermissionGate}), mas recusar depois do
 * clique é a pior forma de dizer não: o usuário aperta "Excluir", espera, e
 * recebe "Permissão negada" — parece defeito do sistema, não regra do perfil. A
 * tela tem que dizer a mesma coisa ANTES do clique.
 *
 * São duas maneiras de dizer, e quem escolhe é a propriedade global "Ações sem
 * permissão" (Propriedades do projeto no Studio; Preferências › Segurança no
 * próprio app, que vence):
 *
 *   • `disable` — o botão fica cinza com a dica do porquê (o histórico);
 *   • `hide`    — o botão sai da tela.
 *
 * Tudo aqui é FAIL-OPEN, e isso é deliberado: sem tela dona, sem ação
 * reconhecível, ou com o perfil sem marcação nenhuma, o botão fica exatamente
 * como sempre foi. Só uma chave EXPLICITAMENTE negada muda alguma coisa — a
 * plataforma nunca inventa restrição que ninguém pediu.
 *
 * Uso:
 *
 *   $d = ActionGuard::decide(MinhaTela::class, 'onDelete');
 *   if ($d['mode'] === 'hide') return '';            // some
 *   if ($d['mode'] === 'disable') { … $d['title'] }  // cinza, com a dica
 */
final class ActionGuard
{
    /** Modos aceitos, na mesma ordem da allowlist do app. */
    public const MODES = ['disable', 'hide'];

    /**
     * Histórico e fail-safe. Esconder um botão por causa de um valor inventado
     * faria a tela sumir sem ninguém ter pedido; deixá-lo cinza é visível e
     * reversível.
     */
    public const MODE_DEFAULT = 'disable';

    /** Serviço do app gerado que resolve a preferência (pode não existir). */
    private const APP_CONFIG = '\App\Service\Iam\AppConfigService';

    /**
     * Como os botões sem permissão aparecem: `disable` | `hide`.
     *
     * A preferência do APP vence a config do `.env` — é o único canal que chega
     * a um app já publicado. A leitura é delegada ao serviço do app quando ele
     * existe (o pacote roda em app antigo também); ele já memoiza por request e
     * a tela de Preferências limpa o memo ao gravar, então NÃO memoizamos aqui:
     * um segundo cache teria que ser invalidado por fora e ninguém lembraria.
     */
    public static function mode(): string
    {
        $servico = self::APP_CONFIG;
        if (class_exists($servico) && method_exists($servico, 'actionPermissionType')) {
            try {
                $modo = self::normalize($servico::actionPermissionType());
                if (in_array($modo, self::MODES, true)) {
                    return $modo;
                }
            } catch (\Throwable $e) {
                // app sem tabela de preferência / boot parcial — cai na config
            }
        }

        $modo = self::normalize(config('mad.permission.action_visibility', self::MODE_DEFAULT));

        return in_array($modo, self::MODES, true) ? $modo : self::MODE_DEFAULT;
    }

    /**
     * O que fazer com o botão desta ação.
     *
     * @param  string     $class   Classe DONA da permissão — a tela que o perfil
     *                             marca. Para um botão que abre outra tela, é a
     *                             tela de DESTINO.
     * @param  string     $method  Método da ação (`onDelete`, `onAprovar`) ou,
     *                             quando o chamador já sabe, a chave pronta do
     *                             vocabulário (`insert`).
     * @param  bool|null  $isNew   Só para `onSave`/`onSaveDraft`: a tela está sem
     *                             registro aberto? `null` = não dá para saber —
     *                             aí libera, que é o comportamento de sempre.
     *
     * @return array{mode: 'allow'|'hide'|'disable', key: string|null, title: string}
     */
    public static function decide(string $class, string $method, ?bool $isNew = null): array
    {
        $class  = ltrim(trim($class), '\\');
        $method = trim($method);

        if ($class === '' || $method === '') {
            return self::liberado(null);
        }

        $chave = self::keyOf($method, $isNew);
        if ($chave === null) {
            return self::liberado(null);
        }

        if (PermissionGate::allows($class, $chave)) {
            return self::liberado($chave);
        }

        return [
            'mode'  => self::mode(),
            'key'   => $chave,
            'title' => ActionVocab::denyTitle($chave, $class),
        ];
    }

    /**
     * Telas que se ABREM, sem cadastro em branco: navegar até elas não é
     * incluir nada (mad-framework#356).
     */
    private const OPEN_ONLY_BASES = [
        'Mad\\Grid\\MadDataGrid',
        'Mad\\Dashboard\\MadDashboard',
        'Mad\\Calendar\\MadKanban',
        'Mad\\Calendar\\MadCalendarComponent',
        'Mad\\Calendar\\MadGanttComponent',
        'Mad\\OrgChart\\MadOrgChart',
        'Mad\\Sheet\\MadSheet',
        'Mad\\Pdv\\MadPdvComponent',
        'Mad\\Reconcile\\MadReconcile',
        'Mad\\Site\\MadSitePage',
    ];

    /**
     * Botão que abre OUTRA tela sem nomear o método (`navigate="Tela"`).
     *
     * A decisão é pela tela de destino, em execução: um formulário abre um
     * cadastro em branco (= incluir, como sempre foi); uma listagem, um
     * dashboard, um kanban… só abrem — basta o perfil ter a tela. E quem não
     * tem a tela no grupo recebe a dica de acesso, não a de "incluir".
     *
     * @return array{mode: 'allow'|'disable'|'hide', key: string|null, title: string}
     */
    public static function decideOpen(string $class): array
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '') {
            return self::liberado(null);
        }

        // Sem sessão de perfil (fora do painel, app sem permissões carregadas)
        // não há o que perguntar — fail-open, como o resto.
        if (PermissionGate::isLogged() && is_array(session('programs')) && !PermissionGate::canAccess($class)) {
            return [
                'mode'  => self::mode(),
                'key'   => 'view',
                'title' => \Mad\I18n\MadLang::t('mad.no_screen_access'),
            ];
        }

        return self::opensNewRecord($class)
            ? self::decide($class, 'insert', true)
            : self::liberado(null);
    }

    /**
     * Abrir esta tela sem método é abrir um cadastro em branco? Não para as
     * bases que só se abrem ({@see OPEN_ONLY_BASES}) nem para a listagem pelo
     * nome (`…List`, a convenção do gerador). Classe que não se resolve fica
     * como formulário — o comportamento de antes.
     */
    private static function opensNewRecord(string $class): bool
    {
        $base = ($p = strrpos($class, '\\')) === false ? $class : substr($class, $p + 1);
        if (preg_match('/List$/', $base) === 1) {
            return false;
        }

        $fqcn = null;
        try {
            $fqcn = ClassSource::locate($class)['fqcn'] ?? null;
        } catch (\Throwable $e) {
            $fqcn = null;
        }
        $fqcn ??= $class;
        try {
            if (!class_exists($fqcn)) {
                return true;
            }
        } catch (\Throwable $e) {
            return true;
        }

        foreach (self::OPEN_ONLY_BASES as $somenteAbre) {
            if (is_a($fqcn, $somenteAbre, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A chave de permissão que este método consome, ou null quando ele não tem
     * uma (navegação, método irreconhecível, contexto desconhecido).
     */
    private static function keyOf(string $method, ?bool $isNew): ?string
    {
        // Chave do vocabulário escrita direto: é assim que um botão "Novo"
        // (abrir o formulário vazio) pede `insert` sem existir método nenhum.
        if (in_array($method, ActionVocab::KEYS, true)) {
            return $method;
        }

        if (ActionVocab::isNavigation($method)) {
            return null;
        }

        $chave = ActionVocab::keyFor($method, $isNew);
        if ($chave !== null) {
            return $chave;
        }

        // Ação própria do control (`onAprovar`): a chave é o próprio método.
        // Só o que tem cara de ação MAD — qualquer outra coisa fica liberada.
        return preg_match('/^on[A-Z]/', $method) === 1 ? $method : null;
    }

    /** @return array{mode: 'allow', key: string|null, title: string} */
    private static function liberado(?string $chave): array
    {
        return ['mode' => 'allow', 'key' => $chave, 'title' => ''];
    }

    private static function normalize(mixed $valor): string
    {
        return is_scalar($valor) ? mb_strtolower(trim((string) $valor)) : '';
    }
}
