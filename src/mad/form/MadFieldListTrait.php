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
     *   - ALGUMA row com a chave do model → smart sync: atualiza as que trazem
     *     a chave, insere as que não trazem e apaga as ausentes
     *   - nenhuma row com a chave         → delete + insert
     *
     * A decisão olha todas as rows, não só a primeira: uma linha nova no topo
     * (Sortable) não faz mais as existentes serem apagadas e recriadas com
     * outra chave. A exclusão é feita pelo Model, linha a linha: respeita a
     * exclusão lógica da tabela e dispara `deleting`/`deleted`.
     *
     * Row que traz a chave de uma linha que não existe mais (outra aba ou outra
     * pessoa a removeu) NÃO é recriada: fica fora do retorno e o usuário é
     * avisado pela resposta da ação.
     *
     * O que é gravado de cada linha que JÁ existe: as colunas que a lista tem
     * e o que o código atribuiu — a chave cujo valor ele mudou (ou pôs) na
     * linha que passa, e o que o `$each` atribui. A linha vai inteira para o
     * navegador e volta inteira; a coluna que a lista não mostra e que a linha
     * traz com o valor que estava no banco quando o `loadDetailRows()` a leu
     * NÃO é atribuída — o que está no banco agora pode ter sido gravado por
     * outra tela (a baixa do item, o custo, a situação). É coluna a coluna:
     * pôr o custo em cada linha antes de salvar grava o custo, e só ele.
     * "O mesmo valor" não depende do tipo: 10 e '10', 1.5 e '1.50', nulo e
     * texto vazio, a data com e sem a meia-noite.
     *
     * Vale para as linhas que o `loadDetailRows()` carregou; linha nova e
     * linha carregada de outro jeito gravam todas as chaves, como sempre.
     *
     * Para dizer as colunas na própria chamada, sem depender da carga:
     *
     *   $this->saveDetailItems('PedidoItem', 'pedido_id', $id, $rows, only: ['produto_id', 'qtd']);
     *
     * A chave para outro cadastro que uma linha vai GRAVAR (o cliente, o
     * responsável) passa antes pelas regras de referência (`exists`) do
     * `rules()` do Model da linha: o campo da lista só mostra o que quem salva
     * enxerga, e a requisição alterada gravava o número que quisesse. A chave
     * que a linha já tem não é conferida de novo, e o que o `$each` atribui é
     * valor do código. Todas as linhas são conferidas antes de qualquer
     * escrita; uma recusada lança ReferenceViolation (uma
     * MadValidationException: o `catch` do `onSave` mostra a mensagem).
     *
     * @throws ReferenceViolation linha com a chave de um cadastro que quem salva não enxerga
     *
     * @param  string        $model     Nome da classe do model (ex: 'PedidoVendaItem')
     * @param  string        $fk        Nome da FK (ex: 'pedido_venda_id')
     * @param  mixed         $parentId  Valor da FK
     * @param  array         $rows      Rows do field-list (ex: $data['itens'])
     * @param  callable|null $each      fn(object $instance, array $row): void — roda antes do store()
     * @param  string|null   $fieldList Nome da lista na tela. Só é preciso quando a tela tem DUAS
     *                                  listas com o mesmo model e a mesma FK: sem ele, a lista é a
     *                                  que o `loadDetailRows()` carregou com este model e esta FK.
     * @param  list<string>|null $only  Colunas que a gravação copia de cada linha (a chave e a FK
     *                                  são tratadas à parte). O que o `$each` atribui não passa por aqui.
     * @return array                    Instâncias salvas
     */
    public function saveDetailItems(
        string     $model,
        string     $fk,
        mixed      $parentId,
        array      $rows,
        ?callable  $each      = null,
        ?string    $fieldList = null,
        ?array     $only      = null
    ): array {
        $only = $only !== null ? array_fill_keys(array_map('strval', $only), true) : null;
        $form = $this->_detailItemsForm();
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
        $detailPk   = 'id';
        $canTypeKey = false;   // a chave é digitada na tela (não incremental e gravável)?
        try {
            $__cls = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            if ($__cls && class_exists($__cls)) {
                $__probe    = new $__cls();
                $detailPk   = $__probe->getKeyName();
                $canTypeKey = !$__probe->getIncrementing() && $__probe->isFillable((string) $detailPk);
            }
        } catch (\Throwable $e) {}

        // ALGUMA linha com a chave — não só a primeira. Com a decisão presa à
        // 1ª linha, bastava a linha nova estar no topo para o save cair no
        // delete + insert e trocar a chave de todas as outras.
        $hasId = false;
        foreach ($rows as $row) {
            if (array_key_exists($detailPk, $row)) {
                $hasId = true;
                break;
            }
        }

        // As chaves para outro cadastro que as linhas vão gravar, TODAS antes
        // de qualquer escrita: a linha recusada não deixa as outras pela metade.
        $this->_guardDetailItemReferences($model, $fk, $parentId, $rows, $detailPk, $canTypeKey, $hasId, $only, $form, $fieldList);

        if ($hasId) {
            // ── Smart sync ────────────────────────────────────────────────
            $savedIds = [];
            $goneAt   = [];   // posição (a partir de 1) das linhas que outra tela já removeu
            foreach ($rows as $position => $row) {
                [$instance, $gone] = $this->_detailItemTarget($model, $fk, $parentId, $row, $detailPk, $canTypeKey);
                if ($gone) {
                    $goneAt[] = $position + 1;
                    continue;
                }
                $assign     = $this->_detailItemAssign($model, $fk, $parentId, $row, $instance, $detailPk, $only, $form, $fieldList);
                $instance ??= new $model();
                foreach ($assign as $k => $v) {
                    $instance->$k = $v;
                }
                $instance->$fk = $parentId;
                if ($each) {
                    ($each)($instance, $row);
                }
                $instance->save();
                $saved[]    = $instance;
                $savedIds[] = $instance->getKey();
            }
            if ($goneAt) {
                $this->_warnDetailItemsGone($goneAt);
            }
            // Remove os órfãos do pai.
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__dq = $__m::query()->where($fk, '=', $parentId);
            if ($savedIds) {
                $__dq->whereNotIn($detailPk, $savedIds);
            }
            $this->_deleteDetailItemRows($__dq);
        } else {
            // ── Delete + Insert ───────────────────────────────────────────
            $__m = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $this->_deleteDetailItemRows($__m::query()->where($fk, '=', $parentId));

            foreach ($rows as $row) {
                $instance = new $model();
                foreach ($row as $k => $v) {
                    if (str_starts_with($k, '__')) continue;
                    if ($only !== null && !isset($only[$k])) continue;
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
     * Quem é a linha `$row` sob este pai: a linha que já existe (ou null, se é
     * nova) e se ela já foi removida por outra tela.
     *
     * @return array{0: ?object, 1: bool} [linha que já existe | null, já removida]
     */
    private function _detailItemTarget(string $model, string $fk, mixed $parentId, array $row, string $detailPk, bool $canTypeKey): array
    {
        // Sem (int): PK de texto ('ABC-1') viraria 0.
        $pkVal    = (is_scalar($row[$detailPk] ?? null) && !empty($row[$detailPk])) ? $row[$detailPk] : null;
        // Eloquent: carregar por pk p/ que save() faça UPDATE (setar id num
        // model novo causaria INSERT). ESCOPADO ao pai (fk), igual ao
        // gêmeo em MadForm::_saveDetailRows: sem isso um cliente podia
        // passar o id de uma linha de OUTRO pai/tenant e re-parenteá-la
        // (IDOR de linha). Fora do escopo do pai → trata como nova
        // (INSERT), nunca sequestra a linha alheia.
        $instance = $pkVal !== null
            ? $model::query()->where($fk, '=', $parentId)->whereKey($pkVal)->first()
            : null;
        // Linha que veio com a chave e não existe mais em lugar nenhum
        // (apagada ou excluída logicamente por outra aba / outra pessoa):
        // NÃO é recriada — quem removeu por último decidiu. Chave que o
        // usuário digita é outra história: ali a linha é nova.
        $gone = $pkVal !== null && !$instance && !$canTypeKey
            && !$model::query()->whereKey($pkVal)->exists();

        return [$instance, $gone];
    }

    /**
     * O que a linha `$row` atribui à instância dela (`$instance` null = linha nova).
     *
     * @param  array<string,true>|null $only
     * @return array<string,mixed>
     */
    private function _detailItemAssign(string $model, string $fk, mixed $parentId, array $row, ?object $instance, string $detailPk, ?array $only, ?MadForm $form, ?string $fieldList): array
    {
        $assign = [];
        foreach ($row as $k => $v) {
            if (str_starts_with($k, '__')) continue;
            // A chave ja veio do whereKey() (linha existente) ou e vazia
            // (linha nova). Atribuir NULL explicito numa PK serial e
            // INSERT com id=NULL — o Postgres recusa (not-null).
            if ($k === $detailPk) continue;
            $assign[$k] = ($v === '') ? null : $v;
        }
        if ($only !== null) {
            $assign = array_intersect_key($assign, $only);
        } elseif ($instance !== null && $form) {
            // Coluna que a lista não mostra e que a linha traz como
            // estava no banco ao carregar: fica como está no banco agora.
            $assign = $form->handRowAssign($model, $fk, $parentId, $instance, $assign, $fieldList);
        }

        return $assign;
    }

    /**
     * Confere, pelas regras de referência do Model da linha, as chaves para
     * outro cadastro que as linhas vão gravar — ver saveDetailItems(). Model
     * sem regra de referência: nada é lido nem conferido.
     *
     * @param array<string,true>|null $only
     *
     * @throws ReferenceViolation
     */
    private function _guardDetailItemReferences(string $model, string $fk, mixed $parentId, array $rows, string $detailPk, bool $canTypeKey, bool $sync, ?array $only, ?MadForm $form, ?string $fieldList): void
    {
        $guarded = $rows !== [] ? ReferenceGuard::columns($model) : [];
        if ($guarded === []) {
            return;
        }

        $lines = [];
        foreach ($rows as $position => $row) {
            $instance = null;
            if ($sync) {
                [$instance, $gone] = $this->_detailItemTarget($model, $fk, $parentId, $row, $detailPk, $canTypeKey);
                if ($gone) {
                    continue;   // não é gravada (o usuário é avisado no Salvar)
                }
            }
            $lines[] = [$position + 1, $instance, $this->_detailItemAssign($model, $fk, $parentId, $row, $instance, $detailPk, $only, $form, $fieldList)];
        }

        $labels = $form ? $form->rowLabels($fieldList, $model, $fk, $parentId) : ['list' => '', 'columns' => []];
        $errors = MadForm::rowReferenceErrors($model, $guarded, $fk, $lines, $labels, (string) $fieldList);
        if ($errors) {
            throw new ReferenceViolation($errors);
        }
    }

    /**
     * Apaga as linhas da consulta uma a uma, pelo Model: é o `delete()` da
     * instância que conhece a exclusão lógica da tabela (HasMadSoftDeletes /
     * SoftDeletes) e dispara `deleting`/`deleted`. O `delete()` direto na
     * consulta apagava a linha de vez, sem evento nenhum. A linha cujo Model
     * recusa a exclusão (`deleting` devolve false) fica.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     */
    private function _deleteDetailItemRows($query): void
    {
        $orphans = (clone $query)->get();

        // Tabela sem coluna de chave (legada): a instância não tem como apagar
        // só a si mesma — vale a consulta, como sempre foi.
        foreach ($orphans as $orphan) {
            if ($orphan->getKey() === null) {
                $query->delete();

                return;
            }
        }

        foreach ($orphans as $orphan) {
            $orphan->delete();
        }
    }

    /**
     * Avisa o usuário (pela resposta da ação) das linhas que não foram
     * regravadas porque outra tela já as tinha removido. O aviso sai pelo
     * primeiro MadForm público do componente; sem formulário, não há canal.
     *
     * @param list<int> $positions posição das linhas na lista, a partir de 1
     */
    private function _warnDetailItemsGone(array $positions): void
    {
        $this->_detailItemsForm()?->warnChildRowsGone($positions);
    }

    /** O primeiro MadForm público do componente — é nele que as linhas da lista vivem. */
    private function _detailItemsForm(): ?MadForm
    {
        $ref = new \ReflectionObject($this);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($this)) continue;
            $val = $prop->getValue($this);
            if ($val instanceof MadForm) {
                return $val;
            }
        }

        return null;
    }

    /**
     * Carrega linhas do banco prontas para o mad-field-list (via FieldListColumn::normalizeRows).
     *
     * O formulário da tela fica sabendo o que foi lido: é a base com que o
     * `saveDetailItems()` deixa de regravar as colunas que a lista não mostra
     * (o `$transform` roda depois — o que ele põe por cima de uma coluna é
     * valor do código, e é gravado).
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
        $keyName = (string) (new $cls())->getKeyName();
        $objects = \Mad\Database\QuerySource::recordsFromQuery($q, $keyName);
        $rows    = [];
        $keys    = [];
        $read    = [];   // chave => atributos como estão no banco

        foreach ($objects as $obj) {
            $row = method_exists($obj, 'toArray') ? $obj->toArray() : (array) $obj;
            // A chave vem do REGISTRO: um Model com a chave em `$hidden` não a
            // tem no toArray(), e a linha continua sendo dele.
            $rowKey = method_exists($obj, 'getKey') ? $obj->getKey() : ($row[$keyName] ?? null);
            $keys[] = $rowKey;
            if ($rowKey !== null && $rowKey !== '' && $obj instanceof \Illuminate\Database\Eloquent\Model) {
                $read[$rowKey] = $obj->attributesToArray();
            }
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
            $this->_injectIntoForm($fieldListName, $rows, $keyName);
        }

        // O que a tela está entregando ao navegador (chave => __id da linha) e
        // como cada linha estava no banco.
        $ids = [];
        foreach ($rows as $i => $row) {
            $rowKey = $keys[$i] ?? null;
            if ($rowKey !== null && $rowKey !== '' && isset($read[$rowKey])) {
                $ids[$rowKey] = (string) ($row['__id'] ?? '');
            }
        }
        $this->_detailItemsForm()?->handRowsLoaded($fieldListName, $cls, $fk, $parentId, $keyName, $ids, $read);

        return $rows;
    }

    /**
     * Injeta rows no primeiro MadForm público do componente.
     */
    private function _injectIntoForm(string $name, array $rows, string $keyName = ''): void
    {
        $form = $this->_detailItemsForm();
        if ($form) {
            $form->fields[$name] = $rows;
            // A chave da linha: é ela que prende, a cada linha, o que o
            // servidor entregou nas colunas que a lista não mostra.
            $form->noteDetailKey($name, $keyName);
        }
    }
}