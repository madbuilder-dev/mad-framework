<?php

namespace Mad\Ai\Console;

use Mad\Ai\SseSink;

/**
 * SystemToolHandler — uma tool de backend do Command Center que OPERA o sistema
 * do cliente (código, menu, migration, permissões, backups).
 *
 * Diferente das render tools (visualização) e das MCP data tools (dados): estas
 * MUTAM o app gerado. Por isso o ciclo é gated:
 *   1. o modelo chama → SystemTool emite `confirm` (preview()/risk) + stash;
 *   2. usuário aprova → SystemToolExecutor chama execute() (backup→mutate→verify);
 *   3. verify falha → Executor chama rollback(backupRef).
 *
 * `execute()` OWNS o backup-antes-de-mutar (é atômico com a mutação em vários
 * casos, ex.: BuilderCodeSyncService::applyFiles). Falha de backup → devolve
 * ToolOutcome::fail SEM mutar (não lançar) — assim o banco/código fica intacto.
 */
interface SystemToolHandler
{
    public function name(): string;

    public function description(): string;

    /** Spec JSON-Schema do input (convertido via JsonSchemaBuilder::properties). @return array<string,mixed> */
    public function schemaSpec(): array;

    public function risk(): ToolRisk;

    /**
     * Resumo p/ o cartão de confirmação: campos {k,v} e, opcionalmente, um diff.
     *
     * @param  array<string,mixed>  $args
     * @return array{fields:list<array{k:string,v:string}>,diff?:array<string,mixed>}
     */
    public function preview(array $args): array;

    /**
     * Executa a ação JÁ APROVADA — OWNS backup→mutate→verify. Falha de backup
     * deve devolver ToolOutcome::fail (não lançar), p/ não mutar pela metade.
     *
     * @param  array<string,mixed>  $args
     */
    public function execute(array $args, SseSink $sink): ToolOutcome;

    /** Reverte best-effort a partir do backupRef devolvido por execute(). */
    public function rollback(string $backupRef): bool;
}
