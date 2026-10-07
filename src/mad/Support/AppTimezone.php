<?php

declare(strict_types=1);

namespace Mad\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * AppTimezone — fuso horário do app gerado, lido de `APP_TIMEZONE`.
 *
 * O MadBuilder grava o fuso escolhido em Configurações do projeto → Traduções
 * e o emite como `APP_TIMEZONE` no `.env` (zip/SSH, MadCloud e Teste Online).
 * Até a 5.123 o `config/app.php` fixava `'timezone' => 'UTC'` e nada lia a
 * variável: o fuso salvo no Studio nunca chegava ao app, que gravava e mostrava
 * a hora 3h adiantada para quem está no horário de Brasília (fórum #93).
 *
 * Fonte ÚNICA do valor: config/app.php (PHP/Carbon), config/mad.php
 * (`general.timezone`, usado pelos helpers de IA) e config/database.php (fuso
 * da SESSÃO do banco, que decide o `CURRENT_TIMESTAMP`/`now()` do SQL) chamam
 * esta classe, nunca leem o env na mão.
 *
 * Contrato:
 *   - id IANA válido (inclui os aliases antigos, ex. `America/Buenos_Aires`)
 *     → usado como veio;
 *   - ausente, vazio ou lixo → `UTC` no PHP e NENHUM `SET` no banco. É o
 *     comportamento de antes desta classe: app com `.env` antigo não muda.
 *     Lixo não pode chegar ao `date_default_timezone_set` (notice e fuso
 *     mantido) nem ao `SET TIME ZONE` (a conexão morreria no boot).
 */
final class AppTimezone
{
    public const ENV = 'APP_TIMEZONE';

    /** Fuso do PHP/Carbon (`app.timezone`). Sem env válida, UTC. */
    public static function resolve(): string
    {
        return self::configured() ?? 'UTC';
    }

    /**
     * Fuso com fallback próprio — `config/mad.php` (`general.timezone`) sempre
     * assumiu Brasília para os helpers de IA ("hoje" no prompt do agente).
     */
    public static function resolveOr(string $fallback): string
    {
        return self::configured() ?? $fallback;
    }

    /**
     * Fuso da sessão PostgreSQL (`set time zone '<id>'` do PostgresConnector).
     * O PgBouncer em transaction mode (MadCloud) acompanha o parâmetro
     * `TimeZone` por cliente, então o SET não vaza nem se perde entre
     * conexões do pool. `null` = sem SET (o connector testa com isset).
     */
    public static function pgsql(): ?string
    {
        return self::configured();
    }

    /**
     * Fuso da sessão MySQL/MariaDB como DESLOCAMENTO atual (`-04:00`), não
     * como nome: o servidor só entende `America/Campo_Grande` se as tabelas
     * de fuso (`mysql_tzinfo_to_sql`) estiverem carregadas, e a imagem oficial
     * e a maioria dos bancos gerenciados não carregam — o `SET time_zone`
     * derrubaria a conexão. O deslocamento é calculado a cada boot do config;
     * em fuso com horário de verão, worker de vida longa só pega a virada
     * quando reinicia.
     */
    public static function mysqlOffset(): ?string
    {
        $tz = self::configured();
        if ($tz === null) {
            return null;
        }

        return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('P');
    }

    /** Id IANA validado de `APP_TIMEZONE`, ou null quando ausente/inválido. */
    public static function configured(): ?string
    {
        $raw = env(self::ENV);
        $tz = is_string($raw) ? trim($raw) : '';

        return $tz !== '' && self::isValid($tz) ? $tz : null;
    }

    public static function isValid(string $tz): bool
    {
        static $known = null;
        $known ??= array_flip(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC));

        return isset($known[$tz]);
    }
}
