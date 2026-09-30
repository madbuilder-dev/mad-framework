<?php

namespace Mad\Ai;

use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response as McpResponse;
use Mad\Mcp\McpManifest;
use Mad\Mcp\McpManifestLoader;
use Mad\Mcp\McpManifestTool;
use Mad\Mcp\McpToolFactory;

/**
 * McpToolCaller — executa as tools do MCP IN-PROCESS (sem loopback HTTP).
 *
 * Reusa o motor por manifest: McpToolFactory::build() gera as instancias (ja
 * filtradas por permissao via shouldRegister sob o McpCurrentUser corrente) e
 * cada tool e chamada via $tool->handle(new Laravel\Mcp\Request($args)). O
 * resultado e extraido do Laravel\Mcp\Response (Response::json/error) e emitido
 * como evento SSE tool_use_end.
 *
 * Permissoes, mascaramento de PII e auditoria continuam acontecendo DENTRO das
 * tools do manifest — aqui so orquestramos e cronometramos.
 */
final class McpToolCaller
{
    private McpManifest $manifest;

    /** @var array<string, McpManifestTool> tools permitidas, por nome PUBLICO */
    private array $tools = [];

    /** @var array<string, array<string, mixed>> spec do manifest, por nome PUBLICO */
    private array $specs = [];

    public function __construct(?McpManifest $manifest = null)
    {
        $this->manifest = $manifest ?? McpManifestLoader::load();

        foreach (McpToolFactory::build($this->manifest) as $tool) {
            if ($tool instanceof McpManifestTool && $tool->shouldRegister()) {
                $this->tools[self::publicName($tool->name())] = $tool;
            }
        }

        foreach ($this->manifest->tools() as $spec) {
            $id = (string) ($spec['id'] ?? '');
            if ($id !== '') {
                $this->specs[self::publicName($id)] = $spec;
            }
        }
    }

    /**
     * Nome PUBLICO da tool p/ o loop do agente: `ns.slug` -> `ns__slug`.
     * A Anthropic (direta ou via OpenRouter/Bedrock/Vertex) exige tool name
     * `^[a-zA-Z0-9_-]{1,128}$` — ponto e rejeitado com 400. O MCP server
     * externo (JSON-RPC) continua com os nomes canonicos do manifest.
     */
    public static function publicName(string $name): string
    {
        return str_replace('.', '__', $name);
    }

    /** Resolve nome publico OU canonico (alias p/ favoritos/pendencias legados). */
    private function key(string $name): string
    {
        return isset($this->tools[$name]) || isset($this->specs[$name])
            ? $name
            : self::publicName($name);
    }

    public function manifest(): McpManifest
    {
        return $this->manifest;
    }

    /** @return array<string, McpManifestTool> */
    public function allowedTools(): array
    {
        return $this->tools;
    }

    public function has(string $name): bool
    {
        return isset($this->tools[$this->key($name)]);
    }

    /** @return array<string, mixed>|null */
    public function spec(string $name): ?array
    {
        return $this->specs[$this->key($name)] ?? null;
    }

    /** Tool de escrita? (create/update/del ou custom marcada confirm). */
    public function isWrite(string $name): bool
    {
        $spec = $this->spec($name);
        if ($spec === null) {
            return false;
        }
        $verb = (string) ($spec['verb'] ?? '');
        if (in_array($verb, ['create', 'update', 'del'], true)) {
            return true;
        }

        return ! empty($spec['confirm']);
    }

    public function isDestructive(string $name): bool
    {
        $spec = $this->spec($name);

        return $spec !== null && (string) ($spec['verb'] ?? '') === 'del';
    }

    /**
     * Executa a tool in-process e emite tool_use_end (sink null = execucao
     * silenciosa, fora de stream — ex.: compile/replay de favorito). Para
     * del_*, injeta confirm=true (o McpCrudTool reexige confirm no request).
     *
     * @param array<string, mixed> $args
     * @return array{result: mixed, ok: bool, ms: int}
     */
    public function invoke(string $name, array $args, ?SseSink $sink = null): array
    {
        $t0 = microtime(true);

        $name = $this->key($name);
        $tool = $this->tools[$name] ?? null;
        if ($tool === null) {
            $ms  = (int) round((microtime(true) - $t0) * 1000);
            $res = ['error' => "Tool '{$name}' indisponivel ou sem permissao para este perfil."];
            $sink?->toolUseEnd($name, $args, $ms, $res, false);

            return ['result' => $res, 'ok' => false, 'ms' => $ms];
        }

        if ($this->isDestructive($name)) {
            $args['confirm'] = true;
        }

        // Feedback imediato: o card da tool aparece "executando…" no instante
        // em que a consulta comeca (o end substitui com resultado/latencia).
        $sink?->toolUseStart($name, $args);

        try {
            $resp = $tool->handle(new McpRequest($args));
            [$result, $ok] = self::extract($resp);
        } catch (\Throwable $e) {
            $result = ['error' => $e->getMessage()];
            $ok     = false;
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $sink?->toolUseEnd($name, $args, $ms, $result, $ok);

        return ['result' => $result, 'ok' => $ok, 'ms' => $ms];
    }

    /**
     * Extrai o payload do Laravel\Mcp\Response. Response::json → JSON; Response::error
     * → texto puro (vira {message}).
     *
     * @return array{0: mixed, 1: bool}
     */
    private static function extract(McpResponse $resp): array
    {
        $ok   = ! $resp->isError();
        $text = (string) $resp->content();
        $decoded = json_decode($text, true);
        $result  = is_array($decoded) ? $decoded : ['message' => $text];

        return [$result, $ok];
    }
}
