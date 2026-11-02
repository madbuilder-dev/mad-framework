<?php

declare(strict_types=1);

namespace Mad\Support;

/**
 * AppCurrency — a moeda do app (ISO 4217), uma por instalação.
 *
 * Onde fica: preferência `currency` do sistema (`mad_sys_preference`, a mesma
 * tabela das outras preferências da tela "Preferências"); sem ela, o
 * `general.currency` do config/mad.php (`config('mad.general.currency')`).
 * Sem nenhum dos dois, a moeda NÃO está definida e nada muda: cada componente
 * usa o símbolo e as casas de antes (R$ do idioma pt, $ do inglês…).
 *
 * Com a moeda definida, ela vira o PADRÃO de símbolo e casas decimais do
 * `<mad-money-field>`, `<mad-display-field format="money">`, colunas `money` da
 * listagem, `currency`/`money` de gráfico, indicador (kpi/db-metric), kanban,
 * field-list, widgets do Copilot IA e o `currency-symbol` do `<mad-pdv>`. Prop
 * explícita (ex.: `prefix="€"`) continua vencendo. Os separadores (1,234.56 ×
 * 1.234,56) seguem o idioma do app, não a moeda.
 *
 * Uso (PHP, ex. na tela de Settings do app):
 *
 *     AppCurrency::set('USD');            // grava; null/'' volta ao padrão
 *     AppCurrency::code();                // 'USD' | null
 *     AppCurrency::symbol();              // '$'
 *     AppCurrency::decimals();            // 2 (JPY: 0)
 *     AppCurrency::format(1234);          // '$1,234.00' (app em inglês)
 *     AppCurrency::formatMinor(123456);   // '$1,234.56' (centavos inteiros)
 *     AppCurrency::options();             // ['USD' => 'USD — US dollar ($)', …] para um select
 */
final class AppCurrency
{
    /** Id da preferência em `mad_sys_preference`. */
    public const PREFERENCE = 'currency';

    /** Chave do config/mad.php (padrão da instalação, sem preferência gravada). */
    public const CONFIG = 'mad.general.currency';

    /**
     * Catálogo: código ISO 4217 => [símbolo, casas decimais, nome em inglês].
     * Símbolo = o que o comércio local usa (CAD/AUD/NZD/MXN escrevem "$").
     *
     * @var array<string, array{0: string, 1: int, 2: string}>
     */
    private const CATALOG = [
        'USD' => ['$', 2, 'US dollar'],
        'CAD' => ['$', 2, 'Canadian dollar'],
        'AUD' => ['$', 2, 'Australian dollar'],
        'NZD' => ['$', 2, 'New Zealand dollar'],
        'GBP' => ['£', 2, 'Pound sterling'],
        'EUR' => ['€', 2, 'Euro'],
        'BRL' => ['R$', 2, 'Brazilian real'],
        'MXN' => ['$', 2, 'Mexican peso'],
        'JPY' => ['¥', 0, 'Japanese yen'],
        'CHF' => ['CHF', 2, 'Swiss franc'],
        'SEK' => ['kr', 2, 'Swedish krona'],
        'NOK' => ['kr', 2, 'Norwegian krone'],
        'DKK' => ['kr', 2, 'Danish krone'],
        'PLN' => ['zł', 2, 'Polish złoty'],
        'ZAR' => ['R', 2, 'South African rand'],
        'INR' => ['₹', 2, 'Indian rupee'],
        'SGD' => ['$', 2, 'Singapore dollar'],
        'HKD' => ['$', 2, 'Hong Kong dollar'],
        'CNY' => ['¥', 2, 'Chinese yuan'],
        'KRW' => ['₩', 0, 'South Korean won'],
        'ILS' => ['₪', 2, 'Israeli new shekel'],
        'AED' => ['AED', 2, 'UAE dirham'],
        'ARS' => ['$', 2, 'Argentine peso'],
        'CLP' => ['$', 0, 'Chilean peso'],
        'COP' => ['$', 2, 'Colombian peso'],
        'PEN' => ['S/', 2, 'Peruvian sol'],
        'UYU' => ['$', 2, 'Uruguayan peso'],
        'PYG' => ['₲', 0, 'Paraguayan guaraní'],
        'BOB' => ['Bs', 2, 'Bolivian boliviano'],
        'PHP' => ['₱', 2, 'Philippine peso'],
    ];

    /** Memo por pedido: [id do pedido, código|null]. */
    private static ?array $memo = null;

    /** Código ISO definido (preferência > config), ou null quando o app não definiu moeda. */
    public static function code(): ?string
    {
        $key = self::requestKey();
        if (self::$memo !== null && self::$memo[0] === $key) {
            return self::$memo[1];
        }

        $code = self::normalize(self::readPreference()) ?? self::normalize(self::readConfig());
        self::$memo = [$key, $code];

        return $code;
    }

    /** O app definiu uma moeda? Sem ela, cada componente segue o padrão de antes. */
    public static function isConfigured(): bool
    {
        return self::code() !== null;
    }

    /**
     * Grava a moeda do app (preferência do sistema). `null`/'' grava "sem
     * moeda": volta ao `general.currency` do config ou ao padrão de antes.
     *
     * @throws \InvalidArgumentException código fora do catálogo
     */
    public static function set(?string $code): void
    {
        $code = $code === null ? '' : strtoupper(trim($code));
        if ($code !== '' && !self::supports($code)) {
            throw new \InvalidArgumentException('Unsupported currency: ' . $code);
        }

        $model = '\\App\\Models\\Sys\\Preference';
        if (!class_exists($model)) {
            throw new \RuntimeException('System preferences are not available in this app.');
        }

        // Linha apagada (soft delete) volta; '' grava "sem moeda" sem apagar.
        $query = method_exists($model, 'withTrashed') ? $model::withTrashed() : $model::query();
        $row   = $query->where('id', self::PREFERENCE)->first();
        if ($row === null) {
            if ($code !== '') {
                $model::query()->create(['id' => self::PREFERENCE, 'preference' => $code]);
            }
        } else {
            if (method_exists($row, 'trashed') && $row->trashed()) {
                $row->restore();
            }
            $row->preference = $code;
            $row->save();
        }

        self::reset();
        if (class_exists('\\App\\Service\\Sys\\PreferenceService') && property_exists('\\App\\Service\\Sys\\PreferenceService', 'preferences')) {
            // O cache do PreferenceService (getPreferences) também precisa ver a troca.
            $svc = '\\App\\Service\\Sys\\PreferenceService';
            if (is_array($svc::$preferences)) {
                if ($code === '') {
                    unset($svc::$preferences[self::PREFERENCE]);
                } else {
                    $svc::$preferences[self::PREFERENCE] = $code;
                }
            }
        }
    }

    /** O código está no catálogo? (case-insensitive) */
    public static function supports(string $code): bool
    {
        return isset(self::CATALOG[strtoupper(trim($code))]);
    }

    /**
     * Símbolo da moeda do app ("$", "£", "¥"…). Sem moeda definida, `$fallback`.
     */
    public static function symbol(?string $fallback = null): ?string
    {
        $code = self::code();

        return $code === null ? $fallback : self::CATALOG[$code][0];
    }

    /** Casas decimais da moeda do app (JPY/KRW/CLP: 0). Sem moeda definida, `$fallback`. */
    public static function decimals(int $fallback = 2): int
    {
        $code = self::code();

        return $code === null ? $fallback : self::CATALOG[$code][1];
    }

    /**
     * Espaço entre símbolo e valor: o do idioma do app ("$1,234.00" no inglês,
     * "R$ 1.234,00" no português); símbolo com letra ("CHF", "kr", "S/")
     * sempre leva espaço.
     */
    public static function gap(?string $symbol = null): string
    {
        $symbol ??= (string) self::symbol('');
        if ($symbol !== '' && preg_match('/\p{L}$/u', $symbol) && !in_array($symbol, ['R$'], true)) {
            return ' ';
        }

        return ValueFormatter::moneyGap();
    }

    /**
     * Prefixo pronto (símbolo + espaço do idioma). Sem moeda definida, o
     * símbolo do idioma do app de sempre (ValueFormatter::currencyPrefix()).
     */
    public static function prefix(): string
    {
        $symbol = self::symbol();
        if ($symbol === null) {
            return ValueFormatter::currencyPrefix();
        }

        return $symbol . self::gap($symbol);
    }

    /**
     * Valor em unidades (1234.5) formatado na moeda do app: "$1,234.50".
     * Separadores do idioma do app. Negativo: "-$5.00".
     */
    public static function format(int|float|string|null $amount, ?int $decimals = null): string
    {
        $n = is_numeric($amount) ? (float) $amount : 0.0;
        $dec = $decimals ?? self::decimals();
        [$ds, $ts] = ValueFormatter::separators();
        $txt = number_format(abs($n), $dec, $ds, $ts);
        $neg = $n < 0 && (float) $txt !== 0.0 && preg_match('/[1-9]/', $txt);

        return ($neg ? '-' : '') . self::prefix() . $txt;
    }

    /** Valor em centavos inteiros (unidades menores × 100) formatado: 123456 → "$1,234.56". */
    public static function formatMinor(int $minor, ?int $decimals = null): string
    {
        return self::format($minor / 100, $decimals);
    }

    /**
     * Catálogo completo: código => ['symbol' => '$', 'decimals' => 2, 'name' => 'US dollar'].
     *
     * @return array<string, array{symbol: string, decimals: int, name: string}>
     */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => [$symbol, $decimals, $name]) {
            $out[$code] = ['symbol' => $symbol, 'decimals' => $decimals, 'name' => $name];
        }

        return $out;
    }

    /**
     * Opções para um select: ['USD' => 'USD — US dollar ($)', …].
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => [$symbol, , $name]) {
            $out[$code] = $code . ' — ' . $name . ' (' . $symbol . ')';
        }

        return $out;
    }

    /**
     * O que o JavaScript do framework precisa (vai no `window.MAD_I18N`):
     * `currency` (símbolo), `currency_code`, `currency_decimals`, `currency_gap`.
     * Vazio sem moeda definida.
     *
     * @return array<string, string>
     */
    public static function jsDictionary(): array
    {
        $code = self::code();
        if ($code === null) {
            return [];
        }
        $symbol = self::CATALOG[$code][0];

        return [
            'currency'          => $symbol,
            'currency_code'     => $code,
            'currency_decimals' => (string) self::CATALOG[$code][1],
            'currency_gap'      => self::gap($symbol),
        ];
    }

    /** Esquece o valor lido (testes; depois de gravar por fora). */
    public static function reset(): void
    {
        self::$memo = null;
    }

    private static function normalize(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $code = strtoupper(trim($raw));

        return isset(self::CATALOG[$code]) ? $code : null;
    }

    private static function readPreference(): mixed
    {
        $model = '\\App\\Models\\Sys\\Preference';
        if (!class_exists($model)) {
            return null;
        }
        try {
            return $model::getPreference(self::PREFERENCE);
        } catch (\Throwable) {
            // Sem banco (instalação, console sem conexão): sem moeda definida.
            return null;
        }
    }

    private static function readConfig(): mixed
    {
        try {
            return function_exists('config') && function_exists('app') && app()->bound('config')
                ? config(self::CONFIG)
                : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Identidade do pedido atual: o memo não atravessa pedidos (Octane, filas). */
    private static function requestKey(): int
    {
        try {
            if (function_exists('app') && app()->bound('request')) {
                return spl_object_id(app('request'));
            }
        } catch (\Throwable) {
        }

        return 0;
    }
}
