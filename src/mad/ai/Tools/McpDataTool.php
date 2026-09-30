<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\McpToolCaller;
use Mad\Ai\ProvenanceTracker;
use Mad\Ai\SseSink;
use Mad\Mcp\McpManifestTool;

/**
 * McpDataTool — adapta uma tool do manifest MCP para o loop do laravel/ai.
 *
 * Leitura (list/read/query/custom não-confirm): executa in-process via
 * McpToolCaller e emite tool_use_start/end; devolve o JSON do resultado ao
 * modelo. Permissão/PII/auditoria acontecem DENTRO da tool do manifest.
 *
 * Escrita (create/update/del ou confirm): NUNCA executa pelo loop. Self-gate:
 * registra a pendência {tool, args} e emite o bloco `confirm` no SSE — a
 * execução real é determinística, no turno seguinte (confirm:{approved}),
 * no EmbedChatController, com RE-VALIDAÇÃO de permissão. O loop do agente
 * jamais escreve.
 *
 * `schema()` delega à tool do manifest (McpSchemaBuilder já retorna
 * array<string,Type> compatível com o laravel/ai).
 */
final class McpDataTool implements Tool
{
    public function __construct(
        private McpManifestTool $mcpTool,
        private string $toolName,
        private bool $isWrite,
        private bool $isDestructive,
        private McpToolCaller $caller,
        private SseSink $sink,
        private ConfirmCoordinator $confirms,
        private ?ProvenanceTracker $trace = null,
    ) {
    }

    public function name(): string
    {
        return $this->toolName;
    }

    public function description(): string
    {
        $desc = (string) $this->mcpTool->description();
        if ($this->isWrite) {
            $desc .= ' [ESCRITA] A execucao NUNCA e imediata: ao chamar, um cartao de confirmacao e exibido e a acao so roda apos o usuario aprovar.';
        }

        return $desc;
    }

    public function schema(JsonSchema $schema): array
    {
        return $this->mcpTool->schema($schema);
    }

    public function handle(Request $request): string
    {
        $args = $request->all();

        if ($this->isWrite) {
            return $this->gateWrite($args);
        }

        $r = $this->caller->invoke($this->toolName, $args, $this->sink);

        // Proveniencia: registra a leitura ok do turno — as render tools anexam
        // este {tool, args} como `source` do bloco (habilita salvar favorito).
        $this->trace?->recordData($this->toolName, $args, is_array($r['result']) ? $r['result'] : null);

        return json_encode($r['result'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    /**
     * Self-gate de escrita: emite o bloco confirm + registra a pendência, sem
     * tocar no banco. O bloco é interno/determinístico (não vem do modelo) —
     * não passa por validador.
     *
     * @param array<string, mixed> $args
     */
    private function gateWrite(array $args): string
    {
        $id = $this->confirms->newId();

        $fields = [];
        foreach ($args as $k => $v) {
            if ($k === 'confirm') {
                continue;
            }
            $fields[] = [
                'k' => (string) $k,
                'v' => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE),
            ];
        }

        $this->confirms->stash($id, $this->toolName, $args);

        $this->sink->block([
            'type'   => 'confirm',
            'id'     => $id,
            'tool'   => $this->toolName,
            'title'  => 'Confirmar ação: ' . $this->toolName,
            'danger' => $this->isDestructive,
            'fields' => $fields,
        ]);

        return 'Esta acao de escrita requer confirmacao do usuario. Um cartao de confirmacao foi exibido; aguarde a resposta antes de prosseguir.';
    }
}
