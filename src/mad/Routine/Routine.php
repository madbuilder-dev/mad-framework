<?php

namespace Mad\Routine;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mad\Database\DataScope;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * Rotina: ações de dado entre tabelas, montadas na tela Rotinas do Studio e
 * geradas como `app/Routines/<Classe>.php` (`App\Routines\<Classe>`).
 *
 * A classe gerada implementa `run()` (e, se houver travas, `guards()`) só com
 * os helpers desta base. Os que gravam — create, update, accumulate — passam
 * pelo LIVRO ({@see RoutineLedger}), e é ele que permite:
 *
 *   - `redo($origem)`    desfazer o que a rotina gerou para a origem e lançar de novo;
 *   - `reverse($origem)` só desfazer;
 *   - `launch($origem)`  lançar sem desfazer nada (rotina sem origem: `launch()`).
 *
 * Tudo numa transação da conexão do model de origem (dentro de uma transação
 * já aberta vira savepoint), com a origem travada para escrita. Um
 * {@see RoutineStopped} — passo "parar", trava, registro gerado em uso, limite
 * — desfaz tudo e leva a mensagem ao usuário.
 *
 * Quem dispara: o Salvar do formulário (`on_save`), a exclusão do registro de
 * origem (`on_delete`), o botão / ação de linha (`onRunRoutine`, ver
 * {@see RunsRoutines}) e qualquer código: `\App\Routines\X::redo($id)`.
 */
abstract class Routine
{
    public const SLUG = '';

    /** Voltas por laço (`times`/`each`/`children`). */
    public const MAX_LOOP = 1000;

    /** Gravações por execução. */
    public const MAX_ENTRIES = 10000;

    /** FQCN do model de origem; null = rotina sem origem. */
    protected static ?string $source = null;

    private RoutineContext $ctx;
    private ?string $conn = null;
    private string $originType = '';
    private string $originId = '';
    private int $seq = 0;
    private RoutineResult $result;

    // ── Entrada ────────────────────────────────────────────────────────────

    /** Lança sem desfazer nada. Rotina sem origem: `launch(null, $params)`. */
    public static function launch(Model|int|string|null $origin = null, array $params = []): RoutineResult
    {
        return (new static())->execute('launch', $origin, $params);
    }

    /** Desfaz o que a rotina gerou para esta origem e lança de novo. */
    public static function redo(Model|int|string $origin, array $params = []): RoutineResult
    {
        return (new static())->execute('redo', $origin, $params);
    }

    /** Desfaz o que a rotina gerou para esta origem. */
    public static function reverse(Model|int|string $origin): RoutineResult
    {
        return (new static())->execute('reverse', $origin, []);
    }

    /** Slug da rotina (o do `routines.json`). */
    public static function slug(): string
    {
        return static::SLUG !== '' ? static::SLUG : Str::kebab(class_basename(static::class));
    }

    /** FQCN do model de origem, ou null. */
    public static function sourceModel(): ?string
    {
        return static::$source;
    }

    // ── Implementados pela classe gerada ───────────────────────────────────

    abstract protected function run(RoutineContext $ctx): void;

    /** Travas: avaliadas antes de refazer/desfazer; `$this->stop('…')` impede. */
    protected function guards(RoutineContext $ctx): void
    {
    }

    // ── Execução ───────────────────────────────────────────────────────────

    private function execute(string $mode, Model|int|string|null $origin, array $params): RoutineResult
    {
        $source = static::$source;
        if ($source === null && $mode !== 'launch') {
            throw new RoutineStopped(RoutineText::get('no_source'));
        }

        $this->conn   = $source !== null ? (new $source())->getConnectionName() : $this->routineConnection();
        $this->result = new RoutineResult((string) Str::uuid());

        DB::connection($this->conn)->transaction(function () use ($mode, $origin, $params, $source): void {
            RoutineLedger::ensure($this->conn);

            $record = $source !== null ? $this->lockOrigin($source, $origin) : null;
            if ($record !== null) {
                $this->originType = $source;
                $this->originId   = (string) $record->getKey();
            }
            $this->ctx = new RoutineContext($record, $params, $this->result->runId);

            if ($mode !== 'launch') {
                $this->guards($this->ctx);
                $this->result->reversed = RoutineLedger::undo(
                    $this->conn,
                    RoutineLedger::entries($this->conn, static::slug(), $this->originType, $this->originId),
                );
            }
            if ($mode !== 'reverse') {
                $this->run($this->ctx);
            }
        });

        return $this->result;
    }

    /**
     * Rotina sem origem: a conexão vem do `routines.json` (o gerador grava a do
     * primeiro destino); sem ela, a padrão.
     */
    private function routineConnection(): ?string
    {
        $entry = RoutineRegistry::find(static::slug()) ?? RoutineRegistry::forClass(static::class);

        return $entry?->connection;
    }

    /**
     * A origem relida com trava de escrita. Model recebido = quem chamou já o
     * tem (Salvar, exclusão, código): relido sem os escopos globais. Id = veio
     * de fora (botão): relido COM os escopos (unidade, empresa) — id de registro
     * que o usuário não vê não é encontrado.
     */
    private function lockOrigin(string $source, Model|int|string|null $origin): Model
    {
        $found = null;
        if ($origin instanceof Model) {
            if (! $origin instanceof $source) {
                throw new \InvalidArgumentException(static::class . ': a origem tem de ser ' . $source . ', veio ' . get_class($origin) . '.');
            }
            $key = $origin->getKey();
            if ($key !== null && $key !== '') {
                $found = $source::withoutGlobalScopes()->whereKey($key)->lockForUpdate()->first();
            }
        } elseif ($origin !== null && $origin !== '') {
            $found = $source::query()->whereKey($origin)->lockForUpdate()->first();
        }
        if (! $found instanceof Model) {
            throw new RoutineStopped(RoutineText::get('origin_not_found'));
        }

        return $found;
    }

    // ── Helpers do corpo gerado ────────────────────────────────────────────

    /** Cria um registro (vai para o livro: desfazer o apaga). */
    protected function create(string $model, array $attributes): Model
    {
        /** @var Model $row */
        $row = new $model();
        $row->forceFill($this->prepare($row, $attributes));
        $row->save();
        $this->record('create', $row, ['model' => $model, 'key' => $row->getKeyName()]);
        $this->result->created++;

        return $row;
    }

    /**
     * Atualiza os registros que casam com `$where` (`fn ($q) => $q->where(…)`).
     * Devolve quantas linhas mudaram. Só a coluna que mudou vai para o livro.
     */
    protected function update(string $model, \Closure $where, array $attributes): int
    {
        /** @var Model $probe */
        $probe = new $model();
        $rows  = $model::query()->where($where)->orderBy($probe->getKeyName())->lockForUpdate()->get();
        $changed = 0;
        foreach ($rows as $row) {
            $row->forceFill($this->prepare($row, $attributes));
            $dirty = $row->getDirty();
            if ($dirty === []) {
                continue;
            }
            $before = [];
            foreach (array_keys($dirty) as $column) {
                $before[$column] = $row->getRawOriginal($column);
            }
            $row->save();
            $after = array_intersect_key($row->getAttributes(), $before);
            $this->record('update', $row, ['model' => $model, 'key' => $row->getKeyName(), 'before' => $before, 'after' => $after]);
            $changed++;
        }
        $this->result->updated += $changed;

        return $changed;
    }

    /**
     * Soma `$amount` (negativo = subtrai) em `$column` do registro que casa com
     * `$match` (coluna => valor). Sem registro: cria com `$match` + `$defaults`
     * quando `$createIfMissing`; senão não faz nada. Desfazer aplica o delta
     * contrário; o registro criado aqui é apagado quando volta ao valor com que
     * nasceu e nenhuma outra gravação do livro aponta para ele (senão fica).
     */
    protected function accumulate(string $model, array $match, string $column, int|float|string $amount,
                                  bool $createIfMissing = false, array $defaults = []): void
    {
        $delta = RoutineNumber::norm($amount);

        /** @var Model $probe */
        $probe = new $model();
        $query = $model::query();
        foreach ($match as $col => $value) {
            $query->where($col, '=', $value);
        }
        $row = $query->orderBy($probe->getKeyName())->lockForUpdate()->first();
        $created = false;
        if (! $row instanceof Model) {
            if (! $createIfMissing) {
                return;
            }
            $row = new $model();
            $row->forceFill($this->prepare($row, $match + $defaults + [$column => 0]));
            $row->save();
            $created = true;
            $initial = $row->getAttributes()[$column] ?? 0;
        }
        if (RoutineNumber::cmp($delta, '0') === 0 && ! $created) {
            return;
        }

        $current = $row->getAttributes()[$column] ?? 0;
        $row->setRawAttributes([$column => RoutineNumber::add($current, $delta)] + $row->getAttributes());
        $row->save();
        $this->record('accumulate', $row, [
            'model' => $model, 'key' => $row->getKeyName(), 'column' => $column, 'delta' => $delta, 'created' => $created,
        ] + ($created ? ['initial' => RoutineNumber::norm($initial)] : []));
        $this->result->accumulated++;
    }

    /** Há registro que casa? (com os escopos globais do model) */
    protected function exists(string $model, \Closure $where): bool
    {
        return $model::query()->where($where)->exists();
    }

    /** Entre os registros que ESTA rotina gerou para ESTA origem, algum casa? */
    protected function generatedExists(string $model, \Closure $where): bool
    {
        $ids = [];
        foreach (RoutineLedger::entries($this->conn, static::slug(), $this->originType, $this->originId) as $entry) {
            if ($entry->action !== 'create') {
                continue;
            }
            $payload = json_decode((string) $entry->payload, true);
            if (is_array($payload) && ltrim((string) ($payload['model'] ?? ''), '\\') === ltrim($model, '\\')) {
                $ids[] = (string) $entry->target_id;
            }
        }
        foreach (array_chunk($ids, 500) as $chunk) {
            if ($model::withoutGlobalScopes()->whereKey($chunk)->where($where)->exists()) {
                return true;
            }
        }

        return false;
    }

    /** Para a rotina; nada do que ela gravou fica. A mensagem vai ao usuário. */
    protected function stop(string $message): never
    {
        throw new RoutineStopped($message);
    }

    /** Parte `$index` (1..$parts) do rateio de `$total`; a sobra vai na última. */
    protected function split(int|float|string|null $total, int|string|null $parts, int $index, int $decimals = 2): string
    {
        $n = (int) RoutineNumber::norm($parts);
        if ($n < 1 || $index < 1 || $index > $n) {
            $this->stop(RoutineText::get('split_parts'));
        }

        return RoutineNumber::split($total, $n, $index, $decimals);
    }

    /** Soma dias/meses/anos. Mês sem estouro: 31/01 + 1 mês = 28/02 (ou 29). */
    protected function dateAdd(mixed $base, int|string|null $amount, string $unit): ?CarbonImmutable
    {
        if ($base === null || $base === '') {
            return null;
        }
        $date = $base instanceof CarbonInterface ? $base->toImmutable()
            : ($base instanceof \DateTimeInterface ? CarbonImmutable::instance($base) : CarbonImmutable::parse((string) $base));
        $n = (int) RoutineNumber::norm($amount);

        return match ($unit) {
            'day', 'days'     => $date->addDays($n),
            'month', 'months' => $date->addMonthsNoOverflow($n),
            'year', 'years'   => $date->addYearsNoOverflow($n),
            default           => throw new \InvalidArgumentException('Unidade de data desconhecida: ' . $unit),
        };
    }

    /** Valor da sessão de quem disparou: user_id | unit_id | tenant_id. */
    protected function session(string $key): mixed
    {
        return match ($key) {
            'user_id'   => DataScope::userId(),
            'unit_id'   => UnitContext::id(),
            'tenant_id' => TenantContext::id(),
            default     => null,
        };
    }

    /** Parâmetro passado por quem chamou. */
    protected function param(string $key): mixed
    {
        return $this->ctx->params[$key] ?? null;
    }

    /** Passo "chamar código": `public function handle(RoutineContext $ctx): mixed` num serviço do projeto. */
    protected function callService(string $class, string $method, RoutineContext $ctx): mixed
    {
        return app($class)->{$method}($ctx);
    }

    /**
     * Laço "N vezes": 1..N. Acima de {@see MAX_LOOP} a rotina para.
     *
     * @return list<int>
     */
    protected function times(mixed $count): array
    {
        $n = (int) RoutineNumber::norm($count);
        $this->guardLoop($n);

        return $n > 0 ? range(1, $n) : [];
    }

    /**
     * Laço "para cada registro" de uma consulta, pela chave crescente.
     *
     * @return list<Model>
     */
    protected function each(string $model, ?\Closure $where = null): array
    {
        /** @var Model $probe */
        $probe = new $model();
        $query = $model::query();
        if ($where !== null) {
            $query->where($where);
        }
        $rows = $query->orderBy($probe->getKeyName())->limit(static::MAX_LOOP + 1)->get()->all();
        $this->guardLoop(count($rows));

        return $rows;
    }

    /**
     * Laço "para cada filho": registros de `$model` cuja `$foreignKey` aponta
     * para `$parent`, pela chave crescente.
     *
     * @return list<Model>
     */
    protected function children(Model $parent, string $model, string $foreignKey, ?\Closure $where = null): array
    {
        $key = $parent->getKey();

        return $this->each($model, static function ($q) use ($foreignKey, $key, $where) {
            $q->where($foreignKey, '=', $key);
            if ($where !== null) {
                $q->where($where);
            }
        });
    }

    // ── Internos ───────────────────────────────────────────────────────────

    private function guardLoop(int $n): void
    {
        if ($n > static::MAX_LOOP) {
            $this->stop(RoutineText::get('too_many_loops', ['max' => static::MAX_LOOP]));
        }
    }

    /**
     * Data sem conversão do model: dia puro vira `Y-m-d` (coluna DATE), com hora
     * `Y-m-d H:i:s`. Colunas com cast de data ficam com o model.
     */
    private function prepare(Model $row, array $attributes): array
    {
        foreach ($attributes as $column => $value) {
            if ($value instanceof \DateTimeInterface && ! $row->hasCast($column) && ! in_array($column, $row->getDates(), true)) {
                $attributes[$column] = $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s');
            }
        }

        return $attributes;
    }

    private function record(string $action, Model $row, array $payload): void
    {
        if (++$this->seq > static::MAX_ENTRIES) {
            $this->stop(RoutineText::get('too_many_entries', ['max' => static::MAX_ENTRIES]));
        }
        $user = DataScope::userId();
        RoutineLedger::write($this->conn, [
            'routine'      => static::slug(),
            'run_id'       => $this->result->runId,
            'origin_type'  => $this->originType,
            'origin_id'    => $this->originId,
            'seq'          => $this->seq,
            'action'       => $action,
            'target_table' => $row->getTable(),
            'target_id'    => (string) $row->getKey(),
            'user_id'      => $user !== null ? (string) $user : null,
        ], $payload);
    }
}
