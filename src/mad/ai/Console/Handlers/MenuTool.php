<?php

namespace Mad\Ai\Console\Handlers;

use App\Service\Builder\BuilderCodeSyncService;
use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;

/**
 * update_menu — sincroniza a família de menu (menu.xml/top_menu.xml/…) do projeto
 * a partir do MadBuilder. NÃO recebe XML do modelo: (1) busca o menu canônico na
 * nuvem (GET /api/app/agent/menu, project-token) e (2) aplica via a API interna
 * (/agent-console/v1/apply/menu — valida o XML de cada arquivo, backup atômico,
 * grava). Reversível por batch.
 */
final class MenuTool implements SystemToolHandler
{
    use SyncsFromCloud;

    public function name(): string
    {
        return 'update_menu';
    }

    public function description(): string
    {
        return 'Sincroniza os menus (menu.xml, top_menu.xml, menu-navbar-dropdown.xml) do projeto a partir '
            . 'do MadBuilder, a fonte da verdade. Busca o XML canônico gerado na nuvem, valida e aplica com '
            . 'backup atômico e reversível. A IA NÃO escreve XML — só dispara a sincronização (sem argumentos).';
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
            ['k' => 'ação',      'v' => 'Sincronizar os menus do projeto da nuvem'],
            ['k' => 'origem',    'v' => 'MadBuilder (árvore de módulos canônica)'],
            ['k' => 'segurança', 'v' => 'valida XML + backup atômico, reversível'],
        ]];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        try {
            $files = $this->fetchArtifacts('menu');
        } catch (\Throwable $e) {
            return ToolOutcome::fail('Falha ao buscar o menu da nuvem: ' . $e->getMessage());
        }
        if ($files === []) {
            return ToolOutcome::fail('A nuvem não retornou nenhum arquivo de menu.');
        }

        $res = $this->applyArtifacts('menu', $files);
        if (empty($res['ok'])) {
            return ToolOutcome::fail('Falha ao aplicar o menu: ' . ($res['message'] ?? 'erro desconhecido'));
        }

        $batchId = (string) ($res['batchId'] ?? '');

        return ToolOutcome::success(
            sprintf('%d arquivo(s) de menu sincronizados (batch %s).', (int) ($res['applied'] ?? 0), $batchId),
            $batchId,
            true,
            ['batchId' => $batchId, 'applied' => $res['applied'] ?? 0],
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
