<?php
namespace Mad\Agenda;

/**
 * MadAgendaStandalone — concretização instanciável do MadAgenda.
 *
 * Usado pelo MadAgendaCompiler quando o `<mad-agenda>` aparece numa tela cujo
 * host (`$that`) não é subclass de MadAgenda: a config vem da tag e os métodos
 * nomeados nela (`source-method`, `on-move`…) são da tela dona
 * ({@see MadAgenda::_setExternalHost()}).
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadAgendaStandalone extends MadAgenda
{
}
