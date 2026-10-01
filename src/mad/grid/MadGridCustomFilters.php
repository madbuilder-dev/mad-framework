<?php
namespace Mad\Grid;

use Mad\Form\MadFormRegistry;
use Mad\I18n\MadLang;
use Mad\Ui\MadToast;

/**
 * MadGridCustomFilters — "Filtro avançado" do usuário final numa listagem
 * (`<mad-custom-filters>` dentro do `<mad-grid>`). Usada pelo MadDataGrid.
 *
 * ┌─ Quem decide o quê ────────────────────────────────────────────────────┐
 * │                                                                        │
 * │  O DEV declara as colunas filtráveis (defs): campo, tipo, operadores,  │
 * │  opções. O USUÁRIO monta regras {k, op, v, d} sobre essas defs e as    │
 * │  combina com "todas" (AND) ou "qualquer uma" (OR).                     │
 * │                                                                        │
 * │  Nada da regra vira SQL direto: `k` precisa existir nas defs, `op`     │
 * │  precisa estar na lista do tipo e `v` é coagido/validado pelo tipo e   │
 * │  pelo operador. O que não passa é DESCARTADO em silêncio (fail-closed) │
 * │  — na aplicação E de novo em toda consulta, porque o estado viaja pelo │
 * │  cliente e pode ter chegado por outro canal.                           │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Selo das defs ────────────────────────────────────────────────────────┐
 * │                                                                        │
 * │  As defs são a ALLOWLIST de colunas — e são uma prop pública array.    │
 * │  O mad_state é AES-GCM (inforjável), mas o fill() de mad:model grava   │
 * │  chaves NÃO-públicas dentro da primeira prop array que as tiver        │
 * │  (`mad_model[0][field]=senha` reescreveria `customFilterDefs[0]`).     │
 * │  Por isso defs + config levam um HMAC (`customFilterSeal`) calculado   │
 * │  SÓ quando vêm do config compilado; selo que não confere = recurso     │
 * │  desligado nesta requisição (nenhuma coluna filtrável).                │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Shape do estado (`customFilterState`):
 *   ['match' => 'all'|'any', 'rules' => [['k','op','v','d'], …],
 *    'viewId' => ?int (filtro salvo aplicado), 'touched' => bool]
 *
 * `touched` = o estado já foi decidido nesta abertura (pelo usuário ou pelo
 * filtro padrão) — o padrão salvo só é aplicado com touched=false.
 */
trait MadGridCustomFilters
{
    /**
     * Defs normalizadas — a allowlist do que o usuário final pode filtrar.
     * Cada item: ['key','field','label','kind','ops', 'opts'?, 'true'?,
     * 'false'?, 'placeholder'?, 'model'?, 'display'?, 'keyField'?, 'orderBy'?,
     * 'order'?, 'database'?, 'filters'?, 'minLength'?]. Preenchidas pelo
     * config do Blade no render (mesmo ciclo do exportColConfigs).
     */
    public array $customFilterDefs = [];

    /** Opções do container: save / match (inicial) / max / share / label. */
    public array $customFilterConfig = [];

    /** HMAC de defs+config (ver docblock da trait). */
    public string $customFilterSeal = '';

    /** Estado do usuário: match, rules, viewId, touched. */
    public array $customFilterState = [];

    /** Condições descartadas no último filtro salvo aplicado (só esta requisição). */
    protected int $_cfDropped = 0;

    /** Lista de filtros salvos desta requisição (memo). */
    protected ?array $_cfSavedMemo = null;

    /** Props públicas do filtro avançado — fora da descoberta da MadFiltersTrait. */
    public const CUSTOM_FILTER_PROPS = ['customFilterDefs', 'customFilterConfig', 'customFilterSeal', 'customFilterState'];

    /** Teto duro de condições, qualquer que seja o `max` declarado. */
    public const CUSTOM_FILTER_HARD_MAX = 20;

    /** Operadores sem valor (o `v` da regra é ignorado). */
    public const CUSTOM_FILTER_NO_VALUE_OPS = ['empty', 'not empty', 'is null', 'is not null'];

    /** Operadores de lista (valor = array). */
    public const CUSTOM_FILTER_LIST_OPS = ['in', 'not in'];

    /** Operadores de intervalo (valor = [de, até], um lado pode ser ''). */
    public const CUSTOM_FILTER_RANGE_OPS = ['between', 'date between'];

    /** Operador → sufixo da chave de tradução (`mad.gridcf.op.<grupo>.<slug>`). */
    private const CF_OP_SLUGS = [
        'like' => 'like', 'not like' => 'not_like', '=' => 'eq', '!=' => 'neq',
        '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte',
        'starts' => 'starts', 'ends' => 'ends', 'in' => 'in', 'not in' => 'not_in',
        'empty' => 'empty', 'not empty' => 'not_empty', 'between' => 'between',
        'is null' => 'null', 'is not null' => 'not_null', 'date' => 'date',
        'date between' => 'date_between', 'date preset' => 'date_preset',
    ];

    // ── Handlers (wire) ──────────────────────────────────────────────────

    /**
     * Aplica o filtro montado na UI: `{match: 'all'|'any', rules: [{k,op,v,d}],
     * keepView?: bool}`. Cada regra é validada contra as defs; inválida sai
     * (fail-closed). Volta à página 1 e recarrega.
     */
    public function onCustomFilterApply(array $payload = []): void
    {
        $defs = $this->_cfDefsByKey();
        if ($defs === []) {
            return;
        }

        $state = $this->_cfState();
        $match = $payload['match'] ?? $state['match'];
        [$rules] = $this->_cfValidateRules(is_array($payload['rules'] ?? null) ? $payload['rules'] : []);

        $state['match']   = in_array($match, ['all', 'any'], true) ? $match : $state['match'];
        $state['rules']   = $rules;
        $state['touched'] = true;
        if (empty($payload['keepView'])) {
            $state['viewId'] = null;
        }
        $this->customFilterState = $state;

        $this->page = 1;
        $this->loadData();
    }

    /** Remove UMA condição (índice de `customFilters.rules[].index` da view). */
    public function onCustomFilterRemove(int $index): void
    {
        // O índice é o da lista EM VIGOR (a mesma que a view expõe em
        // `rules[].index`) — regra inválida no estado não desloca a contagem.
        $rules = array_map(fn ($pair) => $pair[1], $this->_cfActiveRules());
        if (!array_key_exists($index, $rules)) {
            return;
        }
        array_splice($rules, $index, 1);
        $state = $this->_cfState();
        $state['rules']   = $rules;
        $state['touched'] = true;
        $state['viewId']  = null;   // o filtro na tela já não é o salvo
        $this->customFilterState = $state;

        $this->page = 1;
        $this->loadData();
    }

    /** Remove todas as condições do filtro avançado. */
    public function onCustomFilterClear(): void
    {
        $this->_cfClearRules();
        $this->page = 1;
        $this->loadData();
    }

    /** Troca a combinação: 'all' (todas / AND) ou 'any' (qualquer uma / OR). */
    public function onCustomFilterMatch(string $match): void
    {
        if (!in_array($match, ['all', 'any'], true) || $this->_cfDefs() === []) {
            return;
        }
        $state = $this->_cfState();
        $state['match']   = $match;
        $state['touched'] = true;
        $state['viewId']  = null;
        $this->customFilterState = $state;

        $this->page = 1;
        $this->loadData();
    }

    /**
     * Contagem AO VIVO do rascunho do popover ("Aplicar · N registros"). Mesmo
     * payload e mesma validação do onCustomFilterApply; as regras entram no
     * estado SÓ durante o COUNT e saem em seguida — nada persiste (estado,
     * página, sessão). Devolve um único op `grid_cf_count` que o popover lê da
     * resposta, sem redesenhar a grid (skipFullRender).
     *
     * `count` = null quando a contagem não é confiável ou não é permitida:
     * grid sem filtro avançado, `query()`/`_autoQuery()` próprios (a consulta
     * real não passa pelo _buildQuery), filtro obrigatório que o rascunho ainda
     * não atende, grid no-auto-load sem condição nenhuma (seria o COUNT(*) sem
     * filtro que a opção existe para evitar) ou erro de banco. Roda como o
     * mesmo componente/usuário de qualquer outra ação do wire: permissões,
     * `:filters` do Blade, busca e filtros de coluna continuam valendo.
     */
    public function onCustomFilterCount(array $payload = []): \Mad\Http\MadResponse
    {
        $this->skipFullRender();

        $response = new \Mad\Http\MadResponse();
        $response->ops[] = ['op' => 'grid_cf_count', 'count' => $this->_cfDraftCount($payload)];

        return $response;
    }

    /**
     * Salva o filtro ATUAL com nome (1..120). `shared` só vale para quem pode
     * compartilhar (`share="admin|everyone"`); sem permissão o filtro sai
     * pessoal. Mesmo nome sobrescreve.
     *
     * `$payload` ({match, rules}) = o RASCUNHO do popover: é aplicado antes
     * (mesma validação do onCustomFilterApply) e o que ficou valendo é salvo —
     * "Salvar filtro atual…" numa ida só ao servidor. Sem ele, salva o que já
     * está aplicado na tela.
     */
    public function onSavedFilterSave(string $name, bool $shared = false, bool $default = false, ?array $payload = null): ?\Mad\Http\MadResponse
    {
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();

        if (GridSavedFilterStore::cleanName($name) === null) {
            return MadToast::warning(MadLang::t('mad.gridcf.name_invalid'));
        }
        if ($payload !== null) {
            $this->onCustomFilterApply([
                'match' => $payload['match'] ?? null,
                'rules' => is_array($payload['rules'] ?? null) ? $payload['rules'] : [],
            ]);
        }
        $active = $this->_cfActiveRules();
        if ($active === []) {
            return MadToast::warning(MadLang::t('mad.gridcf.nothing_to_save'));
        }

        $payload = [
            'match' => $this->_cfState()['match'],
            'rules' => array_map(fn ($pair) => $pair[1], $active),
        ];
        $id = $ctx['store']->save(
            $ctx['gridKey'],
            $ctx['userId'],
            $name,
            $payload,
            $shared && $ctx['canShare'],
            $default
        );
        if ($id === null) {
            return MadToast::danger(MadLang::t('mad.gridcf.save_failed'));
        }

        $state = $this->_cfState();
        $state['viewId']  = $id;
        $state['touched'] = true;
        $this->customFilterState = $state;
        $this->_cfSavedMemo = null;

        return MadToast::success(MadLang::t('mad.gridcf.saved_ok'));
    }

    /**
     * Aplica um filtro salvo visível ao usuário. As regras são revalidadas
     * contra as defs ATUAIS: coluna/operador que sumiu da tela é descartado, e
     * a contagem vai para a view (`customFilters.dropped`) avisar o usuário.
     */
    public function onSavedFilterApply(int $id): void
    {
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return;
        }
        $row = $ctx['store']->find($id, $ctx['gridKey'], $ctx['userId']);
        if ($row === null) {
            return;
        }

        $this->_cfUseSaved($row);
        $this->page = 1;
        $this->loadData();
    }

    /** Exclui um filtro salvo — dono, ou admin se for compartilhado. */
    public function onSavedFilterDelete(int $id): ?\Mad\Http\MadResponse
    {
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();

        if (!$ctx['store']->delete($id, $ctx['gridKey'], $ctx['userId'], $ctx['isAdmin'])) {
            return MadToast::warning(MadLang::t('mad.gridcf.not_allowed'));
        }

        $state = $this->_cfState();
        if ($state['viewId'] === $id) {
            $state['viewId'] = null;
            $this->customFilterState = $state;
        }
        $this->_cfSavedMemo = null;

        return MadToast::success(MadLang::t('mad.gridcf.deleted_ok'));
    }

    /** Liga/desliga "usar como padrão" num filtro do usuário. */
    public function onSavedFilterDefault(int $id): void
    {
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return;
        }
        $this->forceFullRender();
        $ctx['store']->toggleDefault($id, $ctx['gridKey'], $ctx['userId']);
        $this->_cfSavedMemo = null;
    }

    /** Renomeia um filtro salvo — mesmas regras da exclusão. */
    public function onSavedFilterRename(int $id, string $name): ?\Mad\Http\MadResponse
    {
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return null;
        }
        $this->forceFullRender();
        if (!$ctx['store']->rename($id, $ctx['gridKey'], $ctx['userId'], $name, $ctx['isAdmin'])) {
            return MadToast::warning(MadLang::t('mad.gridcf.name_invalid'));
        }
        $this->_cfSavedMemo = null;

        return null;
    }

    /**
     * Defs/config/estado ficam FORA do contexto da view: achatadas, as chaves
     * do config e do estado (`label`, `match`, `max`, `save`, `rules`,
     * `viewId`…) viravam variáveis do Blade da tela e sobrescreviam as dela. A
     * view recebe o que precisa por view data (`$customFilters`).
     */
    protected function _viewContextExcludedProps(): array
    {
        return array_merge(parent::_viewContextExcludedProps(), self::CUSTOM_FILTER_PROPS);
    }

    // ── Config / defs / selo ─────────────────────────────────────────────

    /**
     * Aplica o config `customFilters` do Blade (compilado = confiável) e sela.
     * null/sem defs válidas = recurso desligado.
     *
     * @return bool true quando defs/config mudaram E há condições no estado —
     *              o caller força recarga (o loadData do mount rodou sem elas)
     */
    protected function _cfApplyConfig(?array $raw): bool
    {
        $defs = $raw === null ? [] : static::_cfNormalizeDefs(is_array($raw['defs'] ?? null) ? $raw['defs'] : []);
        $cfg  = $defs === [] ? [] : static::_cfNormalizeConfig($raw ?? []);
        $seal = $defs === [] ? '' : $this->_cfSealOf($cfg, $defs);

        $changed = $defs !== $this->customFilterDefs
            || $cfg !== $this->customFilterConfig
            || !hash_equals($seal, $this->customFilterSeal);

        $this->customFilterDefs   = $defs;
        $this->customFilterConfig = $cfg;
        $this->customFilterSeal   = $seal;
        $this->_cfSavedMemo       = null;

        return $changed && $this->_cfState()['rules'] !== [];
    }

    /** Container: save/match/max/share/label com defaults e tetos. */
    protected static function _cfNormalizeConfig(array $raw): array
    {
        $save  = strtolower((string) ($raw['save'] ?? 'shared'));
        $match = strtolower((string) ($raw['match'] ?? 'all'));
        $share = strtolower((string) ($raw['share'] ?? 'admin'));
        $max   = is_numeric($raw['max'] ?? null) ? (int) $raw['max'] : 15;

        return [
            'save'  => in_array($save, ['shared', 'user', 'off'], true) ? $save : 'shared',
            'match' => in_array($match, ['all', 'any'], true) ? $match : 'all',
            'max'   => $max > 0 ? min(self::CUSTOM_FILTER_HARD_MAX, $max) : 15,
            'share' => in_array($share, ['admin', 'everyone'], true) ? $share : 'admin',
            'label' => is_scalar($raw['label'] ?? null) ? mb_substr(trim((string) $raw['label']), 0, 120) : '',
        ];
    }

    /**
     * Defs → forma canônica. Mesmo contrato do compilador (tipo, operadores,
     * campo normalizado, 1ª chave vence), revalidado aqui porque o config
     * também pode chegar montado à mão (GridBuilder, teste, subclasse).
     */
    protected static function _cfNormalizeDefs(array $raw): array
    {
        $out  = [];
        $seen = [];
        foreach ($raw as $d) {
            if (!is_array($d) || !is_string($d['field'] ?? null)) continue;
            $field = MadGridCompiler::normalizeCustomFilterField($d['field']);
            if ($field === null || isset($seen[$field])) continue;
            $seen[$field] = true;

            $kind  = MadGridCompiler::customFilterKind((string) ($d['kind'] ?? $d['type'] ?? 'text'));
            $label = is_scalar($d['label'] ?? null) ? trim((string) $d['label']) : '';
            $def   = [
                'key'   => $field,
                'field' => $field,
                'label' => mb_substr($label !== '' ? $label : $field, 0, 120),
                'kind'  => $kind,
                'ops'   => MadGridCompiler::customFilterOps($kind, is_array($d['ops'] ?? null) || is_string($d['ops'] ?? null) ? $d['ops'] : []),
            ];
            if (is_scalar($d['placeholder'] ?? null) && (string) $d['placeholder'] !== '') {
                $def['placeholder'] = mb_substr((string) $d['placeholder'], 0, 200);
            }

            if ($kind === 'select') {
                $opts = $d['opts'] ?? [];
                if (is_string($opts)) $opts = GridColumn::parseOptsMap($opts);
                $clean = [];
                foreach (is_array($opts) ? $opts : [] as $k => $v) {
                    if (!is_scalar($v)) continue;
                    $clean[(string) $k] = (string) $v;
                    if (count($clean) >= 500) break;
                }
                $def['opts'] = $clean;
            }

            if ($kind === 'bool') {
                $t = is_scalar($d['true'] ?? null) ? (string) $d['true'] : '';
                $f = is_scalar($d['false'] ?? null) ? (string) $d['false'] : '';
                $def['true']  = $t !== '' ? $t : '1';
                $def['false'] = $f !== '' ? $f : '0';
            }

            if ($kind === 'dbcombo' || $kind === 'dbsearch') {
                $ident = '/^[A-Za-z_][A-Za-z0-9_]*$/';
                $def['model']    = is_string($d['model'] ?? null) ? trim($d['model']) : '';
                $def['display']  = is_string($d['display'] ?? null) && trim($d['display']) !== '' ? trim($d['display']) : 'nome';
                $keyField        = is_string($d['keyField'] ?? $d['key_field'] ?? null) ? trim((string) ($d['keyField'] ?? $d['key_field'])) : 'id';
                $def['keyField'] = preg_match($ident, $keyField) ? $keyField : 'id';
                $orderBy         = is_string($d['orderBy'] ?? null) ? trim($d['orderBy']) : '';
                $def['orderBy']  = preg_match($ident, $orderBy) ? $orderBy : '';
                $order           = strtolower(is_string($d['order'] ?? null) ? $d['order'] : '');
                $def['order']    = in_array($order, ['asc', 'desc'], true) ? $order : '';
                $database        = is_string($d['database'] ?? null) ? trim($d['database']) : '';
                $def['database'] = preg_match('/^[A-Za-z0-9_.-]*$/', $database) ? $database : '';
                $def['filters']  = is_array($d['filters'] ?? null) ? array_values($d['filters']) : [];
                $def['minLength'] = is_numeric($d['minLength'] ?? null) ? max(0, min(10, (int) $d['minLength'])) : 2;
            }

            $out[] = $def;
        }

        return $out;
    }

    /** HMAC das defs + config, amarrado à classe do grid. */
    protected function _cfSealOf(array $cfg, array $defs): string
    {
        $json = json_encode([static::class, $cfg, $defs], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return hash_hmac('sha256', 'mad-grid-custom-filters|' . $json, MadFormRegistry::getSecret());
    }

    /** O selo confere com defs+config atuais? (sem defs = nada a proteger) */
    protected function _cfSealed(): bool
    {
        if ($this->customFilterDefs === [] && $this->customFilterConfig === []) {
            return true;
        }
        if ($this->customFilterSeal === ''
            || !hash_equals($this->_cfSealOf($this->customFilterConfig, $this->customFilterDefs), $this->customFilterSeal)) {
            static $warned = [];
            if (empty($warned[static::class])) {
                $warned[static::class] = true;
                @error_log('[MadDataGrid] filtro avançado desligado em ' . static::class
                    . ': defs com selo inválido (alteradas fora do config do Blade).');
            }

            return false;
        }

        return true;
    }

    /** Defs válidas (selo conferido); [] = recurso desligado. */
    protected function _cfDefs(): array
    {
        return $this->_cfSealed() ? $this->customFilterDefs : [];
    }

    /** @return array<string, array> defs indexadas pela chave */
    protected function _cfDefsByKey(): array
    {
        $out = [];
        foreach ($this->_cfDefs() as $d) {
            if (is_array($d) && isset($d['key'])) $out[(string) $d['key']] = $d;
        }

        return $out;
    }

    /** Config efetivo (defaults quando ausente ou selo inválido). */
    protected function _cfConfig(): array
    {
        return static::_cfNormalizeConfig($this->_cfSealed() ? $this->customFilterConfig : []);
    }

    /** Máximo de condições desta tela (≤ teto duro). */
    protected function _cfMax(): int
    {
        return (int) $this->_cfConfig()['max'];
    }

    // ── Estado ───────────────────────────────────────────────────────────

    /** Estado normalizado (tolera prop vazia/adulterada). */
    protected function _cfState(): array
    {
        $s     = $this->customFilterState;
        $match = $s['match'] ?? null;
        $rules = [];
        foreach (is_array($s['rules'] ?? null) ? $s['rules'] : [] as $r) {
            if (is_array($r)) $rules[] = $r;
        }
        $view = $s['viewId'] ?? null;
        $view = (is_int($view) || (is_string($view) && ctype_digit($view))) && (int) $view > 0 ? (int) $view : null;

        return [
            'match'   => in_array($match, ['all', 'any'], true) ? $match : $this->_cfConfig()['match'],
            'rules'   => $rules,
            'viewId'  => $view,
            'touched' => !empty($s['touched']),
        ];
    }

    /** Zera as condições (Limpar / Limpar tudo). */
    protected function _cfClearRules(): void
    {
        if ($this->customFilterState === [] && $this->customFilterDefs === []) {
            return;   // grid sem filtro avançado: estado continua vazio
        }
        $state = $this->_cfState();
        $state['rules']   = [];
        $state['viewId']  = null;
        $state['touched'] = true;
        $this->customFilterState = $state;
    }

    /**
     * Condições em vigor, REVALIDADAS contra as defs e cortadas no máximo.
     *
     * @return list<array{0: array, 1: array}> pares [def, regra normalizada]
     */
    protected function _cfActiveRules(): array
    {
        if ($this->customFilterDefs === []) {
            return [];
        }
        $defs = $this->_cfDefsByKey();
        if ($defs === []) {
            return [];
        }

        $out = [];
        $max = $this->_cfMax();
        foreach ($this->_cfState()['rules'] as $r) {
            $def = $defs[(string) ($r['k'] ?? '')] ?? null;
            if ($def === null) continue;
            $norm = $this->_cfNormalizeRule($r, $def);
            if ($norm === null) continue;
            $out[] = [$def, $norm];
            if (count($out) >= $max) break;
        }

        return $out;
    }

    /**
     * Valida uma lista de regras cruas (do cliente ou de um filtro salvo).
     *
     * @return array{0: list<array>, 1: int} [regras válidas, descartadas]
     */
    protected function _cfValidateRules(array $raw): array
    {
        $defs    = $this->_cfDefsByKey();
        $max     = $this->_cfMax();
        $rules   = [];
        $dropped = 0;
        foreach ($raw as $r) {
            if (!is_array($r)) { $dropped++; continue; }
            $def  = $defs[is_scalar($r['k'] ?? null) ? (string) $r['k'] : ''] ?? null;
            $norm = $def !== null ? $this->_cfNormalizeRule($r, $def) : null;
            if ($norm === null) { $dropped++; continue; }
            if (count($rules) >= $max) { $dropped++; continue; }
            $rules[] = $norm;
        }

        return [$rules, $dropped];
    }

    /**
     * Uma regra crua → normalizada, ou null (fail-closed). Operador precisa
     * estar na lista da def; valor é coagido pelo tipo e pelo operador.
     */
    protected function _cfNormalizeRule(array $r, array $def): ?array
    {
        $op = static::_canonicalOp(is_string($r['op'] ?? null) ? $r['op'] : '');
        if ($op === '' || !in_array($op, $def['ops'] ?? [], true)) {
            return null;
        }

        if (in_array($op, self::CUSTOM_FILTER_NO_VALUE_OPS, true)) {
            $v = null;
        } else {
            $v = $this->_cfNormalizeValue($def, $op, $r['v'] ?? null);
            if ($v === null) return null;
        }

        $d = $r['d'] ?? '';

        return [
            'k'  => (string) $def['key'],
            'op' => $op,
            'v'  => $v,
            // Rótulo do chip vindo do cliente: texto puro, só exibido (o Blade
            // escapa). Nunca entra em SQL.
            'd'  => is_scalar($d) ? mb_substr(trim((string) $d), 0, 300) : '',
        ];
    }

    /** Valor da regra pelo operador; null = inválido. */
    protected function _cfNormalizeValue(array $def, string $op, mixed $v): mixed
    {
        $kind = (string) $def['kind'];

        if (in_array($op, self::CUSTOM_FILTER_LIST_OPS, true)) {
            $out = [];
            foreach (static::_scalarList(static::_normalizeFilterValue($v, 'multi')) as $item) {
                $n = $this->_cfScalar($def, $item);
                if ($n !== null) $out[] = $n;
            }
            $out = array_values(array_unique($out));

            return $out === [] ? null : $out;
        }

        if ($op === 'between') {
            [$a, $b] = static::_rangeParts(static::_normalizeFilterValue($v, 'number-range'));
            $a = $a !== null && is_numeric(trim($a)) ? trim($a) : null;
            $b = $b !== null && is_numeric(trim($b)) ? trim($b) : null;

            return ($a === null && $b === null) ? null : [$a ?? '', $b ?? ''];
        }

        if ($op === 'date between') {
            [$a, $b] = static::_rangeParts(static::_normalizeFilterValue($v, 'date-range'));
            $a = static::_cfDate($a, false);
            $b = static::_cfDate($b, false);

            return ($a === null && $b === null) ? null : [$a ?? '', $b ?? ''];
        }

        if ($op === 'date preset') {
            return is_string($v) && in_array($v, static::DATE_PRESETS, true) ? $v : null;
        }

        if ($op === 'date') {
            return static::_cfDate($v, false);
        }

        if (in_array($kind, ['date', 'datetime'], true)) {
            // `<` / `>` de data: dia (ou data-hora no datetime).
            return static::_cfDate($v, $kind === 'datetime');
        }

        return $this->_cfScalar($def, $v);
    }

    /** Um valor escalar pelo tipo da def; null = inválido. */
    protected function _cfScalar(array $def, mixed $v): ?string
    {
        if ($v === null || is_array($v) || is_object($v)) {
            return null;
        }
        if (is_bool($v)) {
            $v = $v ? 'true' : 'false';
        }
        $s = trim((string) $v);

        switch ((string) $def['kind']) {
            case 'number':
                return is_numeric($s) ? $s : null;

            case 'date':
            case 'datetime':
                return static::_cfDate($s, $def['kind'] === 'datetime');

            case 'bool':
                $true = (string) ($def['true'] ?? '1');
                return ($s === 'true' || $s === '1' || $s === $true) ? $true : (string) ($def['false'] ?? '0');

            case 'select':
                return ($s !== '' && array_key_exists($s, (array) ($def['opts'] ?? []))) ? $s : null;

            case 'dbcombo':
            case 'dbsearch':
                return ($s !== '' && mb_strlen($s) <= 200) ? $s : null;

            default: // text
                return $s === '' ? null : mb_substr($s, 0, 200);
        }
    }

    /**
     * Data `Y-m-d` validada com checkdate. Com `$allowTime`, aceita também
     * `Y-m-d H:i[:s]` (e o `T` do input datetime-local) → `Y-m-d H:i:s`; sem,
     * uma data-hora vira só a data.
     */
    protected static function _cfDate(mixed $v, bool $allowTime): ?string
    {
        if (!is_scalar($v)) return null;
        $s = trim((string) $v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2}))?)?$/', $s, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $date = "{$m[1]}-{$m[2]}-{$m[3]}";
        if (!isset($m[4]) || $m[4] === '' || !$allowTime) {
            return $date;
        }
        $sec = ($m[6] ?? '') !== '' ? $m[6] : '00';
        if ((int) $m[4] > 23 || (int) $m[5] > 59 || (int) $sec > 59) {
            return null;
        }

        return "{$date} {$m[4]}:{$m[5]}:{$sec}";
    }

    // ── Consulta ─────────────────────────────────────────────────────────

    /**
     * COUNT do rascunho (ver onCustomFilterCount) — regras trocadas no estado
     * só durante a consulta, restauradas no `finally`. null = sem contagem.
     */
    protected function _cfDraftCount(array $payload): ?int
    {
        if ($this->_cfDefsByKey() === [] || empty($this->model) || !$this->_cfUsesAutoQuery()) {
            return null;
        }
        [$rules] = $this->_cfValidateRules(is_array($payload['rules'] ?? null) ? $payload['rules'] : []);

        $saved = $this->customFilterState;
        $state = $this->_cfState();
        $state['match'] = ($payload['match'] ?? '') === 'any' ? 'any' : 'all';
        $state['rules'] = $rules;
        $this->customFilterState = $state;

        try {
            // Mesmas travas da carga: filtro obrigatório não atendido e grid
            // no-auto-load sem condição não contam (nem revelam o total).
            if ($this->_filterGateOn() && !$this->_hasUserFilter()) {
                return null;
            }
            if ($rules === [] && $this->_isDeferred()) {
                return null;
            }

            // Mesmo COUNT do _runQuery: clone sem ORDER BY (e sem os binds
            // dele) + o onSearch do dev.
            $q = $this->_buildQuery();
            $b = $this->_queryBuilder($q);
            $b->orders = null;
            $b->bindings['order'] = [];
            if ($this->searchQuery) {
                ($this->searchQuery)($q);
            }

            return (int) $q->count();
        } catch (\Throwable $e) {
            @error_log('[MadDataGrid] contagem do filtro avançado em ' . static::class . ': ' . $e->getMessage());

            return null;
        } finally {
            $this->customFilterState = $saved;
        }
    }

    /**
     * A listagem usa a consulta automática (_buildQuery)? `query()` ou
     * `_autoQuery()` próprios (grid de array, MadSeekGrid) montam outra
     * consulta — um COUNT sobre o _buildQuery mentiria.
     */
    protected function _cfUsesAutoQuery(): bool
    {
        foreach (['query', '_autoQuery'] as $method) {
            if ((new \ReflectionMethod($this, $method))->getDeclaringClass()->getName() !== MadDataGrid::class) {
                return false;
            }
        }

        return true;
    }

    /**
     * (E2) do _buildQuery: UM grupo envolvendo as condições, cada uma no seu
     * próprio fechamento — `where` (todas) ou `orWhere` (qualquer uma).
     *
     * Regra cujo campo/operador falha fechado produz um fechamento VAZIO, que o
     * Laravel descarta (addNestedWhereQuery ignora grupo sem where): um OR só de
     * regras descartadas não vira "nada casa" nem SQL quebrado — o grupo todo
     * some e a listagem fica como sem filtro avançado.
     */
    protected function _applyCustomFilters($q): void
    {
        $pairs = $this->_cfActiveRules();
        if ($pairs === []) {
            return;
        }
        $any = $this->_cfState()['match'] === 'any';

        $q->where(function ($g) use ($pairs, $any) {
            foreach ($pairs as [$def, $rule]) {
                $one = function ($w) use ($def, $rule) {
                    $this->_cfApplyRule($w, $def, $rule);
                };
                $any ? $g->orWhere($one) : $g->where($one);
            }
        });
    }

    /**
     * Uma condição → _applyColWhere (ponto único de SQL por operador, chains
     * inclusive). Ajuste de data que depende do TIPO mora aqui, não no
     * _whereOp: `>` de um dia fecha no fim do dia (senão "depois de 28/07"
     * incluiria 28/07 10h numa coluna datetime); `<` abre no início do dia.
     * No tipo `date` o `<` vai com o dia puro — mesmo instante nos bancos de
     * servidor, e evita a comparação de texto do SQLite, onde '2026-07-28' é
     * MENOR que '2026-07-28 00:00:00' e o próprio dia vazaria.
     */
    protected function _cfApplyRule($w, array $def, array $rule): void
    {
        $op = (string) $rule['op'];
        $v  = $rule['v'];
        if (in_array($def['kind'], ['date', 'datetime'], true) && is_string($v) && strlen($v) === 10) {
            if ($op === '>') {
                $v .= ' 23:59:59';
            } elseif ($op === '<' && $def['kind'] === 'datetime') {
                $v .= ' 00:00:00';
            }
        }

        $this->_applyColWhere($w, (string) $def['field'], $op, $v);
    }

    // ── Filtros salvos ───────────────────────────────────────────────────

    /** Store dos filtros salvos (sobrescrevível em teste/subclasse). */
    protected function _cfStore(): GridSavedFilterStore
    {
        return new GridSavedFilterStore();
    }

    /** Chave do grid na tabela de filtros salvos (classe da tela). */
    protected function _cfGridKey(): string
    {
        return str_replace('\\', '_', static::class);
    }

    /**
     * Contexto dos filtros salvos, ou null quando desligados: `save="off"`,
     * grid sem defs válidas ou nenhum usuário na sessão.
     *
     * @return array{store: GridSavedFilterStore, gridKey: string, userId: int, isAdmin: bool, canShare: bool}|null
     */
    protected function _cfSavedContext(): ?array
    {
        if ($this->_cfDefs() === []) {
            return null;
        }
        $cfg = $this->_cfConfig();
        if ($cfg['save'] === 'off') {
            return null;
        }
        $uid = GridSavedFilterStore::currentUserId();
        if ($uid === null) {
            return null;
        }
        $isAdmin = GridSavedFilterStore::isAdmin();

        return [
            'store'    => $this->_cfStore(),
            'gridKey'  => $this->_cfGridKey(),
            'userId'   => $uid,
            'isAdmin'  => $isAdmin,
            'canShare' => $cfg['save'] === 'shared' && ($cfg['share'] === 'everyone' || $isAdmin),
        ];
    }

    /** Estado passa a ser o do filtro salvo (regras revalidadas). */
    protected function _cfUseSaved(array $row): bool
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        [$rules, $dropped] = $this->_cfValidateRules(is_array($payload['rules'] ?? null) ? $payload['rules'] : []);
        $this->_cfDropped = $dropped;

        $state = $this->_cfState();
        $state['match']   = ($payload['match'] ?? '') === 'any' ? 'any' : 'all';
        $state['rules']   = $rules;
        $state['viewId']  = (int) ($row['id'] ?? 0) ?: null;
        $state['touched'] = true;
        $this->customFilterState = $state;

        return $rules !== [];
    }

    /**
     * Filtro PADRÃO do usuário (pessoal > compartilhado), aplicado uma vez por
     * abertura: só com estado intocado e sem condições. Marca `touched` mesmo
     * sem padrão — os renders seguintes não voltam ao banco por isso.
     *
     * @return bool true = condições aplicadas (o caller recarrega)
     */
    protected function _cfApplyDefaultFilter(): bool
    {
        if ($this->customFilterDefs === []) {
            return false;
        }
        $state = $this->_cfState();
        if ($state['touched'] || $state['rules'] !== []) {
            return false;
        }
        $ctx = $this->_cfSavedContext();
        if ($ctx === null) {
            return false;
        }

        $row = $ctx['store']->defaultFor($ctx['gridKey'], $ctx['userId']);
        if ($row === null) {
            $state['touched'] = true;
            $this->customFilterState = $state;

            return false;
        }

        return $this->_cfUseSaved($row);
    }

    /** Lista de filtros salvos para a view (memo por requisição). */
    protected function _cfSavedList(?array $ctx): array
    {
        if ($ctx === null) {
            return ['mine' => [], 'shared' => []];
        }

        return $this->_cfSavedMemo ??= $ctx['store']->list($ctx['gridKey'], $ctx['userId'], $ctx['isAdmin']);
    }

    // ── Rótulos / view ───────────────────────────────────────────────────

    /**
     * Rótulos de uma lista de ids de uma def dbcombo/dbsearch (select usa as
     * opções declaradas; bool, Sim/Não) — para o chip mostrar o NOME, não o id.
     * dbcombo lê as options memoizadas do _buildFilterCaches (as mesmas da
     * view); dbsearch faz UM whereIn só dos ids pedidos. Chave fora das defs
     * não resolve nada. Prefixo `_`: público para o Blade, mas não é ação do
     * wire (o handler recusa métodos com `_`).
     *
     * @param  array<int, scalar> $ids
     * @return array<string, string> id => rótulo (só os encontrados)
     */
    public function _customFilterLabels(string $key, array $ids): array
    {
        $def = $this->_cfDefsByKey()[$key] ?? null;
        if ($def === null) {
            return [];
        }
        $ids = array_values(array_filter(array_map(
            fn ($v) => is_scalar($v) ? (string) $v : '',
            $ids
        ), fn ($v) => $v !== ''));
        if ($ids === []) {
            return [];
        }

        if ($def['kind'] === 'select') {
            return array_intersect_key((array) ($def['opts'] ?? []), array_flip($ids));
        }
        if ($def['kind'] === 'bool') {
            $out = [];
            foreach ($ids as $id) {
                $out[$id] = $id === (string) $def['true'] ? MadLang::t('mad.gridcf.yes') : MadLang::t('mad.gridcf.no');
            }
            return $out;
        }
        if (!in_array($def['kind'], ['dbcombo', 'dbsearch'], true) || ($def['model'] ?? '') === '') {
            return [];
        }

        // dbcombo: o mapa completo já é carregado (e memoizado por request) pelo
        // _buildFilterCaches — é o mesmo que a view usa, então não custa query.
        if ($def['kind'] === 'dbcombo') {
            [$options] = static::_buildFilterCaches([$this->_cfSourceColumn($def, 'dbcombo')], []);
            $all = (array) ($options[$key] ?? []);
            $found = [];
            foreach ($ids as $id) {
                if (array_key_exists($id, $all)) $found[$id] = (string) $all[$id];
            }
            return $found;
        }

        // dbsearch: whereIn batelado só dos ids pedidos (mesma resolução de
        // model/conexão do _buildFilterCaches), sem carregar a tabela.
        try {
            $model = class_exists($def['model'])
                ? $def['model']
                : \Mad\Form\ModelOptionsLoader::resolveModelClass($def['model']);
            $q = ($def['database'] ?? '') !== '' ? $model::on($def['database']) : $model::query();
            $items = \Mad\Form\ModelOptionsLoader::itemsFromQuery(
                $q->whereIn($def['keyField'] ?? 'id', array_slice($ids, 0, static::FILTER_IN_CAP)),
                $def['keyField'] ?? 'id',
                $def['display'] ?? 'nome',
                null
            ) ?: [];
        } catch (\Throwable $e) {
            @error_log('[MadDataGrid] rótulos do filtro avançado (' . $def['model'] . '): ' . $e->getMessage());
            return [];
        }
        $found = [];
        foreach ($items as $id => $label) {
            if (in_array((string) $id, $ids, true)) $found[(string) $id] = (string) $label;
        }

        return $found;
    }

    /**
     * GridColumn transitória com a fonte da def — é o que deixa o filtro
     * avançado reusar _buildFilterCaches (options memoizadas, token do
     * dbsearch, rótulos por whereIn) sem duplicar nada daquilo.
     */
    protected function _cfSourceColumn(array $def, string $kind, string $field = ''): GridColumn
    {
        $col = GridColumn::make($field !== '' ? $field : (string) $def['key'], (string) $def['label']);
        $col->filterKind      = $kind;
        $col->filterModel     = (string) ($def['model'] ?? '');
        $col->filterDisplay   = (string) ($def['display'] ?? 'nome');
        $col->filterKey       = (string) ($def['keyField'] ?? 'id');
        $col->filterDatabase  = (string) ($def['database'] ?? '');
        $col->filterOrderBy   = (string) ($def['orderBy'] ?? '');
        $col->filterOrder     = (string) ($def['order'] ?? '');
        $col->filterFilters   = (array) ($def['filters'] ?? []);
        $col->filterMinLength = (int) ($def['minLength'] ?? 2);

        return $col;
    }

    /** Rótulo natural de um operador para o tipo (`contém`, `é um dos`, `antes de`…). */
    public static function customFilterOpLabel(string $kind, string $op): string
    {
        $group = match ($kind) {
            'number'                         => 'number',
            'date', 'datetime'               => 'date',
            'bool'                           => 'bool',
            'select', 'dbcombo', 'dbsearch'  => 'choice',
            default                          => 'text',
        };
        $slug = self::CF_OP_SLUGS[$op] ?? '';

        return $slug === '' ? $op : MadLang::t('mad.gridcf.op.' . $group . '.' . $slug);
    }

    /** Rótulos dos períodos do `date preset` (id → texto), na ordem da allowlist. */
    public static function customFilterPresetLabels(): array
    {
        $out = [];
        foreach (static::DATE_PRESETS as $id) {
            $out[$id] = MadLang::t('mad.gridcf.preset.' . $id);
        }

        return $out;
    }

    /**
     * Dados do filtro avançado para o data-grid.blade.php. [] quando o grid
     * não tem `<mad-custom-filters>` (ou o selo não confere). Detalhes só do
     * servidor (model/display/filters/database) ficam de fora das defs.
     *
     * @param GridColumn[] $columns colunas da grade — o chip de uma condição
     *                              numérica mostra o valor como a coluna mostra
     *                              (dinheiro/casas); sem elas, formato do idioma.
     */
    protected function _customFiltersViewData(array $columns = []): array
    {
        $defs = $this->_cfDefs();
        if ($defs === []) {
            return [];
        }
        $cfg    = $this->_cfConfig();
        $state  = $this->_cfState();
        $active = $this->_cfActiveRules();
        $ctx    = $this->_cfSavedContext();

        // ── caches de fonte (dbcombo/dbsearch): options + token numa passada ──
        $cols = [];
        foreach ($defs as $def) {
            if (!in_array($def['kind'], ['dbcombo', 'dbsearch'], true) || ($def['model'] ?? '') === '') continue;
            // dbcombo: options completas (como o filtro de coluna dbcombo);
            // os dois tipos ganham token de busca lazy pro typeahead da UI.
            if ($def['kind'] === 'dbcombo') {
                $cols[] = $this->_cfSourceColumn($def, 'dbcombo');
                $cols[] = $this->_cfSourceColumn($def, 'dbsearch', $def['key'] . '#search');
            } else {
                $cols[] = $this->_cfSourceColumn($def, 'dbsearch');
            }
        }
        [$options, $tokens] = $cols !== [] ? static::_buildFilterCaches($cols, []) : [[], []];

        // Rótulos dos ids em uso nas condições (chips): um lote por def.
        $idsByKey = [];
        foreach ($active as [$def, $rule]) {
            if (!in_array($def['kind'], ['dbcombo', 'dbsearch'], true)) continue;
            foreach (is_array($rule['v']) ? $rule['v'] : [$rule['v']] as $id) {
                if (is_scalar($id) && (string) $id !== '') $idsByKey[$def['key']][] = (string) $id;
            }
        }
        $labels = [];
        foreach ($idsByKey as $k => $ids) {
            $labels[$k] = $this->_customFilterLabels($k, array_values(array_unique($ids)));
        }

        // ── defs públicas ──
        $viewDefs = [];
        foreach ($defs as $def) {
            $vd = [
                'key'         => $def['key'],
                'label'       => $def['label'],
                'kind'        => $def['kind'],
                'ops'         => $def['ops'],
                'opLabels'    => array_combine(
                    $def['ops'],
                    array_map(fn ($op) => static::customFilterOpLabel($def['kind'], $op), $def['ops'])
                ),
                'placeholder' => $def['placeholder'] ?? '',
            ];
            if ($def['kind'] === 'select') {
                $vd['opts'] = $def['opts'] ?? [];
            }
            if ($def['kind'] === 'bool') {
                $vd['true']  = $def['true'];
                $vd['false'] = $def['false'];
                $vd['opts']  = [$def['true'] => MadLang::t('mad.gridcf.yes'), $def['false'] => MadLang::t('mad.gridcf.no')];
            }
            if (in_array($def['kind'], ['dbcombo', 'dbsearch'], true)) {
                $vd['search']    = (string) ($tokens[$def['kind'] === 'dbcombo' ? $def['key'] . '#search' : $def['key']] ?? '');
                $vd['minLength'] = (int) ($def['minLength'] ?? 2);
                if ($def['kind'] === 'dbcombo') {
                    $vd['options'] = (array) ($options[$def['key']] ?? []);
                }
            }
            $viewDefs[] = $vd;
        }

        // ── regras com rótulos resolvidos (chips) ──
        // Coluna da grade por campo (normalizado como o das defs): é ela que diz
        // se "5000" aparece como "5.000,00" (dinheiro) ou "5.000".
        $colByField = [];
        foreach ($columns as $col) {
            if (!($col instanceof GridColumn)) continue;
            $f = MadGridCompiler::normalizeCustomFilterField($col->field);
            if ($f !== null && !isset($colByField[$f])) $colByField[$f] = $col;
        }

        $viewRules = [];
        foreach ($active as $i => [$def, $rule]) {
            $viewRules[] = $rule + [
                'index'      => $i,
                'label'      => $def['label'],
                'kind'       => $def['kind'],
                'opLabel'    => static::customFilterOpLabel($def['kind'], $rule['op']),
                'valueLabel' => $this->_cfValueLabel($def, $rule, (array) ($labels[$def['key']] ?? []),
                    $colByField[(string) $def['field']] ?? null),
            ];
        }

        $saved = $this->_cfSavedList($ctx);
        $viewName = '';
        foreach (array_merge($saved['mine'], $saved['shared']) as $item) {
            if ($item['id'] === $state['viewId']) { $viewName = $item['name']; break; }
        }

        return [
            'enabled'    => true,
            'label'      => $cfg['label'] !== '' ? $cfg['label'] : MadLang::t('mad.gridcf.button'),
            'match'      => $state['match'],
            'max'        => $cfg['max'],
            'defs'       => $viewDefs,
            'rules'      => $viewRules,
            'count'      => count($viewRules),
            'state'      => ['match' => $state['match'], 'rules' => array_map(fn ($p) => $p[1], $active),
                             'viewId' => $state['viewId'], 'touched' => $state['touched']],
            'viewId'     => $state['viewId'],
            'viewName'   => $viewName,
            // id → rótulo dos valores em uso (dbcombo/dbsearch), por chave: o
            // popover mostra NOMES nas etiquetas do rascunho, não ids.
            'labels'     => $labels,
            'dropped'    => $this->_cfDropped,
            'presets'    => static::customFilterPresetLabels(),
            'noValueOps' => self::CUSTOM_FILTER_NO_VALUE_OPS,
            'listOps'    => self::CUSTOM_FILTER_LIST_OPS,
            'rangeOps'   => self::CUSTOM_FILTER_RANGE_OPS,
            'save'       => $cfg['save'],
            'canSave'    => $ctx !== null,
            'canShare'   => $ctx !== null && $ctx['canShare'],
            'saved'      => $saved,
        ];
    }

    /**
     * Texto do valor de uma condição no chip (já com nomes, datas no locale e
     * números como a coluna da grade os mostra — "5.000,00", não "5000").
     */
    protected function _cfValueLabel(array $def, array $rule, array $labels, ?GridColumn $col = null): string
    {
        $op = $rule['op'];
        $v  = $rule['v'];
        if (in_array($op, self::CUSTOM_FILTER_NO_VALUE_OPS, true)) {
            return '';
        }
        if ($op === 'date preset') {
            return MadLang::t('mad.gridcf.preset.' . $v);
        }

        $numCol = $col ?? GridColumn::make((string) $def['field'], '');
        $one = function ($item) use ($def, $labels, $rule, $numCol): string {
            $s = (string) $item;
            return match ($def['kind']) {
                'select'              => (string) ($def['opts'][$s] ?? $s),
                'bool'                => $s === (string) $def['true'] ? MadLang::t('mad.gridcf.yes') : MadLang::t('mad.gridcf.no'),
                'dbcombo', 'dbsearch' => (string) ($labels[$s] ?? ($rule['d'] !== '' && !is_array($rule['v']) ? $rule['d'] : $s)),
                'date', 'datetime'    => static::_cfDateLabel($s),
                'number'              => static::filterValueLabel($numCol, $s, 'number'),
                default               => $s,
            };
        };

        if (in_array($op, self::CUSTOM_FILTER_RANGE_OPS, true)) {
            [$a, $b] = [(string) ($v[0] ?? ''), (string) ($v[1] ?? '')];
            $a = $a !== '' ? $one($a) : '';
            $b = $b !== '' ? $one($b) : '';
            if ($a !== '' && $b !== '') return $a . ' ' . MadLang::t('mad.gridcf.and') . ' ' . $b;
            if ($a !== '') return MadLang::t('mad.gridcf.from') . ' ' . $a;
            return MadLang::t('mad.gridcf.to') . ' ' . $b;
        }

        if (is_array($v)) {
            $names = array_map($one, $v);
            $head  = array_slice($names, 0, 3);
            $rest  = count($names) - count($head);
            return implode(', ', $head) . ($rest > 0 ? ' +' . $rest : '');
        }

        return $one($v);
    }

    /** `Y-m-d[ H:i:s]` → formato de exibição do locale (d/m/Y). */
    protected static function _cfDateLabel(string $v): string
    {
        $fmt = MadLang::t('mad.tempo.date_format');
        $dt  = strlen($v) > 10
            ? \DateTime::createFromFormat('Y-m-d H:i:s', $v)
            : \DateTime::createFromFormat('Y-m-d', $v);
        if (!$dt) {
            return $v;
        }

        return strlen($v) > 10 ? $dt->format($fmt . ' H:i') : $dt->format($fmt);
    }
}
