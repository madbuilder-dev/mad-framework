<?php
namespace Mad\Calendar;

/**
 * MadGanttStandalone — concretizacao instanciavel do MadGanttComponent.
 *
 * Usado pelo MadGanttCompiler quando o `<mad-gantt>` aparece em uma view
 * cujo host (`$that`) nao e subclass de MadGanttComponent. Funciona como
 * helper de render: toda config vem via `_applyInlineConfig()`, e os
 * callbacks (`on-task-click="onFoo"`) sao despachados pelo JS para o host
 * externo (a componente da pagina atual), nao para esta instancia.
 *
 * @internal Nao instanciar diretamente — use o compiler.
 */
class MadGanttStandalone extends MadGanttComponent
{
    /**
     * @internal Override pra evitar invocacao acidental — esta classe nunca
     * passa pela renderizacao normal de MadComponent::render(). O render
     * acontece via `_renderInlineGantt()`.
     */
    protected function view(): string|array
    {
        return ['components.gantt-component-default', ['gantt' => $this->gantt]];
    }

    /**
     * Vários <mad-gantt> inline na mesma página compartilham esta classe —
     * um id fixo colidiria as preferências. Vazio = client deriva rota+posição.
     */
    protected function ganttStorageId(): string
    {
        return '';
    }
}
