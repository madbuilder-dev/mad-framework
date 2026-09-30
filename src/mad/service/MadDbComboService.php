<?php

namespace Mad\Service;

use Mad\Component\MadComponent;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadStateCrypt;

/**
 * MadDbComboService — endpoint AJAX para recarregar options de dbcombo-field.
 *
 * Usado pelo depends-on: quando o campo pai muda, o JS chama este endpoint
 * para carregar as options filtradas do campo filho. Tambem atende o refresh
 * das dbcombos internas do <mad-quick-form> (token com flag `full` — lista
 * completa, sem filtro de coluna, recarregada na abertura do popover).
 *
 * SEGURANCA: a config da query (model, database, key, display, order, column)
 * NUNCA vem do cliente. Ela e criptografada server-side no render
 * do dbcombo-field via MadStateCrypt::encrypt() e embutida no DOM como token
 * opaco em data-mad-dep-token. O cliente envia apenas { token, value }.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher (MadAppController
 * so roda subclasses); o unico entry point e o metodo estatico via ?static=1.
 *
 * Chamado via: /app/MadDbComboService/load?static=1
 */
class MadDbComboService extends MadComponent
{
    public static function load(array $param = []): void
    {
        $token = (string) ($param['token'] ?? '');
        $value = (string) ($param['value'] ?? '');

        $options = [];

        if ($token !== '') {
            $config = MadStateCrypt::decryptFor('db-combo', $token);
            if (is_array($config)) {
                $database = (string)($config['database'] ?? (config('mad.main_database') ?: config('database.default', 'default')));
                $model    = (string)($config['model']    ?? '');
                $key      = (string)($config['key']      ?? 'id');
                $display  = (string)($config['display']  ?? 'nome');
                $order    = (string)($config['order']    ?? '');
                // Token antigo não traz a direção → asc, idêntico ao de antes.
                $orderDir = (string)($config['order_dir'] ?? 'asc');
                $column   = (string)($config['column']   ?? '');

                // Modo lista-completa: token cunhado pelo quick-form
                // (MadNoResultsHelper) com flag `full` — recarrega TODAS as
                // options na abertura do popover. Fail-closed: token de cascata
                // sem `column` (legado/malformado) segue devolvendo [], a
                // ausencia de column NAO destrava o modo full.
                $isFull = !empty($config['full']);

                if ($model !== '' && ($isFull || ($column !== '' && $value !== ''))) {
                    try {
                        // F5: builder-native — filtro depends-on direto no Query Builder.
                        $__m = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
                        $__q = $__m::query();
                        if ($isFull) {
                            if (!empty($config['filters']) && is_array($config['filters'])) {
                                \Mad\Database\QuerySource::applyArrayFilters($__q, $config['filters']);
                            }
                        } else {
                            // Regra de carregamento do combo (`:filters`), cunhada
                            // no token server-side: o recarregamento da cascata
                            // aplica o MESMO filtro do 1º render. Sem isto, trocar
                            // o pai trazia de volta as linhas que a regra exclui.
                            if (! empty($config['filters']) && is_array($config['filters'])) {
                                \Mad\Database\QuerySource::applyArrayFilters($__q, $config['filters']);
                            }
                            $__q->where($column, '=', $value);
                        }
                        $items = ModelOptionsLoader::itemsFromQuery($__q, $key, $display, $order ?: null, $orderDir);
                        if ($isFull) {
                            // Lista ordenada, nao mapa: chave numerica em JSON
                            // vira objeto cujo Object.keys itera inteiros em
                            // ordem crescente — perderia o order_by.
                            $options = [];
                            foreach (($items ?: []) as $k => $v) {
                                $options[] = ['value' => (string)$k, 'label' => (string)$v];
                            }
                        } else {
                            $options = $items;
                        }
                    } catch (\Throwable $e) {
                        $options = [];
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['options' => $options]);
    }

    /** Nunca renderiza — endpoint estatico puro. */
    protected function view(): string|array
    {
        return '';
    }
}
