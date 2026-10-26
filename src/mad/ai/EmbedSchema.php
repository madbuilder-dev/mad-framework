<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;

/**
 * EmbedSchema — schema das tabelas do módulo embed/IA, self-contained
 * (CREATE TABLE IF NOT EXISTS), no padrão do repo (McpTokenService /
 * SystemAccessLogService) — o migrator legado foi removido e as conexões MAD
 * são nativas (config/database.php), fora do `php artisan migrate`.
 *
 * Estado do chat (ensure($db), na conexão do {@see ChatStorage}):
 *   mad_ai_conversation    histórico/pendências por conversa (ConversationStore)
 *   mad_ai_favorite        favoritos do chat (EmbedFavoriteController)
 *   mad_ai_widget          widgets de BI salvos pelo agente (WidgetStore)
 *   mad_ai_dashboard       dashboards do usuário (DashboardStore)
 *   mad_ai_dashboard_share compartilhamento user/grupo de dashboard
 *   mad_ai_tool_audit      auditoria das system tools do Command Center
 *   mad_ai_legacy_import   cópia única do que ficou no banco de negócio (ChatLegacyImport)
 *
 * Medição (ensure('log', ['mad_ai_token_usage']), só o UsageLog):
 *   mad_ai_token_usage     consumo por turno
 *
 * DDL portável (sqlite/mysql/pgsql). A garantia é POR TABELA: uma que falha
 * vai para o log com o nome e o motivo, e as outras seguem. Antes era um bloco
 * só: no PostgreSQL o `DATETIME` do token_usage derrubava o resto, nada era
 * marcado como pronto e todo store relançava o erro — o chat não gravava nem
 * a pendência da confirmação (fw#564).
 *
 * Idempotente, 1x por processo por banco físico (o tenant repointa a conexão).
 */
final class EmbedSchema
{
    /** Tabelas do estado do chat — o que ensure($db) garante por padrão. */
    public const CHAT_TABLES = [
        'mad_ai_conversation',
        'mad_ai_favorite',
        'mad_ai_widget',
        'mad_ai_dashboard',
        'mad_ai_dashboard_share',
        'mad_ai_tool_audit',
        'mad_ai_legacy_import',
    ];

    /** Tabela que falhou só é tentada de novo depois disto (sem repetir o log a cada store). */
    private const RETRY_SECONDS = 60;

    /** @var array<string, bool> "<banco físico>|<tabela>" já garantidas neste processo */
    private static array $ready = [];

    /** @var array<string, int> "<banco físico>|<tabela>" => quando falhou */
    private static array $failedAt = [];

    /**
     * @param list<string>|null $tables null = as do chat ({@see CHAT_TABLES})
     */
    public static function ensure(string $db, ?array $tables = null): void
    {
        $tables ??= self::CHAT_TABLES;
        $key    = self::fingerprint($db);

        $now  = time();
        $todo = array_values(array_filter($tables, static fn (string $t): bool => ! (self::$ready[$key . '|' . $t] ?? false)
            && $now - (self::$failedAt[$key . '|' . $t] ?? 0) >= self::RETRY_SECONDS));
        if ($todo === []) {
            return;
        }

        $conn   = DB::connection($db);
        $pdo    = $conn->getPdo();
        $driver = $conn->getDriverName();

        foreach ($todo as $table) {
            try {
                $pdo->exec(self::create($table, $driver));
                foreach (self::lateColumns($table) as $column => $type) {
                    self::addColumnIfMissing($pdo, $table, $column, $type);
                }
                self::$ready[$key . '|' . $table] = true;
                unset(self::$failedAt[$key . '|' . $table]);
            } catch (\Throwable $e) {
                self::$failedAt[$key . '|' . $table] = $now;
                error_log('[EmbedSchema] tabela ' . $table . ' na conexão ' . $db . ' (' . $driver . ')'
                    . ' não pôde ser criada: ' . $e->getMessage());
            }
        }
    }

    /** CREATE TABLE IF NOT EXISTS da tabela, no dialeto do driver. */
    private static function create(string $table, string $driver): string
    {
        return match ($table) {
            'mad_ai_conversation' => 'CREATE TABLE IF NOT EXISTS mad_ai_conversation ('
                . ' id VARCHAR(64) PRIMARY KEY,'
                . ' user_id INTEGER,'
                . ' title VARCHAR(190),'
                . ' messages_json TEXT,'
                . ' pending_json TEXT,'
                . ' dashboards_json TEXT,'
                . ' transcript_json TEXT,'
                . ' created_at VARCHAR(20),'
                . ' updated_at VARCHAR(20),'
                . ' tenant_id INTEGER'
                . ')',

            // Shape da migration create_mad_schema (conexão `ai`), o que o
            // EmbedFavoriteController grava.
            'mad_ai_favorite' => 'CREATE TABLE IF NOT EXISTS mad_ai_favorite ('
                . ' id VARCHAR(64) PRIMARY KEY,'
                . ' user_id INTEGER,'
                . ' title VARCHAR(190),'
                . ' note TEXT,'
                . ' block_type VARCHAR(24),'
                . ' snapshot_json TEXT,'
                . ' source_json TEXT,'
                . ' spec_json TEXT,'
                . ' spec_status VARCHAR(12),'
                . ' created_at VARCHAR(20),'
                . ' updated_at VARCHAR(20),'
                . ' tenant_id INTEGER'
                . ')',

            // MESMO shape da tabela da tela "Consumo de IA" (migration, conexão
            // `log`) — IF NOT EXISTS cobre instalação limpa. `datetime` da
            // migration = TIMESTAMP no PostgreSQL (DATETIME não existe lá).
            'mad_ai_token_usage' => 'CREATE TABLE IF NOT EXISTS mad_ai_token_usage ('
                . ' id INTEGER PRIMARY KEY,'
                . ' user_id INTEGER,'
                . ' token_id INTEGER,'
                . ' token_prefix VARCHAR(40),'
                . ' app_slug VARCHAR(80),'
                . ' unit_id INTEGER,'
                . ' context VARCHAR(40),'
                . ' provider VARCHAR(40),'
                . ' model VARCHAR(120),'
                . " prompt_tokens INTEGER NOT NULL DEFAULT '0',"
                . " completion_tokens INTEGER NOT NULL DEFAULT '0',"
                . " cache_write_tokens INTEGER NOT NULL DEFAULT '0',"
                . " cache_read_tokens INTEGER NOT NULL DEFAULT '0',"
                . " reasoning_tokens INTEGER NOT NULL DEFAULT '0',"
                . " total_tokens INTEGER NOT NULL DEFAULT '0',"
                . ' request_id VARCHAR(64),'
                . ' status VARCHAR(20),'
                . ' metadata_json TEXT,'
                . ' created_at ' . ($driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME') . ','
                . ' cost_usd NUMERIC'
                . ')',

            // Micro-BI (widgets + dashboards do usuário final). spec_json guarda a
            // estrutura determinística do widget: {type, sql, map, style} — a
            // exibição re-executa a SQL (WidgetSqlGuard) e re-mapeia SEM IA.
            'mad_ai_widget' => 'CREATE TABLE IF NOT EXISTS mad_ai_widget ('
                . ' id VARCHAR(64) PRIMARY KEY,'
                . ' user_id INTEGER,'
                . ' title VARCHAR(190),'
                . ' spec_json TEXT,'
                . ' created_at VARCHAR(20),'
                . ' updated_at VARCHAR(20),'
                . ' tenant_id INTEGER'
                . ')',

            // layout_json = [{widgetId, x, y, w, h}] (grid 12 colunas do viewer).
            // filters_json = filtros globais do dashboard (DashboardStore::sanitizeFilters).
            'mad_ai_dashboard' => 'CREATE TABLE IF NOT EXISTS mad_ai_dashboard ('
                . ' id VARCHAR(64) PRIMARY KEY,'
                . ' user_id INTEGER,'
                . ' title VARCHAR(190),'
                . ' layout_json TEXT,'
                . ' filters_json TEXT,'
                . ' is_home INTEGER DEFAULT 0,'
                . ' created_at VARCHAR(20),'
                . ' updated_at VARCHAR(20),'
                . ' tenant_id INTEGER'
                . ')',

            // kind: user|group; ref_id = system_user.id | system_group.id.
            'mad_ai_dashboard_share' => 'CREATE TABLE IF NOT EXISTS mad_ai_dashboard_share ('
                . ' id INTEGER PRIMARY KEY,'
                . ' dashboard_id VARCHAR(64),'
                . ' kind VARCHAR(8),'
                . ' ref_id INTEGER,'
                . ' created_at VARCHAR(20)'
                . ')',

            // Auditoria das system tools do Command Center (ToolAuditStore). id
            // portável (MAX(id)+1, sem AUTOINCREMENT) — roda em pgsql/mysql/sqlite.
            'mad_ai_tool_audit' => 'CREATE TABLE IF NOT EXISTS mad_ai_tool_audit ('
                . ' id INTEGER PRIMARY KEY,'
                . ' conversation_id VARCHAR(64),'
                . ' user_id INTEGER,'
                . ' login VARCHAR(64),'
                . ' tool VARCHAR(64),'
                . ' params_json TEXT,'
                . ' risk_level VARCHAR(16),'
                . ' requires_backup INTEGER DEFAULT 0,'
                . ' confirm_id VARCHAR(40),'
                . ' confirmed INTEGER DEFAULT 0,'
                . ' confirmed_at VARCHAR(20),'
                . ' backup_ref VARCHAR(190),'
                . ' status VARCHAR(20),'
                . ' result_json TEXT,'
                . ' created_at VARCHAR(20)'
                . ')',

            // Uma linha por banco de negócio já copiado para cá (ChatLegacyImport).
            'mad_ai_legacy_import' => 'CREATE TABLE IF NOT EXISTS mad_ai_legacy_import ('
                . ' source VARCHAR(64) PRIMARY KEY,'
                . ' status VARCHAR(20),'
                . ' detail TEXT,'
                . ' created_at VARCHAR(20),'
                . ' updated_at VARCHAR(20)'
                . ')',

            default => throw new \InvalidArgumentException('Tabela desconhecida do EmbedSchema: ' . $table),
        };
    }

    /**
     * Colunas que nasceram depois da tabela. Tabela PRÉ-EXISTENTE (versão
     * antiga): o CREATE IF NOT EXISTS é no-op e NÃO adiciona a coluna nova. Sem
     * isto, o INSERT com tenant_id do ConversationStore falha ("no column named
     * tenant_id") e o save() (fail-safe) engole → conversas somem.
     *
     * @return array<string, string>
     */
    private static function lateColumns(string $table): array
    {
        return match ($table) {
            'mad_ai_conversation', 'mad_ai_favorite' => ['tenant_id' => 'INTEGER'],
            'mad_ai_dashboard'                       => ['filters_json' => 'TEXT'],
            default                                  => [],
        };
    }

    /**
     * Identidade do banco físico atrás da conexão: com tenant (bridge), a mesma
     * conexão `ai` aponta para outro banco a cada empresa, e a tabela pronta
     * num não diz nada do outro.
     */
    private static function fingerprint(string $db): string
    {
        $c = (array) config('database.connections.' . $db, []);

        return $db . '@' . md5(json_encode([
            $c['driver'] ?? '', $c['host'] ?? '', $c['port'] ?? '', $c['database'] ?? '', $c['search_path'] ?? '',
        ]) ?: '');
    }

    /** ADD COLUMN idempotente. Portável. Só silencia se a coluna existir MESMO. */
    private static function addColumnIfMissing(\PDO $pdo, string $table, string $column, string $type): void
    {
        // Confere antes: no PostgreSQL o ALTER redundante pede trava exclusiva
        // da tabela só para depois falhar com "já existe".
        if (self::columnExists($pdo, $table, $column)) {
            return;
        }

        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");

            return;
        } catch (\Throwable $e) {
            // Antes daqui saía um no-op assumindo "coluna duplicada". Só que
            // privilégio negado, lock timeout, tipo inválido e tabela ausente
            // chegam com exatamente a mesma cara — e aí a coluna NÃO é criada,
            // enquanto o código segue como se estivesse. É o estrago descrito
            // em lateColumns(): o INSERT com tenant_id passa a falhar, o save()
            // fail-safe engole, e as conversas somem sem nada no log.
            // A idempotência agora é VERIFICADA, não presumida.
            if (self::columnExists($pdo, $table, $column)) {
                return; // ALTER redundante: a coluna está lá, no-op legítimo
            }

            error_log('[EmbedSchema::addColumnIfMissing] ALTER TABLE ' . $table
                . ' ADD COLUMN ' . $column . ' falhou e a coluna continua AUSENTE — '
                . 'INSERTs que dependem dela vão falhar adiante: ' . $e->getMessage());
        }
    }

    /**
     * A coluna é legível? Um SELECT dela é o teste mais portável que existe
     * (sqlite/mysql/pgsql divergem em information_schema e em PRAGMA). Trata os
     * dois modos do PDO: ERRMODE_EXCEPTION lança, ERRMODE_SILENT devolve false.
     */
    private static function columnExists(\PDO $pdo, string $table, string $column): bool
    {
        try {
            return $pdo->query("SELECT {$column} FROM {$table} LIMIT 1") !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
