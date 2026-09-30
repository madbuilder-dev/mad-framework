<?php

namespace Mad\Ai\Console\Handlers;

use App\Service\Builder\BuilderCodeSyncService;
use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;

/**
 * update_permissions — sincroniza as permissões (grupos → programas/telas) do
 * projeto a partir do MadBuilder. NÃO recebe grant/revoke do modelo: (1) busca o
 * seed canônico de permissão na nuvem (GET /api/app/agent/permissions, project-token)
 * e (2) aplica via a API interna (/agent-console/v1/apply/permissions — grava o
 * seed com backup e RODA `db:seed` p/ aplicar os grants). Reversível por batch.
 */
final class PermissionsTool implements SystemToolHandler
{
    use SyncsFromCloud;

    public function name(): string
    {
        return 'update_permissions';
    }

    public function description(): string
    {
        return 'Sincroniza as permissões (grupos → programas/telas) do projeto a partir do MadBuilder, a fonte '
            . 'da verdade. Busca o seed canônico de permissão gerado na nuvem e aplica (grava + roda) com backup '
            . 'e reversível. A IA NÃO define permissões à mão — só dispara a sincronização (sem argumentos).';
    }

    public function schemaSpec(): array
    {
        return ['type' => 'object', 'properties' => (object) []];
    }

    public function risk(): ToolRisk
    {
        return ToolRisk::high(true);
    }

    public function preview(array $args): array
    {
        return ['fields' => [
            ['k' => 'ação',      'v' => 'Sincronizar as permissões do projeto da nuvem'],
            ['k' => 'origem',    'v' => 'MadBuilder (grupos → programas canônicos)'],
            ['k' => 'segurança', 'v' => 'backup do seed + reversível por batch'],
        ]];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        try {
            $files = $this->fetchArtifacts('permissions');
        } catch (\Throwable $e) {
            return ToolOutcome::fail('Falha ao buscar as permissões da nuvem: ' . $e->getMessage());
        }
        if ($files === []) {
            return ToolOutcome::fail('A nuvem não retornou nenhum seed de permissão.');
        }

        $res = $this->applyArtifacts('permissions', $files);
        if (empty($res['ok'])) {
            return ToolOutcome::fail('Falha ao aplicar as permissões: ' . ($res['message'] ?? 'erro desconhecido'));
        }

        $batchId = (string) ($res['batchId'] ?? '');
        $seeded  = is_array($res['seeded'] ?? null) ? count($res['seeded']) : 0;

        return ToolOutcome::success(
            sprintf('Permissões sincronizadas da nuvem (%d seed[s], batch %s).', $seeded, $batchId),
            $batchId,
            true,
            ['batchId' => $batchId, 'seeded' => $res['seeded'] ?? []],
        );
    }

    public function rollback(string $backupRef): bool
    {
        if ($backupRef === '') {
            return false;
        }

        return ! empty(BuilderCodeSyncService::rollback($backupRef)['ok']);
    }
}
