<?php

declare(strict_types=1);

namespace Mad\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use Mad\Support\Holidays\BrazilNationalHolidays;

/**
 * BusinessDays — conta em dias úteis: fim de semana e feriados do app.
 *
 *   BusinessDays::isBusinessDay($data, $unidadeId)
 *   BusinessDays::addBusinessDays($inicio, 3, $unidadeId)   // Carbon, mesma hora
 *   BusinessDays::diffBusinessDays($a, $b, $unidadeId)       // int, negativo se $b < $a
 *   BusinessDays::nextBusinessDay($data, $unidadeId)
 *   BusinessDays::holidaysBetween($a, $b, $unidadeId)        // ['Y-m-d' => 'Nome']
 *
 * Dias da semana úteis: `mad.calendar.working_days` (0 = domingo … 6 = sábado,
 * o mesmo número do `working-days` do Gantt); padrão segunda a sexta.
 *
 * Feriados: um {@see HolidayProvider}, resolvido nesta ordem —
 *  1. o que foi passado em {@see useProvider()} (teste, boot do app);
 *  2. a interface ligada no container (`app()->bind(HolidayProvider::class, …)`);
 *  3. `mad.calendar.holiday_provider` (FQCN; `none` = só fim de semana);
 *  4. por convenção, `App\Services\HolidayProvider` quando existir — é o que o
 *     app gerado cria (code_create kind=php-service), sem tocar em config;
 *  5. {@see BrazilNationalHolidays}.
 *
 * `$unitId` é a unidade (filial) cujo calendário vale: feriado municipal de uma
 * unidade não atrasa o prazo de outra. A lista de cada ano×unidade fica em
 * memória por {@see CACHE_TTL} segundos (worker de fila vê feriado novo sem
 * reiniciar); {@see flush()} zera na hora.
 */
final class BusinessDays
{
    public const CACHE_TTL = 60;

    /** Teto de dias corridos percorridos numa conta (calendário sem dia útil). */
    private const MAX_SCAN_DAYS = 3660;

    private static ?object $provider = null;

    private static bool $providerResolved = false;

    /** @var array<string, array{0: float, 1: array<string,string>}> */
    private static array $memo = [];

    /**
     * Fixa o provedor de feriados (teste, ou um app que prefere ligar no boot).
     * `null` volta à resolução automática.
     */
    public static function useProvider(?HolidayProvider $provider): void
    {
        self::$provider = $provider;
        self::$providerResolved = $provider !== null;
        self::$memo = [];
    }

    /** Esquece o provedor resolvido e as listas guardadas. */
    public static function flush(): void
    {
        self::$provider = null;
        self::$providerResolved = false;
        self::$memo = [];
    }

    /** O provedor em uso (resolve na primeira chamada). */
    public static function provider(): object
    {
        if (!self::$providerResolved) {
            self::$provider = self::resolveProvider();
            self::$providerResolved = true;
        }

        return self::$provider;
    }

    /**
     * Dias da semana úteis, 0 (domingo) a 6 (sábado).
     *
     * @return list<int>
     */
    public static function workingDays(): array
    {
        $cfg = function_exists('config') ? config('mad.calendar.working_days') : null;
        if (is_string($cfg)) {
            $cfg = array_filter(array_map('trim', explode(',', $cfg)), static fn ($x) => $x !== '');
        }
        $days = [];
        foreach ((array) $cfg as $d) {
            if (is_numeric($d) && (int) $d >= 0 && (int) $d <= 6) {
                $days[(int) $d] = true;
            }
        }
        $days = array_keys($days);
        sort($days);

        return $days !== [] ? $days : [1, 2, 3, 4, 5];
    }

    public static function isBusinessDay(DateTimeInterface|string $date, int|string|null $unitId = null): bool
    {
        $d = self::carbon($date);
        if (!in_array($d->dayOfWeek, self::workingDays(), true)) {
            return false;
        }

        return !isset(self::yearHolidays($d->year, self::unit($unitId))[$d->format('Y-m-d')]);
    }

    public static function isHoliday(DateTimeInterface|string $date, int|string|null $unitId = null): bool
    {
        return self::holidayName($date, $unitId) !== null;
    }

    /** Nome do feriado na data (string vazia se o provedor não deu nome), ou null. */
    public static function holidayName(DateTimeInterface|string $date, int|string|null $unitId = null): ?string
    {
        $d = self::carbon($date);

        return self::yearHolidays($d->year, self::unit($unitId))[$d->format('Y-m-d')] ?? null;
    }

    /**
     * Soma (ou, com $days negativo, subtrai) dias úteis, mantendo a hora.
     * Sexta + 1 = segunda; 0 devolve a própria data, útil ou não.
     */
    public static function addBusinessDays(DateTimeInterface|string $from, int $days, int|string|null $unitId = null): Carbon
    {
        $d = self::carbon($from);
        $unit = self::unit($unitId);
        $step = $days >= 0 ? 1 : -1;
        $left = abs($days);
        $max = max(self::MAX_SCAN_DAYS, $left * 4);
        $guard = 0;
        while ($left > 0) {
            $d->addDays($step);
            if (self::isBusinessDay($d, $unit)) {
                $left--;
            }
            self::guard(++$guard, $max);
        }

        return $d;
    }

    public static function subBusinessDays(DateTimeInterface|string $from, int $days, int|string|null $unitId = null): Carbon
    {
        return self::addBusinessDays($from, -$days, $unitId);
    }

    /**
     * Próximo dia útil DEPOIS da data (mesma hora). Com $orSame, a própria data
     * quando ela já é útil.
     */
    public static function nextBusinessDay(DateTimeInterface|string $date, int|string|null $unitId = null, bool $orSame = false): Carbon
    {
        if ($orSame && self::isBusinessDay($date, $unitId)) {
            return self::carbon($date);
        }

        return self::addBusinessDays($date, 1, $unitId);
    }

    /** Dia útil anterior à data (mesma hora). Com $orSame, a própria data quando útil. */
    public static function previousBusinessDay(DateTimeInterface|string $date, int|string|null $unitId = null, bool $orSame = false): Carbon
    {
        if ($orSame && self::isBusinessDay($date, $unitId)) {
            return self::carbon($date);
        }

        return self::addBusinessDays($date, -1, $unitId);
    }

    /**
     * Dias úteis de $a até $b: conta os dias úteis depois de $a até $b inclusive
     * (só a data vale, a hora não). Negativo quando $b é antes de $a, e
     * diff($a, $b) === -diff($b, $a). addBusinessDays($a, diff($a, $b)) cai em
     * $b sempre que $b é dia útil.
     */
    public static function diffBusinessDays(DateTimeInterface|string $a, DateTimeInterface|string $b, int|string|null $unitId = null): int
    {
        $from = self::carbon($a)->startOfDay();
        $to = self::carbon($b)->startOfDay();
        if ($from->equalTo($to)) {
            return 0;
        }
        $sign = $to->greaterThan($from) ? 1 : -1;
        if ($sign < 0) {
            [$from, $to] = [$to, $from];
        }

        $unit = self::unit($unitId);
        $count = 0;
        while ($from->lessThan($to)) {
            $from->addDay();
            if (self::isBusinessDay($from, $unit)) {
                $count++;
            }
        }

        return $sign * $count;
    }

    /**
     * Feriados entre as duas datas (inclusive), em ordem, inclusive os que caem
     * em fim de semana.
     *
     * @return array<string,string> 'Y-m-d' => nome
     */
    public static function holidaysBetween(DateTimeInterface|string $a, DateTimeInterface|string $b, int|string|null $unitId = null): array
    {
        $from = self::carbon($a)->format('Y-m-d');
        $to = self::carbon($b)->format('Y-m-d');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $unit = self::unit($unitId);
        $out = [];
        for ($y = (int) substr($from, 0, 4); $y <= (int) substr($to, 0, 4); $y++) {
            foreach (self::yearHolidays($y, $unit) as $date => $name) {
                if ($date >= $from && $date <= $to) {
                    $out[$date] = $name;
                }
            }
        }
        ksort($out);

        return $out;
    }

    // ── internos ─────────────────────────────────────────────────────────

    /** @return array<string,string> */
    private static function yearHolidays(int $year, ?int $unitId): array
    {
        $key = $year . '|' . ($unitId ?? '');
        $now = microtime(true);
        if (isset(self::$memo[$key]) && $now - self::$memo[$key][0] < self::CACHE_TTL) {
            return self::$memo[$key][1];
        }

        $provider = self::provider();
        try {
            $list = self::normalize($provider->holidays($year, $unitId));
        } catch (\Throwable $e) {
            // Provedor do app quebrado (tabela que ainda não existe, banco fora):
            // conta com os nacionais em vez de derrubar a tela ou o tick do SLA.
            // Não guarda: a próxima chamada tenta o provedor de novo.
            self::warn('BusinessDays: o provedor de feriados ' . $provider::class . ' falhou; usando os feriados nacionais. ' . $e->getMessage());

            return (new BrazilNationalHolidays())->holidays($year, $unitId);
        }
        self::$memo[$key] = [$now, $list];

        return $list;
    }

    /** @return array<string,string> */
    private static function normalize(mixed $raw): array
    {
        $out = [];
        foreach ((array) $raw as $k => $v) {
            // Aceita ['Y-m-d' => 'Nome'] e lista simples ['Y-m-d', …].
            [$date, $name] = is_int($k) ? [$v, ''] : [$k, $v];
            if ($date instanceof DateTimeInterface) {
                $date = $date->format('Y-m-d');
            }
            $date = substr(trim((string) $date), 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                continue;
            }
            $out[$date] = is_scalar($name) ? (string) $name : '';
        }

        return $out;
    }

    private static function resolveProvider(): object
    {
        $app = function_exists('app') ? app() : null;
        if ($app !== null && $app->bound(HolidayProvider::class)) {
            return $app->make(HolidayProvider::class);
        }

        $cfg = function_exists('config') ? config('mad.calendar.holiday_provider') : null;
        if ($cfg === false || (is_string($cfg) && in_array(strtolower(trim($cfg)), ['none', 'off', 'false', '0'], true))) {
            return new class implements HolidayProvider {
                public function holidays(int $year, ?int $unitId = null): array
                {
                    return [];
                }
            };
        }
        if (is_string($cfg) && trim($cfg) !== '') {
            $provider = self::instantiate(trim($cfg));
            if ($provider !== null) {
                return $provider;
            }
            self::warn("BusinessDays: mad.calendar.holiday_provider aponta para '{$cfg}', que não existe ou não tem holidays(); usando o padrão.");
        }

        $convention = 'App\\Services\\HolidayProvider';
        if (class_exists($convention)) {
            $provider = self::instantiate($convention);
            if ($provider !== null) {
                return $provider;
            }
            self::warn("BusinessDays: {$convention} existe mas não tem o método holidays(int \$year, ?int \$unitId); usando os feriados nacionais.");
        }

        return new BrazilNationalHolidays();
    }

    private static function instantiate(string $class): ?object
    {
        if (!class_exists($class) || !method_exists($class, 'holidays')) {
            return null;
        }
        $app = function_exists('app') ? app() : null;

        return $app !== null ? $app->make($class) : new $class();
    }

    private static function carbon(DateTimeInterface|string $date): Carbon
    {
        return $date instanceof DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);
    }

    private static function unit(int|string|null $unitId): ?int
    {
        if ($unitId === null || $unitId === '') {
            return null;
        }

        return is_numeric($unitId) ? (int) $unitId : null;
    }

    private static function guard(int $steps, int $max = self::MAX_SCAN_DAYS): void
    {
        if ($steps > $max) {
            throw new \RuntimeException('BusinessDays: nenhum dia útil em ' . $max . ' dias — confira mad.calendar.working_days e o provedor de feriados.');
        }
    }

    private static function warn(string $message): void
    {
        try {
            \Illuminate\Support\Facades\Log::warning($message);
        } catch (\Throwable) {
            // sem app (script solto): segue calado
        }
    }
}
