<?php

namespace Mad\Util;

/**
 * IdGenerator — geradores de identificadores unicos modernos.
 *
 * Suporta:
 *  - uuid4    : UUID v4 (RFC 9562) — random, nao-sortable
 *  - uuid7    : UUID v7 (RFC 9562) — timestamp-first, sortable, monotonic
 *  - ulid     : ULID Crockford Base32, 26 chars, sortable
 *  - tsid     : Time-Sorted ID 64 bits, cabe em BIGINT
 *  - cuid2    : Collision-resistant, secure, sem leak de timestamp
 *  - nanoid   : URL-safe, 21 chars, 126 bits entropy
 *  - snowflake: Twitter-style 64 bits (ts + node + seq)
 */
class IdGenerator
{
    /** Epoch customizado: 2020-01-01 00:00:00 UTC em ms */
    private const CUSTOM_EPOCH = 1577836800000;

    /** Node ID pra Snowflake (configuravel via setSnowflakeNode) */
    private static int $snowflakeNode = 0;

    /**
     * UUID v4 (RFC 9562) — totalmente random.
     */
    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122

        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' .
               substr($hex, 8, 4) . '-' .
               substr($hex, 12, 4) . '-' .
               substr($hex, 16, 4) . '-' .
               substr($hex, 20, 12);
    }

    /**
     * UUID v7 (RFC 9562) — timestamp ms + monotonic counter + random.
     * Sortable, ideal pra indices clustered (MySQL InnoDB, SQL Server).
     */
    public static function uuid7(): string
    {
        static $lastTs = 0;
        static $counter = 0;

        $ts = (int) (microtime(true) * 1000);

        if ($ts <= $lastTs) {
            $counter = ($counter + 1) & 0x0FFF;
            $ts = $lastTs;
        } else {
            $counter = random_int(0, 0x0FFF);
            $lastTs = $ts;
        }

        $time_hex = str_pad(dechex($ts), 12, '0', STR_PAD_LEFT);

        // Bloco 3: version (0x7) + 12 bits monotonic counter
        $randA = 0x7000 | ($counter & 0x0FFF);

        // Bloco 4: variant (10) + 14 bits random
        $randBytes = random_bytes(2);
        $randB = ((ord($randBytes[0]) & 0x3F) | 0x80) << 8 | ord($randBytes[1]);

        return sprintf(
            '%s-%s-%04x-%04x-%s',
            substr($time_hex, 0, 8),
            substr($time_hex, 8, 4),
            $randA,
            $randB,
            bin2hex(random_bytes(6))
        );
    }

    /**
     * ULID — 26 chars Crockford Base32, sortable, monotonic.
     * Spec: https://github.com/ulid/spec
     */
    public static function ulid(): string
    {
        static $lastTs = 0;
        static $lastRandom = '';

        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $ts = (int) (microtime(true) * 1000);

        // 48 bits ts -> 10 chars base32
        $tsChars = '';
        $tmp = $ts;
        for ($i = 0; $i < 10; $i++) {
            $tsChars = $alphabet[$tmp & 0x1F] . $tsChars;
            $tmp = intdiv($tmp, 32);
        }

        // 80 bits random -> 16 chars base32
        if ($ts === $lastTs && $lastRandom !== '') {
            $lastRandom = self::ulidIncrement($lastRandom, $alphabet);
        } else {
            $randomChars = '';
            for ($i = 0; $i < 16; $i++) {
                $randomChars .= $alphabet[random_int(0, 31)];
            }
            $lastRandom = $randomChars;
            $lastTs = $ts;
        }

        return $tsChars . $lastRandom;
    }

    private static function ulidIncrement(string $random, string $alphabet): string
    {
        $chars = str_split($random);
        for ($i = count($chars) - 1; $i >= 0; $i--) {
            $idx = strpos($alphabet, $chars[$i]);
            if ($idx < 31) {
                $chars[$i] = $alphabet[$idx + 1];
                return implode('', $chars);
            }
            $chars[$i] = $alphabet[0];
        }
        // Overflow improvavel: regenera
        $new = '';
        for ($i = 0; $i < 16; $i++) {
            $new .= $alphabet[random_int(0, 31)];
        }
        return $new;
    }

    /**
     * TSID — 64 bits (cabe em BIGINT). 42 bits ts | 22 bits sequence.
     * Throughput: 4.194.304 IDs/ms por processo.
     */
    public static function tsid(): string
    {
        static $lastTs = 0;
        static $sequence = 0;

        $ts = (int) (microtime(true) * 1000) - self::CUSTOM_EPOCH;

        if ($ts === $lastTs) {
            $sequence = ($sequence + 1) & 0x3FFFFF; // 22 bits
            if ($sequence === 0) {
                // Espera proximo ms
                while (((int) (microtime(true) * 1000) - self::CUSTOM_EPOCH) <= $ts) {
                    usleep(100);
                }
                $ts = (int) (microtime(true) * 1000) - self::CUSTOM_EPOCH;
            }
        } else {
            $sequence = random_int(0, 0xFFF); // start random
            $lastTs = $ts;
        }

        // 42 bits ts | 22 bits seq
        $tsid = ($ts << 22) | $sequence;

        return (string) $tsid;
    }

    /**
     * CUID2 — collision-resistant, secure, sem leak de timestamp legivel.
     * Output: hash hex (default 24 chars), unguessable.
     */
    public static function cuid2(int $length = 24): string
    {
        $length = max(8, min(128, $length));

        // Componentes:
        // - prefix letter (a-z)
        // - timestamp ms em base36
        // - counter (incremento por processo)
        // - fingerprint (random pid-ish)
        // - random salt
        static $counter = 0;
        $counter = ($counter + 1) & 0xFFFFFF;

        $prefix     = chr(random_int(ord('a'), ord('z')));
        $time       = base_convert((string)((int)(microtime(true) * 1000)), 10, 36);
        $count      = base_convert((string) $counter, 10, 36);
        $fingerprint = bin2hex(random_bytes(4));
        $salt       = bin2hex(random_bytes(16));

        $combined = $prefix . $time . $count . $fingerprint . $salt;

        // Hash SHA3-512, pega N chars (sem o primeiro pra preservar prefix letter)
        $hash = hash('sha3-512', $combined);

        return $prefix . substr($hash, 0, $length - 1);
    }

    /**
     * NanoID — 21 chars URL-safe (default), 126 bits entropy.
     * Spec: https://github.com/ai/nanoid
     */
    public static function nanoid(int $size = 21): string
    {
        $alphabet = '_-0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $alphabetSize = 64;
        $id = '';
        for ($i = 0; $i < $size; $i++) {
            $id .= $alphabet[random_int(0, $alphabetSize - 1)];
        }
        return $id;
    }

    /**
     * Snowflake (Twitter-style) — 64 bits: 41 ts | 10 node | 12 seq.
     * Configurar node ID via setSnowflakeNode() em multi-instance.
     */
    public static function snowflake(): string
    {
        static $lastTs = 0;
        static $sequence = 0;

        $ts = (int) (microtime(true) * 1000) - self::CUSTOM_EPOCH;

        if ($ts === $lastTs) {
            $sequence = ($sequence + 1) & 0xFFF; // 12 bits
            if ($sequence === 0) {
                // Espera proximo ms
                while (((int) (microtime(true) * 1000) - self::CUSTOM_EPOCH) <= $ts) {
                    usleep(100);
                }
                $ts = (int) (microtime(true) * 1000) - self::CUSTOM_EPOCH;
            }
        } else {
            $sequence = 0;
            $lastTs = $ts;
        }

        // 41 bits ts | 10 bits node | 12 bits seq = 63 bits (positivo signed)
        $id = ($ts << 22) | ((self::$snowflakeNode & 0x3FF) << 12) | $sequence;

        return (string) $id;
    }

    /**
     * Configura node ID pra Snowflake em multi-instance (0-1023).
     */
    public static function setSnowflakeNode(int $nodeId): void
    {
        self::$snowflakeNode = $nodeId & 0x3FF;
    }

    /**
     * Dispatcher principal — gera ID conforme policy.
     */
    public static function generate(string $policy): string
    {
        return match ($policy) {
            'uuid', 'uuid4' => self::uuid4(),
            'uuid7'         => self::uuid7(),
            'ulid'          => self::ulid(),
            'tsid'          => self::tsid(),
            'cuid2'         => self::cuid2(),
            'nanoid'        => self::nanoid(),
            'snowflake'     => self::snowflake(),
            default         => throw new \InvalidArgumentException("Unknown ID policy: {$policy}"),
        };
    }
}
