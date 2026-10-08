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
     * O `$each` continua rodando para TODA marca (a nova e a que já existia):
     * o que ele atribui é gravado.
     *
     * A marca que vai virar ligação NOVA passa antes pelas regras de
     * referência (`exists`) do `rules()` do Model da ligação: o checklist só
     * mostra os itens que quem salva enxerga, e a requisição alterada ligava o
     * registro a um item de outra unidade. Uma marca recusada lança
     * ReferenceViolation (uma MadValidationException: o `catch` do `onSave`
     * mostra a mensagem), antes de qualquer ligação ser apagada ou incluída.
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
     * Dos dois últimos casos quem salva é avisado, com o nome dos itens, pela
     * resposta do Salvar (MadForm::warnMarksChanged). Sem essa base — registro
     * novo, tela aberta antes desta versão, tela que lê as marcas por conta
     * própria — vale o conjunto que veio, como sempre valeu.
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

        $unmarkedElsewhere = [];   // a tela mostrava, veio marcado e não é mais ligação
        $markedElsewhere   = [];   // é ligação, não veio marcado e a tela nunca mostrou
        if ($shown !== null) {
            foreach ($wanted as $key => $itemId) {
                if (isset($shown[$key]) && !isset($existing[$key])) {
                    $unmarkedElsewhere[(string) $key] = $itemId;
                }
            }
            foreach (array_keys($existing) as $key) {
                if (!isset($wanted[$key]) && !isset($shown[$key])) {
                    $markedElsewhere[(string) $key] = (string) $key;
                }
            }
        }

        // As marcas que vão virar ligação: o item é de quem salva? (A que
        // outra tela desmarcou não volta a ser ligação — não é conferida.)
        $refused = MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, array_keys(array_diff_key($wanted, $existing, $unmarkedElsewhere)));
        if ($refused !== null) {
            ReferenceGuard::logRefused(sprintf('uma marca do checklist (coluna "%s" de %s)', $itemFk, $model));

            throw new ReferenceViolation([$itemFk => $refused]);
        }

        // As ligações que não voltaram marcadas e cujo item quem salva não
        // enxerga: o checklist não as mostrou, então ninguém as desmarcou.
        $unseen = [];
        foreach (array_keys(array_diff_key($existing, $wanted, $markedElsewhere)) as $itemId) {
            if (MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, [$itemId]) !== null) {
                $unseen[(string) $itemId] = $itemId;
            }
        }

        // Ficam como estão: a que quem salva não enxerga e a que outra tela fez.
        $kept = $unseen + $markedElsewhere;

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

        $saved = [];
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
            }

            // A ligação mantida só é gravada quando o `$each` mudou algo nela.
            if (!$record->exists || $record->isDirty()) {
                $record->save();
            }
            $saved[] = $record;
        }

        if ($form !== null) {
            // A tela continua mostrando marcado o que mandou marcado: é disso
            // que o Salvar seguinte dela parte (quando este for confirmado).
            $form->marksSaved($list, $parentId, array_keys($wanted), new $model());

            $this->_warnChecklistChanged($form, $model, $fk, $parentId, $itemFk, $markedElsewhere, $unmarkedElsewhere);
        }

        return $saved;
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

        $this->_checklistForm()?->marksLoaded(
            self::_checklistListName($model, $fk, $itemFk),
            $parentId,
            array_map(static fn (string $itemId): string => (string) (int) $itemId, $selected)
        );

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
     */
    private function _warnChecklistChanged(MadForm $form, string $model, string $fk, mixed $parentId, string $itemFk, array $marked, array $unmarked): void
    {
        foreach ($marked as $key => $itemId) {
            if (MadForm::linkReferenceError($model, $fk, $parentId, $itemFk, [$itemId]) !== null) {
                unset($marked[$key]);
            }
        }
        if (!$marked && !$unmarked) {
            return;
        }

        $names = self::_checklistItemNames($model, $itemFk, array_merge(array_keys($marked), array_keys($unmarked)));
        $named = static fn (array $items): array => array_values(array_intersect_key($names, $items));

        $form->warnMarksChanged(
            $named($marked),
            $named($unmarked),
            count(array_diff_key($marked, $names)),
            count(array_diff_key($unmarked, $names)),
            new $model(),
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
