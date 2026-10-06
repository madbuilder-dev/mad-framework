<?php
namespace Mad\Form;

use Mad\Http\MadStateCrypt;

/**
 * MadNoResultsHelper — gera payload encriptado + atributos HTML para o bloco
 * "no results" (create/quick register) dos componentes de selecao do MAD.
 *
 * Equivalente dos recursos `configureNoResultsCreateButton` e
 * `configureNoResultsQuickRegister` dos selects do framework legado, portado para
 * o ecossistema MAD (MAD Select + MadResponse + Mad.go).
 *
 * Uso tipico num Blade component:
 *
 *   $noResultsAttrs = \Mad\Form\MadNoResultsHelper::buildAttrs([
 *       'name'         => $name,
 *       'model'        => $model,
 *       'database'     => $database,
 *       'key'          => $keyField,
 *       'display'      => $display,
 *       'createAction' => $noResultsCreateAction,        // 'Classe::metodo'
 *       'createLabel'  => $noResultsCreateLabel,
 *       'createIcon'   => $noResultsCreateIcon,
 *       'createClass'  => $noResultsCreateClass,
 *       'quickAction'  => $noResultsQuickRegisterAction, // 'Classe::metodoEstatico'
 *       'quickLabel'   => $noResultsQuickRegisterLabel,
 *       'quickIcon'    => $noResultsQuickRegisterIcon,
 *       'quickClass'   => $noResultsQuickRegisterClass,
 *       'message'      => $noResultsMessage,
 *   ]);
 *   // Injeta no <select>: {!! $noResultsAttrs !!}
 *
 * Quando nenhuma das duas actions esta configurada, retorna string vazia — nao
 * adiciona ruido ao DOM.
 */
class MadNoResultsHelper
{
    /**
     * Monta a string de atributos HTML com o payload base64 codificado para
     * injetar num <select>. Retorna '' se nao houver action configurada.
     */
    public static function buildAttrs(array $cfg): string
    {
        $payload = self::buildPayload($cfg);
        if ($payload === null) {
            return '';
        }
        // Atributo lido pelo JS do MAD Select para montar o bloco no-results
        // ("cadastrar novo" / "quick register") no dropdown.
        return 'data-mad-noresults-payload="' . htmlspecialchars($payload, ENT_QUOTES, 'UTF-8') . '"';
    }

    /**
     * Monta o payload base64-JSON. Retorna null se nenhuma action estiver
     * configurada. Util para call-sites que precisam do payload bruto.
     */
    public static function buildPayload(array $cfg): ?string
    {
        $createAction = trim((string)($cfg['createAction'] ?? ''));
        $quickAction  = trim((string)($cfg['quickAction']  ?? ''));
        if ($createAction === '' && $quickAction === '') {
            return null;
        }

        $fieldName = (string)($cfg['name']    ?? '');
        $model     = (string)($cfg['model']   ?? '');
        $database  = (string)($cfg['database'] ?? '');
        $key       = (string)($cfg['key']     ?? 'id');
        $display   = (string)($cfg['display'] ?? 'nome');
        $message   = (string)($cfg['message'] ?? '');

        $data = [
            'fieldName' => $fieldName,
            'message'   => $message,
        ];

        // Bloco "Create" — abre outro MadComponent via Mad.go()
        if (($create = self::createAction($cfg)) !== null) {
            $data['create'] = $create;
        }

        // Bloco "Quick Register" — chama MadQuickRegisterService com token encriptado
        if ($quickAction !== '' && str_contains($quickAction, '::')) {
            [$cls, $mtd] = explode('::', $quickAction, 2);

            // Normaliza quickFields — array de definicoes de campos extras.
            // Quando vazio, o quick register usa apenas o input "term" (1 campo).
            // Quando definido, renderiza um mini-form com varios inputs.
            $rawFields = $cfg['quickFields'] ?? [];
            $resolved  = self::resolveQuickFields(is_array($rawFields) ? $rawFields : []);

            // Lista de names dos fields permitidos (allow-list usada no service)
            $allowedNames = array_values(array_map(fn($f) => $f['name'], $resolved));

            $token = MadStateCrypt::encryptFor('quick-register', [
                'class'         => trim($cls),
                'method'        => trim($mtd),
                'field_name'    => $fieldName,
                'model'         => $model,
                'database'      => $database,
                'key'           => $key,
                'display'       => $display,
                'allowedFields' => $allowedNames,
            ]);

            // onRun é estático → static=1 legítimo. Rota amigável assada no
            // servidor (/app/MadQuickRegisterService/onRun?static=1).
            $quickEndpoint = \Mad\Routing\MadRoutes::urlFor('MadQuickRegisterService', 'onRun', ['static' => 1]);

            $data['quick'] = [
                'label'    => (string)($cfg['quickLabel']  ?? 'Adicionar'),
                'icon'     => (string)($cfg['quickIcon']   ?? 'check'),
                'btnClass' => (string)($cfg['quickClass']  ?? 'mad-btn mad-btn-success mad-btn-sm'),
                'endpoint' => $quickEndpoint,
                'token'    => $token,
                'placeholder' => (string)($cfg['quickPlaceholder'] ?? ($message ?: 'Digite o nome...')),
                'fields'   => $resolved,  // [] = modo simples (so term)
            ];
        }

        return base64_encode(json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Bloco "Create": a tela que cadastra um registro novo para o campo, com a
     * URL assada e a IDENTIDADE DA ORIGEM assinada. Usado pelo no-results dos
     * combos e pelo botão "Novo" do `<mad-seek create>` — o form alvo devolve o
     * registro salvo pelo mesmo `MadComponent::returnToCombo()`.
     *
     * `createAction` = 'Classe::metodo' (ou só 'Classe', que abre no `show`).
     * Null quando não há ação.
     *
     * @return array{class:string,method:string,url:string,label:string,icon:string,btnClass:string,token:string}|null
     */
    public static function createAction(array $cfg): ?array
    {
        $createAction = trim((string)($cfg['createAction'] ?? ''));
        if ($createAction === '') {
            return null;
        }
        if (!str_contains($createAction, '::')) {
            // Só o no-results aceitava sem método; o seek abre a tela no show.
            if (empty($cfg['allowClassOnly']) || !preg_match('/^\\\\?[A-Za-z_][\w\\\\]*$/', $createAction)) {
                return null;
            }
            $createAction .= '::show';
        }

        [$cls, $mtd] = explode('::', $createAction, 2);
        $cls = trim($cls);
        $mtd = trim($mtd) ?: 'show';

        // Parâmetros fixos da abertura (`:create-params` do "+" do combo) —
        // assados na URL como os de um <mad-btn navigate>. A origem continua
        // no token: param solto não decide para onde a option volta.
        $params = [];
        foreach ((is_array($cfg['params'] ?? null) ? $cfg['params'] : []) as $k => $v) {
            if (is_string($k) && $k !== '' && $k[0] !== '_' && (is_scalar($v) || $v === null)) {
                $params[$k] = (string) $v;
            }
        }

        // URL amigável ASSADA NO SERVIDOR — mesmo caminho do <mad-btn>
        // (MadAction::url() → MadRoutes::urlFor). Sem ela o JS caía no
        // fallback genérico `/app/<Classe>/<metodo>?static=1`, que só
        // existe para classes com exposeClass: um form registrado por
        // resource()/expose() vive em `/app/paises/novo` e a forma genérica
        // dava 404. O `static=1` também sumiu: quem decide é o MadAction,
        // olhando se o método é REALMENTE estático (show de MadComponent
        // não é). Params dinâmicos (_mad_origin/_term) o JS anexa na hora.
        return [
            'class'    => $cls,
            'method'   => $mtd,
            'url'      => \Mad\Ui\MadAction::to($cls, $mtd, $params)->url(),
            'label'    => (string)($cfg['createLabel']  ?? 'Cadastrar novo'),
            'icon'     => (string)($cfg['createIcon']   ?? 'plus'),
            'btnClass' => (string)($cfg['createClass']  ?? 'mad-btn mad-btn-primary mad-btn-sm'),

            // IDENTIDADE DA ORIGEM, ASSINADA — o form alvo lê isto para
            // devolver a nova option ao combo certo (MadComponent::comboOrigin).
            // Token e não query param solto por dois motivos: o `field_name`
            // desemboca num `select[name="…"]` do JS (injeção de seletor), e a
            // resolução do label precisa de `model`/`database` — aceitar nome
            // de model vindo da URL seria primitiva de leitura arbitrária.
            // Mesmo mecanismo do bloco "quick".
            // Finalidade 'combo-origin': o form alvo a lê (decrypt comum); os
            // serviços de busca a recusam — tem model/display, mas não o filtro.
            'token'    => MadStateCrypt::encryptFor('combo-origin', [
                'field_name' => (string)($cfg['name']     ?? ''),
                'model'      => (string)($cfg['model']    ?? ''),
                'database'   => (string)($cfg['database'] ?? ''),
                'key'        => (string)($cfg['key']      ?? 'id'),
                'display'    => (string)($cfg['display']  ?? 'nome'),
            ]),
        ];
    }

    /**
     * Botão "+" AO LADO do combo (`create` do `<mad-dbcombo-field>` /
     * `<mad-dbunique-search-field>`): o mesmo "Cadastrar novo" do bloco sem
     * resultados, sempre à mão. Abre o cadastro por cima da tela com a origem
     * ASSINADA e o `returnToCombo()` do form alvo devolve a option nova
     * selecionada. Era o botão "+" que o 4.0 punha ao lado do TDBCombo.
     *
     * Aceita as chaves de createAction() (`createAction` = 'Classe::metodo' ou
     * só 'Classe', `params` fixos). '' quando não há ação.
     */
    public static function createButton(array $cfg, bool $disabled = false): string
    {
        $create = self::createAction(['allowClassOnly' => true] + $cfg);
        if ($create === null) {
            return '';
        }
        $payload = [
            'class'  => $create['class'],
            'method' => $create['method'],
            'url'    => $create['url'],
            'token'  => $create['token'],
            'field'  => (string) ($cfg['name'] ?? ''),
        ];
        $label = $create['label'] !== '' ? $create['label'] : 'Cadastrar novo';
        $icon  = preg_match('/^[a-z0-9-]+$/', $create['icon']) ? $create['icon'] : 'plus';
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        return '<button type="button" class="mad-btn mad-btn-secondary mad-combo-create-btn"'
            . ' data-mad-combo-create="' . $e(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '"'
            . ' onclick="Mad.comboCreate(this)"'
            . ' aria-label="' . $e($label) . '" title="' . $e($label) . '"'
            . ($disabled ? ' disabled' : '') . '>'
            . '<i data-lucide="' . $e($icon) . '" style="width:14px;height:14px;"></i></button>';
    }

    /**
     * Normaliza e pre-resolve os quickFields. Para tipos `dbcombo` ou `select`
     * com `model`, carrega as options do banco no servidor e devolve como array
     * inline — o cliente nao faz query.
     *
     * Cada definicao aceita:
     *   ['name' => 'codigo_ibge', 'label' => 'Cod. IBGE', 'type' => 'text',
     *    'required' => true, 'placeholder' => '...']
     *
     *   ['name' => 'estado_id', 'label' => 'Estado', 'type' => 'dbcombo',
     *    'model' => 'Estado', 'display' => 'nome', 'database' => 'business',
     *    'key' => 'id', 'order_by' => 'nome', 'required' => true]
     *
     *   ['name' => 'tipo', 'label' => 'Tipo', 'type' => 'select',
     *    'options' => ['A' => 'Ativo', 'I' => 'Inativo']]
     *
     * Tipos suportados: text, number, email, tel, password, date, select, dbcombo.
     */
    private static function resolveQuickFields(array $fields): array
    {
        $out = [];
        foreach ($fields as $raw) {
            if (!is_array($raw) || empty($raw['name'])) {
                continue;
            }
            $type = strtolower((string)($raw['type'] ?? 'text'));
            $field = [
                'name'        => (string) $raw['name'],
                'label'       => (string)($raw['label'] ?? $raw['name']),
                'type'        => $type,
                'required'    => !empty($raw['required']),
                'placeholder' => (string)($raw['placeholder'] ?? ''),
                'value'       => (string)($raw['value'] ?? ''),
            ];

            if ($type === 'select') {
                $opts = $raw['options'] ?? [];
                $field['options'] = is_array($opts) ? self::normalizeOptions($opts) : [];
            } elseif ($type === 'dbcombo') {
                $field['options'] = self::loadDbComboOptions($raw);
                $field['type']    = 'select'; // unifica no JS
                // Token de refresh: as options acima ficam CONGELADAS no payload
                // do render do form host. Se um registro nasce depois (ex.: pais
                // criado via no-results-create de outra combo na mesma tela), o
                // popover abriria desatualizado. O token permite ao JS re-buscar
                // a lista COMPLETA no MadDbComboService na abertura do popover.
                // `full` e flag explicita: token de cascata (depends-on) sem
                // `column` continua fail-closed no service, nunca vira dump.
                $model = (string)($raw['model'] ?? '');
                if ($model !== '') {
                    $field['token'] = MadStateCrypt::encryptFor('db-combo', [
                        'full'     => true,
                        'database' => (string)($raw['database'] ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : '')),
                        'model'    => $model,
                        'key'      => (string)($raw['key'] ?? 'id'),
                        'display'  => (string)($raw['display'] ?? 'nome'),
                        'order'    => (string)($raw['order_by'] ?? $raw['orderBy'] ?? ''),
                        'filters'  => (isset($raw['filters']) && is_array($raw['filters'])) ? $raw['filters'] : [],
                    ]);
                }
            }

            $out[] = $field;
        }
        return $out;
    }

    /** Converte ['1' => 'A', '2' => 'B'] em [['value'=>'1','label'=>'A'], ...]. */
    private static function normalizeOptions(array $opts): array
    {
        $out = [];
        foreach ($opts as $k => $v) {
            $out[] = ['value' => (string)$k, 'label' => (string)$v];
        }
        return $out;
    }

    /** Carrega options de um model Eloquent (mesma logica do dbcombo-field). */
    private static function loadDbComboOptions(array $raw): array
    {
        $database = (string)($raw['database'] ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : ''));
        $model    = (string)($raw['model']    ?? '');
        $keyField = (string)($raw['key']      ?? 'id');
        $display  = (string)($raw['display']  ?? 'nome');
        $orderBy  = (string)($raw['order_by'] ?? $raw['orderBy'] ?? '');

        if ($model === '') {
            return [];
        }

        try {
            // F5: builder-native — aplica :filters via applyArrayFilters (Query Builder puro).
            $__m = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__q = $__m::query();
            if (!empty($raw['filters']) && is_array($raw['filters'])) {
                \Mad\Database\QuerySource::applyArrayFilters($__q, $raw['filters']);
            }
            $items = \Mad\Form\ModelOptionsLoader::itemsFromQuery($__q, $keyField, $display, $orderBy ?: null);
            return self::normalizeOptions($items ?: []);
        } catch (\Throwable $e) {
            // Combo do cadastro rápido vazio, como antes — mas com o motivo no log.
            OptionsLoadError::report($e, 'cadastro rápido (dbcombo)', [
                'field'    => (string)($raw['name'] ?? ''),
                'model'    => $model,
                'database' => $database,
                'display'  => $display,
                'order_by' => $orderBy,
            ]);
            return [];
        }
    }

}
