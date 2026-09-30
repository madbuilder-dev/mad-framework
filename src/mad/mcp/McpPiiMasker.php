<?php

namespace Mad\Mcp;

/**
 * McpPiiMasker
 *
 * Mascara campos PII (pii=true) e remove campos nao expostos (expose=false) de
 * TODO retorno (Contrato secao 6). Email -> "local@***"; demais PII -> "***".
 */
final class McpPiiMasker
{
    /** @var array<string,bool> nome -> expose */
    private array $expose = [];
    /** @var array<string,bool> nome -> pii */
    private array $pii = [];

    /** @param array<int,array<string,mixed>> $fields specs do manifest p/ a tabela */
    public function __construct(array $fields)
    {
        foreach ($fields as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $this->expose[$name] = (bool) ($f['expose'] ?? false);
            $this->pii[$name]    = (bool) ($f['pii'] ?? false);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function maskRow(array $row): array
    {
        $out = [];
        foreach ($row as $col => $value) {
            // Se a coluna foi declarada e NAO e exposta, remove.
            if (array_key_exists($col, $this->expose) && $this->expose[$col] === false) {
                continue;
            }
            if (! empty($this->pii[$col]) && $value !== null && $value !== '') {
                $value = $this->maskValue((string) $value);
            }
            $out[$col] = $value;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public function maskRows(array $rows): array
    {
        return array_map(fn (array $r): array => $this->maskRow($r), $rows);
    }

    private function maskValue(string $value): string
    {
        if (str_contains($value, '@')) {
            $local = strstr($value, '@', true);

            return ($local !== false ? $local : '') . '@***';
        }

        return '***';
    }
}
