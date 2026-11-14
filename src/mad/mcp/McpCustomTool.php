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
 * spec = { class, method, params[], confirm?, read_only?, reviewed?, entity? }
 *
 * params[] aceita dois formatos (podem se misturar):
 *   - objeto {name, type, required?=true, description?} — o que o configurador
 *     do Studio publica ("Acoes personalizadas");
 *   - string "nome", "nome?" (opcional), "nome:tipo", "nome:tipo?" (legado).
 *
 * Tipos: string | integer | number | boolean | json (lista/objeto: aceita o
 * valor nativo ou o texto JSON e entrega ARRAY ao metodo) + actor/scope
 * (injetados no servidor) + os aliases legados (int, float, decimal, bool...).
 * O argumento e validado/convertido ANTES do invoke; valor invalido volta ao
 * agente como erro que ele consegue corrigir. Invocacao posicional, na ordem
 * de params.
 *
 * Erro de regra de negocio: o metodo lanca \DomainException com a mensagem para
 * o usuario final ("Produto inativo.") — ela volta ao agente como erro. Qualquer
 * outro Throwable segue generico (+ error_log): stack/SQL nunca vazam ao modelo.
 *
 * SEGURANCA (H3): a invocacao NAO e livre. Exige:
 *   - allowlist mad.mcp.custom_tools_allowed[] = Classe::metodo (fail-closed),
 *     que o builder gera a partir das acoes do manifest publicado
 *     (env MAD_MCP_CUSTOM_TOOLS);
 *   - confirm=true salvo se a tool for explicitamente read_only. No Chat IA esse
 *     confirm NUNCA vem do modelo: o McpToolCaller descarta o do LLM e so o
 *     injeta na execucao aprovada no cartao de confirmacao;
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
    public const INJECT_TYPES = ['scope', 'actor'];

    /** Tipos que o configurador publica (alem de actor/scope). */
    public const PARAM_TYPES = ['string', 'integer', 'number', 'boolean', 'json'];

    /** Alias legado → tipo canonico. Tipo fora daqui (e fora de PARAM_TYPES/INJECT_TYPES) e desconhecido. */
    private const TYPE_ALIASES = [
        'int' => 'integer', 'bigint' => 'integer', 'smallint' => 'integer', 'tinyint' => 'integer',
        'double' => 'number', 'float' => 'number', 'decimal' => 'number', 'numeric' => 'number', 'real' => 'number',
        'bool' => 'boolean',
        'varchar' => 'string', 'text' => 'string', 'char' => 'string', 'date' => 'string', 'datetime' => 'string',
        'array' => 'json', 'object' => 'json',
    ];

    public function annotations(): array
    {
        if (self::specReadOnly($this->spec)) {
            return ['readOnlyHint' => true];
        }

        return ['destructiveHint' => true];
    }

    /** A acao so le? (`read_only`/`readonly` no spec). Sem isso, e ESCRITA. */
    public static function specReadOnly(array $spec): bool
    {
        return ! empty($spec['read_only']) || ! empty($spec['readonly']);
    }

    /**
     * Tipo canonico de um tipo declarado (alias legado incluido), ou null se
     * desconhecido. `decimal(12,2)` → number.
     */
    public static function canonicalType(string $type): ?string
    {
        $base = strtolower(trim((string) strtok(trim($type), '(')));
        if ($base === '') {
            return 'string';
        }
        if (in_array($base, self::PARAM_TYPES, true) || in_array($base, self::INJECT_TYPES, true)) {
            return $base;
        }

        return self::TYPE_ALIASES[$base] ?? null;
    }

    /**
     * Params normalizados (objeto ou string legada), na ordem declarada.
     *
     * @param  array<int,mixed> $params
     * @return list<array{name:string,type:string,canonical:?string,required:bool,description:string,inject:bool}>
     */
    public static function normalizeParams(array $params): array
    {
        $out = [];
        foreach ($params as $p) {
            if (is_array($p)) {
                $name     = trim((string) ($p['name'] ?? ''));
                $type     = trim((string) ($p['type'] ?? 'string'));
                $required = ! array_key_exists('required', $p) || filter_var($p['required'], FILTER_VALIDATE_BOOLEAN);
                $desc     = trim((string) ($p['description'] ?? ''));
            } else {
                $p        = trim((string) $p);
                $required = ! str_ends_with($p, '?');
                $p        = rtrim($p, '?');
                [$name, $type] = array_pad(explode(':', $p, 2), 2, 'string');
                $name     = trim($name);
                $type     = trim((string) $type);
                $desc     = '';
            }
            if ($name === '') {
                continue;
            }
            $type      = $type !== '' ? $type : 'string';
            $canonical = self::canonicalType($type);
            $out[]     = [
                'name'        => $name,
                'type'        => $type,
                'canonical'   => $canonical,
                'required'    => $required,
                'description' => $desc,
                'inject'      => in_array($canonical, self::INJECT_TYPES, true),
            ];
        }

        return $out;
    }

    /** @return list<array{name:string,type:string,canonical:?string,required:bool,description:string,inject:bool}> */
    private function parsedParams(): array
    {
        return self::normalizeParams((array) ($this->spec['params'] ?? []));
    }

    public function schema(JsonSchema $schema): array
    {
        // Params injetados (scope/actor) NAO sao expostos ao agente — sao server-side.
        $visible = array_values(array_filter($this->parsedParams(), fn (array $p) => ! $p['inject']));

        return $this->schemaBuilder()->customParamsSchema($schema, $visible);
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
        $needsConfirm = ! self::specReadOnly($this->spec) || ! empty($this->spec['confirm']);
        if ($needsConfirm && $request->get('confirm', false) !== true) {
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
            $raw = $request->get($p['name']);
            if ($raw === null || $raw === '') {
                if ($p['required']) {
                    return Response::error("Parametro obrigatorio ausente: {$p['name']}.");
                }
                $args[] = null;
                continue;
            }
            [$ok, $value] = self::coerce($raw, $p['canonical']);
            if (! $ok) {
                return Response::error("Parametro {$p['name']}: {$value}");
            }
            $args[] = $value;
        }

        try {
            $result = DB::connection($this->db())->transaction(function () use ($ref, $class, $args) {
                return $ref->isStatic()
                    ? $ref->invokeArgs(null, $args)
                    : $ref->invokeArgs(new $class(), $args);
            });
        } catch (\DomainException $e) {
            // Regra de negocio recusada pelo proprio metodo: a mensagem e para o
            // usuario final (o agente a repassa). Transacao ja desfeita.
            $msg = trim($e->getMessage());

            return Response::error($msg !== '' ? $msg : 'A operacao foi recusada.');
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
     * Allowlist explicito de Classe::metodo (mad.mcp.custom_tools_allowed[]).
     * Case-insensitive; aceita lista ou texto separado por virgula. Sem
     * allowlist configurada => NEGA tudo (fail-closed): a invocacao dinamica e
     * o sink mais perigoso do manifest.
     */
    private static function isAllowed(string $class, string $method): bool
    {
        $ini  = \Mad\Core\AppConfig::get();
        $list = $ini['mcp']['custom_tools_allowed'] ?? [];
        if (is_string($list)) {
            $list = explode(',', $list);
        }
        if (! is_array($list) || $list === []) {
            return false;
        }

        $pair = strtolower(ltrim($class, '\\') . '::' . $method);
        foreach ($list as $entry) {
            if (strtolower(ltrim(trim((string) $entry), '\\')) === $pair) {
                return true;
            }
        }
        return false;
    }

    /**
     * Converte o argumento ao tipo canonico do param. Tipo desconhecido passa
     * cru (manifest escrito a mao, comportamento antigo).
     *
     * @return array{0: bool, 1: mixed} [ok, valor | mensagem de erro]
     */
    private static function coerce(mixed $val, ?string $type): array
    {
        switch ($type) {
            case 'integer':
                if (is_int($val)) {
                    return [true, $val];
                }
                if (is_float($val) && floor($val) === $val) {
                    return [true, (int) $val];
                }
                if (is_string($val) && preg_match('/^\s*-?\d+\s*$/', $val) === 1) {
                    return [true, (int) trim($val)];
                }

                return [false, 'deve ser um numero inteiro.'];

            case 'number':
                if (is_int($val) || is_float($val)) {
                    return [true, $val];
                }
                if (is_string($val)) {
                    $s = trim($val);
                    // aceita "12,50" (decimal com virgula) sem milhar
                    if (preg_match('/^-?\d+,\d+$/', $s) === 1) {
                        $s = str_replace(',', '.', $s);
                    }
                    if (is_numeric($s)) {
                        return [true, $s + 0];
                    }
                }

                return [false, 'deve ser um numero.'];

            case 'boolean':
                if (is_bool($val)) {
                    return [true, $val];
                }
                if (is_int($val) || is_string($val)) {
                    $s = strtolower(trim((string) $val));
                    if (in_array($s, ['1', 'true', 'sim', 's', 'yes', 'on'], true)) {
                        return [true, true];
                    }
                    if (in_array($s, ['0', 'false', 'nao', 'não', 'n', 'no', 'off'], true)) {
                        return [true, false];
                    }
                }

                return [false, 'deve ser verdadeiro ou falso.'];

            case 'json':
                if (is_array($val)) {
                    return [true, $val];
                }
                if (is_object($val)) {
                    return [true, json_decode((string) json_encode($val), true)];
                }
                if (is_string($val)) {
                    $decoded = json_decode($val, true);
                    if (is_array($decoded)) {
                        return [true, $decoded];
                    }
                }

                return [false, 'deve ser uma lista ou um objeto JSON.'];

            case 'string':
                if (is_scalar($val)) {
                    return [true, (string) $val];
                }

                return [true, (string) json_encode($val, JSON_UNESCAPED_UNICODE)];

            default:
                return [true, $val];
        }
    }
}
