<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Mad\Service\MadUploadStorage;
use Mad\Ui\MadRecordInUse;

/**
 * MadRecordFiles — os arquivos de um registro saem com ele, quando ele sai DE
 * FATO do banco (fórum #274, decisão do dono: "só quando de fato excluir do
 * banco de dados").
 *
 * Só vale para o Model que DECLARA as colunas que guardam arquivo enviado
 * (o caminho no disco de uploads, como o Upload grava com `storage="disk"`):
 *
 *     class Pedido extends Model
 *     {
 *         protected array $madFiles = ['foto', 'anexos'];
 *     }
 *
 * O Studio escreve essa linha quando a coluna tem a marca "Guarda arquivo
 * enviado" em Modelos de dados. Sem a declaração nada muda: nenhum arquivo é
 * apagado. `protected array $madFiles = [];` (vazio) não apaga nada do registro,
 * mas leva os arquivos das filhas que o banco apaga junto (abaixo).
 *
 * Quando o arquivo sai (tudo conferido DEPOIS do commit, uma linha por vez):
 *
 *  - o registro não existe mais no banco — exclusão lógica (HasMadSoftDeletes
 *    com a coluna, SoftDeletes) não apaga nada, nem dele nem das filhas;
 *  - exclusão recusada (`deleting` devolve false), que lança erro ou cuja
 *    transação é desfeita: nada sai;
 *  - o caminho não é usado por outra linha da mesma tabela, em nenhuma das
 *    colunas declaradas (a linha na lixeira conta: restaurada, volta com ele);
 *  - o caminho é um arquivo do disco de uploads: `data:`/BLOB, endereço
 *    `http(s)://`, caminho absoluto ou com `..` ficam de fora;
 *  - o arquivo público sai com a pasta própria (fórum #269).
 *
 * Filhas: a que o próprio Model apaga junto (o `deleting` do Model gerado, pela
 * ação Cascade da FK) dispara os eventos dela, e cuida dos arquivos dela se
 * declarar os seus. A que só o BANCO apaga (`on delete cascade`, sem evento) é
 * lida ANTES da exclusão, pelas relações hasMany/hasOne do Model e pela regra
 * da FK no banco — a mesma leitura da pergunta "vai junto" da listagem —, até
 * {@see MAX_DEPTH} níveis; cada uma só tem o arquivo apagado se a linha dela
 * de fato sumiu. Exclusão em massa pela consulta (`Model::where()->delete()`)
 * não passa pelo Model e não apaga arquivo.
 */
final class MadRecordFiles
{
    /** Níveis de filhas lidos: filhas, netas e bisnetas (como a pergunta "vai junto"). */
    public const MAX_DEPTH = 3;

    /** Linhas filhas lidas por exclusão; acima disso os arquivos delas ficam (e vai para o log). */
    public const MAX_ROWS = 2000;

    /** @var \WeakMap<Model, list<array{0:class-string<Model>,1:?string,2:mixed,3:string,4:string}>>|null */
    private static ?\WeakMap $pending = null;

    /** Liga os listeners (MadServiceProvider). */
    public static function listen(\Illuminate\Contracts\Events\Dispatcher $events): void
    {
        $events->listen('eloquent.deleting: *', [self::class, 'onDeleting']);
        $events->listen('eloquent.deleted: *', [self::class, 'onDeleted']);
    }

    /** O Model declara `$madFiles` (mesmo vazio)? */
    public static function declares(Model $model): bool
    {
        return property_exists($model, 'madFiles');
    }

    /**
     * As colunas de arquivo que o Model declara, sem repetir e só com nome de
     * coluna válido.
     *
     * @return list<string>
     */
    public static function columns(Model $model): array
    {
        if (! self::declares($model)) {
            return [];
        }
        $raw = (fn () => $this->madFiles ?? [])->call($model);
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $column) {
            if (is_string($column) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column) && ! in_array($column, $out, true)) {
                $out[] = $column;
            }
        }

        return $out;
    }

    /** O registro tem algum arquivo nas colunas declaradas (para a pergunta da listagem)? */
    public static function hasFiles(Model $model): bool
    {
        foreach (self::columns($model) as $column) {
            foreach (self::paths($model->getRawOriginal($column, $model->getAttribute($column))) as $path) {
                if (self::isUploadPath($path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A linha da listagem (os valores, como a listagem os carregou) tem algum
     * arquivo nas colunas que `$class` declara? Coluna que a listagem não
     * carregou não conta.
     *
     * @param class-string<Model> $class
     */
    public static function rowHasFiles(string $class, array $row): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }
        $model = new $class();
        // Com lixeira a exclusão só marca: nenhum arquivo sai — a pergunta não promete.
        if (MadRecordInUse::isTrashDelete($model)) {
            return false;
        }
        foreach (self::columns($model) as $column) {
            foreach (self::paths($row[$column] ?? null) as $path) {
                if (self::isUploadPath($path)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** O Model `$class` declara ao menos uma coluna de arquivo e a exclusão dele é de verdade (sem lixeira)? */
    public static function classHasColumns(string $class): bool
    {
        if (! is_subclass_of($class, Model::class)) {
            return false;
        }
        $model = new $class();

        return self::columns($model) !== [] && ! MadRecordInUse::isTrashDelete($model);
    }

    /**
     * "Excluir este registro?" → "Excluir este registro e os arquivos dele?".
     * Sem o "?" no fim, o complemento vai no fim.
     */
    public static function withFilesNote(string $message, bool $many = false): string
    {
        $note = (string) mad_t($many ? 'mad.ui.confirm_delete_files_many' : 'mad.ui.confirm_delete_files');
        if ($note === '' || str_starts_with($note, 'mad.ui.')) {
            $note = $many ? ' e os arquivos deles' : ' e os arquivos dele';
        }
        $trimmed = rtrim($message);
        if (str_ends_with($trimmed, '?')) {
            return substr($trimmed, 0, -1) . $note . '?';
        }

        return $trimmed . $note;
    }

    /** @internal Listener de `eloquent.deleting: *`. */
    public static function onDeleting(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || ! self::declares($model) || MadRecordInUse::isTrashDelete($model)) {
            return;
        }
        try {
            $found = self::own($model);
            MadRecordInUse::forgetForeignKeys();
            $rows = 0;
            self::cascaded($model, 1, $found, $rows);
        } catch (\Throwable $e) {
            // Sem a lista, nada é apagado: o arquivo fica.
            error_log('[MadRecordFiles] não deu para ler os arquivos de ' . $model::class . ': ' . $e->getMessage());

            return;
        } finally {
            MadRecordInUse::forgetForeignKeys();
        }
        if ($found !== []) {
            self::$pending ??= new \WeakMap();
            self::$pending[$model] = $found;
        }
    }

    /** @internal Listener de `eloquent.deleted: *`. */
    public static function onDeleted(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model || self::$pending === null || ! isset(self::$pending[$model])) {
            return;
        }
        $found = self::$pending[$model];
        unset(self::$pending[$model]);

        $class = $model::class;
        $conn  = $model->getConnectionName();
        $key   = $model->getKey();
        self::afterCommit($model, static function () use ($class, $conn, $key, $found): void {
            self::discard($class, $conn, $key, $found);
        });
    }

    // ── Internos ────────────────────────────────────────────────────────

    /**
     * Os caminhos do próprio registro.
     *
     * @return list<array{0:class-string<Model>,1:?string,2:mixed,3:string,4:string}>
     */
    private static function own(Model $model): array
    {
        $out = [];
        $key = $model->getKey();
        if ($key === null) {
            return [];
        }
        foreach (self::columns($model) as $column) {
            foreach (self::paths($model->getRawOriginal($column, $model->getAttribute($column))) as $path) {
                $out[] = [$model::class, $model->getConnectionName(), $key, $column, $path];
            }
        }

        return $out;
    }

    /**
     * As filhas que o BANCO vai apagar junto (`on delete cascade`), com os
     * caminhos das que declaram arquivos.
     *
     * @param list<array{0:class-string<Model>,1:?string,2:mixed,3:string,4:string}> $found
     */
    private static function cascaded(Model $model, int $depth, array &$found, int &$rows, ?\Illuminate\Database\Eloquent\Builder $parents = null): void
    {
        foreach (MadRecordInUse::childRelations($model, $parents === null) as $relation) {
            if (MadRecordInUse::deleteRule($relation, $model) !== 'cascade') {
                continue;
            }
            $query = $relation->getQuery();
            if ($parents !== null) {
                $query->whereIn(
                    $relation->getQualifiedForeignKeyName(),
                    (clone $parents)->select($model->qualifyColumn($relation->getLocalKeyName()))
                );
                if ($relation instanceof MorphOneOrMany) {
                    $query->where($relation->getQualifiedMorphType(), $relation->getMorphClass());
                }
            }
            // O banco apaga a linha da filha mesmo na lixeira dela: o escopo da
            // lixeira não pode esconder nenhuma.
            $query = $query->withoutGlobalScopes();
            $child = $relation->getRelated();
            $columns = self::columns($child);
            if ($columns !== []) {
                if ($rows >= self::MAX_ROWS) {
                    return;
                }
                foreach ((clone $query)->limit(self::MAX_ROWS - $rows + 1)->get() as $row) {
                    if (++$rows > self::MAX_ROWS) {
                        error_log('[MadRecordFiles] mais de ' . self::MAX_ROWS . ' linhas filhas ao excluir ' . $model::class . ': os arquivos das que passaram do limite ficam.');

                        return;
                    }
                    foreach ($columns as $column) {
                        foreach (self::paths($row->getRawOriginal($column)) as $path) {
                            $found[] = [$child::class, $row->getConnectionName(), $row->getKey(), $column, $path];
                        }
                    }
                }
            }
            if ($depth < self::MAX_DEPTH) {
                self::cascaded($child, $depth + 1, $found, $rows, $query);
            }
        }
    }

    /**
     * Depois do commit: apaga o que de fato ficou sem dono.
     *
     * @param class-string<Model>                                                     $rootClass
     * @param list<array{0:class-string<Model>,1:?string,2:mixed,3:string,4:string}> $found
     */
    private static function discard(string $rootClass, ?string $rootConn, mixed $rootKey, array $found): void
    {
        try {
            // O registro voltou (lixeira por outro caminho, restaurado, outra
            // inserção com a mesma chave): nada sai.
            if (self::rowExists($rootClass, $rootConn, $rootKey)) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[MadRecordFiles] não deu para conferir ' . $rootClass . ': ' . $e->getMessage());

            return;
        }

        $gone = [];   // classe|chave => a linha sumiu?
        $done = [];
        foreach ($found as [$class, $conn, $key, , $path]) {
            if (isset($done[$path]) || ! self::isUploadPath($path)) {
                continue;
            }
            try {
                $id = $class . '|' . $conn . '|' . (string) $key;
                $gone[$id] ??= ! self::rowExists($class, $conn, $key);
                if (! $gone[$id] || self::inUse($class, $conn, $path) || ! MadUploadStorage::exists($path)) {
                    continue;
                }
                MadUploadStorage::delete($path);
                $done[$path] = true;
            } catch (\Throwable $e) {
                // O banco já confirmou: o que sobra é um arquivo sem dono, no log.
                error_log('[MadRecordFiles] o arquivo "' . $path . '" não saiu do disco: ' . $e->getMessage());
            }
        }
    }

    /** @param class-string<Model> $class */
    private static function rowExists(string $class, ?string $conn, mixed $key): bool
    {
        if ($key === null) {
            return true;   // sem chave não dá para conferir: o arquivo fica
        }

        return self::instance($class, $conn)->newQueryWithoutScopes()->whereKey($key)->exists();
    }

    /** @param class-string<Model> $class */
    private static function instance(string $class, ?string $conn): Model
    {
        $model = new $class();
        if ($conn !== null) {
            $model->setConnection($conn);
        }

        return $model;
    }

    /**
     * Outra linha da tabela (a da lixeira conta) ainda aponta para `$path` em
     * alguma das colunas declaradas?
     *
     * @param class-string<Model> $class
     */
    private static function inUse(string $class, ?string $conn, string $path): bool
    {
        $model = self::instance($class, $conn);
        foreach (self::columns($model) as $column) {
            $candidates = $model->newQueryWithoutScopes()
                ->where($column, 'like', '%' . basename($path) . '%')
                ->pluck($column);
            foreach ($candidates as $value) {
                if (in_array($path, self::paths($value), true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Caminhos guardados numa célula: um só, a lista por vírgula (vários
     * arquivos) ou uma lista JSON de textos.
     *
     * @return list<string>
     */
    public static function paths(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }
        $value = trim($value);
        if (str_starts_with($value, 'data:')) {
            return [];
        }
        if (str_starts_with($value, '[')) {
            $list = json_decode($value, true);

            return is_array($list)
                ? array_values(array_filter(array_map(static fn ($p) => is_string($p) ? trim($p) : '', $list), static fn (string $p): bool => $p !== ''))
                : [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $p): bool => $p !== ''));
    }

    /** Caminho relativo do disco de uploads (nada de `data:`, URL, absoluto ou `..`)? */
    public static function isUploadPath(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= 1024
            && ! str_contains($path, "\0")
            && ! str_starts_with($path, 'data:')
            && ! str_contains($path, '://')
            && ! str_starts_with($path, '/')
            && ! str_starts_with($path, '\\')
            && ! preg_match('/^[A-Za-z]:/', $path)
            && ! in_array('..', preg_split('#[/\\\\]+#', $path), true);
    }

    /**
     * Roda `$callback` quando a transação do registro confirmar (desfeita, ele
     * não roda). Sem transação aberta, roda já — o banco já confirmou.
     */
    private static function afterCommit(Model $model, \Closure $callback): void
    {
        $connection = $model->getConnection();
        if ($connection->transactionLevel() === 0) {
            $callback();

            return;
        }
        try {
            $connection->afterCommit($callback);
        } catch (\RuntimeException $e) {
            // Transação aberta sem o gerenciador: não há como saber se ela vai
            // confirmar. O arquivo fica.
            error_log('[MadRecordFiles] transação sem gerenciador: os arquivos de ' . $model::class . ' ficam.');
        }
    }
}
