<?php

namespace Mad\Webhook;

use App\Models\Iam\Tenant;
use App\Models\Iam\Unit;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;

/**
 * Contexto de unidade/empresa para processar a entrega de um webhook por
 * unidade — fora de uma requisição autenticada (fila, depois da resposta,
 * `mad:webhook:tick`).
 *
 * Reproduz as regras do `MadApiTenantMiddleware` (alias `mad.api`), que faz o
 * mesmo para um token de API:
 *
 *  - unidade existente e ativa; empresa da unidade existente e ativa;
 *  - modo de isolamento ligado + unidade sem empresa = recusa (a consulta não
 *    teria filtro nem banco próprio);
 *  - banco dedicado ligado + empresa sem banco provisionado = recusa, exceto a
 *    empresa padrão (dona do banco principal);
 *  - `TenantContext`/`UnitContext` fixados como vindos de uma credencial
 *    (`setFromToken()`: a chave do endpoint é o token da unidade — o que o
 *    handler grava é conferido contra a empresa/unidade dela, fw#534),
 *    unidade e empresa na sessão em memória (carimbo de auditoria), data-plane
 *    repontado com `TenantConnectionResolver::applyTenant`.
 *
 * Qualquer regra quebrada lança {@see WebhookScopeException} e NADA fica
 * aplicado: a entrega falha em vez de rodar no escopo errado.
 *
 * Diferente do middleware, `deactivate()` RESTAURA o que havia antes em vez de
 * zerar: o botão Reprocessar roda dentro da sessão de um administrador logado,
 * e zerar a unidade da sessão dele o tiraria da própria tela. Fora de uma
 * sessão não havia nada, e restaurar equivale a limpar.
 *
 * Uso: `activate()` e, SEMPRE em `finally`, `deactivate()`.
 */
final class WebhookScope
{
    /** Chaves da sessão em memória que o escopo fixa (as mesmas que os traits de auditoria leem). */
    private const SESSION_KEYS = ['userunitid', 'tenant_id'];

    /** @var array<int, array<string, mixed>> estados anteriores (ativações aninhadas) */
    private static array $stack = [];

    /**
     * A unidade existe e está ativa? Mesma regra do `activate()`; quem cria
     * endpoint por unidade confere antes, para não gravar endpoint órfão.
     */
    public static function unitAvailable(int $unitId): bool
    {
        $unit = $unitId > 0 ? Unit::find($unitId) : null;

        return $unit !== null && ($unit->active ?? 'Y') !== 'N';
    }

    /** @throws WebhookScopeException */
    public static function activate(int $unitId): void
    {
        $unit = Unit::find($unitId);
        if (! $unit || ($unit->active ?? 'Y') === 'N') {
            throw new WebhookScopeException('Unidade do endpoint inexistente ou inativa.');
        }

        $tenant = $unit->tenant_id ? Tenant::find($unit->tenant_id) : null;
        if ($unit->tenant_id && (! $tenant || $tenant->active !== 'Y')) {
            throw new WebhookScopeException('Empresa do endpoint inexistente ou inativa.');
        }

        // Modo de isolamento ligado + unidade sem empresa = furo de escopo.
        $multiDatabase = (string) config('mad.general.multi_database') === '1';
        $isolating = (bool) config('mad.tenant.row_scope_enabled') || $multiDatabase;
        if ($tenant === null && $isolating) {
            throw new WebhookScopeException('Unidade do endpoint sem empresa em modo de isolamento.');
        }

        // Banco dedicado: empresa sem banco provisionado leria e gravaria no
        // banco principal. Só a empresa padrão fica nele.
        $database = (string) ($tenant?->connection_name ?? '');
        if ($tenant !== null && $multiDatabase && $database === '' && ! $tenant->isDefault()) {
            throw new WebhookScopeException('Empresa do endpoint sem banco provisionado.');
        }

        $previous = self::snapshot();

        try {
            TenantContext::setFromToken($tenant?->id !== null ? (int) $tenant->id : null);
            UnitContext::setFromToken((int) $unit->id);
            self::session(['userunitid' => (int) $unit->id, 'tenant_id' => $tenant?->id]);

            if ($database !== '') {
                \TenantConnectionResolver::applyTenant($database);
            } else {
                \TenantConnectionResolver::resetToDefault();
            }
        } catch (\Throwable $e) {
            self::restore($previous);

            throw $e instanceof \InvalidArgumentException
                ? new WebhookScopeException('Empresa do endpoint sem conexão de banco válida.', 0, $e)
                : $e;
        }

        self::$stack[] = $previous;
    }

    /** Devolve o contexto ao que era antes do `activate()` correspondente. Sem ativação pendente, não faz nada. */
    public static function deactivate(): void
    {
        $previous = array_pop(self::$stack);
        if ($previous !== null) {
            self::restore($previous);
        }
    }

    /** Há um escopo de webhook aplicado agora? */
    public static function active(): bool
    {
        return self::$stack !== [];
    }

    /** @return array<string, mixed> */
    private static function snapshot(): array
    {
        $session = [];
        foreach (self::SESSION_KEYS as $key) {
            try {
                if (app()->bound('session') && session()->has($key)) {
                    $session[$key] = session()->get($key);
                }
            } catch (\Throwable $e) {
                // sem sessão utilizável (CLI): nada a guardar
            }
        }

        return [
            'tenant' => TenantContext::overridden() ? [TenantContext::id(), TenantContext::fromToken()] : null,
            'unit' => UnitContext::overridden() ? [UnitContext::id(), UnitContext::fromToken()] : null,
            'session' => $session,
            'connection' => \TenantConnectionResolver::current(),
        ];
    }

    /** @param  array<string, mixed>  $state */
    private static function restore(array $state): void
    {
        // Com a origem: o token de API que estava valendo continua conferido.
        if ($state['tenant'] === null) {
            TenantContext::clear();
        } else {
            $state['tenant'][1] ? TenantContext::setFromToken($state['tenant'][0]) : TenantContext::set($state['tenant'][0]);
        }
        if ($state['unit'] === null) {
            UnitContext::clear();
        } else {
            $state['unit'][1] ? UnitContext::setFromToken($state['unit'][0]) : UnitContext::set($state['unit'][0]);
        }

        try {
            if (app()->bound('session')) {
                session()->forget(array_values(array_diff(self::SESSION_KEYS, array_keys($state['session']))));
                if ($state['session'] !== []) {
                    session($state['session']);
                }
            }
        } catch (\Throwable $e) {
            // sem sessão utilizável (CLI): nada a limpar
        }

        if (\TenantConnectionResolver::current() === $state['connection']) {
            return;
        }

        try {
            if ($state['connection'] !== null) {
                \TenantConnectionResolver::applyTenant($state['connection']);
            } else {
                \TenantConnectionResolver::resetToDefault();
            }
        } catch (\Throwable $e) {
            \TenantConnectionResolver::resetToDefault();
        }
    }

    /** @param  array<string, mixed>  $values */
    private static function session(array $values): void
    {
        try {
            if (app()->bound('session')) {
                session($values);
            }
        } catch (\Throwable $e) {
            // sem sessão utilizável (CLI): os contextos estáticos já bastam
        }
    }
}
