<?php

namespace Mad\Routine;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mad\Ui\MadRecordInUse;

/**
 * O livro das rotinas: o que cada execução gravou, para refazer sem duplicar e
 * desfazer só o que a rotina fez.
 *
 * Uma tabela `mad_routine_entry` por CONEXÃO (a da origem), criada pela
 * migration que a plataforma gera (`createTable`, idempotente). Em tempo de
 * execução não há DDL: tabela ausente = {@see RoutineStopped} com a instrução.
 *
 * Cada linha é UMA gravação: `create` (a linha criada), `update` (antes/depois
 * das colunas mudadas) ou `accumulate` (coluna e delta aplicado). Desfazer
 * percorre as linhas em ordem inversa:
 *
 *  - create     → apaga a linha (em uso por outro registro = RoutineStopped);
 *  - accumulate → aplica o delta contrário (linha que sumiu = ignora). A
 *                 linha que o próprio accumulate CRIOU é apagada quando volta
 *                 ao valor com que nasceu e nenhuma outra gravação viva do
 *                 livro (de qualquer rotina/origem) aponta para ela; em uso por
 *                 outro registro, fica com o valor devolvido, sem parar. Se
 *                 outra origem ainda soma nela, a marca de "criada" passa
 *                 para essa gravação (quem desfizer por último apaga);
 *  - update     → devolve o valor de antes só na coluna que ainda tem o valor
 *                 que a rotina gravou (o que alguém mudou depois fica).
 */
final class RoutineLedger
{
    public const TABLE = 'mad_routine_entry';

    /** @var array<string, true> conexões em que a tabela já foi vista */
    private static array $ready = [];

    /** Cria a tabela do livro na conexão, se ainda não existe. Chamado pela migration. */
    public static function createTable(?string $connection = null): void
    {
        $schema = Schema::connection($connection);
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, static function (Blueprint $t): void {
            $t->bigIncrements('id');
            $t->string('routine', 120);
            $t->string('run_id', 40);
            $t->string('origin_type', 190)->default('');
            $t->string('origin_id', 64)->default('');
            $t->unsignedInteger('seq');
            $t->string('action', 20);
            $t->string('target_table', 120);
            $t->string('target_id', 64)->default('');
            $t->text('payload')->nullable();
            $t->string('user_id', 64)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index(['routine', 'origin_type', 'origin_id'], 'mad_routine_entry_origin_idx');
            $t->index('run_id', 'mad_routine_entry_run_idx');
        });
        self::$ready[self::key($connection)] = true;
    }

    /** A tabela existe nesta conexão? Senão, para a rotina com a instrução de instalação. */
    public static function ensure(?string $connection): void
    {
        $key = self::key($connection);
        if (isset(self::$ready[$key])) {
            return;
        }
        if (! Schema::connection($connection)->hasTable(self::TABLE)) {
            throw new RoutineStopped(RoutineText::get('not_installed'));
        }
        self::$ready[$key] = true;
    }

    /** Esquece as conexões já conferidas (testes). */
    public static function flush(): void
    {
        self::$ready = [];
    }

    /**
     * Linhas do livro desta rotina para esta origem, na ordem em que foram gravadas.
     *
     * @return list<object>
     */
    public static function entries(?string $connection, string $routine, string $originType, string $originId): array
    {
        return DB::connection($connection)->table(self::TABLE)
            ->where('routine', $routine)
            ->where('origin_type', $originType)
            ->where('origin_id', $originId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /** @param array<string, mixed> $payload */
    public static function write(?string $connection, array $row, array $payload): void
    {
        DB::connection($connection)->table(self::TABLE)->insert($row + [
            'payload'    => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'created_at' => now(),
        ]);
    }

    /**
     * Desfaz as linhas (ordem inversa) e as apaga do livro. Devolve quantas
     * gravações foram desfeitas.
     *
     * @param list<object> $entries
     */
    public static function undo(?string $connection, array $entries): int
    {
        $undone = 0;
        $batch  = [];
        foreach ($entries as $entry) {
            $batch[(string) $entry->id] = true;
        }
        foreach (array_reverse($entries) as $entry) {
            $payload = json_decode((string) ($entry->payload ?? ''), true);
            $payload = is_array($payload) ? $payload : [];
            $did = match ((string) $entry->action) {
                'create'     => self::undoCreate($connection, $entry, $payload),
                'update'     => self::undoUpdate($connection, $entry, $payload),
                'accumulate' => self::undoAccumulate($connection, $entry, $payload, $batch),
                default      => false,
            };
            if ($did) {
                $undone++;
            }
        }
        $ids = array_map(static fn ($e) => $e->id, $entries);
        foreach (array_chunk($ids, 500) as $chunk) {
            DB::connection($connection)->table(self::TABLE)->whereIn('id', $chunk)->delete();
        }

        return $undone;
    }

    /** O registro que a linha do livro aponta, sem escopos (o livro é a autoridade), travado. */
    public static function target(?string $connection, object $entry, array $payload): ?Model
    {
        $class = (string) ($payload['model'] ?? '');
        if ($class === '' || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var Model $found */
        $found = $class::withoutGlobalScopes()->whereKey((string) $entry->target_id)->lockForUpdate()->first();

        return $found;
    }

    private static function undoCreate(?string $connection, object $entry, array $payload): bool
    {
        try {
            $model = self::target($connection, $entry, $payload);
            if ($model !== null) {
                if ($model->delete() === false) {
                    throw new RoutineStopped(RoutineText::get('in_use'));
                }

                return true;
            }
            // Classe que não existe mais: apaga pela tabela.
            $key = (string) ($payload['key'] ?? 'id');

            return DB::connection($connection)->table((string) $entry->target_table)
                ->where($key, (string) $entry->target_id)->delete() > 0;
        } catch (RoutineStopped $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (MadRecordInUse::matches($e)) {
                throw new RoutineStopped(RoutineText::get('in_use'), 0, $e);
            }
            throw $e;
        }
    }

    private static function undoUpdate(?string $connection, object $entry, array $payload): bool
    {
        $model = self::target($connection, $entry, $payload);
        if ($model === null) {
            return false;
        }
        $before = is_array($payload['before'] ?? null) ? $payload['before'] : [];
        $after  = is_array($payload['after'] ?? null) ? $payload['after'] : [];
        $changed = false;
        foreach ($before as $column => $value) {
            if (! array_key_exists($column, $after)) {
                continue;
            }
            if (! self::same($model->getAttributes()[$column] ?? null, $after[$column])) {
                continue; // alguém mudou depois da rotina: fica o valor de agora
            }
            $model->setRawAttributes([$column => $value] + $model->getAttributes());
            $changed = true;
        }
        if ($changed) {
            $model->save();
        }

        return $changed;
    }

    /** @param array<string, true> $batch ids do livro sendo desfeitos agora (já não contam como vivos) */
    private static function undoAccumulate(?string $connection, object $entry, array $payload, array $batch = []): bool
    {
        $model  = self::target($connection, $entry, $payload);
        $column = (string) ($payload['column'] ?? '');
        if ($model === null || $column === '') {
            return false;
        }
        $current = $model->getAttributes()[$column] ?? 0;
        $model->setRawAttributes([$column => RoutineNumber::sub($current, (string) ($payload['delta'] ?? '0'))] + $model->getAttributes());
        $model->save();

        if (($payload['created'] ?? false) !== true || ! array_key_exists('initial', $payload)) {
            return true;
        }
        $others = self::livingEntriesFor($connection, $entry, $batch);
        if ($others !== []) {
            self::handOverCreation($connection, $others, $column, $payload['initial']);
        } elseif (self::same($model->getAttributes()[$column] ?? null, $payload['initial'])) {
            self::deleteIfFree($model);
        }

        return true;
    }

    /**
     * Gravações do livro, fora das que estão sendo desfeitas, que apontam para a
     * mesma linha (de qualquer rotina/origem).
     *
     * @param array<string, true> $batch
     * @return list<object>
     */
    private static function livingEntriesFor(?string $connection, object $entry, array $batch): array
    {
        $rows = DB::connection($connection)->table(self::TABLE)
            ->where('target_table', (string) $entry->target_table)
            ->where('target_id', (string) $entry->target_id)
            ->orderBy('id')
            ->get(['id', 'action', 'payload']);
        $out = [];
        foreach ($rows as $row) {
            if (! isset($batch[(string) $row->id])) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * A linha nasceu de um accumulate que agora foi desfeito, mas outra origem
     * ainda soma nela: a marca de "criada" (com o valor de nascimento) passa
     * para a primeira gravação viva que acumula na MESMA coluna. Quem desfizer
     * por último leva a linha junto, em vez de deixá-la órfã. Sem gravação
     * compatível, a linha simplesmente fica.
     *
     * @param list<object> $others
     */
    private static function handOverCreation(?string $connection, array $others, string $column, mixed $initial): void
    {
        foreach ($others as $other) {
            if ((string) $other->action !== 'accumulate') {
                continue;
            }
            $p = json_decode((string) ($other->payload ?? ''), true);
            if (! is_array($p) || (string) ($p['column'] ?? '') !== $column) {
                continue;
            }
            $p['created'] = true;
            $p['initial'] = $initial;
            DB::connection($connection)->table(self::TABLE)->where('id', $other->id)->update([
                'payload' => json_encode($p, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            ]);

            return;
        }
    }

    /**
     * Apaga a linha num savepoint: em uso por outro registro (FK), ela fica com
     * o valor já devolvido e a rotina segue (no Postgres, sem o savepoint, a
     * recusa abortaria a transação inteira).
     */
    private static function deleteIfFree(Model $model): void
    {
        try {
            $model->getConnection()->transaction(static function () use ($model): void {
                $model->delete();
            });
        } catch (\Throwable $e) {
            if (! MadRecordInUse::matches($e)) {
                throw $e;
            }
        }
    }

    /** Mesmo valor gravado? `10` = `'10'` = `10.0`, nulo = nulo, o resto como texto. */
    public static function same(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return $a === null && $b === null;
        }
        if (is_bool($a) || is_bool($b)) {
            return (int) $a === (int) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return RoutineNumber::cmp((string) $a, (string) $b) === 0;
        }

        return (string) $a === (string) $b;
    }

    private static function key(?string $connection): string
    {
        return $connection ?? (string) config('database.default');
    }
}
