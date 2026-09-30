<?php
namespace Mad\Sheet;

/**
 * MadSheetStandalone — concretização instanciável do MadSheet.
 *
 * Usado pelo MadSheetCompiler quando o `<mad-sheet>` aparece numa view cujo
 * host (`$that`) não é subclass de MadSheet. Espelha MadKanbanStandalone:
 * toda config vem via `_renderInlineSheet()`.
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadSheetStandalone extends MadSheet
{
}
