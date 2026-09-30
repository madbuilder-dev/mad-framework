<?php

namespace Mad\Support;

use Mad\Core\AppConfig;

/**
 * Gate ÚNICO dos painéis de diagnóstico que expõem SQL na tela.
 *
 * Os widgets de dashboard (`<mad-kpi-card>`, `<mad-db-metric-card>`,
 * `<mad-db-chart>` e o card de comparação) sabem imprimir o SQL da consulta,
 * com os valores dos parâmetros já embutidos, numa tira "SQL DEBUG" abaixo do
 * indicador. Cada um resolvia o gate por conta própria, lendo
 * `general.debug` do config e comparando com a string `'1'`.
 *
 * Dois problemas nasciam daí:
 *
 *  1. O config do app-template trazia `'debug' => '1'` literal, sem env. TODO
 *     app publicado saía com o painel LIGADO: quem abria o dashboard via a tira
 *     "⚙ SQL DEBUG — METRIC_…" embaixo de cada indicador e gráfico, sem nunca
 *     ter pedido diagnóstico nenhum. Pior que feio: é o SQL da aplicação, com
 *     valores reais, na tela do usuário final.
 *  2. A comparação `=== '1'` só aceitava a string. Um `MAD_DEBUG=true` no .env
 *     (bool verdadeiro) DESLIGAVA o painel, e um `MAD_DEBUG=0` (string '0')
 *     parecia ligado pra quem lê `isset()`.
 *
 * O gate agora exige as DUAS coisas: a flag explícita do app E o debug do
 * Laravel (`APP_DEBUG`). Em produção `APP_DEBUG=false`, então o painel fica
 * desligado mesmo num app antigo que já tenha `general.debug = 1` gravado — é
 * o que conserta o app JÁ publicado, sem depender de ninguém reeditar config.
 */
final class MadDebug
{
    /** Lembra a resolução por request (o config não muda no meio do render). */
    private static ?bool $sqlPanel = null;

    /**
     * Painel de SQL dos widgets de dashboard está liberado?
     *
     * `general.debug` (flag explícita do app) E `app.debug` (APP_DEBUG).
     */
    public static function sqlPanel(): bool
    {
        if (self::$sqlPanel !== null) {
            return self::$sqlPanel;
        }

        return self::$sqlPanel = self::flagEnabled() && self::appDebug();
    }

    /** Só a flag do app (`general.debug` do config/mad.php), normalizada. */
    public static function flagEnabled(): bool
    {
        try {
            $ini = AppConfig::get();
        } catch (\Throwable $e) {
            return false;
        }

        return self::truthy($ini['general']['debug'] ?? null);
    }

    /** `config('app.debug')` — false quando o Laravel não está disponível. */
    public static function appDebug(): bool
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return self::truthy(config('app.debug'));
            }
        } catch (\Throwable $e) {
            // fora do Laravel (CLI isolado) — fail closed
        }

        return false;
    }

    /**
     * Normaliza o que pode aparecer no config: bool do `env()`, string '1'/'0'
     * do .ini legado, int, 'true'/'on'/'yes'. Qualquer outra coisa = desligado.
     */
    public static function truthy(mixed $v): bool
    {
        if (is_bool($v))                 return $v;
        if (is_int($v) || is_float($v))  return (int) $v === 1;
        if (! is_string($v))             return false;

        return in_array(strtolower(trim($v)), ['1', 'true', 'on', 'yes'], true);
    }

    /** Testes / troca de config em runtime. */
    public static function reset(): void
    {
        self::$sqlPanel = null;
    }
}
