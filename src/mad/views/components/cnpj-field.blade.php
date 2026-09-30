@php
    /**
     * mad-cnpj-field — Campo de CNPJ com integração nativa à API do madbuilder.
     *
     * Busca o CNPJ via CNPJService (que internamente resolve CEP + cidade/estado)
     * e popula outros campos do form automaticamente segundo o mapa `fill-fields`.
     *
     * Props:
     *   name         string   Nome do campo (obrigatorio)
     *   label        string   Label
     *   value        string   Valor inicial (sobrescrito pelo MadRenderContext)
     *   fill-fields  array    Mapa [ 'form_field' => 'api_field', ... ]
     *                         ex: ['nome' => 'razao_social', 'fone' => 'ddd_telefone_1']
     *   auto         bool     Dispara busca no blur quando o CNPJ tem 14 caracteres
     *                         (default: true). Aceita o formato ALFANUMERICO da
     *                         Receita (julho/2026): 12 posicoes [0-9A-Z] + 2 digitos
     *                         verificadores — ex. UK.PVM.E1E/8HI9-96.
     *   full         bool     Retorna o payload completo do provedor em vez do basico (default: false)
     *   placeholder  string   Placeholder (default: '00.000.000/0000-00')
     *   hint         string   Texto de ajuda
     *   error        string   Mensagem de erro
     *   required     bool
     *   disabled     bool
     *   strip-mask   bool     Remove mascara no getData() — backend recebe so digitos
     *   attrs        string   Atributos HTML extras
     *   id           string   ID do elemento (default: $name)
     *
     * Campos derivados de localização (mesmo vocabulário do <mad-cep-field>):
     *   cidade, cidade_cod_ibge, uf, estado, estado_cod_ibge, rua — derivados do
     *   payload (basic: municipio/codigo_municipio_ibge/uf; full: estabelecimento).
     *
     * --- resolução automática de cidade/estado (opt-in) — idêntica ao CEP ---
     *   city-model / state-model            Model Eloquent — ativa a resolução
     *   city-match-column / state-match-column   default 'codigo_ibge'
     *   city-api-field                      default 'cidade_cod_ibge'
     *   state-api-field                     default 'estado_cod_ibge'
     *   city-key / state-key                default 'id'
     *   city-target / state-target          default 'cidade_id' / 'estado_id'
     *   city-state-fk                       FK do estado NO model de cidade
     *   city-scope-by-state                 bool — lookup da cidade restrito ao estado
     *   database                            override da conexão do model
     *   city-create / state-create          array|bool — auto-criar quando não existir
     *
     *   Semântica, transaction, pré-flight NOT NULL, dedupe tenant-safe e o
     *   fallback de projeto (MAD_CEP_AUTO_CREATE) são os MESMOS do
     *   <mad-cep-field> — ver a doc completa em cep-field.blade.php.
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $value       = $value       ?? '';
    $fillFields  = $fillFields  ?? [];
    $auto        = isset($auto) ? !empty($auto) : true;
    $full        = !empty($full);
    $placeholder = $placeholder ?? '00.000.000/0000-00';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $stripMask   = !empty($stripMask);
    $attrs       = $attrs       ?? '';

    // Resolucao automatica de cidade/estado — mesmos props/defaults do cep-field.
    $cityModel        = $cityModel        ?? '';
    $stateModel       = $stateModel       ?? '';
    $cityMatchColumn  = $cityMatchColumn  ?? 'codigo_ibge';
    $stateMatchColumn = $stateMatchColumn ?? 'codigo_ibge';
    $cityApiField     = $cityApiField     ?? 'cidade_cod_ibge';
    $stateApiField    = $stateApiField    ?? 'estado_cod_ibge';
    $cityKey          = $cityKey          ?? 'id';
    $stateKey         = $stateKey         ?? 'id';
    $cityTarget       = $cityTarget       ?? 'cidade_id';
    $stateTarget      = $stateTarget      ?? 'estado_id';
    $cityStateFk      = $cityStateFk      ?? '';
    $cityScopeByState = !empty($cityScopeByState);
    $database         = $database         ?? '';
    $cityCreate       = $cityCreate       ?? false;
    $stateCreate      = $stateCreate      ?? false;
    $cityCreateMap  = is_array($cityCreate)  ? $cityCreate  : ($cityCreate  ? ['nome' => 'cidade'] : []);
    $stateCreateMap = is_array($stateCreate) ? $stateCreate : ($stateCreate ? ['nome' => 'estado'] : []);

        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError    = !empty($error);
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';

    // mad:model + value (registro > prop `value`) — regra em \Mad\Support\MadFieldValue
    $attrs = \Mad\Support\MadFieldValue::mergeAttrs($attrs, (string)$name, $value);

    \Mad\Form\MadFormRegistry::register($name, 'input', [
        'label'     => strip_tags($label),
        'type'      => 'text',
        'required'  => $required,
        'mask'      => 'cnpj',
        'stripMask' => $stripMask,
    ]);

    // Config de resolucao automatica (so vai no token quando ha model declarado).
    $resolve = [];
    if (trim((string) $cityModel) !== '') {
        $resolve['city'] = [
            'model'          => (string) $cityModel,
            'match_col'      => (string) $cityMatchColumn,
            'api_field'      => (string) $cityApiField,
            'key'            => (string) $cityKey,
            'target'         => (string) $cityTarget,
            'state_fk'       => (string) $cityStateFk,
            'scope_by_state' => $cityScopeByState,
            'create'         => is_array($cityCreateMap) ? $cityCreateMap : [],
        ];
    }
    if (trim((string) $stateModel) !== '') {
        $resolve['state'] = [
            'model'     => (string) $stateModel,
            'match_col' => (string) $stateMatchColumn,
            'api_field' => (string) $stateApiField,
            'key'       => (string) $stateKey,
            'target'    => (string) $stateTarget,
            'create'    => is_array($stateCreateMap) ? $stateCreateMap : [],
        ];
    }
    if (trim((string) $database) !== '') {
        $resolve['database'] = (string) $database;
    }

    // Token AJAX criptografado. AES-256-GCM autenticado: o client nao pode
    // forjar model/coluna/mapa de criacao do lookup.
    $cnpjToken = \Mad\Http\MadStateCrypt::encryptFor('cnpj', [
        'fill_fields' => is_array($fillFields) ? $fillFields : [],
        'full'        => $full,
        'resolve'     => $resolve,
    ]);
    // SÓ json_encode — o {{ }} já faz o escape HTML (uma vez). htmlspecialchars
    // aqui dobrava o escape (&quot; -> &amp;quot;), o browser decodificava só um
    // nível e o Alpine via `&` solto no x-data -> "Unexpected token '&'".
    $xDataCfg = json_encode([
        'token' => $cnpjToken,
        'auto'  => (bool)$auto,
    ], JSON_UNESCAPED_UNICODE);
    $width    = $width ?? '';
    $maxWidth = $maxWidth ?? '';
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field mad-cnpj-field" x-data="madCnpjField({{ $xDataCfg }})" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group" style="display:flex;gap:0;align-items:stretch;">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            {{-- SEM inputmode="numeric": o CNPJ da Receita (julho/2026) tem
                 LETRAS nas 12 primeiras posicoes, e o teclado numerico do mobile
                 impediria de digitar um CNPJ valido. --}}
            autocomplete="off"
            maxlength="18"
            style="border-top-right-radius:0;border-bottom-right-radius:0;"
            @if($required) required @endif
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        <button
            type="button"
            class="mad-btn mad-btn-secondary mad-cnpj-search-btn"
            data-mad-api-trigger
            title="Buscar CNPJ"
            style="border-top-left-radius:0;border-bottom-left-radius:0;border-left:0;padding:0 12px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;"
            @if($disabled) disabled @endif
        >
            <i data-lucide="search" style="width:18px;height:18px;"></i>
        </button>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
