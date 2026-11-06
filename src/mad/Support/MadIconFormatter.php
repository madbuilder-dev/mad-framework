<?php

namespace Mad\Support;

/**
 * MadIconFormatter — transformador de célula para coluna que guarda o NOME de
 * um ícone (o que o `<mad-icon-field>` grava: `truck`, `tabler:truck`).
 *
 * Tokens (escolhidos no "Transformador" da coluna no builder):
 *   icon        só o ícone; o nome fica na dica e para o leitor de tela
 *   icon-label  o ícone com o nome ao lado
 *
 * O ícone sai como `<i data-lucide="…">`, o mesmo caminho dos botões da
 * grade: o `lucide.createIcons` do runtime desenha Lucide e, com o prefixo
 * `tabler:`, Tabler (baixa só o balde do ícone). Nome que não existe nas duas
 * bibliotecas deixa o `<i>` sem desenho e o CSS mostra o nome no lugar.
 *
 *   - valor vazio → célula vazia;
 *   - valor que não é nome de ícone (espaço, acento, HTML…) → o texto, escapado;
 *   - exportação (CSV/XLSX/PDF) → o nome gravado, sem HTML (plain()).
 *
 * O HTML sai RAW no grid (GridColumn::renderValue tem branch dedicado antes
 * do escape) — todo valor dinâmico aqui é escapado com htmlspecialchars.
 */
class MadIconFormatter
{
    public const TOKENS = ['icon', 'icon-label'];

    /** Nome de ícone Lucide/Tabler: minúsculas, dígitos e hífen; `tabler:` opcional. */
    private const NAME_RE = '/^(tabler:)?[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public static function isIconToken(string $token): bool
    {
        return in_array(strtolower(trim($token)), self::TOKENS, true);
    }

    /** O valor como texto, aparado; '' para nulo, vazio ou o que não vira texto. */
    public static function plain(mixed $value): string
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }
        if (is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /** É um nome que o Lucide/Tabler podem desenhar (sem conferir se o ícone existe)? */
    public static function isIconName(string $name): bool
    {
        return preg_match(self::NAME_RE, strtolower($name)) === 1;
    }

    public static function render(mixed $value, string $mode): string
    {
        $name = self::plain($value);
        if ($name === '') {
            return '';
        }
        if (!self::isIconName($name)) {
            return htmlspecialchars($name, ENT_QUOTES);
        }

        $icon  = strtolower($name);
        $title = htmlspecialchars($name, ENT_QUOTES);
        // Ao lado do ícone, o nome sem o prefixo da biblioteca.
        $label = htmlspecialchars(str_starts_with($icon, 'tabler:') ? substr($icon, 7) : $icon, ENT_QUOTES);
        $cls   = strtolower(trim($mode)) === 'icon-label' ? 'mad-dg-icon mad-dg-icon--label' : 'mad-dg-icon';

        return '<span class="' . $cls . '" title="' . $title . '">'
            . '<i data-lucide="' . htmlspecialchars($icon, ENT_QUOTES) . '" aria-hidden="true"></i>'
            . '<span class="mad-dg-icon-name">' . $label . '</span>'
            . '</span>';
    }
}
