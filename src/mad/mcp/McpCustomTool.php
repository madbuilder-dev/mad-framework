<?php

namespace Mad\Mcp;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Illuminate\Support\Facades\DB;

/**
 * McpCustomTool
 *
 * Acao customizada (Contrato 3.3): aponta para um metodo publico de um model/
 * servico do app, cujo nome REAL vem do manifest (class+method) — o configurador
 * e a fonte confiavel. Roda em Transaction; respeita confirm.
 *
 * spec = { class, method, params[], confirm?, read_only?, entity? }
 *
 * params[] aceita "nome", "nome?" (opcional) e "nome:tipo" / "nome:tipo?" —
 * o tipo coage o argumento (integer/float/boolean/string) antes do invoke.
 *
 * SEGURANCA (H3): a invocacao NAO e livre. Exige:
 *   - allowlist [mcp] custom_tools_allowed[] = Classe::metodo (fail-closed);
 *   - confirm=true salvo se a tool for explicitamente read_only;
 *   - args coagidos/validados contra os params declarados.
 *
 * SEGURANCA (FURO #4): o metodo roda FORA do McpScopedGateway (SQL opaco), entao:
 *   - param com tipo `scope`/`actor` e INJETADO pelo servidor com o id do usuario
 *     do token (McpScopeContext), ignorando qualquer valor do LLM — o metodo
 *     filtra por esse id e nao por um seletor controlado pelo agente;
 *   - ADMIN-ONLY por default: so admin chama tool custom, salvo `reviewed:true`
 *     no spec (opt-out explicito apos revisao de seguranca do metodo).
 */
final class McpCustomTool extends McpManifestTool
{
    /** Tipos de param cujo valor e injetado server-side (id do ator), nunca do LLM. */
    private const INJECT_TYPES = ['scope', 'actor'];

    public function annotations(): array
    {
        $ro = ! empty($this->spec['read_only']) || ! empty($this->spec['readonly']);
        if ($ro) {
            return ['readOnlyHint' => true];
        }
        return ! empty($this->spec['confirm']) ? ['destructiveHint' => true] : [];
    }

    /**
     * Params parseados: nome, opcionalidade e tipo.
     *
     * @return array<int,array{col:string,type:string,optional:bool,inject:bool}>
     */
    private function parsedParams(): array
    {
        $out = [];
        foreach ((array) ($this->spec['params'] ?? []) as $p) {
            $p        = (string) $p;
            $optional = str_ends_with($p, '?');
            $p        = rtrim($p, '?');
            [$col, $type] = array_pad(explode(':', $p, 2), 2, 'string');
            $col  = trim($col);
            $type = trim((string) $type);
            if ($col === '') {
                continue;
            }
            $type   = $type !== '' ? $type : 'string';
            $inject = in_array(strtolower($type), self::INJECT_TYPES, true);
            $out[]  = ['col' => $col, 'type' => $type, 'optional' => $optional, 'inject' => $inject];
        }
        return $out;
    }

    public function schema(JsonSchema $schema): array
    {
        // Params injetados (scope/actor) NAO sao expostos ao agente — sao server-side.
        $names = array_map(
            fn (array $p) => $p['col'] . ($p['optional'] ? '?' : ''),
            array_values(array_filter($this->parsedParams(), fn (array $p) => ! $p['inject']))
        );
        return $this->schemaBuilder()->paramsSchema($schema, $names);
    }

    public function handle(Request $request): Response
    {
        $class  = (string) ($this->spec['class'] ?? '');
        $method = (string) ($this->spec['method'] ?? '');

        if ($class === '' || $method === '') {
            return Response::error('Tool custom sem class/method no manifest.');
        }

        // H3 (1): allowlist explicito de Classe::metodo. Fail-closed.
        if (! self::isAllowed($class, $method)) {
            \error_log("[mcp:custom] bloqueado fora do allowlist: {$class}::{$method}");
            return Response::error('Operacao custom nao autorizada.');
        }

        // FURO #4: ADMIN-ONLY por default. O metodo roda SQL opaco fora do escopo,
        // entao so admin o alcanca ate o configurador marcar reviewed:true (apos
        // garantir que o metodo filtra pelo ator injetado).
        if (empty($this->spec['reviewed']) && ! (new McpPermissionResolver())->isAdmin()) {
            McpScopeAudit::denied((string) ($this->spec['id'] ?? $method), 'custom-not-reviewed');
            return Response::error('Tool custom e admin-only ate ser revisada (spec.reviewed).');
        }

        // H3 (3): confirm obrigatorio, salvo tool explicitamente read_only.
        $readOnly     = ! empty($this->spec['read_only']) || ! empty($this->spec['readonly']);
        $needsConfirm = ! $readOnly || ! empty($this->spec['confirm']);
        if ($needsConfirm && ! (bool) $request->get('confirm', false)) {
            return Response::error('Operacao custom requer confirm=true.');
        }

        if (! class_exists($class) || ! method_exists($class, $method)) {
            return Response::error("Metodo {$class}::{$method} indisponivel.");
        }

        $ref = new \ReflectionMethod($class, $method);
        if (! $ref->isPublic() || $ref->isAbstract()) {
            return Response::error("Metodo {$class}::{$method} nao e invocavel.");
        }

        // H3 (2): args posicionais coagidos/validados contra os params declarados.
        $args = [];
        foreach ($this->parsedParams() as $p) {
            // FURO #4: param `scope`/`actor` = id do usuario do token (server-side),
            // NUNCA do LLM. Mantem a posicao no invoke; valor do request e ignorado.
            if ($p['inject']) {
                $args[] = (int) (McpScopeContext::userId() ?? -1);
                continue;
            }
            $raw = $request->get($p['col']);
            if ($raw === null) {
                if (! $p['optional']) {
                    return Response::error("Parametro obrigatorio ausente: {$p['col']}.");
                }
                $args[] = null;
                continue;
            }
            $args[] = self::coerce($raw, $p['type']);
        }

        try {
            $result = DB::connection($this->db())->transaction(function () use ($ref, $class, $args) {
                return $ref->isStatic()
                    ? $ref->invokeArgs(null, $args)
                    : $ref->invokeArgs(new $class(), $args);
            });
        } catch (\Throwable $e) {
            \error_log("[mcp:custom] {$class}::{$method}: " . $e->getMessage());

            return Response::error('Erro ao executar a operacao custom.');
        }

        McpChangeAudit::record($this->entity() ?: $class, '-', $method, 'custom', [], ['args' => json_encode($args)]);

        return Response::json([
            'ok'     => true,
            'result' => $this->maskResult($this->normalizeResult($result)),
        ]);
    }

    /**
     * L2: aplica o PII masker da entidade ao resultado (quando record-like).
     * Single row (assoc) -> maskRow; lista de rows -> maskRows; escalar -> intacto.
     */
    private function maskResult(mixed $result): mixed
    {
        if (! is_array($result) || $result === []) {
            return $result;
        }
        $masker = $this->masker();
        // lista de registros (array de arrays)
        if (array_is_list($result)) {
            foreach ($result as $r) {
                if (! is_array($r)) {
                    return $result; // nao e lista de rows — nao mexe
                }
            }
            return $masker->maskRows($result);
        }
        // row unica (mapa coluna=>valor)
        return $masker->maskRow($result);
    }

    private function normalizeResult(mixed $result): mixed
    {
        if (is_object($result) && method_exists($result, 'toArray')) {
            return $result->toArray();
        }
        if (is_scalar($result) || is_array($result) || $result === null) {
            return $result;
        }

        return (string) $result;
    }

    /**
     * Allowlist explicito de Classe::metodo ([mcp] custom_tools_allowed[]).
     * Case-insensitive. Sem allowlist configurada => NEGA tudo (fail-closed):
     * a invocacao dinamica e o sink mais perigoso do manifest.
     */
    private static function isAllowed(string $class, string $method): bool
    {
        $ini  = \Mad\Core\AppConfig::get();
        $list = $ini['mcp']['custom_tools_allowed'] ?? [];
        if (is_string($list)) {
            $list = [$list];
        }
        if (! is_array($list) || $list === []) {
            return false;
        }

        $pair = strtolower($class . '::' . $method);
        foreach ($list as $entry) {
            if (strtolower(trim((string) $entry)) === $pair) {
                return true;
            }
        }
        return false;
    }

    /** Coage o argumento ao tipo declarado no param (espelha McpCrudTool::coerce). */
    private static function coerce(mixed $val, string $type): mixed
    {
        $base = strtok(strtolower(trim($type)), '(');

        return match ($base) {
            'integer', 'bigint', 'int', 'smallint', 'tinyint' => (int) $val,
            'double', 'float', 'decimal', 'numeric', 'real'   => (float) $val,
            'boolean', 'bool'                                  => ((bool) $val) ? 1 : 0,
            'string', 'varchar', 'text', 'char'                => is_scalar($val) ? (string) $val : $val,
            default                                            => $val,
        };
    }
}
