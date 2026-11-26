<?php

namespace Mad\Security;

use Attribute;

/**
 * MadPermission — o que a TELA diz sobre um botão seu na hora de montar a lista
 * de permissões do programa.
 *
 * A descoberta automática lê os métodos públicos da tela e oferece cada um como
 * caixinha na tela de Perfis. Duas coisas ela não tem como adivinhar, e este
 * atributo resolve as duas:
 *
 *   1. **O nome que o usuário vê.** Sem atributo, o rótulo sai do nome do
 *      método (`onAprovarOrcamento` → "Aprovar orcamento") — serve, mas fica
 *      sem acento e sem as palavras do negócio. O rótulo escrito à mão vale
 *      também como afirmação: um método cujo NOME parece de reação de tela
 *      (`onChangePlan`) mas que muda dado de verdade entra na lista por causa
 *      dele.
 *   2. **Que aquele método não é uma permissão.** Um método público que só
 *      reage à tela (recarregar um combo, recalcular um total) viraria uma
 *      caixinha que ninguém entende e que, desmarcada, quebraria o formulário.
 *
 * ```php
 * #[MadPermission('Aprovar orçamento')]
 * public function onAprovarOrcamento(int $id): MadResponse { ... }
 *
 * #[MadPermission(permission: false)]
 * public function onControllerChange(string $valor): void { ... }
 *
 * #[MadPermission('Prévia', inherit: 'view')]
 * public function onPrevia(int $id): MadResponse { ... }
 * ```
 *
 * `inherit: 'view'` é para ação de LEITURA (prévia, ver, contagens): quem pode
 * ver a tela pode usá-la, sem ela constar da lista do perfil. Sem ele, a ação
 * própria nova num perfil que marca ações nasce negada — o certo para
 * "Assinar laudo", o errado para "Prévia". Ela continua sendo uma caixinha em
 * Perfis: o perfil que a desmarca nega. O app e o MadBuilder a marcam sozinhos,
 * quando ela aparece, em quem já tem "Visualizar" naquela tela; o que o dono
 * desmarcar depois fica desmarcado. Só "view" é aceito — outro valor é
 * ignorado (a ação segue a regra comum, nunca fica mais aberta).
 *
 * O MadBuilder lê o MESMO atributo do fonte da tela (por texto, sem executar
 * nada) para mostrar a mesma palavra no editor. Por isso os nomes dos
 * argumentos — `label` e `permission` — fazem parte do contrato: renomeá-los
 * aqui faria o editor e o app discordarem. O mesmo vale para `inherit`.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class MadPermission
{
    /**
     * @param string|null $label      Nome da ação como o usuário a vê. Null =
     *                                deriva do nome do método. Preenchido, a
     *                                ação entra na lista mesmo que o nome do
     *                                método pareça de navegação.
     * @param bool        $permission `false` tira o método da lista: ele não é
     *                                uma permissão, é reação de tela.
     * @param string|null $inherit    `'view'`: a ação segue o Visualizar do
     *                                perfil (quem vê a tela usa). Outro valor =
     *                                null (sem herança).
     */
    public function __construct(
        public ?string $label = null,
        public bool $permission = true,
        ?string $inherit = null,
    ) {
        $inherit       = $inherit === null ? null : strtolower(trim($inherit));
        $this->inherit = in_array($inherit, self::INHERITABLE, true) ? $inherit : null;
    }

    /** Chaves de quem uma ação pode herdar. */
    public const INHERITABLE = ['view'];

    /** Chave que a ação segue quando não consta da lista do perfil, ou null. */
    public ?string $inherit;
}
