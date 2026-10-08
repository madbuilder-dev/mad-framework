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
 * read-only vêm da entrada da chave (keystore). O request PODE apontar um
 * `database` alvo, mas SÓ um banco DO PRÓPRIO app (mesmo prefixo
 * `app_{uuid}_*` — adicionais/tenants), validado por RestTargetDatabase
 * (fail-closed) + backstop de CONNECT cross-tenant no Postgres. Um app nunca
 * alcança o banco de outro app.
 *
 * Somente leitura: a CHAVE manda; o corpo assinado pode trazer
 * `read_only: true` só para RESTRINGIR uma chave de escrita (nunca libera). Em
 * somente leitura o SQL do usuário (run/plan) roda numa conexão PRÓPRIA, aberta
 * com as opções do modo e descartada no fim — ver RestDriverReadOnlySession.
 */
class RestDriverController
{
    /** Conexão efêmera usada quando o request aponta um database irmão. */
    private const TARGET_CONNECTION = '__mad_rest_target';

    /** Conexão efêmera do SQL do usuário em modo somente leitura. */
    private const READONLY_CONNECTION = '__mad_rest_readonly';

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
        $readOnly = $this->readOnly($request);

        return $this->handle($request, fn ($pdo) => $this->runner->run($pdo, $sql, $readOnly), $readOnly);
    }

    public function plan(Request $request): JsonResponse
    {
        $sql = (string) $request->json('sql', '');
        $readOnly = $this->readOnly($request);

        return $this->handle($request, fn ($pdo) => $this->runner->explain($pdo, $sql, $readOnly), $readOnly);
    }

    public function update(Request $request): JsonResponse
    {
        $table = (string) $request->json('table', '');
        $edits = (array) $request->json('edits', []);
        $readOnly = $this->readOnly($request);

        return $this->handle($request, fn ($pdo) => $this->runner->updateRows($pdo, $table, $edits, $readOnly));
    }

    /**
     * Modo somente leitura do request: o da CHAVE, ou a restrição pedida no
     * corpo ASSINADO (`read_only: true`) — é como a conexão marcada Somente
     * leitura no Studio passa a ser garantida pelo banco mesmo com uma chave de
     * escrita. O corpo só restringe: `read_only: false` não libera nada.
     */
    private function readOnly(Request $request): bool
    {
        return (bool) ($this->key($request)['read_only'] ?? false)
            || filter_var($request->json('read_only'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Resolve PDO da conexão da chave, executa $fn, e devolve resposta ASSINADA.
     *
     * @param  bool  $readOnlySql  $fn executa SQL do usuário em modo somente leitura:
     *                             recebe a conexão PRÓPRIA do modo, descartada no fim
     */
    private function handle(Request $request, callable $fn, bool $readOnlySql = false): JsonResponse
    {
        $key = $this->key($request);

        try {
            $pdo = $this->targetPdo($request, $key, $readOnlySql);
            $payload = $fn($pdo);

            return $this->signed($request, $key, $payload, 200);
        } catch (Throwable $e) {
            return $this->signed($request, $key, ['error' => $e->getMessage()], 422);
        } finally {
            if ($readOnlySql) {
                // Fecha a conexão: nada da sessão do usuário sobrevive ao request.
                DB::purge(self::READONLY_CONNECTION);
            }
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
    private function targetPdo(Request $request, array $key, bool $readOnlySql = false): PDO
    {
        $base = $this->baseConnection($request, $key);
        $ownDatabase = (string) config("database.connections.{$base}.database", '');

        $requested = $request->json('database');
        $target = RestTargetDatabase::resolve($ownDatabase, is_string($requested) ? $requested : null);

        if ($readOnlySql) {
            return $this->readOnlyPdo($base, $target);
        }

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
     * Conexão PRÓPRIA do SQL do usuário em modo somente leitura: mesmo
     * servidor/credenciais da conexão base (ou do database irmão), aberta com as
     * opções que só valem na abertura (MySQL sem vários comandos por envio,
     * arquivo SQLite só para leitura). A conexão do app nunca recebe SQL de uma
     * chave somente leitura — a exceção é o SQLite em memória, que só existe
     * nela (a trava do engine fica com o PRAGMA query_only).
     */
    private function readOnlyPdo(string $base, ?string $target): PDO
    {
        $cfg = config("database.connections.{$base}");
        if (! is_array($cfg)) {
            throw new RuntimeException('Conexão base do Driver REST ausente.');
        }
        if ($target !== null) {
            $cfg['database'] = $target;
        }

        $cfg = RestDriverReadOnlySession::connectionConfig($cfg);
        if ($cfg === null) {
            return DB::connection($base)->getPdo();
        }

        // Sobrescreve a config efêmera A CADA request (sem estado velho sob
        // Octane/FrankenPHP) e reconecta antes de usar.
        config(['database.connections.'.self::READONLY_CONNECTION => $cfg]);
        DB::purge(self::READONLY_CONNECTION);

        return DB::connection(self::READONLY_CONNECTION)->getPdo();
    }

    /**
     * Conexão Laravel base do request. O request PODE apontar uma `connection`
     * (no corpo ASSINADO) — seletor de banco do Database Manager —, validada
     * fail-closed:
     *   - chave com conexão pinada no keystore → só a pinada;
     *   - a conexão pedida tem que EXISTIR no config do app (allowlist);
     *   - as conexões efêmeras internas (__mad_rest_target, __mad_rest_readonly)
     *     nunca são endereçáveis.
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

        if (in_array($requested, [self::TARGET_CONNECTION, self::READONLY_CONNECTION], true)
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
