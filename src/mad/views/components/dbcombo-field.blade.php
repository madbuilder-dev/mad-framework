@php
    /**
     * mad-dbcombo-field — Select com busca ativa + auto-query do banco.
     * Combo de banco. Carrega options automaticamente via Eloquent/QuerySource.
     *
     * COMPONENTE UNIFICADO: <mad-dbselect-field> e um alias deste (mesmo
     * comportamento). Veja components/dbselect-field.blade.php.
     *
     * Props:
     *   name        string   Nome do campo
     *   label       string   Label do campo
     *   database    string   Conexão (padrão: main_database)
     *   model       string   Classe do modelo (ex: 'Cliente', 'Produto')
     *   key         string   Campo da PK (padrão: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} {sobrenome}'
     *   order-by    string   Campo para ordenar (padrão: igual a display)
     *   filters     array           Atalho: [['field','op','val'], ...] — vira WHERE no builder.
     *                                COMPATÍVEL com depends-on: é a forma de filtrar um combo
     *                                dependente (vale no 1º render e na recarga da cascata).
     *   query       Builder|null    Eloquent/Query Builder já filtrado (:query="$that->metodo()").
     *                                Tem prioridade sobre model+filters; dispensa model/database
     *                                (vêm do builder). Preserva soft-delete via global scopes.
     *                                INCOMPATÍVEL com depends-on (cascata serializa a config no
     *                                token; Builder não serializa) → lança erro se combinados.
     *   selected    string   Valor pré-selecionado
     *   placeholder string   Opção vazia (padrão: 'Selecione...')
     *   no-empty    bool     Sem a opção vazia: o campo já abre no primeiro registro
     *   no-search   bool     Lista sem a caixa de busca
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro (PHP-driven)
     *   required    bool
     *   empty-as    string   Em branco grava: 'null' (padrão), 'zero' ou 'empty' ('' — comportamento antigo)
     *   disabled    bool
     *   attrs       string   Attrs extras: 'x-model="clienteId" @change="onCliente()"'
     *   depends-on  string   Campo pai para cascata automática (ex: 'familia_produto_id')
     *   depends-column string  Coluna do model filho a filtrar (default: igual a depends-on)
     *   id          string   ID do elemento (padrão: igual a name)
     *
     *   no-results-create-action        string  'Classe::metodo' — abre form em drawer/modal
     *   no-results-create-label         string  Texto do botao (padrao: 'Cadastrar novo')
     *   no-results-create-icon          string  Icone Lucide (padrao: 'plus')
     *   no-results-create-class         string  Classe CSS do botao (padrao: 'mad-btn mad-btn-primary mad-btn-sm')
     *   no-results-quick-register-action string 'Classe::metodoEstatico' — cadastra inline
     *   no-results-quick-register-label  string  Texto do botao (padrao: 'Adicionar')
     *   no-results-quick-register-icon   string  Icone Lucide (padrao: 'check')
     *   no-results-quick-register-class  string  Classe CSS do botao (padrao: 'mad-btn mad-btn-success mad-btn-sm')
     *   no-results-message              string  Mensagem quando nao ha resultados
     *
     *   create          string  'Classe::metodo' (ou 'Classe') — botão "+" ao lado do combo que
     *                           abre o cadastro; o returnToCombo() do form alvo devolve a option
     *                           nova selecionada (mesmo contrato do no-results-create-action)
     *   create-label    string  Dica/nome acessível do botão (padrao: 'Cadastrar novo')
     *   create-icon     string  Icone Lucide (padrao: 'plus')
     *   create-params   array   Parametros fixos da abertura (:create-params="['origem' => 'x']")
     */

    $name          = $name          ?? '';
    $label         = $label         ?? '';
    $database      = $database      ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $model         = $model         ?? '';
    $keyField      = $key           ?? 'id';
    $display       = $display       ?? 'nome';
    $orderBy       = $orderBy       ?? '';
    // Direção do `order-by`. O painel do builder já escrevia `order="desc"` e
    // nada acontecia: as options saíam sempre ASC. Valor fora do par cai em
    // asc no `itemsFromQuery` / no serviço de busca, que normalizam.
    $orderDir      = $order         ?? '';
    $filters       = $filters       ?? [];
    $query         = $query         ?? null;   // Eloquent/Query Builder (novo padrão)
    $autoFill      = $autoFill      ?? [];      // <fill> filhos → auto-fill ao selecionar

    // Largura — width / max-width (aceita calc(), %, px, ...) aplicados ao
    // wrapper .mad-field. Espelha o input-field e o preview do builder.
    $width    = $width    ?? '';
    $maxWidth = $maxWidth ?? '';
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);

    // Resolve um valor de CAMINHO-DE-RELACAO ('{estado->pais_id}' ou 'estado->pais_id')
    // a partir do record original do fill() — usado p/ pre-popular combos de cascata
    // na EDICAO: o FK do pai (ex.: pais_id) so existe via relacao, nao como coluna do
    // model editado. Retorna null se: nao for caminho de relacao / sem record / sem valor.
    $__madRelValue = static function (string $relKey) {
        $relKey = trim($relKey);
        if ($relKey !== '' && $relKey[0] === '{' && substr($relKey, -1) === '}') {
            $relKey = substr($relKey, 1, -1);            // tira { }
        }
        if (strpos($relKey, '->') === false) {
            return null;                                  // nao e caminho de relacao
        }
        $__form = \Mad\Component\MadRenderContext::getForm();
        $__rec  = $__form ? $__form->getSourceRecord() : null;
        if (!$__rec) {
            return null;
        }
        $__cur = $__rec;
        foreach (explode('->', $relKey) as $__part) {     // traversa relacao (lazy-load)
            $__part = trim($__part);
            if (is_object($__cur)) {
                try { $__cur = $__cur->{$__part} ?? null; } catch (\Throwable $e) { return null; }
            } elseif (is_array($__cur)) {
                $__cur = $__cur[$__part] ?? null;
            } else {
                return null;
            }
            if ($__cur === null) {
                return null;
            }
        }
        return $__cur;
    };

    $selected      = $selected      ?? null;
    if ($selected === null && $name) {
        // Resolve valor atual do MadForm/MadComponent (igual ao select-field)
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (is_array($_ctx) && array_key_exists($name, $_ctx)) {
            $selected = (string) $_ctx[$name];
        } elseif (isset($$name)) {
            $selected = (string) $$name;
        }
    }
    // Cascata/edicao: name como caminho de relacao ('{estado->pais_id}') nao existe
    // no contexto (nao e coluna) — deriva o valor do record original.
    if (($selected === null || $selected === '') && $name && strpos($name, '->') !== false) {
        $__sv = $__madRelValue($name);
        if ($__sv !== null) {
            $selected = (string) $__sv;
        }
    }
    $selected = (string) ($selected ?? '');
    $placeholder   = $placeholder   ?? 'Selecione...';
    // `no-empty`: a lista sai sem a opção em branco e o navegador elege o
    // primeiro registro — o `setDefaultOption(false)` de quem vem do 4.0.
    $noEmpty       = !empty($noEmpty ?? false);
    $noSearch      = !empty($noSearch ?? false);
    $hint          = $hint          ?? '';
    $error         = $error         ?? '';
    $required      = !empty($required);
    $disabled      = !empty($disabled);
    $_isHidden     = $name && \Mad\Component\MadRenderContext::isHidden($name);
    $_isReadonly   = $name && \Mad\Component\MadRenderContext::isReadonly($name);
    $dependsOn     = $dependsOn     ?? '';     // nome do campo pai
    $dependsColumn = $dependsColumn ?? '';     // coluna do model (default: dependsOn)
    if ($dependsOn && !$dependsColumn) $dependsColumn = $dependsOn;
    $attrs         = $attrs         ?? '';
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';

    // Em branco grava NULL (a chave de outra tabela nunca é ''): coluna
    // inteira recusava o '' e o salvar quebrava. empty-as="empty" = antigo.
    $emptyAs = strtolower(trim((string) ($emptyAs ?? 'null'))) ?: 'null';
    \Mad\Form\MadFormRegistry::register($name, 'select', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs,
    ]);

    // :filters → Query Builder interno, EXCETO com depends-on: a cascata serializa
    // a config no token (Builder não serializa). Sem cascata, :filters vira $query.
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model && !$dependsOn) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
    }

    // F5: :filters não-depends-on já viraram $query (builder) acima. 100% Query Builder.

    // Carrega options. Sem depends-on: lista completa.
    // Com depends-on E pai ja tem valor (edicao/onEdit + form->set): filtra pelo pai
    // inline pra evitar combo vazio no primeiro render — handler change so dispara
    // em interacao do usuario, nao em hidratacao server-side.
    // :query (Eloquent/Query Builder) é incompatível com depends-on: a cascata
    // serializa a config num token assinado (ver bloco $dependsToken abaixo) e
    // um Builder carrega conexão PDO + closures → não serializa. Falha cedo e
    // claro em vez de gerar token quebrado.
    if (\Mad\Database\QuerySource::isQuery($query) && $dependsOn) {
        throw new \InvalidArgumentException(
            "<mad-dbcombo-field name=\"{$name}\">: a prop :query não suporta depends-on (cascata). "
            . "Use :filters (ou model) com depends-on, ou remova depends-on para usar :query."
        );
    }

    $options = [];
    // Falha ao carregar (model que não resolve, coluna de display/order-by que
    // não existe, SQL inválido, conexão errada): o combo continua vazio para o
    // usuário final, mas o motivo vai para o log e, com APP_DEBUG, aparece no
    // próprio campo. Antes o catch engolia tudo sem rastro (fórum #41).
    $__optError = null;
    $__optCtx   = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy,
    ];
    $__optMissing = [];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        // Novo padrão: builder pronto fornece o WHERE; soft-delete preservado.
        try {
            $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query,
                $keyField,
                $display,
                $orderBy ?: null, $orderDir ?: 'asc',
                $__optMissing
            );
        } catch (\Throwable $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbcombo-field', $__optCtx);
        }
    } elseif ($model) {
        $parentValue = '';
        if ($dependsOn) {
            $ctx = \Mad\Component\MadRenderContext::current();
            $parentValue = isset($ctx[$dependsOn]) ? (string)$ctx[$dependsOn] : '';
            // Cascata/edicao: dependsOn como caminho de relacao ('{estado->pais_id}')
            // ausente do contexto — deriva do record original p/ carregar as options
            // do filho ja filtradas no 1o render (senao o combo filho vem vazio e o
            // valor salvo nao tem <option> p/ pre-selecionar).
            if ($parentValue === '' && strpos($dependsOn, '->') !== false) {
                $__pv = $__madRelValue($dependsOn);
                if ($__pv !== null) {
                    $parentValue = (string) $__pv;
                }
            }
        }
        if (!$dependsOn || $parentValue !== '') {
            try {
                $__m  = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                $__cq = $__m::query();
                // Regra de carregamento CONVIVE com a cascata: sem depends-on os
                // :filters já viraram $query lá em cima; COM depends-on eles não
                // podem virar Builder (o throw acima), então são aplicados aqui.
                // Sem isto o combo dependente listava tudo — o filtro entrava no
                // Blade e não filtrava nada, sem erro nem log.
                if ($dependsOn && !empty($filters)) {
                    \Mad\Database\QuerySource::applyArrayFilters($__cq, $filters);
                }
                if ($dependsOn && $parentValue !== '') {
                    $__cq->where($dependsColumn, '=', $parentValue);
                }
                $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                    $__cq, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
                );
            } catch (\Throwable $e) {
                $options = [];
                $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbcombo-field', $__optCtx);
            }
        }
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbcombo-field', $__optCtx);

    // Valor que o campo já tem e a lista não mostra (cadastro de outra unidade
    // ou empresa, usuário que a pessoa não enxerga, filtro do campo, registro
    // inativo ou excluído, lista que não carregou): a opção dele fica no
    // campo, selecionada, com um rótulo neutro. Sem ela o campo abria em
    // "Selecione...", ia vazio na requisição e o Salvar apagava o valor sem
    // ninguém ter mexido nele — ver \Mad\Form\OutsideOption.
    $__outside = \Mad\Form\OutsideOption::applies($selected, $options);

    // Para depends-on, criptografa toda a config da query num token assinado.
    // O cliente nao ve nem manipula model/database/display/column — so o token
    // opaco. O servidor decripta, valida e executa a query com a config segura.
    // (F5: serializa só model/key/display/column — builder-native, nada de filtro serializado;
    // o filtro depends-on é aplicado server-side no MadDbComboService.)
    // `filters` entra no token (não no DOM) para que o recarregamento da cascata
    // aplique a MESMA regra de carregamento do 1º render — a regra é config de
    // servidor, o cliente continua vendo só o token opaco.
    $dependsToken = '';
    if ($dependsOn) {
        // Finalidade 'db-combo': só o MadDbComboService aceita (colado no
        // db-search, listava o model sem a regra de carregamento do combo).
        $dependsToken = \Mad\Http\MadStateCrypt::encryptFor('db-combo', [
            'database' => $database,
            'model'    => $model,
            'key'      => $keyField,
            'display'  => $display,
            'order'    => $orderBy,
            'order_dir' => $orderDir,
            'column'   => $dependsColumn,
            'filters'  => $filters,
        ]);
    }

    // Auto-fill (<fill>): cifra a config (model/key/db + query_sql do filtro do
    // combo + mapa de fills) num token assinado. O cliente só manda {token,value};
    // o MadAutoFillService re-carrega o registro DENTRO do filtro e resolve.
    $autoFillToken = '';
    if (!empty($autoFill)) {
        $__afSql = '';
        $__afBindings = [];
        if (\Mad\Database\QuerySource::isQuery($query)) {
            try { [$__afSql, $__afBindings] = \Mad\Database\QuerySource::compileSql($query); }
            catch (\Throwable $e) { $__afSql = ''; $__afBindings = []; }
        } elseif ($model) {
            try {
                $__afm  = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
                $__afqb = $__afm::query();
                if (!empty($filters)) {
                    \Mad\Database\QuerySource::applyArrayFilters($__afqb, $filters);
                }
                [$__afSql, $__afBindings] = \Mad\Database\QuerySource::compileSql($__afqb);
            } catch (\Throwable $e) { $__afSql = ''; $__afBindings = []; }
        }
        $autoFillToken = \Mad\Http\MadStateCrypt::encryptFor('auto-fill', [
            'database'       => $database,
            'model'          => $model,
            'key'            => $keyField,
            'query_sql'      => $__afSql,
            'query_bindings' => $__afBindings,
            'fills'          => $autoFill,
        ]);
    }

    // No-results: create button + quick register (ver MadNoResultsHelper)
    $noResultsAttrs = \Mad\Form\MadNoResultsHelper::buildAttrs([
        'name'         => $name,
        'model'        => $model,
        'database'     => $database,
        'key'          => $keyField,
        'display'      => $display,
        'createAction' => $noResultsCreateAction        ?? '',
        'createLabel'  => $noResultsCreateLabel         ?? 'Cadastrar novo',
        'createIcon'   => $noResultsCreateIcon          ?? 'plus',
        'createClass'  => $noResultsCreateClass         ?? 'mad-btn mad-btn-primary mad-btn-sm',
        'quickAction'  => $noResultsQuickRegisterAction ?? '',
        'quickLabel'   => $noResultsQuickRegisterLabel  ?? 'Adicionar',
        'quickIcon'    => $noResultsQuickRegisterIcon   ?? 'check',
        'quickClass'   => $noResultsQuickRegisterClass  ?? 'mad-btn mad-btn-success mad-btn-sm',
        'quickFields'  => $noResultsQuickFields         ?? [],
        'message'      => $noResultsMessage             ?? '',
    ]);

    // "+" ao lado do combo (`create`): cadastra e volta selecionado. Só string —
    // `$create` de outro escopo (o array do <mad-seek>) não liga o botão.
    $_createBtn = \Mad\Form\MadNoResultsHelper::createButton([
        'createAction' => is_string($create ?? null) ? $create : '',
        'createLabel'  => is_string($createLabel ?? null) ? $createLabel : '',
        'createIcon'   => is_string($createIcon ?? null) ? $createIcon : '',
        'params'       => is_array($createParams ?? null) ? $createParams : [],
        'name'         => $name,
        'model'        => $model,
        'database'     => $database,
        'key'          => $keyField,
        'display'      => $display,
    ], $disabled || $_isReadonly);
@endphp
<div class="mad-field{{ $_isHidden ? ' mad-hidden' : '' }}{{ $_isReadonly ? ' mad-readonly' : '' }}" @if($name) data-mad-field="{{ $name }}" @endif @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    @if($_createBtn !== '')<div class="mad-combo-create-row" style="display:flex;gap:6px;align-items:flex-start;min-width:0"><div style="flex:1;min-width:0">@endif
    <select
        id="{{ $id }}"
        name="{{ $name }}"
        class="mad-select{{ $hasError ? ' mad-input-error' : '' }}{{ $_isReadonly ? ' mad-readonly-select' : '' }}"
        data-mad-select
        @if($noSearch) data-mad-nosearch @endif
        @if($required) required @endif
        @if($disabled) disabled @endif
        @if($dependsOn)
            data-mad-depends="{{ $dependsOn }}"
            data-mad-dep-token="{{ $dependsToken }}"
        @endif
        @if($autoFillToken)
            data-mad-autofill-token="{{ $autoFillToken }}"
            data-mad-autofill-fields="{{ implode(',', array_column($autoFill, 'field')) }}"
        @endif
        {!! $noResultsAttrs !!}
        {!! $attrs !!}
    >
        @if(!$noEmpty)
            <option value="">{{ $placeholder }}</option>
        @endif
        @if($__optError !== null)
            <option value="" disabled data-mad-options-error>{{ $__optError }}</option>
        @endif
        @foreach($options as $optKey => $optLabel)
            <option value="{{ $optKey }}"
                    @if((string)$optKey === (string)$selected) selected @endif>
                {{ $optLabel }}
            </option>
        @endforeach
        @if($__outside)
            <option value="{{ $selected }}" selected {!! \Mad\Form\OutsideOption::ATTRS !!}>{{ \Mad\Form\OutsideOption::label() }}</option>
        @endif
    </select>
    @if($_createBtn !== '')</div>{!! $_createBtn !!}</div>@endif
    @include('components.partials.options-error', ['optionsError' => $__optError])
    {{-- Slot de erro SEMPRE presente: MadResponse::fieldError() escreve em
         [data-field-error="campo"]. Sem o elemento no DOM a mensagem some. --}}
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
