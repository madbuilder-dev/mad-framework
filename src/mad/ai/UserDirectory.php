<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Mad\Database\DataScope;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * UserDirectory — o NOME dos usuários do sistema para a SQL do assistente.
 *
 * `mad_iam_user` é tabela protegida inteira ({@see \Mad\Security\ProtectedData}):
 * guarda o hash da senha e o segredo do 2FA, e SQL crua não se deixa restringir
 * por coluna. Só que toda coluna "vendedor", "responsável", "criado por" aponta
 * para ela — e o Chat IA respondia "vendedor 3, 71,6%" em vez de "Ana Ribeiro".
 *
 * Saída: uma relação VIRTUAL `usuarios_sistema(id, nome)`, montada aqui em PHP
 * (id e nome, nada mais) e entregue à consulta como uma CTE de valores
 * literais. A SQL do modelo nunca lê `mad_iam_user` — continua recusada pelo
 * nome. E funciona com o cadastro de usuários em outra conexão (a `iam`), que
 * uma CTE lendo a tabela não alcançaria.
 *
 * Escopo: o mesmo da camada de tenancy do MCP ({@see \Mad\Mcp\McpScopeContext}).
 * Com Multi-unidade, só os usuários ligados à unidade ativa; com empresa em
 * pool, só os das unidades da empresa ativa. Sem unidade/empresa no contexto,
 * a lista vem vazia (fail-closed).
 */
final class UserDirectory
{
    /** Nome da relação virtual, como o modelo a escreve. */
    public const TABLE = 'usuarios_sistema';

    /** Teto de linhas na CTE literal (a consulta é montada em texto). */
    public const MAX_USERS = 5000;

    /**
     * Usuários visíveis no escopo atual: id => nome.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return DataScope::memo('ai.user_directory', 'names', static function (): array {
            try {
                return self::load();
            } catch (\Throwable $e) {
                error_log('[UserDirectory] usuários ilegíveis: ' . $e->getMessage());

                return [];
            }
        });
    }

    /**
     * Corpo da CTE `"usuarios_sistema" AS (…)` na conexão da consulta: os
     * valores são literais (id inteiro, nome com quote do driver).
     */
    public static function cte(string $db): string
    {
        $conn    = DB::connection($db);
        $grammar = $conn->getQueryGrammar();
        $id      = $grammar->wrap('id');
        $nome    = $grammar->wrap('nome');
        $alias   = $grammar->wrap(self::TABLE);

        $rows = [];
        foreach (self::names() as $uid => $name) {
            $q      = $conn->getPdo()->quote($name);
            $rows[] = $rows === []
                ? 'SELECT ' . (int) $uid . " AS {$id}, {$q} AS {$nome}"
                : 'SELECT ' . (int) $uid . ", {$q}";
        }

        $body = $rows !== []
            ? implode(' UNION ALL ', $rows)
            : "SELECT * FROM (SELECT NULL AS {$id}, NULL AS {$nome}) __mad_u WHERE 1=0";

        return "{$alias} AS ({$body})";
    }

    /**
     * Linhas que o db_schema anuncia para a relação virtual (mesmo formato das
     * tabelas do manifest: "coluna tipo [pk] [— significado]").
     *
     * @return list<string>
     */
    public static function schemaColumns(): array
    {
        return [
            'id integer pk',
            'nome text — nome do usuário do sistema (vendedor, responsável, criado por, atendente…)',
        ];
    }

    /** "mad_iam_user.id" (FK de coluna do manifest) aponta para a relação virtual. */
    public static function rewriteFk(string $fk): string
    {
        return (string) preg_replace('/^\s*mad_iam_user\s*\./i', self::TABLE . '.', $fk);
    }

    /** @return array<int, string> */
    private static function load(): array
    {
        $iam = DB::connection('iam');
        $q   = $iam->table('mad_iam_user as u')->select('u.id', 'u.name');

        $unitScope   = DataScope::unitScopeActive();
        $tenantScope = DataScope::tenantScopeActive();

        if ($unitScope) {
            $unit = UnitContext::id();
            if ($unit === null || $unit <= 0) {
                return [];
            }
            $q->where(static function ($w) use ($unit) {
                $w->where('u.unit_id', $unit)
                  ->orWhereExists(static function ($s) use ($unit) {
                      $s->selectRaw('1')->from('mad_iam_user_unit as uu')
                        ->whereColumn('uu.user_id', 'u.id')
                        ->where('uu.unit_id', $unit);
                  });
            });
        }

        if ($tenantScope) {
            $tenant = TenantContext::id();
            if ($tenant === null || $tenant <= 0) {
                return [];
            }
            $q->where(static function ($w) use ($tenant) {
                $w->whereIn('u.unit_id', static function ($s) use ($tenant) {
                    $s->select('t.id')->from('mad_iam_unit as t')->where('t.tenant_id', $tenant);
                })->orWhereExists(static function ($s) use ($tenant) {
                    $s->selectRaw('1')->from('mad_iam_user_unit as uu')
                      ->join('mad_iam_unit as t2', 't2.id', '=', 'uu.unit_id')
                      ->whereColumn('uu.user_id', 'u.id')
                      ->where('t2.tenant_id', $tenant);
                });
            });
        }

        $out = [];
        foreach ($q->orderBy('u.id')->limit(self::MAX_USERS + 1)->get() as $r) {
            $name = trim((string) ($r->name ?? ''));
            if ($name !== '') {
                $out[(int) $r->id] = $name;
            }
        }
        if (count($out) > self::MAX_USERS) {
            error_log('[UserDirectory] mais de ' . self::MAX_USERS . ' usuários no escopo: o assistente vê só os primeiros.');
            $out = array_slice($out, 0, self::MAX_USERS, true);
        }

        return $out;
    }
}
