<?php

namespace Mad\Routine;

/**
 * Uma rotina declarada em `app/config/routines.json` (gerado pela plataforma).
 *
 * `fromArray()` devolve null para entrada que não serve (slug ou classe fora
 * do formato): a rotina fica de fora, as outras seguem valendo.
 */
final class RoutineEntry
{
    public const SLUG_PATTERN  = '[a-z0-9]+(?:-[a-z0-9]+)*';
    private const CLASS_PATTERN = '/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /** @param list<string> $controllers basenames das telas que disparam ao salvar ([] = qualquer formulário) */
    public function __construct(
        public readonly string $slug,
        public readonly string $class,
        public readonly ?string $source,
        public readonly ?string $connection,
        public readonly bool $onSave,
        public readonly array $controllers,
        public readonly bool $onDelete,
        public readonly bool $button,
    ) {
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }
        $slug  = is_string($raw['slug'] ?? null) ? trim($raw['slug']) : '';
        $class = is_string($raw['class'] ?? null) ? ltrim(trim($raw['class']), '\\') : '';
        if (! preg_match('/^' . self::SLUG_PATTERN . '$/', $slug) || strlen($slug) > 120
            || ! preg_match(self::CLASS_PATTERN, $class)) {
            return null;
        }

        $source = is_string($raw['source'] ?? null) ? ltrim(trim($raw['source']), '\\') : '';
        if ($source !== '' && ! preg_match(self::CLASS_PATTERN, $source)) {
            return null;
        }
        $connection = is_string($raw['connection'] ?? null) && trim($raw['connection']) !== ''
            ? trim($raw['connection']) : null;

        $save        = $raw['on_save'] ?? null;
        $onSave      = is_array($save) ? ($save['enabled'] ?? false) === true : $save === true;
        $controllers = [];
        foreach (is_array($save) && is_array($save['controllers'] ?? null) ? $save['controllers'] : [] as $c) {
            if (is_string($c) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $c)) {
                $controllers[] = $c;
            }
        }

        // Sem origem não há salvar nem excluir que dispare: só o botão / código.
        $hasSource = $source !== '';

        return new self(
            $slug,
            $class,
            $hasSource ? $source : null,
            $connection,
            $hasSource && $onSave,
            $controllers,
            $hasSource && ($raw['on_delete'] ?? false) === true,
            ($raw['button'] ?? false) === true,
        );
    }

    /** A rotina dispara ao salvar este registro por esta tela? */
    public function firesOnSave(object $record, ?string $controller): bool
    {
        if (! $this->onSave || $this->source === null || ! $record instanceof $this->source) {
            return false;
        }

        return $this->controllers === [] || ($controller !== null && in_array($controller, $this->controllers, true));
    }

    /** A rotina se desfaz quando este registro é excluído? */
    public function firesOnDelete(object $record): bool
    {
        return $this->onDelete && $this->source !== null && $record instanceof $this->source;
    }
}
