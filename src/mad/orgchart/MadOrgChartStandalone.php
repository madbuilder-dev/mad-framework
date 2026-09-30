<?php
namespace Mad\OrgChart;

/**
 * MadOrgChartStandalone — concretização instanciável do MadOrgChart.
 *
 * Usado pelo MadOrgChartCompiler quando o `<mad-org-chart>` aparece numa
 * view cujo host (`$that`) não é subclass de MadOrgChart. Espelha
 * MadKanbanStandalone: toda config vem via `_renderInlineOrgChart()`.
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadOrgChartStandalone extends MadOrgChart
{
}
