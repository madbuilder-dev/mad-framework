<?php
namespace Mad\Util;

/**
 * String helpers kept for code written against the legacy runtime.
 *
 * New code should prefer Laravel's `Illuminate\Support\Str`. This class stays
 * because imported apps still call it, so every method keeps its original
 * name, parameters and output — including the quirks documented below.
 *
 * @author     Matheus Agnes Dias
 * @copyright  Copyright (c) 2025-2026 Mad Solutions LTDA (https://madbuilder.dev)
 * @license    MIT
 */
class StringConversion
{
    /**
     * Letters folded by removeAccent(), grouped by the ASCII text they become.
     *
     * @var array<string, string>
     */
    private const ACCENT_FOLDING = [
        'A' => 'ÀÁÂÃÄÅĀĂĄǍǺ',   'a' => 'àáâãäåāăąǎǻ',
        'AE' => 'ÆǼ',           'ae' => 'æǽ',
        'C' => 'ÇĆĈĊČ',         'c' => 'çćĉċč',
        'D' => 'ÐĎĐ',           'd' => 'ďđ',
        'E' => 'ÈÉÊËĒĔĖĘĚ',     'e' => 'èéêëēĕėęě',
        'f' => 'ƒ',
        'G' => 'ĜĞĠĢ',          'g' => 'ĝğġģ',
        'H' => 'ĤĦ',            'h' => 'ĥħ',
        'I' => 'ÌÍÎÏĨĪĬĮİǏ',    'i' => 'ìíîïĩīĭįıǐ',
        'IJ' => 'Ĳ',            'ij' => 'ĳ',
        'J' => 'Ĵ',             'j' => 'ĵ',
        'K' => 'Ķ',             'k' => 'ķ',
        // Ł folds to a lowercase "l": the historical output, kept on purpose.
        'L' => 'ĹĻĽĿ',          'l' => 'ĺļľŀŁł',
        'N' => 'ÑŃŅŇ',          'n' => 'ñńņňŉ',
        'O' => 'ÒÓÔÕÖØŌŎŐƠǑǾ',  'o' => 'òóôõöøōŏőơǒǿ',
        'OE' => 'Œ',            'oe' => 'œ',
        'R' => 'ŔŖŘ',           'r' => 'ŕŗř',
        'S' => 'ŚŜŞŠ',          's' => 'ßśŝşšſ',
        'T' => 'ŢŤŦ',           't' => 'ţťŧ',
        'U' => 'ÙÚÛÜŨŪŬŮŰŲƯǓǕǗǙǛ', 'u' => 'ùúûüũūŭůűųưǔǖǘǚǜ',
        'W' => 'Ŵ',             'w' => 'ŵ',
        'Y' => 'ÝŶŸ',           'y' => 'ýÿŷ',
        'Z' => 'ŹŻŽ',           'z' => 'źżž',
    ];

    /**
     * Letters transliterated by slug() before anything else is stripped.
     * Smaller than ACCENT_FOLDING on purpose: slug() historically drops the
     * letters it does not know (a lowercase "ü", for instance, disappears).
     *
     * @var array<string, string>
     */
    private const SLUG_FOLDING = [
        'A' => 'ÀÁÂÃÄÅÆ',  'a' => 'àáâãäåæ',
        'B' => 'Þ',        'b' => 'þ',
        'C' => 'ÇČĆ',      'c' => 'çčć',
        'Dj' => 'Đ',       'dj' => 'đ',
        'E' => 'ÈÉÊË',     'e' => 'èéêë',
        'I' => 'ÌÍÎÏ',     'i' => 'ìíîï',
        'N' => 'Ñ',        'n' => 'ñ',
        'O' => 'ÒÓÔÕÖØ',   'o' => 'ðòóôõöø',
        'R' => 'Ŕ',        'r' => 'ŕ',
        'S' => 'Š',        's' => 'š',
        'Ss' => 'ß',
        'U' => 'ÙÚÛÜ',     'u' => 'ùúû',
        'Y' => 'Ý',        'y' => 'ýÿ',
        'Z' => 'Ž',        'z' => 'ž',
    ];

    /** @var array<string, array<string, string>> expanded strtr() maps, built once */
    private static array $maps = [];

    /**
     * "order_item" → "OrderItem". Each part is lower-cased, trimmed and gets its
     * first byte upper-cased. With $spaces, the parts are joined by a space and
     * the result keeps a trailing space ("Order Item ").
     */
    public static function camelCaseFromUnderscore($string, $spaces = FALSE)
    {
        $glue  = $spaces ? ' ' : '';
        $parts = explode('_', mb_strtolower((string) $string));

        return implode($glue, array_map(
            static fn (string $part): string => ucfirst(trim($part)),
            $parts
        )) . $glue;
    }

    /**
     * "OrderItem" → "order_item": an underscore goes wherever a lowercase ASCII
     * letter is followed by an uppercase one, then everything is lower-cased.
     * With $spaces, the result is trimmed and its spaces become underscores.
     */
    public static function underscoreFromCamelCase($string, $spaces = FALSE)
    {
        $snake = mb_strtolower((string) preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', (string) $string));

        return $spaces ? str_replace(' ', '_', trim($snake)) : $snake;
    }

    /**
     * Replaces accented Latin letters by their plain ASCII form ("Ação" → "Acao").
     * Letters outside ACCENT_FOLDING are returned untouched.
     */
    public static function removeAccent($str)
    {
        return strtr((string) $str, self::map('accent', self::ACCENT_FOLDING));
    }

    /**
     * Returns $content as UTF-8. Valid UTF-8 comes back as is; anything else is
     * read as ISO-8859-1 (the encoding legacy databases and CSV files use).
     */
    public static function assureUnicode($content)
    {
        $content = (string) $content;

        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');

        return $converted === false ? $content : $converted;
    }

    /**
     * Returns $content as ISO-8859-1. UTF-8 input is transliterated (characters
     * with no Latin-1 form are approximated or dropped); input that is not valid
     * UTF-8 is assumed to be Latin-1 already and comes back unchanged.
     */
    public static function assureIso($content)
    {
        $content = (string) $content;

        if (! mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $converted = iconv('UTF-8', 'ISO-8859-1//TRANSLIT//IGNORE', $content);

        return $converted === false ? $content : $converted;
    }

    /**
     * URL-friendly text: "Ação Rápida!" → "acao-rapida".
     *
     * Only ASCII letters, digits, "_" and "-" survive. Runs of spaces and hyphens
     * shrink to one separator; each underscore becomes a separator of its own.
     */
    public static function slug($content, $separator = '-')
    {
        $text = mb_strtolower(strtr(self::assureUnicode($content), self::map('slug', self::SLUG_FOLDING)));

        $text = preg_replace('/[^a-z0-9_\s-]/', '', $text);
        $text = preg_replace('/[\s-]+/', ' ', $text);
        $text = preg_replace('/[\s_]/', (string) $separator, trim($text));

        // A separator outside printable ASCII is removed as well.
        return preg_replace('/[[:^print:]]/', '', $text);
    }

    /**
     * Replaces the first section delimited by $needle_start … $needle_end.
     *
     * Legacy naming: with $include_limits = true only the text BETWEEN the
     * delimiters is replaced (they are kept); with false the delimiters are
     * replaced too. Returns $str unchanged when either delimiter is missing.
     */
    public static function replaceBetween($str, $needle_start, $needle_end, $replacement, $include_limits = true)
    {
        $range = self::locate((string) $str, (string) $needle_start, (string) $needle_end, (bool) $include_limits);

        return $range === null ? $str : substr_replace($str, $replacement, $range[0], $range[1]);
    }

    /**
     * Returns the first section delimited by $needle_start … $needle_end, or ''
     * when either delimiter is missing. $include_limits works as in
     * replaceBetween(): true returns only the inner text.
     */
    public static function getBetween($str, $needle_start, $needle_end, $include_limits = true)
    {
        $range = self::locate((string) $str, (string) $needle_start, (string) $needle_end, (bool) $include_limits);

        return $range === null ? '' : substr($str, $range[0], $range[1]);
    }

    /** ISO-8859-1 → UTF-8 (replacement for the utf8_encode() PHP deprecated). */
    protected static function utf8_encode($s)
    {
        return mb_convert_encoding((string) $s, 'UTF-8', 'ISO-8859-1');
    }

    /** UTF-8 → ISO-8859-1 (replacement for the utf8_decode() PHP deprecated). */
    public static function utf8_decode($s)
    {
        return mb_convert_encoding((string) $s, 'ISO-8859-1', 'UTF-8');
    }

    /**
     * Offset and length of the delimited section, or null when not found.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function locate(string $haystack, string $open, string $close, bool $innerOnly): ?array
    {
        $openAt = strpos($haystack, $open);
        if ($openAt === false) {
            return null;
        }

        // The closing delimiter is searched from $from: with the delimiters
        // included it may overlap the opening one ("[" … "[").
        $from    = $innerOnly ? $openAt + strlen($open) : $openAt;
        $closeAt = strpos($haystack, $close, $from);
        if ($closeAt === false) {
            return null;
        }

        $to = $innerOnly ? $closeAt : $closeAt + strlen($close);

        return [$from, $to - $from];
    }

    /**
     * Expands a folding table into a character → replacement map for strtr().
     *
     * @param  array<string, string>  $folding
     * @return array<string, string>
     */
    private static function map(string $name, array $folding): array
    {
        if (! isset(self::$maps[$name])) {
            $map = [];
            foreach ($folding as $plain => $letters) {
                foreach (mb_str_split($letters) as $letter) {
                    $map[$letter] = $plain;
                }
            }
            self::$maps[$name] = $map;
        }

        return self::$maps[$name];
    }
}
