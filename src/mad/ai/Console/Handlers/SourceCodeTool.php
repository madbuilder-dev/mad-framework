<?php

namespace Mad\Ai\Console\Handlers;

use App\Service\Builder\BuilderCodeSyncService;
use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;

/**
 * update_source_code — sincroniza TODO o código (telas/páginas e códigos) do
 * projeto a partir do MadBuilder (fonte da verdade). NÃO recebe código do modelo:
 * (1) busca os arquivos canônicos na nuvem (GET /api/app/agent/code, project-token)
 * e (2) aplica via a API interna (/agent-console/v1/apply/code →
 * BuilderCodeSyncService::applyFiles: stage → `php -l` → backup → rename atômico →
 * audit). O app casa cada arquivo por controller+diretório. Reversível por batch.
 */
final class SourceCodeTool implements SystemToolHandler
{
    use SyncsFromCloud;

    public function name(): string
    {
        return 'update_source_code';
    }

    public function description(): string
    {
        return 'Sincroniza TODO o código (páginas/telas e códigos) do projeto a partir do MadBuilder, '
            . 'a fonte da verdade. Busca os arquivos canônicos gerados na nuvem e aplica localmente por '
            . 'controller+diretório, com `php -l`, backup atômico e reversível. A IA NÃO escreve código — '
            . 'só dispara a sincronização (sem argumentos).';
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
            ['k' => 'ação',      'v' => 'Sincronizar o código do projeto da nuvem'],
            ['k' => 'origem',    'v' => 'MadBuilder (geradores canônicos)'],
            ['k' => 'segurança', 'v' => 'php -l + backup atômico, reversível por batch'],
        ]];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        try {
            $files = $this->fetchArtifacts('code');
        } catch (\Throwable $e) {
            return ToolOutcome::fail('Falha ao buscar o código da nuvem: ' . $e->getMessage());
        }
        if ($files === []) {
            return ToolOutcome::fail('A nuvem não retornou nenhum arquivo de código.');
        }

        $res = $this->applyArtifacts('code', $files);
        if (empty($res['ok'])) {
            return ToolOutcome::fail('Falha ao aplicar o código: ' . ($res['message'] ?? 'erro desconhecido'));
        }

        $batchId = (string) ($res['batchId'] ?? '');

        return ToolOutcome::success(
            sprintf('%d arquivo(s) sincronizados da nuvem (batch %s).', (int) ($res['applied'] ?? 0), $batchId),
            $batchId,
            true,
            [
                'batchId' => $batchId,
                'applied' => $res['applied'] ?? 0,
                'new'     => $res['new'] ?? 0,
                'changed' => $res['changed'] ?? 0,
            ],
        );
    }

    public function rollback(string $backupRef): bool
    {
        if ($backupRef === '') {
            return false;
        }
        $r = BuilderCodeSyncService::rollback($backupRef);

        return ! empty($r['ok']);
    }
}
