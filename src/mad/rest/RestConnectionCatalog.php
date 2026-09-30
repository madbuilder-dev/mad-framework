<?php

namespace Mad\Rest;

/**
 * Catálogo de BANCOS do próprio app exposto pelo Driver REST (ping) para o
 * Database Manager do builder montar o seletor de banco.
 *
 * Lista as conexões Laravel do app DEDUPLICADAS por banco físico: no layout
 * sqlite padrão as 6 conexões MAD (iam/log/business/comm/ged/ai) + as conexões
 * por data-model apontam TODAS para o mesmo mad.sqlite — o catálogo devolve
 * UMA entrada por banco (a primeira conexão vira a canônica, as demais viram
 * `aliases`). Entram as conexões declaradas no config do app (default + MAD +
 * data-model + quaisquer outras) — só os templates de engine do Laravel
 * (sqlite/mysql/mariadb/pgsql/sqlsrv, quando não são a default) ficam de fora;
 * o targeting aceita qualquer conexão existente no config (allowlist).
 *
 * A fonte é `config('database.connections')`, não o `.env`: é o único lugar
 * que sobrevive ao `config:cache` (o Laravel pula o carregamento do .env
 * quando há config cacheado, então env() volta null e getenv() volta false).
 */
final class RestConnectionCatalog
{
    /** Conexões lógicas fixas do MAD. */
    private const MAD_CONNECTIONS = ['iam', 'log', 'business', 'comm', 'ged', 'ai'];

    /**
     * Nomes que o config/database.php do Laravel traz como TEMPLATE de engine.
     * Ficam fora do catálogo (a menos que sejam a conexão default do app).
     */
    private const ENGINE_TEMPLATES = ['sqlite', 'mysql', 'mariadb', 'pgsql', 'sqlsrv'];

    /**
     * Catálogo deduplicado. Chave pinada no keystore → só a entrada dela
     * (não vaza o resto do app pra uma chave escopada).
     *
     * @return array<int,array{connection:string,driver:string,database:string,aliases:array<int,string>}>
     */
    public static function list(string $pinnedConnection = ''): array
    {
        if ($pinnedConnection !== '') {
            $entry = self::entry($pinnedConnection);

            return $entry === null ? [] : [self::publicEntry($entry)];
        }

        $byDatabase = [];
        foreach (self::candidates() as $name) {
            $entry = self::entry($name);
            if ($entry === null) {
                continue;
            }
            if (isset($byDatabase[$entry['dedupe']])) {
                $byDatabase[$entry['dedupe']]['aliases'][] = $name;

                continue;
            }
            $byDatabase[$entry['dedupe']] = $entry;
        }

        return array_values(array_map(self::publicEntry(...), $byDatabase));
    }

    /** Rótulo de banco de UMA conexão (pro "conectado em" do builder). */
    public static function databaseLabel(string $connection): string
    {
        return self::entry($connection)['database'] ?? '';
    }

    /**
     * Candidatas = default + MAD + TODAS as demais conexões do config que não
     * sejam os templates de engine do Laravel (sqlite/mysql/mariadb/pgsql/
     * sqlsrv, que só existem como modelo e não representam um banco do app).
     *
     * A fonte é `config('database.connections')` — as conexões por data model
     * (MAD_DATA_MODELS) já estão materializadas ali e, ao contrário do `.env`,
     * o config sobrevive ao `config:cache`. Antes isto dependia de
     * `getenv('DB_CONNECTION_NAMES')`, que os apps do MadBuilder nunca setam
     * (usam MAD_DATA_MODELS) e que volta `false` com config cacheado — os
     * bancos de data model ficavam invisíveis no Database Manager. O env legado
     * continua sendo unido, para quem já dependia dele.
     *
     * @return array<int,string>
     */
    private static function candidates(): array
    {
        $default = (string) config('database.default');

        $names = array_merge([$default], self::MAD_CONNECTIONS);

        $configured = array_keys((array) config('database.connections', []));
        foreach ($configured as $name) {
            $name = (string) $name;
            if ($name === $default || in_array($name, self::ENGINE_TEMPLATES, true)) {
                continue;
            }
            $names[] = $name;
        }

        $legacy = array_filter(array_map('trim', explode(',', (string) getenv('DB_CONNECTION_NAMES'))));

        return array_values(array_unique(array_merge($names, $legacy)));
    }

    /** @return array{connection:string,driver:string,database:string,dedupe:string,aliases:array<int,string>}|null */
    private static function entry(string $name): ?array
    {
        $cfg = config("database.connections.{$name}");
        if (! is_array($cfg)) {
            return null;
        }

        $driver = (string) ($cfg['driver'] ?? '');
        $database = (string) ($cfg['database'] ?? '');

        if ($driver === 'sqlite') {
            // Identidade física = o caminho do arquivo; rótulo = o basename.
            return [
                'connection' => $name,
                'driver' => 'sqlite',
                'database' => basename($database) ?: $database,
                'dedupe' => 'sqlite|'.$database,
                'aliases' => [],
            ];
        }

        return [
            'connection' => $name,
            'driver' => $driver,
            'database' => $database,
            'dedupe' => $driver.'|'.($cfg['host'] ?? '').':'.($cfg['port'] ?? '').'|'.$database,
            'aliases' => [],
        ];
    }

    /** @return array{connection:string,driver:string,database:string,aliases:array<int,string>} */
    private static function publicEntry(array $entry): array
    {
        unset($entry['dedupe']);

        return $entry;
    }
}
