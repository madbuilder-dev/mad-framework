<?php
namespace Mad\Form;
use Mad\Component\MadComponent;


/**
 * MadFieldListTrait — helper para salvar/carregar itens de um mad-field-list.
 *
 * Uso:
 *   class MyForm extends MadComponent {
 *       use \Mad\Form\MadFieldListTrait;
 *   }
 */
trait MadFieldListTrait
{
    /**
     * Persiste as linhas do FieldList.
     *
     * Estratégia automática:
     *   - rows com chave 'id' → smart sync (update/insert + delete dos ausentes)
     *   - caso contrário      → delete + insert (simples e sempre correto)
     *
     * @param  string        $model     Nome da classe do model (ex: 'PedidoVendaItem')
     * @param  string        $fk        Nome da FK (ex: 'pedido_venda_id')
     * @param  mixed         $parentId  Valor da FK
     * @param  array         $rows      Rows do field-list (ex: $data['itens'])
     * @param  callable|null $each      fn(object $instance, array $row): void — roda antes do store()
     * @return array                    Instâncias salvas
     */
    public function saveDetailItems(
        string     $model,
        string     $fk,
        mixed      $parentId,
        array      $rows,
        ?callable  $each     = null
    ): array {
        // Filtra rows completamente vazias
        $rows = array_values(array_filter($rows, static function (array $row): bool {
            foreach ($row as $k => $v) {
                if (str_starts_with($k, '__')) continue;
                if (trim((string)($v ?? '')) !== '') return true;
            }
            return false;
        }));

        $saved   = [];
        // Chave do model FILHO — com a chave errada o smart-sync nunca dispara
        // e cada gravacao apaga e recria as linhas da lista.
        $detailPk = 'id';
        try {
            $__cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if ($__cls && class_exists($__cls)) $detailPk = (new $__cls())->getKeyName();
        } catch (\Throwable $e) {}

        $hasId   = !empty($rows) && array_key_exists($detailPk, $rows[0]);

        if ($hasId) {
            // ── Smart sync ────────────────────────────────────────────────
            $savedIds = [];
            foreach ($rows as $row) {
                // Sem (int): PK de texto ('ABC-1') viraria 0.
                $pkVal    = !empty($row[$detailPk]) ? $row[$detailPk] : null;
                // Eloquent: carregar por pk p/ que save() faça UPDATE (setar id num
                // model novo causaria INSERT). ESCOPADO ao pai (fk), igual ao
                // gêmeo em MadForm::_saveDetailRows: sem isso um cliente podia
                // passar o id de uma linha de OUTRO pai/tenant e re-parenteá-la
                // (IDOR de linha). Fora do escopo do pai → trata como nova
                // (INSERT), nunca sequestra a linha alheia.
                $instance = $pkVal
                    ? ($model::query()->where($fk, '=', $parentId)->whereKey($pkVal)->first() ?? new $model())
                    : new $model();
                foreach ($row as $k => $v) {
                    if (str_starts_with($k, '__')) continue;
                    // A chave ja veio do whereKey() (linha existente) ou e vazia
                    // (linha nova). Atribuir NULL explicito numa PK serial e
                    // INSERT com id=NULL — o Postgres recusa (not-null).
                    if ($k === $detailPk) continue;
                    $instance->$k = ($v === '') ? null : $v;
                }
                $instance->$fk = $parentId;
                if ($each) {
                    ($each)($instance, $row);
                }
                $instance->save();
                $saved[]    = $instance;
                $savedIds[] = $instance->getKey();
            }
            // F5: delete builder-native (Query Builder) — remove órfãos do pai.
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__dq = $__m::query()->where($fk, '=', $parentId);
            if ($savedIds) {
                $__dq->whereNotIn($detailPk, $savedIds);
            }
            $__dq->delete();
        } else {
            // ── Delete + Insert ───────────────────────────────────────────
            $__m = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__m::query()->where($fk, '=', $parentId)->delete();

            foreach ($rows as $row) {
                $instance = new $model();
                foreach ($row as $k => $v) {
                    if (str_starts_with($k, '__')) continue;
                    $instance->$k = ($v === '') ? null : $v;
                }
                $instance->$fk = $parentId;
                if ($each) {
                    ($each)($instance, $row);
                }
                $instance->save();
                $saved[] = $instance;
            }
        }

        return $saved;
    }

    /**
     * Carrega linhas do banco prontas para o mad-field-list (via FieldListColumn::normalizeRows).
     *
     * Quando $fieldListName é informado, injeta automaticamente no primeiro MadForm do componente:
     *   $this->loadDetailRows('PedidoVendaItem', 'pedido_venda_id', $id, fieldList: 'itens');
     *   // equivale a: $this->form->fields['itens'] = $rows;
     *
     * @param  string        $model         Nome da classe do model
     * @param  string        $fk            Nome da FK
     * @param  mixed         $parentId      Valor da FK
     * @param  callable|null $transform     fn(object $instance): array — campos extras/override
     * @param  string|null   $fieldListName Nome do field-list no form (auto-inject)
     * @return array                        Rows normalizadas prontas para view()
     */
    public function loadDetailRows(
        string     $model,
        string     $fk,
        mixed      $parentId,
        ?callable  $transform     = null,
        ?string    $fieldListName = null
    ): array {
        // F5: detalhe por FK builder-native (Query Builder).
        $cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $q   = $cls::query()->where($fk, '=', $parentId);

        // Ordena pela CHAVE do model: 'id' fixo era "unknown column".
        $objects = \Mad\Database\QuerySource::recordsFromQuery($q, (new $cls())->getKeyName());
        $rows    = [];

        foreach ($objects as $obj) {
            $row = method_exists($obj, 'toArray') ? $obj->toArray() : (array) $obj;
            if ($transform) {
                $extra = ($transform)($obj);
                if (is_array($extra)) {
                    $row = array_merge($row, $extra);
                }
            }
            $rows[] = $row;
        }

        $rows = FieldListColumn::normalizeRows($rows);

        // Auto-inject no primeiro MadForm encontrado
        if ($fieldListName !== null) {
            $this->_injectIntoForm($fieldListName, $rows);
        }

        return $rows;
    }

    /**
     * Injeta rows no primeiro MadForm público do componente.
     */
    private function _injectIntoForm(string $name, array $rows): void
    {
        $ref = new \ReflectionObject($this);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($this)) continue;
            $val = $prop->getValue($this);
            if ($val instanceof MadForm) {
                $val->fields[$name] = $rows;
                return;
            }
        }
    }
}