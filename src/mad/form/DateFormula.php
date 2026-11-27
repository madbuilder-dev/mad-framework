<?php

declare(strict_types=1);

namespace Mad\Form;

use DateTimeImmutable;
use DateTimeZone;
use Mad\Support\BusinessDays;
use Mad\Support\DateMask;

/**
 * Funções de data do "Preencher valor quando" (`fill-value`) e do campo
 * calculado (`compute`) — fórum #440 — avaliadas no SERVIDOR, com a mesma conta
 * do navegador (`_madFill` do mad-ui.js):
 *
 *   addDays(data, n)   subDays(data, n)   addMonths(data, n)   subMonths(data, n)
 *   diffDays(inicio, fim)                 diffBusinessDays(inicio, fim)
 *
 * - `data`: `{campo}`, `today()` ou `now()`; `n`: `{campo}` ou número inteiro.
 *   Nada aninhado. Os argumentos são DADO: o texto do campo é lido como data
 *   pela máscara dele, nunca executado.
 * - addMonths no fim do mês fica no último dia do mês de chegada: 31/01 + 1 mês
 *   = 28/02 (29/02 em ano bissexto).
 * - diffDays = fim − início, só a data (a hora não conta).
 * - diffBusinessDays segue o BusinessDays::diffBusinessDays: os dias úteis
 *   depois do início até o fim, inclusive; negativo quando o fim vem antes. Os
 *   dias úteis e os feriados são os do app, os mesmos que o navegador recebe no
 *   `data-mad-bdays` ({@see calendarAttr()}): ano atual ± CAL_YEARS. Data fora
 *   dessa janela = sem resultado, dos dois lados.
 * - Data vazia ou que não é data = sem resultado: o preenchimento não escreve e
 *   o cálculo fica vazio.
 *
 * O resto da expressão é o pedaço do `compute` que dá para conferir sem
 * executar código: números, `{campo}` (lido como número, vazio = 0), `+ - * /`,
 * parênteses e round/max/min/abs/floor/ceil. Qualquer outra coisa (texto entre
 * aspas, `if`, `discount`, `{@campo}`, chamada desconhecida) dá null: "não sei"
 * — quem chama mantém o valor que o navegador mandou. Analisador próprio, sem
 * eval.
 *
 * `{combo->campo}` (campo do registro escolhido num combo — 5.375.0) é lido
 * como `{campo}`: quem chama põe o valor em `$values['combo->campo']`
 * ({@see ComboFieldRef::valuesFor()}).
 */
final class DateFormula
{
    /** As funções, com o tipo de cada argumento: d = data, n = quantidade. */
    public const FUNCTIONS = [
        'addDays'          => ['d', 'n'],
        'subDays'          => ['d', 'n'],
        'addMonths'        => ['d', 'n'],
        'subMonths'        => ['d', 'n'],
        'diffDays'         => ['d', 'd'],
        'diffBusinessDays' => ['d', 'd'],
    ];

    /** As que devolvem número (as únicas do campo calculado). */
    public const NUMBER_FUNCTIONS = ['diffDays', 'diffBusinessDays'];

    /** Limites: além disso é engano de campo, não conta de prazo. */
    public const MAX_DAYS = 36600;
    public const MAX_MONTHS = 1200;

    /** Janela de feriados levada ao navegador: ano atual ± isto. */
    public const CAL_YEARS = 5;

    private const MAX_EXPR = 2000;

    private const FALLBACK_MASKS = [
        'yyyy-mm-dd hh:ii:ss', 'yyyy-mm-dd hh:ii', 'yyyy-mm-dd',
        'dd/mm/yyyy hh:ii:ss', 'dd/mm/yyyy hh:ii', 'dd/mm/yyyy',
    ];

    private const MATH = ['round' => 1, 'max' => 1, 'min' => 1, 'abs' => 1, 'floor' => 1, 'ceil' => 1];

    /** Relógio (o teste troca). */
    private static ?DateTimeImmutable $clock = null;

    /** @var array{wd: list<int>, from: int, to: int, h: array<string, true>}|null */
    private static ?array $calendarMemo = null;

    private static ?int $calendarYear = null;

    /** @var array{wd: list<int>, from: int, to: int, h: array<string, true>}|null */
    private static ?array $calendarOverride = null;

    public static function useClock(?DateTimeImmutable $now): void
    {
        self::$clock = $now;
        self::$calendarMemo = null;
    }

    /**
     * Calendário fixo (teste): `['wd' => [1,…], 'from' => 2021, 'to' => 2031,
     * 'h' => ['2026-11-02', …]]`. null volta ao do app.
     *
     * @param array{wd: list<int>, from: int, to: int, h: list<string>}|null $cal
     */
    public static function useCalendar(?array $cal): void
    {
        self::$calendarOverride = $cal === null ? null : [
            'wd'   => array_map('intval', $cal['wd']),
            'from' => (int) $cal['from'],
            'to'   => (int) $cal['to'],
            'h'    => array_fill_keys(array_map('strval', $cal['h']), true),
        ];
    }

    /** Esquece o calendário guardado (teste, troca de provedor de feriados). */
    public static function flush(): void
    {
        self::$calendarMemo = null;
    }

    /**
     * A expressão chama alguma função de data (fora de aspas)? Com `$only`, só
     * as da lista.
     *
     * @param list<string>|null $only
     */
    public static function uses(string $expr, ?array $only = null): bool
    {
        $names = $only ?? array_keys(self::FUNCTIONS);
        $code = self::outsideQuotes($expr);

        return (bool) preg_match('/\b(?:' . implode('|', array_map('preg_quote', $names)) . ')\s*\(/', $code);
    }

    /**
     * O Salvar refaz esta fórmula no servidor? Com função de data ou com
     * `{combo->campo}` (campo do registro escolhido num combo — fórum #440).
     * Fora disso a tela compila e grava como sempre.
     */
    public static function redoneOnSave(string $expr): bool
    {
        return self::uses($expr) || ComboFieldRef::uses($expr);
    }

    // ── Calendário de dias úteis ─────────────────────────────────────────────

    /**
     * Dias da semana úteis e feriados do app (unidade padrão) na janela de
     * anos. É o que o navegador recebe e o que o servidor usa: a mesma lista
     * dos dois lados.
     *
     * @return array{wd: list<int>, from: int, to: int, h: array<string, true>}
     */
    public static function calendar(): array
    {
        if (self::$calendarOverride !== null) {
            return self::$calendarOverride;
        }
        $year = (int) self::now()->format('Y');
        if (self::$calendarMemo !== null && self::$calendarYear === $year) {
            return self::$calendarMemo;
        }
        $from = $year - self::CAL_YEARS;
        $to = $year + self::CAL_YEARS;
        $holidays = [];
        try {
            foreach (array_keys(BusinessDays::holidaysBetween($from . '-01-01', $to . '-12-31')) as $d) {
                $holidays[(string) $d] = true;
            }
        } catch (\Throwable) {
            $holidays = [];
        }
        self::$calendarYear = $year;

        return self::$calendarMemo = [
            'wd'   => BusinessDays::workingDays(),
            'from' => $from,
            'to'   => $to,
            'h'    => $holidays,
        ];
    }

    /** `data-mad-bdays="…"` para o campo cuja fórmula usa diffBusinessDays. */
    public static function calendarAttr(): string
    {
        $cal = self::calendar();
        $json = json_encode([
            'wd'   => $cal['wd'],
            'from' => $cal['from'],
            'to'   => $cal['to'],
            'h'    => array_keys($cal['h']),
        ], JSON_UNESCAPED_SLASHES);

        return 'data-mad-bdays="' . htmlspecialchars((string) $json, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }

    // ── Avaliação ────────────────────────────────────────────────────────────

    /**
     * Resultado da expressão com os valores enviados, ou null quando o servidor
     * não sabe avaliá-la.
     *
     *   ['kind' => 'number', 'value' => float]     (NAN/INF = vazio)
     *   ['kind' => 'date'|'datetime', 'value' => DateTimeImmutable]
     *   ['kind' => 'nothing']                      (data vazia/ilegível)
     *
     * @param array<string, mixed>  $values valores enviados (crus)
     * @param array<string, string> $masks  máscara de exibição dos campos de data
     * @param bool                  $numbersOnly campo calculado: só as funções que dão número
     * @return array{kind: string, value?: mixed}|null
     */
    public static function evaluate(string $expr, array $values, array $masks = [], bool $numbersOnly = false): ?array
    {
        $expr = trim($expr);
        if ($expr === '' || strlen($expr) > self::MAX_EXPR) {
            return null;
        }
        $tokens = self::tokens($expr);
        if ($tokens === null) {
            return null;
        }
        $p = new DateFormulaParser($tokens, $values, $masks, $numbersOnly);
        try {
            $r = $p->parse();
        } catch (\Throwable) {
            return null;
        }

        return $r;
    }

    /**
     * O resultado como o campo de destino posta: data na máscara dele (ou na do
     * idioma), número limpo ("12", "12.5"), '' para vazio. null = não escrever
     * (data vazia no preenchimento).
     *
     * @param array{kind: string, value?: mixed} $result
     * @param array<string, mixed>               $target props do campo de destino
     */
    public static function posted(array $result, array $target, bool $forFill): ?string
    {
        $type = (string) ($target['type'] ?? '');
        switch ($result['kind']) {
            case 'nothing':
                return $forFill ? null : '';
            case 'number':
                $v = (float) $result['value'];
                if (!is_finite($v)) {
                    return $forFill ? null : '';
                }

                return self::cleanNumber($v);
            case 'date':
            case 'datetime':
                /** @var DateTimeImmutable $d */
                $d = $result['value'];
                if ($type === 'datetime') {
                    $mask = (string) ($target['display_mask'] ?? '') ?: DateMask::datetime();
                    // Data sem hora num campo de data e hora: à meia-noite.
                    if ($result['kind'] === 'date') {
                        $d = $d->setTime(0, 0, 0);
                    }

                    return self::format($d, $mask);
                }
                if ($type === 'date') {
                    return self::format($d, (string) ($target['display_mask'] ?? '') ?: DateMask::date());
                }

                return self::format($d, $result['kind'] === 'datetime' ? DateMask::datetime() : DateMask::date());
        }

        return null;
    }

    // ── Contas (as mesmas do mad-ui.js) ──────────────────────────────────────

    public static function now(): DateTimeImmutable
    {
        return self::$clock ?? new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    }

    /** Dias somados (mantém a hora). null fora do limite. */
    public static function addDays(DateTimeImmutable $d, int $n): ?DateTimeImmutable
    {
        if (abs($n) > self::MAX_DAYS) {
            return null;
        }
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        // Conta no calendário (dia juliano), sem passar por fuso/horário de verão.
        $jd = self::dayNumber($y, $m, $day) + $n;
        [$ny, $nm, $nd] = self::fromDayNumber($jd);

        return $d->setDate($ny, $nm, $nd);
    }

    /** Meses somados; o dia fica no fim do mês quando ele não existe. */
    public static function addMonths(DateTimeImmutable $d, int $n): ?DateTimeImmutable
    {
        if (abs($n) > self::MAX_MONTHS) {
            return null;
        }
        $y = (int) $d->format('Y');
        $m = (int) $d->format('n');
        $day = (int) $d->format('j');
        $total = $y * 12 + ($m - 1) + $n;
        $ny = intdiv($total, 12);
        $nm = $total % 12;
        if ($nm < 0) {
            $nm += 12;
            $ny--;
        }
        $nm++;
        $last = self::daysInMonth($ny, $nm);

        return $d->setDate($ny, $nm, min($day, $last));
    }

    /** fim − início, em dias de calendário. */
    public static function diffDays(DateTimeImmutable $a, DateTimeImmutable $b): ?int
    {
        $n = self::dayNumber((int) $b->format('Y'), (int) $b->format('n'), (int) $b->format('j'))
            - self::dayNumber((int) $a->format('Y'), (int) $a->format('n'), (int) $a->format('j'));

        return abs($n) > self::MAX_DAYS ? null : $n;
    }

    /**
     * Dias úteis depois de $a até $b, inclusive (negativo quando $b < $a), pelo
     * calendário da janela. null quando uma das datas está fora dela.
     */
    public static function diffBusinessDays(DateTimeImmutable $a, DateTimeImmutable $b): ?int
    {
        $cal = self::calendar();
        $ya = (int) $a->format('Y');
        $yb = (int) $b->format('Y');
        if ($ya < $cal['from'] || $ya > $cal['to'] || $yb < $cal['from'] || $yb > $cal['to']) {
            return null;
        }
        $from = self::dayNumber($ya, (int) $a->format('n'), (int) $a->format('j'));
        $to = self::dayNumber($yb, (int) $b->format('n'), (int) $b->format('j'));
        if ($from === $to) {
            return 0;
        }
        $sign = $to > $from ? 1 : -1;
        if ($sign < 0) {
            [$from, $to] = [$to, $from];
        }
        $wd = array_flip($cal['wd']);
        $count = 0;
        for ($j = $from + 1; $j <= $to; $j++) {
            [$y, $m, $d] = self::fromDayNumber($j);
            // 0 = domingo … 6 = sábado (o dia juliano 0 caiu numa segunda).
            $dow = ($j + 1) % 7;
            if (isset($wd[$dow]) && !isset($cal['h'][sprintf('%04d-%02d-%02d', $y, $m, $d)])) {
                $count++;
            }
        }

        return $sign * $count;
    }

    /**
     * Texto → data pela máscara do campo (tokens yyyy/yy/mm/dd/hh/HH/ii/MM/ss/SS),
     * depois pelas máscaras de reserva. [data, tem hora] ou null.
     *
     * @return array{0: DateTimeImmutable, 1: bool}|null
     */
    public static function parseDate(mixed $value, string $mask = ''): ?array
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }
        $s = trim((string) $value);
        if ($s === '') {
            return null;
        }
        $masks = $mask !== '' ? array_merge([$mask], self::FALLBACK_MASKS) : self::FALLBACK_MASKS;
        foreach ($masks as $i => $m) {
            $r = self::parseMasked($i === 0 && $mask !== '' ? $s : str_replace('T', ' ', $s), $m);
            if ($r !== null) {
                return $r;
            }
        }

        return null;
    }

    /** Número da quantidade de dias/meses: inteiro (corta a fração), ou null. */
    public static function count(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? (int) $value : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = trim($value);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(['.', ','], ['', '.'], $s);
        }
        if (!preg_match('/^[-+]?\d+(?:\.\d+)?$/', $s)) {
            return null;
        }

        return (int) (float) $s;
    }

    /** Como o `toNum` do compute: vazio/ilegível = 0, "1.234,5" = 1234.5. */
    public static function toNumber(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? (float) $v : 0.0;
        }
        $s = trim(is_scalar($v) ? (string) $v : '');
        if ($s === '') {
            return 0.0;
        }
        if (str_contains($s, ',')) {
            $s = str_replace(',', '.', str_replace('.', '', $s));
        }

        return preg_match('/^[-+]?(?:\d+\.?\d*|\.\d+)(?:[eE][-+]?\d+)?/', $s, $m) ? (float) $m[0] : 0.0;
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** @return array{0: DateTimeImmutable, 1: bool}|null */
    private static function parseMasked(string $s, string $mask): ?array
    {
        $re = '';
        $order = [];
        $i = 0;
        $len = strlen($mask);
        while ($i < $len) {
            $four = substr($mask, $i, 4);
            $two = substr($mask, $i, 2);
            if ($four === 'yyyy') {
                $re .= '(\d{4})';
                $order[] = 'y';
                $i += 4;
            } elseif ($two === 'yy') {
                $re .= '(\d{2})';
                $order[] = 'yy';
                $i += 2;
            } elseif ($two === 'mm') {
                $re .= '(\d{1,2})';
                $order[] = 'mo';
                $i += 2;
            } elseif ($two === 'dd') {
                $re .= '(\d{1,2})';
                $order[] = 'd';
                $i += 2;
            } elseif ($two === 'hh' || $two === 'HH') {
                $re .= '(\d{1,2})';
                $order[] = 'h';
                $i += 2;
            } elseif ($two === 'ii' || $two === 'MM') {
                $re .= '(\d{2})';
                $order[] = 'i';
                $i += 2;
            } elseif ($two === 'ss' || $two === 'SS') {
                $re .= '(\d{2})';
                $order[] = 's';
                $i += 2;
            } else {
                $re .= preg_quote($mask[$i], '~');
                $i++;
            }
        }
        if (!preg_match('~^' . $re . '$~', $s, $m)) {
            return null;
        }
        $p = ['y' => null, 'mo' => null, 'd' => null, 'h' => 0, 'i' => 0, 's' => 0];
        foreach ($order as $k => $key) {
            $v = (int) $m[$k + 1];
            if ($key === 'yy') {
                $p['y'] = 2000 + $v;
            } else {
                $p[$key] = $v;
            }
        }
        if ($p['y'] === null || $p['mo'] === null || $p['d'] === null) {
            return null;
        }
        if ($p['mo'] < 1 || $p['mo'] > 12 || $p['d'] < 1 || $p['h'] > 23 || $p['i'] > 59 || $p['s'] > 59) {
            return null;
        }
        if (!checkdate($p['mo'], $p['d'], $p['y'])) {
            return null;
        }
        $hasTime = in_array('h', $order, true);
        $d = self::now()->setDate($p['y'], $p['mo'], $p['d'])->setTime($p['h'], $p['i'], $p['s']);

        return [$d, $hasTime];
    }

    private static function format(DateTimeImmutable $d, string $mask): string
    {
        return $d->format(DateMask::toPhp($mask));
    }

    /** Número limpo, como o `toPrecision(12)` do navegador: 30.3, não 30.299999999999997. */
    private static function cleanNumber(float $v): string
    {
        $s = sprintf('%.12G', $v);
        if (str_contains($s, 'E')) {
            return (string) (float) $s;
        }
        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    private static function daysInMonth(int $y, int $m): int
    {
        return $m === 2 ? (($y % 4 === 0 && $y % 100 !== 0) || $y % 400 === 0 ? 29 : 28) : (in_array($m, [4, 6, 9, 11], true) ? 30 : 31);
    }

    /** Dias desde 01/01/0001 (calendário gregoriano proléptico), 0-based. */
    private static function dayNumber(int $y, int $m, int $d): int
    {
        $a = intdiv(14 - $m, 12);
        $yy = $y + 4800 - $a;
        $mm = $m + 12 * $a - 3;

        return $d + intdiv(153 * $mm + 2, 5) + 365 * $yy + intdiv($yy, 4) - intdiv($yy, 100) + intdiv($yy, 400) - 32045 - 1721426;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function fromDayNumber(int $n): array
    {
        $jdn = $n + 1721426;
        $a = $jdn + 32044;
        $b = intdiv(4 * $a + 3, 146097);
        $c = $a - intdiv(146097 * $b, 4);
        $d = intdiv(4 * $c + 3, 1461);
        $e = $c - intdiv(1461 * $d, 4);
        $m = intdiv(5 * $e + 2, 153);
        $day = $e - intdiv(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * intdiv($m, 10);
        $year = 100 * $b + $d - 4800 + intdiv($m, 10);

        return [$year, $month, $day];
    }

    /** A expressão sem o conteúdo entre aspas (para procurar chamadas). */
    private static function outsideQuotes(string $expr): string
    {
        return (string) preg_replace('/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s', "''", $expr);
    }

    /**
     * Fichas da expressão; null com qualquer coisa fora do pedaço entendido
     * (aspas, `@`, ponto solto, `$`…).
     *
     * @return list<array{0: string, 1: mixed}>|null
     */
    private static function tokens(string $expr): ?array
    {
        $out = [];
        $i = 0;
        $len = strlen($expr);
        while ($i < $len) {
            $c = $expr[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if (preg_match('/\G\{([A-Za-z_]\w*)\}/', $expr, $m, 0, $i)) {
                $out[] = ['field', $m[1]];
                $i += strlen($m[0]);
                continue;
            }
            // `{combo->campo}` (fórum #440): campo do registro escolhido no
            // combo — quem chama põe o valor em `$values['combo->campo']`
            // ({@see ComboFieldRef::valuesFor()}); sem ele, vazio.
            if (preg_match('/\G\{([A-Za-z_]\w*->[A-Za-z_]\w*)\}/', $expr, $m, 0, $i)) {
                $out[] = ['field', $m[1]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G\d+(?:\.\d+)?/', $expr, $m, 0, $i)) {
                $out[] = ['num', (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (preg_match('/\G[A-Za-z_]\w*/', $expr, $m, 0, $i)) {
                $out[] = ['id', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (str_contains('+-*/(),', $c)) {
                $out[] = ['op', $c];
                $i++;
                continue;
            }

            return null;
        }

        return $out;
    }

    /** @internal usado pelo DateFormulaParser */
    public static function isMath(string $name): bool
    {
        return isset(self::MATH[strtolower($name)]);
    }
}
