<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Mad\Database\DataScope;
use Mad\Mcp\McpAccess;
use Mad\Security\ProtectedData;

/**
 * WidgetSqlScope — a SQL que o modelo escreve (preview/save_widget, query_db e
 * a re-execução dos widgets salvos) obedece às MESMAS regras das tools do MCP:
 * só tabelas concedidas ao perfil do usuário, só as colunas expostas (PII
 * mascarada) e só as linhas do escopo dele. Ver {@see McpAccess}.
 *
 * Até o 5.96.21 a SQL ignorava o manifest: qualquer usuário lia qualquer
 * tabela do banco que não fosse de credencial — clientes num perfil sem a tela
 * de clientes, as linhas de outro dono, a coluna que o manifest escondeu.
 *
 * Como, sem parser de SQL: toda relação (tabela/view) da conexão que a SQL
 * cita ganha uma CTE COM O MESMO NOME na frente da consulta —
 *
 *   WITH "ordem_servico" AS (SELECT <expostas>, <pii mascarada>
 *                            FROM "main"."ordem_servico" WHERE <escopo>),
 *        "cliente"       AS (SELECT * FROM "main"."cliente" WHERE 1=0)
 *   SELECT * FROM ( <SQL do modelo> ) __mad_w LIMIT n
 *
 * O nome não qualificado resolve na CTE antes da tabela (SQLite, Postgres e
 * MySQL 8), então qualquer forma de citá-la — maiúscula, "aspas", 'literal',
 * subconsulta, UNION, CTE do próprio modelo — lê a versão filtrada. O único
 * caminho que pularia a CTE é o nome QUALIFICADO (`main.x`, `public.x`,
 * `outrobanco.x`), e esse é recusado.
 *
 *  - Relação SEM concessão logo depois de FROM/JOIN → recusa amigável
 *    ("Você não tem acesso a cliente."), que o modelo repassa.
 *  - Relação sem concessão citada em outro ponto (vírgula de junção, apelido,
 *    coluna homônima) → CTE vazia: se era leitura disfarçada, não sai linha;
 *    se era só um nome, nada muda. A detecção de FROM/JOIN é para a mensagem
 *    honesta; a CTE vazia é a garantia.
 *  - Relação concedida → só as colunas expostas (coluna escondida não existe:
 *    `SELECT *` devolve as expostas, citar a escondida dá erro e não há
 *    oráculo por WHERE); PII vira '***' dentro da CTE, então nenhum apelido,
 *    função, WHERE ou GROUP BY a revela.
 *  - View só se o próprio manifest a expõe e concede; com Multi-unidade/pool
 *    ligado ela precisa ter a coluna de escopo (não dá para filtrar por
 *    dentro dela).
 *
 * Os valores do escopo são ids inteiros do contexto do token — nunca da SQL.
 */
final class WidgetSqlScope
{
    /**
     * @return array{ok: bool, error?: string, with?: string}
     *         with = prefixo "WITH … " (vazio quando a SQL não cita relação)
     */
    public static function plan(string $db, string $sql, ?McpAccess $access = null): array
    {
        $access ??= McpAccess::current();

        $rel = self::relations($db);
        if ($rel === null) {
            return ['ok' => false, 'error' => 'Não foi possível conferir as tabelas da consulta contra as permissões do seu perfil.'];
        }

        if (($q = self::qualifiedReference($db, $sql)) !== null) {
            return ['ok' => false, 'error' => "Escreva o nome da tabela sem o banco/esquema na frente (\"{$q}\"): nome qualificado não é permitido na consulta do assistente."];
        }

        $mentioned = [];
        foreach (ProtectedData::mentionedNames($sql) as $tok) {
            if (isset($rel[$tok])) {
                $mentioned[$tok] = $rel[$tok];
            }
        }

        foreach (self::fromJoinTargets($sql) as $target) {
            if (isset($rel[$target]) && ! $access->canRead($target)) {
                return ['ok' => false, 'error' => self::deniedMessage($rel[$target]['name'])];
            }
        }

        if ($mentioned === []) {
            return ['ok' => true, 'with' => ''];
        }

        $grammar = DB::connection($db)->getQueryGrammar();
        $ctes    = [];
        foreach ($mentioned as $info) {
            $name      = $info['name'];
            $qualified = $grammar->wrap(($info['schema'] !== '' ? $info['schema'] . '.' : '') . $name);
            $alias     = $grammar->wrap($name);

            if (! $access->canRead($name)) {
                $ctes[] = "{$alias} AS (SELECT * FROM {$qualified} WHERE 1=0)";
                continue;
            }

            $body = self::grantedBody($db, $access, $info, $qualified);
            if (! $body['ok']) {
                return ['ok' => false, 'error' => $body['error']];
            }
            $ctes[] = "{$alias} AS ({$body['sql']})";
        }

        return ['ok' => true, 'with' => 'WITH ' . implode(', ', $ctes) . ' '];
    }

    /**
     * Recusa — a MESMA frase vai ao modelo (que a repete, pela regra do prompt
     * em {@see SystemPromptAssembler}) e à tela "Meus Dashboards" (widget de
     * painel compartilhado): por isso é só a frase para o usuário.
     */
    public static function deniedMessage(string $table): string
    {
        return "Você não tem acesso a {$table}.";
    }

    /**
     * SELECT da CTE de uma relação concedida: colunas expostas (PII mascarada)
     * + filtros de linha.
     *
     * @param  array{name: string, schema: string, view: bool} $info
     * @return array{ok: bool, sql?: string, error?: string}
     */
    private static function grantedBody(string $db, McpAccess $access, array $info, string $qualified): array
    {
        $name    = $info['name'];
        $grammar = DB::connection($db)->getQueryGrammar();
        $real    = self::columnsOf($db, $name);
        if ($real === null) {
            return ['ok' => false, 'error' => "Não foi possível conferir as colunas de {$name}."];
        }

        $select = [];
        $seen   = [];
        foreach ($access->columns($name) as $f) {
            $col = (string) $f['name'];
            // manifest desatualizado (coluna que não existe mais): fica de fora
            if (! in_array(strtolower($col), $real, true) || isset($seen[strtolower($col)])) {
                continue;
            }
            $seen[strtolower($col)] = true;
            $w = $grammar->wrap($col);
            $select[] = ! empty($f['pii'])
                ? "CASE WHEN {$w} IS NULL THEN NULL ELSE '***' END AS {$w}"
                : $w;
        }
        if ($select === []) {
            return ['ok' => false, 'error' => self::deniedMessage($name)];
        }

        $rows = $access->rowFilters($db, $name);
        if (! $rows['ok']) {
            // o motivo é técnico (plano do row-scope): log, não tela
            error_log("[WidgetSqlScope] escopo de linha negado em {$name}: " . ($rows['reason'] ?? '?'));

            return ['ok' => false, 'error' => "Você não tem acesso aos registros de {$name}."];
        }

        // View concedida com Multi-unidade/pool: sem a coluna de escopo não há
        // como filtrar por dentro dela.
        if ($info['view']) {
            if ((DataScope::unitScopeActive() && ! in_array('unit_id', $real, true))
                || (DataScope::tenantScopeActive() && ! in_array('tenant_id', $real, true))) {
                return ['ok' => false, 'error' => "A view {$name} não traz a coluna de unidade/empresa e não pode ser filtrada para o seu acesso."];
            }
        }

        $where = [];
        foreach ($rows['filters'] ?? [] as [$col, $op, $val]) {
            $col = strtolower((string) $col);
            if (! in_array($col, $real, true)) {
                // soft delete declarado numa coluna que a tabela não tem: sem filtro
                if ($op === 'is null') {
                    continue;
                }

                return ['ok' => false, 'error' => "Não foi possível aplicar o seu escopo em {$name}."];
            }
            $w = $grammar->wrap($col);
            if ($op === 'is null') {
                $where[] = "{$w} IS NULL";
                if (! isset($seen[$col])) {
                    // o modelo escreve "deleted_at IS NULL": a coluna existe na CTE
                    $select[] = "NULL AS {$w}";
                    $seen[$col] = true;
                }
                continue;
            }
            if ($op !== 'in') {
                return ['ok' => false, 'error' => "Não foi possível aplicar o seu escopo em {$name}."];
            }
            $ids = [];
            foreach ((array) $val as $v) {
                if (! is_int($v) && ! (is_string($v) && preg_match('/^-?\d+$/', $v))) {
                    return ['ok' => false, 'error' => "Não foi possível aplicar o seu escopo em {$name}."];
                }
                $ids[] = (int) $v;
            }
            $where[] = $ids === [] ? '1=0' : "{$w} IN (" . implode(', ', $ids) . ')';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM ' . $qualified
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '');

        return ['ok' => true, 'sql' => $sql];
    }

    /**
     * Relações citadas logo depois de FROM/JOIN (último segmento, sem aspas,
     * minúsculas). Só para a mensagem — a garantia é a CTE vazia.
     *
     * @return list<string>
     */
    public static function fromJoinTargets(string $sql): array
    {
        $id = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|\'[^\']+\'|[A-Za-z_][A-Za-z0-9_$]*)';
        $re = '/\b(?:from|join)\s+(?:(?:only|lateral)\s+)?(' . $id . '(?:\s*\.\s*' . $id . ')*)/i';
        if (! preg_match_all($re, $sql, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $ref) {
            $parts = preg_split('/\s*\.\s*/', $ref) ?: [$ref];
            $last  = strtolower(trim((string) end($parts), " \t\n\r`\"'[]"));
            if ($last !== '') {
                $out[$last] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Primeiro `<esquema/banco>.<nome>` que a SQL escreve, ou null. Qualificar
     * pula a CTE de mesmo nome (e, no MySQL, alcança outro banco do servidor).
     */
    private static function qualifiedReference(string $db, string $sql): ?string
    {
        foreach (self::schemas($db) as $schema) {
            $re = '/(?<![\w$])[`"\[\']?' . preg_quote($schema, '/') . '[`"\]\']?\s*\.\s*([`"\[\']?[A-Za-z_][\w$]*)/i';
            if (preg_match($re, $sql, $m)) {
                return $schema . '.' . trim($m[1], "`\"'[]");
            }
        }

        return null;
    }

    /** @return list<string> esquemas/bancos visíveis na conexão (+ temp do SQLite) */
    private static function schemas(string $db): array
    {
        return DataScope::memo('widget_sql.schemas', $db, static function () use ($db): array {
            $out = [];
            try {
                $conn = DB::connection($db);
                foreach ($conn->getSchemaBuilder()->getSchemas() as $s) {
                    $out[strtolower((string) ($s['name'] ?? ''))] = true;
                }
                if ($conn->getDriverName() === 'sqlite') {
                    $out['main'] = true;
                    $out['temp'] = true;
                }
            } catch (\Throwable $e) {
                error_log('[WidgetSqlScope] esquemas de ' . $db . ' ilegíveis: ' . $e->getMessage());
            }
            unset($out['']);

            return array_keys($out);
        });
    }

    /**
     * Tabelas e views da conexão, por nome em minúsculas (memo por request).
     *
     * @return array<string, array{name: string, schema: string, view: bool}>|null
     */
    private static function relations(string $db): ?array
    {
        return DataScope::memo('widget_sql.scope_relations', $db, static function () use ($db): ?array {
            try {
                $schema = DB::connection($db)->getSchemaBuilder();
                $out    = [];
                foreach ($schema->getTables() as $t) {
                    $out[strtolower((string) $t['name'])] = ['name' => (string) $t['name'], 'schema' => (string) ($t['schema'] ?? ''), 'view' => false];
                }
                foreach ($schema->getViews() as $v) {
                    $out[strtolower((string) $v['name'])] = ['name' => (string) $v['name'], 'schema' => (string) ($v['schema'] ?? ''), 'view' => true];
                }

                return $out;
            } catch (\Throwable $e) {
                error_log('[WidgetSqlScope] introspecção de ' . $db . ' falhou: ' . $e->getMessage());

                return null;
            }
        });
    }

    /** @return list<string>|null colunas reais (minúsculas) — null = não deu pra ler */
    private static function columnsOf(string $db, string $table): ?array
    {
        return DataScope::memo('widget_sql.columns', $db . '|' . $table, static function () use ($db, $table): ?array {
            try {
                $cols = array_map('strtolower', DB::connection($db)->getSchemaBuilder()->getColumnListing($table));

                return $cols !== [] ? $cols : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }
}
