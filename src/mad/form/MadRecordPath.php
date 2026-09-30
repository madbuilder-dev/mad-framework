<?php

namespace Mad\Form;

/**
 * MadRecordPath — resolve um "source" (coluna · caminho de relação · template)
 * a partir de um registro (model Eloquent, stdClass ou array).
 *
 * Fonte da verdade compartilhada entre o auto-fill do dbseek
 * (Mad\Seek\MadSeekGrid) e o auto-fill dos selects de banco
 * (Mad\Service\MadAutoFillService). O código nasceu no MadSeekGrid; foi
 * extraído aqui para reuso sem duplicação.
 *
 * Formatos aceitos em resolve():
 *   'nome'                                      → $record->nome (path simples)
 *   'cidade->estado->nome'                      → $record->cidade->estado->nome (relação)
 *   '{cidade->nome} - {cidade->estado->sigla}'  → template com múltiplos paths
 *
 * Retorna SEMPRE string ('' quando o caminho não resolve).
 */
class MadRecordPath
{
    /**
     * Resolve o source de um campo.
     * Se contém '{', é template — substitui cada {path}; senão, path direto.
     */
    public static function resolve(object $record, string $source): string
    {
        if (strpos($source, '{') !== false) {
            return preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($record) {
                return self::resolvePath($record, trim($m[1]));
            }, $source);
        }

        return self::resolvePath($record, $source);
    }

    /**
     * Resolve um caminho de atributo/relacionamento no registro.
     *
     * Ex: 'nome'                  → $record->nome
     *     'cidade->estado->nome'  → $record->cidade->estado->nome
     */
    public static function resolvePath(object $record, string $path): string
    {
        $parts   = explode('->', $path);
        $current = $record;

        foreach ($parts as $part) {
            if ($current === null) return '';
            if (is_object($current)) {
                $current = $current->{trim($part)} ?? null;
            } elseif (is_array($current)) {
                $current = $current[trim($part)] ?? null;
            } else {
                return '';
            }
        }

        return $current !== null ? (string) $current : '';
    }
}
