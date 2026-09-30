<?php

namespace Mad\Ai\Console\Handlers;

use Illuminate\Support\Facades\Artisan;
use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;

/**
 * run_migration — roda migrations do app (migrate / rollback / status) via
 * Artisan. CRÍTICA: faz DUMP do banco antes de migrate/rollback (backup-antes-
 * de-mutar). `migrate:rollback` só reverte migrations com `down()` — por isso o
 * dump é a malha de segurança real.
 *
 * O legado Mad\Service\Migration\MadMigration não foi portado; aqui é Laravel
 * puro (Artisan::call), com --database por conexão de app.
 */
final class MigrationTool implements SystemToolHandler
{
    private const ACTIONS     = ['migrate', 'rollback', 'status'];
    private const CONNECTIONS = ['business', 'iam', 'comm', 'ged', 'ai', 'log'];

    public function name(): string
    {
        return 'run_migration';
    }

    public function description(): string
    {
        return 'Roda migrations do banco: migrate (aplicar), rollback (reverter último lote) ou status. '
            . 'Crítico: faz backup (dump) do banco antes de migrate/rollback. O rollback só reverte migrations com down().';
    }

    public function schemaSpec(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'action'   => ['type' => 'string', 'enum' => self::ACTIONS, 'description' => 'migrate | rollback | status (default migrate).'],
                'database' => ['type' => 'string', 'enum' => self::CONNECTIONS, 'description' => 'Conexão (default business).'],
                'pretend'  => ['type' => 'boolean', 'description' => 'Dry-run: mostra o SQL sem executar.'],
            ],
        ];
    }

    public function risk(): ToolRisk
    {
        return ToolRisk::critical(true);
    }

    public function preview(array $args): array
    {
        return [
            'fields' => [
                ['k' => 'ação', 'v' => $this->action($args)],
                ['k' => 'conexão', 'v' => $this->conn($args)],
                ['k' => 'dry-run', 'v' => ! empty($args['pretend']) ? 'sim' : 'não'],
            ],
        ];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        $action  = $this->action($args);
        $conn    = $this->conn($args);
        $pretend = ! empty($args['pretend']);

        // status: leitura — sem mutação, sem backup.
        if ($action === 'status') {
            try {
                Artisan::call('migrate:status', ['--database' => $conn]);

                return ToolOutcome::success('migrate:status executado.', '', true, ['output' => trim(Artisan::output())]);
            } catch (\Throwable $e) {
                return ToolOutcome::fail('Falha no migrate:status: ' . $e->getMessage());
            }
        }

        // migrate / rollback: backup-antes-de-mutar (dump do banco). Se o dump
        // falhar, ABORTA sem tocar o banco.
        $backupRef = '';
        if (! $pretend) {
            $backup = (new DatabaseBackupTool())->execute(['connection' => $conn], $sink);
            if (! $backup->ok) {
                return ToolOutcome::fail('Backup do banco falhou; migration abortada: ' . $backup->message);
            }
            $backupRef = $backup->backupRef;
        }

        $cmd  = $action === 'rollback' ? 'migrate:rollback' : 'migrate';
        $opts = ['--database' => $conn, '--force' => true];
        if ($pretend) {
            $opts['--pretend'] = true;
        }

        try {
            Artisan::call($cmd, $opts);
            $output = trim(Artisan::output());
        } catch (\Throwable $e) {
            return ToolOutcome::fail('Falha na migration: ' . $e->getMessage());
        }

        // Verify (best-effort): migrate:status volta a rodar sem erro.
        $verifyOk = true;
        try {
            Artisan::call('migrate:status', ['--database' => $conn]);
        } catch (\Throwable $e) {
            $verifyOk = false;
        }

        return ToolOutcome::success(
            ($pretend ? 'Dry-run de ' : '') . $cmd . ' concluído.',
            $backupRef,
            $verifyOk,
            ['output' => $output, 'connection' => $conn],
        );
    }

    /** Verify-fail → reverte o último lote (best-effort). O dump é a rede maior. */
    public function rollback(string $backupRef): bool
    {
        try {
            Artisan::call('migrate:rollback', ['--force' => true]);

            return true;
        } catch (\Throwable $e) {
            error_log('[agent-console] migration rollback: ' . $e->getMessage());

            return false;
        }
    }

    private function action(array $args): string
    {
        $a = (string) ($args['action'] ?? 'migrate');

        return in_array($a, self::ACTIONS, true) ? $a : 'migrate';
    }

    private function conn(array $args): string
    {
        $c = (string) ($args['database'] ?? 'business');

        return in_array($c, self::CONNECTIONS, true) ? $c : 'business';
    }
}
