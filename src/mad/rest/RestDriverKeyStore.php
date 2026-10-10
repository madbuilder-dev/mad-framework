<?php

namespace Mad\Rest;

use Mad\Security\StoredSecret;

/**
 * Armazena as chaves de pareamento do Driver REST FORA do código e do config
 * versionado — num JSON em `storage/app/mad/rest-driver.json` (gitignored,
 * chmod 600). Cada entrada: { secret, connection, read_only, label, created_at }.
 *
 * O SEGREDO é simétrico (HMAC) — o builder guarda a cópia cifrada. Aqui ele
 * fica só no disco do app do cliente, com permissão restrita, e CIFRADO com a
 * chave do app (`StoredSecret`): uma cópia do `storage/` que saia sem o `.env`
 * (backup, imagem) não leva o segredo. Arquivo antigo, em texto puro, continua
 * valendo e é cifrado na primeira leitura. Segredo cifrado com outra
 * `APP_KEY` volta '' (não configurado: o middleware recusa) e fica no arquivo
 * como está — volta a valer se a chave anterior for para `APP_PREVIOUS_KEYS`.
 *
 * ALÉM do JSON, uma chave ÚNICA pode vir do ENV (MAD_REST_DRIVER_KEY_ID +
 * MAD_REST_DRIVER_SECRET) — é como o app hospedado no MadCloud (MadCloud) é
 * pareado: o control plane injeta a chave no .env no deploy, sem escrever
 * arquivo no container. A chave do env é somente-leitura por padrão
 * (MAD_REST_DRIVER_READONLY=false libera escrita).
 */
class RestDriverKeyStore
{
    /** Onde o segredo é informado — o que o log mostra quando ele está ilegível. */
    private const LABEL = 'Driver REST › chave de pareamento';

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

    /** @return array<string,array<string,mixed>> keyId => entry (segredo em claro) */
    public function all(): array
    {
        $data = [];
        foreach ($this->fileEntries() as $keyId => $entry) {
            if (is_array($entry) && array_key_exists('secret', $entry)) {
                $entry['secret'] = StoredSecret::open((string) $entry['secret'], self::LABEL);
            }
            $data[$keyId] = $entry;
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
        $all = $this->fileEntries();
        $all[$keyId] = $this->sealEntry($entry);
        $this->write($all);
    }

    /**
     * Remove a chave do ARQUIVO. A chave do env (MadCloud) não mora nele — só
     * sai tirando as variáveis do `.env`.
     */
    public function revoke(string $keyId): bool
    {
        $all = $this->fileEntries();
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

    /**
     * Entradas como estão no ARQUIVO (segredo cifrado), sem a chave do env.
     * Segredo ainda em texto puro (arquivo de antes da cifragem) é cifrado e
     * regravado aqui, uma vez; sem permissão de escrita, segue como está.
     *
     * @return array<string,array<string,mixed>>
     */
    private function fileEntries(): array
    {
        if (! is_file($this->path)) {
            return [];
        }
        $raw = @file_get_contents($this->path);
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $plain = false;
        foreach ($decoded as $keyId => $entry) {
            if (is_array($entry) && StoredSecret::needsSealing(isset($entry['secret']) ? (string) $entry['secret'] : null)) {
                $decoded[$keyId] = $this->sealEntry($entry);
                $plain = true;
            }
        }
        if ($plain) {
            try {
                $this->write($decoded);
            } catch (\Throwable $e) {
                // Sem escrita (permissão): continua valendo em texto puro.
            }
        }

        return $decoded;
    }

    /**
     * Cifra o segredo em texto puro. Vazio ou já cifrado (inclusive com outra
     * chave) fica como está.
     *
     * @param array<string,mixed> $entry
     */
    private function sealEntry(array $entry): array
    {
        if (isset($entry['secret']) && StoredSecret::needsSealing((string) $entry['secret'])) {
            $entry['secret'] = StoredSecret::seal((string) $entry['secret']);
        }

        return $entry;
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
