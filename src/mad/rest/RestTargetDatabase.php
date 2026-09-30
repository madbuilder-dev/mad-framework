<?php

namespace Mad\Rest;

use RuntimeException;

/**
 * Guard de ISOLAMENTO do Driver REST (multi-database do MESMO app).
 *
 * Dado o database da conexão base (o próprio banco do app — sempre
 * `app_{uuid}`, uuid = 32 hex fixos) e um database alvo pedido no request,
 * devolve o alvo validado — ou `null` p/ usar o base. Aceita SÓ:
 *   - o próprio banco (`req === own`), ou
 *   - um irmão com o MESMO prefixo do app (`app_{uuid}_*`: bancos adicionais
 *     por data-model + bancos de tenant).
 * Recusa (fail-closed) qualquer outra coisa — formato inválido, banco de
 * sistema, e SOBRETUDO o banco de outro app.
 *
 * Por que o prefixo isola: todo uuid tem 32 hex de tamanho fixo, então
 * `app_{outroUuid}` NUNCA começa com `app_{meuUuid}_` (a fronteira `_`
 * impede casamento parcial tipo `app_1` × `app_12`). Backstop no banco: o
 * Postgres já REVOGA CONNECT cross-tenant (create-db.sh:
 * "REVOKE ALL ON DATABASE … FROM PUBLIC", cada banco owned pela role do app).
 * Defesa em profundidade: guard + grant.
 */
final class RestTargetDatabase
{
    /** Bancos de sistema — nunca acessíveis pelo driver do app. */
    private const SYSTEM = [
        'postgres', 'template0', 'template1',
        'mysql', 'information_schema', 'performance_schema', 'sys',
    ];

    /**
     * @param  string       $ownDatabase  database da conexão base (banco do app)
     * @param  string|null  $requested    database alvo pedido no request (ou null)
     * @return string|null  alvo validado, ou null p/ usar o base
     *
     * @throws RuntimeException  fail-closed em qualquer alvo fora do escopo do app
     */
    public static function resolve(string $ownDatabase, ?string $requested): ?string
    {
        $own = trim($ownDatabase);
        $req = $requested !== null ? trim($requested) : '';

        // Vazio ou o próprio banco → usa a conexão base (comportamento atual).
        if ($req === '' || $req === $own) {
            return null;
        }

        // Formato de identificador de banco (evita qualquer coisa esquisita no
        // parâmetro de conexão do PDO).
        if (! preg_match('/^[A-Za-z0-9_]{1,63}$/', $req)) {
            throw new RuntimeException('Database alvo inválido.');
        }

        // Bancos de sistema nunca.
        if (in_array(strtolower($req), self::SYSTEM, true)) {
            throw new RuntimeException('Database de sistema não é acessível.');
        }

        // Sem prefixo próprio conhecido → fail-closed (não dá pra provar posse).
        // Só bancos do PRÓPRIO app (mesmo prefixo, com fronteira `_`).
        if ($own === '' || ! str_starts_with($req, $own.'_')) {
            throw new RuntimeException('Database fora do escopo deste app.');
        }

        return $req;
    }
}
