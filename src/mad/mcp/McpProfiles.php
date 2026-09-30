<?php

namespace Mad\Mcp;

use Mad\Database\DataScope;

/**
 * McpProfiles — quais perfis da matriz do manifest valem para o usuário atual.
 *
 * A matriz (`permissions[<perfil>][<tool>]`) é montada no Studio (MCP ›
 * Permissões) sobre os `PermissionGroup` do projeto, e a chave é o id DA
 * PLATAFORMA. No app esses perfis viram `mad_iam_group` pelo NOME (o
 * `ProjectPermissionSeeder` faz `firstOrCreate(['name'])` e grava o `code`):
 * os ids não coincidem. Comparar o id da plataforma com o id do grupo do app
 * — o que o motor fazia — dava concessão ao grupo errado ou a nenhum.
 *
 * Com o bloco `profiles` no manifest ({@see McpManifest::profiles()}) o perfil
 * casa com o grupo do app pelo `code` quando os dois têm um, senão pelo nome
 * (sem diferenciar maiúsculas). Manifest SEM o bloco (escrito à mão, ou
 * publicado antes dele existir) segue comparando os ids crus, como sempre.
 *
 * Grupos do usuário = os EFETIVOS: diretos, dos papéis globais e dos papéis da
 * unidade ativa (licenciamento, respeitando os módulos contratados) — o mesmo
 * cálculo das telas ({@see \App\Service\Iam\PermissionResolver}). Vários
 * perfis somam; a negação de qualquer um vence (quem decide é o
 * {@see McpPermissionResolver}).
 *
 * Memo por requisição ({@see DataScope::memo()}, descartado entre requisições
 * no Octane): perfil trocado vale na próxima mensagem.
 */
final class McpProfiles
{
    /**
     * Ids de perfil (chaves de `permissions`) do usuário atual neste manifest.
     *
     * @return list<string>
     */
    public static function forManifest(McpManifest $manifest): array
    {
        $groups = self::appGroups();
        if ($groups === []) {
            return [];
        }

        if (! $manifest->hasProfiles()) {
            return array_values(array_unique(array_map(static fn (array $g): string => (string) $g['id'], $groups)));
        }

        $out = [];
        foreach ($manifest->profiles() as $profileId => $profile) {
            foreach ($groups as $g) {
                if (self::matches($profile, $g)) {
                    $out[(string) $profileId] = true;
                    break;
                }
            }
        }

        return array_map('strval', array_keys($out));
    }

    /**
     * @param array{name: string, code: string}               $profile
     * @param array{id: int, name: string, code: string}      $group
     */
    private static function matches(array $profile, array $group): bool
    {
        if ($profile['code'] !== '' && $group['code'] !== '') {
            return strcasecmp($profile['code'], $group['code']) === 0;
        }

        return $profile['name'] !== ''
            && mb_strtolower($profile['name']) === mb_strtolower(trim($group['name']));
    }

    /**
     * Grupos efetivos do usuário atual (id, nome, código).
     *
     * @return list<array{id: int, name: string, code: string}>
     */
    public static function appGroups(): array
    {
        $uid = McpCurrentUser::id();
        if ($uid === null || $uid <= 0) {
            return [];
        }

        return DataScope::memo('mcp.profiles.groups', (string) $uid, static function () use ($uid): array {
            $ids = array_map('intval', McpCurrentUser::groupIds());
            foreach (self::effectiveGroupIds($uid) as $id) {
                $ids[] = $id;
            }
            $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
            if ($ids === []) {
                return [];
            }

            $meta = self::groupMeta($ids);
            $out  = [];
            foreach ($ids as $id) {
                $out[] = ['id' => $id, 'name' => $meta[$id]['name'] ?? '', 'code' => $meta[$id]['code'] ?? ''];
            }

            return $out;
        });
    }

    /**
     * Grupos dados por papel (global e da unidade ativa). Fail-safe: sem o
     * resolver (app antigo) ou com erro, ficam só os diretos do token.
     *
     * @return list<int>
     */
    private static function effectiveGroupIds(int $uid): array
    {
        if (! class_exists(\App\Models\Iam\User::class) || ! class_exists(\App\Service\Iam\PermissionResolver::class)) {
            return [];
        }

        try {
            $user = \App\Models\Iam\User::find($uid);
            if ($user === null) {
                return [];
            }
            $unit = self::session('userunitid');
            $res  = \App\Service\Iam\PermissionResolver::resolve($user, $unit !== null && (int) $unit > 0 ? (int) $unit : null);

            return array_map('intval', (array) ($res['group_ids'] ?? []));
        } catch (\Throwable $e) {
            error_log('[mcp-profiles] grupos por papel indisponíveis: ' . $e->getMessage());

            return [];
        }
    }

    /**
     * @param  list<int> $ids
     * @return array<int, array{name: string, code: string}>
     */
    private static function groupMeta(array $ids): array
    {
        if (! class_exists(\App\Models\Iam\Group::class)) {
            return [];
        }

        $out = [];
        foreach ([['id', 'name', 'code'], ['id', 'name']] as $cols) {
            try {
                foreach (\App\Models\Iam\Group::query()->whereIn('id', $ids)->get($cols) as $g) {
                    $out[(int) $g->id] = ['name' => (string) ($g->name ?? ''), 'code' => (string) ($g->code ?? '')];
                }

                return $out;
            } catch (\Throwable) {
                // app anterior à coluna `code`: tenta só com o nome
                $out = [];
            }
        }

        return $out;
    }

    private static function session(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
