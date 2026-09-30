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
 * ```
 *
 * O MadBuilder lê o MESMO atributo do fonte da tela (por texto, sem executar
 * nada) para mostrar a mesma palavra no editor. Por isso os nomes dos
 * argumentos — `label` e `permission` — fazem parte do contrato: renomeá-los
 * aqui faria o editor e o app discordarem.
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
     */
    public function __construct(
        public ?string $label = null,
        public bool $permission = true,
    ) {
    }
}
