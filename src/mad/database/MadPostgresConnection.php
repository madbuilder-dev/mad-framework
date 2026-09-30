<?php

declare(strict_types=1);

namespace Mad\Database;

use Illuminate\Database\PostgresConnection;
use PDO;

/**
 * MadPostgresConnection — conexão pgsql que se comporta igual com e sem PgBouncer.
 *
 * No MadCloud o app fala com o PostgreSQL através do PgBouncer em modo
 * transaction, que não aceita prepared statement nomeado; por isso o control
 * plane liga `DB_EMULATE_PREPARES` e o PDO passa a montar o SQL ele mesmo.
 *
 * Nessa emulação o PDO escreve `PDO::PARAM_INT` como literal SEM aspas — e o
 * Laravel binda todo `int` como PARAM_INT, e todo `bool` vira `int` antes
 * (`Connection::prepareBindings`). O `false` de um toggle chega ao banco como
 * `set "ativo" = 0`, um literal `integer`, que o PostgreSQL recusa para coluna
 * `boolean`:
 *
 *   SQLSTATE[42804] column "ativo" is of type boolean but expression is of type integer
 *   SQLSTATE[42883] operator does not exist: boolean = integer      (no where)
 *
 * Sem a emulação (dev, Teste Online, qualquer PostgreSQL direto) o pdo_pgsql
 * manda TODO parâmetro como texto de tipo não especificado e o servidor infere
 * o tipo pelo contexto — `'0'` vira `false` numa coluna boolean e `0` numa
 * integer. Por isso a mesma tela funciona no teste e quebra publicada.
 *
 * O conserto reproduz essa semântica: com a emulação ligada, int e bool são
 * bindados como PARAM_STR, então viram `'0'` — literal sem tipo, resolvido pelo
 * contexto exatamente como no modo nativo. Sem a emulação nada muda.
 */
class MadPostgresConnection extends PostgresConnection
{
    public function bindValues($statement, $bindings)
    {
        if (! $this->emulatesPrepares()) {
            parent::bindValues($statement, $bindings);

            return;
        }

        foreach ($bindings as $key => $value) {
            $statement->bindValue(
                is_string($key) ? $key : $key + 1,
                $value,
                is_resource($value) ? PDO::PARAM_LOB : PDO::PARAM_STR,
            );
        }
    }

    /** Lido da config (é ela que o `config/database.php` monta a partir do env). */
    public function emulatesPrepares(): bool
    {
        $options = $this->getConfig('options');

        return is_array($options) && ! empty($options[PDO::ATTR_EMULATE_PREPARES]);
    }
}
