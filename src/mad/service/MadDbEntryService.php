<?php

namespace Mad\Service;

use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Http\MadStateCrypt;

/**
 * MadDbEntryService — endpoint AJAX para autocomplete do dbentry-field.
 *
 * Retorna valores de texto (nao id/label) de uma coluna do banco.
 * A config da query e criptografada server-side no render do Blade.
 * O cliente envia apenas { token, q }.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher (MadAppController
 * so roda subclasses); o unico entry point e o metodo estatico via ?static=1.
 *
 * Chamado via: /app/MadDbEntryService/onSearch?static=1
 */
class MadDbEntryService extends MadComponent
{
    public static function onSearch(array $param = []): void
    {
        $token = (string) ($param['token'] ?? '');
        $q     = trim((string) ($param['q'] ?? ''));

        $items = [];
        $error = null; // aviso da falha, só com APP_DEBUG (\Mad\Form\OptionsLoadError)

        if ($token !== '' && $q !== '') {
            $config = MadStateCrypt::decryptFor('db-entry', $token);
            if (is_array($config)) {
                $database = (string)($config['database'] ?? (config('mad.main_database') ?: config('database.default', 'default')));
                $model    = (string)($config['model']    ?? '');
                $column   = (string)($config['column']   ?? '');
                $limit    = (int)($config['limit']       ?? 20);

                $querySql      = (string)($config['query_sql'] ?? '');
                $queryBindings = (array)($config['query_bindings'] ?? []);

                if ($column !== '' && ($querySql !== '' || $model !== '')) {
                    try {
                        $isPgsql = DB::connection($database)->getDriverName() === 'pgsql';
                        $term    = str_replace(' ', '%', $q);

                        if ($querySql !== '') {
                            // Caminho :query — derived table (fromRaw), builder-native
                            $qb = DB::connection($database)->query()
                                ->fromRaw("({$querySql}) as mad_q", $queryBindings);
                            if ($isPgsql) {
                                $qb->where($column, 'ilike', "%{$term}%");
                            } else {
                                $qb->whereRaw("lower({$column}) like ?", [strtolower("%{$term}%")]);
                            }
                            $collection = $qb->orderBy($column)->limit($limit)->get();
                        } else {
                            // F5: fallback model (sem :query) — busca direta no Query Builder.
                            $__cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                            // toBase() (não getQuery()): aplica os global scopes do
                            // model — unidade/tenant e soft delete.
                            $qb = ($database ? $__cls::on($database) : $__cls::query())->toBase();
                            if ($isPgsql) {
                                $qb->where($column, 'ilike', "%{$term}%");
                            } else {
                                $qb->whereRaw("lower({$column}) like ?", [strtolower("%{$term}%")]);
                            }
                            $collection = $qb->orderBy($column)->limit($limit)->get();
                        }

                        // DISTINCT em PHP (o group via SQL variava por driver)
                        foreach ($collection as $object) {
                            $val = (string)($object->{$column} ?? '');
                            if ($val !== '' && !in_array($val, $items, true)) {
                                $items[] = $val;
                            }
                        }
                    } catch (\Throwable $e) {
                        // Sem sugestões para o usuário, como antes; o motivo vai
                        // para o log em vez de sumir (fórum #41).
                        $items = [];
                        $error = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbentry-field (busca)', [
                            'model'    => $model !== '' ? $model : 'consulta (:query)',
                            'database' => $database,
                            'display'  => $column,
                        ]);
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode($error !== null ? ['items' => $items, 'error' => $error] : ['items' => $items]);
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
