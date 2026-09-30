<?php

namespace Mad\Service;

use Illuminate\Support\Facades\Schema;

/**
 * MadUploadIngest — ingestão de um arquivo enviado: grava no disco de uploads
 * e descreve/aplica os METADADOS no registro (path, nome original, bytes, mime,
 * disco).
 *
 * Por que existe: essa lógica nasceu privada dentro de `MadForm`
 * (`_processMultiFileUpload`), então só rodava no caminho `form->save($pai)`.
 * O `<mad-db-blocks>` (e os presets `<mad-comments>`/`<mad-attachments>`)
 * gravam item-a-item por ação própria, sem passar por `MadForm::_afterStore` —
 * e por isso não conseguiam receber arquivo. Extraído aqui, os dois caminhos
 * compartilham exatamente a mesma regra (nome sanitizado, extensão bloqueada,
 * metadados por convenção/prop).
 *
 * ⚠️ `MadForm` continua com a mesma API pública; internamente delega pra cá.
 */
class MadUploadIngest
{
    /**
     * Colunas de METADADO por papel, na ordem de preferência. Só preenche
     * coluna que EXISTE na tabela e que ainda está vazia — nunca inventa
     * coluna, nunca sobrescreve o que o dev setou.
     */
    public const META_COLUMNS = [
        'original' => ['original_name', 'nome_original', 'name_original'],
        'size'     => ['size', 'file_size', 'tamanho', 'bytes'],
        'mime'     => ['mime_type', 'mimetype', 'content_type', 'tipo_mime'],
        'disk'     => ['disk', 'storage_disk'],
    ];

    /** Prop explícita (kebab → camel) por papel. */
    public const META_PROPS = [
        'original' => 'originalNameColumn',
        'size'     => 'sizeColumn',
        'mime'     => 'mimeColumn',
        'disk'     => 'diskColumn',
    ];

    /** Extensões nunca aceitas (espelha o gate do MadForm). */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'sh', 'htaccess', 'htpasswd', 'env', 'ini',
    ];

    /**
     * Sanitiza nome vindo do usuário: sem path, sem null byte, sem extensão
     * executável, sem "double extension" (evil.php.jpg).
     */
    public static function sanitizeName(string $name): string
    {
        $clean = basename(str_replace(['\\', "\0"], ['/', ''], $name));
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        $clean = ltrim($clean, '.');
        if ($clean === '') {
            $clean = 'arquivo';
        }

        $parts = explode('.', $clean);
        foreach ($parts as $part) {
            if (in_array(strtolower($part), self::FORBIDDEN_EXTENSIONS, true)) {
                throw new \RuntimeException("Extensão de arquivo não permitida: {$clean}");
            }
        }

        return $clean;
    }

    /** Nome final no disco conforme o modo (`prefix` default, `unique`, `original`, `record`). */
    public static function buildFileName(string $originalName, string $mode = 'prefix', ?object $record = null): string
    {
        $clean = self::sanitizeName($originalName);
        $ext   = pathinfo($clean, PATHINFO_EXTENSION);
        $pk    = $record ? ($record->{$record->getKeyName()} ?? null) : null;
        $rand  = bin2hex(random_bytes(8));

        return match ($mode) {
            'unique'   => $rand . ($ext ? ".{$ext}" : ''),
            'original' => $clean,
            'record'   => ($pk ?? $rand) . '_' . $clean,
            default    => $rand . '_' . $clean,
        };
    }

    /**
     * Grava o arquivo temporário no disco de uploads e devolve a descrição
     * (path relativo + metadados) — sem tocar em banco.
     *
     * @param array{name:string,tmp_name:string,type?:string,size?:int} $file entrada de $_FILES
     * @return array{path:string,original:string,size:int,mime:string,disk:string}
     */
    public static function store(array $file, string $folder, string $fileNameMode = 'prefix', ?object $record = null): array
    {
        $original = (string) ($file['name'] ?? 'arquivo');
        $tmp      = (string) ($file['tmp_name'] ?? '');
        $fileName = self::buildFileName($original, $fileNameMode, $record);
        $path     = rtrim($folder, '/') . '/' . $fileName;

        MadUploadStorage::put($tmp, $path);

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 && is_file($tmp)) {
            $size = (int) filesize($tmp);
        }
        $mime = (string) ($file['type'] ?? '');
        if ($mime === '' && is_file($tmp) && function_exists('mime_content_type')) {
            $mime = (string) @mime_content_type($tmp);
        }

        return [
            'path'     => $path,
            'original' => $original,
            'size'     => $size,
            'mime'     => $mime,
            'disk'     => MadUploadStorage::diskName(),
        ];
    }

    /**
     * Aplica os metadados no registro: coluna explícita da prop vence; sem
     * prop, usa a convenção — e só quando a coluna EXISTE e está vazia.
     *
     * @param array{path:string,original:string,size:int,mime:string,disk:string} $meta
     * @param array<string,mixed> $props props do campo (originalNameColumn, sizeColumn, …)
     */
    public static function fillMeta(object $record, array $meta, array $props = []): void
    {
        $values = [
            'original' => $meta['original'] ?? '',
            'size'     => $meta['size'] ?? 0,
            'mime'     => $meta['mime'] ?? '',
            'disk'     => $meta['disk'] ?? '',
        ];

        foreach (self::META_COLUMNS as $role => $candidates) {
            $value = $values[$role] ?? '';
            if ($value === '' || $value === 0) {
                continue;
            }

            // A coluna mapeada na tag é um DESTINO A MAIS, não um substituto:
            // a tabela pode ter as duas (ex.: `filename` pra exibir + o
            // `original_name NOT NULL` da convenção). Preencher só a explícita
            // deixava a outra nula e o insert estourava.
            $explicit = trim((string) ($props[self::META_PROPS[$role]] ?? ''));
            if ($explicit !== '') {
                $record->$explicit = $value;
            }

            foreach ($candidates as $col) {
                if ($col === $explicit || !self::hasColumn($record, $col)) {
                    continue;
                }
                if (($record->$col ?? null) === null || $record->$col === '') {
                    $record->$col = $value;
                }
                break;
            }
        }
    }

    /** Colunas da tabela do registro, cacheadas por classe (1 query por request). */
    public static function hasColumn(object $record, string $column): bool
    {
        static $cache = [];
        $key = get_class($record);
        if (!isset($cache[$key])) {
            try {
                $cache[$key] = Schema::connection($record->getConnectionName())
                    ->getColumnListing($record->getTable());
            } catch (\Throwable $e) {
                // NÃO memoiza o erro. Gravar [] no $cache estático fazia uma
                // falha transitória de introspecção (reconnect, lock, timeout)
                // valer pelo REQUEST INTEIRO: com todas as colunas dadas como
                // inexistentes, fillMeta() deixa original_name/size/mime vazios
                // e _blockCloneForFile() não zera o `path` do clone — no upload
                // múltiplo o 2º arquivo herda o path do 1º e as duas linhas
                // apontam pro mesmo objeto (um arquivo é perdido).
                // Retornando sem cachear, a próxima chamada tenta de novo.
                error_log('[MadUploadIngest::hasColumn] introspecção de colunas '
                    . 'falhou para ' . $key . ' — metadados de upload podem sair '
                    . 'incompletos: ' . $e->getMessage());

                return false;
            }
        }

        return in_array($column, $cache[$key], true);
    }

    /**
     * Arquivos válidos de um campo em `$_FILES`, SEMPRE como lista de entradas
     * simples (`name`/`tmp_name`/`type`/`size`) — normaliza o formato
     * multi-arquivo do PHP (arrays paralelos).
     *
     * @return array<int,array{name:string,tmp_name:string,type:string,size:int}>
     */
    public static function filesFor(string $fieldName): array
    {
        $raw = $_FILES[$fieldName] ?? null;
        if (!is_array($raw) || empty($raw['tmp_name'])) {
            return [];
        }

        $out = [];
        if (is_array($raw['tmp_name'])) {
            foreach ($raw['tmp_name'] as $i => $tmp) {
                if ($tmp === '' || ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $out[] = [
                    'name'     => (string) ($raw['name'][$i] ?? 'arquivo'),
                    'tmp_name' => (string) $tmp,
                    'type'     => (string) ($raw['type'][$i] ?? ''),
                    'size'     => (int) ($raw['size'][$i] ?? 0),
                ];
            }

            return $out;
        }

        if (($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [];
        }

        return [[
            'name'     => (string) ($raw['name'] ?? 'arquivo'),
            'tmp_name' => (string) $raw['tmp_name'],
            'type'     => (string) ($raw['type'] ?? ''),
            'size'     => (int) ($raw['size'] ?? 0),
        ]];
    }
}
