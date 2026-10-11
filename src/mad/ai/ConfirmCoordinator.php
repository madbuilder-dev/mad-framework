<?php

namespace Mad\Ai;

/**
 * ConfirmCoordinator — guarda as escritas pendentes de confirmacao por sessao.
 *
 * Fluxo: no turno N a render tool confirm_action (ou o self-gate de uma write
 * tool) registra {tool, args, editable} sob um id e emite o bloco confirm. O
 * controller persiste isto no ConversationStore. No turno N+1, com
 * confirm:{id,approved,values}, o controller resolve o id e executa a escrita
 * de forma deterministica.
 *
 * `editable` e a allowlist dos argumentos que o usuario pode corrigir no
 * cartao antes de confirmar (campo com `name` + `input`). So essas chaves,
 * convertidas para o tipo declarado, entram por cima dos args — qualquer outra
 * chave em `values` e ignorada (o cliente nao injeta argumento novo nem troca o
 * registro alvo).
 *
 * Holder em memoria — a persistencia entre requests e feita pelo controller
 * (seed na carga, all() no save).
 */
final class ConfirmCoordinator
{
    /** Tipos de campo editavel aceitos no cartao (espelho do front). */
    public const INPUTS = ['text', 'number', 'date', 'select', 'textarea'];

    /** Teto de um valor de texto editado. */
    private const MAX_TEXT = 5000;

    /**
     * @var array<string, array{tool: string, args: array<string, mixed>, editable: array<string, array{input: string, options?: list<string>, cast?: string}>}>
     */
    private array $pending = [];

    /** Semeia a partir do estado persistido. */
    public function seed(array $pending): void
    {
        foreach ($pending as $id => $p) {
            if (is_array($p) && isset($p['tool'])) {
                $this->pending[(string) $id] = [
                    'tool'     => (string) $p['tool'],
                    'args'     => is_array($p['args'] ?? null) ? $p['args'] : [],
                    'editable' => self::cleanEditable($p['editable'] ?? null),
                ];
            }
        }
    }

    public function newId(): string
    {
        return 'confirm-' . substr(md5(uniqid('', true)), 0, 12);
    }

    /**
     * @param array<string, mixed>                                                          $args
     * @param array<string, array{input: string, options?: list<string>, cast?: string}> $editable
     */
    public function stash(string $id, string $tool, array $args, array $editable = []): void
    {
        if ($id === '' || $tool === '') {
            return;
        }
        $this->pending[$id] = ['tool' => $tool, 'args' => $args, 'editable' => self::cleanEditable($editable)];
    }

    /** @return array{tool: string, args: array<string, mixed>, editable: array<string, array{input: string, options?: list<string>, cast?: string}>}|null */
    public function resolve(string $id): ?array
    {
        $p = $this->pending[$id] ?? null;
        if ($p !== null) {
            unset($this->pending[$id]);
        }

        return $p;
    }

    /** @return array<string, array{tool: string, args: array<string, mixed>, editable: array<string, array{input: string, options?: list<string>, cast?: string}>}> */
    public function all(): array
    {
        return $this->pending;
    }

    /**
     * Allowlist a partir dos campos de um bloco confirm ja validado
     * (BlockValidator): so os que tem `name` + `input`.
     *
     * @param  list<array<string, mixed>> $fields
     * @return array<string, array{input: string, options?: list<string>}>
     */
    public static function editableFromFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $name  = $f['name'] ?? null;
            $input = $f['input'] ?? null;
            if (! is_string($name) || $name === '' || ! in_array($input, self::INPUTS, true)) {
                continue;
            }
            $spec = ['input' => $input];
            if ($input === 'select') {
                $spec['options'] = array_values(array_map(
                    static fn ($o) => (string) $o['value'],
                    array_filter($f['options'] ?? [], static fn ($o) => is_array($o) && isset($o['value']))
                ));
            }
            $out[$name] = $spec;
        }

        return $out;
    }

    /**
     * Aplica os valores editados no cartao por cima dos args da escrita. So as
     * chaves da allowlist, e so valor que passa na conversao do tipo; o resto
     * fica com o argumento original.
     *
     * @param  array<string, mixed>                                                          $args
     * @param  array<string, array{input: string, options?: list<string>, cast?: string}> $editable
     * @param  array<mixed>                                                                  $values
     * @return array<string, mixed>
     */
    public static function applyValues(array $args, array $editable, array $values): array
    {
        foreach ($values as $name => $raw) {
            if (! is_string($name) || ! isset($editable[$name])) {
                continue;
            }
            [$ok, $value] = self::cast($raw, $editable[$name]);
            if ($ok) {
                $args[$name] = $value;
            }
        }

        return $args;
    }

    /**
     * @param  array{input: string, options?: list<string>, cast?: string} $spec
     * @return array{0: bool, 1: mixed}
     */
    private static function cast(mixed $raw, array $spec): array
    {
        if (! is_scalar($raw)) {
            return [false, null];
        }

        switch ($spec['input']) {
            case 'number':
                if (is_int($raw) || is_float($raw)) {
                    return is_finite((float) $raw) ? [true, $raw] : [false, null];
                }
                $s = trim((string) $raw);
                if ($s === '' || ! is_numeric($s)) {
                    return [false, null];
                }

                return [true, $s + 0];

            case 'date':
                $s = trim((string) $raw);
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return [false, null];
                }

                return [true, $s];

            case 'select':
                $s = (string) $raw;
                if (! in_array($s, $spec['options'] ?? [], true)) {
                    return [false, null];
                }

                return [true, ($spec['cast'] ?? null) === 'bool' ? $s === '1' : $s];

            default: // text | textarea
                if (is_bool($raw)) {
                    return [false, null];
                }

                return [true, mb_substr((string) $raw, 0, self::MAX_TEXT)];
        }
    }

    /**
     * Normaliza a allowlist vinda do estado persistido ou de quem chama.
     *
     * @return array<string, array{input: string, options?: list<string>, cast?: string}>
     */
    private static function cleanEditable(mixed $editable): array
    {
        if (! is_array($editable)) {
            return [];
        }
        $out = [];
        foreach ($editable as $name => $spec) {
            if (! is_string($name) || $name === '' || ! is_array($spec) || ! in_array($spec['input'] ?? null, self::INPUTS, true)) {
                continue;
            }
            $clean = ['input' => (string) $spec['input']];
            if ($clean['input'] === 'select') {
                $clean['options'] = array_values(array_map('strval', array_filter(
                    is_array($spec['options'] ?? null) ? $spec['options'] : [],
                    'is_scalar'
                )));
            }
            if (($spec['cast'] ?? null) === 'bool') {
                $clean['cast'] = 'bool';
            }
            $out[$name] = $clean;
        }

        return $out;
    }
}
