<?php
namespace Mad\Grid;

use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * MadGridPdfPlaceholders — placeholders de CONTEXTO e placeholders PRÓPRIOS
 * do app nas bandas de header/footer do PDF exportado (MadGridExporter::pdf).
 *
 * Contexto ("quem emite"): {UNIT_NAME} (unidade ativa), {USER_NAME} (usuário
 * logado) e {TENANT_NAME} (empresa/tenant). Resolvidos de forma PREGUIÇOSA:
 * só quando o html custom das bandas usa o token — banda que não os cita
 * nunca toca sessão nem banco (o PDF de sempre sai byte a byte igual).
 *
 * Próprios: o app declara `['CNPJ' => '12.345…']` (chave com ou sem chaves)
 * em três camadas, da mais geral pra mais específica:
 *   1. `"placeholders"` do app/config/pdf-export.json — valores fixos
 *      digitados no MadBuilder (Configurações do projeto → Exportação de PDF),
 *      sem código;
 *   2. a classe global opcional App\Helpers\PdfExportPlaceholders::resolve()
 *      (valor calculado, vale pra todas as telas; o MadBuilder cria o
 *      esqueleto dela com um clique — kind php-helper). App\Support\… é
 *      aceito como alias da 1ª versão do contrato;
 *   3. o hook exportPdfPlaceholders() da página (vence as duas).
 * Valores são TEXTO PURO: escapados na substituição, nunca viram markup.
 * Podem sobrescrever os built-ins de texto ({APP_NAME}, {TITLE}, {DATE}, os
 * de contexto…); os ESTRUTURAIS não (PROTECTED): {LOGO} dentro de src= é
 * regex pré-mapa e {PAGE_NUM}/{PAGE_COUNT} dirigem a paginação e o 2º render
 * — sobrescrever quebraria a paginação em silêncio.
 */
final class MadGridPdfPlaceholders
{
    /** Tokens que emitem HTML/contadores — nunca sobrescrevíveis pelo app. */
    public const PROTECTED = ['{LOGO}', '{LOGO_SMALL}', '{PAGE_NUM}', '{PAGE_COUNT}'];

    /** Classe opcional do app com `public static function resolve(): array` (kind php-helper do MadBuilder). */
    public const GLOBAL_CLASS = 'App\\Helpers\\PdfExportPlaceholders';

    /** Ordem de procura: canônica primeiro, alias da 1ª versão do contrato depois. */
    public const GLOBAL_CLASSES = [self::GLOBAL_CLASS, 'App\\Support\\PdfExportPlaceholders'];

    /** Tokens de contexto resolvidos aqui (sempre presentes no mapa final). */
    public const CONTEXT_TOKENS = ['{UNIT_NAME}', '{USER_NAME}', '{TENANT_NAME}'];

    /** Chave válida: identificador — evita '', '{}', espaços e o warning do strtr. */
    private const KEY_RE = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    private static ?string $globalClass = null;
    private static bool $hasGlobalOverride = false;

    /**
     * Normaliza um mapa vindo do app: `['CNPJ' => …, '{X}' => …]` →
     * `['{CNPJ}' => 'texto', '{X}' => 'texto']`. Chave inválida (vazia, `{}`,
     * com espaço/símbolo, inteira de array-lista) ou protegida é descartada;
     * valor null vira '', escalar/Stringable vira string, array/objeto cai fora.
     */
    public static function normalize(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key)) {
                continue;
            }
            $name = trim($key);
            if (strlen($name) >= 2 && $name[0] === '{' && substr($name, -1) === '}') {
                $name = trim(substr($name, 1, -1));
            }
            if ($name === '' || !preg_match(self::KEY_RE, $name)) {
                continue;
            }
            $token = '{' . $name . '}';
            if (in_array($token, self::PROTECTED, true)) {
                continue;
            }

            if ($value === null) {
                $value = '';
            } elseif (is_scalar($value) || $value instanceof \Stringable) {
                $value = (string) $value;
            } else {
                continue;
            }

            $out[$token] = $value;
        }

        return $out;
    }

    /** Mapa da classe global do app, já normalizado; [] quando ausente ou quebrada. */
    public static function fromGlobalHook(): array
    {
        $candidates = self::$hasGlobalOverride ? [self::$globalClass] : self::GLOBAL_CLASSES;
        foreach ($candidates as $class) {
            if ($class === null || !class_exists($class) || !method_exists($class, 'resolve')) {
                continue;
            }
            try {
                $map = $class::resolve();
                return is_array($map) ? self::normalize($map) : [];
            } catch (\Throwable) {
                // Classe opcional do app: falha dela não pode derrubar o export.
                return [];
            }
        }

        return [];
    }

    /** Valores fixos do projeto (`"placeholders"` do pdf-export.json), já normalizados. */
    public static function fromProjectConfig(): array
    {
        return MadGridPdfBranding::placeholders();
    }

    /** Nome do usuário logado (app gerado: session('username')); '' sem sessão. */
    public static function userName(): string
    {
        $name = self::session('username');
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }
        try {
            $user = function_exists('auth') && app()->bound('auth') ? auth()->user() : null;
            $name = $user?->name ?? null;
        } catch (\Throwable) {
            $name = null;
        }

        return is_string($name) ? trim($name) : '';
    }

    /**
     * Mapa final pra substituição: contexto ({UNIT_NAME}/{USER_NAME}/
     * {TENANT_NAME}) + custom. As três chaves de contexto SEMPRE saem ('' quando
     * a banda não as usa ou não há como resolver — token nunca fica cru); cada
     * uma só é resolvida quando `$bandsHtml` contém o token e o custom não a
     * sobrescreveu. Custom (já normalizado) vence: array_merge com chaves string.
     */
    public static function withContext(array $custom, string $bandsHtml): array
    {
        $context = [];
        foreach (self::CONTEXT_TOKENS as $token) {
            $context[$token] = '';
            if (array_key_exists($token, $custom) || strpos($bandsHtml, $token) === false) {
                continue;
            }
            $context[$token] = match ($token) {
                '{UNIT_NAME}'   => UnitContext::name(),
                '{USER_NAME}'   => self::userName(),
                '{TENANT_NAME}' => TenantContext::name(),
            };
        }

        return array_merge($context, $custom);
    }

    /** Override da classe global (testes). null = "nenhuma classe". */
    public static function useGlobalClass(?string $fqcn): void
    {
        self::$globalClass = $fqcn;
        self::$hasGlobalOverride = true;
    }

    public static function reset(): void
    {
        self::$globalClass = null;
        self::$hasGlobalOverride = false;
    }

    /** Leitura de sessão que não lança fora de request (console, testes sem kernel). */
    private static function session(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
