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
    $selected      = $selected      ?? (isset($$name) ? (string)$$name : '');
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
    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $attrs = 'mad:model="' . $name . '" ' . $attrs;
    }
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

    // Carrega options (não carrega se tem depends-on — será carregado via AJAX quando o pai mudar)
    $options = [];
    if (\Mad\Database\QuerySource::isQuery($query)) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $query, $keyField, $display, $orderBy ?: null, $orderDir ?: 'asc'
            );
        } catch (\Throwable $e) {
            $options = [];
        }
    } elseif ($model && !$dependsOn) {
        try {
            $options = \Mad\Form\ModelOptionsLoader::items(
                $model,
                $keyField,
                $display,
                $orderBy ?: null, $orderDir ?: 'asc'
            );
        } catch (\Exception $e) {
            $options = [];
        }
    }

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
    <div style="{{ $wrapStyle }}">
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
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
