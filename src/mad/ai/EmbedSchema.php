<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;

/**
 * EmbedSchema — schema das 3 tabelas do módulo embed/IA, self-contained
 * (CREATE TABLE IF NOT EXISTS), no padrão do repo (McpTokenService /
 * SystemAccessLogService) — o migrator legado foi removido e as conexões MAD
 * são nativas (config/database.php), fora do `php artisan migrate`.
 *
 *   mad_ai_conversation  histórico/pendências por conversa (ConversationStore)
 *   mad_ai_favorite      favoritos do chat (F3.2 — tabela criada, sem uso ainda)
 *   mad_ai_token_usage     medição de consumo por turno (UsageLog)
 *   mad_ai_widget          widgets de BI salvos pelo agente (WidgetStore)
 *   mad_ai_dashboard       dashboards do usuário (DashboardStore)
 *   mad_ai_dashboard_share compartilhamento user/grupo de dashboard
 *
 * Idempotente, 1x por processo por conexão.
 */
final class EmbedSchema
{
    /** @var array<string, bool> conexões já garantidas neste processo */
    private static array $ready = [];

    public static function ensure(string $db): void
    {
        if (self::$ready[$db] ?? false) {
            return;
        }

        $pdo = DB::connection($db)->getPdo();

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_conversation ('
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
            . ')'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_favorite ('
            . ' id VARCHAR(64) PRIMARY KEY,'
            . ' user_id INTEGER,'
            . ' title VARCHAR(190),'
            . ' payload_json TEXT,'
            . ' created_at VARCHAR(20),'
            . ' updated_at VARCHAR(20),'
            . ' tenant_id INTEGER'
            . ')'
        );

        // MESMO shape da tabela da tela "Consumo de IA" (criada pelo módulo de
        // usage já portado) — IF NOT EXISTS cobre instalação limpa.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_token_usage ('
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
            . ' created_at DATETIME,'
            . ' cost_usd NUMERIC'
            . ')'
        );

        // Tabela PRÉ-EXISTENTE (criada por uma versão antiga sem tenant_id): o
        // CREATE IF NOT EXISTS acima é no-op e NÃO adiciona a coluna nova. Sem
        // isto, o INSERT com tenant_id do ConversationStore falha ("no column
        // named tenant_id") e o save() (fail-safe) engole → conversas somem.
        // ADD COLUMN é idempotente via try/catch (erro = coluna já existe);
        // portável sqlite/mysql/pgsql.
        self::addColumnIfMissing($pdo, 'mad_ai_conversation', 'tenant_id', 'INTEGER');
        self::addColumnIfMissing($pdo, 'mad_ai_favorite', 'tenant_id', 'INTEGER');

        // Micro-BI (widgets + dashboards do usuário final). spec_json guarda a
        // estrutura determinística do widget: {type, sql, map, style} — a
        // exibição re-executa a SQL (WidgetSqlGuard) e re-mapeia SEM IA.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_widget ('
            . ' id VARCHAR(64) PRIMARY KEY,'
            . ' user_id INTEGER,'
            . ' title VARCHAR(190),'
            . ' spec_json TEXT,'
            . ' created_at VARCHAR(20),'
            . ' updated_at VARCHAR(20),'
            . ' tenant_id INTEGER'
            . ')'
        );

        // layout_json = [{widgetId, x, y, w, h}] (grid 12 colunas do viewer).
        // filters_json = filtros globais do dashboard (DashboardStore::sanitizeFilters).
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_dashboard ('
            . ' id VARCHAR(64) PRIMARY KEY,'
            . ' user_id INTEGER,'
            . ' title VARCHAR(190),'
            . ' layout_json TEXT,'
            . ' filters_json TEXT,'
            . ' is_home INTEGER DEFAULT 0,'
            . ' created_at VARCHAR(20),'
            . ' updated_at VARCHAR(20),'
            . ' tenant_id INTEGER'
            . ')'
        );
        self::addColumnIfMissing($pdo, 'mad_ai_dashboard', 'filters_json', 'TEXT');

        // kind: user|group; ref_id = system_user.id | system_group.id.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_dashboard_share ('
            . ' id INTEGER PRIMARY KEY,'
            . ' dashboard_id VARCHAR(64),'
            . ' kind VARCHAR(8),'
            . ' ref_id INTEGER,'
            . ' created_at VARCHAR(20)'
            . ')'
        );

        // Auditoria das system tools do Command Center (ToolAuditStore). id
        // portável (MAX(id)+1, sem AUTOINCREMENT) — roda em pgsql/mysql/sqlite.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS mad_ai_tool_audit ('
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
            . ')'
        );

        self::$ready[$db] = true;
    }

    /** ADD COLUMN idempotente. Portável. Só silencia se a coluna existir MESMO. */
    private static function addColumnIfMissing(\PDO $pdo, string $table, string $column, string $type): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$type}");

            return;
        } catch (\Throwable $e) {
            // Antes daqui saía um no-op assumindo "coluna duplicada". Só que
            // privilégio negado, lock timeout, tipo inválido e tabela ausente
            // chegam com exatamente a mesma cara — e aí a coluna NÃO é criada,
            // enquanto o código segue como se estivesse. É o estrago descrito
            // nas linhas 89-92: o INSERT com tenant_id passa a falhar, o save()
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
