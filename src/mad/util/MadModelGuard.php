<?php
namespace Mad\Util;

/**
 * MadModelGuard
 *
 * Lista negra de modelos sensiveis que NUNCA podem ser consultados ou
 * manipulados por servicos publicos do framework (`Mad\Service\Mad*Service`).
 *
 * Defesa em profundidade: ainda que um desenvolvedor referencie um destes
 * modelos em um componente que termine acessivel publicamente (ex: combo
 * de busca em pagina sem login), a operacao e bloqueada.
 *
 * Lista pode ser estendida em runtime via {@see self::block()}.
 */
class MadModelGuard
{
    /** @var string[] */
    private static array $blocked = [
        'IamUser',
        'SystemUser',
        'LogAccess',
        'LogSql',
        'LogRequest',
        'CommMessage',
        'SysPreference',
        'SystemPasswordReset',
        'SystemTwoFactor',
    ];

    /** Adiciona um modelo a lista negra em runtime (init/bootstrap). */
    public static function block(string $modelClass): void
    {
        $name = trim($modelClass, '\\');
        if ($name !== '' && !in_array($name, self::$blocked, true)) {
            self::$blocked[] = $name;
        }
    }

    /**
     * Remove um modelo da lista negra para a request atual.
     *
     * Use em contextos administrativos autenticados onde a busca em modelos
     * sensiveis e legitima (ex: form de gestao de usuarios). Idealmente
     * chame APOS a verificacao de permissao do usuario.
     *
     * Padroes de uso recomendados:
     *
     *  1) Por-componente (mais seguro — escopo apertado):
     *     public function mount(): void {
     *         MadModelGuard::unblock('IamUser');
     *         $this->form = new MadForm('form');
     *     }
     *
     *  2) Bootstrap admin autenticado (mais conveniente):
     *     // em init.php apos resolver login:
     *     if (session('logged')) {
     *         MadModelGuard::unblock('IamUser');
     *         MadModelGuard::unblock('CommMessage');
     *     }
     *
     *  3) Por-action explicita com permissao:
     *     public function onAlgumaCoisa(): MadResponse {
     *         if (!$user->isAdmin()) throw new Exception('forbidden');
     *         MadModelGuard::unblock('IamUser');
     *         // ... logica admin ...
     *     }
     *
     * NUNCA chame unblock() incondicionalmente em codigo acessivel
     * publicamente — derrota a defesa em profundidade.
     */
    public static function unblock(string $modelClass): void
    {
        $name  = trim($modelClass, '\\');
        $short = ltrim(strrchr($name, '\\') ?: $name, '\\');
        self::$blocked = array_values(array_filter(
            self::$blocked,
            fn($m) => $m !== $name && $m !== $short
        ));
    }

    /** Reset para os defaults (testes). */
    public static function reset(): void
    {
        self::$blocked = [
            'IamUser', 'SystemUser',
            'LogAccess', 'LogSql', 'LogRequest',
            'CommMessage', 'SysPreference',
            'SystemPasswordReset', 'SystemTwoFactor',
        ];
    }

    /** Verifica se o modelo esta bloqueado (case-sensitive, nome curto). */
    public static function isBlocked(string $modelClass): bool
    {
        $name = trim($modelClass, '\\');
        if ($name === '') return false;
        // Tambem aceita FQCN comparando o nome curto.
        $short = ltrim(strrchr($name, '\\') ?: $name, '\\');
        return in_array($name, self::$blocked, true)
            || in_array($short, self::$blocked, true);
    }

    /** Loga tentativa bloqueada (usado pelos services). */
    public static function logBlocked(string $service, string $model): void
    {
        if (function_exists('error_log')) {
            error_log("[MadModelGuard] $service tentativa bloqueada para modelo: $model");
        }
    }
}
