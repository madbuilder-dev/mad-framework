<?php
namespace Mad\Form;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
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
 *
 * O par trabalha junto: o `loadChecklist()` anota, no primeiro MadForm público
 * da tela, as marcas que entregou; o `saveChecklist()` grava só o que a pessoa
 * marcou e desmarcou NAQUELA tela e avisa o que outra aba ou outra pessoa
 * mudou enquanto ela estava aberta. Nada muda no código de quem chama.
 */
trait MadChecklistTrait
{
    /**
     * Persiste as seleções de um checklist.
     *
     * Só o que mudou: a ligação que deixou de estar marcada é apagada, a marca
     * nova é incluída e a ligação que CONTINUA marcada fica como está — com a
     * mesma chave e com as colunas que o checklist não mostra (quem criou, o
     * nível, as ações). Antes era "apaga tudo e inclui de novo": toda coluna
     * da ligação fora do checklist voltava ao padrão a cada Salvar, inclusive
     * o que outra tela tivesse gravado nela.
     *
     * A exclusão é feita pelo Model, linha a linha: respeita a exclusão lógica
     * da tabela e dispara `deleting`/`deleted`.
     *
     * O `$each` continua rodando para TODA marca (a nova e a que já existia),
     * e o que ele atribui é gravado — menos o dado que a tela não mudou e que
     * outra aba ou outra pessoa mudou depois que ela abriu: quando as marcas
     * foram lidas com o `loadChecklist()` com o `$mapExtra` (o dado por item
     * que a tela mostra), a coluna que o `$each` devolve igual ao que a tela
     * leu fica como está no banco. O `$each` atribui o que a linha da tela
     * manda, e a linha mostra o que estava gravado quando a tela abriu: era
     * assim que o último Salvar desfazia a ação que a outra pessoa tinha
     * tirado do programa.
     *
     * A marca que vai virar ligação NOVA passa antes pelas regras de
     * referência (`exists`) do `rules()` do Model da ligação: o checklist só
     * mostra os itens que quem salva enxerga, e a requisição alterada ligava o
     * registro a um item de outra unidade. E passa pela consulta do Model das
     * opções do checklist que a tela desenhou (`model=` da tag, com os escopos
     * do Model), que vale mesmo quando o Model da ligação não tem `rules()`.
     * Uma marca recusada lança ReferenceViolation (uma
     * MadValidationException: o `catch` do `onSave` mostra a mensagem), antes
     * de qualquer ligação ser apagada ou incluída.
     *
     * Pelas mesmas regras, a ligação que já existe com um item que quem salva
     * NÃO enxerga (o administrador ligou alguém de outra unidade) fica: ela
     * não aparece no checklist dessa pessoa, não volta na requisição, e isso
     * não é pedido de ninguém para desmarcá-la.
     *
     * Com a tela aberta em dois lugares, "o que veio marcado" inclui as marcas
     * de quando ESTA tela abriu: deixar a tabela igual a isso desfazia a marca
     * que a outra tela tinha feito e devolvia a que ela tinha desmarcado. Por
     * isso, quando as marcas foram lidas com o `loadChecklist()`, a comparação
     * é com o que a tela MOSTRAVA (o formulário guarda), não com o banco:
     *
     *   - entra a marca que a tela não mostrava e a pessoa fez;
     *   - sai a que a tela mostrava e a pessoa desmarcou;
     *   - a ligação que a tela nunca mostrou marcada e não veio marcada foi
     *     feita por outra aba ou outra pessoa: fica;
     *   - a marca que a tela ainda mostra e que não é mais ligação foi
     *     desmarcada por outra aba ou outra pessoa: não é recriada.
     *
     * Dos dois últimos casos quem salva é avisado, com o nome dos itens e o
     * da lista, pela resposta do Salvar (MadForm::warnMarksChanged). Sem essa
     * base — registro novo, tela aberta antes desta versão, tela que lê as
     * marcas por conta própria — vale o conjunto que veio, como sempre valeu.
     *
     * A ligação lida que o checklist NÃO desenhou — a lista da tela é
     * filtrada (`:filters`, `:query`) e não oferece o item — não volta
     * marcada, e ninguém a desmarcou: fica.
     *
     * @throws ReferenceViolation marca de um item que quem salva não enxerga
     *
     * @param string        $model       Classe do pivot (ex: IamGroupProgram::class)
     * @param string        $fk          FK do pai (ex: 'program_id')
     * @param mixed         $parentId    Valor da FK do pai
     * @param string        $itemFk      FK do item selecionado (ex: 'group_id')
     * @param array|null    $selectedIds IDs selecionados do checklist
     * @param callable|null $each        fn($record, $itemId): void — roda antes de persistir
     * @return array                     Instâncias das marcas (as novas e as mantidas)
     */
    public function saveChecklist(
        string    $model,
        string    $fk,
        mixed     $parentId,
        string    $itemFk,
        mixed     $selectedIds = null,
        ?callable $each = null
    ): array {
        // Normaliza: string CSV → array
        if (is_string($selectedIds)) {
            $selectedIds = $selectedIds !== '' ? explode(',', $selectedIds) : [];
        }
        $selectedIds = array_filter(array_map('trim', (array) ($selectedIds ?? [])), fn($v) => $v !== '');

        // O item é gravado como inteiro (como sempre foi): é por ele que a
        // marca reconhece a ligação que já existe.
        $wanted = [];
        foreach ($selectedIds as $itemId) {
            $wanted[(string) (int) $itemId] = $itemId;
        }

        $links = $model::where($fk, $parentId)->get();

        $existing = [];
        foreach ($links as $link) {
            $existing[(string) $link->$itemFk] = true;
        }

        // O que ESTA tela mostrava marcado (o `loadChecklist()` anotou no
        // formulário). Null = sem base: vale o conjunto que veio da tela.
        $form  = $this->_checklistForm();
        $list  = self::_checklistListName($model, $fk, $itemFk);
        $shown = $form?->marksShown($list, $parentId);
        $shown = $shown === null ? null : array_fill_keys($shown, true);
        // As ligações lidas que o checklist não desenhou (a lista não oferece o item).
        $notDrawn = $shown === null ? [] : array_fill_keys($form->marksNotDrawn($list, $parentId), true);

        $unmarkedElsewhere = [];   // a tela mostrava, veio marcado e não é mais ligação
        $markedElsewhere   = [];   // é ligação, não veio marcado e a tela nunca mostrou
        $notOffered        = [];   // é ligação, a tela leu e o checklist não oferece
        if ($shown !== null) {
            foreach ($wanted as $key => $itemId) {
                if (isset($shown[$key]) && !isset($existing[$key])) {
                    $unmarkedElsewhere[(string) $key] = $itemId;
                }
            }
            foreach (array_keys($existing) as $key) {
                if (isset($wanted[$key]) || isset($shown[$key])) {
                    continue;
                }
                if (isset($notDrawn[$key])) {
                    $notOffered[(string) $key] = (string) $key;
                } else {
                    $markedElsewhere[(string) $key] = (string) $key;
                }
            }
        }

        // As marcas que vão virar ligação: o item é de quem salva? (A que
        // outra tela desmarcou não volta a ser ligação — não é conferida.)
        $entering = array_map('strval', array_keys(array_diff_key($wanted, $existing, $unmarkedElsewhere)));
        $refused  = MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, $entering);
        if ($refused !== null) {
            ReferenceGuard::logRefused(sprintf('uma marca do checklist (coluna "%s" de %s)', $itemFk, $model));

            throw new ReferenceViolation([$itemFk => $refused]);
        }
        // E pela consulta do Model das opções do checklist que a tela desenhou:
        // é o que barra a marca quando o Model da ligação não tem `rules()`.
        $form?->guardMarksSource($list, $entering, array_map('strval', array_keys($wanted)));

        // As ligações que não voltaram marcadas e cujo item quem salva não
        // enxerga: o checklist não as mostrou, então ninguém as desmarcou.
        $unseen = [];
        foreach (array_keys(array_diff_key($existing, $wanted, $markedElsewhere, $notOffered)) as $itemId) {
            if (MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, [$itemId]) !== null) {
                $unseen[(string) $itemId] = $itemId;
            }
        }

        // Ficam como estão: a que quem salva não enxerga, a que outra tela fez
        // e a que a lista da tela não oferece.
        $kept = $unseen + $markedElsewhere + $notOffered;

        // Tabela de ligação SEM coluna de chave: a instância não tem como
        // apagar nem atualizar só a si mesma. Ali a única sincronização possível
        // é trocar o conjunto, como sempre foi.
        $keyless = false;
        foreach ($links as $link) {
            if ($link->getKey() === null) {
                $keyless = true;
                break;
            }
        }

        $linked = [];   // item => ligação que continua
        if ($keyless) {
            $stale = $model::where($fk, $parentId);
            if ($kept) {
                $stale->whereNotIn($itemFk, array_values($kept));
            }
            $stale->delete();
        } else {
            foreach ($links as $link) {
                $itemId = (string) $link->$itemFk;
                if (isset($kept[$itemId])) {
                    continue;   // fica como está
                }
                // Desmarcada — ou repetida (dado antigo): o "apaga tudo e
                // inclui" também desfazia a repetição.
                if (!isset($wanted[$itemId]) || isset($linked[$itemId])) {
                    $link->delete();
                    continue;
                }
                $linked[$itemId] = $link;
            }
        }

        // O dado por item que a tela leu (loadChecklist com `$mapExtra`).
        $values = ($form !== null && $each !== null) ? self::_checklistValuesSource($list, $parentId) : null;

        $saved            = [];
        $changedElsewhere = [];   // continua marcado; o dado foi mudado por fora e fica
        $written          = [];   // "item|coluna" => o que a tela passa a mostrar
        foreach ($wanted as $key => $itemId) {
            if (isset($unmarkedElsewhere[$key])) {
                continue;   // quem desmarcou por último decidiu: não é recriada
            }

            $record = $linked[$key] ?? null;
            if ($record === null) {
                $record = new $model();
                $record->$fk     = $parentId;
                $record->$itemFk = (int) $itemId;
            }

            if ($each) {
                $each($record, $itemId);
                if ($values !== null && $record->exists && self::_checklistKeepChangedElsewhere($form, $values, (string) $key, $record)) {
                    $changedElsewhere[(string) $key] = $itemId;
                }
            }

            // A ligação mantida só é gravada quando o `$each` mudou algo nela.
            if (!$record->exists || $record->isDirty()) {
                if ($values !== null) {
                    $columns = $record->exists ? $record->getDirty() : self::_checklistDataColumns($record, $fk, $itemFk);
                    foreach ($columns as $column => $value) {
                        $written[$key . '|' . $column] = $value;
                    }
                }
                $record->save();
            }
            $saved[] = $record;
        }

        if ($form !== null) {
            // A tela continua mostrando marcado o que mandou marcado: é disso
            // que o Salvar seguinte dela parte (quando este for confirmado).
            $form->marksSaved($list, $parentId, array_keys($wanted), new $model(), array_keys($notOffered));
            // E mostrando, em cada item, o dado que mandou.
            if ($values !== null && $written !== []) {
                self::_checklistAfterCommit(new $model(), static fn () => $form->valuesLoaded($values, $written));
            }

            $this->_warnChecklistChanged($form, $model, $fk, $parentId, $itemFk, $markedElsewhere, $unmarkedElsewhere, $changedElsewhere, $list);
        }

        return $saved;
    }

    /**
     * Depois do `$each`, numa ligação que continua marcada: a coluna que ele
     * devolveu IGUAL ao que a tela leu (a pessoa não mexeu no dado) e que está
     * diferente no banco (outra aba ou outra pessoa mudou depois que a tela
     * abriu) volta ao que está no banco — não é gravada. A coluna que a pessoa
     * mudou é gravada, como sempre.
     *
     * Devolve true quando alguma coluna ficou como a outra aba deixou (vai
     * para o aviso). Sem o que a tela leu (`loadChecklist()` sem `$mapExtra`,
     * tela aberta antes desta versão): grava tudo, como sempre.
     */
    private static function _checklistKeepChangedElsewhere(MadForm $form, string $values, string $key, object $record): bool
    {
        if (!$record->isDirty()) {
            return false;
        }
        $dirty = $record->getDirty();
        $asked = [];
        foreach ($dirty as $column => $value) {
            $asked[$key . '|' . $column] = $value;
        }
        $changed = $form->valuesChanged($values, $asked);
        if ($changed === null) {
            return false;
        }
        $changed = array_fill_keys($changed, true);

        $attributes = $record->getAttributes();
        $kept       = false;
        foreach (array_keys($dirty) as $column) {
            if (!isset($changed[$key . '|' . $column])) {
                $attributes[$column] = $record->getRawOriginal($column);
                $kept = true;
            }
        }
        if ($kept) {
            $record->setRawAttributes($attributes);
        }

        return $kept;
    }

    /**
     * As colunas de DADOS de uma ligação (o que o `$each` grava): tudo menos a
     * chave da linha, a do pai, a do item e as datas que o Model carimba.
     *
     * @return array<string,mixed> coluna => valor como está no registro
     */
    private static function _checklistDataColumns(object $link, string $fk, string $itemFk): array
    {
        if (!$link instanceof \Illuminate\Database\Eloquent\Model) {
            return [];
        }
        $skip = [$fk => true, $itemFk => true, (string) $link->getKeyName() => true];
        if ($link->usesTimestamps()) {
            $skip[(string) $link->getCreatedAtColumn()] = true;
            $skip[(string) $link->getUpdatedAtColumn()] = true;
        }
        if (method_exists($link, 'getDeletedAtColumn')) {
            $skip[(string) $link->getDeletedAtColumn()] = true;
        }

        return array_diff_key($link->getAttributes(), $skip);
    }

    /** Fonte, no formulário (valuesLoaded), do dado por item de uma lista sob um pai. */
    private static function _checklistValuesSource(string $list, mixed $parentId): string
    {
        return 'checklist:' . $list . '#' . (string) $parentId;
    }

    /**
     * Roda `$callback` quando a transação em curso na conexão de `$record`
     * confirmar — na hora, sem transação. Desfeita: não roda (o que a tela
     * mostra continua sendo o que ela leu).
     */
    private static function _checklistAfterCommit(object $record, callable $callback): void
    {
        $ran  = false;
        $once = static function () use (&$ran, $callback): void {
            if (!$ran) {
                $ran = true;
                $callback();
            }
        };
        try {
            $record->getConnection()->afterCommit($once);
        } catch (\RuntimeException $e) {
            if ($ran) {
                throw $e;
            }
            $once();   // sem gerenciador de transações: o que foi gravado está gravado
        }
    }

    /**
     * Carrega os IDs selecionados de um checklist e opcionalmente dados extras por item.
     *
     * O formulário da tela fica sabendo o que foi lido: são as marcas que ela
     * vai mostrar, e é contra elas que o `saveChecklist()` separa o que a
     * pessoa marcou e desmarcou nesta tela do que outra aba ou outra pessoa
     * mudou enquanto ela estava aberta. A anotação só vale quando a tela é
     * desenhada com elas (abertura, redesenho): reler as marcas numa ação que
     * não redesenha a tela não muda nada.
     *
     * Com `$mapExtra`, o formulário guarda também o dado de cada ligação (as
     * colunas além das chaves): é contra ele que o `saveChecklist()` separa o
     * dado que a pessoa mudou do que outra aba mudou nesse meio tempo.
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

        $form = $this->_checklistForm();
        $list = self::_checklistListName($model, $fk, $itemFk);
        $form?->marksLoaded($list, $parentId, array_map(static fn (string $itemId): string => (string) (int) $itemId, $selected));

        // Com `$mapExtra` a tela mostra um dado por item (as ações do
        // programa): o formulário guarda o que ela leu, coluna a coluna, e o
        // `saveChecklist()` não regrava o que a pessoa não mudou.
        if ($mapExtra && $form !== null) {
            $values = [];
            foreach ($items as $item) {
                $key = (string) (int) $item->$itemFk;
                foreach (self::_checklistDataColumns($item, $fk, $itemFk) as $column => $value) {
                    $values[$key . '|' . $column] = $value;
                }
            }
            if ($values !== []) {
                $form->valuesLoaded(self::_checklistValuesSource($list, $parentId), $values);
            }
        }

        return ['selected' => $selected, 'extras' => $extras];
    }

    /** O primeiro MadForm público da tela — é nele que as marcas do checklist ficam anotadas. */
    private function _checklistForm(): ?MadForm
    {
        $ref = new \ReflectionObject($this);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->isStatic() || !$prop->isInitialized($this)) {
                continue;
            }
            $val = $prop->getValue($this);
            if ($val instanceof MadForm) {
                return $val;
            }
        }

        return null;
    }

    /** Nome, no formulário, da lista de marcas de um Model de ligação (com a chave do pai e a do item). */
    private static function _checklistListName(string $model, string $fk, string $itemFk): string
    {
        try {
            $model = (new \ReflectionClass($model))->getName();   // o apelido e a classe são a mesma lista
        } catch (\Throwable $e) {
            // classe que não resolve: vale o nome como veio
        }

        return substr(md5(ltrim($model, '\\') . '|' . $fk . '|' . $itemFk), 0, 10);
    }

    /**
     * Avisa quem salvou (pela resposta da ação) do que outra aba ou outra
     * pessoa marcou e desmarcou enquanto a tela estava aberta — e que este
     * Salvar manteve.
     *
     * A ligação com um item que quem salva não enxerga (regras de referência
     * do Model) fica fora do aviso: ela não aparece no checklist dessa pessoa
     * nem depois de atualizar a tela.
     *
     * @param array<string,string>     $marked   itens marcados por fora
     * @param array<string,int|string> $unmarked itens desmarcados por fora
     * @param array<string,int|string> $changed  itens cujo dado foi mudado por fora
     */
    private function _warnChecklistChanged(MadForm $form, string $model, string $fk, mixed $parentId, string $itemFk, array $marked, array $unmarked, array $changed = [], string $list = ''): void
    {
        foreach ($marked as $key => $itemId) {
            if (MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, [$itemId]) !== null) {
                unset($marked[$key]);
            }
        }
        if (!$marked && !$unmarked && !$changed) {
            return;
        }

        $names = self::_checklistItemNames($model, $itemFk, array_merge(array_keys($marked), array_keys($unmarked), array_keys($changed)));
        $named = static fn (array $items): array => array_values(array_intersect_key($names, $items));

        $form->warnMarksChanged(
            $named($marked),
            $named($unmarked),
            count(array_diff_key($marked, $names)),
            count(array_diff_key($unmarked, $names)),
            new $model(),
            $named($changed),
            count(array_diff_key($changed, $names)),
            $list,
        );
    }

    /**
     * Nome de cada item, como o usuário o vê, para o aviso do Salvar.
     *
     * O cadastro do item é o do relacionamento `belongsTo` que o Model da
     * ligação declara para `$itemFk` (o gerador de models o emite, com o tipo
     * de retorno), e o nome é a primeira coluna de nome que o registro tem. A
     * consulta passa pelo Model do item: os escopos dele (unidade, exclusão
     * lógica) valem — o item que quem salva não alcança fica sem nome.
     *
     * Nunca propaga exceção: descobrir um nome não pode transformar um Salvar
     * que deu certo em erro na tela. Item sem nome conhecido entra no aviso
     * como quantidade.
     *
     * @param  list<int|string>     $items
     * @return array<string,string> item => nome
     */
    private static function _checklistItemNames(string $model, string $itemFk, array $items): array
    {
        if ($items === []) {
            return [];
        }

        try {
            $link     = new $model();
            $relation = null;
            foreach ((new \ReflectionClass($link))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $type = $method->getReturnType();
                if ($method->isStatic() || $method->getNumberOfParameters() > 0
                    || !$type instanceof \ReflectionNamedType
                    || !is_a($type->getName(), BelongsTo::class, true)) {
                    continue;
                }
                $candidate = $method->invoke($link);
                if ($candidate instanceof BelongsTo && !$candidate instanceof MorphTo
                    && $candidate->getForeignKeyName() === $itemFk) {
                    $relation = $candidate;
                    break;
                }
            }
            if ($relation === null) {
                return [];
            }

            $ownerKey = $relation->getOwnerKeyName();
            $names    = [];
            foreach ($relation->getRelated()->newQuery()->whereIn($ownerKey, $items)->get() as $record) {
                foreach (['nome', 'name', 'descricao', 'description', 'titulo', 'title', 'razao_social'] as $column) {
                    $value = $record->getAttribute($column);
                    if (is_scalar($value) && trim((string) $value) !== '') {
                        $names[(string) $record->getAttribute($ownerKey)] = trim((string) $value);
                        break;
                    }
                }
            }

            return $names;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
