<?php
namespace Mad\Reconcile;

/**
 * MadReconcileStandalone — concretização instanciável do MadReconcile.
 *
 * Usado pelo MadReconcileCompiler quando o `<mad-reconcile>` aparece numa
 * view cujo host (`$that`) não é subclass de MadReconcile. Espelha
 * MadKanbanStandalone: toda config vem via `_renderInlineReconcile()`.
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadReconcileStandalone extends MadReconcile
{
}
