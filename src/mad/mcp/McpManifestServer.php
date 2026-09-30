<?php

namespace Mad\Mcp;

use Laravel\Mcp\Server;

/**
 * McpManifestServer
 *
 * Servidor MCP dirigido por manifest. No boot() (chamado a cada requisicao, antes
 * de createContext) le o mcp.config.json e gera as tools dinamicamente. O
 * laravel/mcp aceita instancias de Tool em $this->tools.
 *
 * Registrado em app/routes/ai.php: Mcp::web('/mcp/v1/sse', McpManifestServer::class).
 */
class McpManifestServer extends Server
{
    protected function boot(): void
    {
        try {
            $manifest = McpManifestLoader::load();
        } catch (\Throwable $e) {
            $this->name         = 'MCP (sem manifest)';
            $this->version      = '0.0.0';
            $this->instructions = 'Manifest mcp.config.json indisponivel: ' . $e->getMessage();
            $this->tools        = [];

            return;
        }

        $this->name         = $manifest->systemValue('name', 'MCP Server');
        $this->version      = $manifest->version();
        $this->instructions = $this->buildSystemPrompt($manifest);
        $this->tools        = McpToolFactory::build($manifest);

        // Audita tentativas negadas por permissao em tools/call (error_log +
        // MadTrace + LogAccess modo 'mcp') antes do "Tool not found".
        $this->addMethod('tools/call', McpAuditedCallTool::class);
    }

    /**
     * System prompt montado do manifest (Contrato 7): description + domain/lang/
     * tone + instructions + Vocabulario (glossary) + exemplos (fewshot).
     */
    private function buildSystemPrompt(McpManifest $manifest): string
    {
        $sys   = $manifest->system();
        $lines = [];

        if (! empty($sys['description'])) {
            $lines[] = (string) $sys['description'];
        }

        $meta = array_filter([
            'Dominio' => $sys['domain']  ?? null,
            'Idioma'  => $sys['lang']    ?? null,
            'Tom'     => $sys['tone']    ?? null,
        ]);
        if ($meta !== []) {
            $parts = [];
            foreach ($meta as $k => $v) {
                $parts[] = "{$k}: {$v}";
            }
            $lines[] = implode(' | ', $parts);
        }

        if (! empty($sys['instructions'])) {
            $lines[] = "\n## Regras\n" . (string) $sys['instructions'];
        }

        $glossary = $manifest->glossary();
        if ($glossary !== []) {
            $g = ["\n## Vocabulario"];
            foreach ($glossary as $item) {
                $term = (string) ($item['term'] ?? '');
                $def  = (string) ($item['definition'] ?? '');
                if ($term !== '') {
                    $g[] = "- **{$term}**: {$def}";
                }
            }
            $lines[] = implode("\n", $g);
        }

        $fewshot = $manifest->fewshot();
        if ($fewshot !== []) {
            $f = ["\n## Exemplos"];
            foreach ($fewshot as $ex) {
                $q     = (string) ($ex['q'] ?? '');
                $tools = implode(', ', (array) ($ex['tools'] ?? []));
                if ($q !== '') {
                    $f[] = "- \"{$q}\" -> {$tools}";
                }
            }
            $lines[] = implode("\n", $f);
        }

        return implode("\n", $lines);
    }
}
