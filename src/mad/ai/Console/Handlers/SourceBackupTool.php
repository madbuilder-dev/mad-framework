<?php

namespace Mad\Ai\Console\Handlers;

use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;
use RuntimeException;

/**
 * backup_source_code — snapshot tar.gz do código do app (app/ + resources/views).
 * Seguro (somente leitura). Malha de segurança para update_source_code em massa.
 * É CONSTRUÍDO aqui (o backup por-arquivo do BuilderCodeSyncService cobre o
 * caminho de batch; este é o snapshot amplo, sob demanda).
 */
final class SourceBackupTool implements SystemToolHandler
{
    private const TARGETS = ['app', 'resources/views', 'routes'];

    public function name(): string
    {
        return 'backup_source_code';
    }

    public function description(): string
    {
        return 'Cria um snapshot (tar.gz) do código-fonte do sistema (app/, resources/views/, routes/). '
            . 'Seguro — não altera nada. Use antes de mudanças amplas de código.';
    }

    public function schemaSpec(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function risk(): ToolRisk
    {
        return ToolRisk::safe();
    }

    public function preview(array $args): array
    {
        return ['fields' => [['k' => 'alvo', 'v' => implode(', ', self::TARGETS)]]];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        $dir = base_path('app/backup/agent/code');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $dest = $dir . '/' . date('Ymd-His') . '.tar.gz';

        $targets = array_values(array_filter(self::TARGETS, static fn ($t) => is_dir(base_path($t))));
        if ($targets === []) {
            return ToolOutcome::fail('Nenhum diretório de código encontrado para backup.');
        }

        $cmd = 'tar -czf ' . escapeshellarg($dest) . ' -C ' . escapeshellarg(base_path());
        foreach ($targets as $t) {
            $cmd .= ' ' . escapeshellarg($t);
        }
        $cmd .= ' 2>&1';

        exec($cmd, $out, $code);
        if ($code !== 0 || ! is_file($dest)) {
            return ToolOutcome::fail('Falha no snapshot de código (tar): ' . trim(implode(' ', $out)));
        }

        return ToolOutcome::success(
            'Snapshot de código criado: ' . $dest,
            $dest,
            true,
            ['path' => $dest, 'sizeBytes' => (int) (@filesize($dest) ?: 0)],
        );
    }

    public function rollback(string $backupRef): bool
    {
        return false;
    }
}
