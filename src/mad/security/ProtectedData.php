<?php

namespace Mad\Security;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mad\Database\DataScope;

/**
 * ProtectedData — o que a IA do app (Copilot IA, Meus Dashboards, motor MCP)
 * NUNCA lê nem escreve, não importa o que o manifest, o prompt ou a SQL do
 * modelo peçam: tabelas com credencial, tabelas privadas por pessoa (conversas
 * do Copilot, chat interno — {@see PRIVATE_PREFIXES}), catálogos do banco,
 * funções que leem arquivo/configuração ou executam SQL montada em texto, e
 * colunas de senha/token. É a barreira ABSOLUTA por cima das concessões do
 * manifest MCP ({@see \Mad\Mcp\McpAccess}): roda antes delas.
 *
 * Até o 5.96.19 o guard da SQL do modelo (WidgetSqlGuard) só barrava escrita e
 * DDL: "me mostra o hash de senha dos usuários" virava
 * `SELECT password FROM mad_iam_user` e o hash saía na conversa — o mesmo para
 * os tokens do Copilot/API, as sessões (que guardam o token do Copilot), os
 * tokens de redefinição de senha e as chaves dos provedores de pagamento.
 *
 * Três pontos usam esta lista (o mesmo critério nos três):
 *  - {@see sqlViolation()} — SQL crua do modelo (preview/save_widget, query_db
 *    e a re-execução dos widgets salvos), via WidgetSqlGuard::run();
 *  - {@see assertTable()} / {@see assertColumn()} / {@see stripSecretColumns()} —
 *    o McpTableGateway das tools do MCP (um manifest que exponha a tabela de
 *    usuários não abre as credenciais);
 *  - {@see isProtectedTable()} / {@see isSecretColumn()} — o db_schema não
 *    anuncia nada disto ao modelo.
 *
 * Por que TABELA INTEIRA e não só as colunas: SQL crua não se deixa restringir
 * por coluna com segurança — `SELECT *`, `u.*`, UNION sem citar a coluna,
 * subquery/EXISTS como oráculo, funções que viram a linha em texto. O nome da
 * tabela, esse, tem que aparecer no texto. O Copilot responde perguntas do
 * NEGÓCIO; quem precisar expor dados de usuários ao assistente faz isso por
 * uma tool do MCP com os campos escolhidos, que passa pelo masker de PII.
 *
 * Scan CONSERVADOR (mesmo desenho do tenancyViolation do 5.96.6): o nome vale
 * em qualquer lugar do texto — código, "aspas", `crase`, [colchetes], 'literal'
 * (o SQLite aceita 'tabela' como identificador) e comentário (o MySQL executa
 * comentário /*! … *\/).
 */
final class ProtectedData
{
    /**
     * Tabelas com credencial ou segredo (conferidas no schema do framework:
     * create_mad_schema + migrations) → o que elas guardam, para o log.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        // IAM / acesso
        'mad_iam_user'           => 'senha (hash) e segredo do 2FA dos usuários',
        'mad_mcp_token'          => 'tokens do Copilot IA, da Central de Comando e do MCP',
        'mad_api_token'          => 'tokens da API REST',
        'mad_iam_tenant'         => 'credenciais do banco de cada empresa (db_config)',
        'mad_ged_shared_link'    => 'token e senha dos links públicos de documentos',
        // cobrança do SaaS
        'mad_iam_payment_method' => 'token do cartão no provedor de pagamento',
        'mad_iam_billing_event'  => 'eventos brutos dos webhooks do provedor de pagamento',
        'mad_sys_preference'     => 'chaves dos provedores de pagamento (billing_*_secret) e preferências do sistema',
        // logs que carregam segredo
        'mad_log_request'        => 'cabeçalhos (cookie, Authorization) e corpo das requisições, inclusive o login',
        'mad_log_sql'            => 'texto das instruções SQL executadas',
        // Laravel (nomes convencionais — o app pode tê-las por pacote)
        'users'                  => 'senha dos usuários (tabela padrão do Laravel)',
        'password_reset_tokens'  => 'tokens de redefinição de senha',
        'password_resets'        => 'tokens de redefinição de senha (nome antigo do Laravel)',
        'personal_access_tokens' => 'tokens de acesso (Sanctum)',
        'sessions'               => 'sessões dos usuários (guardam o token do Copilot e o CSRF)',
        'cache'                  => 'cache do servidor',
        'cache_locks'            => 'travas do cache do servidor',
        'jobs'                   => 'fila (e-mails com link de convite e de redefinição de senha)',
        'job_batches'            => 'lotes da fila',
        'failed_jobs'            => 'fila com falha (mesmo conteúdo da fila)',
    ];

    /**
     * Famílias de tabelas PRIVADAS POR PESSOA (prefixo → o que guardam). Não
     * têm credencial, mas o conteúdo é de cada usuário: as conversas, os
     * favoritos e os widgets do Copilot IA e o chat interno. Nenhum manifesto
     * as abre para a IA — o próprio Copilot lê o que é do usuário pelos stores
     * dele (ConversationStore, WidgetStore…), nunca pela SQL do modelo.
     *
     * @var array<string, string>
     */
    public const PRIVATE_PREFIXES = [
        'mad_ai_'   => 'conversas, favoritos e widgets do Copilot IA de cada usuário',
        'mad_comm_' => 'mensagens do chat interno entre usuários',
    ];

    /**
     * Catálogos e visões de sistema: hash de senha dos papéis do banco
     * (pg_authid/pg_shadow, mysql.user), SQL de outras sessões
     * (pg_stat_activity, performance_schema), DDL e configuração do servidor.
     * O db_schema já dá ao modelo as tabelas e colunas do negócio.
     */
    public const CATALOGS = [
        'information_schema', 'performance_schema', 'pg_catalog', 'pg_toast',
        'sqlite_master', 'sqlite_schema', 'sqlite_temp_master', 'sqlite_temp_schema', 'sqlite_dbpage', 'dbstat',
        'pg_authid', 'pg_shadow', 'pg_user', 'pg_roles', 'pg_user_mapping', 'pg_user_mappings',
        'pg_stat_activity', 'pg_stat_statements', 'pg_settings', 'pg_file_settings',
        'pg_hba_file_rules', 'pg_ident_file_mappings', 'pg_largeobject',
    ];

    /**
     * Esquemas de sistema do MySQL que só valem QUALIFICADOS (`mysql.user`) —
     * como palavra solta "mysql" aparece até em comentário de SQL.
     */
    private const QUALIFIED_CATALOGS = ['mysql'];

    /**
     * Funções que leem arquivo/configuração do servidor ou EXECUTAM SQL recebida
     * como TEXTO (o nome da tabela pode ser montado por concatenação e escapar
     * do scan de nomes). Mais: qualquer nome com `to_xml` (query_to_xml,
     * table_to_xml, cursor_to_xml…) e `pragma_*` (SQLite).
     */
    public const FUNCTIONS = [
        'ts_stat', 'ts_rewrite',
        'pg_read_file', 'pg_read_binary_file', 'pg_ls_dir', 'pg_stat_file', 'pg_ls_logdir',
        'pg_ls_waldir', 'pg_ls_tmpdir', 'pg_ls_archive_statusdir', 'pg_relation_filepath',
        'lo_import', 'lo_export', 'lo_get', 'lo_open', 'loread',
        'current_setting', 'set_config', 'pg_reload_conf',
        'dblink', 'dblink_exec',
        'load_extension', 'readfile', 'writefile', 'fts3_tokenizer',
        'sys_eval', 'sys_exec', 'xp_cmdshell',
    ];

    /**
     * Colunas de credencial — em QUALQUER tabela (também nas do negócio). Nada
     * genérico demais: "senha" fica de fora de propósito (em fila de
     * atendimento, "senha" é o número da vez).
     */
    public const COLUMNS = [
        'password', 'password_hash', 'remember_token',
        'two_factor_secret', 'two_factor_recovery_codes',
        'token_hash', 'gateway_token', 'api_key', 'secret_key', 'client_secret',
        'access_token', 'refresh_token',
    ];

    /** Profundidade máxima de view dentro de view conferida. */
    private const MAX_VIEW_DEPTH = 8;

    public static function isProtectedTable(string $name): bool
    {
        $name = strtolower(trim($name));

        return isset(self::TABLES[$name]) || in_array($name, self::CATALOGS, true)
            || self::privatePrefix($name) !== null;
    }

    /** Prefixo privado que a tabela usa (`mad_ai_`, `mad_comm_`) ou null. */
    public static function privatePrefix(string $name): ?string
    {
        $name = strtolower(trim($name));
        foreach (array_keys(self::PRIVATE_PREFIXES) as $prefix) {
            if (str_starts_with($name, $prefix) && strlen($name) > strlen($prefix)) {
                return $prefix;
            }
        }

        return null;
    }

    public static function isSecretColumn(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::COLUMNS, true);
    }

    /**
     * SQL crua escrita pelo modelo: null quando pode rodar; senão a recusa em
     * linguagem de gente para o modelo (o detalhe vai para o log).
     */
    public static function sqlViolation(string $db, string $sql, string $origin = 'ai_sql'): ?string
    {
        $hit = self::sqlHit($db, $sql);
        if ($hit === null) {
            return null;
        }

        self::logRefusal($origin, $hit, $sql);

        return $hit['message'];
    }

    /** Tool do MCP: lança {@see ProtectedDataException} para tabela protegida. */
    public static function assertTable(string $table, string $origin = 'mcp'): void
    {
        if (! self::isProtectedTable($table)) {
            return;
        }
        $hit = ['kind' => 'table', 'name' => strtolower($table), 'message' => self::tableMessage(strtolower($table))];
        self::logRefusal($origin, $hit, null);

        throw new ProtectedDataException($hit['message']);
    }

    /** Tool do MCP: lança {@see ProtectedDataException} para coluna de credencial. */
    public static function assertColumn(string $column, string $origin = 'mcp'): void
    {
        if (! self::isSecretColumn($column)) {
            return;
        }
        $hit = ['kind' => 'column', 'name' => strtolower($column), 'message' => self::columnMessage(strtolower($column))];
        self::logRefusal($origin, $hit, null);

        throw new ProtectedDataException($hit['message']);
    }

    /**
     * Tira das linhas as colunas de credencial (o `SELECT *` do gateway numa
     * tabela do negócio que tem, p.ex., `password`).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function stripSecretColumns(array $rows): array
    {
        foreach ($rows as $i => $row) {
            foreach (array_keys($row) as $col) {
                if (self::isSecretColumn((string) $col)) {
                    unset($rows[$i][$col]);
                }
            }
        }

        return $rows;
    }

    // ── mensagens (vão para o MODELO: curtas, sem detalhe técnico) ──────────

    private static function tableMessage(string $table, ?string $view = null): string
    {
        $what = $view !== null ? "a view \"{$view}\" lê \"{$table}\", que guarda" : "\"{$table}\" guarda";

        $kind = self::privatePrefix($table) !== null
            ? 'dados particulares de cada usuário'
            : 'senhas, tokens ou dados internos do sistema';

        return "Tabela protegida: {$what} {$kind}, e o assistente não pode consultá-la."
            . ' Use só as tabelas do negócio listadas pelo db_schema.';
    }

    private static function columnMessage(string $column): string
    {
        return "Coluna protegida: \"{$column}\" guarda credenciais, e o assistente não pode lê-la nem filtrar por ela.";
    }

    // ── detecção na SQL crua ───────────────────────────────────────────────

    /** @return array{kind: string, name: string, message: string, via?: string}|null */
    private static function sqlHit(string $db, string $sql): ?array
    {
        // Postgres: U&"…" escreve o identificador com escape (\005f = _).
        if (preg_match('/\bU&\s*["\']/i', $sql)) {
            return ['kind' => 'identifier', 'name' => 'U&',
                'message' => 'Identificador protegido: nome escrito com escape unicode (U&) não é permitido. Escreva o nome da tabela normalmente.'];
        }

        foreach (self::QUALIFIED_CATALOGS as $schema) {
            if (preg_match('/(?<![\w$])[`"\[]?' . preg_quote($schema, '/') . '[`"\]]?\s*\./i', $sql)) {
                return ['kind' => 'catalog', 'name' => $schema, 'message' => self::catalogMessage($schema)];
            }
        }

        $tokens = self::mentionedNames($sql);
        $hit = self::tokensHit($tokens);
        if ($hit !== null) {
            return $hit;
        }

        return self::viewsHit($db, $tokens);
    }

    /**
     * @param  list<string>  $tokens
     * @return array{kind: string, name: string, message: string}|null
     */
    private static function tokensHit(array $tokens, ?string $view = null): ?array
    {
        // Tabela/catálogo/função antes de coluna: "Tabela protegida: mad_iam_user"
        // diz mais ao modelo (e ao log) do que "coluna password".
        foreach ($tokens as $tok) {
            if (isset(self::TABLES[$tok]) || self::privatePrefix($tok) !== null) {
                return ['kind' => 'table', 'name' => $tok, 'message' => self::tableMessage($tok, $view)];
            }
            if (in_array($tok, self::CATALOGS, true)) {
                return ['kind' => 'catalog', 'name' => $tok, 'message' => self::catalogMessage($tok, $view)];
            }
            if (in_array($tok, self::FUNCTIONS, true) || str_contains($tok, 'to_xml') || str_starts_with($tok, 'pragma_')) {
                return ['kind' => 'function', 'name' => $tok,
                    'message' => "Função protegida: \"{$tok}\" executa SQL montada em texto ou lê arquivos/configuração do servidor, e não é permitida."];
            }
        }
        foreach ($tokens as $tok) {
            if (in_array($tok, self::COLUMNS, true)) {
                return ['kind' => 'column', 'name' => $tok, 'message' => self::columnMessage($tok)];
            }
        }

        return null;
    }

    private static function catalogMessage(string $name, ?string $view = null): string
    {
        $what = $view !== null ? "a view \"{$view}\" lê o catálogo \"{$name}\"" : "o catálogo do banco \"{$name}\"";

        return "Catálogo protegido: {$what} não pode ser consultado pelo assistente. Para ver tabelas e colunas, use o db_schema.";
    }

    /**
     * View pode esconder o nome da tabela protegida: confere a DEFINIÇÃO de
     * toda view citada (e das views dentro dela). Definição ilegível (sem
     * permissão para ver o código da view) = recusa — não dá para provar que
     * ela não lê credencial.
     *
     * @param  list<string>  $tokens
     * @return array{kind: string, name: string, message: string, via?: string}|null
     */
    private static function viewsHit(string $db, array $tokens): ?array
    {
        $views = self::viewDefinitions($db);
        if ($views === null) {
            return ['kind' => 'introspection', 'name' => $db,
                'message' => 'Consulta protegida: não foi possível conferir as views do banco contra as tabelas protegidas. Tente consultar direto as tabelas do negócio.'];
        }

        $seen = [];
        $queue = [];
        foreach ($tokens as $tok) {
            if (isset($views[$tok])) {
                $queue[] = [$tok, $tok, 0];
            }
        }

        while ($queue !== []) {
            [$name, $root, $depth] = array_shift($queue);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;

            $definition = trim((string) $views[$name]);
            if ($definition === '' || $depth > self::MAX_VIEW_DEPTH) {
                return ['kind' => 'view', 'name' => $name, 'via' => $root,
                    'message' => "Consulta protegida: não foi possível conferir o que a view \"{$root}\" lê. Consulte direto as tabelas do negócio."];
            }

            $inner = self::mentionedNames($definition);
            $hit = self::tokensHit($inner, $root);
            if ($hit !== null) {
                return $hit + ['via' => $root];
            }
            foreach ($inner as $tok) {
                if (isset($views[$tok]) && ! isset($seen[$tok])) {
                    $queue[] = [$tok, $root, $depth + 1];
                }
            }
        }

        return null;
    }

    /**
     * Views (e, no Postgres, materialized views) da conexão → definição, por
     * request. null = não deu para ler.
     *
     * @return array<string, string>|null
     */
    public static function viewDefinitions(string $db): ?array
    {
        return DataScope::memo('protected_data.views', $db, static function () use ($db): ?array {
            try {
                $conn = DB::connection($db);
                $out = [];
                foreach ($conn->getSchemaBuilder()->getViews() as $v) {
                    $out[strtolower((string) $v['name'])] = (string) ($v['definition'] ?? '');
                }
                if ($conn->getDriverName() === 'pgsql') {
                    foreach ($conn->select('select matviewname as name, definition from pg_matviews') as $mv) {
                        $out[strtolower((string) $mv->name)] = (string) ($mv->definition ?? '');
                    }
                }

                return $out;
            } catch (\Throwable $e) {
                error_log('[ProtectedData] views de ' . $db . ' ilegíveis: ' . $e->getMessage());

                return null;
            }
        });
    }

    /**
     * Todo nome que a SQL menciona, em minúsculas: palavras soltas e o conteúdo
     * inteiro de "…", `…`, […] e '…'. (Mesmo desenho do WidgetSqlGuard.)
     * Público: o {@see \Mad\Ai\WidgetSqlScope} usa o MESMO extrator para achar
     * as relações que a SQL cita.
     *
     * @return list<string>
     */
    public static function mentionedNames(string $sql): array
    {
        $names = [];
        if (preg_match_all('/[A-Za-z_][A-Za-z0-9_$]*/', $sql, $m)) {
            foreach ($m[0] as $t) {
                $names[strtolower($t)] = true;
            }
        }
        if (preg_match_all('/"([^"]+)"|`([^`]+)`|\[([^\]]+)\]|\'([^\']+)\'/', $sql, $m, PREG_SET_ORDER)) {
            foreach ($m as $set) {
                $names[strtolower(trim((string) end($set)))] = true;
            }
        }
        unset($names['']);

        return array_map('strval', array_keys($names));
    }

    /** @param array{kind: string, name: string, message: string, via?: string} $hit */
    private static function logRefusal(string $origin, array $hit, ?string $sql): void
    {
        $context = [
            'origin' => $origin,
            'kind'   => $hit['kind'],
            'name'   => $hit['name'],
            'via'    => $hit['via'] ?? null,
            'what'   => self::TABLES[$hit['name']] ?? (($p = self::privatePrefix($hit['name'])) !== null ? self::PRIVATE_PREFIXES[$p] : null),
            'user'   => self::currentUserId(),
            'sql'    => $sql !== null ? mb_substr($sql, 0, 2000) : null,
        ];

        try {
            Log::warning('[mad-ai] acesso a dado protegido recusado', $context);
        } catch (\Throwable) {
            error_log('[mad-ai] acesso a dado protegido recusado ' . json_encode($context, JSON_UNESCAPED_UNICODE));
        }
    }

    private static function currentUserId(): ?string
    {
        try {
            $id = function_exists('session') ? session('userid') : null;

            return $id !== null ? (string) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
