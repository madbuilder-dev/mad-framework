<?php
namespace Mad\Form;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Http\MadResponse;
use Mad\Http\MadStateCrypt;
use Mad\Ui\MadMessage;


use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Sentinel exception thrown inside a DB::transaction() closure to force a
 * rollback when an on-add/on-remove/on-update hook returns false (operação
 * cancelada). Caught right outside the transaction wrapper to convert into a
 * user-facing error toast without leaking as a generic failure.
 */
final class MadDbBlockCancelled extends RuntimeException {}

/**
 * Trait that adds <mad-db-blocks> handler methods to a MadComponent host.
 *
 * Three public actions are dispatched against the host:
 *
 *   blockAdd()                       — POSTs hidden __mad_db_blocks_state + form fields
 *   blockRemove($id, $state)
 *   blockUpdate($id, $field, $value, $state)
 *
 * Hooks (resolved on the host via on-add / on-remove / on-update props):
 *   void/null/true → continue
 *   false          → rollback + error toast
 *   MadResponse    → return as-is
 *
 * After-hooks (on-after-add / on-after-remove), chamados APOS o commit da
 * DB::transaction() — ideais para operacoes que precisam do commit
 * ja aplicado (ex: manageRow, que abre nova conexao PDO):
 *   fn($pivot, MadResponse $resp): void   → acrescenta ops no $resp
 */
trait MadDbBlocksTrait
{
    /**
     * Add a pivot row from form submit. State comes from $_POST when blank.
     */
    public function blockAdd(string $state = ''): MadResponse
    {
        if ($state === '') {
            $state = (string) ($_POST['__mad_db_blocks_state'] ?? '');
        }
        $cfg = MadStateCrypt::decrypt($state);
        if (!$cfg) {
            return MadMessage::error('Erro', 'Estado inválido');
        }
        // O state pode ter sido selado num render em que o pai ainda não tinha
        // id (tela aberta em branco e preenchida depois). Reresolve contra ESTE
        // componente — nunca contra input do cliente.
        $cfg['recordId'] = MadDbBlocks::resolveRecordId($cfg['recordId'] ?? null, $this);

        // O PHP recusou o arquivo (acima do limite de envio do servidor, envio
        // interrompido): o item era gravado SEM ele, com toast de sucesso.
        // Nada é gravado — nem o item, nem o gancho on-add roda — e o aviso
        // diz o arquivo e o limite.
        $refused = self::_blockRefusedFile($cfg);
        if ($refused !== null) {
            return MadMessage::warning(MadUploadRules::oversizedTitle(), $refused)
                ->fieldError((string) $cfg['fileField'], $refused);
        }

        $pivot = null;
        try {
            $resp = DB::connection($cfg['database'])->transaction(function () use ($cfg, &$pivot) {
                $pivot = new $cfg['pivotModel']();
                $flatMode = (($cfg['mode'] ?? 'pivot') === 'flat');
                if (!$flatMode) {
                    $pivot->{$cfg['foreignKey']} = $cfg['recordId'];
                }

                // Payload do bloco. O form do bloco é OUTRO form (posta só os
                // campos dele + o state), então o que ele mandou tem que vencer
                // o form da tela: `MadForm::getData()` filtra pelo schema do
                // form HOSPEDEIRO e descartaria `body`/`titulo` do filho — o
                // insert saía só com a FK (estourando NOT NULL ou gravando vazio).
                $data = self::_blockPayload($this->form ?? null);

                // Whitelist de colunas do pivot (addAttribute). Quando o host é um
                // componente com MUITAS props (ex.: um MadDataGrid cujo getData()
                // devolve filtros/estado do grid: mes, ano, viewMode, sortBy...),
                // sem este filtro o pivot recebe colunas inexistentes e o INSERT
                // explode ("table X has no column named mes"). Se o modelo declara
                // addAttribute(), só essas colunas são atribuídas; modelos sem
                // whitelist mantêm o comportamento anterior (aceitam tudo).
                $allowed = method_exists($pivot, 'getFillable') ? $pivot->getFillable() : [];
                $fixed   = self::_blockFixedColumns($pivot, $cfg);
                $sent    = [];
                foreach ($data as $k => $v) {
                    if (!empty($allowed) && !in_array($k, $allowed, true)) {
                        continue;
                    }
                    // A chave do item e as colunas do escopo do bloco (a chave
                    // do pai, as colunas dos filtros) não vêm do formulário: o
                    // item novo ia para a lista de outro registro.
                    if (in_array((string) $k, $fixed, true)) {
                        continue;
                    }
                    if (is_array($v)) $v = implode(',', $v);
                    $sent[(string) $k] = $v;
                    // A coluna é descartada e o INSERT segue — o item grava
                    // incompleto e o usuário ainda recebe toast de sucesso. Cast
                    // json/decimal recusando o valor, mutator que lança, ou prop
                    // tipada incompatível caem aqui. Manter o insert é
                    // proposital (uma coluna acessória não deve derrubar o
                    // bloco), mas sem log a divergência entre o que foi digitado
                    // e o que foi gravado ficava invisível.
                    try { $pivot->$k = $v; } catch (Throwable $e) {
                        error_log('[MadDbBlocks::blockAdd] coluna "' . $k . '" de '
                            . get_class($pivot) . ' descartada do insert: ' . $e->getMessage());
                    }
                }

                // Chave para outro cadastro (cliente, categoria, usuário) passa
                // pelas regras de referência do Model, como nas linhas filhas do
                // formulário: a de outra unidade, ou que não existe, é recusada.
                $refused = self::_blockRefusedReferences($pivot, $cfg, $sent);
                if ($refused !== null) {
                    return $refused;
                }

                // Hook on-add
                $hook = $cfg['onAdd'] ?? '';
                if ($hook && method_exists($this, $hook)) {
                    $r = $this->{$hook}($pivot);
                    if ($r instanceof MadResponse) {
                        return $r;
                    }
                    if ($r === false) {
                        throw new MadDbBlockCancelled('Operação cancelada');
                    }
                }

                // Upload por item: o `<mad-db-blocks>` grava fora do
                // `MadForm::_afterStore`, então o arquivo é ingerido aqui —
                // mesma regra (nome sanitizado, extensão bloqueada, metadados)
                // via MadUploadIngest. Multi-arquivo vira N itens: o 1º ocupa
                // este pivot, os demais clonam os campos do form.
                //
                // ⚠️ ANTES do save: a tabela de anexos costuma ter `path`/nome
                // do arquivo NOT NULL, e um INSERT só com a FK estourava a
                // constraint antes de qualquer coluna de arquivo ser escrita.
                $extraPivots = self::_blockIngestFiles($pivot, $cfg);

                self::_blockSave($pivot);

                // Os itens 2..N só gravam DEPOIS do 1º — a ordem das linhas
                // segue a ordem em que o usuário escolheu os arquivos.
                foreach ($extraPivots as $extra) {
                    $extra->save();
                }

                $resp = new MadResponse();
                if (empty($cfg['noList'])) {
                    // no-list (modo "só adicionar"): a exibição vive em outro
                    // componente (ex.: tree) — sem rowView, renderizar aqui quebraria.
                    $resp->html('#mad-db-blocks-list-' . $cfg['name'], self::_blockRenderList($cfg));
                }
                self::_blockCloseOverlay($resp, $cfg);

                return $resp;
            });

            // Hook on-after-add (pos-commit) — ideal para manageRow etc.
            // Só dispara quando o fluxo normal foi até o save (não em early-return de hook).
            $afterHook = $cfg['onAfterAdd'] ?? '';
            if ($pivot !== null && $pivot->exists && $afterHook && method_exists($this, $afterHook)) {
                $this->{$afterHook}($pivot, $resp);
            }

            return $resp;

        } catch (MadDbBlockCancelled $e) {
            return MadMessage::error('Erro', 'Operação cancelada');
        } catch (Throwable $e) {
            if (self::_blockIsUniqueViolation($e)) {
                return MadMessage::error('Registro duplicado', 'Já existe um item com esses dados.');
            }
            return MadMessage::error('Erro', \Mad\Ui\MadUserError::message($e, null, static::class . ' db-blocks'));
        }
    }

    /**
     * Detecta violação de UNIQUE/PK de forma portável (sqlite/mysql/pgsql/sqlsrv).
     *
     * PRECISÃO importa: NÃO basta o SQLSTATE 23000 — no MySQL ele é genérico
     * ("integrity constraint violation") e cobre unique (1062), FK (1452) e
     * NOT NULL (1048) igual. Casar 23000 ou substrings largas ('viola',
     * 'unique') marcaria um erro de FK/NOT NULL como "duplicado". Então:
     *   - 23505 (postgres) é específico de unique → confiável.
     *   - demais drivers: casa só substrings ESPECÍFICAS de unique na mensagem
     *     (sqlite "UNIQUE constraint failed", mysql "Duplicate entry",
     *     sqlsrv/pg "duplicate key").
     */
    private static function _blockIsUniqueViolation(Throwable $e): bool
    {
        if ((string) $e->getCode() === '23505') { // postgres: unique_violation
            return true;
        }
        $msg = strtolower($e->getMessage());
        foreach (['unique constraint', 'duplicate entry', 'duplicate key'] as $needle) {
            if (str_contains($msg, $needle)) {
                return true;
            }
        }
        return false;
    }

    public function blockRemove(int $id, string $state): MadResponse
    {
        $cfg = MadStateCrypt::decrypt($state);
        if (!$cfg) {
            return MadMessage::error('Erro', 'Estado inválido');
        }
        $cfg['recordId'] = MadDbBlocks::resolveRecordId($cfg['recordId'] ?? null, $this);
        $pivot   = null;
        $removed = false;
        try {
            $resp = DB::connection($cfg['database'])->transaction(function () use ($cfg, $id, &$pivot, &$removed) {
                $pivot = self::_blockFind($cfg, $id);
                if ($pivot === null) {
                    return self::_blockNotHere($cfg, $id, 'remover');
                }

                $hook = $cfg['onRemove'] ?? '';
                if ($hook && method_exists($this, $hook)) {
                    $r = $this->{$hook}($pivot);
                    if ($r instanceof MadResponse) {
                        return $r;
                    }
                    if ($r === false) {
                        throw new MadDbBlockCancelled('Operação cancelada');
                    }
                }

                // Arquivo do item (quando o bloco é de anexos): apaga do disco
                // de uploads ANTES de sumir com a linha — senão o arquivo fica
                // órfão pra sempre (a linha era a única referência ao path).
                $pathColumn = (string) ($cfg['pathColumn'] ?? '');
                if ($pathColumn !== '') {
                    $relPath = (string) ($pivot->$pathColumn ?? '');
                    if ($relPath !== '' && \Mad\Service\MadUploadStorage::exists($relPath)) {
                        \Mad\Service\MadUploadStorage::delete($relPath);
                    }
                }

                $pivot->delete();
                $removed = true;

                $listHtml = self::_blockRenderList($cfg);

                return (new MadResponse())
                    ->html('#mad-db-blocks-list-' . $cfg['name'], $listHtml);
            });

            // Hook on-after-remove (pos-commit) — ideal para manageRow etc.
            // Só dispara quando a remoção de fato ocorreu (não em early-return de hook).
            $afterHook = $cfg['onAfterRemove'] ?? '';
            if ($removed && $afterHook && method_exists($this, $afterHook)) {
                $this->{$afterHook}($pivot, $resp);
            }

            return $resp;

        } catch (MadDbBlockCancelled $e) {
            return MadMessage::error('Erro', 'Operação cancelada');
        } catch (Throwable $e) {
            return MadMessage::error('Erro', \Mad\Ui\MadUserError::message($e, null, static::class . ' db-blocks'));
        }
    }

    public function blockUpdate(int $id, string $field, mixed $value, string $state): MadResponse
    {
        $cfg = MadStateCrypt::decrypt($state);
        if (!$cfg) {
            return MadMessage::error('Erro', 'Estado inválido');
        }
        $cfg['recordId'] = MadDbBlocks::resolveRecordId($cfg['recordId'] ?? null, $this);
        try {
            return DB::connection($cfg['database'])->transaction(function () use ($cfg, $id, $field, $value) {
                $pivot = self::_blockFind($cfg, $id);
                if ($pivot === null) {
                    return self::_blockNotHere($cfg, $id, 'alterar');
                }

                // O nome do campo vem da requisição: só o que o bloco edita
                // (`edit-fields`; na falta, o `$fillable` do Model), e nunca a
                // chave do item nem as colunas do escopo do bloco.
                if (!self::_blockEditable($pivot, $cfg, $field)) {
                    error_log(sprintf('[MadDbBlocks::blockUpdate] campo "%s" recusado no bloco "%s" (%s): fora de edit-fields/$fillable, '
                        . 'ou chave/escopo do bloco. Declare o campo em edit-fields se a tela o edita.',
                        $field, (string) ($cfg['name'] ?? ''), get_class($pivot)));

                    return MadMessage::error('Erro', 'O campo "' . $field . '" não pode ser alterado neste bloco.');
                }

                $hook = $cfg['onUpdate'] ?? '';
                if ($hook && method_exists($this, $hook)) {
                    $r = $this->{$hook}($pivot, $field, $value);
                    if ($r instanceof MadResponse) {
                        return $r;
                    }
                    if ($r === false) {
                        throw new MadDbBlockCancelled('Operação cancelada');
                    }
                }

                $pivot->{$field} = $value;
                $refused = self::_blockRefusedReferences($pivot, $cfg, [$field => $value]);
                if ($refused !== null) {
                    return $refused;
                }
                self::_blockSave($pivot);

                // Re-render a lista inteira (consistente com blockAdd/blockRemove).
                // NÃO dá pra fazer ->html('#block-row-…') por linha: o op 'html' é
                // innerHTML, e renderRow devolve o <div id="block-row-…"> COMPLETO —
                // injetar isso no próprio row aninha a linha dentro dela mesma.
                $listHtml = self::_blockRenderList($cfg);

                return (new MadResponse())
                    ->html('#mad-db-blocks-list-' . $cfg['name'], $listHtml);
            });

        } catch (MadDbBlockCancelled $e) {
            return MadMessage::error('Erro', 'Operação cancelada');
        } catch (Throwable $e) {
            if (self::_blockIsUniqueViolation($e)) {
                return MadMessage::error('Registro duplicado', 'Já existe um item com esses dados.');
            }
            return MadMessage::error('Erro', \Mad\Ui\MadUserError::message($e, null, static::class . ' db-blocks'));
        }
    }

    /**
     * Edita um item existente com os campos do MESMO form do adder (o item
     * abre preenchido no lugar). Irmão do `blockAdd`: mesma whitelist de
     * colunas (`getFillable`), mesmo hook (`on-update`), mesma transação e
     * mesmo re-render da lista.
     *
     * `blockUpdate` continua existindo pro caso "um campo só" (select de
     * nível de permissão do GED, por exemplo) — este aqui é o form inteiro.
     */
    public function blockEdit(int $id, string $state = ''): MadResponse
    {
        if ($state === '') {
            $state = (string) ($_POST['__mad_db_blocks_state'] ?? '');
        }
        $cfg = MadStateCrypt::decrypt($state);
        if (!$cfg) {
            return MadMessage::error('Erro', 'Estado inválido');
        }
        $cfg['recordId'] = MadDbBlocks::resolveRecordId($cfg['recordId'] ?? null, $this);

        try {
            return DB::connection($cfg['database'])->transaction(function () use ($cfg, $id) {
                // Escopo: o item TEM que estar na lista do bloco — filho do pai
                // atual (modo pivot) e dentro dos filtros da tag. Senão um id
                // forjado editaria filho de outro registro.
                $pivot = self::_blockFind($cfg, $id);
                if ($pivot === null) {
                    return self::_blockNotHere($cfg, $id, 'editar');
                }

                $data = self::_blockPayload($this->form ?? null);

                $allowed = method_exists($pivot, 'getFillable') ? $pivot->getFillable() : [];
                $editable = (array) ($cfg['editFields'] ?? []);
                $fixed    = self::_blockFixedColumns($pivot, $cfg);
                $sent     = [];
                foreach ($data as $k => $v) {
                    if (!empty($allowed) && !in_array($k, $allowed, true)) {
                        continue;
                    }
                    if ($editable !== [] && !in_array($k, $editable, true)) {
                        continue;
                    }
                    // A chave do item e as do escopo (o pai, os filtros) não se
                    // trocam pelo formulário: a edição levaria o item embora.
                    if (in_array((string) $k, $fixed, true)) {
                        continue;
                    }
                    if (is_array($v)) $v = implode(',', $v);
                    $sent[(string) $k] = $v;
                    // Igual ao blockAdd, e pior no UPDATE: a coluna que falhou
                    // mantém o valor ANTIGO no banco enquanto a tela mostra o
                    // novo, então a edição "salva" divergente sem nada indicar.
                    try { $pivot->$k = $v; } catch (Throwable $e) {
                        error_log('[MadDbBlocks::blockEdit] coluna "' . $k . '" de '
                            . get_class($pivot) . ' descartada do update (valor antigo '
                            . 'preservado no banco): ' . $e->getMessage());
                    }
                }

                $refused = self::_blockRefusedReferences($pivot, $cfg, $sent);
                if ($refused !== null) {
                    return $refused;
                }

                $hook = $cfg['onUpdate'] ?? '';
                if ($hook && method_exists($this, $hook)) {
                    $r = $this->{$hook}($pivot, null, null);
                    if ($r instanceof MadResponse) {
                        return $r;
                    }
                    if ($r === false) {
                        throw new MadDbBlockCancelled('Operação cancelada');
                    }
                }

                self::_blockSave($pivot);

                $resp = (new MadResponse())
                    ->html('#mad-db-blocks-list-' . $cfg['name'], self::_blockRenderList($cfg));
                self::_blockCloseOverlay($resp, $cfg);

                return $resp;
            });

        } catch (MadDbBlockCancelled $e) {
            return MadMessage::error('Erro', 'Operação cancelada');
        } catch (Throwable $e) {
            return MadMessage::error('Erro', \Mad\Ui\MadUserError::message($e, null, static::class . ' db-blocks'));
        }
    }

    /** Render the full list — delegates to inline or partial depending on config. */
    private static function _blockRenderList(array $cfg): string
    {
        if (!empty($cfg['rowSlot'])) {
            return MadDbBlocks::renderListInline($cfg, $cfg['rowSlot']);
        }
        if (empty($cfg['rowView'])) {
            // Sem template de linha (ex.: modo no-list) não há o que renderizar.
            // Sem este guard, renderList() lança "View [] not found" DENTRO da
            // transação do blockAdd — e o rollback desfaz um save que já deu certo.
            return '';
        }
        return MadDbBlocks::renderList($cfg);
    }

    /** Render a single row — delegates to inline or partial depending on config. */
    private static function _blockRenderRow(array $cfg, $item): string
    {
        if (!empty($cfg['rowSlot'])) {
            return MadDbBlocks::renderRowInline($cfg, $cfg['rowSlot'], $item);
        }
        return MadDbBlocks::renderRow($cfg, $item);
    }

    /**
     * Fecha o overlay do adder OU, no modo `inline` (form fixo na tela),
     * LIMPA o formulário — sem isso o texto recém-enviado continua no
     * campo e o usuário reenvia achando que não salvou (incidente real do
     * comentário duplicado, 24/jul/2026).
     */
    private static function _blockCloseOverlay(MadResponse $resp, array $cfg): void
    {
        $mode = $cfg['addMode'] ?? 'popover';
        $name = $cfg['name'];
        if ($mode === 'inline') {
            // reset() zera todos os campos do form do adder (o form tem
            // data-mad-block-form com o nome do bloco). Alpine/mad:model
            // acompanham via evento input disparado pelo próprio reset.
            $resp->script(
                "(function(){var f=document.querySelector('form[data-mad-block-form=\"{$name}\"]');"
                . "if(f){f.reset();f.querySelectorAll('input,textarea,select').forEach(function(el){"
                . "el.dispatchEvent(new Event('input',{bubbles:true}));"
                . "el.dispatchEvent(new Event('change',{bubbles:true}));});}})()"
            );
            return;
        }
        if ($mode === 'modal') {
            $resp->script("window.dispatchEvent(new CustomEvent('madmodal',{detail:{name:'db-blocks-{$name}-modal',action:'close'}}))");
        } elseif ($mode === 'drawer') {
            $resp->script("window.dispatchEvent(new CustomEvent('maddrawer',{detail:{name:'db-blocks-{$name}-drawer',action:'close'}}))");
        } else {
            $resp->script("window.dispatchEvent(new CustomEvent('mad-db-blocks-popover-close',{detail:{field:'{$name}'}}))");
        }
    }

    /**
     * Grava os arquivos enviados no campo declarado pelo state
     * (`fileField` + `folder` + `pathColumn`). Devolve os pivots EXTRAS
     * criados quando o usuário mandou mais de um arquivo (1 arquivo = 1 item).
     *
     * @return array<int,object>
     */
    /**
     * Ingere os arquivos do campo `fileField` e PREENCHE as colunas — sem
     * salvar. O 1º arquivo ocupa o próprio pivot (que o `_blockSave` grava em
     * seguida, já com path/nome: tabela de anexo costuma ter essas colunas
     * NOT NULL); os demais voltam como pivots extras, prontos pra salvar em
     * ordem.
     *
     * @return array<int,object> pivots extras (arquivos 2..N), ainda não salvos
     */
    private static function _blockIngestFiles(object $pivot, array $cfg): array
    {
        $field = (string) ($cfg['fileField'] ?? '');
        $pathColumn = (string) ($cfg['pathColumn'] ?? '');
        if ($field === '' || $pathColumn === '') {
            return [];
        }

        $files = \Mad\Service\MadUploadIngest::filesFor($field);
        if ($files === []) {
            return [];
        }

        $folder   = (string) ($cfg['folder'] ?? 'uploads');
        $nameMode = (string) ($cfg['fileName'] ?? 'prefix');
        $extras   = [];

        foreach ($files as $i => $file) {
            $target = $i === 0 ? $pivot : self::_blockCloneForFile($pivot, $pathColumn);
            $meta   = \Mad\Service\MadUploadIngest::store($file, $folder, $nameMode, $target);
            $target->$pathColumn = $meta['path'];
            \Mad\Service\MadUploadIngest::fillMeta($target, $meta, $cfg);
            // Ninguém salva aqui: o 1º alvo é o pivot do próprio blockAdd
            // (gravado pelo `_blockSave` logo depois, já com as colunas do
            // arquivo) e os extras são gravados na sequência, em ordem.
            if ($i > 0) {
                $extras[] = $target;
            }
        }

        return $extras;
    }

    /**
     * O aviso do arquivo do campo `fileField` que o PHP não recebeu — ou null.
     * Mesma condição do {@see _blockIngestFiles()}: sem `pathColumn` o bloco
     * não grava arquivo nenhum, e não há o que avisar.
     */
    private static function _blockRefusedFile(array $cfg): ?string
    {
        $field = (string) ($cfg['fileField'] ?? '');
        if ($field === '' || (string) ($cfg['pathColumn'] ?? '') === '') {
            return null;
        }

        return \Mad\Service\MadUploadIngest::refused($field);
    }

    /**
     * Novo pivot com os mesmos campos do original (menos a PK) — 1 arquivo por
     * item. ⚠️ Zera path + metadados: o `replicate()` traz os do PRIMEIRO
     * arquivo e o `fillMeta` só escreve em coluna VAZIA — sem isto o 2º item
     * herdava nome/tamanho/mime do 1º (pego pelo teste de multi-arquivo).
     */
    private static function _blockCloneForFile(object $pivot, string $pathColumn): object
    {
        $clone = $pivot->replicate();
        $clone->exists = false;

        $reset = [$pathColumn];
        foreach (\Mad\Service\MadUploadIngest::META_COLUMNS as $candidates) {
            foreach ($candidates as $col) {
                $reset[] = $col;
            }
        }
        foreach (array_unique($reset) as $col) {
            if ($col !== '' && \Mad\Service\MadUploadIngest::hasColumn($clone, $col)) {
                $clone->$col = null;
            }
        }

        return $clone;
    }

    /**
     * Campos que o formulário do BLOCO mandou.
     *
     * O bloco tem form próprio (só os campos dele + `__mad_db_blocks_state`),
     * então o `mad_model` da requisição é a fonte da verdade. O form da tela
     * entra apenas como base — `MadForm::getData()` filtra pelo schema do form
     * HOSPEDEIRO e sozinho jogaria fora os campos do filho.
     */
    protected static function _blockPayload(mixed $hostForm = null): array
    {
        $base = ($hostForm instanceof MadForm) ? (array) $hostForm->getData() : [];

        $posted = $_POST['mad_model'] ?? null;
        if ($posted === null && function_exists('request')) {
            $posted = request()->input('mad_model');
        }

        return array_merge($base, is_array($posted) ? $posted : []);
    }

    /**
     * O item `$id` como a lista do bloco o mostra — filho do registro aberto
     * (modo pivot) e dentro dos filtros da tag — ou `null`. O número vem da
     * requisição; procurar só pela chave deixava editar e remover a linha de
     * qualquer outro registro da tabela.
     */
    private static function _blockFind(array $cfg, $id): ?object
    {
        $filters = MadDbBlocks::scopeFilters($cfg);
        if ($filters === null || empty($cfg['pivotModel'])) {
            return null;
        }

        $model = ModelOptionsLoader::resolveModelClass((string) $cfg['pivotModel']);
        $query = $model::query();
        QuerySource::applyArrayFilters($query, $filters);

        return $query->where($query->getModel()->getQualifiedKeyName(), $id)->first();
    }

    /** Item fora da lista do bloco (de outro registro, ou removido): nada é feito. */
    private static function _blockNotHere(array $cfg, $id, string $acao): MadResponse
    {
        error_log(sprintf('[MadDbBlocks] %s recusado: o item %s não está na lista do bloco "%s" (%s) — de outro registro, '
            . 'fora dos filtros da tag ou já removido.', $acao, (string) $id, (string) ($cfg['name'] ?? ''), (string) ($cfg['pivotModel'] ?? '')));

        return MadMessage::error('Erro', 'Item não pertence a este registro.');
    }

    /**
     * Colunas que o formulário do bloco não grava: a chave do item e as do
     * escopo (a chave do pai, as colunas dos filtros da tag).
     *
     * @return list<string>
     */
    private static function _blockFixedColumns(object $pivot, array $cfg): array
    {
        $fixed = MadDbBlocks::scopeColumns($cfg);
        if (method_exists($pivot, 'getKeyName')) {
            $fixed[] = (string) $pivot->getKeyName();
        }

        return $fixed;
    }

    /**
     * O campo que o `blockUpdate` pode gravar: um dos `edit-fields` do bloco
     * ou, sem eles, do `$fillable` do Model — Model sem lista nenhuma só
     * aceita o que ele mesmo libera (`$guarded`). Nunca a chave do item nem
     * as colunas do escopo.
     */
    private static function _blockEditable(object $pivot, array $cfg, string $field): bool
    {
        if ($field === '' || in_array($field, self::_blockFixedColumns($pivot, $cfg), true)) {
            return false;
        }
        $editable = array_map('strval', (array) ($cfg['editFields'] ?? []));
        if ($editable !== []) {
            return in_array($field, $editable, true);
        }
        $fillable = method_exists($pivot, 'getFillable') ? $pivot->getFillable() : [];
        if ($fillable !== []) {
            return in_array($field, $fillable, true);
        }

        return method_exists($pivot, 'isFillable') && $pivot->isFillable($field);
    }

    /**
     * As regras de referência (`exists`) do `rules()` do Model sobre o que o
     * formulário do bloco mandou e MUDA na linha — a chave que o item já
     * tinha gravada não é conferida de novo. `null` = pode gravar; senão, a
     * resposta com o motivo (e nada é gravado).
     *
     * @param array<string,mixed> $sent coluna => valor que veio da requisição
     */
    private static function _blockRefusedReferences(object $pivot, array $cfg, array $sent): ?MadResponse
    {
        if ($sent === [] || !($pivot instanceof \Illuminate\Database\Eloquent\Model)) {
            return null;
        }
        $changed = $pivot->exists ? array_intersect_key($pivot->getDirty(), $sent) : array_intersect_key($pivot->getAttributes(), $sent);
        if ($changed === []) {
            return null;
        }

        $errors = ReferenceGuard::check(get_class($pivot), $pivot->exists ? $pivot->getKey() : null, $changed, [], $pivot->getAttributes());
        if ($errors === []) {
            return null;
        }
        foreach (array_keys($errors) as $column) {
            ReferenceGuard::logRefused(sprintf('a coluna "%s" de um item do bloco "%s"', $column, (string) ($cfg['name'] ?? '')));
        }

        return MadMessage::error('Erro', implode("\n", array_values($errors)));
    }

    private static function _blockSave(object $pivot): void
    {
        $pivot->save();
    }
}