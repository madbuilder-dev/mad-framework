<?php

namespace Mad\Ai\Console;

use Mad\Ai\SystemPromptAssembler;

/**
 * AgentConsolePromptAssembler — system prompt do Mad Agent Command Center (admin).
 *
 * Diferente do embed (SystemPromptAssembler): aqui o agente OPERA o sistema —
 * código, menu, migration, permissões, backups. O prompt:
 *   (1) identidade ops + público admin;
 *   (2) inventário das system tools (com risco) deste registry;
 *   (3) a disciplina dura: nunca mutar sem confirmação; backup antes de destrutiva;
 *   (4) reusa o bloco de visualização (render tools show_*) do embed.
 */
final class AgentConsolePromptAssembler
{
    public static function assemble(SystemToolRegistry $registry): string
    {
        $parts = [
            self::identity(),
            self::toolInventory($registry),
            self::opsRules(),
            self::renderGuidance(),
        ];

        return implode("\n\n", array_filter($parts, static fn ($p) => trim((string) $p) !== ''));
    }

    private static function identity(): string
    {
        // Nome do app (general.title) — `application` é o identificador interno
        // e no esqueleto vale 'mad_framework' ("agente administrativo do mad_framework").
        $app = \Mad\Core\AppConfig::appDisplayName('o sistema');

        return "# Mad Agent — Central de Comando\n\n"
            . "Você é o agente administrativo do {$app}, falando com um ADMINISTRADOR. Além de consultar dados, "
            . "você pode OPERAR o sistema com tools de backend (código, menu, migration, permissões, backups). "
            . 'Responda em português, objetivo e curto. Toda ação que MUDA o sistema é de alto impacto — trate com cuidado.';
    }

    private static function toolInventory(SystemToolRegistry $registry): string
    {
        $desc = $registry->descriptors();
        if ($desc === []) {
            return '';
        }

        $lines = ['## Tools de backend (operam o sistema)'];
        foreach ($desc as $name => $d) {
            $risk = (string) ($d['risk']['risk_level'] ?? 'low');
            $tag  = ! empty($d['risk']['requires_confirmation']) ? " [risco: {$risk} — exige confirmação]" : " [risco: {$risk} — seguro]";
            $lines[] = "- {$name}: " . trim((string) $d['description']) . $tag;
        }

        return implode("\n", $lines);
    }

    private static function opsRules(): string
    {
        return <<<'RULES'
## Regras de operação (a trava)
- NUNCA execute uma tool que muda o sistema (update_source_code/menu/permissões, run_migration) por conta própria. Ao chamar uma dessas, um cartão de confirmação é exibido e a ação SÓ roda depois que o admin aprovar. Anuncie em 1 frase o que vai fazer e aguarde.
- Para qualquer mudança destrutiva ou irreversível, garanta um BACKUP antes (backup_database antes de migration; o update_source_code já faz backup por batch). Em dúvida, faça o backup primeiro.
- Mudança de código: envie o conteúdo COMPLETO do arquivo (não trechos). Prefira UM arquivo por vez para o admin revisar o diff.
- Migration: trate como crítica. Se não houver rollback (`down()`), avise que é irreversível antes de propor.
- Se a verificação pós-execução falhar, o sistema faz rollback automático a partir do backup — relate isso com clareza.
- Depois de uma ação aplicada, resuma o efeito em 1 frase e ofereça o rollback quando existir.
RULES;
    }

    /** Reaproveita o guia de visualização (render tools) do embed — mesmo contrato de blocos. */
    private static function renderGuidance(): string
    {
        // O embed já mantém o RENDER_SYSTEM_PROMPT alinhado ao contract/prompt.ts.
        // Reusamos o conjunto completo (sem manifest/inventário de dados).
        return SystemPromptAssembler::assemble(null, []);
    }
}
