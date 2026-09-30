<?php
namespace Mad\Grid;

use Mad\Support\MadMediaFormatter;
use Mad\Support\ValueFormatter;

/**
 * Tradução de UMA célula da grid para a planilha exportada.
 *
 * Até a 5.92 o XLSX só conhecia money/number/date e gravava todo o resto CRU:
 * o formatador da coluna (`cpf`, `cnpj`, `phone-br`, `percent`…), o transform
 * do projeto e o rótulo do badge eram ignorados, e a biblioteca de planilha ainda
 * convertia qualquer texto só de dígitos em número — o CNPJ virava
 * `1,23457E+13` e o telefone uma quantia. Data saía como texto e os totais
 * como a string já formatada ("R$ 1.234,50"), impossíveis de somar.
 *
 * A regra agora é a da tela: a PRECEDÊNCIA é a mesma do
 * `GridColumn::renderValue` (transform > mídia > badge > money > number >
 * date > formatador built-in > html > arquivo embutido > valor cru), e o que
 * a coluna declara decide o TIPO da célula:
 *
 *  - valor (money/number/percent/formatador numérico) → número com o formato
 *    da coluna, somável no Excel;
 *  - data → data de verdade do Excel, com o `date-format` convertido;
 *  - o resto → exatamente o texto que a grid mostra, gravado COMO TEXTO
 *    (sem conversão para número e sem virar fórmula quando começa com `=`).
 *
 * Coluna sem tipo: número nativo (int/float) continua número. Texto numérico
 * ("12.50", como o banco devolve `decimal`) só vira número quando a FONTE é
 * numérica — o tipo da coluna no banco (GridExportSourceTypes) ou, sem fonte
 * conhecida, a coluna inteira só com decimais (inferSourceNumeric). Qualquer
 * outro texto — "4555.5555" num varchar, CNPJ, CPF, telefone, código de
 * barras sem máscara — fica texto (fórum #47).
 */
final class GridExportCell
{
    public const TEXT   = 'text';
    public const NUMBER = 'number';
    public const DATE   = 'date';
    public const BLANK  = 'blank';

    /** Decimal com parte fracionária, sem zero à esquerda — o formato em que o banco devolve `decimal`. */
    private const DECIMAL_STRING_RE = '/^-?(?:0|[1-9]\d{0,14})\.\d+$/';

    /** Número em texto vindo de coluna numérica do banco (`decimal(10,0)` devolve "12"). */
    private const NUMERIC_STRING_RE = '/^-?\d{1,15}(?:\.\d+)?$/';

    /**
     * Formato Excel dos formatadores built-in numéricos. `percent` é tratado
     * à parte: a grid mostra `15` como "15,0%", então a célula recebe 0,15.
     */
    private const BUILTIN_NUMBER_FORMATS = [
        'money'        => '#,##0.00',
        'currency-usd' => '"US$ "#,##0.00',
        'currency-eur' => '"€ "#,##0.00',
        'currency-gbp' => '"£ "#,##0.00',
        'currency-jpy' => '"¥ "#,##0',
        'number'       => '#,##0.00',
        'number-en'    => '#,##0.00',
        'integer'      => '#,##0',
        'decimal-4'    => '#,##0.0000',
    ];

    /**
     * Formato Excel dos formatadores built-in de data. Os três por extenso
     * fixam o idioma pt-BR (`[$-416]`), como a grid, que sempre os escreve
     * em português — sem o prefixo o Excel usaria o idioma da máquina.
     *
     * @var array<string, array{0:string, 1:bool, 2:bool}> token => [formato, temData, temHora]
     */
    private const BUILTIN_DATE_FORMATS = [
        'date'             => ['dd/mm/yyyy', true, false],
        'date-iso'         => ['yyyy-mm-dd', true, false],
        'date-long'        => ['[$-416]d "de" mmmm "de" yyyy', true, false],
        'date-month-year'  => ['[$-416]mmm/yyyy', true, false],
        'date-weekday'     => ['[$-416]dddd', true, false],
        'datetime'         => ['dd/mm/yyyy hh:mm', true, true],
        'datetime-seconds' => ['dd/mm/yyyy hh:mm:ss', true, true],
        'time'             => ['hh:mm', false, true],
    ];

    /** Letra do `date()` do PHP → código Excel. */
    private const PHP_DATE_TOKENS = [
        'd' => 'dd',   'j' => 'd',    'D' => 'ddd',  'l' => 'dddd',
        'm' => 'mm',   'n' => 'm',    'M' => 'mmm',  'F' => 'mmmm',
        'Y' => 'yyyy', 'y' => 'yy',
        'H' => 'hh',   'G' => 'h',    'h' => 'hh',   'g' => 'h',
        'i' => 'mm',   's' => 'ss',
        'A' => 'AM/PM', 'a' => 'AM/PM',
    ];

    /** Separadores que o Excel aceita sem aspas num formato de data. */
    private const DATE_BARE_LITERALS = ['/', '-', ':', ' ', '.'];

    /**
     * Célula de DADOS.
     *
     * @return array{type:string, value:mixed, format:string}
     *         `format` = '' quando a célula fica no formato Geral.
     */
    public static function forXlsx(GridColumn $col, mixed $value, array $row = []): array
    {
        // 1. Transform do projeto (callable): a grid mostra o que ele devolve.
        if ($col->hasTransform && is_callable($col->transform)) {
            return self::text(self::plainText($col->renderValue($value, $row)));
        }

        // 2. Mídia: a planilha não tem miniatura — o nome dos arquivos (igual ao PDF).
        if ($col->mediaFormat !== '') {
            return self::text(MadMediaFormatter::renderPlain($value));
        }

        // 3. Badge: o RÓTULO do mapa ("Ativo"), não a chave gravada ("A").
        if ($col->isBadge) {
            return self::text(self::plainText($col->renderValue($value, $row)));
        }

        if (self::isBlank($value)) {
            // Vazio fica vazio (e não "R$ 0,00"/"0,00", que a tela inventa) —
            // exceto o `boolean`, em que vazio É uma resposta: "Não".
            return $col->builtinFormat === 'boolean' && !$col->isMoney && !$col->isNumber
                ? self::text(ValueFormatter::apply($value, 'boolean'))
                : self::blank();
        }

        // 4/5. Valor declarado na coluna.
        if ($col->isMoney) {
            return is_numeric($value)
                ? self::number((float) $value, self::moneyFormat($col->moneyPrefix))
                : self::text(self::scalar($value));
        }
        if ($col->isNumber) {
            return is_numeric($value)
                ? self::number((float) $value, self::decimalsFormat($col->numberDecimals))
                : self::text(self::scalar($value));
        }

        // 6. Data declarada na coluna — mesmo parser do renderValue. Valor que
        //    não é data cai adiante, como na tela.
        if ($col->isDate) {
            $dt = self::parseDate($value);
            if ($dt !== null) {
                $spec = self::excelDateFormat($col->dateFormat);
                return $spec === null
                    ? self::text(self::plainText($col->renderValue($value, $row)))
                    : self::date($dt, ...$spec);
            }
        }

        // 7. Formatador built-in do formatter-select.
        if ($col->builtinFormat !== '') {
            return self::builtin($col->builtinFormat, $value);
        }

        // 8. HTML declarado: o texto, sem as tags.
        if ($col->isHtml) {
            return self::text(self::plainText((string) $value));
        }

        // 9. Arquivo embutido na célula sem transformador (mesma rede do PDF).
        if (MadMediaFormatter::looksLikeInlineFile($value)) {
            return self::text(MadMediaFormatter::renderPlain($value));
        }

        // 10. Coluna sem tipo. Número nativo é número; texto numérico só quando
        //     a FONTE é numérica (decimal do banco chega como "12.50") — um
        //     varchar com "4555.5555" continua o texto que a grid mostra.
        if (is_int($value) || is_float($value)) {
            return self::number($value, '');
        }
        if ($col->sourceNumeric === true && is_string($value) && preg_match(self::NUMERIC_STRING_RE, $value)) {
            return self::number((float) $value, '');
        }

        // Data serializada pelo Eloquent ("2026-09-27T23:15:00.000000Z") numa
        // coluna sem formato: a grid mostra data (GridColumn::serializedDateText),
        // a planilha recebe data de verdade — não o ISO cru.
        if (GridColumn::isSerializedDate($value) && ($dt = self::parseDate($value)) !== null) {
            return $dt->format('H:i:s') === '00:00:00'
                ? self::date($dt, 'dd/mm/yyyy', true, false)
                : self::date($dt, 'dd/mm/yyyy hh:mm', true, true);
        }

        return self::text(self::scalar($value));
    }

    /**
     * Fonte desconhecida (query própria, alias, accessor): a coluna é numérica
     * só se TODO valor preenchido for número nativo ou decimal ("12.50") — uma
     * única célula como "000.555-32" prova que a coluna é texto e nenhuma das
     * outras vira número. Coluna só de inteiros em texto ("000123", CNPJ) não
     * conta como numérica: é o identificador que a 5.93 tirou do Excel-número.
     *
     * @param iterable<array> $rows
     */
    public static function inferSourceNumeric(GridColumn $col, iterable $rows): bool
    {
        $seen = false;
        foreach ($rows as $row) {
            $value = $row[$col->field] ?? null;
            if (self::isBlank($value) || is_int($value) || is_float($value)) {
                continue;
            }
            if (!is_string($value) || !preg_match(self::DECIMAL_STRING_RE, $value)) {
                return false;
            }
            $seen = true;
        }

        return $seen;
    }

    /**
     * Texto que a grid mostra, sem HTML — o que vai no CSV (que não tem tipo)
     * e no fallback de texto do XLSX.
     */
    public static function displayText(GridColumn $col, mixed $value, array $row = []): string
    {
        if ($col->mediaFormat !== '' && !($col->hasTransform && is_callable($col->transform))) {
            return MadMediaFormatter::renderPlain($value);
        }
        if (!$col->hasTransform && MadMediaFormatter::looksLikeInlineFile($value)) {
            return MadMediaFormatter::renderPlain($value);
        }

        return self::plainText($col->renderValue($value, $row));
    }

    /**
     * Converte um formato `date()` do PHP (o `date-format` da coluna) para o
     * código de formato do Excel.
     *
     * @return array{0:string, 1:bool, 2:bool}|null [formato, temData, temHora];
     *         null quando o formato usa algo que o Excel não expressa
     *         (semana do ano, sufixo "th", fuso…) — a célula vira o texto.
     */
    public static function excelDateFormat(string $php): ?array
    {
        $out      = '';
        $literal  = '';
        $hasDate  = false;
        $hasTime  = false;
        $has12h   = false;
        $hasAmPm  = false;

        $flush = function () use (&$out, &$literal): void {
            if ($literal !== '') {
                $out     .= '"' . $literal . '"';
                $literal  = '';
            }
        };

        $chars = mb_str_split($php);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];

            if ($c === '\\') {
                // `\x` no PHP = x literal.
                $next = $chars[++$i] ?? '';
                if ($next !== '' && $next !== '"') {
                    $literal .= $next;
                }
                continue;
            }

            if (isset(self::PHP_DATE_TOKENS[$c])) {
                $flush();
                $out .= self::PHP_DATE_TOKENS[$c];
                if (in_array($c, ['d', 'j', 'D', 'l', 'm', 'n', 'M', 'F', 'Y', 'y'], true)) {
                    $hasDate = true;
                } else {
                    $hasTime = true;
                    $has12h  = $has12h  || $c === 'h' || $c === 'g';
                    $hasAmPm = $hasAmPm || $c === 'A' || $c === 'a';
                }
                continue;
            }

            // Qualquer outra letra é código do date() sem equivalente no Excel.
            if (preg_match('/^[A-Za-z]$/', $c)) {
                return null;
            }

            if (in_array($c, self::DATE_BARE_LITERALS, true)) {
                $flush();
                $out .= $c;
            } elseif ($c !== '"') {
                $literal .= $c;
            }
        }
        $flush();

        // Hora de 12h sem AM/PM: o Excel mostraria 24h — melhor o texto exato.
        if ($out === '' || ($has12h && !$hasAmPm)) {
            return null;
        }

        return [$out, $hasDate, $hasTime];
    }

    /**
     * Formato Excel de dinheiro. O prefixo vai entre aspas: solto, letras como
     * o `s` de "US$" seriam lidas como código de formato.
     */
    public static function moneyFormat(string $prefix): string
    {
        $prefix = str_replace('"', '', trim($prefix));

        return $prefix === '' ? '#,##0.00' : '"' . $prefix . ' "#,##0.00';
    }

    public static function decimalsFormat(int $decimals): string
    {
        $decimals = max(0, min(30, $decimals));

        return $decimals === 0 ? '#,##0' : '#,##0.' . str_repeat('0', $decimals);
    }

    // ── Internos ─────────────────────────────────────────────────────────

    private static function builtin(string $token, mixed $value): array
    {
        if (isset(self::BUILTIN_NUMBER_FORMATS[$token])) {
            return is_numeric($value)
                ? self::number((float) $value, self::BUILTIN_NUMBER_FORMATS[$token])
                : self::text(ValueFormatter::apply($value, $token));
        }

        if ($token === 'percent') {
            return is_numeric($value)
                ? self::number((float) $value / 100, '0.0%')
                : self::text(ValueFormatter::apply($value, $token));
        }

        if (isset(self::BUILTIN_DATE_FORMATS[$token])) {
            $dt = ValueFormatter::toDateTime($value);
            return $dt === null
                ? self::text(ValueFormatter::apply($value, $token))
                : self::date($dt, ...self::BUILTIN_DATE_FORMATS[$token]);
        }

        // text, boolean, cpf, cnpj, cep, phone-br: a máscara é o dado.
        return self::text(ValueFormatter::apply($value, $token));
    }

    /** Mesmo parser do `renderValue` para colunas `date`. */
    private static function parseDate(mixed $value): ?\DateTimeInterface
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        try {
            // Mesmo fuso da tela (GridColumn::renderValue): o ISO UTC do
            // Eloquent vira a hora do app; texto sem fuso não muda.
            return (new \DateTime((string) $value))
                ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function date(\DateTimeInterface $dt, string $format, bool $hasDate, bool $hasTime): array
    {
        // O Excel não representa data antes de 1900 — mostra o texto da tela.
        if ((int) $dt->format('Y') < 1900) {
            return self::text($dt->format($hasTime && !$hasDate ? 'H:i:s' : 'd/m/Y'));
        }

        if ($hasDate && !$hasTime) {
            // Só a data: sem a fração da hora, o filtro "igual a" do Excel acerta.
            $serial = self::excelDay((int) $dt->format('Y'), (int) $dt->format('m'), (int) $dt->format('d'));
        } else {
            $serial = self::excelDateTime($dt);
            if (!$hasDate) {
                $serial -= floor($serial);
            }
        }

        return ['type' => self::DATE, 'value' => $serial, 'format' => $format];
    }

    /**
     * Serial de data do Excel (sistema 1900): dias desde 30/12/1899. O Excel
     * trata 1900 como bissexto (o 29/02/1900 que não existiu), então antes de
     * 01/03/1900 o serial é um a menos.
     */
    private static function excelDay(int $y, int $m, int $d): int
    {
        $days = intdiv(gmmktime(0, 0, 0, $m, $d, $y) - gmmktime(0, 0, 0, 12, 30, 1899), 86400);

        return ($y === 1900 && $m <= 2) ? $days - 1 : $days;
    }

    /** Data + hora "de parede" (sem converter fuso), como a grid mostra. */
    private static function excelDateTime(\DateTimeInterface $dt): int|float
    {
        $days = self::excelDay((int) $dt->format('Y'), (int) $dt->format('m'), (int) $dt->format('d'));
        $secs = (int) $dt->format('H') * 3600 + (int) $dt->format('i') * 60 + (int) $dt->format('s')
              + (int) $dt->format('u') / 1e6;

        return $secs == 0 ? $days : $days + $secs / 86400;
    }

    private static function number(int|float $value, string $format): array
    {
        return ['type' => self::NUMBER, 'value' => $value, 'format' => $format];
    }

    private static function text(string $value): array
    {
        return $value === ''
            ? self::blank()
            : ['type' => self::TEXT, 'value' => $value, 'format' => ''];
    }

    private static function blank(): array
    {
        return ['type' => self::BLANK, 'value' => null, 'format' => ''];
    }

    private static function isBlank(mixed $value): bool
    {
        return $value === null || $value === '' || (is_string($value) && trim($value) === '');
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '';
        }
        if (is_array($value)) {
            return implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : '', $value));
        }

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /** HTML do renderValue → texto: tags fora, entidades decodificadas, `<br>` vira quebra. */
    public static function plainText(string $html): string
    {
        $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
