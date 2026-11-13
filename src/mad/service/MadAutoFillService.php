<?php

namespace Mad\Service;

use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Form\MadFillTransform;
use Mad\Form\MadRecordPath;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadStateCrypt;

/**
 * MadAutoFillService — endpoint AJAX que resolve o auto-fill (`<fill>`) dos
 * selects de banco (<mad-dbcombo-field> / <mad-dbunique-search-field>).
 *
 * Quando o usuário seleciona um registro, o JS chama este endpoint com apenas
 * { token, value }. A config (model, key, database, query_sql, fills) NUNCA vem
 * do cliente: é criptografada server-side no render via MadStateCrypt::encrypt()
 * e embutida no DOM em data-mad-autofill-token. O servidor decripta, re-carrega
 * o registro selecionado, resolve cada `from` (MadRecordPath) e aplica o
 * `transform` (MadFillTransform), devolvendo os valores prontos pro formulário.
 *
 * SEGURANÇA: o token carrega query_sql+bindings (mesma SQL do combo, com
 * :query/:filters e soft-delete já baked). O `value` recebido é validado contra
 * esse conjunto (derived table + WHERE key=value) ANTES de resolver — um value
 * forjado fora do filtro não retorna nada. Espelha MadDbSearchService::onSearch.
 *
 * Estende MadComponent apenas pelo contrato do dispatcher (MadAppController só
 * roda subclasses); o único entry point é o método estático via ?static=1.
 *
 * Chamado via: /app/services/auto-fill/resolve?static=1
 */
class MadAutoFillService extends MadComponent
{
    public static function resolve(array $param = []): void
    {
        $token = (string) ($param['token'] ?? '');
        $value = (string) ($param['value'] ?? '');

        $values    = [];
        $onlyEmpty = [];
        $warnings  = [];

        if ($token !== '' && $value !== '') {
            $config = MadStateCrypt::decryptFor('auto-fill', $token);
            if (is_array($config)) {
                $database = (string) ($config['database'] ?? (config('mad.main_database') ?: config('database.default', 'default')));
                $model    = (string) ($config['model'] ?? '');
                $key      = (string) ($config['key'] ?? 'id');
                $fills    = (array)  ($config['fills'] ?? []);
                $querySql = (string) ($config['query_sql'] ?? '');
                $bindings = (array)  ($config['query_bindings'] ?? []);

                // Campos do formulário ligados pela relação da própria chave (`peca->…`
                // quando o combo é `peca_id`): pedidos pelo navegador e aceitos só com o
                // prefixo do token (fórum #291 r4).
                $relName = (string) ($config['rel_fill'] ?? '');
                foreach (self::relFillFields($relName, (string) ($param['fields'] ?? '')) as $relField) {
                    // O registro carregado é o escolhido no combo (a relação): o campo
                    // dele é o que vem depois de `rel->` (`peca->estoque_atual` → `estoque_atual`).
                    $fills[] = ['field' => $relField, 'from' => substr($relField, strlen($relName) + 2)];
                }

                $record = self::loadRecord($database, $model, $key, $value, $querySql, $bindings);

                if ($record !== null) {
                    foreach ($fills as $f) {
                        $field = (string) ($f['field'] ?? '');
                        $from  = (string) ($f['from'] ?? '');
                        if ($field === '' || $from === '') continue;

                        $raw = MadRecordPath::resolve((object) $record, $from);

                        $fieldWarnings = [];
                        $values[$field] = MadFillTransform::apply(
                            (string) ($f['transform'] ?? ''), $raw, (object) $record, $fieldWarnings
                        );
                        foreach ($fieldWarnings as $w) {
                            $warnings[] = "campo '{$field}': {$w}";
                        }

                        if (!empty($f['only_empty'])) {
                            $onlyEmpty[] = $field;
                        }
                    }
                }
            }
        }

        header('Content-Type: application/json');
        echo json_encode(['values' => (object) $values, 'onlyEmpty' => $onlyEmpty, 'warnings' => $warnings], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Campos `rel->campo` pedidos pelo navegador que a relação `$rel` permite:
     * só um elo (o campo ligado pela chave), nome simples, no máximo 50.
     *
     * @return list<string>
     */
    public static function relFillFields(string $rel, string $raw): array
    {
        if ($rel === '' || $raw === '' || !preg_match('/^[a-zA-Z_]\w*$/', $rel)) {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $name) {
            $name = trim($name);
            if (preg_match('/^' . preg_quote($rel, '/') . '->[a-zA-Z_]\w*$/', $name) === 1 && !in_array($name, $out, true)) {
                $out[] = $name;
            }
            if (count($out) >= 50) {
                break;
            }
        }

        return $out;
    }

    /**
     * Carrega o registro selecionado respeitando o filtro do combo.
     *
     * 1) Gate de segurança: se há query_sql, a PK precisa pertencer ao conjunto
     *    filtrado (derived table + WHERE key=value). Bloqueia value forjado.
     * 2) Carga: com model conhecido, usa Eloquent (relações disponíveis p/
     *    from="a->b->c"); sem model (só :query cru), usa a própria derived table
     *    (apenas colunas planas — relações resolvem para '').
     */
    private static function loadRecord(
        string $database,
        string $model,
        string $key,
        string $value,
        string $querySql,
        array $bindings
    ): ?object {
        try {
            if ($querySql !== '') {
                $inSet = DB::connection($database)->query()
                    ->fromRaw("({$querySql}) as mad_q", $bindings)
                    ->where($key, $value)
                    ->exists();
                if (!$inSet) {
                    return null; // value fora do filtro :query/:filters — bloqueado
                }
            }

            if ($model !== '') {
                $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
                return $cls::query()->where($key, '=', $value)->first();
            }

            if ($querySql !== '') {
                return DB::connection($database)->query()
                    ->fromRaw("({$querySql}) as mad_q", $bindings)
                    ->where($key, $value)
                    ->first();
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    /** Nunca renderiza — endpoint estático puro. */
    protected function view(): string|array
    {
        return '';
    }
}
