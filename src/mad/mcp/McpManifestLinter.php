<?php

namespace Mad\Mcp;

/**
 * McpManifestLinter
 *
 * Gate de seguranca ESTATICO sobre o manifest (FURO #2/#4 em CI). Garante que
 * ninguem exponha uma entidade ou tool custom sem decisao de escopo:
 *   - tool de dados (crud/query): a entidade PRECISA ter coluna de escopo
 *     (`owner_column` e/ou `unit_column`) OU estar marcada `exempt = true`
 *     (lookup/referencia revisada);
 *   - tool `kind:custom`: PRECISA declarar um param tipo `scope`/`actor`
 *     (ator injetado server-side) OU `reviewed:true` (revisada manualmente).
 *
 * Puro/sem efeito colateral — usado pelo McpManifestSecurityLintTest e
 * disponivel p/ um lint de CLI/CI sobre qualquer mcp.config.json.
 */
final class McpManifestLinter
{
    /**
     * @return array<int,string> violacoes (vazio = manifest seguro)
     */
    public static function violations(McpManifest $manifest): array
    {
        $out = [];
        foreach ($manifest->tools() as $spec) {
            $id   = (string) ($spec['id'] ?? '?');
            $kind = strtolower((string) ($spec['kind'] ?? 'crud')); // factory: default = crud

            if ($kind === 'custom') {
                if (empty($spec['reviewed']) && ! self::hasInjectParam($spec)) {
                    $out[] = "tool custom '{$id}' sem param scope/actor nem reviewed:true";
                }
                continue;
            }

            // tool de dados (crud/query) — opera sobre uma entidade
            $entity = (string) ($spec['entity'] ?? '');
            if ($entity === '') {
                $out[] = "tool '{$id}' (kind={$kind}) sem entity";
                continue;
            }
            $hasColumn = $manifest->ownerColumnFor($entity) !== null || $manifest->unitColumnFor($entity) !== null;
            if (! $hasColumn && ! $manifest->isScopeExempt($entity)) {
                $out[] = "entidade '{$entity}' exposta por '{$id}' sem owner_column/unit_column nem exempt";
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string,mixed> $spec */
    private static function hasInjectParam(array $spec): bool
    {
        foreach ((array) ($spec['params'] ?? []) as $p) {
            $p    = rtrim((string) $p, '?');
            $type = strtolower(trim((string) (explode(':', $p, 2)[1] ?? '')));
            if ($type === 'scope' || $type === 'actor') {
                return true;
            }
        }

        return false;
    }
}
