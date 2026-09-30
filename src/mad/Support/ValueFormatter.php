<?php

namespace Mad\Support;

/**
 * ValueFormatter — resolver ÚNICO dos formatadores built-in do formatter-select
 * do MadBuilder (lockstep com `formatters.ts` do frontend).
 *
 * Catálogo:
 *   text | boolean
 *   money | currency-USD | currency-EUR | currency-GBP | currency-JPY
 *   number | number-en | integer | percent | decimal-4
 *   date | date-iso | date-long | date-month-year | date-weekday
 *   datetime | datetime-seconds | time
 *   cpf | cnpj | cep | phone-br
 *
 * Compartilhado pelos DOIS contextos de render:
 *   - Documentos: MadDocRuntime::applyFormat delega pra cá (o prefixo
 *     `custom:` fica FORA — resolve pra classe de transformer diferente por
 *     contexto, DocumentTransformer no doc / GridTransformer no grid).
 *   - Grid: GridColumn::renderValue usa como FALLBACK quando o transform é um
 *     token built-in (não-callable). Precedência do grid: transform custom
 *     (callable) → attrs de render-type (badge/money/date/number) → built-in
 *     daqui → default. Attrs vencem o built-in de propósito: é o contrato que
 *     o editor mostra e mantém o render de colunas antigas com os dois attrs.
 *
 * Formatação padrão pt-BR (vírgula decimal, ponto de milhar). Puro e sem
 * estado — valor cru entra, string sai; entrada inválida degrada pro valor
 * cru (nunca lança).
 */
final class ValueFormatter
{
    /** Tokens aceitos (lowercase) — espelho de BUILTIN_FORMATTERS do frontend. */
    private const TOKENS = [
        'text', 'boolean',
        'money', 'currency-usd', 'currency-eur', 'currency-gbp', 'currency-jpy',
        'number', 'number-en', 'integer', 'percent', 'decimal-4',
        'date', 'date-iso', 'date-long', 'date-month-year', 'date-weekday',
        'datetime', 'datetime-seconds', 'time',
        'cpf', 'cnpj', 'cep', 'phone-br',
    ];

    private const MONTHS_PT = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    private const WEEKDAYS_PT = [
        'domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
        'quinta-feira', 'sexta-feira', 'sábado',
    ];

    /**
     * Catálogo completo, em lowercase — para mensagem de erro ("aceitos: …"),
     * teste de paridade com o gêmeo JS e qualquer UI que ofereça a lista.
     *
     * @return string[]
     */
    public static function tokens(): array
    {
        return self::TOKENS;
    }

    /** O token é um formatador built-in conhecido? (case-insensitive) */
    public static function supports(string $token): bool
    {
        return in_array(strtolower(trim($token)), self::TOKENS, true);
    }

    /**
     * Aplica um formatador built-in. Token desconhecido cai no default `text`
     * (mesmo contrato do switch original do MadDocRuntime).
     */
    public static function apply(mixed $value, string $token): string
    {
        $token = strtolower(trim($token)) ?: 'text';

        switch ($token) {
            // `money` fica SEM símbolo (contrato original — apps publicados e
            // prefixos manuais tipo "Total: R$ " dependem disso). Os
            // currency-* carregam símbolo por serem autodescritivos.
            case 'money':
                return number_format(self::num($value), 2, ',', '.');
            case 'currency-usd':
                return 'US$ ' . number_format(self::num($value), 2, '.', ',');
            case 'currency-eur':
                return '€ ' . number_format(self::num($value), 2, ',', '.');
            case 'currency-gbp':
                return '£ ' . number_format(self::num($value), 2, '.', ',');
            case 'currency-jpy':
                return '¥ ' . number_format(self::num($value), 0, '.', ',');

            case 'number':
                return number_format(self::num($value), 2, ',', '.');
            case 'number-en':
                return number_format(self::num($value), 2, '.', ',');
            case 'integer':
                return number_format(self::num($value), 0, ',', '.');
            case 'percent':
                return number_format(self::num($value), 1, ',', '.') . '%';
            case 'decimal-4':
                return number_format(self::num($value), 4, ',', '.');

            case 'date':
                return self::formatDateAs($value, 'd/m/Y');
            case 'date-iso':
                return self::formatDateAs($value, 'Y-m-d');
            case 'date-long': {
                $dt = self::toDateTime($value);
                if ($dt === null) return self::scalarString($value);
                return $dt->format('j') . ' de ' . self::MONTHS_PT[(int) $dt->format('n')] . ' de ' . $dt->format('Y');
            }
            case 'date-month-year': {
                $dt = self::toDateTime($value);
                if ($dt === null) return self::scalarString($value);
                return ucfirst(substr(self::MONTHS_PT[(int) $dt->format('n')], 0, 3)) . '/' . $dt->format('Y');
            }
            case 'date-weekday': {
                $dt = self::toDateTime($value);
                if ($dt === null) return self::scalarString($value);
                return self::WEEKDAYS_PT[(int) $dt->format('w')];
            }
            case 'datetime':
                return self::formatDateAs($value, 'd/m/Y H:i');
            case 'datetime-seconds':
                return self::formatDateAs($value, 'd/m/Y H:i:s');
            case 'time':
                return self::formatDateAs($value, 'H:i');

            case 'cpf': {
                $d = self::digits($value);
                if (strlen($d) !== 11) return self::scalarString($value);
                return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
            }
            case 'cnpj': {
                // Alfanumérico (Receita, julho/2026): sanitize preserva letras.
                // `digits()` aqui destruía o CNPJ novo e caía no scalarString.
                $c = MadCnpj::sanitize(self::scalarString($value));
                if (strlen($c) !== 14) return self::scalarString($value);
                return MadCnpj::format($c);
            }
            case 'cep': {
                $d = self::digits($value);
                if (strlen($d) !== 8) return self::scalarString($value);
                return substr($d, 0, 5) . '-' . substr($d, 5, 3);
            }
            case 'phone-br': {
                $d = self::digits($value);
                if (strlen($d) === 11) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7, 4);
                if (strlen($d) === 10) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6, 4);
                return self::scalarString($value);
            }

            case 'boolean':
                if (is_string($value)) {
                    $truthy = in_array(strtolower(trim($value)), ['1', 't', 'true', 'sim', 's', 'y', 'yes'], true);
                } else {
                    $truthy = (bool) $value;
                }
                return $truthy ? 'Sim' : 'Não';

            case 'text':
            default:
                if ($value === null) {
                    return '';
                }
                if (is_bool($value)) {
                    return $value ? 'Sim' : 'Não';
                }
                if (is_array($value)) {
                    return implode(', ', array_map('strval', $value));
                }
                return (string) $value;
        }
    }

    private static function num(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function digits(mixed $value): string
    {
        return preg_replace('/\D+/', '', self::scalarString($value)) ?? '';
    }

    private static function scalarString(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    private static function formatDateAs(mixed $value, string $fmt): string
    {
        $dt = self::toDateTime($value);
        return $dt === null ? self::scalarString($value) : $dt->format($fmt);
    }

    /**
     * Data de um valor cru — `DateTimeInterface`/Carbon, `Y-m-d H:i:s`, `Y-m-d`,
     * qualquer string que o `DateTimeImmutable` entenda, ou epoch (numérico).
     * `null` quando não é data — o chamador decide o fallback.
     *
     * PÚBLICA porque a granularidade da quebra do grid (`group-by="data|day"`)
     * precisa do MESMO parser que os formatadores `date*` usam. Reimplementar
     * lá seria garantir que a banda do grupo e a célula formatada divirjam num
     * formato de entrada qualquer.
     */
    public static function toDateTime(mixed $value): ?\DateTimeInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            if ($value instanceof \DateTimeInterface) {
                return $value;
            }
            if (is_numeric($value)) {
                return (new \DateTimeImmutable())->setTimestamp((int) $value);
            }
            // Texto COM fuso (o ISO UTC do `toArray()` do Eloquent) vai para o
            // fuso do app — "23:15Z" num app em America/Sao_Paulo é 20:15. Sem
            // fuso, o texto já está no fuso do app e nada muda.
            return (new \DateTimeImmutable((string) $value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }
}
