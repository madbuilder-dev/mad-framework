<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mad\Http\CurrentControl;
use Mad\Http\TransactionId;

/**
 * ChangeLog
 *
 * Escritor único do "log de mudança de objeto" (mad_log_change, conexão 'log'):
 * 1 linha por coluna afetada — operação (created/changed/deleted), valor antigo
 * → novo, quem (login/sessão/IP), quando, de onde (classe + stack trace) e o id
 * da transação. Equivale ao TRACKCHANGES do Adianti; aqui é ligado por model
 * via trait HasMadChangeLog, ou chamado explicitamente
 * (ChangeLogService::register(), compat).
 *
 * Garantias:
 *  - best-effort: NUNCA lança — falha no log não derruba o save;
 *  - escrita adiada para depois do COMMIT da conexão do model (afterCommit):
 *    rollback do negócio não deixa linha órfã no log; fora de transação grava
 *    na hora;
 *  - colunas de carimbo (created_at/by…, updated_*, deleted_*) não entram —
 *    já são redundantes com login/logdate da própria linha;
 *  - $hidden do model + `mask` + toda coluna cujo nome contenha
 *    password/senha/token/secret… (mask_patterns) entram como '***' nos dois
 *    lados: registra QUE mudou, não o valor;
 *  - `except` tira a coluna do log de vez.
 *
 * Kill-switch: config mad.change_log.enabled (env MAD_CHANGE_LOG) global;
 * ChangeLog::withoutLogging(fn) pontual (importação em massa, seeder).
 */
final class ChangeLog
{
    public const TABLE = 'mad_log_change';
    public const MASK  = '***';

    /** Trechos de nome de coluna sempre mascarados (case-insensitive, substring). */
    public const DEFAULT_MASK_PATTERNS = ['password', 'passwd', 'senha', 'secret', 'token', 'api_key', 'apikey', 'private_key'];

    /** Contador de pausas aninhadas (withoutLogging). */
    private static int $paused = 0;

    /** Ligado? Config global + pausa pontual. */
    public static function enabled(): bool
    {
        if (self::$paused > 0) {
            return false;
        }
        try {
            return (bool) config('mad.change_log.enabled', true);
        } catch (\Throwable) {
            return true;
        }
    }

    /** Executa $fn com o log desligado (aninhável). Devolve o retorno de $fn. */
    public static function withoutLogging(callable $fn): mixed
    {
        self::$paused++;
        try {
            return $fn();
        } finally {
            self::$paused--;
        }
    }

    /** Conexão de destino (config mad.change_log.connection; default 'log'). */
    public static function connectionName(): string
    {
        try {
            return (string) (config('mad.change_log.connection') ?: 'log');
        } catch (\Throwable) {
            return 'log';
        }
    }

    /**
     * Registra o diff entre dois estados (coluna => valor cru) de um model.
     * $old vazio = criação; $new vazio = exclusão; ambos = alteração.
     *
     * @param array{except?: string[], mask?: string[]} $options
     */
    public static function record(Model $model, array $old, array $new, array $options = []): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            $except = array_merge(self::stampColumns($model), self::modelOption($model, 'except'), $options['except'] ?? []);
            if ($old === [] || $new === []) {
                // criação/exclusão: a PK já vai em pkvalue — como coluna seria só ruído
                $except[] = $model->getKeyName();
            }
            $mask   = array_merge($model->getHidden(), self::modelOption($model, 'mask'), $options['mask'] ?? []);
            $mask   = array_merge($mask, self::sensitiveColumns(array_keys($old + $new)));

            $old = array_diff_key($old, array_flip($except));
            $new = array_diff_key($new, array_flip($except));

            $rows = self::diff($old, $new);
            if ($rows === []) {
                return;
            }

            $rows = self::applyMask($rows, $mask);
            $rows = self::envelope($model, $rows);

            self::deferToCommit($model, fn () => self::write($rows));
        } catch (\Throwable $e) {
            self::warn($e);
        }
    }

    /** Alias com a assinatura legada (SystemChangeLogService::register). */
    public static function register(Model $model, array $lastState, array $currentState): void
    {
        self::record($model, $lastState, $currentState);
    }

    /**
     * Diff puro entre dois estados. Uma entrada por coluna afetada:
     *   coluna só em $old (valor não vazio)  → deleted
     *   coluna nos dois, valor diferente      → changed
     *   coluna só em $new (valor não vazio)   → created
     *
     * Compara sem ruído: null ≡ '' ; numéricos por valor ("10.0" ≡ 10);
     * não-escalares vão como JSON.
     *
     * @return array<int, array{operation: string, column: string, old: ?string, new: ?string}>
     */
    public static function diff(array $old, array $new): array
    {
        $rows = [];

        foreach ($old as $col => $value) {
            if (array_key_exists($col, $new)) {
                continue;
            }
            $before = self::scalar($value);
            if ($before !== null && $before !== '') {
                $rows[] = ['operation' => 'deleted', 'column' => (string) $col, 'old' => $before, 'new' => ''];
            }
        }

        foreach ($new as $col => $value) {
            $after = self::scalar($value);

            if (array_key_exists($col, $old)) {
                $before = self::scalar($old[$col]);
                if (! self::same($before, $after)) {
                    $rows[] = ['operation' => 'changed', 'column' => (string) $col, 'old' => $before ?? '', 'new' => $after];
                }
                continue;
            }

            if ($after !== null && $after !== '') {
                $rows[] = ['operation' => 'created', 'column' => (string) $col, 'old' => '', 'new' => $after];
            }
        }

        return $rows;
    }

    /**
     * Linha do tempo: as linhas de mad_log_change agrupadas por GRAVAÇÃO
     * (transaction_id — um clique em Salvar/Excluir do usuário) e, dentro dela,
     * por REGISTRO (tabela + pk). É o que o <mad-change-timeline> renderiza.
     *
     * Linha sem transaction_id (ex.: auditoria MCP antiga) vira gravação própria
     * (chave tabela|pk|operação|segundo). Lê as `$scan` linhas mais recentes que
     * casam com os filtros e devolve até `$limit` gravações, da mais nova para a
     * mais antiga; linhas dentro da gravação ficam em ordem de escrita.
     *
     * Com Multi-unidade ou tenant em pool ligados, só entram as linhas de
     * registros que o usuário ENXERGA — o mesmo escopo das telas (ver
     * {@see scopedRows()}). Antes o feed sem `pk` mostrava a descrição, o
     * cliente e os valores dos registros de todas as unidades.
     *
     * @param array{table?:string, pk?:string|int|null, login?:string, class_name?:string, session_id?:string} $filters
     * @return array<int, array{
     *   key:string, logdate:string, login:?string, class_name:?string, access_ip:?string,
     *   session_id:?string, transaction_id:?string, operation:string, trace_id:int, fields:int,
     *   records: array<int, array{tablename:string, pkvalue:?string, operation:string,
     *     rows: array<int, array{id:int, column:string, operation:string, old:?string, new:?string, masked:bool}>}>
     * }>
     */
    public static function timeline(array $filters = [], int $limit = 30, int $scan = 600): array
    {
        try {
            $query = function () use ($filters) {
                $q = DB::connection(self::connectionName())->table(self::TABLE);
                if (! empty($filters['table'])) {
                    $q->where('tablename', (string) $filters['table']);
                }
                if (isset($filters['pk']) && $filters['pk'] !== '' && $filters['pk'] !== null) {
                    $q->where('pkvalue', (string) $filters['pk']);
                }
                if (! empty($filters['login'])) {
                    $q->where('login', (string) $filters['login']);
                }
                if (! empty($filters['class_name'])) {
                    $q->where('class_name', 'like', '%' . $filters['class_name'] . '%');
                }
                if (! empty($filters['session_id'])) {
                    $q->where('session_id', 'like', '%' . $filters['session_id'] . '%');
                }

                return $q;
            };
            $rows = DataScope::active()
                ? self::scopedRows($query, max(1, $scan))
                : $query()->orderByDesc('id')->limit(max(1, $scan))->get();
        } catch (\Throwable $e) {
            self::warn($e);

            return [];
        }

        $groups = [];
        foreach ($rows as $r) {
            $key = $r->transaction_id
                ?: 'row:' . $r->tablename . ':' . $r->pkvalue . ':' . $r->operation . ':' . $r->logdate;

            if (! isset($groups[$key])) {
                if (count($groups) >= $limit) {
                    continue; // gravação nova além do limite; as já abertas seguem recebendo linhas
                }
                $groups[$key] = [
                    'key'            => $key,
                    'logdate'        => (string) $r->logdate,
                    'login'          => $r->login,
                    'class_name'     => $r->class_name,
                    'access_ip'      => $r->access_ip,
                    'session_id'     => $r->session_id,
                    'transaction_id' => $r->transaction_id,
                    'operation'      => '',
                    'trace_id'       => (int) $r->id,
                    'fields'         => 0,
                    'records'        => [],
                    '_ops'           => [],
                ];
            }
            $g = &$groups[$key];
            // linhas vêm da mais nova p/ a mais antiga: a última vista é a primeira gravada
            $g['logdate']  = (string) $r->logdate;
            $g['trace_id'] = (int) $r->id;
            $g['login']    = $g['login'] ?: $r->login;
            $g['class_name'] = $g['class_name'] ?: $r->class_name;

            $rk = $r->tablename . '|' . $r->pkvalue;
            if (! isset($g['records'][$rk])) {
                $g['records'][$rk] = [
                    'tablename' => (string) $r->tablename,
                    'pkvalue'   => $r->pkvalue,
                    'operation' => '',
                    'rows'      => [],
                    '_ops'      => [],
                ];
            }
            $rec = &$g['records'][$rk];
            $op  = (string) $r->operation;
            array_unshift($rec['rows'], [
                'id'        => (int) $r->id,
                'column'    => (string) ($r->columnname ?? ''),
                'operation' => $op,
                'old'       => $r->oldvalue,
                'new'       => $r->newvalue,
                'masked'    => $r->oldvalue === self::MASK || $r->newvalue === self::MASK,
            ]);
            $rec['_ops'][$op] = true;
            $g['_ops'][$op]   = true;
            $g['fields']++;
            unset($rec, $g);
        }

        $summary = fn (array $ops): string => count($ops) === 1 ? (string) array_key_first($ops) : 'mixed';
        foreach ($groups as &$g) {
            $g['operation'] = $summary($g['_ops']);
            unset($g['_ops']);
            foreach ($g['records'] as &$rec) {
                $rec['operation'] = $summary($rec['_ops']);
                unset($rec['_ops']);
            }
            unset($rec);
            // registros na ordem em que foram gravados (mestre antes dos detalhes)
            $g['records'] = array_reverse(array_values($g['records']));
        }
        unset($g);

        return array_values($groups);
    }

    // ── escopo de unidade/tenant da linha do tempo ──────────────────────────

    /** Lotes de varredura com escopo ligado (lote = `$scan` linhas). */
    private const SCOPED_MAX_BATCHES = 8;

    /** Soft delete não esconde histórico: a exclusão lógica também é parte dele. */
    private const SOFT_DELETE_SCOPES = ['madSoftDelete', \Illuminate\Database\Eloquent\SoftDeletingScope::class];

    /**
     * Varre o log do mais novo ao mais antigo, em lotes de `$scan`, ficando só
     * com as linhas de registros visíveis no escopo corrente — até juntar
     * `$scan` linhas ou esgotar SCOPED_MAX_BATCHES lotes. Em lotes porque a
     * janela não pode ser comida pelas outras unidades: com o escopo aplicado
     * DEPOIS de um `limit` único, uma unidade movimentada empurrava o histórico
     * das outras para fora.
     */
    private static function scopedRows(\Closure $query, int $scan): \Illuminate\Support\Collection
    {
        $kept   = collect();
        $cursor = null;
        for ($batch = 0; $batch < self::SCOPED_MAX_BATCHES; $batch++) {
            $q = $query();
            if ($cursor !== null) {
                $q->where('id', '<', $cursor);
            }
            $rows = $q->orderByDesc('id')->limit($scan)->get();
            if ($rows->isEmpty()) {
                break;
            }
            $cursor = $rows->last()->id;
            $kept   = $kept->concat(self::visibleRows($rows));
            if ($kept->count() >= $scan || $rows->count() < $scan) {
                break;
            }
        }

        return $kept->take($scan)->values();
    }

    /** Filtra as linhas do log pelos registros (tabela + pk) visíveis no escopo. */
    private static function visibleRows(\Illuminate\Support\Collection $rows): \Illuminate\Support\Collection
    {
        $byTable = [];
        foreach ($rows as $r) {
            $byTable[(string) $r->tablename][(string) $r->pkvalue] = true;
        }

        $visible = [];
        foreach ($byTable as $table => $pks) {
            $visible[$table] = self::visiblePks((string) $table, array_map('strval', array_keys($pks)));
        }

        return $rows->filter(fn ($r) => isset($visible[(string) $r->tablename][(string) $r->pkvalue]))->values();
    }

    /**
     * PKs da tabela que o usuário enxerga: consulta pelo PRÓPRIO model, com os
     * escopos globais (unidade, tenant, os do app) e sem o de soft delete.
     * Tabela sem model conhecido não tem como provar o escopo → nada (fail-closed).
     * Registro que não está mais no banco (excluído de verdade): vale a última
     * unidade/tenant gravada no próprio log ({@see visibleByLoggedScope()}).
     *
     * @param list<string> $pks
     * @return array<string, true>
     */
    private static function visiblePks(string $table, array $pks): array
    {
        $cls = self::modelForTable($table);
        if ($cls === null) {
            return [];
        }

        try {
            $model = new $cls();
            $key   = $model->getKeyName();
            $q     = $cls::query()->withoutGlobalScopes(self::SOFT_DELETE_SCOPES);
            $dims  = self::scopeDimensions($q, $model->getTable());

            $found = [];
            foreach (array_chunk($pks, 500) as $chunk) {
                $ids = array_map(static fn (string $v) => preg_match('/^(?:0|[1-9][0-9]*)$/', $v) ? (int) $v : $v, $chunk);
                foreach ((clone $q)->whereIn($model->qualifyColumn($key), $ids)->pluck($key) as $v) {
                    $found[(string) $v] = true;
                }
            }

            $missing = array_values(array_filter($pks, static fn (string $pk) => ! isset($found[$pk])));
            if ($missing !== []) {
                $found += $dims === []
                    ? array_fill_keys($missing, true)            // tabela sem escopo de unidade/tenant
                    : self::visibleByLoggedScope($table, $missing, $dims);
            }

            return $found;
        } catch (\Throwable $e) {
            self::warn($e);

            return [];
        }
    }

    /**
     * Colunas de escopo que os escopos globais do model aplicaram AGORA
     * (`unit_id`/`tenant_id` => valor corrente). Vazio = o model não é
     * escopado (catálogo compartilhado) ou não há unidade/tenant corrente.
     *
     * @return array<string, mixed>
     */
    private static function scopeDimensions(\Illuminate\Database\Eloquent\Builder $q, string $table): array
    {
        $dims = [];
        foreach ((array) $q->toBase()->wheres as $w) {
            if (($w['type'] ?? '') !== 'Basic' || ($w['operator'] ?? '') !== '=' || ($w['boolean'] ?? 'and') !== 'and') {
                continue;
            }
            $col = (string) ($w['column'] ?? '');
            if (str_starts_with($col, $table . '.')) {
                $col = substr($col, strlen($table) + 1);
            }
            if (in_array($col, ['unit_id', 'tenant_id'], true)) {
                $dims[$col] = $w['value'] ?? null;
            }
        }

        return $dims;
    }

    /**
     * Registros que não existem mais: visíveis se a ÚLTIMA unidade/tenant
     * gravada no log para eles (exclusão → valor antigo; criação/alteração →
     * valor novo) bate com o escopo corrente. Sem esse dado no log → oculto.
     *
     * @param list<string>         $pks
     * @param array<string, mixed> $dims
     * @return array<string, true>
     */
    private static function visibleByLoggedScope(string $table, array $pks, array $dims): array
    {
        $last = [];
        foreach (array_chunk($pks, 500) as $chunk) {
            $rows = DB::connection(self::connectionName())->table(self::TABLE)
                ->where('tablename', $table)
                ->whereIn('pkvalue', $chunk)
                ->whereIn('columnname', array_keys($dims))
                ->orderByDesc('id')
                ->get(['pkvalue', 'columnname', 'operation', 'oldvalue', 'newvalue']);
            foreach ($rows as $r) {
                $pk  = (string) $r->pkvalue;
                $col = (string) $r->columnname;
                if (! isset($last[$pk]) || ! array_key_exists($col, $last[$pk])) {
                    $last[$pk][$col] = $r->operation === 'deleted' ? $r->oldvalue : $r->newvalue;
                }
            }
        }

        $out = [];
        foreach ($pks as $pk) {
            foreach ($dims as $col => $value) {
                if (! isset($last[$pk][$col]) || (string) $last[$pk][$col] !== (string) $value) {
                    continue 2;
                }
            }
            $out[$pk] = true;
        }

        return $out;
    }

    /**
     * Model dono da tabela: primeiro os do app (ModelRegistry), depois qualquer
     * model já carregado. Mapa montado uma vez por request.
     *
     * @return class-string<Model>|null
     */
    private static function modelForTable(string $table): ?string
    {
        $map = DataScope::memo('changelog.table_models', 'all', static function (): array {
            try {
                $registry = array_values(ModelRegistry::map());
            } catch (\Throwable) {
                $registry = [];
            }

            $out = [];
            foreach (array_unique(array_merge($registry, get_declared_classes())) as $cls) {
                if (! is_string($cls) || ! is_subclass_of($cls, Model::class)) {
                    continue;
                }
                try {
                    $ref = new \ReflectionClass($cls);
                    if ($ref->isAbstract() || $ref->isAnonymous()) {
                        continue;
                    }
                    $name = strtolower((string) $ref->newInstanceWithoutConstructor()->getTable());
                } catch (\Throwable) {
                    continue;
                }
                $out[$name] ??= $cls;
            }

            return $out;
        });

        return $map[strtolower($table)] ?? null;
    }

    // ── internos ────────────────────────────────────────────────────────────

    /** Normaliza um valor cru para texto (null preservado; não-escalar → JSON). */
    private static function scalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** Igualdade sem ruído: null ≡ ''; numéricos por valor; senão string estrita. */
    private static function same(?string $a, ?string $b): bool
    {
        $a ??= '';
        $b ??= '';
        if ($a === $b) {
            return true;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return $a == $b; // comparação numérica nativa (int preciso, float por valor)
        }

        return false;
    }

    /** @param string[] $mask */
    private static function applyMask(array $rows, array $mask): array
    {
        if ($mask === []) {
            return $rows;
        }
        foreach ($rows as &$row) {
            if (in_array($row['column'], $mask, true)) {
                if ($row['old'] !== '' && $row['old'] !== null) { $row['old'] = self::MASK; }
                if ($row['new'] !== '' && $row['new'] !== null) { $row['new'] = self::MASK; }
            }
        }

        return $rows;
    }

    /**
     * Colunas cujo NOME já denuncia segredo (password, senha, token, secret…):
     * mascaradas sempre, mesmo sem $hidden no model — um hash de senha não
     * pode parar no log por esquecimento. Padrões em mad.change_log.mask_patterns.
     */
    private static function sensitiveColumns(array $columns): array
    {
        try {
            $patterns = config('mad.change_log.mask_patterns');
        } catch (\Throwable) {
            $patterns = null;
        }
        $patterns = is_array($patterns) ? $patterns : self::DEFAULT_MASK_PATTERNS;

        return array_values(array_filter($columns, function ($col) use ($patterns) {
            $c = strtolower((string) $col);
            foreach ($patterns as $p) {
                if ($p !== '' && str_contains($c, strtolower((string) $p))) {
                    return true;
                }
            }

            return false;
        }));
    }

    /** Colunas de carimbo do model (HasMadAudit / HasMadSoftDeletes / timestamps). */
    private static function stampColumns(Model $model): array
    {
        $getters = [
            'getCreatedAtColumn', 'getUpdatedAtColumn', 'getDeletedAtColumn',
            'getCreatedByColumn', 'getUpdatedByColumn', 'getDeletedByColumn',
            'getCreatedByUserIdColumn', 'getUpdatedByUserIdColumn', 'getDeletedByUserIdColumn',
            'getCreatedByUnitIdColumn',
        ];
        $cols = [];
        foreach ($getters as $getter) {
            if (method_exists($model, $getter)) {
                $col = $model->{$getter}();
                if (is_string($col) && $col !== '') {
                    $cols[] = $col;
                }
            }
        }

        return $cols;
    }

    /** Lê `except`/`mask` do model (via HasMadChangeLog::madChangeLogOptions). */
    private static function modelOption(Model $model, string $key): array
    {
        if (! method_exists($model, 'madChangeLogOptions')) {
            return [];
        }
        $opt = $model->madChangeLogOptions()[$key] ?? [];

        return is_array($opt) ? array_values($opt) : [(string) $opt];
    }

    /** Completa cada linha com o envelope (quem/quando/onde) — mesmo shape da tabela. */
    private static function envelope(Model $model, array $rows): array
    {
        $now   = date('Y-m-d H:i:s');
        $login = self::sessionValue('login');
        $sid   = self::sessionId();
        $ip    = $_SERVER['REMOTE_ADDR'] ?? (PHP_SAPI === 'cli' ? 'cli' : null);
        $tx    = TransactionId::get();
        $trace = (new \Exception)->getTraceAsString();
        $class = self::currentClass();
        $pk    = $model->getKeyName();
        $pkVal = $model->getKey();

        return array_map(fn (array $r) => [
            'logdate'        => $now,
            'log_year'       => date('Y'),
            'log_month'      => date('m'),
            'log_day'        => date('d'),
            'login'          => $login,
            'tablename'      => $model->getTable(),
            'primarykey'     => $pk,
            'pkvalue'        => $pkVal === null ? null : (string) $pkVal,
            'operation'      => $r['operation'],
            'columnname'     => $r['column'],
            'oldvalue'       => $r['old'],
            'newvalue'       => $r['new'],
            'access_ip'      => $ip,
            'transaction_id' => $tx,
            'log_trace'      => $trace,
            'session_id'     => $sid,
            'class_name'     => $class,
            'php_sapi'       => PHP_SAPI,
        ], $rows);
    }

    /** Adia $fn para o commit da conexão do model; fora de transação roda na hora. */
    private static function deferToCommit(Model $model, callable $fn): void
    {
        $conn = $model->getConnection();
        if (method_exists($conn, 'afterCommit') && $conn->transactionLevel() > 0) {
            $conn->afterCommit($fn);

            return;
        }
        $fn();
    }

    private static function write(array $rows): void
    {
        try {
            DB::connection(self::connectionName())->table(self::TABLE)->insert($rows);
        } catch (\Throwable $e) {
            self::warn($e);
        }
    }

    /**
     * Classe do control corrente: ?class= legado → CurrentControl (registrado
     * pelo MadComponentHandler no POST /app/_mad-wire, onde a classe só existe
     * dentro do mad_state criptografado) → default 'class' da rota MAD → ''.
     */
    private static function currentClass(): string
    {
        try {
            $class = $_REQUEST['class'] ?? CurrentControl::get();
            if (! $class && app()->bound('request')) {
                $class = request()->route()?->parameter('class');
            }

            return $class ? class_basename((string) $class) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function sessionValue(string $key): ?string
    {
        try {
            $v = app()->bound('session') ? session($key) : null;

            return $v === null ? null : (string) $v;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function sessionId(): ?string
    {
        try {
            return app()->bound('session') ? (session()->getId() ?: null) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function warn(\Throwable $e): void
    {
        try {
            Log::warning('[mad change-log] ' . $e->getMessage());
        } catch (\Throwable) {
            error_log('[mad change-log] ' . $e->getMessage());
        }
    }
}
