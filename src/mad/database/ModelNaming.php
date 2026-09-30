<?php

namespace Mad\Database;

/**
 * ModelNaming — convenção EXECUTÁVEL de colocação de model a partir da tabela.
 * Single source of truth p/ o scaffold, a skill mad-framework e o guard-rail
 * (ModelNamingConventionTest deriva a expectativa daqui).
 *
 * Regra:
 *   - tabela do FRAMEWORK `mad_<dominio>_<entidade...>`
 *       -> App\Models\<PascalDominio>\<PascalEntidade>   (sub-namespace por domínio)
 *       ex: mad_iam_user -> App\Models\Iam\User ; mad_comm_chat_message -> Comm\ChatMessage
 *   - tabela do APP (sem prefixo `mad_`)  -> App\Models\<PascalEntidade>  (FLAT)
 *       ex: teste_cliente -> App\Models\TesteCliente ; negociacao -> App\Models\Negociacao
 *
 * `mad_` é reservado ao framework; o domínio vem do TOKEN da tabela, não da
 * conexão (mad_sys_schedule_log fica em $connection='iam'). É o espelho-classe da
 * normalização `mad_<dominio>_<entidade>` das tabelas.
 */
class ModelNaming
{
    /**
     * Deriva a colocação do model a partir do nome da tabela.
     *
     * @return array{namespace:string,class:string,fqcn:string,path:string,domain:string}|null
     *         null se $table for vazio.
     */
    public static function forTable(string $table): ?array
    {
        $table = trim($table);
        if ($table === '') {
            return null;
        }

        if (preg_match('/^mad_([a-z0-9]+)_(.+)$/', $table, $m)) {
            $domain = self::pascal($m[1]);   // Iam
            $class  = self::pascal($m[2]);   // User | ChatMessage
        } else {
            $domain = '';                    // app-space -> flat
            $class  = self::pascal($table);  // TesteCliente
        }

        $rel  = ($domain !== '' ? $domain . '\\' : '') . $class;
        $ns   = $domain !== '' ? 'App\\Models\\' . $domain : 'App\\Models';

        return [
            'namespace' => $ns,
            'class'     => $class,
            'fqcn'      => 'App\\Models\\' . $rel,
            'path'      => 'app/Models/' . str_replace('\\', '/', $rel) . '.php',
            'domain'    => $domain,
        ];
    }

    /** snake_case -> PascalCase (chat_message -> ChatMessage). */
    public static function pascal(string $snake): string
    {
        return str_replace(' ', '', ucwords(str_replace('_', ' ', trim($snake))));
    }
}
