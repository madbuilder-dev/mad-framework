<?php

declare(strict_types=1);

namespace Mad\Database;

/**
 * MysqlSslOptions — opções PDO de TLS/SSL para conexões MySQL/MariaDB.
 *
 * Bancos gerenciados (DigitalOcean Managed MySQL, Aiven, PlanetScale e afins)
 * só aceitam conexão cifrada e entregam um CA próprio. Sem essas opções o PDO
 * abre a conexão em texto claro e o servidor derruba com "connections using
 * insecure transport are prohibited". Fonte ÚNICA das opções: config/database.php
 * (conexões MAD + as stock mysql/mariadb) e o Installer (botão "Testar conexão"
 * e applyRuntimeConnections) chamam esta classe, nunca montam o array na mão.
 *
 * Contrato de env (o prefixo default é DB_MAD; qualquer chave setada LIGA o TLS):
 *
 *   | chave                | valor                                    | atributo PDO                |
 *   |----------------------|------------------------------------------|-----------------------------|
 *   | DB_MAD_SSL_CA        | caminho do CA do servidor                | ATTR_SSL_CA                 |
 *   | DB_MAD_SSL_CERT      | caminho do certificado do CLIENTE        | ATTR_SSL_CERT               |
 *   | DB_MAD_SSL_KEY       | caminho da chave do CLIENTE              | ATTR_SSL_KEY                |
 *   | DB_MAD_SSL_VERIFY    | true (default) / false                   | ATTR_SSL_VERIFY_SERVER_CERT |
 *
 * `MYSQL_ATTR_SSL_CA` (nome stock do Laravel) é fallback de `{prefix}_SSL_CA`,
 * então um .env legado continua funcionando. Caminho RELATIVO é resolvido a
 * partir de `base_path()` (ex.: `storage/app/private/db-ssl/ca.pem`); absoluto
 * (`/etc/ssl/...` ou `C:\...`) vai como veio. VERIFY é emitido sempre que há
 * QUALQUER material TLS (CA, cert ou chave): no mysqlnd o default do driver é
 * verificar, então omitir o atributo NÃO é neutro — mTLS sem CA com verify
 * omitido morre em "[2002] Cannot connect to MySQL using SSL" (visto em
 * MySQL 8.4 com --require-secure-transport). Sem `pdo_mysql` carregado (app
 * rodando em sqlite/pgsql) devolve `[]`, porque as constantes não existem.
 */
final class MysqlSslOptions
{
    /**
     * Lê o contrato de env acima e devolve as opções PDO prontas.
     *
     * @return array<int,string|bool> vazio quando não há nenhuma chave SSL setada
     */
    public static function fromEnv(string $prefix = 'DB_MAD'): array
    {
        // CA do servidor: chave MAD primeiro, nome stock do Laravel como fallback.
        $ca = self::env("{$prefix}_SSL_CA") ?? self::env('MYSQL_ATTR_SSL_CA');

        return self::build(
            $ca,
            self::env("{$prefix}_SSL_CERT"),
            self::env("{$prefix}_SSL_KEY"),
            self::boolEnv("{$prefix}_SSL_VERIFY"),
        );
    }

    /**
     * Monta as opções a partir dos caminhos já conhecidos (sem tocar no env).
     *
     * @param  bool  $verify `false` desliga a checagem de hostname/certificado do
     *                       servidor — necessário quando o CA é auto-assinado ou o
     *                       host do DSN não bate com o CN do certificado.
     * @return array<int,string|bool>
     */
    public static function build(?string $ca, ?string $cert, ?string $key, bool $verify = true): array
    {
        // As constantes ATTR_SSL_* vivem na extensão; sem ela não há o que montar.
        if (! extension_loaded('pdo_mysql')) {
            return [];
        }

        $ca   = self::path($ca);
        $cert = self::path($cert);
        $key  = self::path($key);

        if ($ca === null && $cert === null && $key === null) {
            return [];
        }

        $options = [];
        if ($ca !== null) {
            $options[self::attr('CA')] = $ca;
        }
        if ($cert !== null) {
            $options[self::attr('CERT')] = $cert;
        }
        if ($key !== null) {
            $options[self::attr('KEY')] = $key;
        }
        // Sempre que há material TLS: o default do mysqlnd é verificar, então
        // omitir o atributo não é neutro (mTLS sem CA + verify omitido não
        // conecta). Aqui nunca chega sem material — o early-return acima garante.
        $options[self::attr('VERIFY')] = $verify;

        return $options;
    }

    /**
     * Constante PDO do atributo, à prova de versão: as `PDO::MYSQL_ATTR_*` foram
     * movidas pra classe `Pdo\Mysql` (PHP 8.4) e são DEPRECADAS no 8.5. O `match`
     * só avalia o braço escolhido, então o nome inexistente na versão corrente
     * nunca é resolvido (mesmo padrão do config/database.php do MadBuilder).
     */
    private static function attr(string $which): int
    {
        if (PHP_VERSION_ID >= 80500) {
            return match ($which) {
                'CA'     => \Pdo\Mysql::ATTR_SSL_CA,
                'CERT'   => \Pdo\Mysql::ATTR_SSL_CERT,
                'KEY'    => \Pdo\Mysql::ATTR_SSL_KEY,
                'VERIFY' => \Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT,
            };
        }

        return match ($which) {
            'CA'     => \PDO::MYSQL_ATTR_SSL_CA,
            'CERT'   => \PDO::MYSQL_ATTR_SSL_CERT,
            'KEY'    => \PDO::MYSQL_ATTR_SSL_KEY,
            'VERIFY' => \PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT,
        };
    }

    /** Caminho normalizado: vazio vira null; relativo é resolvido a partir da raiz do app. */
    private static function path(?string $raw): ?string
    {
        $path = trim((string) $raw);
        if ($path === '') {
            return null;
        }
        if (self::isAbsolute($path)) {
            return $path;
        }

        // `base_path()` só existe com o app de pé (o pacote depende de
        // illuminate/container, não de foundation) — fora dele devolve como veio.
        if (function_exists('base_path')) {
            try {
                return base_path($path);
            } catch (\Throwable) {
                // app não bootado (ex.: script/teste unitário puro)
            }
        }

        return $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('~^[A-Za-z]:[\\\\/]~', $path);
    }

    /** Valor de env já trimado; setado-mas-vazio conta como ausente. */
    private static function env(string $key): ?string
    {
        $value = trim((string) env($key, ''));

        return $value !== '' ? $value : null;
    }

    /**
     * Booleano de env tolerante a "false"/"0"/"off"; ausente ou ilegível = true.
     * O `env()` do Laravel JÁ converte "true"/"false" em bool — sem o teste de
     * is_bool(), o `(string) false` viraria '' e cairia no default true, isto é,
     * DB_MAD_SSL_VERIFY=false não desligaria nada.
     */
    private static function boolEnv(string $key): bool
    {
        $raw = env($key);
        if (is_bool($raw)) {
            return $raw;
        }

        $raw = trim((string) $raw);

        return $raw !== ''
            ? (filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true)
            : true;
    }
}
