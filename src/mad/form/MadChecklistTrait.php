<?php
namespace Mad\Form;
use Mad\Component\MadComponent;


/**
 * MadChecklistTrait — helper para salvar/carregar seleções de um mad-checklist-field.
 *
 * Models Eloquent.
 *
 * Uso:
 *   class MyForm extends MadComponent {
 *       use \Mad\Form\MadChecklistTrait;
 *
 *       // Salvar:
 *       $this->saveChecklist(IamGroupProgram::class, 'program_id', $id, 'group_id', $data->groups);
 *
 *       // Salvar com dados extras por item:
 *       $this->saveChecklist(IamGroupProgram::class, 'program_id', $id, 'group_id', $data->groups,
 *           fn($record, $itemId) => $record->actions = json_encode($_POST["{$itemId}_actions"] ?? [])
 *       );
 *
 *       // Carregar:
 *       $cl = $this->loadChecklist(IamGroupProgram::class, 'program_id', $id, 'group_id');
 *       $selectedIds = $cl['selected'];
 *
 *       // Carregar com dados extras:
 *       $cl = $this->loadChecklist(IamGroupProgram::class, 'program_id', $id, 'group_id',
 *           fn($record) => json_decode($record->actions ?: '[]', true)
 *       );
 *       $selectedIds  = $cl['selected'];
 *       $extrasById   = $cl['extras'];  // [itemId => data, ...]
 *   }
 */
trait MadChecklistTrait
{
    /**
     * Persiste as seleções de um checklist.
     *
     * Estratégia: delete all + insert (simples e correto para tabelas pivot).
     *
     * @param string        $model       Classe do pivot (ex: IamGroupProgram::class)
     * @param string        $fk          FK do pai (ex: 'program_id')
     * @param mixed         $parentId    Valor da FK do pai
     * @param string        $itemFk      FK do item selecionado (ex: 'group_id')
     * @param array|null    $selectedIds IDs selecionados do checklist
     * @param callable|null $each        fn($record, $itemId): void — roda antes de persistir
     * @return array                     Instâncias salvas
     */
    public function saveChecklist(
        string    $model,
        string    $fk,
        mixed     $parentId,
        string    $itemFk,
        mixed     $selectedIds = null,
        ?callable $each = null
    ): array {
        // Deleta todos os registros existentes para este pai
        $model::where($fk, $parentId)->delete();

        $saved = [];
        if (empty($selectedIds)) return $saved;

        // Normaliza: string CSV → array
        if (is_string($selectedIds)) {
            $selectedIds = $selectedIds !== '' ? explode(',', $selectedIds) : [];
        }
        $selectedIds = array_filter(array_map('trim', (array)$selectedIds), fn($v) => $v !== '');

        foreach ((array)$selectedIds as $itemId) {
            $record = new $model();
            $record->$fk     = $parentId;
            $record->$itemFk = (int)$itemId;

            if ($each) {
                $each($record, $itemId);
            }

            $record->save();
            $saved[] = $record;
        }

        return $saved;
    }

    /**
     * Carrega os IDs selecionados de um checklist e opcionalmente dados extras por item.
     *
     * @param string        $model    Classe do pivot (ex: IamGroupProgram::class)
     * @param string        $fk       FK do pai (ex: 'program_id')
     * @param mixed         $parentId Valor da FK do pai
     * @param string        $itemFk   FK do item (ex: 'group_id')
     * @param callable|null $mapExtra fn($record): mixed — extrai dados extras por item
     * @return array                  ['selected' => [id1, id2, ...], 'extras' => [id => data, ...]]
     */
    public function loadChecklist(
        string    $model,
        string    $fk,
        mixed     $parentId,
        string    $itemFk,
        ?callable $mapExtra = null
    ): array {
        $items = $model::where($fk, $parentId)->get();

        $selected = [];
        $extras   = [];
        foreach ($items as $item) {
            $itemId = (string)$item->$itemFk;
            $selected[] = $itemId;
            if ($mapExtra) {
                $extras[$itemId] = $mapExtra($item);
            }
        }

        return ['selected' => $selected, 'extras' => $extras];
    }
}
