<?php

namespace Mad\Security;

use Illuminate\Support\Facades\DB;

/**
 * OwnerAdmin — "o administrador do dono do app", regra ÚNICA.
 *
 * É a conta que abre a Central de Comando, o GED inteiro, o editor do menu do
 * builder e a ação confirmada do agente. Até a 5.122.0 cada um desses lugares
 * conferia só `session('login') === 'admin'` — e o login é um texto do
 * cadastro: o cadastro público e o cadastro de usuários da unidade (SaaS)
 * deixavam outra pessoa usar o mesmo login (fw#103).
 *
 * ┌─ Quem é ───────────────────────────────────────────────────────────────┐
 * │ login `admin` (exato, o canônico do banco) E membro do grupo           │
 * │ Administrador (id 1). Os DOIS: o login sozinho é texto; o grupo        │
 * │ sozinho é configurável (o dono pode pô-lo no auto-cadastro).           │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Três leituras, uma regra:
 *  - session()  → o usuário DESTA sessão (lista de grupos gravada no login e no
 *                 middleware do token MCP);
 *  - isUserId() → um usuário pelo id, lendo o banco (o GED recebe o id);
 *  - userId()   → QUAL cadastro é o dono, sem ambiguidade: o mais antigo
 *                 (menor id) com login `admin` e grupo 1. É o mesmo que a
 *                 migration do login único mantém intacto quando acha logins
 *                 repetidos — o id 1 é só o costume do seed (app importado ou
 *                 com o admin recriado não tem o dono no id 1).
 *
 * `Mad\Database\AdminScope::isOwnerAdmin()` (ler todas as empresas) usa a
 * mesma regra e, além dela, recusa o administrador de unidade.
 *
 * Nada aqui lança: na dúvida, false/null (fail-closed).
 */
final class OwnerAdmin
{
    /** Login da conta do dono (o mesmo da Central de Comando). */
    public const LOGIN = 'admin';

    /** Grupo "Administrador" do IAM (id estável do seed de referência). */
    public const GROUP_ID = 1;

    private const CONN = 'iam';

    /**
     * O usuário da SESSÃO é o administrador do dono? Sessão sem a lista de
     * grupos (JWT da API, sessão incompleta) não passa.
     */
    public static function session(): bool
    {
        if ((string) self::sessionValue('login') !== self::LOGIN) {
            return false;
        }
        $groups = self::sessionValue('usergroupids');
        if (! is_array($groups)) {
            return false;
        }

        return in_array(self::GROUP_ID, array_map('intval', $groups), true);
    }

    /** O usuário deste id é o administrador do dono (login `admin` + grupo 1)? */
    public static function isUserId(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $login = DB::connection(self::CONN)->table('mad_iam_user')->where('id', $userId)->value('login');
            if ((string) $login !== self::LOGIN) {
                return false;
            }

            return DB::connection(self::CONN)->table('mad_iam_user_group')
                ->where('user_id', $userId)
                ->where('group_id', self::GROUP_ID)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Id do cadastro do dono: o mais antigo (menor id) com login `admin` e
     * grupo 1. Null quando não há nenhum (app sem o seed de referência).
     *
     * O login é conferido em PHP, byte a byte: no MySQL a comparação do banco
     * não diferencia maiúsculas e aceitaria `Admin`.
     */
    public static function userId(): ?int
    {
        try {
            $rows = DB::connection(self::CONN)->table('mad_iam_user')
                ->whereIn('id', DB::connection(self::CONN)->table('mad_iam_user_group')
                    ->where('group_id', self::GROUP_ID)
                    ->select('user_id'))
                ->where('login', self::LOGIN)
                ->orderBy('id')
                ->get(['id', 'login']);

            foreach ($rows as $row) {
                if ((string) $row->login === self::LOGIN) {
                    return (int) $row->id;
                }
            }
        } catch (\Throwable) {
            // sem as tabelas do IAM: não há dono a resolver
        }

        return null;
    }

    /** Leitura de sessão que não lança fora de request (console, testes sem kernel). */
    private static function sessionValue(string $key): mixed
    {
        try {
            return (function_exists('session') && app()->bound('session')) ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
