<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\SseSink;
use Mad\Ai\UserDirectory;
use Mad\Mcp\McpManifest;
use Mad\Mcp\McpAccess;

/**
 * DbSchemaTool — expõe ao modelo o schema consultável para autoria de SQL de
 * widgets e do query_db (micro-BI do embed).
 *
 * Lista SÓ o que o usuário atual pode ler pelo manifest MCP ({@see McpAccess}):
 * tabelas com tool de leitura concedida a um dos perfis dele, com as colunas
 * expostas e o que o configurador escreveu delas (tipo, PK, FK, descrição);
 * PII marcada como mascarada. É o espelho do que a SQL aceita
 * ({@see \Mad\Ai\WidgetSqlScope}): anunciar tabela que a SQL recusa faz o
 * modelo errar e, no limite, inventar.
 *
 * Até o 5.96.21 a ausência do manifest (ou `mad.ai.widgets_full_schema`)
 * caía na introspecção do banco INTEIRO — o modelo recebia todas as tabelas,
 * inclusive as que o perfil do usuário não acessa. Não existe mais: sem
 * manifest ou sem concessão, a lista vem vazia com a frase para o modelo
 * repassar.
 */
final class DbSchemaTool implements Tool
{
    public function __construct(
        private string $db,
        private ?McpManifest $manifest,
        private SseSink $sink,
    ) {
    }

    public function name(): string
    {
        return 'db_schema';
    }

    public function description(): string
    {
        return 'List the queryable tables + columns (and the SQL dialect) of the system database that THIS USER may read. '
            . 'Each column reads "name type [pk] [→ table.column it references] [— business meaning] [(pii: masked)]". '
            . 'ALWAYS call this before writing SQL for query_db/preview_widget/save_widget — never guess table or column names. '
            . 'A table that is not listed does not exist for this user.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $t0 = microtime(true);
        $this->sink->toolUseStart('db_schema', []);

        $driver = 'sql';
        try {
            $driver = (string) DB::connection($this->db)->getDriverName();
        } catch (\Throwable) {
        }

        $access = McpAccess::for($this->manifest);
        $tables = [];
        $sb     = null;
        try {
            $sb = DB::connection($this->db)->getSchemaBuilder();
        } catch (\Throwable) {
        }

        foreach ($access->readableTables() as $table) {
            // Manifest stale (tabela declarada que NÃO existe no banco) não
            // entra na lista — anunciar tabela fantasma faz o modelo gerar
            // SQL quebrada e, no limite, fabricar dados.
            try {
                if ($sb !== null && ! $sb->hasTable($table) && ! in_array(strtolower($table), array_map(
                    static fn ($v) => strtolower((string) ($v['name'] ?? '')),
                    $sb->getViews()
                ), true)) {
                    continue;
                }
            } catch (\Throwable) {
            }

            $cols = $access->columns($table);
            if ($cols === []) {
                continue;
            }
            // "coluna tipo [pk] [→ tabela.coluna] [— descrição] [(pii)]": a FK diz
            // como fazer o JOIN e a descrição liga o termo do negócio à coluna.
            $tables[$table] = array_map(
                static fn (array $f): string => (string) ($f['name'] ?? '')
                    . (isset($f['type']) ? (' ' . $f['type']) : '')
                    . (! empty($f['pk']) ? ' pk' : '')
                    . (! empty($f['fk']) && is_string($f['fk']) ? (' → ' . UserDirectory::rewriteFk($f['fk'])) : '')
                    . (! empty($f['desc']) && is_string($f['desc']) ? (' — ' . $f['desc']) : '')
                    . (! empty($f['pii']) ? ' (pii: valor mascarado)' : ''),
                $cols
            );
        }

        // Nome dos usuários (vendedor, responsável, criado por…): a tabela de
        // usuários é protegida inteira; o assistente lê id e nome por esta
        // relação virtual. Só com alguma tabela liberada — e nunca por cima de
        // uma tabela de verdade com o mesmo nome.
        $realUsersTable = false;
        try {
            $realUsersTable = $sb !== null && $sb->hasTable(UserDirectory::TABLE);
        } catch (\Throwable) {
        }
        if ($tables !== [] && ! $realUsersTable) {
            $tables[UserDirectory::TABLE] = UserDirectory::schemaColumns();
        }

        $out = ['driver' => $driver, 'tables' => $tables];
        if ($tables === []) {
            $out['note'] = $this->manifest === null
                ? 'O assistente ainda não tem acesso aos dados deste sistema (o MCP do projeto não foi publicado). '
                    . 'Diga ao usuário que ele não tem acesso a dados pelo assistente e que o administrador do sistema libera isso.'
                : 'O perfil deste usuário não tem acesso a nenhuma tabela pelo assistente. '
                    . 'Diga isso a ele e que o administrador do sistema pode liberar; não tente consultar mesmo assim.';
        }
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->sink->toolUseEnd('db_schema', [], $ms, ['total' => count($tables)]);

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }
}
