<?php

namespace Mad\Registry;

/**
 * ServiceRegistry — resolve um identificador de service para o FQCN real, varrendo
 * `App\Service\**` recursivamente. Estende {@see AbstractClassRegistry} (esqueleto
 * comum com Control/ModelRegistry) e adiciona só o que é específico da camada de
 * serviço da app (pós-normalização por domínio).
 *
 * Pós-normalização os services vivem em sub-namespaces por domínio:
 *   App\Service\Iam\AuthenticationService   (era ApplicationAuthenticationService)
 *   App\Service\Comm\ChatService            (era SystemChatService)
 *   App\Service\Log\RequestLogService       (era SystemRequestLogService)
 *   App\Service\Builder\BuilderPageService  (nome preservado)
 *
 * Índice: basename da classe -> FQCN (os 45 basenames são únicos). Mais a LEGACY
 * map: os nomes ANTIGOS dos 12 services renomeados -> FQCN novo, pra que refs bare
 * legadas (`SystemChatService::isEnabled()`, `ApplicationAuthenticationService::...`)
 * e strings em config continuem resolvendo via {@see \global_services.php}. É a
 * ponte permanente, igual ao que o compat.php faz com os nomes legados.
 *
 * Resolve por classe, interface OU trait (services podem ser contratos). Memoizado
 * por processo. Home em Mad\Registry (prefixo PSR-4 já existente — src/mad/registry/)
 * pelo mesmo motivo do ModelRegistry: zero landmine de autoload no CI Linux.
 */
class ServiceRegistry extends AbstractClassRegistry
{
    /** Nomes ANTIGOS (pré-normalização) dos services renomeados => FQCN novo. */
    private const LEGACY = [
        'ApplicationAuthenticationRestService' => 'App\\Service\\Iam\\AuthenticationRestService',
        'ApplicationAuthenticationService'     => 'App\\Service\\Iam\\AuthenticationService',
        'SystemPermission'                     => 'App\\Service\\Iam\\PermissionService',
        'SystemDatabaseInformationService'     => 'App\\Service\\Sys\\DatabaseInformationService',
        'SystemPreferenceService'              => 'App\\Service\\Sys\\PreferenceService',
        'SystemAccessLogService'               => 'App\\Service\\Log\\AccessLogService',
        'SystemAccessNotificationLogService'   => 'App\\Service\\Log\\AccessNotificationLogService',
        'SystemChangeLogService'               => 'App\\Service\\Log\\ChangeLogService',
        'SystemRequestLogService'              => 'App\\Service\\Log\\RequestLogService',
        'SystemChatMessageService'             => 'App\\Service\\Comm\\ChatMessageService',
        'SystemChatService'                    => 'App\\Service\\Comm\\ChatService',
    ];

    /** @var array<string,string>|null basename|legacy => FQCN (cache PRÓPRIO). */
    protected static ?array $map = null;

    protected static function baseNamespace(): string
    {
        return 'App\\Service\\';
    }

    protected static function relativeDir(): string
    {
        return 'app/Service';
    }

    /** Pré-boot: a ponte LEGACY ainda resolve (não memoiza vazio). */
    protected static function emptyMap(): array
    {
        return self::LEGACY;
    }

    /** Service pode ser classe, interface ou trait (contratos). Filtro de descoberta. */
    protected static function accepts(string $fqcn): bool
    {
        return class_exists($fqcn) || interface_exists($fqcn) || trait_exists($fqcn);
    }

    /**
     * Service pode ser contrato: interface e trait entram no índice junto com
     * classe. Substitui o antigo `acceptsLoaded()` — a descoberta não carrega
     * mais o arquivo (tokeniza), então o filtro é pelo TIPO declarado.
     */
    protected static function acceptsKind(string $kind): bool
    {
        return in_array($kind, ['class', 'interface', 'trait'], true);
    }

    /** Curto-circuito de resolve(): input já é classe/interface/trait válida? */
    protected static function classExists(string $name): bool
    {
        return class_exists($name) || interface_exists($name) || trait_exists($name);
    }

    /** @param list<array{fqcn:string,domain:string,class:string}> $discovered */
    protected static function buildMap(array $discovered): array
    {
        $map = self::LEGACY; // nomes antigos primeiro
        foreach ($discovered as $d) {
            $map[$d['class']] = $d['fqcn']; // basename novo (único) -> FQCN
        }

        return $map;
    }
}
