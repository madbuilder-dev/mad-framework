<?php

namespace Mad\Rest;

/**
 * MadAppIdentity — identidade ÚNICA e ESTÁVEL desta instância do app gerado
 * (o "console" que conecta no Database Manager da nuvem).
 *
 * O app_uuid é gerado UMA vez e persistido em storage/app/mad/app-uuid (chmod
 * 600, fora do git), igual ao keystore do REST driver. Sobrevive a restart/deploy
 * (mesmo arquivo) → a nuvem reconhece a mesma instância pelo mesmo uuid.
 *
 * É o id de membro do canal presence (presence-dbm.{project}) — a nuvem vê quem
 * está online em tempo real e identifica cada cliente unicamente. O paired_key_id
 * (do keystore) liga essa identidade à conexão pareada na nuvem.
 */
final class MadAppIdentity
{
    private static ?string $cached = null;

    public static function uuid(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }

        $path = self::path();
        if (is_file($path)) {
            $u = trim((string) @file_get_contents($path));
            if ($u !== '') {
                return self::$cached = $u;
            }
        }

        $u   = self::genUuidV4();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($path, $u, LOCK_EX);
        @chmod($path, 0600);

        return self::$cached = $u;
    }

    /**
     * Token de presence que o iframe apresenta no /broadcasting/auth da NUVEM
     * para entrar no canal presence-dbm.{project}. Assinado com o paired_secret
     * (keystore) — o segredo NUNCA sai daqui; a nuvem reverifica com a cópia que
     * ela tem cifrada. Vazio se o app não estiver pareado.
     *
     * Formato: base64url(json{app_uuid, paired_key_id, exp}) . "." . hmac_sha256(payload, secret)
     */
    public static function presenceToken(int $ttlSeconds = 120): string
    {
        $keyId = self::pairedKeyId();
        if ($keyId === '') {
            return '';
        }
        $entry  = (new RestDriverKeyStore())->find($keyId);
        $secret = (string) ($entry['secret'] ?? '');
        if ($secret === '') {
            return '';
        }

        $claims  = ['app_uuid' => self::uuid(), 'paired_key_id' => $keyId, 'exp' => time() + max(30, $ttlSeconds)];
        $payload = self::b64url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
        $sig     = hash_hmac('sha256', $payload, $secret);

        return $payload . '.' . $sig;
    }

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** Primeiro keyId do keystore do REST driver (paired_key_id), ou '' se não pareado. */
    public static function pairedKeyId(): string
    {
        try {
            $keys = array_keys((new RestDriverKeyStore())->all());

            return (string) ($keys[0] ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private static function path(): string
    {
        $base = function_exists('storage_path') ? storage_path('app/mad') : sys_get_temp_dir() . '/mad';

        return $base . '/app-uuid';
    }

    private static function genUuidV4(): string
    {
        $d = random_bytes(16);
        $d[6] = chr((ord($d[6]) & 0x0f) | 0x40); // versão 4
        $d[8] = chr((ord($d[8]) & 0x3f) | 0x80); // variante
        $hex  = bin2hex($d);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
