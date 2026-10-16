<?php

declare(strict_types=1);

namespace Mad\Ui\Menu;

/**
 * Um item do menu como o provedor o enxerga.
 *
 *   $item->is('AtividadeList', 'AtividadeKanban')  // tela do item (basename)
 *   $item->class                                    // 'AtividadeList' ('' = grupo/link externo)
 *   $item->method                                   // 'onReload' quando a ação traz #method=
 *   $item->label / $item->text()                    // rótulo cru do XML / traduzido
 *   $item->trail                                    // rótulos dos níveis acima (crus)
 *   $item->group                                    // tem filhos (módulo ou submódulo)
 *   $item->inModule('Governança')                   // está debaixo desse módulo/grupo
 *
 * Rótulos chegam como estão no menu.xml (podem vir como `_t{Texto}`); `text()`
 * e `inModule()` comparam também com a forma traduzida.
 */
final class MenuItem
{
    /**
     * @param string[] $trail
     */
    public function __construct(
        public readonly string $label,
        public readonly string $class = '',
        public readonly string $method = '',
        public readonly string $action = '',
        public readonly string $icon = '',
        public readonly array $trail = [],
        public readonly bool $group = false,
    ) {
    }

    /**
     * Monta a partir do `<action>` do menu.xml (`Classe#method=x#static=1`,
     * `\rota`, URL externa).
     *
     * @param string[] $trail
     */
    public static function fromAction(string $label, string $action, string $icon = '', array $trail = [], bool $group = false): self
    {
        $action = trim($action);
        $class = '';
        $method = '';

        if ($action !== '' && !preg_match('#^(https?:)?//#i', $action) && $action[0] !== '\\') {
            $parts = explode('#', $action);
            $class = class_basename(trim($parts[0]));
            foreach (array_slice($parts, 1) as $part) {
                if (str_starts_with($part, 'method=')) {
                    $method = substr($part, 7);
                }
            }
        }

        return new self($label, $class, $method, $action, $icon, array_values($trail), $group);
    }

    /** O item abre uma destas telas (basename ou FQCN). */
    public function is(string ...$classes): bool
    {
        if ($this->class === '') {
            return false;
        }

        foreach ($classes as $c) {
            if (strcasecmp(class_basename($c), $this->class) === 0) {
                return true;
            }
        }

        return false;
    }

    /** Rótulo traduzido no idioma ativo. */
    public function text(): string
    {
        return self::translate($this->label);
    }

    /** Algum nível acima (ou o próprio item, se for grupo) tem este rótulo. */
    public function inModule(string $label): bool
    {
        $want = mb_strtolower(trim($label));
        $levels = $this->trail;
        if ($this->group) {
            $levels[] = $this->label;
        }

        foreach ($levels as $level) {
            if (mb_strtolower(trim($level)) === $want || mb_strtolower(trim(self::translate($level))) === $want) {
                return true;
            }
        }

        return false;
    }

    /**
     * Chave estável do item na página: o basename da tela; grupo e link sem
     * tela usam o hash do rótulo (o mesmo `menu-target` do rail).
     */
    public function key(): string
    {
        return $this->class !== '' ? $this->class : md5($this->label);
    }

    /** Identidade do item para o cache da requisição (o mesmo item aparece em vários menus). */
    public function cacheKey(): string
    {
        return implode("\x1f", [$this->class, $this->method, $this->label, implode("\x1e", $this->trail), $this->group ? 'g' : 'i']);
    }

    private static function translate(string $label): string
    {
        return str_contains($label, '_t{') && function_exists('mad_translate_template')
            ? (string) mad_translate_template($label)
            : $label;
    }
}
