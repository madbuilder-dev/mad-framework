<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\JsonSchemaBuilder;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\SseSink;
use Mad\Ai\WidgetBlockBuilder;
use Mad\Ai\WidgetSqlGuard;

/**
 * DbQueryTool — query_db: consulta de LEITURA ao banco que NÃO aparece como
 * bloco para o usuário (só o card da tool). Serve para o modelo conferir antes
 * de responder (nomes de status, faixa de datas, contagens, amostras).
 *
 * Sem ela o modelo explorava com preview_widget — e cada exploração virava um
 * bloco na conversa ("Colunas venda", "Diagnóstico rápido de volume…").
 * Mesmo guard da SQL de widget (SELECT-only, marcadores de data do sistema);
 * as linhas voltam em amostra (PII mascarada) e viram prova de origem do turno.
 */
final class DbQueryTool implements Tool
{
    /** Linhas que voltam ao modelo. */
    public const MAX_ROWS = 50;

    /**
     * @param list<string> $piiColumns colunas PII do manifest (mascaradas)
     */
    public function __construct(
        private string $db,
        private SseSink $sink,
        private ?ProvenanceTracker $trace = null,
        private array $piiColumns = [],
    ) {
    }

    public function name(): string
    {
        return 'query_db';
    }

    public function description(): string
    {
        return 'Run ONE read-only SELECT on the system database and get the rows back (up to ' . self::MAX_ROWS . ') '
            . 'WITHOUT showing anything to the user — to explore/confirm before answering (status names, date ranges, counts, samples). '
            . 'To SHOW the answer to the user use preview_widget (the result becomes a block in the chat). '
            . 'Same SQL rules as widgets: SELECT/WITH only, call db_schema first, date markers :hoje, :agora, :inicio_mes, '
            . ':inicio_proximo_mes, :inicio_mes_anterior, :inicio_ano are filled by the system.';
    }

    public function schema(JsonSchema $schema): array
    {
        return JsonSchemaBuilder::properties($schema, [
            'type'       => 'object',
            'properties' => [
                'sql' => ['type' => 'string', 'description' => 'ONE read-only SELECT (or WITH … SELECT).'],
            ],
            'required'   => ['sql'],
        ]);
    }

    public function handle(Request $request): string
    {
        $t0  = microtime(true);
        $sql = trim((string) ($request->all()['sql'] ?? ''));
        $this->sink->toolUseStart($this->name(), []);

        if ($sql === '') {
            $this->sink->toolUseEnd($this->name(), [], 0, ['error' => 'sem sql'], false);

            return 'erro: informe sql.';
        }
        if (str_contains($sql, ':')) {
            $sql = WidgetSqlGuard::interpolate($this->db, $sql, WidgetBlockBuilder::dateParams());
        }

        $run = WidgetSqlGuard::run($this->db, $sql, self::MAX_ROWS + 1);
        $ms  = (int) round((microtime(true) - $t0) * 1000);
        if (! $run['ok']) {
            $err = (string) ($run['error'] ?? 'falha ao executar a SQL');
            $this->sink->toolUseEnd($this->name(), [], $ms, ['error' => $err], false);

            return 'erro: ' . $err;
        }

        $rows = $run['rows'] ?? [];
        $this->trace?->recordRows($rows);
        $sample = WidgetUpsertTool::sampleRows($rows, self::MAX_ROWS, $this->piiColumns);
        $this->trace?->recordRows($sample['rows']);
        $this->sink->toolUseEnd($this->name(), [], $ms, ['total' => count($sample['rows'])]);

        return json_encode([
            'rowCount'  => count($sample['rows']),
            'columns'   => $sample['columns'],
            'rows'      => $sample['rows'],
            'truncated' => $sample['truncated'],
        ] + WidgetUpsertTool::summarize($rows, $this->piiColumns) + [
            'note'      => 'Nada disto foi exibido ao usuário. Para MOSTRAR a resposta, use preview_widget; '
                . 'use SOMENTE estes valores (contagens/totais de "counts"/"stats") — nunca invente.'
                . ($sample['rows'] === [] ? ' A consulta não retornou linhas.' : ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{"rowCount":0}';
    }
}
