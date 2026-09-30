<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * mad:trace-infra — coleta métricas de INFRA (host + filas) e envia ao receptor
 * MadTrace como snapshot schema 2 kind:infra (MadTrace::ingestInfra). É o
 * "agente cron" citado no docblock do MadTrace::ingestInfra: roda fora do
 * caminho da request, agendado a cada minuto em routes/console.php.
 *
 * Coletores são best-effort e cross-platform (Linux /proc + macOS sysctl/vm_stat),
 * degradando p/ null quando a métrica não é determinável no SO. NUNCA derruba o
 * cron — o envio em si é fail-soft dentro do MadTrace.
 *
 * Contrato dos campos casa com o receptor (ApmIngestionService::ingestInfra →
 * MadtraceHost / MadtraceQueue): host (chave) + cpu/mem/disk/load/uptime_d/
 * workers; name (chave) + pending/processing/failed_pending/oldest_wait_s/conn.
 */
class TraceInfraCommand extends Command
{
    protected $signature = 'mad:trace-infra {--dry : Coleta e imprime o payload sem enviar}';

    protected $description = 'Envia snapshot de infra (host CPU/mem/disco/load + filas) ao MadTrace';

    public function handle(): int
    {
        if (!class_exists('MadTrace')) {
            $this->warn('MadTrace indisponível (autoload).');
            return self::FAILURE;
        }
        if (!\MadTrace::performanceEnabled()) {
            $this->warn('MadTrace performance OFF (mad.trace.enabled/performance) — nada a enviar.');
            return self::SUCCESS;
        }

        $payload = [
            'hosts'  => [$this->collectHost()],
            'queues' => $this->collectQueues(),
        ];

        if ($this->option('dry')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        \MadTrace::ingestInfra($payload);

        $send   = \MadTrace::_lastSend();
        $hosts  = count($payload['hosts']);
        $queues = count($payload['queues']);
        $status = $send['status'] ?? -1;
        $extra  = ($send && ($send['error'] ?? '') !== '') ? '  ' . $send['error'] : '';

        $this->info(sprintf(
            'infra enviado: hosts=%d queues=%d -> status=%s ep=%s%s',
            $hosts, $queues, $status, $send['endpoint'] ?? '(n/a)', $extra
        ));

        return self::SUCCESS;
    }

    // ───────────────────────────────── host ─────────────────────────────────

    /** @return array<string,mixed> */
    private function collectHost(): array
    {
        $load = $this->loadAvg();
        $ncpu = $this->cpuCount();
        $cpu  = ($load !== null && $ncpu > 0) ? round(min(100.0, ($load[0] / $ncpu) * 100), 1) : null;

        // host é a CHAVE do upsert — nunca pode faltar. Os demais campos com null
        // são removidos para o receptor aplicar seus próprios defaults (?? 0).
        return array_filter([
            'host'     => gethostname() ?: php_uname('n') ?: 'unknown',
            'role'     => 'app',
            'cpu'      => $cpu,
            'mem'      => $this->memPercent(),
            'disk'     => $this->diskPercent(),
            'load'     => $load,
            'uptime_d' => $this->uptimeDays(),
            'workers'  => $this->queueWorkers(),
        ], static fn ($v) => $v !== null);
    }

    /** loadavg [1m,5m,15m] ou null. */
    private function loadAvg(): ?array
    {
        if (function_exists('sys_getloadavg')) {
            $l = @sys_getloadavg();
            if (is_array($l) && count($l) >= 3) {
                return [round((float) $l[0], 2), round((float) $l[1], 2), round((float) $l[2], 2)];
            }
        }
        return null;
    }

    private function cpuCount(): int
    {
        $n = 0;
        if (is_readable('/proc/cpuinfo')) {
            $n = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
        }
        if ($n < 1 && function_exists('shell_exec')) {
            $o = @shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null');
            if (!$o) { $o = @shell_exec('sysctl -n hw.ncpu 2>/dev/null'); }
            $n = (int) trim((string) $o);
        }
        return max(1, $n);
    }

    /** % de memória usada (Linux /proc/meminfo, macOS hw.memsize+vm_stat) ou null. */
    private function memPercent(): ?float
    {
        if (is_readable('/proc/meminfo')) {
            $m = (string) @file_get_contents('/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $m, $t) && preg_match('/MemAvailable:\s+(\d+)/', $m, $a)) {
                $total = (float) $t[1];
                if ($total > 0) {
                    return round((1 - ((float) $a[1]) / $total) * 100, 1);
                }
            }
        }
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $total = (float) trim((string) @shell_exec('sysctl -n hw.memsize 2>/dev/null'));
            $vm    = (string) @shell_exec('vm_stat 2>/dev/null');
            if ($total > 0 && preg_match('/page size of (\d+)/', $vm, $ps)) {
                $page = (float) $ps[1];
                $freeBytes = ($this->vmStat($vm, 'Pages free') + $this->vmStat($vm, 'Pages inactive')) * $page;
                if ($freeBytes > 0) {
                    return round((1 - $freeBytes / $total) * 100, 1);
                }
            }
        }
        return null;
    }

    private function vmStat(string $vm, string $key): float
    {
        return preg_match('/' . preg_quote($key, '/') . ':\s+(\d+)\./', $vm, $m) ? (float) $m[1] : 0.0;
    }

    /** % de disco usado da partição do app, ou null. */
    private function diskPercent(): ?float
    {
        $path  = defined('PATH') ? PATH : base_path();
        $total = @disk_total_space($path);
        $free  = @disk_free_space($path);
        if ($total && $total > 0 && $free !== false) {
            return round((1 - ((float) $free) / ((float) $total)) * 100, 1);
        }
        return null;
    }

    private function uptimeDays(): ?int
    {
        if (is_readable('/proc/uptime')) {
            $u = (float) strtok((string) @file_get_contents('/proc/uptime'), ' ');
            return (int) floor($u / 86400);
        }
        if (PHP_OS_FAMILY === 'Darwin' && function_exists('shell_exec')) {
            $o = (string) @shell_exec('sysctl -n kern.boottime 2>/dev/null'); // { sec = 170..., usec = ... }
            if (preg_match('/sec\s*=\s*(\d+)/', $o, $m)) {
                return (int) floor((time() - (int) $m[1]) / 86400);
            }
        }
        return null;
    }

    private function queueWorkers(): int
    {
        if (function_exists('shell_exec')) {
            $n = (int) trim((string) @shell_exec("pgrep -fc 'queue:work' 2>/dev/null"));
            if ($n > 0) {
                return $n;
            }
        }
        return 0;
    }

    // ──────────────────────────────── filas ─────────────────────────────────

    /**
     * Profundidade das filas do driver `database` (jobs/failed_jobs). Outros
     * drivers (redis/sqs) precisam de introspecção própria — retornamos só o
     * que dá p/ medir de forma confiável.
     *
     * @return array<int,array<string,mixed>>
     */
    private function collectQueues(): array
    {
        $conn   = (string) config('queue.default');
        $driver = (string) config("queue.connections.$conn.driver");
        if ($driver !== 'database') {
            return [];
        }

        $jobsTable   = (string) (config("queue.connections.$conn.table") ?: 'jobs');
        $failedTable = (string) (config('queue.failed.table') ?: 'failed_jobs');

        /** @var array<string,array<string,mixed>> $q */
        $q = [];
        $touch = function (string $name) use (&$q, $conn): void {
            if (!isset($q[$name])) {
                $q[$name] = [
                    'name' => $name, 'pending' => 0, 'processing' => 0,
                    'failed_pending' => 0, 'oldest_wait_s' => 0, 'conn' => $conn,
                ];
            }
        };

        if (Schema::hasTable($jobsTable)) {
            $now = time();
            $rows = DB::table($jobsTable)->selectRaw(
                'queue,'
                . ' sum(case when reserved_at is null then 1 else 0 end) as pending,'
                . ' sum(case when reserved_at is not null then 1 else 0 end) as processing,'
                . ' min(available_at) as oldest'
            )->groupBy('queue')->get();

            foreach ($rows as $r) {
                $name = (string) ($r->queue ?? 'default');
                $touch($name);
                $q[$name]['pending']       = (int) $r->pending;
                $q[$name]['processing']    = (int) $r->processing;
                $q[$name]['oldest_wait_s'] = $r->oldest ? max(0, $now - (int) $r->oldest) : 0;
            }
        }

        if (Schema::hasTable($failedTable) && Schema::hasColumn($failedTable, 'queue')) {
            foreach (DB::table($failedTable)->selectRaw('queue, count(*) as failed')->groupBy('queue')->get() as $r) {
                $name = (string) ($r->queue ?? 'default');
                $touch($name);
                $q[$name]['failed_pending'] = (int) $r->failed;
            }
        }

        // Sempre reporta ao menos a fila default (profundidade 0) p/ o painel
        // mostrar a fila viva mesmo sem backlog.
        if (!$q) {
            $touch((string) (config("queue.connections.$conn.queue") ?: 'default'));
        }

        return array_values($q);
    }
}
