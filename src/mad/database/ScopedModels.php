<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * ScopedModels — quais models têm escopo por LINHA (unidade/tenant) e de qual
 * tabela cada um é. Serve a quem só enxerga o NOME da tabela e precisa saber se
 * ela é escopada — hoje o verificador de presença das regras `unique`/`exists`
 * ({@see \Mad\Form\ScopedPresenceVerifier}).
 *
 * Por que existe: `Rule::unique(Model::class)` vira `unique:conexao.tabela,...`
 * antes de chegar ao validador — a classe do model se perde no caminho. Sem
 * este mapa a validação contava as linhas de TODAS as unidades: num SaaS
 * Multi-unidade uma unidade não conseguia usar um código que outra já usava, e a
 * mensagem "já está sendo utilizado" revelava que o código existe em outro
 * cliente; `exists` aceitava o id de um registro de outra unidade.
 *
 * Registro: BelongsToUnit/BelongsToTenant chamam {@see register()} no boot do
 * model — o `new` que o próprio `Rule::unique(Model::class)` /
 * `Rule::exists(Model::class)` faz já dispara. Regra em string
 * (`exists:conexao.tabela,id`) cujo model ainda não subiu neste processo ganha
 * uma busca de melhor esforço pelo nome da tabela no {@see ModelRegistry} — só
 * com algum escopo ligado, então app de unidade única não paga nada.
 *
 * Estado por PROCESSO de propósito: é metadado de classe (qual model mora em
 * qual tabela), igual ao boot do Eloquent — nenhum dado de usuário/unidade.
 */
final class ScopedModels
{
    /** @var array<class-string<Model>, true> */
    private static array $classes = [];

    /** @var array<class-string<Model>, string> classe => chave "conexão|tabela" */
    private static array $classKeys = [];

    /** @var array<string, class-string<Model>|null> chave => model (memo da busca) */
    private static array $byTable = [];

    /** @var array<string, true> chaves já procuradas no ModelRegistry */
    private static array $probed = [];

    /** Chamado pelo boot de BelongsToUnit/BelongsToTenant. */
    public static function register(string $class): void
    {
        if (isset(self::$classes[$class])) {
            return;
        }
        self::$classes[$class] = true;
        // Model novo pode responder por uma tabela que já foi consultada sem dono.
        self::$byTable = [];
    }

    /**
     * Model escopado dono da tabela, ou null. `$connection` null = conexão
     * padrão. Tabela com schema (`public.cliente`) casa pelo nome simples.
     *
     * @return class-string<Model>|null
     */
    public static function forTable(?string $connection, string $table): ?string
    {
        $key = self::key($connection, $table);
        if (array_key_exists($key, self::$byTable)) {
            return self::$byTable[$key];
        }

        $found = self::scan($key);
        if ($found === null && ! isset(self::$probed[$key])) {
            self::$probed[$key] = true;
            self::probe($table);
            $found = self::scan($key);
        }

        return self::$byTable[$key] = $found;
    }

    /** Esvazia o registro — testes (junto com Model::clearBootedModels()). */
    public static function flush(): void
    {
        self::$classes   = [];
        self::$classKeys = [];
        self::$byTable   = [];
        self::$probed    = [];
    }

    private static function scan(string $key): ?string
    {
        foreach (array_keys(self::$classes) as $class) {
            if (self::classKey($class) === $key) {
                return $class;
            }
        }

        return null;
    }

    private static function classKey(string $class): ?string
    {
        if (array_key_exists($class, self::$classKeys)) {
            return self::$classKeys[$class];
        }
        try {
            /** @var Model $model */
            $model = new $class();

            return self::$classKeys[$class] = self::key($model->getConnectionName(), $model->getTable());
        } catch (\Throwable) {
            return self::$classKeys[$class] = null;
        }
    }

    /**
     * Regra em string sobre tabela cujo model não subiu neste processo: tenta o
     * model pelo nome da tabela (`status_os` → `StatusOs`, o nome que o gerador
     * dá) — o `new` faz o boot, que registra. Nunca lança.
     */
    private static function probe(string $table): void
    {
        if (! DataScope::active()) {
            return;
        }
        $base = self::baseName($table);
        foreach (array_unique([Str::studly($base), Str::studly(Str::singular($base))]) as $name) {
            try {
                $fqcn = ModelRegistry::lookup($name);
                if ($fqcn !== null && class_exists($fqcn) && is_subclass_of($fqcn, Model::class)) {
                    new $fqcn();
                }
            } catch (\Throwable) {
                // melhor esforço — sem model, a regra segue como antes
            }
        }
    }

    private static function key(?string $connection, string $table): string
    {
        $conn = ($connection === null || $connection === '') ? self::defaultConnection() : $connection;

        return strtolower((string) $conn) . '|' . strtolower(self::baseName($table));
    }

    private static function baseName(string $table): string
    {
        $pos = strrpos($table, '.');

        return $pos === false ? $table : substr($table, $pos + 1);
    }

    private static function defaultConnection(): string
    {
        try {
            return function_exists('config') ? (string) config('database.default', '') : '';
        } catch (\Throwable) {
            return '';
        }
    }
}
