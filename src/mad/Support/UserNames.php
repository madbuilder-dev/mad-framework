<?php

namespace Mad\Support;

use Illuminate\Support\Facades\DB;
use Mad\Database\DataScope;

/**
 * Nome de um usuário do sistema pelo id — para mostrar QUEM fez algo
 * ("Ana Ribeiro" em vez de "Usuário #3") num registro que mora no banco do app
 * enquanto os usuários moram no banco IAM (mad-framework#467).
 *
 * Só id → nome, sem escopo de unidade: quem vê o registro vê o nome de quem o
 * escreveu, inclusive de usuário de outra unidade ou já excluído. Memo por
 * request (uma consulta por usuário, não por linha). Sem cadastro de usuários
 * (app antigo, conexão `iam` ausente) devolve null e quem chama mantém o texto
 * de antes.
 */
final class UserNames
{
    /** Model dos usuários no app gerado (pode não existir). */
    private const MODEL = '\App\Models\Iam\User';

    public static function of(mixed $id): ?string
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $id = (int) $id;

        return DataScope::memo('mad.user_names', (string) $id, static fn (): ?string => self::load($id));
    }

    private static function load(int $id): ?string
    {
        try {
            $model = self::MODEL;
            $name  = class_exists($model)
                ? $model::query()->withoutGlobalScopes()->whereKey($id)->value('name')
                : DB::connection('iam')->table('mad_iam_user')->where('id', $id)->value('name');
        } catch (\Throwable) {
            return null;
        }

        $name = is_string($name) ? trim($name) : '';

        return $name !== '' ? $name : null;
    }
}
