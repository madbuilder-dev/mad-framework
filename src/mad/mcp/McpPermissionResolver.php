<?php

namespace Mad\Mcp;

use Mad\Security\PermissionGate;

/**
 * McpPermissionResolver
 *
 * Quem pode usar uma tool do manifest. A regra é a que o dev configurou no
 * Studio (MCP › Permissões): acesso = manifest ∩ concessões dos perfis do
 * usuário logado.
 *
 *   1. CONCESSÃO — permissions[<perfil>][<tool>] = allow|deny, sobre os perfis
 *      EFETIVOS do usuário ({@see McpProfiles}: diretos, por papel e por papel
 *      da unidade ativa). `deny` de qualquer perfil vence; sem nenhum `allow`
 *      a tool não existe para ele (célula "herda" = sem acesso — fail-closed).
 *   2. PROGRAMA — `program` no spec (manifest escrito à mão) continua sendo uma
 *      restrição EXTRA de tela (PermissionGate::canAccess). O configurador não
 *      emite `program`: sem ele, a concessão é o piso.
 *   3. ESCOPO — tool de dados (crud/query) precisa do bloco `scope` da
 *      entidade (fail-closed por omissão, o mesmo contrato do linter/publish).
 *
 * Até o 5.96.21 o piso era só o programa (e tool sem `program` era negada):
 * como o configurador nunca emitia `program`, toda tool publicada ficava
 * invisível para todos, e a matriz era comparada com os ids do app em vez dos
 * perfis do Studio. `del_*` exigia ser admin além da matriz — com a concessão
 * explícita obrigatória esse degrau virou redundante e contradizia o que o dev
 * marcou (template "Operador" nega exclusão; "Administrador" libera). O cartão
 * de confirmação do Copilot continua em toda escrita.
 */
final class McpPermissionResolver
{
    /**
     * Gate completo de uma tool para o usuario atual.
     *
     * @param array<string,mixed> $spec tool spec do manifest (id, verb, confirm, program...)
     */
    public function canUseTool(McpManifest $manifest, array $spec): bool
    {
        return $this->denyReason($manifest, $spec) === null;
    }

    /**
     * Motivo da negacao (ou null se permitido). Usado para auditoria.
     *
     * @param array<string,mixed> $spec
     * @return 'unauthenticated'|'matriz'|'sem_concessao'|'piso'|'escopo'|null
     */
    public function denyReason(McpManifest $manifest, array $spec): ?string
    {
        if (! McpCurrentUser::isAuthenticated()) {
            return 'unauthenticated';
        }

        $decision = $this->matrixDecision($manifest, (string) ($spec['id'] ?? ''));
        if ($decision === 'deny') {
            return 'matriz';
        }
        if ($decision !== 'allow') {
            return 'sem_concessao';
        }

        if (! $this->floorAllows($spec)) {
            return 'piso';
        }

        $kind   = strtolower((string) ($spec['kind'] ?? 'crud'));
        $entity = (string) ($spec['entity'] ?? '');
        if ($kind !== 'custom' && ($entity === '' || ! $manifest->hasScopeBlock($entity))) {
            return 'escopo';
        }

        return null;
    }

    /**
     * Restrição EXTRA de tela: só quando o spec declara `program`. Sem ele a
     * concessão do perfil é o piso (é o que o configurador publica).
     */
    public function floorAllows(array $spec): bool
    {
        $program = trim((string) ($spec['program'] ?? ''));

        return $program === '' || PermissionGate::canAccess($program);
    }

    /**
     * Decisao agregada da matriz sobre os perfis EFETIVOS do usuario:
     * deny se algum nega; allow se algum permite e nenhum nega; senao null.
     */
    public function matrixDecision(McpManifest $manifest, string $toolId): ?string
    {
        if ($toolId === '') {
            return null;
        }

        $sawAllow = false;
        foreach (McpProfiles::forManifest($manifest) as $pid) {
            $d = $manifest->matrixDecision($pid, $toolId);
            if ($d === 'deny') {
                return 'deny';
            }
            if ($d === 'allow') {
                $sawAllow = true;
            }
        }

        return $sawAllow ? 'allow' : null;
    }

    /**
     * Admin = pode acessar o programa administrativo configurado
     * ([mcp] admin_program, default ProgramForm). Usado pelas tools `custom`
     * (admin-only até `reviewed:true`).
     */
    public function isAdmin(): bool
    {
        $ini     = \Mad\Core\AppConfig::get();
        $program = (string) (($ini['mcp']['admin_program'] ?? '') ?: 'ProgramForm');

        return PermissionGate::canAccess($program);
    }
}
