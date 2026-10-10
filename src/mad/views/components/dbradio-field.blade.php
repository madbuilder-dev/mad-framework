@php
    /**
     * mad-dbradio-field — Radio group com auto-load do banco de dados.
     *
     * Combina o carregamento de options do dbcombo-field com a renderização do radio-field.
     *
     * Props:
     *   name        string   Nome do campo
     *   label       string   Label do campo
     *   database    string   Conexão (padrão: main_database)
     *   model       string   Classe do modelo (ex: 'TipoProduto', 'Status')
     *   key         string   Campo da PK (padrão: 'id')
     *   display     string   Campo a exibir; suporta template '{nome} {sobrenome}'
     *   order-by    string   Campo para ordenar (padrão: igual a display)
     *   query       Builder|null   Eloquent/Query Builder para filtrar
     *   filters     array           Atalho: [['field','op','val'], ...] — aplicado no builder
     *   selected    string   Valor pré-selecionado
     *   inline      bool     Layout horizontal (padrão: vertical)
     *   depends-on  string   Campo pai para cascata automática
     *   depends-column string  Coluna do model filho a filtrar (default: igual a depends-on)
     *   hint        string   Texto de ajuda
     *   error       string   Mensagem de erro
     *   required    bool
     *   empty-as    string   Em branco grava: 'null' (padrão), 'zero' ou 'empty' ('' — comportamento antigo)
     *   disabled    bool
     *   attrs       string   Attrs extras
     *   id          string   ID do elemento (padrão: igual a name)
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
    // Valor atual do campo: o do formulário (MadForm/MadComponent), como no
    // dbcombo-field. Lia só uma variável da view com o nome do campo, que a
    // tag não recebe: o registro abria sem nenhuma opção marcada.
    $selected      = $selected      ?? null;
    if ($selected === null && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (is_array($_ctx) && array_key_exists($name, $_ctx)) {
            $selected = $_ctx[$name];
        } elseif (isset($$name)) {
            $selected = $$name;
        }
    }
    $selected      = is_scalar($selected) ? (string) $selected : '';
    // "Valor padrão" do Studio: só quando nem `selected` nem o registro trouxeram valor.
    $selected      = \Mad\Support\MadFieldValue::withDefault((string) $name, $selected, $default ?? null);
    $inline        = !empty($inline);
    $hint          = $hint          ?? '';
    $error         = $error         ?? '';
    $required      = !empty($required);
    $disabled      = !empty($disabled);
    $dependsOn     = $dependsOn     ?? '';
    $dependsColumn = $dependsColumn ?? '';
    if ($dependsOn && !$dependsColumn) $dependsColumn = $dependsOn;
    $width         = $width         ?? '';
    $maxWidth      = $maxWidth      ?? '';
    $attrs         = $attrs         ?? '';
    // Quem liga o campo ao formulário é o GRUPO (como no radio-field): o valor
    // enviado é o do rádio marcado, ou vazio. Com o `mad:model` em cada rádio o
    // MadWire percorria todos e ficava com o valor do ÚLTIMO, marcado ou não —
    // todo Salvar gravava a última opção da lista.
    $__groupModel  = $name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false;
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError      = !empty($error);
    $reqStar       = $required ? ' <span class="mad-required">*</span>' : '';

    // Em branco grava NULL (a chave de outra tabela nunca é ''): coluna
    // inteira recusava o '' e o salvar quebrava. empty-as="empty" = antigo.
    $emptyAs = strtolower(trim((string) ($emptyAs ?? 'null'))) ?: 'null';
    \Mad\Form\MadFormRegistry::register($name, 'radio', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs,
    ]);

    // :filters → Query Builder interno (builder-native), EXCETO com depends-on
    // (cascata carrega via AJAX por data-attrs; sem cascata vira $query).
    if (!\Mad\Database\QuerySource::isQuery($query) && !empty($filters) && $model && !$dependsOn) {
        $__m   = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $query = $__m::query();
        \Mad\Database\QuerySource::applyArrayFilters($query, $filters);
    }

    // F5: :filters não-depends-on já viraram $query (builder) acima. 100% Query Builder.

    // :query (Builder) é incompatível com depends-on (cascata serializa a config
    // no token; Builder não serializa). Falha clara, igual ao dbcombo-field.
    if (\Mad\Database\QuerySource::isQuery($query) && $dependsOn) {
        throw new \InvalidArgumentException(
            "<mad-dbradio-field name=\"{$name}\">: a prop :query não suporta depends-on (cascata). "
            . "Use :criteria/:filters com depends-on, ou remova depends-on para usar :query."
        );
    }

    // Falha ao carregar: o campo continua vazio para o usuário final, o motivo
    // vai para o log e, com APP_DEBUG, aparece no próprio campo (fórum #41).
    $__optError   = null;
    $__optMissing = [];
    $__optCtx     = [
        'field'    => $name,
        'model'    => \Mad\Form\OptionsLoadError::sourceOf($query, (string) $model),
        'database' => $database,
        'display'  => $display,
        'order_by' => $orderBy,
    ];
    // Carrega options (não carrega se tem depends-on — será carregado via AJAX quando o pai mudar)
    // Lista posta pelo código (`$this->form->setItems()`, num On Change ou no
    // mount/onEdit) vence a do Model: é a que o reload_radio mostrou. Sem isto qualquer
    // redesenho da tela voltava a lista inteira, sem erro (fw#228; o
    // <mad-dbcombo-field> já fazia assim, fw#139).
    $__codeItems = \Mad\Support\MadItems::fromForm((string) $name);
    $options = [];
    if ($__codeItems !== null) {
        $options = \Mad\Support\MadItems::normalize($__codeItems);
    } elseif (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Throwable $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbradio-field', $__optCtx);
        }
    } elseif ($model && !$dependsOn) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::items(
                $model,
                $keyField,
                $display,
                $orderBy ?: null, $orderDir ?: 'asc', $__optMissing
            );
        } catch (\Exception $e) {
            $options = [];
            $__optError = \Mad\Form\OptionsLoadError::handle($e, 'mad-dbradio-field', $__optCtx);
        }
    }
    $__optError ??= \Mad\Form\OptionsLoadError::handleMissing($__optMissing, 'mad-dbradio-field', $__optCtx);

    // Valor que o campo já tem e a lista não mostra (cadastro de outra unidade
    // ou empresa, usuário que a pessoa não enxerga, filtro do campo, registro
    // inativo ou excluído, lista que não carregou): ele fica no campo, num
    // rádio próprio, marcado e com um rótulo neutro. Sem ele nenhuma opção
    // abria marcada e o Salvar trocava o valor — ver \Mad\Form\OutsideOption.
    $__outside = \Mad\Form\OutsideOption::applies($selected, $options);

    $wrapStyle = $inline
        ? 'display:flex;flex-direction:row;flex-wrap:wrap;gap:16px;'
        : 'display:flex;flex-direction:column;gap:8px;';
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}"@endif
    @if($dependsOn)
        data-mad-depends="{{ $dependsOn }}"
        data-mad-dep-database="{{ $database }}"
        data-mad-dep-model="{{ $model }}"
        data-mad-dep-key="{{ $keyField }}"
        data-mad-dep-display="{{ $display }}"
        data-mad-dep-order="{{ $orderBy }}"
        data-mad-dep-column="{{ $dependsColumn }}"
        data-mad-dep-type="radio"
    @endif
>
    @if($label)
        <div class="mad-label">{!! $label !!}{!! $reqStar !!}</div>
    @endif
    <div style="{{ $wrapStyle }}"
        @if($__groupModel) data-mad-radio-group="{{ $name }}" data-mad-model="{{ $name }}" @endif>
        @foreach($options as $optKey => $optLabel)
            @php
                $oId = $name . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $optKey);
            @endphp
            <label class="mad-radio-wrap" for="{{ $oId }}">
                <input
                    type="radio"
                    id="{{ $oId }}"
                    name="{{ $name }}"
                    value="{{ $optKey }}"
                    class="mad-radio"
                    @if((string)$optKey === (string)$selected) checked @endif
                    @if($disabled) disabled @endif
                    {!! $attrs !!}
                >
                <span class="mad-radio-circle"></span>
                <span class="mad-radio-label">{{ $optLabel }}</span>
            </label>
        @endforeach
        @if($__outside)
            <label class="mad-radio-wrap" for="{{ $name }}__outside">
                <input
                    type="radio"
                    id="{{ $name }}__outside"
                    name="{{ $name }}"
                    value="{{ $selected }}"
                    class="mad-radio"
                    checked
                    {!! \Mad\Form\OutsideOption::ATTRS !!}
                    @if($disabled) disabled @endif
                    {!! $attrs !!}
                >
                <span class="mad-radio-circle"></span>
                <span class="mad-radio-label">{{ \Mad\Form\OutsideOption::label() }}</span>
            </label>
        @endif
    </div>
    @include('components.partials.options-error', ['optionsError' => $__optError])
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
