<?php

namespace Mad\Support;

/**
 * SessionClosure — a closure de um atributo (`:where="$that->filtro()"`) num
 * componente que guarda a config na sessão (`<mad-pdv>`, `<mad-org-chart>`).
 *
 * Código não viaja na sessão: no padrão do app ela é gravada em JSON, e um
 * objeto-closure voltava de lá como `{}` — o filtro simplesmente deixava de
 * valer nas ações, sem erro. No `<mad-pdv>` o que vai para a sessão é um
 * MARCADOR; a closure de verdade o componente pede de novo à tela, que é quem
 * a escreve ({@see \Mad\Pdv\MadPdvComponent::hydrate()}).
 *
 *   • {@see fingerprint()} — resumo ESTÁVEL da closure (arquivo, linhas,
 *     valores capturados). O resumo do `serialize()` levava o número do objeto
 *     na memória: mudava a cada render, e a chave da config mudava junto.
 *   • {@see marker()} / {@see isMarker()} — o que fica na sessão no lugar dela.
 *   • {@see denyAll()} — o filtro que vale enquanto a closure não voltou:
 *     nenhuma linha. Filtro perdido nunca pode virar "sem filtro".
 */
final class SessionClosure
{
    /** Prefixo do marcador gravado na sessão no lugar da closure. */
    public const MARK = '@closure:';

    /**
     * Resumo estável da closure: o mesmo a cada render do mesmo código com os
     * mesmos valores capturados; diferente quando um deles muda.
     */
    public static function fingerprint(\Closure $closure, ?object $host = null): string
    {
        try {
            $ref   = new \ReflectionFunction($closure);
            $dono  = $ref->getClosureThis();
            $parts = [
                (string) $ref->getFileName(),
                (int) $ref->getStartLine(),
                (int) $ref->getEndLine(),
                $ref->getClosureScopeClass()?->getName(),
                self::normalize($ref->getClosureUsedVariables(), $host, 0),
                // `$this` que é a tela não entra: a closure volta ligada à tela viva.
                $dono === null || $dono === $host ? null : self::normalize($dono, $host, 0),
            ];

            return md5((string) json_encode($parts, JSON_PARTIAL_OUTPUT_ON_ERROR));
        } catch (\Throwable $e) {
            return md5(spl_object_hash($closure));
        }
    }

    /** O texto que ocupa o lugar da closure na sessão (e na chave da config). */
    public static function marker(\Closure $closure, ?object $host = null): string
    {
        return self::MARK . self::fingerprint($closure, $host);
    }

    public static function isMarker(mixed $value): bool
    {
        return is_string($value) && str_starts_with($value, self::MARK);
    }

    /** Filtro de consulta que não deixa passar nenhuma linha. */
    public static function denyAll(): \Closure
    {
        return static function ($query): void {
            $query->whereRaw('1 = 0');
        };
    }

    /** Valor capturado reduzido ao que o identifica, sem depender de endereço de objeto. */
    private static function normalize(mixed $value, ?object $host, int $depth): mixed
    {
        if ($value === null || is_scalar($value)) {
            return $value;
        }
        if ($depth >= 4) {
            return is_object($value) ? get_class($value) : gettype($value);
        }
        if (is_array($value)) {
            $saida = [];
            foreach ($value as $k => $v) {
                $saida[$k] = self::normalize($v, $host, $depth + 1);
            }

            return $saida;
        }
        if ($value instanceof \Closure) {
            return ['closure' => self::fingerprint($value, $host)];
        }
        if ($value === $host) {
            return '@host';
        }
        if ($value instanceof \BackedEnum) {
            return [get_class($value), $value->value];
        }
        if ($value instanceof \Stringable && ! method_exists($value, 'getKey')) {
            return [get_class($value), (string) $value];
        }
        if (is_object($value)) {
            // Registro: classe + chave. Outro objeto: classe + o estado público.
            if (method_exists($value, 'getKey')) {
                try {
                    return [get_class($value), $value->getKey()];
                } catch (\Throwable $e) {
                    return get_class($value);
                }
            }

            $publico = [];
            foreach ((new \ReflectionObject($value))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isStatic() || ! $prop->isInitialized($value)) {
                    continue;
                }
                $publico[$prop->getName()] = self::normalize($prop->getValue($value), $host, $depth + 1);
            }

            return [get_class($value), $publico];
        }

        return gettype($value);
    }
}
