<?php

namespace Mad\Ai\Console\Handlers;

use Mad\Ai\Console\SystemToolHandler;
use Mad\Ai\Console\ToolOutcome;
use Mad\Ai\Console\ToolRisk;
use Mad\Ai\SseSink;
use RuntimeException;

/**
 * backup_database — dump do banco do app gerado ANTES de operações de risco
 * (e como malha de segurança da migration). Seguro (somente leitura do banco).
 * Branch por driver: sqlite → copy; mysql/mariadb → mysqldump; pgsql → pg_dump.
 * Output em app/backup/agent/db/{ts}-{conn}.(sqlite|sql).
 *
 * É CONSTRUÍDO aqui (não havia backup de banco no framework). Best-effort: falha
 * (binário ausente, sem permissão) vira ToolOutcome::fail — e como é o 1º passo
 * das destrutivas, o Executor aborta sem tocar no banco.
 */
final class DatabaseBackupTool implements SystemToolHandler
{
    public function name(): string
    {
        return 'backup_database';
    }

    public function description(): string
    {
        return 'Cria um backup (dump) do banco de dados do sistema. Seguro — não altera dados. '
            . 'Use antes de rodar migrations ou qualquer operação de risco no banco.';
    }

    public function schemaSpec(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'connection' => ['type' => 'string', 'description' => 'Conexão a salvar (default: a principal do sistema).'],
            ],
        ];
    }

    public function risk(): ToolRisk
    {
        return ToolRisk::safe();
    }

    public function preview(array $args): array
    {
        return ['fields' => [['k' => 'conexão', 'v' => $this->conn($args)]]];
    }

    public function execute(array $args, SseSink $sink): ToolOutcome
    {
        $conn = $this->conn($args);
        $cfg  = config('database.connections.' . $conn);
        if (! is_array($cfg)) {
            return ToolOutcome::fail("Conexão desconhecida: {$conn}");
        }

        $dir = base_path('app/backup/agent/db');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $ts     = date('Ymd-His');
        $driver = (string) ($cfg['driver'] ?? '');

        try {
            $ref = match ($driver) {
                'sqlite'          => $this->dumpSqlite($cfg, $dir, $ts, $conn),
                'mysql', 'mariadb' => $this->dumpMysql($cfg, $dir, $ts, $conn),
                'pgsql'           => $this->dumpPgsql($cfg, $dir, $ts, $conn),
                default           => throw new RuntimeException("Backup não suportado para o driver '{$driver}'."),
            };
        } catch (\Throwable $e) {
            return ToolOutcome::fail('Falha no backup do banco: ' . $e->getMessage());
        }

        return ToolOutcome::success("Backup do banco criado: {$ref}", $ref, true, ['path' => $ref, 'connection' => $conn]);
    }

    /** O backup em si não é "revertível" — é o ponto de restauração de OUTRAS tools. */
    public function rollback(string $backupRef): bool
    {
        return false;
    }

    private function conn(array $args): string
    {
        $c = trim((string) ($args['connection'] ?? ''));

        return $c !== '' ? $c : (string) (config('mad.general.main_database') ?: config('database.default'));
    }

    private function dumpSqlite(array $cfg, string $dir, string $ts, string $conn): string
    {
        $src = (string) ($cfg['database'] ?? '');
        if ($src === '' || ! is_file($src)) {
            throw new RuntimeException('Arquivo sqlite não encontrado: ' . $src);
        }
        $dest = "{$dir}/{$ts}-{$conn}.sqlite";
        if (! @copy($src, $dest)) {
            throw new RuntimeException('copy() falhou para ' . $dest);
        }

        return $dest;
    }

    private function dumpMysql(array $cfg, string $dir, string $ts, string $conn): string
    {
        $dest = "{$dir}/{$ts}-{$conn}.sql";
        $cmd  = sprintf(
            'mysqldump --single-transaction --host=%s --port=%s --user=%s %s > %s 2>&1',
            escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($cfg['port'] ?? '3306')),
            escapeshellarg((string) ($cfg['username'] ?? 'root')),
            escapeshellarg((string) ($cfg['database'] ?? '')),
            escapeshellarg($dest),
        );

        $this->run($cmd, ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]);

        return $dest;
    }

    private function dumpPgsql(array $cfg, string $dir, string $ts, string $conn): string
    {
        $dest = "{$dir}/{$ts}-{$conn}.sql";
        $cmd  = sprintf(
            'pg_dump --host=%s --port=%s --username=%s --no-owner --file=%s %s 2>&1',
            escapeshellarg((string) ($cfg['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($cfg['port'] ?? '5432')),
            escapeshellarg((string) ($cfg['username'] ?? 'postgres')),
            escapeshellarg($dest),
            escapeshellarg((string) ($cfg['database'] ?? '')),
        );

        $this->run($cmd, ['PGPASSWORD' => (string) ($cfg['password'] ?? '')]);

        return $dest;
    }

    /** Executa o dump com a senha em env (fora da lista de processos). */
    private function run(string $cmd, array $env): void
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $merged      = array_merge($_ENV, $_SERVER, $env);
        $proc        = @proc_open($cmd, $descriptors, $pipes, null, $merged);
        if (! is_resource($proc)) {
            throw new RuntimeException('Não foi possível iniciar o dump (proc_open).');
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        foreach ($pipes as $p) {
            @fclose($p);
        }
        $code = proc_close($proc);
        if ($code !== 0) {
            throw new RuntimeException('dump retornou ' . $code . ': ' . trim((string) $out));
        }
    }
}
