<?php
namespace Mad\Calendar;

/**
 * MadKanbanStandalone — concretização instanciável do MadKanban.
 *
 * Usado pelo MadKanbanCompiler quando o `<mad-kanban>` aparece numa view cujo
 * host (`$that`) não é subclass de MadKanban. Espelha MadGanttStandalone: serve
 * só como helper de render inline — toda config vem via `_renderInlineKanban()`,
 * e os callbacks (clickTarget etc.) apontam para classes-alvo, não para esta
 * instância.
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadKanbanStandalone extends MadKanban
{
    /**
     * @internal Override pra satisfazer MadComponent::view() (abstract). Esta
     * classe nunca passa pelo render normal — o HTML sai de _renderInlineKanban().
     */
    protected function view(): string|array
    {
        return ['components.kanban', ['__component' => $this]];
    }
}
