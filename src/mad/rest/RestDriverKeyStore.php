<?php

namespace Mad\Rest;

/**
 * Armazena as chaves de pareamento do Driver REST FORA do código e do config
 * versionado — num JSON em `storage/app/mad/rest-driver.json` (gitignored,
 * chmod 600). Cada entrada: { secret, connection, read_only, label, created_at }.
 *
 * O SEGREDO é simétrico (HMAC) — o builder guarda a cópia cifrada. Aqui ele
 * fica só no disco do app do cliente, com permissão restrita.
 *
 * ALÉM do JSON, uma chave ÚNICA pode vir do ENV (MAD_REST_DRIVER_KEY_ID +
 * MAD_REST_DRIVER_SECRET) — é como o app hospedado no MadCloud (MadCloud) é
 * pareado: o control plane injeta a chave no .env no deploy, sem escrever
 * arquivo no container. A chave do env é somente-leitura por padrão
 * (MAD_REST_DRIVER_READONLY=false libera escrita).
 */
class RestDriverKeyStore
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?: $this->defaultPath();
    }

    /** @return array<string,mixed>|null Chave provinda do env (MadCloud), se houver. */
    private function envKey(): ?array
    {
        $keyId = getenv('MAD_REST_DRIVER_KEY_ID') ?: null;
        $secret = getenv('MAD_REST_DRIVER_SECRET') ?: null;
        if (! is_string($keyId) || ! is_string($secret) || $keyId === '' || $secret === '') {
            return null;
        }
        $readOnlyEnv = getenv('MAD_REST_DRIVER_READONLY');
        $readOnly = ! ($readOnlyEnv === 'false' || $readOnlyEnv === '0'); // default true
        $connection = getenv('MAD_REST_DRIVER_CONNECTION') ?: null;

        return ['key_id' => $keyId, 'entry' => [
            'secret' => $secret,
            'connection' => is_string($connection) ? $connection : null,
            'read_only' => $readOnly,
            'label' => 'madcloud-env',
            'created_at' => gmdate('c'),
        ]];
    }

    private function defaultPath(): string
    {
        $base = function_exists('storage_path') ? storage_path('app/mad') : sys_get_temp_dir() . '/mad';
        return $base . '/rest-driver.json';
    }

    /** @return array<string,array<string,mixed>> keyId => entry */
    public function all(): array
    {
        $data = [];
        if (is_file($this->path)) {
            $raw = @file_get_contents($this->path);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $data = $decoded;
                }
            }
        }
        // Chave do env (MadCloud) tem precedência — não é sobreposta pelo JSON.
        $env = $this->envKey();
        if ($env !== null) {
            $data[$env['key_id']] = $env['entry'];
        }

        return $data;
    }

    /** @return array<string,mixed>|null */
    public function find(string $keyId): ?array
    {
        $env = $this->envKey();
        if ($env !== null && $env['key_id'] === $keyId) {
            return $env['entry'];
        }

        return $this->all()[$keyId] ?? null;
    }

    public function put(string $keyId, array $entry): void
    {
        $all = $this->all();
        $all[$keyId] = $entry;
        $this->write($all);
    }

    public function revoke(string $keyId): bool
    {
        $all = $this->all();
        if (! isset($all[$keyId])) {
            return false;
        }
        unset($all[$keyId]);
        $this->write($all);
        return true;
    }

    /**
     * Gera uma nova chave (keyId + secret) e persiste.
     *
     * @return array{key_id:string,secret:string,entry:array<string,mixed>}
     */
    public function generate(string $connection, bool $readOnly = false, string $label = ''): array
    {
        $keyId = 'mrk_' . bin2hex(random_bytes(6));
        $secret = bin2hex(random_bytes(32)); // 256-bit
        $entry = [
            'secret' => $secret,
            'connection' => $connection,
            'read_only' => $readOnly,
            'label' => $label,
            'created_at' => gmdate('c'),
        ];
        $this->put($keyId, $entry);

        return ['key_id' => $keyId, 'secret' => $secret, 'entry' => $entry];
    }

    private function write(array $all): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $this->path . '.tmp';
        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new \RuntimeException('Não foi possível gravar o keystore do Driver REST.');
        }
        @chmod($tmp, 0600);
        @rename($tmp, $this->path);
        @chmod($this->path, 0600);
    }
}
