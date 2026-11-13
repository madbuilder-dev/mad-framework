<?php

namespace Mad\Service;

use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;

/**
 * Registro das execuções das tarefas agendadas do PROJETO em
 * `mad_sys_schedule_log` (fórum #300): é dali que a aba Agendamentos da
 * Central de Comando tira as colunas Última e Status.
 *
 * Tarefa do projeto = tudo que não é comando `mad:*`. As do framework
 * (`mad:trace-flush`, `mad:webhook:tick`…) ficam de fora: rodam a cada
 * minuto e encheriam a tabela sem ninguém pedir.
 *
 * O registro vai nos callbacks `before`/`after` do próprio evento, que o
 * Laravel chama em qualquer caminho que roda a tarefa: o `schedule:run` do
 * cron, o "Executar agendador" (Artisan::call('schedule:run')) e o
 * "Executar agora" (`schedule:test --name`, que NÃO dispara os eventos
 * ScheduledTask*). Tarefa pausada ou pulada por sobreposição não chega ao
 * `before`, então não grava nada.
 *
 * Custo: nenhuma consulta no `schedule:run` sem tarefa do projeto no horário;
 * com tarefa, um INSERT no início e um UPDATE no fim. Erro de banco nunca
 * derruba a tarefa (fail-open). A cada 200 execuções apaga o que passou de
 * {@see KEEP_DAYS} dias (índice em `started_at`).
 */
final class ScheduleRunLog
{
    public const TABLE = 'mad_sys_schedule_log';

    /** Prazo das linhas do log, em dias. */
    public const KEEP_DAYS = 30;

    /** Teto da mensagem de falha gravada. */
    private const MAX_MESSAGE = 2000;

    /** @var \WeakMap<Event, true>|null eventos que já ganharam os callbacks */
    private static ?\WeakMap $decorated = null;

    /** @var \WeakMap<Event, int>|null id da linha da execução em andamento */
    private static ?\WeakMap $running = null;

    /** Comandos que rodam (ou terminam) tarefas agendadas. */
    public const COMMANDS = ['schedule:run', 'schedule:work', 'schedule:test', 'schedule:finish'];

    /**
     * Nome estável da tarefa — o mesmo da Central de Comando e do pause:
     * o comando artisan (`mad:foo --bar`) ou o `->name()` do Schedule::call.
     */
    public static function taskName(Event $event): string
    {
        $cmd = (string) ($event->command ?? '');
        if ($cmd !== '' && preg_match('/\bartisan[\'"]?\s+(.+)$/s', $cmd, $m)) {
            return trim($m[1]);
        }

        return trim((string) $event->getSummaryForDisplay());
    }

    public static function isProjectTask(string $name): bool
    {
        return $name !== '' && ! str_starts_with($name, 'mad:');
    }

    /**
     * Pendura o registro em cada tarefa do projeto do Schedule. Idempotente:
     * o mesmo evento não ganha os callbacks duas vezes no mesmo processo.
     */
    public static function decorate(Schedule $schedule, ?string $connection = null): void
    {
        self::$decorated ??= new \WeakMap();
        self::$running ??= new \WeakMap();
        $connection = $connection ?: self::connection();

        foreach ($schedule->events() as $event) {
            if (isset(self::$decorated[$event])) {
                continue;
            }
            self::$decorated[$event] = true;

            $name = self::taskName($event);
            if (! self::isProjectTask($name)) {
                continue;
            }

            $event->before(static function () use ($event, $name, $connection): void {
                self::started($event, $name, $connection);
            });
            $event->after(static function () use ($event, $name, $connection): void {
                self::finished($event, $name, $connection);
            });
        }
    }

    public static function connection(): string
    {
        return (string) (config('mad.schedule.connection') ?: 'iam');
    }

    private static function started(Event $event, string $name, string $connection): void
    {
        try {
            $id = (int) DB::connection($connection)->table(self::TABLE)->insertGetId([
                'task_name'  => mb_substr($name, 0, 255),
                'expression' => (string) $event->expression,
                'started_at' => now()->format('Y-m-d H:i:s'),
                'status'     => 'running',
            ]);
            self::$running[$event] = $id;
            if ($id > 0 && $id % 200 === 0) {
                self::prune($connection);
            }
        } catch (\Throwable) {
            // sem tabela/conexão: a tarefa roda do mesmo jeito
        }
    }

    private static function finished(Event $event, string $name, string $connection): void
    {
        try {
            $exitCode = (int) ($event->exitCode ?? 0);
            $error = $event instanceof CallbackEvent
                ? (fn () => $this->exception ?? null)->call($event)
                : null;
            $ok = $exitCode === 0 && $error === null;

            $message = null;
            if ($error instanceof \Throwable) {
                $message = trim($error->getMessage()) !== '' ? $error->getMessage() : get_class($error);
            } elseif (! $ok) {
                $message = $event instanceof CallbackEvent
                    ? 'A tarefa retornou false.'
                    : "O comando terminou com o código de saída {$exitCode}.";
            }

            $values = [
                'finished_at' => now()->format('Y-m-d H:i:s'),
                'status'      => $ok ? 'success' : 'failed',
                'exception'   => $message === null ? null : mb_substr($message, 0, self::MAX_MESSAGE),
            ];

            $q  = DB::connection($connection)->table(self::TABLE);
            $id = self::$running[$event] ?? null;
            unset(self::$running[$event]);
            if ($id === null) {
                // terminou noutro processo (comando em segundo plano → schedule:finish)
                $id = $q->clone()->where('task_name', mb_substr($name, 0, 255))
                    ->where('status', 'running')->max('id');
            }
            if ($id) {
                $q->where('id', $id)->update($values);
            }
        } catch (\Throwable) {
            // fail-open
        }
    }

    private static function prune(string $connection): void
    {
        DB::connection($connection)->table(self::TABLE)
            ->where('started_at', '<', now()->subDays(self::KEEP_DAYS)->format('Y-m-d H:i:s'))
            ->delete();
    }
}
