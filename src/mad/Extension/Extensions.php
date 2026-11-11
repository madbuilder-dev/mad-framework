<?php

namespace Mad\Extension;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pontos de extensão das telas do app para pacotes opcionais (Propriedades do
 * projeto → Integrações no MadBuilder). Um pacote registra no `boot()` do
 * ServiceProvider dele:
 *
 *  - uma aba em Configurações da conta (SettingsForm), por usuário:
 *      Extensions::accountTab('google-calendar', [
 *          'label' => fn () => __('google-calendar::ui.tab'),
 *          'icon'  => 'calendar',
 *          'view'  => 'google-calendar::account-tab',   // MadBlade::addNamespace
 *          'data'  => fn () => [...],                    // variáveis da view
 *          'visible' => fn () => true,                   // opcional
 *      ]);
 *
 *  - uma aba em Preferências (PreferenceForm), do administrador. Os campos
 *    da aba são preferências comuns (`mad_sys_preference`), carregadas e
 *    gravadas pelo Salvar da própria tela; `secrets` seguem o contrato do
 *    PreferenceSecrets (cifrados, nunca voltam à tela, em branco mantém):
 *      Extensions::preferenceTab('google-calendar', [
 *          'label' => ..., 'icon' => ..., 'view' => ..., 'data' => ...,
 *          'fields'  => ['google_calendar_client_id'],
 *          'arrays'  => ['google_calendar_screens'],      // lista → "a,b,c"
 *          'secrets' => ['google_calendar_client_secret' => 'Preferências › Google Agenda › Client Secret'],
 *      ]);
 *
 * Erro dentro de uma extensão nunca derruba a tela: a aba some e o log diz por quê.
 */
final class Extensions
{
    public const ACCOUNT = 'account';

    public const PREFERENCES = 'preferences';

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $tabs = [self::ACCOUNT => [], self::PREFERENCES => []];

    /** @param array<string, mixed> $def */
    public static function accountTab(string $name, array $def): void
    {
        self::register(self::ACCOUNT, $name, $def);
    }

    /** @param array<string, mixed> $def */
    public static function preferenceTab(string $name, array $def): void
    {
        self::register(self::PREFERENCES, $name, $def);
    }

    /** @param array<string, mixed> $def */
    private static function register(string $slot, string $name, array $def): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name) || ! is_string($def['view'] ?? null)) {
            return;
        }
        self::$tabs[$slot][$name] = $def + ['name' => $name];
    }

    /** Para testes. */
    public static function reset(): void
    {
        self::$tabs = [self::ACCOUNT => [], self::PREFERENCES => []];
    }

    /** @return list<string> */
    public static function names(string $slot): array
    {
        return array_keys(self::$tabs[$slot] ?? []);
    }

    /**
     * Abas visíveis agora, prontas para a tela: name, label, icon, view, data.
     *
     * @return list<array{name: string, label: string, icon: string, view: string, data: array<string, mixed>}>
     */
    public static function tabs(string $slot): array
    {
        $out = [];
        foreach (self::$tabs[$slot] ?? [] as $name => $def) {
            try {
                $visible = $def['visible'] ?? null;
                if ($visible instanceof Closure && ! $visible()) {
                    continue;
                }
                $data = $def['data'] ?? [];
                $out[] = [
                    'name' => $name,
                    'label' => (string) self::value($def['label'] ?? $name),
                    'icon' => (string) ($def['icon'] ?? 'puzzle'),
                    'view' => (string) $def['view'],
                    'data' => (array) ($data instanceof Closure ? $data() : $data),
                ];
            } catch (Throwable $e) {
                Log::warning("Extensão '{$name}' fora da tela {$slot}: ".$e->getMessage());
            }
        }

        return $out;
    }

    /**
     * Preferências comuns que as abas de Preferências declaram.
     *
     * @return list<string>
     */
    public static function preferenceFields(): array
    {
        $out = [];
        foreach (self::$tabs[self::PREFERENCES] as $def) {
            foreach (array_merge((array) ($def['fields'] ?? []), (array) ($def['arrays'] ?? [])) as $key) {
                if (self::validKey($key)) {
                    $out[] = $key;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    public static function preferenceArrayFields(): array
    {
        $out = [];
        foreach (self::$tabs[self::PREFERENCES] as $def) {
            foreach ((array) ($def['arrays'] ?? []) as $key) {
                if (self::validKey($key)) {
                    $out[] = $key;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Segredos que as abas de Preferências declaram: chave => onde é informado.
     *
     * @return array<string, string>
     */
    public static function preferenceSecrets(): array
    {
        $out = [];
        foreach (self::$tabs[self::PREFERENCES] as $def) {
            foreach ((array) ($def['secrets'] ?? []) as $key => $label) {
                if (is_int($key)) {
                    [$key, $label] = [(string) $label, (string) $label];
                }
                if (self::validKey($key)) {
                    $out[$key] = (string) $label;
                }
            }
        }

        return $out;
    }

    private static function validKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[a-z][a-z0-9_]{2,63}$/', $key) === 1;
    }

    private static function value(mixed $v): mixed
    {
        return $v instanceof Closure ? $v() : $v;
    }
}
