@php
    // ── Props ────────────────────────────────────────────────────────────────
    $name       = $name       ?? 'detail';
    $mode       = $mode       ?? 'inline';    // inline | modal | drawer
    $formTitle  = $formTitle  ?? '';
    $formCols   = (int)($formCols ?? 2);
    $addLabel   = $addLabel   ?? mad_t('mad.ui.add');
    $beforeAdd    = $beforeAdd    ?? '';         // método PHP para validação server-side ao adicionar
    $beforeDelete = $beforeDelete ?? '';         // método PHP para validação server-side ao deletar
    $model        = $model        ?? '';         // classe do model Eloquent (ex: 'DocReceitaItem')
    $foreignKey   = $foreignKey   ?? '';         // coluna FK no model filho (habilita auto-save/load)
    $database     = $database     ?? '';         // conexão do banco (default: MAIN_DATABASE)
    $width      = trim((string)($width ?? ''));   // largura do overlay (modal/drawer)
    $perPage    = (int)($perPage ?? 0);
    $sticky     = !empty($sticky);
    // Lado da coluna de ações (fórum #357): mesmo `action-side` da <mad-grid>. Padrão: direita.
    // Na listagem, as diretivas desse lado ficam na coluna 0 de propósito: não deixam
    // espaço no HTML, e à direita a saída é a de antes, byte a byte. A célula das ações
    // é capturada uma vez (antes do x-for) e impressa do lado pedido.
    $actionSide = strtolower(trim((string) ($actionSide ?? 'right'))) === 'left' ? 'left' : 'right';
    $fieldSlot  = $fieldSlot  ?? '';
    $colConfigs = $colConfigs ?? [];
    $actConfigs = $actConfigs ?? [];
    $rows       = $rows       ?? [];

    // Detail é sempre escopado por FK (builder-native); sem filtro extra.
    $_dfCritToken = '';

    // ── Instanciar GridColumn e GridAction a partir dos configs ──────────────
    $columns = [];
    foreach ($colConfigs as $c) {
        // false: no detalhe o Campo da coluna calculada é a coluna do banco que
        // recebe o valor (a listagem usa chave própria, fórum #417).
        $columns[] = \Mad\Grid\MadDataGrid::_colFromConfig($c, false);
    }
    // Coluna que a tela escondeu ("Oculta" ou `display-condition` falso) sai da
    // listagem, como na <mad-grid> — no detalhe as duas eram ignoradas (fórum
    // #365). O índice continua o da coluna (fórmulas de total contam por ele).
    $_dfShowCol = [];
    $_dfColWhen = [];
    foreach ($columns as $_dfCi => $_dfCol) {
        $_dfShowCol[$_dfCi] = !$_dfCol->hidden && $_dfCol->checkDisplay();
        // `visible-when` da coluna: o valor da célula só aparece nas linhas em que vale.
        if ($_dfShowCol[$_dfCi] && $_dfCol->visibleWhen !== '') $_dfColWhen[$_dfCi] = $_dfCol->visibleWhen;
    }
    unset($_dfCi, $_dfCol);
    // A linha do detalhe roda na TELA que contém o <mad-detail-form> — é ela que
    // o perfil marca. Sem informar o dono aqui, as ações da linha (Excluir e
    // companhia) eram as únicas da tela que escapavam da permissão por ação:
    // o servidor recusava o clique, mas o botão continuava lá, prometendo algo
    // que ia falhar. Fora de qualquer tela (preview/render solto) o dono é
    // vazio e nada muda, como no resto do gate.
    $_dfPermOwner = ($_dfPermComp = \Mad\Component\MadRenderContext::getComponent())
        ? get_class($_dfPermComp)
        : '';
    $actions = [];
    $_dfActConds = [];   // índice da ação => config, das que só valem em algumas linhas (fw#363)
    foreach ($actConfigs as $a) {
        $act = \Mad\Grid\MadDataGrid::_actFromConfig($a, $_dfPermOwner);
        if ($act) {
            if ($act->hasRowConditions()) $_dfActConds[count($actions)] = $a;
            $actions[] = $act;
        }
    }

    // ── Colunas com caminho de relacionamento (`{cidade->estado->pais->nome}`) ──
    // Não existem no toArray() da linha: só resolvem percorrendo as relações do
    // registro filho. Mesmo mecanismo do <mad-grid>/<mad-data-table>.
    $_dfRenderFields = \Mad\Grid\GridRenderHelpers::detectRenderFields($columns);

    // ── Auto-load: se model+fk declarados e rows vazias, carrega do banco ─
    $_dfGivenRows = !empty($rows);
    if ($model && $foreignKey && empty($rows)) {
        $_form = \Mad\Component\MadRenderContext::getForm();
        if ($_form) {
            $rows = $_form->autoLoadDetailRows($name, $model, $foreignKey, $database, $_dfRenderFields);
        }
        unset($_form);
    }

    // ── Normalizar rows (garantir __id) ─────────────────────────────────────
    $normalizedRows = \Mad\Form\FieldListColumn::normalizeRows($rows);

    // Linhas entregues pelo código da tela (não pelo auto-load) num detail que
    // grava sozinho: o formulário anota que as mostrou — o Salvar só apaga a
    // linha que a tela mostrou e o usuário removeu.
    if ($_dfGivenRows && $model && $foreignKey && ($_form = \Mad\Component\MadRenderContext::getForm())) {
        $_form->noteDetailRows($name, $model, $foreignKey, $normalizedRows);
    }
    unset($_form, $_dfGivenRows);

    // ── Colunas calculadas (`evaluate`) nas linhas que já existem (#84) ─────
    // A linha adicionada no navegador é calculada pelo gêmeo JS
    // (`_applyEvaluates`); a que veio do banco era montada com `toArray()` e
    // saía 0 — e o total do rodapé junto. Mesma regra do servidor
    // (MadDetailFormRef::evaluateRow), aplicada antes da formatação abaixo.
    $_dfEvalCols = array_values(array_filter(array_map(
        fn($c) => $c->evaluate ? ['field' => $c->field, 'evaluate' => $c->evaluate] : null,
        $columns
    )));
    if ($_dfEvalCols && $normalizedRows) {
        try {
            $_dfModelCls = $model ? \Mad\Form\ModelOptionsLoader::resolveModelClass($model) : '';
        } catch (\Throwable $e) {
            $_dfModelCls = '';
        }
        $_dfEval = new \Mad\Form\MadDetailFormRef($name, $_dfEvalCols, $_dfModelCls);
        foreach ($normalizedRows as $_dfI => $_dfRow) {
            $_dfEval->evaluateRow($_dfRow);
            $normalizedRows[$_dfI] = $_dfRow;
        }
        unset($_dfEval, $_dfModelCls, $_dfRow, $_dfI);
    }
    unset($_dfEvalCols);

    // ── Transform CALLABLE / token de mídia: pré-computa no servidor ────────
    // O cliente não executa `Classe::metodo` nem monta o HTML de `file-thumb`.
    // Token built-in (money/cpf/date-long/…) NÃO entra aqui: tem gêmeo JS
    // (GridRenderHelpers::builtinFormatJs) e formata também a linha criada no
    // navegador. Aqui só o que depende do PHP.
    //
    // Chave `__fmt`: o prefixo `__` é descartado no save (MadForm::_saveDetailRows
    // e ::_persistDetailInstance pulam `__*`), então não vira coluna nem faz
    // linha vazia parecer preenchida. A linha EDITADA no navegador é remontada
    // pelo `formData` e perde o `__fmt` — cai no valor cru, que é melhor que
    // exibir um formatado obsoleto.
    $_dfFmtCols = array_values(array_filter(
        $columns,
        fn($c) => $c->hasTransform || $c->mediaFormat !== '' || $c->iconFormat !== ''
    ));
    if ($_dfFmtCols) {
        foreach ($normalizedRows as $_dfI => $_dfRow) {
            $_dfFmt = [];
            foreach ($_dfFmtCols as $_dfCol) {
                $_dfKey = \Mad\Grid\GridRenderHelpers::rowDataKey($_dfCol->field);
                $_dfRaw = $_dfRow[$_dfKey] ?? ($_dfRow[$_dfCol->field] ?? '');
                try {
                    // renderValue já escapa (htmlspecialchars) o que não é html/mídia
                    // — por isso a célula desse ramo usa x-html.
                    $_dfFmt[$_dfCol->field] = $_dfCol->renderValue($_dfRaw, $_dfRow);
                } catch (\Throwable $e) {
                    // Transformer do dev que lança não pode derrubar o formulário
                    // inteiro: degrada pro valor cru (mesma política do PDV).
                    error_log('[mad-detail-form] transform da coluna "' . $_dfCol->field
                        . '" lançou: ' . $e->getMessage() . ' — exibindo valor cru.');
                    $_dfFmt[$_dfCol->field] = htmlspecialchars(
                        is_scalar($_dfRaw) ? (string) $_dfRaw : '',
                        ENT_QUOTES
                    );
                }
            }
            $normalizedRows[$_dfI]['__fmt'] = $_dfFmt;
        }
    }

    // ── Config JSON para Alpine ─────────────────────────────────────────────
    $alpineCfg = [
        'name'      => $name,
        'mode'      => $mode,
        'rows'      => $normalizedRows,
        'columns'   => array_map(fn($c) => array_filter([
            'field'    => $c->field,
            'default'  => '',
            'evaluate' => $c->evaluate ?: null,
            // Fórmula do rodapé (`total-formula`) vai pela config, não inline no
            // x-text: o texto do dev pode ter aspas. O rodapé chama pelo índice.
            'totalFormula' => $c->totalFormula !== '' ? $c->totalFormula : null,
        ], fn($v) => $v !== null), $columns),
        'perPage'   => $perPage,
        'beforeAdd'    => $beforeAdd ?: null,
        'beforeDelete' => $beforeDelete ?: null,
    ];

    // ── Registra no MadFormRegistry para acesso via $form->getDetailForm() ──
    \Mad\Form\MadFormRegistry::registerDetailForm($name, array_map(fn($c) => [
        'field'    => $c->field,
        'label'    => $c->label,
        'evaluate' => $c->evaluate ?: null,
    ], $columns), $model, $foreignKey, $database);

    // ── Form fields: renderiza os componentes Blade do fieldSlot ────────────
    // O fieldSlot vem como string crua com tags Blade (<mad-input-field>, etc.)
    // Compilamos em runtime via MadBlade::renderString.
    // Snapshot do registry antes/depois: campos file/image que o sub-form
    // registrar são colunas de arquivo deste detail (auto-save por-linha).
    $_dfFieldsBefore = array_keys(\Mad\Form\MadFormRegistry::getFields());
    // Os campos desenhados daqui até o fim do editor são colunas da LINHA: o
    // formulário da tela não os toma por campos do registro principal.
    \Mad\Form\MadFormRegistry::beginDetailEditor($name);
    // ...e o valor inicial deles é o padrão da tag (`value`, `:checked`), não o
    // que o registro principal tem numa coluna de mesmo nome: é desse HTML que
    // a linha nova tira os padrões (mad-ui.js, _applyFieldDefaults).
    preg_match_all('/\sname\s*=\s*"([^"{}$]+)"/', (string) $fieldSlot, $_dfSlotNames);
    \Mad\Component\MadRenderContext::pushWithout($_dfSlotNames[1]);
    try {
        $formHtml = \Mad\View\MadBlade::renderString($fieldSlot, get_defined_vars());
    } finally {
        \Mad\Component\MadRenderContext::pop();
        \Mad\Form\MadFormRegistry::endDetailEditor($formHtml ?? '');
    }
    // Lista de itens da LINHA (fw#251): um <mad-field-list> com model e
    // foreign-key dentro do editor. Cada linha do detalhe leva os itens dela,
    // na chave com o nome da lista.
    if (($_form = \Mad\Component\MadRenderContext::getForm()) && $_form->hasNestedLists($name)) {
        $normalizedRows    = $_form->loadNestedRows($name, $normalizedRows);
        $alpineCfg['rows'] = $normalizedRows;
    }
    unset($_form);

    // Texto dos valores que as linhas já têm nos campos de busca no banco do
    // editor (fórum #273): o campo é desenhado vazio e, ao editar a linha, só
    // recebia a chave — mostrava "1619" no lugar de "1619 - Supermercado...".
    $_dfLabels = \Mad\Form\DetailRowLabels::resolve(
        array_values(array_diff(array_keys(\Mad\Form\MadFormRegistry::getFields()), $_dfFieldsBefore)),
        $normalizedRows
    );
    if ($_dfLabels) {
        $alpineCfg['labels'] = $_dfLabels;
    }
    unset($_dfLabels);

    // ── Casas dos campos numéricos do detalhe (fórum #291 r5): a coluna sem formato
    // explícito mostra o mesmo formato do campo (madNumFmt), na linha carregada e na recalculada.
    $_dfNumDecimals = [];
    if (preg_match_all('/<mad-(?:numeric|number)-field\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', (string) $fieldSlot, $_dfNumTags)) {
        foreach ($_dfNumTags[0] as $_dfNumTag) {
            if (!preg_match('/\sname\s*=\s*"([^"{}$]+)"/', $_dfNumTag, $_dfNumN)) continue;
            $_dfNumDecimals[$_dfNumN[1]] = preg_match('/\s:?decimals\s*=\s*"(\d+)"/', $_dfNumTag, $_dfNumD)
                ? (int) $_dfNumD[1] : 2;
        }
    }
    unset($_dfNumTags, $_dfNumTag, $_dfNumN, $_dfNumD);

    // ── Casas DECLARADAS nos campos do sub-form (fórum #330): a coluna `money`, a
    // `num`/`number` sem valor e os formatos "Real"/"Número" (transform money/number)
    // mostram as casas que o campo de mesmo nome declara (`decimals`/`:decimals`),
    // na linha carregada, na criada/editada e no total. Coluna com casas escolhidas
    // (`num="4"`, decimal-4, inteiro…), campo sem `decimals` ou sem campo: nada muda.
    $_dfFieldDec = [];
    if (preg_match_all('/<mad-(?:numeric|number|money)-field\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', (string) $fieldSlot, $_dfFdTags)) {
        foreach ($_dfFdTags[0] as $_dfFdTag) {
            if (!preg_match('/\sname\s*=\s*"([^"{}$]+)"/', $_dfFdTag, $_dfFdN)) continue;
            if (preg_match('/\s:?decimals\s*=\s*"(\d+)"/', $_dfFdTag, $_dfFdD)) {
                $_dfFieldDec[$_dfFdN[1]] = max(0, min(20, (int) $_dfFdD[1]));
            }
        }
    }
    unset($_dfFdTags, $_dfFdTag, $_dfFdN, $_dfFdD);
    $_dfInheritDec = static function ($col) use ($_dfFieldDec): ?int {
        if ($col->hasTransform || $col->mediaFormat !== '' || $col->iconFormat !== '') return null;
        $key = \Mad\Grid\GridRenderHelpers::rowDataKey($col->field);
        if (!array_key_exists($key, $_dfFieldDec)) return null;
        if ($col->isMoney || ($col->isNumber && $col->numberDecimalsAuto)) return $_dfFieldDec[$key];
        if (!$col->isMoney && !$col->isNumber && in_array($col->builtinFormat, ['money', 'number'], true)) {
            return $_dfFieldDec[$key];
        }
        return null;
    };

    // ── Campos do sub-form com `compute` que lê o formulário principal (`{@campo}`,
    // fórum #291): as linhas que já existem recalculam esse campo quando o mestre
    // muda e na carga, como a tela. Só as tags com `{@` entram; as demais ficam como estão.
    $_dfMestreComputes = [];
    // `>` dentro de aspas (`{@peca->estoque_atual}`) não fecha a tag: o padrão anda por atributos
    // entre aspas (fórum #291 r5).
    if (preg_match_all('/<mad-[\w-]+\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>/', (string) $fieldSlot, $_dfTags)) {
        foreach ($_dfTags[0] as $_dfTag) {
            if (!preg_match('/\scompute\s*=\s*"([^"]*\{@[^"]*)"/', $_dfTag, $_dfC)) continue;
            if (!preg_match('/\sname\s*=\s*"([^"{}$]+)"/', $_dfTag, $_dfN)) continue;
            $_dfMestreComputes[] = [
                'field' => $_dfN[1],
                'expr'  => html_entity_decode($_dfC[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            ];
        }
    }
    if ($_dfMestreComputes) {
        $alpineCfg['mestreComputes'] = $_dfMestreComputes;
    }
    unset($_dfTags, $_dfTag, $_dfC, $_dfN, $_dfMestreComputes);

    // ── Ações da linha que só valem em algumas linhas (fw#363) ──────────────
    // `when-*`/`disabled-*` o navegador avalia na linha como ela está (também a
    // adicionada ali); `display-condition`, `disabled` por método e
    // `deny-condition` (fw#636) são PHP: vêm calculados por linha carregada —
    // o motivo da recusa vai em `actDeny` e vira a dica do botão. O servidor
    // reconfere antes de rodar a ação (MadComponent::_detailRowActionRefusal),
    // pelo que o formulário anota aqui.
    if ($_dfActConds) {
        $_dfHosts  = array_values(array_filter([$_dfPermOwner]));
        $_dfRules  = [];
        $_dfRowFn  = [];
        $_dfRowDeny = [];
        $_dfByMeth = [];
        foreach ($_dfActConds as $_dfI => $_dfA) {
            $_dfRule = array_filter([
                'w'  => is_array($_dfA['when'] ?? null) ? $_dfA['when'] : null,
                'wf' => is_array($_dfA['whenField'] ?? null) ? $_dfA['whenField'] : null,
                'd'  => is_array($_dfA['disabled'] ?? null) ? $_dfA['disabled'] : null,
            ]);
            $_dfFnCfg = array_filter([
                'method'   => (string) ($_dfA['method'] ?? ''),
                'when'     => is_string($_dfA['when'] ?? null) ? $_dfA['when'] : null,
                'disabled' => is_string($_dfA['disabled'] ?? null) ? $_dfA['disabled'] : null,
                'deny'     => is_string($_dfA['deny'] ?? null) ? $_dfA['deny'] : null,
            ]);
            if (count($_dfFnCfg) > 1 && ($_dfFnAct = \Mad\Grid\MadDataGrid::_actFromConfig($_dfFnCfg)?->resolveIn(...$_dfHosts))) {
                $_dfRule['f'] = 1;
                foreach ($normalizedRows as $_dfRow) {
                    $_dfRid = (string) ($_dfRow['__id'] ?? '');
                    if ($_dfRid === '') continue;
                    try {
                        $_dfSt = '';
                        if (!$_dfFnAct->isVisible($_dfRow)) {
                            $_dfSt = 'hide';
                        } elseif (($_dfDeny = $_dfFnAct->denyReason($_dfRow)) !== '') {
                            $_dfRowDeny[$_dfRid][$_dfI] = $_dfDeny;
                        } elseif ($_dfFnAct->isDisabled($_dfRow)) {
                            $_dfSt = 'off';
                        }
                    } catch (\Throwable $e) {
                        $_dfSt = '';
                    }
                    if ($_dfSt !== '') $_dfRowFn[$_dfRid][$_dfI] = $_dfSt;
                }
            }
            $_dfRules[$_dfI] = $_dfRule;
            $_dfByMeth[(string) ($_dfA['method'] ?? '')][] = array_intersect_key($_dfA, array_flip(['when', 'whenField', 'disabled', 'deny']));
        }
        $alpineCfg['actRules'] = $_dfRules;
        if ($_dfRowFn) $alpineCfg['actFn'] = $_dfRowFn;
        if ($_dfRowDeny) $alpineCfg['actDeny'] = $_dfRowDeny;
        // Método que também tem ação SEM condição vale em qualquer linha.
        foreach ($actions as $_dfI => $_dfAct) {
            if (!isset($_dfActConds[$_dfI])) unset($_dfByMeth[$_dfAct->method]);
        }
        unset($_dfHosts, $_dfRules, $_dfRowFn, $_dfRowDeny, $_dfDeny, $_dfI, $_dfA, $_dfRule, $_dfFnCfg, $_dfFnAct, $_dfRow, $_dfRid, $_dfSt, $_dfAct);
    }
    \Mad\Component\MadRenderContext::getForm()?->declareDetailActions($name, $_dfByMeth ?? [], (string) $model, (string) $foreignKey);
    unset($_dfByMeth);
    if ($_dfColWhen) $alpineCfg['colWhen'] = $_dfColWhen;
    $alpineCfg = json_encode($alpineCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $_dfFileCols = [];
    $_dfColMeta  = [];
    $_dfDateFmt  = [];   // campo de data do editor => formato PHP da tela (célula sem formato)
    $_dfEditor   = [];   // campos do editor: colunas da LINHA, não do registro principal
    foreach (\Mad\Form\MadFormRegistry::getFields() as $_dfFn => $_dfFp) {
        if (in_array($_dfFn, $_dfFieldsBefore, true)) {
            continue;
        }
        $_dfEditor[] = (string) $_dfFn;
        // strip-mask do campo do editor vale para a coluna da linha: sem isto
        // o auto-save gravava '123.456.789-01' mesmo com strip-mask declarado.
        if (!empty($_dfFp['stripMask']) && !empty($_dfFp['mask'])) {
            $_dfColMeta[$_dfFn] = ['mask' => (string) $_dfFp['mask'], 'stripMask' => true];
        }
        $_dfT = $_dfFp['type'] ?? '';
        // Data / data e hora do editor (fórum #334): a linha grava no formato do
        // banco, como o formulário principal — o texto da tela ("16/11/2026 13:40")
        // ia cru e PostgreSQL/MySQL recusavam a linha. A célula sem formato
        // próprio mostra a data no formato do campo (inclusive a que volta do banco).
        if ($_dfT === 'date' || $_dfT === 'datetime') {
            $_dfColMeta[$_dfFn] = ($_dfColMeta[$_dfFn] ?? []) + array_filter([
                'date'        => $_dfT,
                'dbMask'      => (string) ($_dfFp['database_mask'] ?? ''),
                'displayMask' => (string) ($_dfFp['display_mask'] ?? ''),
            ]);
            $_dfDateFmt[$_dfFn] = \Mad\Support\DateMask::toPhp((string) ($_dfFp['display_mask'] ?? '')
                ?: ($_dfT === 'date' ? \Mad\Support\DateMask::date() : \Mad\Support\DateMask::datetime()));
        }
        // `image` SEM `storage` (<mad-image-field>, <mad-signature-field>)
        // guarda o base64 na própria coluna — o hidden com `name` o leva na
        // linha, como no formulário próprio da tabela. Como coluna de arquivo
        // (com "disk" no lugar do storage ausente) o Salvar pulava o valor e,
        // sem arquivo enviado, a coluna ficava NULL: a assinatura desenhada na
        // cortina sumia sem erro. O `file` sem storage segue indo para o disco,
        // e o `<mad-avatar-field>` também: ele só manda o arquivo (o
        // `<input type="file">` dele tem `name` mesmo sem storage), que é o
        // que o navegador captura como arquivo da linha.
        if ($_dfT === 'image' && empty($_dfFp['storage'])
            && !preg_match(
                '/<input\b(?=(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\stype="file")(?=(?:[^>"\']|"[^"]*"|\'[^\']*\')*?\sname="'
                    . preg_quote(e((string) $_dfFn), '/') . '")/',
                (string) $formHtml
            )) {
            continue;
        }
        if ($_dfT === 'file' || $_dfT === 'image') {
            $_dfFileCols[$_dfFn] = [
                'storage'    => $_dfFp['storage'] ?? 'disk',
                'folder'     => $_dfFp['folder'] ?? 'uploads',
                'fileName'   => $_dfFp['fileName'] ?? 'prefix',
                'nameColumn' => $_dfFp['nameColumn'] ?? '',
                'multi'      => !empty($_dfFp['multiple']),
            ] + array_filter([
                // `public` (#244), Tipos aceitos e Tamanho máximo do campo valem
                // para o arquivo da linha como no formulário principal (fórum
                // #274: o arquivo ia para a pasta privada, sem conferir o tipo).
                'public'   => !empty($_dfFp['public']),
                'accept'   => (string) ($_dfFp['accept'] ?? ''),
                'maxBytes' => (int) ($_dfFp['maxBytes'] ?? 0),
            ]);
        }
    }
    // Sempre, mesmo sem campo de arquivo no editor: o formulário da tela anota
    // como o detalhe trata os arquivos das linhas, e o Salvar só aceita essa
    // descrição.
    \Mad\Form\MadFormRegistry::registerDetailFileColumns($name, $_dfFileCols);
    if ($_dfColMeta) {
        \Mad\Form\MadFormRegistry::registerDetailColMeta($name, $_dfColMeta);
    }
    \Mad\Form\MadFormRegistry::registerDetailEditorFields($name, $_dfEditor);

    // Colunas da linha que este detail não mostra: o formulário anota como
    // estavam no banco — o Salvar não regrava a que ninguém mexeu. No detail
    // gravado à mão (sem model na tag) a base vem do loadDetailRows() da tela.
    if ($_form = \Mad\Component\MadRenderContext::getForm()) {
        $_form->noteDetailColumns($name, (string) $model, (string) $foreignKey, $normalizedRows);
    }
    // As linhas como vão para o navegador: o que voltar diferente nas colunas
    // que o detalhe não tem não é aceito (MadForm::takeRowsFromBrowser).
    \Mad\Component\MadRenderContext::getForm()?->rowsRendered($name, $normalizedRows);
    unset($_form, $_dfEditor);

    $hasTotals = !empty(array_filter($columns, fn($c, $i) => $_dfShowCol[$i] && ($c->totalFunc || $c->totalFormula !== ''), ARRAY_FILTER_USE_BOTH));

    // Formulário em leitura / modo Visualizar (#351, fórum #382): só a lista
    // dos itens — sem Adicionar, sem o formulário do item, sem Editar/Remover
    // e sem célula editável. Ação própria da linha segue a regra dos botões
    // da tela aberta pelo Visualizar (some a que grava).
    $_dfRead = \Mad\Form\ReadMode::mode($__env) !== '';
    $_dfActHidden = fn($act) => $act->permMode() === 'hide' || ($_dfRead && \Mad\Form\ViewOnly::hides((string) $act->method));
    $_dfShowActions = ! $_dfRead || count(array_filter($actions, fn($a) => ! $_dfActHidden($a))) > 0;

    // `hide-below` da coluna (fórum #409): a coluna some — cabeçalho, células e
    // total — em tela mais estreita que N px, como na <mad-grid> e no
    // <mad-field-list>. Só a coluna com a opção ganha a classe `mad-df-cN`; sem
    // nenhuma, o HTML é o de antes. O valor continua indo no POST.
    $_dfHbCls = [];
    $_dfHbCss = '';
    $_dfHbUid = '';
    foreach ($columns as $_dfCi => $_dfCol) {
        if ($_dfShowCol[$_dfCi] && (int) $_dfCol->hideBelow > 0) $_dfHbCls[$_dfCi] = (int) $_dfCol->hideBelow;
    }
    if ($_dfHbCls) {
        $_dfHbUid = 'df' . bin2hex(random_bytes(5));
        $_dfHbBy = [];
        foreach ($_dfHbCls as $_dfCi => $_dfPx) $_dfHbBy[$_dfPx][] = $_dfCi;
        krsort($_dfHbBy);
        foreach ($_dfHbBy as $_dfPx => $_dfIdx) {
            $_dfSel = implode(',', array_map(fn ($i) => '[data-mad-df-uid="' . $_dfHbUid . '"] .mad-df-c' . (int) $i, $_dfIdx));
            $_dfHbCss .= '@media (max-width:' . ($_dfPx - 0.02) . 'px){' . $_dfSel . '{display:none !important}}';
        }
    }
    $_dfColCls = fn (int $i): string => isset($_dfHbCls[$i]) ? ' mad-df-c' . $i : '';
    unset($_dfCi, $_dfCol, $_dfPx, $_dfIdx, $_dfSel, $_dfHbBy);
@endphp

<div class="mad-df{{ $_dfRead ? ' mad-df-read' : '' }}" data-mad-df-name="{{ $name }}" x-data="madDetailForm({{ $alpineCfg }})">

    {{-- Token de escopo: sobrevive ao POST para o auto-save aplicar o mesmo escopo --}}
    @if($_dfCritToken)
        <input type="hidden" name="__mad_fl_crit[{{ $name }}]" value="{{ $_dfCritToken }}">
    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         FORMULÁRIO — inline, modal ou drawer
         ═══════════════════════════════════════════════════════════════════════ --}}

    @php
        $dfOverlayName = 'df_' . $name;
        $btnLabelJs    = htmlspecialchars($addLabel, ENT_QUOTES);
        // `width` do <mad-detail-form> vira a prop `size` do overlay: token do
        // mapa (sm|md|lg|xl|full) ou medida livre — x-drawer/x-modal já passam
        // por CssUnits::length e caem no default deles se a medida for inválida.
        $dfModalSize   = $width !== '' ? $width : 'lg';
        $dfDrawerSize  = $width !== '' ? $width : 'md';
    @endphp

    @if($_dfRead)
    {{-- Em leitura: sem Adicionar e sem o formulário do item. --}}
    @elseif($mode === 'modal')

    {{-- Botão Adicionar --}}
    <div class="mad-df-toolbar">
        <button type="button" class="mad-btn mad-btn-primary" @click="openNew()">
            <i data-lucide="plus" style="width:14px;height:14px;"></i>
            {!! $addLabel !!}
        </button>
    </div>

    {{-- Formulário do item: clique fora não fecha (perdia o que foi digitado);
         fecha no X, no Esc, em Cancelar e ao adicionar/atualizar. --}}
    <x-modal :name="$dfOverlayName" :title="$formTitle ?: 'Detalhe'" :size="$dfModalSize" :close-on-backdrop="false">
        <div data-df-fields data-df-name="{{ $name }}" @keydown.enter.prevent="addOrUpdate()">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()">
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? '{{ addslashes(mad_t('mad.ui.update')) }}' : '{{ $btnLabelJs }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" @click="cancelEdit()">{!! __('mad.cancel') !!}</button>
        </div>
    </x-modal>

    @elseif($mode === 'drawer')

    {{-- Botão Adicionar --}}
    <div class="mad-df-toolbar">
        <button type="button" class="mad-btn mad-btn-primary" @click="openNew()">
            <i data-lucide="plus" style="width:14px;height:14px;"></i>
            {!! $addLabel !!}
        </button>
    </div>

    {{-- Idem modal: clique fora não fecha a cortina do item. --}}
    <x-drawer :name="$dfOverlayName" :title="$formTitle ?: 'Detalhe'" :size="$dfDrawerSize" :close-on-backdrop="false">
        <div data-df-fields data-df-name="{{ $name }}" @keydown.enter.prevent="addOrUpdate()">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()">
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? '{{ addslashes(mad_t('mad.ui.update')) }}' : '{{ $btnLabelJs }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" @click="cancelEdit()">{!! __('mad.cancel') !!}</button>
        </div>
    </x-drawer>

    @else {{-- inline --}}

    <div class="mad-df-form-inline mad-card">
        <div data-df-fields data-df-name="{{ $name }}">
            {!! $formHtml !!}
        </div>
        <div class="mad-df-form-actions">
            <button type="button" class="mad-btn mad-btn-primary" @click="addOrUpdate()"
                    style="display:inline-flex;align-items:center;gap:6px;">
                <i data-lucide="plus" style="width:14px;height:14px;" x-show="(typeof editIndex!=='undefined' && editIndex < 0)"></i>
                <i data-lucide="check" style="width:14px;height:14px;" x-show="(typeof editIndex!=='undefined' && editIndex >= 0)" x-cloak></i>
                <span x-text="(typeof editIndex!=='undefined' && editIndex >= 0) ? '{{ addslashes(mad_t('mad.ui.update')) }}' : '{{ $addLabel }}'"></span>
            </button>
            <button type="button" class="mad-btn mad-btn-ghost" x-show="(typeof editIndex!=='undefined' && editIndex >= 0)" x-cloak
                    @click="cancelEdit()" style="display:inline-flex;align-items:center;gap:6px;">
                <i data-lucide="x" style="width:14px;height:14px;"></i> {{ mad_t('mad.ui.cancel') }}
            </button>
        </div>
    </div>

    @endif

    {{-- ═══════════════════════════════════════════════════════════════════════
         LISTAGEM (GRID)
         ═══════════════════════════════════════════════════════════════════════ --}}

    <div class="mad-df-list" style="margin-top:12px;">
        {{-- data-mobile="table": no celular o detalhe segue tabela, com rolagem
             lateral. O cartão do celular do tema ERP é da <mad-grid> (o servidor
             marca o lugar de cada célula); aqui escondia todas as células e
             sobravam só os botões da linha (fórum #409). --}}
@if($_dfHbCss !== '')
        <style>{!! $_dfHbCss !!}</style>
@endif
        <div class="mad-dg-wrap" data-mobile="table"@if($_dfHbUid !== '') data-mad-df-uid="{{ $_dfHbUid }}"@endif style="overflow-x:auto;">
            <table class="mad-dg-table">
                <thead class="mad-dg-head{{ $sticky ? ' mad-dg-sticky' : '' }}">
                    <tr>
@if($actionSide === 'left' && $_dfShowActions)
                        <th class="mad-dg-th" style="width:80px;text-align:center;">{{ mad_t('mad.ui.actions') }}</th>
@endif
                        @foreach($columns as $col)
@continue(!$_dfShowCol[$loop->index])
                        @php
                            // Config crua (sem passar pelo setter fluente) trazia '140'
                            // sem unidade e a largura era descartada em silêncio.
                            $_thW = \Mad\Support\CssUnits::length((string) ($col->width ?? ''));
                        @endphp
                        <th class="mad-dg-th{{ $_dfColCls($loop->index) }}" style="text-align:{{ $col->align }};{{ $_thW !== '' ? 'width:'.$_thW.';' : '' }}">
                            {{ $col->label }}
                        </th>
                        @endforeach
@if($actionSide === 'right' && $_dfShowActions)
                        <th class="mad-dg-th" style="width:80px;text-align:center;">{{ mad_t('mad.ui.actions') }}</th>
@endif
                    </tr>
                </thead>
                <tbody>
@php ob_start(); @endphp
                            <td class="mad-dg-cell mad-dg-actions-cell" style="text-align:center;">
                                <div class="mad-dg-actions">
                                    @if(! $_dfRead)
                                    <button type="button" class="mad-dg-action-btn" title="{{ mad_t('mad.ui.edit') }}"
                                            @click="editRow(idx)">
                                        <i data-lucide="pencil" style="width:14px;height:14px;"></i>
                                    </button>
                                    <button type="button" class="mad-dg-action-btn mad-dg-action-danger"
                                            title="{{ mad_t('mad.ui.remove') }}" @click="madDeletePopover($event, () => deleteRow(idx))">
                                        <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
                                    </button>
                                    @endif
                                    @foreach($actions as $act)
                                    {{-- As linhas do detalhe são montadas no navegador (x-for), então
                                         não há linha em PHP para os callbacks por linha. Permissão não
                                         depende da linha — é da ação — e por isso vem de permMode(). --}}
                                    @if(! $_dfActHidden($act))
                                    @php
                                        $_dfCondAttr = isset($_dfActConds[$loop->index]) ? ' x-show="_actState(row, ' . $loop->index . ') !== \'hide\'" :disabled="_actState(row, ' . $loop->index . ') === \'off\'"' : '';
                                        // deny-condition (fw#636): na linha recusada, o botão fica
                                        // como o do perfil (aria-disabled + data-mad-deny) e a dica
                                        // é o motivo. A recusa do perfil, fixa, vence.
                                        if (!empty($_dfActConds[$loop->index]['deny']) && $act->permMode() !== 'disable') {
                                            $_dfDn = '_actDeny(row, ' . $loop->index . ')';
                                            $_dfCondAttr .= ' :aria-disabled="' . $_dfDn . ' ? \'true\' : null" :data-mad-deny="' . $_dfDn . ' ? \'\' : null"'
                                                . ' :title="' . $_dfDn . ' || ' . e(json_encode($act->label, JSON_UNESCAPED_UNICODE)) . '"';
                                        }
                                    @endphp
                                    <button type="button"
                                            class="mad-dg-action-btn{{ $act->isPrimary ? ' mad-dg-action-primary' : '' }}{{ $act->isDanger ? ' mad-dg-action-danger' : '' }}"
                                            title="{{ $act->denyTitle() ?: $act->label }}"@if($act->permMode() === 'disable') aria-disabled="true" data-mad-deny @endif{!! $_dfCondAttr !!}
                                            @click="callAction('{{ $act->method }}', row, idx)">
                                        @if($act->icon)<i data-lucide="{{ $act->icon }}" style="width:14px;height:14px;"></i>@endif
                                    </button>
                                    @endif
                                    @endforeach
                                </div>
                            </td>
@php $_dfActionsCell = ob_get_clean(); @endphp
                    <template x-for="(row, idx) in (perPage > 0 ? pagedRows : rows)" :key="row.__id">
                        <tr class="mad-dg-row" :class="{ 'mad-df-row-editing': (typeof editIndex!=='undefined' && editIndex === idx) }"
                            x-init="$nextTick(() => { if (typeof _madLucide === 'function') _madLucide([$el]); })">
@if($actionSide === 'left' && $_dfShowActions)
@php echo $_dfActionsCell; @endphp
@endif
                            @foreach($columns as $col)
@continue(!$_dfShowCol[$loop->index])
                            @php
                                // `field` e TEMPLATE DE EXIBICAO, nao chave da linha:
                                // a linha do servidor traz o template resolvido na
                                // propria chave, a criada no navegador so tem as
                                // chaves nuas dos inputs do sub-form. `$_cv` le as
                                // duas; `$_ck` e a chave de ESCRITA (edicao inline).
                                $_cv = \Mad\Grid\GridRenderHelpers::rowValueJs($col->field);
                                $_ck = \Mad\Grid\GridRenderHelpers::rowDataKey($col->field);

                                // Transform da coluna — MESMA precedência do
                                // GridColumn::renderValue (callable/mídia → badge →
                                // money → number → date → built-in → html → cru),
                                // pra célula do detalhe e célula da grid contarem a
                                // mesma história.
                                //  • callable/mídia: só o servidor resolve → lê o
                                //    mapa `__fmt` da linha (x-html: já vem escapado).
                                //  • built-in: gêmeo JS inline → vale também pra
                                //    linha criada/editada no navegador.
                                // O valor da linha NUNCA é tocado: `row[chave]`
                                // segue cru, que é o que vai no POST.
                                $_cFmtKey = \Mad\Grid\GridRenderHelpers::fieldKeyJs($col->field);
                                $_cSrvFmt = $col->hasTransform || $col->mediaFormat !== '' || $col->iconFormat !== '';
                                // Casas do campo do sub-form (fórum #330) — null = as da coluna.
                                $_cDec    = $_dfInheritDec($col);
                                // Formato em que o campo de data do sub-form escreve a linha
                                // criada/editada no navegador ("10/11/2026"): a célula lê por
                                // ele, e não como mês/dia (troca de dia e mês na grade).
                                $_cInFmt  = $_dfDateFmt[\Mad\Grid\GridRenderHelpers::rowDataKey($col->field)] ?? null;
                                $_cFmtJs  = $col->builtinFormat !== ''
                                    ? \Mad\Grid\GridRenderHelpers::builtinFormatJs($col->builtinFormat, $_cv, $_cDec, $_cInFmt)
                                    : '';
                                // Tem valor pré-formatado NESTA linha? (a criada/editada
                                // no navegador não tem — cai no ramo cru, escapado
                                // pelo x-text, nunca no x-html).
                                $_cHasFmt = "(row.__fmt && row.__fmt[{$_cFmtKey}] != null)";
                                // Coluna sem formato explícito cujo campo do detalhe é numérico: mesma
                                // casa do campo (fórum #291 r5). Coluna com formato próprio não muda.
                                $_cNumDec = null;
                                if ($col->builtinFormat === '' && !$col->hasTransform && $col->mediaFormat === ''
                                    && $col->iconFormat === '' && !$col->isNumber && !$col->isMoney && !$col->isDate
                                    && !$col->isBadge && !$col->isHtml) {
                                    $_cNumKey = \Mad\Grid\GridRenderHelpers::rowDataKey($col->field);
                                    if (array_key_exists($_cNumKey, $_dfNumDecimals)) {
                                        $_cNumDec = $_dfNumDecimals[$_cNumKey];
                                    }
                                }
                                // Idem para o campo de data / data e hora (fórum #334): a linha que
                                // volta do banco ("2026-11-16 13:40:00") aparece como a tela digita.
                                $_cDateFmt = null;
                                if ($_cNumDec === null && $col->builtinFormat === '' && !$col->hasTransform && $col->mediaFormat === ''
                                    && $col->iconFormat === '' && !$col->isNumber && !$col->isMoney && !$col->isDate
                                    && !$col->isBadge && !$col->isHtml) {
                                    $_cDateFmt = $_dfDateFmt[\Mad\Grid\GridRenderHelpers::rowDataKey($col->field)] ?? null;
                                }
                            @endphp
                            <td class="mad-dg-cell{{ $_dfColCls($loop->index) }}" style="text-align:{{ $col->align }};">
@if(isset($_dfColWhen[$loop->index]))
                                <span data-mad-col-when x-show="_colVis(row, {{ $loop->index }})">
@endif
                                @if(! $_dfRead && $col->editable && $col->editType === 'switch')
                                {{-- Chave liga/desliga (fórum #297): alterna o valor da linha;
                                     grava com o formulário. 'true'/'false' vão como booleano. --}}
                                @php
                                    $_swOnJs  = json_encode(strtolower($col->editOn) === 'true' ? true : (strtolower($col->editOn) === 'false' ? false : $col->editOn));
                                    $_swOffJs = json_encode(strtolower($col->editOff) === 'false' ? false : (strtolower($col->editOff) === 'true' ? true : $col->editOff));
                                    $_swOnLc  = json_encode(strtolower($col->editOn));
                                    $_swOffLc = json_encode(strtolower($col->editOff));
                                @endphp
                                <label class="mad-toggle mad-toggle--sm mad-dg-switch" data-mad-no-row-click>
                                    <input type="checkbox" data-mad-df-switch aria-label="{{ trim(strip_tags((string) $col->label)) }}"
                                           :checked="(v => { if (v === true) return true; if (v === false || v === null || v === undefined || v === '') return false; const s = String(v).trim().toLowerCase(); if (s === {{ $_swOnLc }}) return true; if (s === {{ $_swOffLc }}) return false; return ['1','true','t','on','sim','s','yes','y'].includes(s); })({!! $_cv !!})"
                                           @change="row['{{ $_ck }}'] = $event.target.checked ? {{ $_swOnJs }} : {{ $_swOffJs }}">
                                    <span class="mad-toggle-track"></span>
                                </label>
                                @elseif(! $_dfRead && $col->editable && $col->editMode === 'inline')
                                {{-- Inline edit: campo sempre visível --}}
                                @if($col->editType === 'money')
                                <input type="text" inputmode="decimal" class="mad-input mad-dg-inline-input"
                                       :value="madNumFmt({!! $_cv !!} || 0, {{ $col->editDecimals }})"
                                       @focus="madSelectOnFocus($event)"
                                       @blur="let v=madNumParse($event.target.value);$event.target.value=madNumFmt(v,{{ $col->editDecimals }});row['{{ $_ck }}']=v"
                                       style="text-align:{{ $col->align }};">
                                @elseif($col->editType === 'number')
                                <input type="number" class="mad-input mad-dg-inline-input"
                                       x-model="row['{{ $_ck }}']"
                                       step="{{ $col->editDecimals > 0 ? '0.'.str_repeat('0', $col->editDecimals - 1).'1' : '1' }}"
                                       style="text-align:{{ $col->align }};">
                                @else
                                <input type="text" class="mad-input mad-dg-inline-input"
                                       x-model="row['{{ $_ck }}']"
                                       style="text-align:{{ $col->align }};">
                                @endif
                                @elseif($_cSrvFmt)
                                {{-- Transform callable / token de mídia: HTML pronto do
                                     servidor (renderValue já escapou o que não é html).
                                     Linha do navegador não tem `__fmt` → span cru ao lado. --}}
                                <span x-html="{!! $_cHasFmt !!} ? row.__fmt[{!! $_cFmtKey !!}] : ''"
                                      x-show="!!{!! $_cHasFmt !!}"></span>
                                <span x-text="{!! $_cv !!}" x-show="!{!! $_cHasFmt !!}"></span>
                                @elseif($col->isBadge && !empty($col->badgeMap))
                                @php $badgeJson = json_encode($col->badgeMap); @endphp
                                <span x-html="(() => {
                                    let raw = {!! $_cv !!};
                                    let v = raw === true ? '1' : (raw === false ? '0' : (raw == null ? '' : String(raw)));
                                    let map = {{ $badgeJson }};
                                    let lv = v.trim().toLowerCase();
                                    let alias = ['1','t','true'].includes(lv) ? ['1','true'] : (['0','f','false'].includes(lv) ? ['0','false'] : []);
                                    let entry = map[v] ?? map[alias[0]] ?? map[alias[1]];
                                    if (!entry) return v;
                                    let variant = 'secondary', lbl = v;
                                    if (typeof entry === 'string' && entry.includes(':')) {
                                        let parts = entry.split(':'); variant = parts[0]; lbl = parts[1] || v;
                                    } else if (typeof entry === 'string') { variant = entry; }
                                    return '<span class=&quot;mad-badge mad-badge-' + variant + '&quot;>' + lbl + '</span>';
                                })()"></span>
                                @elseif($col->isMoney)
                                <span x-text="'{{ $col->moneyPrefix }} ' + madNumFmt({!! $_cv !!} || 0, {{ (int) ($_cDec ?? $col->moneyDecimals) }})"></span>
                                @elseif($col->isNumber)
                                {{-- attr `num=`/`number=` — faltava aqui e caía no valor cru --}}
                                <span x-text="madNumFmt({!! $_cv !!} || 0, {{ (int) ($_cDec ?? $col->numberDecimals) }})"></span>
                                @elseif($_cDateFmt !== null)
                                {{-- Campo de data do detalhe sem formato na coluna: o formato do campo (fórum #334). --}}
                                <span x-text="{!! \Mad\Grid\GridRenderHelpers::dateFormatJs($_cDateFmt, $_cv, $_cDateFmt) !!}"></span>
                                @elseif($_cNumDec !== null)
                                {{-- Campo numérico do detalhe sem formato na coluna: mesma casa do campo (fórum #291 r5). --}}
                                <span x-text="madNumFmt({!! $_cv !!} || 0, {{ (int) $_cNumDec }})"></span>
                                @elseif($col->isDate && $col->dateFormat)
                                {{-- Formato da coluna (H:i, l, d/m/Y…), igual à listagem; hora
                                     pura vale (fórum #294). Antes: sempre a data do navegador,
                                     "Invalid Date" na hora e o dia anterior a oeste de Greenwich. --}}
                                <span x-text="{!! \Mad\Grid\GridRenderHelpers::dateFormatJs($col->dateFormat, $_cv, $_cInFmt) !!}"></span>
                                @elseif($_cFmtJs !== '')
                                {{-- Token built-in do formatter-select (money/cpf/date-long/…):
                                     gêmeo JS do ValueFormatter, emitido inline. FALLBACK de
                                     propósito — os attrs de render-type acima vencem, igual
                                     ao GridColumn::renderValue. --}}
                                <span x-text="{!! $_cFmtJs !!}"></span>
                                @elseif($col->isHtml)
                                <span x-html="{!! $_cv !!}"></span>
                                @else
                                <span x-text="{!! $_cv !!}"></span>
                                @endif
@if(isset($_dfColWhen[$loop->index]))
                                </span>
@endif
                            </td>
                            @endforeach
@if($actionSide === 'right' && $_dfShowActions)
@php echo $_dfActionsCell; @endphp
@endif
                        </tr>
                    </template>
                </tbody>

                {{-- Empty state --}}
                <tbody x-show="rows.length === 0">
                    <tr>
                        <td colspan="{{ count(array_filter($_dfShowCol)) + ($_dfShowActions ? 1 : 0) }}" class="mad-dg-cell" style="text-align:center;padding:24px;color:var(--mad-muted-fg);">
                            <i data-lucide="inbox" style="width:24px;height:24px;margin-bottom:8px;opacity:.5;"></i>
                            <div>{{ mad_t('mad.ui.no_items_added') }}</div>
                        </td>
                    </tr>
                </tbody>

                {{-- Totals --}}
                @if($hasTotals)
                <tfoot class="mad-dg-foot" x-show="rows.length > 0">
                    <tr>
@if($actionSide === 'left' && $_dfShowActions)
                        <td></td>
@endif
                        @foreach($columns as $_dfColIdx => $col)
@continue(!$_dfShowCol[$_dfColIdx])
                        @php
                            // Agregado calculado no cliente por `_agg()` (madDetailForm) —
                            // as mesmas cinco funções da grid (computeTotals). O total é
                            // formatado com a MESMA precedência da célula (renderValue:
                            // money → number → transform built-in → número pt-BR):
                            // `transform="money" total="sum"` mostrava 9.990,00 na linha
                            // e 9.990 no rodapé, porque aqui só `money=` era conhecido e
                            // o resto caía num toLocaleString sem casas decimais.
                            //
                            // `total-formula` (fórum #199) vence o `total`: a conta sai
                            // das SOMAS dos campos — `{ctb} / {total} * 100` dá a
                            // contribuição da lista inteira, que somar ou tirar a média
                            // dos percentuais das linhas não dá. Mesmo formatador.
                            $_cv  = \Mad\Grid\GridRenderHelpers::rowValueJs($col->field, 'r');
                            $_hasFormula = $col->totalFormula !== '';
                            $_agg = $_hasFormula
                                ? '_aggFormula(' . (int) $_dfColIdx . ')'
                                : "_agg('{$col->totalFunc}', r => {$_cv})";
                            if (! $_hasFormula && $col->totalFunc === 'count') {
                                $_tot = 'rows.length';               // contagem: sem máscara de dinheiro
                            } elseif (! $_hasFormula && ! in_array($col->totalFunc, ['sum', 'avg', 'min', 'max'], true)) {
                                $_tot = '';
                            } elseif ($col->isMoney) {
                                $_tot = "'{$col->moneyPrefix} ' + madNumFmt({$_agg}, " . (int) ($_dfInheritDec($col) ?? $col->moneyDecimals) . ')';
                            } elseif ($col->isNumber) {
                                $_tot = "madNumFmt({$_agg}, " . (int) ($_dfInheritDec($col) ?? $col->numberDecimals) . ')';
                            } elseif ($col->builtinFormat !== '') {
                                $_tot = \Mad\Grid\GridRenderHelpers::builtinFormatJs($col->builtinFormat, $_agg, $_dfInheritDec($col));
                            } else {
                                $_tot = "madNumFmt({$_agg}, 2)";
                            }
                        @endphp
                        <td class="mad-dg-cell{{ $_dfColCls($_dfColIdx) }}" style="text-align:{{ $col->align }};font-weight:600;">
                            @if($_tot !== '')
                            <span x-text="{!! $_tot !!}"></span>
                            @endif
                        </td>
                        @endforeach
@if($actionSide === 'right' && $_dfShowActions)
                        <td></td>
@endif
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        {{-- Paginação client-side --}}
        <template x-if="perPage > 0 && totalPages > 1">
            <div class="mad-df-pagination">
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" :disabled="page <= 1" @click="goToPage(page - 1)">
                    <i data-lucide="chevron-left" style="width:14px;height:14px;"></i>
                </button>
                <span class="mad-df-page-info" x-text="page + ' / ' + totalPages"></span>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" :disabled="page >= totalPages" @click="goToPage(page + 1)">
                    <i data-lucide="chevron-right" style="width:14px;height:14px;"></i>
                </button>
            </div>
        </template>
    </div>

</div>
