<?php

namespace Mad\Security;

use Mad\Database\DataScope;

/**
 * "Visível para" (fórum #346): `visible-groups` e `visible-users` na aba
 * (`<mad-tab-panel>`), na seção (`<mad-form-section>`, `<mad-card>`), na grade
 * (`<mad-grid>`, `<mad-field-list>`, `<mad-detail-form>`) e no campo.
 *
 * O MadBlade embrulha a tag num `if` que chama allows() a CADA requisição, para
 * o usuário logado — o compilado é o mesmo para todos, o resultado não. Negado,
 * o bloco não executa: nada dele vai no HTML nem no estado da tela, e os campos
 * dele ficam anotados como negados no formulário (MadForm::denyFields), que os
 * ignora no Salvar mesmo que o navegador mande.
 *
 * Regra (aprovada pelo MAD BOSS em 17/11/2026):
 *  - basta casar UM: o login está em `visible-users`, ou um dos grupos EFETIVOS
 *    do usuário (diretos + os dos papéis e da unidade, mesma regra das
 *    permissões — PermissionResolver) está em `visible-groups`;
 *  - grupo pelo CÓDIGO (o do Studio › Grupos de permissão); grupo sem código
 *    casa pelo nome. Sem diferenciar maiúsculas;
 *  - o administrador dono (login `admin`, grupo 1 — OwnerAdmin) sempre vê;
 *  - sem sessão, ou grupo/login que não existe mais (renomeado, apagado):
 *    NEGA. Nada aqui lança nem escreve o nome do grupo/usuário em mensagem.
 *
 * É restrição de TELA, não permissão do dado: o mesmo dado pode aparecer numa
 * listagem, exportação ou na API — restrinja também lá.
 */
final class VisibleFor
{
    /** Lista do atributo ("rh, financeiro") → itens sem espaço nas pontas. */
    public static function parse(string $list): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $list) ?: [] as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * O usuário da sessão vê o bloco? Listas vazias = sem restrição (true).
     *
     * @param  list<string>|string  $groups
     * @param  list<string>|string  $users
     */
    public static function allows(array|string $groups, array|string $users): bool
    {
        $groups = is_array($groups) ? $groups : self::parse($groups);
        $users  = is_array($users) ? $users : self::parse($users);
        if ($groups === [] && $users === []) {
            return true;
        }

        $login = trim((string) self::session('login'));
        $uid   = (int) self::session('userid');
        if ($login === '' || $uid <= 0) {
            return false;
        }
        if (OwnerAdmin::session()) {
            return true;
        }

        $me = mb_strtolower($login);
        foreach ($users as $u) {
            if (mb_strtolower(trim((string) $u)) === $me) {
                return true;
            }
        }

        if ($groups === []) {
            return false;
        }
        $mine = self::groupsOf($uid);
        foreach ($groups as $wanted) {
            $wanted = mb_strtolower(trim((string) $wanted));
            if ($wanted === '') {
                continue;
            }
            foreach ($mine as $g) {
                // Pelo código; grupo sem código casa pelo nome.
                $hit = $g['code'] !== ''
                    ? mb_strtolower($g['code']) === $wanted
                    : mb_strtolower($g['name']) === $wanted;
                if ($hit) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Todas as regras deixam? (`[['g' => [...], 'u' => [...]], ...]`, como o
     * formulário guarda). Lista que não é de regras = nega.
     */
    public static function allowsAll(mixed $rules): bool
    {
        if (!is_array($rules)) {
            return false;
        }
        foreach ($rules as $rule) {
            if (!is_array($rule) || !self::allows((array) ($rule['g'] ?? []), (array) ($rule['u'] ?? []))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Grupos efetivos do usuário (diretos da sessão + dos papéis e da unidade),
     * com nome e código. Memo por requisição.
     *
     * @return list<array{id: int, name: string, code: string}>
     */
    public static function groupsOf(int $uid): array
    {
        return DataScope::memo('visible-for.groups', (string) $uid, static function () use ($uid): array {
            $ids = array_map('intval', (array) (self::session('usergroupids') ?? []));
            foreach (self::resolvedGroupIds($uid) as $id) {
                $ids[] = $id;
            }
            $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
            if ($ids === [] || ! class_exists(\App\Models\Iam\Group::class)) {
                return [];
            }

            $out = [];
            foreach ([['id', 'name', 'code'], ['id', 'name']] as $cols) {
                try {
                    foreach (\App\Models\Iam\Group::query()->whereIn('id', $ids)->get($cols) as $g) {
                        $out[] = ['id' => (int) $g->id, 'name' => trim((string) ($g->name ?? '')), 'code' => trim((string) ($g->code ?? ''))];
                    }

                    return $out;
                } catch (\Throwable) {
                    $out = []; // banco antigo sem a coluna `code`: tenta só id e nome
                }
            }

            return $out;
        });
    }

    /** Grupos dados por papel (global e da unidade ativa). Fail-closed: erro = só os diretos. */
    private static function resolvedGroupIds(int $uid): array
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
        } catch (\Throwable) {
            return [];
        }
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
