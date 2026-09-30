<?php

namespace Mad\I18n;

/**
 * MadLang — Internal i18n for the MAD framework.
 *
 * Loads translation catalogs from lib/mad/i18n/lang/{locale}.php and
 * resolves nested keys via dot notation:
 *
 *     MadLang::t('mad.btn.clear')               → "Limpar"
 *     MadLang::t('mad.dashf.filter_by', ['label' => 'Status'])
 *
 * Locale is auto-detected from `general.language` in config/mad.php
 * (falls back to 'en'). Explicit override via MadLang::setLocale().
 *
 * Placeholders use Laravel-style :name (e.g. "Hello :user").
 *
 * Missing keys return the key itself — never silent failure, never
 * throws. Loaded catalogs are cached per locale.
 */
final class MadLang
{
    /** Default fallback locale (used when key missing in active locale). */
    public const FALLBACK = 'en';

    private static ?string $locale = null;
    private static array  $catalogs = [];   // [locale => array]
    private static array  $loaded   = [];   // [locale => true]

    /** Set active locale explicitly. */
    public static function setLocale(string $locale): void
    {
        self::$locale = self::normalize($locale);
    }

    /** Alias de setLocale — mantido p/ os translators do app que chamavam o sync legado. */
    public static function syncLegacy(string $locale): void
    {
        self::setLocale($locale);
    }

    /** Get active locale (lazy-detect from app config on first call). */
    public static function getLocale(): string
    {
        if (self::$locale !== null) {
            return self::$locale;
        }

        // Try app config first
        try {
            if (class_exists(\Mad\Core\AppConfig::class)) {
                $ini = \Mad\Core\AppConfig::get();
                $lang = $ini['general']['language'] ?? null;
                if ($lang) {
                    self::$locale = self::normalize($lang);
                    return self::$locale;
                }
            }
        } catch (\Throwable $e) { /* fall through */ }

        // LANG constant defined in init.php
        if (defined('LANG')) {
            self::$locale = self::normalize((string) constant('LANG'));
            return self::$locale;
        }

        self::$locale = self::FALLBACK;
        return self::$locale;
    }

    /**
     * Translate a key. Returns the resolved string with placeholders
     * substituted, or the key itself when not found.
     *
     * @param string $key      Dot-notated path (e.g. 'mad.btn.clear')
     * @param array  $replace  ['name' => 'value'] → replaces ':name'
     */
    public static function t(string $key, array $replace = []): string
    {
        $locale = self::getLocale();

        $value = self::resolve($key, $locale);
        if ($value === null && str_contains($locale, '-')) {
            // Regional → base (ex: pt-PT → pt) antes do fallback final
            $value = self::resolve($key, explode('-', $locale)[0]);
        }
        if ($value === null && $locale !== self::FALLBACK) {
            $value = self::resolve($key, self::FALLBACK);
        }
        if ($value === null) {
            $value = $key;
        }

        return $replace ? self::interpolate($value, $replace) : $value;
    }

    /** Bulk-set or override a catalog (useful for tests). */
    public static function override(string $locale, array $messages): void
    {
        $locale = self::normalize($locale);
        self::$catalogs[$locale] = array_replace_recursive(
            self::$catalogs[$locale] ?? [],
            $messages
        );
        self::$loaded[$locale] = true;
    }

    /** Reset all in-memory state (tests). */
    public static function reset(): void
    {
        self::$locale   = null;
        self::$catalogs = [];
        self::$loaded   = [];
    }

    // ── Internals ───────────────────────────────────────────────────────

    private static function resolve(string $key, string $locale): ?string
    {
        self::loadLocale($locale);
        $parts   = explode('.', $key);
        $cursor  = self::$catalogs[$locale] ?? null;
        foreach ($parts as $p) {
            if (!is_array($cursor) || !array_key_exists($p, $cursor)) {
                return null;
            }
            $cursor = $cursor[$p];
        }
        return is_string($cursor) ? $cursor : null;
    }

    private static function loadLocale(string $locale): void
    {
        if (isset(self::$loaded[$locale])) return;

        $file = __DIR__ . '/lang/' . $locale . '.php';
        if (is_file($file)) {
            $data = include $file;
            self::$catalogs[$locale] = is_array($data) ? $data : [];
        } else {
            self::$catalogs[$locale] = [];
        }
        self::$loaded[$locale] = true;
    }

    private static function interpolate(string $message, array $replace): string
    {
        foreach ($replace as $k => $v) {
            $message = str_replace(':' . $k, (string) $v, $message);
        }
        return $message;
    }

    /**
     * Normalizes locale codes:
     *   pt-BR → pt        (sem catálogo regional)
     *   pt-PT → pt-PT     (catálogo regional existe)
     *   en-US → en
     *   pt    → pt
     */
    private static function normalize(string $locale): string
    {
        $locale = strtolower(trim(str_replace('_', '-', $locale)));
        if ($locale === '') return self::FALLBACK;
        if (str_contains($locale, '-')) {
            [$base, $region] = explode('-', $locale, 2);
            // Catálogo regional (ex: pt-PT.php) tem precedência sobre o base
            $regional = $base . '-' . strtoupper($region);
            if (is_file(__DIR__ . '/lang/' . $regional . '.php')) {
                return $regional;
            }
            return $base;
        }
        return $locale;
    }
}
