@php
    /**
     * mad-cep-field — Campo de CEP com integração nativa à API do madbuilder.
     *
     * Busca o CEP direto na API do madbuilder (MadCepService::onSearch) e popula
     * outros campos do form automaticamente segundo o mapa `fill-fields`.
     *
     * Campos da API disponíveis em fill-fields:
     *   bairro, cep, cidade, cidade_cod_ibge, estado, estado_cod_ibge,
     *   logradouro, tipo_logradouro, uf, rua (= tipo_logradouro + logradouro)
     *
     * Resolução automática de cidade/estado (opt-in — "mágica"):
     *   Declarando `city-model` e/ou `state-model`, o serviço busca no banco o
     *   registro correspondente (por padrão casando `codigo_ibge` do model com o
     *   `cidade_cod_ibge` / `estado_cod_ibge` da API) e injeta o ID resolvido nos
     *   campos `cidade_id` / `estado_id` da resposta.
     *
     *   OBRIGATÓRIO: declarar city-model/state-model NÃO basta. O ID resolvido só
     *   chega ao form se o campo-alvo (`cidade_id`/`estado_id`, ou o city-target/
     *   state-target customizado) TAMBÉM estiver mapeado em `fill-fields`. Sem isso
     *   o ID é descartado silenciosamente e o combo fica vazio. No exemplo abaixo são
     *   as duas últimas linhas do fill-fields que ligam a resolução aos combos
     *   (`<mad-dbcombo-field name="cidade_id" depends-on="estado_id">`).
     *
     *   ex:
     *     <mad-cep-field name="cep"
     *         city-model="Cidade" state-model="Estado"
     *         :fill-fields="[
     *             'endereco'  => 'rua',
     *             'bairro'    => 'bairro',
     *             'estado_id' => 'estado_id',   // liga o estado resolvido ao combo
     *             'cidade_id' => 'cidade_id',   // liga a cidade resolvida ao combo
     *         ]" />
     *
     * Props:
     *   name           string  Nome do campo (obrigatorio)
     *   label          string  Label
     *   value          string  Valor inicial (sobrescrito pelo MadRenderContext)
     *   fill-fields    array   Mapa [ 'form_field' => 'api_field', ... ]
     *                          ex: ['endereco' => 'rua', 'bairro' => 'bairro', 'uf' => 'uf']
     *   auto           bool    Dispara busca no blur quando CEP tem 8 digitos (default: true)
     *   placeholder    string  Placeholder (default: '00000-000')
     *   hint           string  Texto de ajuda
     *   error          string  Mensagem de erro
     *   required       bool
     *   disabled       bool
     *   strip-mask     bool    Remove mascara no getData() — backend recebe so digitos
     *   attrs          string  Atributos HTML extras
     *   id             string  ID do elemento (default: $name)
     *
     *   --- resolução automática (opt-in) ---
     *   city-model         string  Model Eloquent da cidade — ativa a resolução. ex: "Cidade"
     *   state-model        string  Model Eloquent do estado — ativa a resolução. ex: "Estado"
     *   city-match-column  string  Coluna do model casada com a API (default: 'codigo_ibge')
     *   state-match-column string  Coluna do model casada com a API (default: 'codigo_ibge')
     *   city-api-field     string  Campo da API usado no match (default: 'cidade_cod_ibge')
     *   state-api-field    string  Campo da API usado no match (default: 'estado_cod_ibge')
     *   city-key           string  Coluna do model devolvida como ID (default: 'id')
     *   state-key          string  Coluna do model devolvida como ID (default: 'id')
     *   city-target        string  Campo injetado na resposta com o ID da cidade (default: 'cidade_id')
     *   state-target       string  Campo injetado na resposta com o ID do estado (default: 'estado_id')
     *   city-state-fk      string  Coluna FK do estado NO model de cidade — preenche o estado a
     *                              partir da cidade quando state-model não é informado. ex: 'estado_id'
     *   city-scope-by-state bool   Restringe o lookup da cidade ao estado resolvido
     *                              (WHERE city-state-fk = estado_id). Essencial quando
     *                              city-match-column é 'nome' — o Brasil tem dezenas de
     *                              cidades homônimas em estados diferentes. Requer
     *                              city-state-fk + state-model. Default: false.
     *   database           string  Override da conexão do lookup/criação (default: a
     *                              conexão declarada no próprio model Eloquent)
     *
     *   --- auto-criar quando não existir na base (opt-in) ---
     *   city-create   array|bool  Cria a cidade se o lookup não achar. Default: false (read-only).
     *                             array = mapa [ modelColumn => apiField ] (recomendado — você define
     *                             as colunas conforme seu schema). bool true = mapa mínimo ['nome'=>'cidade'].
     *                             A coluna de match (codigo_ibge) e a FK do estado (city-state-fk) são
     *                             setadas automaticamente no registro novo.
     *   state-create  array|bool  Idem para o estado. array = [ modelColumn => apiField ];
     *                             true = ['nome'=>'estado']. match (codigo_ibge) setado automaticamente.
     *
     *   ATENÇÃO: o registro novo é inserido APENAS com as colunas do mapa de criação
     *   + a coluna de match (codigo_ibge) + a FK do estado (via city-state-fk). Antes
     *   do insert roda um PRÉ-FLIGHT de schema: coluna NOT NULL sem default fora do
     *   mapa (ex: estado.sigla, flag ativo) → o insert NÃO é tentado e o motivo sai em
     *   Log::warning('mad.location.create_missing_required', {columns}) + no campo
     *   `_resolve_errors` da resposta. Estado+cidade criam dentro de TRANSACTION
     *   (falha na cidade não deixa estado órfão; conexões diferentes não são 2PC).
     *   O preenchimento do endereço nunca quebra por causa da resolução.
     *   Dedupe: registro soft-deleted com o mesmo match é REVIVIDO (deleted_at e
     *   deleted_by* limpos) em vez de duplicado; linha existente FORA do escopo
     *   (outro tenant/unit) NÃO é reaproveitada — loga exists_out_of_scope e cria
     *   no escopo atual. Recomenda-se índice UNIQUE em codigo_ibge (por tenant,
     *   quando a tabela é tenant-scoped).
     *
     *   Fallback de projeto: com MAD_CEP_AUTO_CREATE=true (config mad.cep.auto_create)
     *   e SEM city-create/state-create explícito, o mapa de criação é montado por
     *   introspecção do schema (nome/sigla/uf reconhecíveis). Mapa explícito sempre
     *   vence. A flag sozinha não faz nada sem city-model/state-model declarado.
     *
     *   ex (auto-criar com colunas explícitas):
     *     <mad-cep-field name="cep"
     *         city-model="Cidade"  state-model="Estado"  city-state-fk="estado_id"
     *         :state-create="['nome' => 'estado', 'sigla' => 'uf']"
     *         :city-create="['nome' => 'cidade']"
     *         :fill-fields="['estado_id' => 'estado_id', 'cidade_id' => 'cidade_id']" />
     */

    $name        = $name        ?? '';
    $label       = $label       ?? '';
    $value       = $value       ?? '';
    $fillFields  = $fillFields  ?? [];
    $auto        = isset($auto) ? !empty($auto) : true;
    $placeholder = $placeholder ?? '00000-000';
    $hint        = $hint        ?? '';
    $error       = $error       ?? '';
    $required    = !empty($required);
    $disabled    = !empty($disabled);
    $stripMask   = !empty($stripMask);
    $attrs       = $attrs       ?? '';

    // Resolucao automatica de cidade/estado (opt-in via city-model / state-model).
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

    // Auto-criar quando nao existir na base (opt-in). Aceita:
    //   array  -> mapa [ modelColumn => apiField ] (recomendado, controla colunas)
    //   true   -> usa mapa minimo padrao (nome <- cidade/estado)
    //   false  -> read-only (default)
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
        'mask'      => 'cep',
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

    // Token AJAX criptografado (carrega fill_fields + resolve para o endpoint).
    // AES-256-GCM autenticado: o client nao pode forjar model/coluna do lookup.
    $cepToken = \Mad\Http\MadStateCrypt::encryptFor('cep', [
        'fill_fields' => is_array($fillFields) ? $fillFields : [],
        'resolve'     => $resolve,
    ]);
    // SÓ json_encode — o {{ }} já faz o escape HTML (uma vez). htmlspecialchars
    // aqui dobrava o escape (&quot; -> &amp;quot;), o browser decodificava só um
    // nível e o Alpine via `&` solto no x-data -> "Unexpected token '&'".
    $xDataCfg = json_encode([
        'token' => $cepToken,
        'auto'  => (bool)$auto,
    ], JSON_UNESCAPED_UNICODE);
    $width    = $width ?? '';
    $maxWidth = $maxWidth ?? '';
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field mad-cep-field" x-data="madCepField({{ $xDataCfg }})" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
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
            inputmode="numeric"
            autocomplete="off"
            maxlength="9"
            style="border-top-right-radius:0;border-bottom-right-radius:0;"
            @if($required) required @endif
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        <button
            type="button"
            class="mad-btn mad-btn-secondary mad-cep-search-btn"
            data-mad-api-trigger
            title="Buscar CEP"
            style="border-top-left-radius:0;border-bottom-left-radius:0;border-left:0;padding:0 12px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;"
            @if($disabled) disabled @endif
        >
            <i data-lucide="search" style="width:18px;height:18px;"></i>
        </button>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
