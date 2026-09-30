<?php

namespace Mad\Database;

/**
 * QueryCache — cache opcional dos resultados de agregação dos componentes de
 * chart/metric (BaseChart::loadData e QuerySource::aggregate).
 *
 * Chave = conexão + SQL + bindings: qualquer filtro aplicado ao builder já
 * está nos bindings, então cada combinação de filtros vira uma entrada
 * própria (write-through conforme o usuário navega).
 *
 * OFF por default (`mad.chart.cache_ttl` = 0) — comportamento idêntico ao
 * atual em apps que não configuram. Falha do store NUNCA derruba o chart:
 * cai pro exec direto.
 */
class QueryCache
{
    public static function remember(string $conn, string $sql, array $binds, \Closure $exec): mixed
    {
        $ttl = 0;
        if (function_exists('config')) {
            $ttl = (int) config('mad.chart.cache_ttl', 0);
        }
        if ($ttl <= 0) {
            return $exec();
        }

        $key = 'madchart:' . md5($conn . '|' . $sql . '|' . json_encode($binds, JSON_PARTIAL_OUTPUT_ON_ERROR));

        // Armazena JSON, não o objeto serializado: serialize() de Collection/
        // stdClass com props protegidas contém NULL BYTES (\0*\0items) e o
        // store database em PostgreSQL trunca TEXT no \0 → unserialize devolve
        // "incomplete object". JSON é binário-limpo em qualquer store.
        try {
            $json = \Illuminate\Support\Facades\Cache::remember(
                $key,
                $ttl,
                fn () => json_encode($exec(), JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_UNICODE),
            );

            $decoded = json_decode((string) $json, false);

            // Coleções de linhas voltam como array de stdClass → re-embrulha
            // em Collection (consumidores fazem foreach + acesso a propriedade).
            return is_array($decoded) ? collect($decoded) : $decoded;
        } catch (\Throwable $e) {
            @error_log('[Mad QueryCache] fallback sem cache: ' . $e->getMessage());

            return $exec();
        }
    }
}
