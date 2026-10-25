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
 * `outrobanco.x`): o de uma relação conhecida é trocado pelo nome da CTE dela
 * antes de tudo (`bi.grupo` → `bi__grupo`, `public.grupo` → `grupo`), e o que
 * sobrar qualificado depois disso é recusado.
 *
 * Tabela em outro schema (PostgreSQL): o modelo escreve `bi.grupo`, como o
 * db_schema lista, ou o nome puro. O nome puro é o do schema padrão (o
 * search_path, como no Postgres); sem ele, vale a única tabela com esse nome;
 * em mais de um schema, sem a do padrão, a consulta é recusada pedindo o nome
 * com o schema. A permissão e as colunas são as da chave do manifest
 * (`bi.grupo`; `grupo` no schema padrão).
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
     * @return array{ok: bool, error?: string, with?: string, sql?: string}
     *         with = prefixo "WITH … " (vazio quando a SQL não cita relação)
     *         sql  = a SQL do modelo a executar, com `schema.nome` trocado
     *                pelo nome da CTE
     */
    public static function plan(string $db, string $sql, ?McpAccess $access = null): array
    {
        $access ??= McpAccess::current();

        $rel = self::relations($db);
        if ($rel === null) {
            return ['ok' => false, 'error' => 'Não foi possível conferir as tabelas da consulta contra as permissões do seu perfil.'];
        }

        // `bi.grupo` / `public.grupo` → nome da CTE da relação; o que continuar
        // qualificado não é relação conhecida e pularia a CTE.
        $grammar = DB::connection($db)->getQueryGrammar();
        $sql     = self::unqualify($sql, $rel, static fn (string $alias): string => $grammar->wrap($alias));
        if (($q = self::qualifiedReference($db, $sql)) !== null) {
            return ['ok' => false, 'error' => "A consulta cita \"{$q}\", que não é uma tabela que o assistente possa ler. Escreva o nome da tabela como o db_schema mostra."];
        }

        $mentioned = [];
        $ambiguous = [];
        // Diretório de usuários (id, nome): relação virtual, só quando o banco
        // não tem uma tabela de verdade com esse nome — ver UserDirectory.
        $users = false;
        foreach (ProtectedData::mentionedNames($sql) as $tok) {
            if (isset($rel['alias'][$tok])) {
                $mentioned[$tok] = $rel['alias'][$tok];
            } elseif (isset($rel['ambiguous'][$tok])) {
                $ambiguous[$tok] = $rel['ambiguous'][$tok];
            } elseif ($tok === UserDirectory::TABLE) {
                $users = true;
            }
        }

        foreach (self::fromJoinTargets($sql) as $target) {
            if (isset($rel['alias'][$target]) && ! $access->canRead($rel['alias'][$target]['key'])) {
                return ['ok' => false, 'error' => self::deniedMessage($rel['alias'][$target]['key'])];
            }
            if (isset($rel['ambiguous'][$target])) {
                return ['ok' => false, 'error' => self::ambiguousMessage($rel['ambiguous'][$target])];
            }
        }

        if ($mentioned === [] && $ambiguous === [] && ! $users) {
            return ['ok' => true, 'with' => '', 'sql' => $sql];
        }

        $ctes = $users ? [UserDirectory::cte($db)] : [];
        foreach ($mentioned as $info) {
            $qualified = $grammar->wrap(self::reference($info));
            $alias     = $grammar->wrap($info['alias']);

            if (! $access->canRead($info['key'])) {
                $ctes[] = "{$alias} AS (SELECT * FROM {$qualified} WHERE 1=0)";
                continue;
            }

            $body = self::grantedBody($db, $access, $info, $qualified);
            if (! $body['ok']) {
                return ['ok' => false, 'error' => $body['error']];
            }
            $ctes[] = "{$alias} AS ({$body['sql']})";
        }
        // Nome puro de mais de um schema citado fora de FROM/JOIN: CTE vazia
        // (o SQLite resolveria o nome puro num banco anexado).
        foreach ($ambiguous as $candidates) {
            $ctes[] = $grammar->wrap($candidates[0]['name']) . ' AS (SELECT * FROM '
                . $grammar->wrap(self::reference($candidates[0])) . ' WHERE 1=0)';
        }

        return ['ok' => true, 'with' => 'WITH ' . implode(', ', $ctes) . ' ', 'sql' => $sql];
    }

    /**
     * Nome puro que existe em mais de um schema, nenhum deles o padrão.
     *
     * @param list<array{name: string, schema: string}> $candidates
     */
    private static function ambiguousMessage(array $candidates): string
    {
        $name    = $candidates[0]['name'];
        $schemas = array_map(static fn (array $c): string => $c['schema'], $candidates);

        return "A tabela {$name} existe em mais de um schema (" . implode(', ', $schemas) . '): escreva o nome com o schema, como '
            . $schemas[0] . '.' . $name . '.';
    }

    /** `schema.nome` da relação (só o nome quando o banco não informa schema). */
    private static function reference(array $info): string
    {
        return ($info['schema'] !== '' ? $info['schema'] . '.' : '') . $info['name'];
    }

    /**
     * Troca `schema.nome` de relação conhecida (com ou sem "aspas", `crase`,
     * [colchetes]) pelo nome da CTE dela. Literal entre aspas simples fica
     * intacto: um `'bi.grupo'` dentro dele continua qualificado e é recusado.
     *
     * @param array{alias: array<string, array<string, mixed>>, qualified: array<string, string>} $rel
     * @param \Closure(string): string $wrap aspas do banco no nome da CTE
     */
    private static function unqualify(string $sql, array $rel, \Closure $wrap): string
    {
        if ($rel['qualified'] === []) {
            return $sql;
        }

        $seg   = '(?:"([A-Za-z_][\w$]*)"|`([A-Za-z_][\w$]*)`|\[([A-Za-z_][\w$]*)\]|([A-Za-z_][\w$]*))';
        $re    = '/(?<![\w$."`\]])' . $seg . '\s*\.\s*' . $seg . '/';
        $parts = preg_split("/('(?:[^'\\\\]|\\\\.|'')*')/", $sql, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$sql];
        foreach ($parts as $i => $part) {
            if ($i % 2 === 1) {
                continue; // literal
            }
            $parts[$i] = (string) preg_replace_callback($re, static function (array $m) use ($rel, $wrap): string {
                $schema = ($m[1] ?? '') . ($m[2] ?? '') . ($m[3] ?? '') . ($m[4] ?? '');
                $name   = ($m[5] ?? '') . ($m[6] ?? '') . ($m[7] ?? '') . ($m[8] ?? '');
                $alias  = $rel['qualified'][strtolower($schema . '.' . $name)] ?? null;

                return $alias !== null ? $wrap($alias) : $m[0];
            }, $part);
        }

        return implode('', $parts);
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
     * @param  array{name: string, schema: string, view: bool, key: string, alias: string} $info
     * @return array{ok: bool, sql?: string, error?: string}
     */
    private static function grantedBody(string $db, McpAccess $access, array $info, string $qualified): array
    {
        $name    = $info['key'];
        $grammar = DB::connection($db)->getQueryGrammar();
        $real    = self::columnsOf($db, self::reference($info));
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
     * Tabelas e views da conexão (memo por request):
     *   - alias:     nome da CTE em minúsculas → relação. O nome puro fica com a
     *                do schema padrão (ou com a única que tem esse nome); as
     *                outras ganham `schema__nome`.
     *   - qualified: `schema.nome` em minúsculas → nome da CTE (o que o
     *                {@see unqualify()} escreve no lugar).
     *   - ambiguous: nome puro sem dono → as relações dos schemas que o têm.
     * `key` é a chave da relação no manifest/permissão: `bi.grupo`, ou só
     * `grupo` no schema padrão.
     *
     * @return array{alias: array<string, array{name: string, schema: string, view: bool, key: string, alias: string}>, qualified: array<string, string>, ambiguous: array<string, list<array{name: string, schema: string, view: bool}>>}|null
     */
    private static function relations(string $db): ?array
    {
        return DataScope::memo('widget_sql.scope_relations', $db, static function () use ($db): ?array {
            try {
                $conn   = DB::connection($db);
                $schema = $conn->getSchemaBuilder();
                $all    = [];
                foreach ($schema->getTables() as $t) {
                    $all[] = ['name' => (string) $t['name'], 'schema' => (string) ($t['schema'] ?? ''), 'view' => false];
                }
                foreach ($schema->getViews() as $v) {
                    $all[] = ['name' => (string) $v['name'], 'schema' => (string) ($v['schema'] ?? ''), 'view' => true];
                }

                $defaults = array_map('strtolower', (array) ($schema->getCurrentSchemaListing() ?? []));
                if ($conn->getDriverName() === 'pgsql' && ! in_array('public', $defaults, true)) {
                    $defaults[] = 'public';
                }

                return self::indexRelations($all, $defaults);
            } catch (\Throwable $e) {
                error_log('[WidgetSqlScope] introspecção de ' . $db . ' falhou: ' . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * @param list<array{name: string, schema: string, view: bool}> $all
     * @param list<string> $defaults schemas do search_path, em ordem (minúsculas)
     */
    private static function indexRelations(array $all, array $defaults): array
    {
        $byName = [];
        foreach ($all as $r) {
            $byName[strtolower($r['name'])][] = $r;
        }

        $out = ['alias' => [], 'qualified' => [], 'ambiguous' => []];
        $own = []; // relações que ficam com o nome puro
        foreach ($byName as $lname => $list) {
            $rank = static function (array $r) use ($defaults): int {
                $i = array_search(strtolower($r['schema']), $defaults, true);

                return $r['schema'] === '' ? -1 : ($i === false ? PHP_INT_MAX : (int) $i);
            };
            usort($list, static fn (array $a, array $b): int => $rank($a) <=> $rank($b));
            if (count($list) === 1 || $rank($list[0]) !== PHP_INT_MAX) {
                $own[$lname] = $list[0];
            } else {
                $out['ambiguous'][$lname] = $list;
            }
        }

        foreach ($all as $r) {
            $lname   = strtolower($r['name']);
            $mine    = $own[$lname] ?? null;
            $isOwn   = $mine !== null && $mine['schema'] === $r['schema'] && $mine['view'] === $r['view'];
            $default = $r['schema'] === '' || in_array(strtolower($r['schema']), $defaults, true);
            // a chave do manifest: só o nome no schema padrão (como a plataforma publica)
            $r['key'] = $isOwn && $default ? $r['name'] : $r['schema'] . '.' . $r['name'];
            if ($isOwn) {
                $r['alias'] = $r['name'];
            } else {
                $r['alias'] = $r['schema'] . '__' . $r['name'];
                // `schema__nome` que já é o nome de outra relação: sem apelido —
                // citada com o schema, continua qualificada e é recusada.
                if (isset($byName[strtolower($r['alias'])])) {
                    continue;
                }
            }
            $out['alias'][strtolower($r['alias'])] = $r;
            if ($r['schema'] !== '') {
                $out['qualified'][strtolower($r['schema'] . '.' . $r['name'])] = $r['alias'];
            }
        }

        return $out;
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
