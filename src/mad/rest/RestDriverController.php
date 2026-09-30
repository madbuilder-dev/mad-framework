<?php

namespace Mad\Rest;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mad\Database\SchemaIntrospector;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Driver REST — endpoint que vive DENTRO do app gerado e faz proxy do SQL
 * contra o próprio banco do app (sem abrir porta nem VPN). Autenticado por HMAC
 * (middleware RestDriverHmac, que injeta `mad_rest_key`). Toda resposta é
 * ASSINADA com o segredo da chave p/ o builder verificar autenticidade.
 *
 * Ações: ping · introspect · run · update · plan. A conexão base e o modo
 * read-only vêm da entrada da chave (keystore), nunca do request. O request
 * PODE apontar um `database` alvo, mas SÓ um banco DO PRÓPRIO app (mesmo
 * prefixo `app_{uuid}_*` — adicionais/tenants), validado por RestTargetDatabase
 * (fail-closed) + backstop de CONNECT cross-tenant no Postgres. Um app nunca
 * alcança o banco de outro app.
 */
class RestDriverController
{
    /** Conexão efêmera usada quando o request aponta um database irmão. */
    private const TARGET_CONNECTION = '__mad_rest_target';

    public function __construct(
        private readonly SchemaIntrospector $introspector = new SchemaIntrospector,
        private readonly RestDriverRunner $runner = new RestDriverRunner,
        private readonly RestDriverSigner $signer = new RestDriverSigner,
    ) {}

    public function ping(Request $request): JsonResponse
    {
        $key = $this->key($request);

        // Além do engine/versão, o ping publica o catálogo de bancos do app
        // (deduplicado) + a conexão/banco em uso — é o que alimenta o seletor
        // de banco do Database Manager no builder.
        return $this->handle($request, function ($pdo) use ($request, $key) {
            $base = $this->baseConnection($request, $key);

            return $this->runner->ping($pdo) + [
                'connection' => $base,
                'database' => RestConnectionCatalog::databaseLabel($base),
                'connections' => RestConnectionCatalog::list((string) ($key['connection'] ?? '')),
            ];
        });
    }

    public function introspect(Request $request): JsonResponse
    {
        $counts = (bool) ($request->json('counts') ?? true);

        return $this->handle($request, fn ($pdo) => $this->introspector->introspect($pdo, $counts));
    }

    public function run(Request $request): JsonResponse
    {
        $sql = (string) $request->json('sql', '');
        $key = $this->key($request);

        return $this->handle($request, fn ($pdo) => $this->runner->run($pdo, $sql, (bool) ($key['read_only'] ?? false)));
    }

    public function plan(Request $request): JsonResponse
    {
        $sql = (string) $request->json('sql', '');
        $key = $this->key($request);

        return $this->handle($request, fn ($pdo) => $this->runner->explain($pdo, $sql, (bool) ($key['read_only'] ?? false)));
    }

    public function update(Request $request): JsonResponse
    {
        $key = $this->key($request);
        $table = (string) $request->json('table', '');
        $edits = (array) $request->json('edits', []);

        return $this->handle($request, fn ($pdo) => $this->runner->updateRows($pdo, $table, $edits, (bool) ($key['read_only'] ?? false)));
    }

    /** Resolve PDO da conexão da chave, executa $fn, e devolve resposta ASSINADA. */
    private function handle(Request $request, callable $fn): JsonResponse
    {
        $key = $this->key($request);

        try {
            $pdo = $this->targetPdo($request, $key);
            $payload = $fn($pdo);

            return $this->signed($request, $key, $payload, 200);
        } catch (Throwable $e) {
            return $this->signed($request, $key, ['error' => $e->getMessage()], 422);
        }
    }

    /**
     * PDO da conexão base da chave, opcionalmente apontando para OUTRO database
     * do MESMO app (adicional/tenant). O `database` do request é aceito SÓ se
     * passar pelo RestTargetDatabase (mesmo prefixo do banco base); qualquer
     * outro nome é fail-closed. Reusa host/porta/credenciais da conexão base —
     * só troca o database.
     *
     * @param  array<string,mixed>  $key
     */
    private function targetPdo(Request $request, array $key): PDO
    {
        $base = $this->baseConnection($request, $key);
        $ownDatabase = (string) config("database.connections.{$base}.database", '');

        $requested = $request->json('database');
        $target = RestTargetDatabase::resolve($ownDatabase, is_string($requested) ? $requested : null);

        if ($target === null) {
            return DB::connection($base)->getPdo();
        }

        $cfg = config("database.connections.{$base}");
        if (! is_array($cfg)) {
            throw new RuntimeException('Conexão base do Driver REST ausente.');
        }

        // Sobrescreve a config efêmera A CADA request (sem estado velho sob
        // Octane/FrankenPHP) e reconecta antes de usar.
        $cfg['database'] = $target;
        config(['database.connections.'.self::TARGET_CONNECTION => $cfg]);
        DB::purge(self::TARGET_CONNECTION);

        return DB::connection(self::TARGET_CONNECTION)->getPdo();
    }

    /**
     * Conexão Laravel base do request. O request PODE apontar uma `connection`
     * (no corpo ASSINADO) — seletor de banco do Database Manager —, validada
     * fail-closed:
     *   - chave com conexão pinada no keystore → só a pinada;
     *   - a conexão pedida tem que EXISTIR no config do app (allowlist);
     *   - a conexão efêmera interna (__mad_rest_target) nunca é endereçável.
     * Sem `connection` no request → conexão da chave, senão a default.
     */
    private function baseConnection(Request $request, array $key): string
    {
        $pinned = (string) ($key['connection'] ?? '');

        $requested = $request->json('connection');
        $requested = is_string($requested) ? trim($requested) : '';

        if ($requested === '' || $requested === $pinned) {
            return $pinned !== '' ? $pinned : RestDriver::defaultConnection();
        }

        if ($pinned !== '') {
            throw new RuntimeException('Conexão fora do escopo desta chave.');
        }

        if ($requested === self::TARGET_CONNECTION
            || ! is_array(config("database.connections.{$requested}"))) {
            throw new RuntimeException('Conexão desconhecida.');
        }

        return $requested;
    }

    /** @return array<string,mixed> */
    private function key(Request $request): array
    {
        $k = $request->attributes->get('mad_rest_key');

        return is_array($k) ? $k : [];
    }

    /** Serializa + assina a resposta (X-Mad-Timestamp/Nonce/Signature). */
    private function signed(Request $request, array $key, array $payload, int $status): JsonResponse
    {
        // Corpo verbatim: o builder verifica a assinatura sobre ESTES bytes,
        // então a resposta DEVE conter exatamente este string (fromJsonString).
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $resp = JsonResponse::fromJsonString((string) $body, $status);

        $secret = (string) ($key['secret'] ?? '');
        $keyId = (string) ($key['key_id'] ?? '');
        if ($secret !== '' && $keyId !== '') {
            $ts = time();
            $nonce = bin2hex(random_bytes(16));
            $sig = $this->signer->responseSignature($secret, $keyId, $ts, $nonce, (string) $body);
            $resp->headers->set(RestDriverSigner::HEADER_TS, (string) $ts);
            $resp->headers->set(RestDriverSigner::HEADER_NONCE, $nonce);
            $resp->headers->set(RestDriverSigner::HEADER_SIGNATURE, $sig);
        }

        return $resp;
    }
}
