<?php
namespace Mad\Form;
use Mad\Component\MadComponentHandler;
use Mad\Http\MadResponse;


/**
 * FieldListColumn — Builder fluente de colunas para MadFieldList (mad-field-list).
 *
 * Uso:
 *   FieldListColumn::make('nome', 'Nome')->width('30%')->placeholder('Nome...')->required()
 *   FieldListColumn::make('valor', 'Valor')->type('number')->min(0)->step(0.01)->sum()
 *   FieldListColumn::make('status', 'Status')->type('select')->options(['a' => 'Ativo'])
 *   FieldListColumn::make('id')->type('hidden')
 */
class FieldListColumn
{
    public string $field        = '';
    public string $label        = '';
    public string $type         = 'text';   // text|number|numeric|money|discount|combo_input|date|email|tel|select|dbcombo|hidden|textarea|file|multifile|files|checkbox|toggle|radio
    public string $width        = '';       // CSS: '200px', '30%', '1fr'
    public string $placeholder  = '';
    public string $hint         = '';       // dica: tooltip no header + title na célula
    public string $default      = '';
    public array  $options      = [];       // ['val' => 'Label'] para type='select'
    public bool   $required     = false;
    public bool   $doSum        = false;    // somar na linha de totais
    public bool   $doCount      = false;    // contar na linha de totais
    public bool   $readonly     = false;
    public bool   $disabled     = false;
    public ?float $min          = null;
    public ?float $max          = null;
    public float  $step         = 1;
    public int    $decimals     = 2;       // casas decimais para type='money'
    public string $prefix       = '';      // prefixo visual para type='money' (ex: 'R$')
    public string $typeField    = '';      // campo companion para type='discount' (default: {field}_tipo)

    // ── Props exclusivas de type='file' / type='multifile' / type='files' ──
    public string $accept       = '';      // filtro de tipos: '.pdf,.jpg,image/*'
    public int    $maxSize      = 0;       // tamanho máximo em KB (0 = sem limite)
    public string $storage      = '';      // disk | db — onde gravar o arquivo da linha (default 'disk' no save)
    public string $folder       = 'uploads'; // pasta no disco quando storage='disk'
    public string $fileName     = 'prefix';  // estratégia do nome no disco: prefix|unique|original|record
    public string $nameColumn   = '';      // coluna do model filho p/ gravar o nome original do arquivo

    // ── type='files' (modal multi-arquivo → tabela NETO, 1 linha por arquivo) ──
    // Reusa $model (classe do neto), $storage, $folder, $fileName, $nameColumn.
    public string $foreignKey   = '';      // FK do neto apontando p/ a linha do field-list (id do item)
    public string $pathColumn   = '';      // coluna do neto p/ o path (disk) OU o BLOB base64 (db)

    // ── Props exclusivas de type='dbcombo' ──────────────────────────────────
    public string    $database  = '';
    public string    $model     = '';
    public string    $keyField  = 'id';
    public string    $display   = 'nome';
    public string    $orderBy   = '';
    /** Direção do `orderBy` ('asc'|'desc'; qualquer outra coisa vira asc). */
    public string    $orderDir  = 'asc';

    // ── Computed ──────────────────────────────────────────────────────────────
    public string $compute     = '';   // expressão JS: 'madCalcLineTotal(row)' — row disponível no escopo

    // ── Dependência entre colunas ─────────────────────────────────────────
    public string $dependsOn     = '';   // campo pai no form: nome do input cujo change dispara o reload (ex: 'produto_tipo_produto_id')
    public string $dependsColumn = '';   // coluna do model filho no WHERE (default = dependsOn). Use quando o campo pai e a coluna têm nomes diferentes.
    public string $where         = '';   // filtros estáticos: 'ativo=1|tipo=P' ou 'preco:>:0'

    // ── Atributos extras ──────────────────────────────────────────────────
    public string $attrs        = '';   // HTML attrs extras: 'data-mad-autocomplete="methods" autocomplete="off"'

    // ── Eventos ─────────────────────────────────────────────────────────────
    public string $onChange     = '';   // expressão Alpine: 'doSomething(row, $event)'

    // ── Estado condicional POR ROW (compilado pra bind Alpine) ──────────────
    // Sintaxe: "{campo} == 'valor'" — {campo} vira row['campo'] (compileCondition).
    // Sem {token}, a string passa crua como JS Alpine (row disponível no escopo).
    //   disabled-when="{field_type} != 'select'"  → :disabled="row['field_type'] != 'select'"
    public string $disabledWhen = '';   // desabilita o controle da célula
    public string $readonlyWhen = '';   // readonly (só controles de texto)
    public string $requiredWhen = '';   // required condicional
    public string $visibleWhen  = '';   // x-show na célula inteira (esconde, mas SUBMETE — x-show é só CSS)

    // ── Apresentação da célula (paridade com <mad-input-field>) ─────────────
    // Só valem nos tipos texto-ish (text/email/tel/textarea) — ver
    // FieldListColumn::supportsTextPresentation(). Em money/numeric/date o
    // runtime já tem formatação/popup próprios e um data-mad-mask por cima
    // brigaria com eles.
    public string $mask           = '';   // alias ('cpf','cnpj','cep'...) ou pattern (999.999-99)
    public bool   $stripMask      = false; // salva só dígitos/letras (aplicado no getFieldList)
    public string $forceCase      = '';   // upper | lower | title
    public string $icon           = '';   // lucide icon name
    public string $iconColor      = '';   // cor CSS do ícone
    public string $iconSide       = 'left'; // left | right
    public string $maxWidth       = '';   // limite do input DENTRO da célula (≠ width, que é o track da coluna)
    public ?int   $maxlength      = null;  // auto-setado por alias de máscara quando ausente
    public bool   $togglePassword = false; // inicia como password + botão olho

    // ── Agrupamento visual de colunas (<mad-field-list-group label="...">) ───
    // Colunas contíguas com o MESMO grupo viram uma banda acima do header.
    // $groupKey é setado pelo compilador (g0, g1, ...) pra que dois grupos
    // ADJACENTES com o mesmo rótulo não se fundam numa banda só; escrito à mão
    // (->group('Fiscal') sem key) o agrupamento cai no próprio rótulo.
    public string $group        = '';
    public string $groupKey     = '';

    // ── Construtor ─────────────────────────────────────────────────────────────

    public static function make(string $field, string $label = ''): self
    {
        $c        = new self();
        $c->field = $field;
        $c->label = $label;
        return $c;
    }

    // ── Fluent setters ──────────────────────────────────────────────────────────

    public function type(string $t): self         { $this->type = $t;                          return $this; }
    public function width(string $w): self        { $this->width = $w;                         return $this; }
    public function placeholder(string $p): self  { $this->placeholder = $p;                   return $this; }
    public function default(string $d): self      { $this->default = $d;                       return $this; }
    public function options(array|string $o): self { $this->options = self::normalizeOptions($o); return $this; }
    public function required(): self              { $this->required = true;                    return $this; }
    public function sum(): self                   { $this->doSum = true;                       return $this; }
    public function count(): self                 { $this->doCount = true;                     return $this; }
    public function readonly(): self              { $this->readonly = true;                    return $this; }
    public function disabled(): self              { $this->disabled = true;                    return $this; }
    public function min(float $v): self           { $this->min = $v;                           return $this; }
    public function max(float $v): self           { $this->max = $v;                           return $this; }
    public function step(float $v): self          { $this->step = $v;                          return $this; }
    public function decimals(int $v): self         { $this->decimals = $v;                      return $this; }
    public function prefix(string $v): self        { $this->prefix = $v;                        return $this; }
    public function typeField(string $v): self      { $this->typeField = $v;                     return $this; }
    public function database(string $v): self      { $this->database = $v;                     return $this; }
    public function model(string $v): self         { $this->model = $v;                        return $this; }
    public function keyField(string $v): self      { $this->keyField = $v;                     return $this; }
    public function display(string $v): self       { $this->display = $v;                      return $this; }
    public function orderBy(string $v): self       { $this->orderBy = $v;                      return $this; }
    public function orderDir(string $v): self      { $this->orderDir = $v;                     return $this; }
    public function accept(string $v): self         { $this->accept = $v;                        return $this; }
    public function maxSize(int $v): self            { $this->maxSize = $v;                       return $this; }
    public function storage(string $v): self         { $this->storage = $v;                       return $this; }
    public function folder(string $v): self          { $this->folder = $v;                        return $this; }
    public function fileName(string $v): self         { $this->fileName = $v;                      return $this; }
    public function nameColumn(string $v): self       { $this->nameColumn = $v;                    return $this; }
    public function foreignKey(string $v): self       { $this->foreignKey = $v;                    return $this; }
    public function pathColumn(string $v): self       { $this->pathColumn = $v;                    return $this; }
    public function attrs(string $v): self           { $this->attrs = $v;                         return $this; }
    public function compute(string $v): self       { $this->compute = $v;                      return $this; }
    public function dependsOn(string $v): self     { $this->dependsOn = $v;                    return $this; }
    public function dependsColumn(string $v): self { $this->dependsColumn = $v;                return $this; }
    public function where(string $v): self        { $this->where = $v;                       return $this; }
    public function onChange(string $v): self      { $this->onChange = $v;                     return $this; }
    public function disabledWhen(string $v): self  { $this->disabledWhen = $v;                 return $this; }
    public function readonlyWhen(string $v): self  { $this->readonlyWhen = $v;                 return $this; }
    public function requiredWhen(string $v): self  { $this->requiredWhen = $v;                 return $this; }
    public function visibleWhen(string $v): self   { $this->visibleWhen = $v;                  return $this; }
    public function hint(string $v): self          { $this->hint = $v;                         return $this; }
    public function group(string $v): self         { $this->group = $v;                        return $this; }
    public function groupKey(string $v): self      { $this->groupKey = $v;                     return $this; }
    public function mask(string $v): self          { $this->mask = $v;                         return $this; }
    public function stripMask(): self              { $this->stripMask = true;                  return $this; }
    public function forceCase(string $v): self     { $this->forceCase = $v;                    return $this; }
    public function icon(string $v): self          { $this->icon = $v;                         return $this; }
    public function iconColor(string $v): self     { $this->iconColor = $v;                    return $this; }
    public function iconSide(string $v): self      { $this->iconSide = $v;                     return $this; }
    public function maxWidth(string $v): self      { $this->maxWidth = $v;                     return $this; }
    public function maxlength(int $v): self        { $this->maxlength = $v;                    return $this; }
    public function togglePassword(): self         { $this->togglePassword = true;             return $this; }

    // ── Apresentação de célula ──────────────────────────────────────────────

    /**
     * O tipo da coluna aceita máscara / force-case / ícone / toggle-password?
     *
     * `date` tem popup + máscara de exibição próprios; `money`/`numeric`/
     * `discount`/`combo_input` já formatam no @input do Alpine. Empilhar um
     * `data-mad-mask` por cima disputaria o mesmo evento e embaralharia o
     * cursor — por isso a apresentação de texto só vale nos tipos texto-ish.
     *
     * `textarea` fica de fora porque tem branch próprio no template (não passa
     * pelo fallback que emite estes atributos): incluí-lo aqui prometeria no
     * painel algo que a célula não renderiza.
     */
    public function supportsTextPresentation(): bool
    {
        return in_array($this->type, ['text', 'email', 'tel', 'password'], true);
    }

    /**
     * `maxlength` efetivo: o autorado, ou o tamanho fixo do alias de máscara.
     *
     * Mesma tabela do <mad-input-field> — ponto único em
     * \Mad\Support\MadMask::aliasMaxLength().
     */
    public function effectiveMaxlength(): ?int
    {
        if ($this->maxlength !== null) {
            return $this->maxlength;
        }

        return $this->mask !== '' ? \Mad\Support\MadMask::aliasMaxLength($this->mask) : null;
    }

    /** Normaliza o lado do ícone ('right' ou 'left'). */
    public function iconSideNormalized(): string
    {
        return strtolower($this->iconSide) === 'right' ? 'right' : 'left';
    }

    /**
     * `style` do input da célula — hoje só o `max-width` da coluna.
     *
     * Mora AQUI, e não inline no field-list.blade.php, porque o
     * `FieldDimStyleTest` monta seu data provider procurando `CssUnits::dim`
     * nos blades de componente: um componente que contém a chamada declara que
     * honra `width`/`max-width` DELE PRÓPRIO, e o teste cobra isso. O
     * `<mad-field-list>` não tem dimensão própria (é bloco full-width) — o
     * `max-width` aqui é da CÉLULA de uma coluna. Deixar a chamada no template
     * fazia o componente entrar num contrato que ele não implementa.
     *
     * `width` fica de fora de propósito: ele é o track da coluna no
     * `grid-template-columns` (ver cssTrack), não largura do input.
     */
    public function cellDimStyle(): string
    {
        return \Mad\Support\CssUnits::dim('', $this->maxWidth);
    }

    /** Aplica o force-case declarado (upper/lower/title) — espelho do client. */
    public function applyForceCase(string $value): string
    {
        return match (strtolower($this->forceCase)) {
            'upper' => mb_strtoupper($value),
            'lower' => mb_strtolower($value),
            'title' => mb_convert_case(mb_strtolower($value), MB_CASE_TITLE, 'UTF-8'),
            default => $value,
        };
    }

    // ── Condições por-row (disabled-when / readonly-when / ...) ─────────────────

    /**
     * Compila uma condição por-row pra expressão Alpine.
     *
     *   "{field_type} == 'select'"   → "row['field_type'] == 'select'"
     *   "{qtd} > 0 && {ativo}"       → "row['qtd'] > 0 && row['ativo']"
     *   "row['x'] === 'y'"           → passa cru (sem {token} = JS raw)
     *
     * Avaliada no escopo do x-for do field-list, onde `row` existe. Espelha o
     * açúcar do compute ({campo}), mas SEM o wrap numérico do compileFormula —
     * condição compara strings na maioria dos casos.
     */
    public static function compileCondition(string $expr): string
    {
        if (!preg_match('/\{[a-zA-Z_]\w*\}/', $expr)) {
            return $expr; // JS raw — backward compat com quem já escreve row[...]
        }

        return preg_replace('/\{([a-zA-Z_]\w*)\}/', "row['$1']", $expr);
    }

    // ── Largura → track de grid ─────────────────────────────────────────────────

    /**
     * Normaliza a largura autorada de uma coluna num track válido de
     * `grid-template-columns`.
     *
     * `width="140"` (px implícito) é o contrato DOCUMENTADO do atributo — o
     * `<mad-grid>` já sufixava `px` no compilador, o field-list não, e o valor
     * cru vazava pro CSS. Isso é fatal e não parcial: um único track inválido
     * invalida a DECLARAÇÃO INTEIRA, o browser descarta o
     * `grid-template-columns` todo, o `display:grid` cai numa coluna implícita e
     * a lista inteira empilha — header virando lista de rótulos, um input por
     * linha.
     *
     * Por isso o irreconhecível degrada pra `1fr` em vez de passar cru: perder o
     * dimensionamento de UMA coluna é infinitamente melhor que perder o layout
     * do componente. Quem escreveu errado recebe aviso do compilador
     * (MadGridCompiler::compileFieldListBlock).
     *
     * Delega pra \Mad\Support\CssUnits::track() — ponto único compartilhado com
     * o <mad-form-grid>, o <mad-grid> e os demais. Mantido como método aqui
     * porque o blade e o compilador já chamam por este nome.
     */
    public static function cssTrack(string $w): string
    {
        return \Mad\Support\CssUnits::track($w);
    }

    // ── Normalização de options ─────────────────────────────────────────────────

    /**
     * Converte qualquer forma aceita de options no MAPA que o runtime consome
     * (`@foreach($col->options as $optVal => $optLabel)` em field-list.blade.php).
     *
     * Formas aceitas:
     *   ['text' => 'Texto curto']                          mapa canônico
     *   [['value' => 'text', 'label' => 'Texto curto']]    lista value/label
     *   '[{"value":"text","label":"Texto curto"}]'         JSON (editor visual:
     *                                                      OptionsMapEditor emite
     *                                                      isto em discount/combo_input)
     *   'text:Texto curto,date:Data'                       compacta (mesma gramática
     *                                                      de SheetColumn::parseStaticOptions)
     *
     * Entrada irreconhecível degrada pra [] — select vazio em vez de fatal. O
     * caminho canônico continua sendo `:options="['text' => 'Texto curto']"`;
     * as demais existem porque o compilador do Blade e o editor visual já
     * produziam string, e um TypeError aqui derruba a VIEW INTEIRA.
     */
    public static function normalizeOptions(array|string $o): array
    {
        if (is_string($o)) {
            $raw = trim($o);
            if ($raw === '') {
                return [];
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::normalizeOptions($decoded);
            }

            return self::parseCompactOptions($raw);
        }

        $out = [];
        foreach ($o as $key => $value) {
            // Linha value/label (JSON do editor visual, ou lista de mapas).
            if (is_array($value)) {
                $v = $value['value'] ?? $value['id'] ?? $value['key'] ?? null;
                $l = $value['label'] ?? $value['text'] ?? $value['name'] ?? $v;
                if ($v !== null && ! is_array($v)) {
                    $out[(string) $v] = is_scalar($l) ? (string) $l : (string) $v;
                }
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }

    /** "1:Ativo,2:Inativo" → [1 => 'Ativo', 2 => 'Inativo']. */
    private static function parseCompactOptions(string $raw): array
    {
        $out = [];
        foreach (explode(',', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            [$value, $label] = array_pad(explode(':', $pair, 2), 2, null);
            $value = trim((string) $value);
            $label = $label === null ? $value : trim($label);
            if ($value !== '') {
                $out[$value] = $label;
            }
        }

        return $out;
    }

    // ── Compilação de fórmulas compute ──────────────────────────────────────────

    /**
     * Compila uma expressão compute para JavaScript válido no x-effect do Alpine.
     *
     * Se contém {campo}, é fórmula simplificada — compilada para JS:
     *   "{quantidade} * {valor}"               → parseFloat(row['quantidade']||0) * parseFloat(row['valor']||0)
     *   "max({a} * {b} - {c}, 0)"              → Math.max(... , 0)
     *   "round({a} * {b}, 2)"                  → _madRound(... , 2)
     *   "if({ativo}, {valor}, 0)"              → ((ativo) ? (valor) : (0))
     *   "discount({qtd} * {preco}, {desc}, {desc_tipo})"  → _madDiscount(subtotal, desc, tipo_string)
     *
     * Funções: round, max, min, abs, floor, ceil, if, discount
     *
     * Se NÃO contém {campo}, retorna como JS raw (backward compat).
     *
     * @param string $expr Expressão compute
     * @return string      JS para x-effect
     */
    public static function compileFormula(string $expr): string
    {
        // Se não contém {campo}, é JS raw — retorna direto
        if (!preg_match('/\{[a-zA-Z_]\w*\}/', $expr)) {
            return $expr;
        }

        $js = $expr;

        // ── discount(subtotal_expr, {valor_desc}, {tipo_desc}) ──────────
        // O 3o arg é campo string (%, R$) — NÃO pode virar parseFloat.
        // Regex greedy no 1o arg para suportar expressões complexas.
        $js = preg_replace_callback(
            '/\bdiscount\s*\((.+),\s*\{(\w+)\}\s*,\s*\{(\w+)\}\s*\)/i',
            fn($m) => "_madDiscount({$m[1]}, parseFloat(row['{$m[2]}']||0), (row['{$m[3]}']||'%'))",
            $js
        );

        // ── Funções matemáticas ──────────────────────────────────────────
        $js = preg_replace('/\bround\s*\(/i', '_madRound(', $js);
        $js = preg_replace('/\bmax\s*\(/i', 'Math.max(', $js);
        $js = preg_replace('/\bmin\s*\(/i', 'Math.min(', $js);
        $js = preg_replace('/\babs\s*\(/i', 'Math.abs(', $js);
        $js = preg_replace('/\bfloor\s*\(/i', 'Math.floor(', $js);
        $js = preg_replace('/\bceil\s*\(/i', 'Math.ceil(', $js);

        // ── if(cond, then, else) → ternário ─────────────────────────────
        $js = preg_replace_callback(
            '/\bif\s*\(\s*(.+?)\s*,\s*(.+?)\s*,\s*(.+?)\s*\)/i',
            fn($m) => '((' . $m[1] . ') ? (' . $m[2] . ') : (' . $m[3] . '))',
            $js
        );

        // ── {campo} → parseFloat(row['campo']||0) ───────────────────────
        $js = preg_replace_callback('/\{([a-zA-Z_]\w*)\}/', function ($m) {
            return "parseFloat(row['" . $m[1] . "']||0)";
        }, $js);

        return $js;
    }

    // ── Serialização para Alpine (JSON) ─────────────────────────────────────────

    public function toArray(): array
    {
        return [
            'field'       => $this->field,
            'label'       => $this->label,
            'type'        => $this->type,
            'width'       => $this->width,
            'placeholder' => $this->placeholder,
            'default'     => $this->default,
            'options'     => $this->options,
            'required'    => $this->required,
            'doSum'       => $this->doSum,
            'doCount'     => $this->doCount,
            'readonly'    => $this->readonly,
            'disabled'    => $this->disabled,
            'min'         => $this->min,
            'max'         => $this->max,
            'step'        => $this->step,
            'decimals'    => $this->decimals,
            'prefix'      => $this->prefix,
            'typeField'   => $this->typeField,
            'accept'      => $this->accept,
            'maxSize'     => $this->maxSize,
        ];
    }

    // ── Respostas para onChange em field-list (usados com data-mad-fl-change) ──

    /**
     * Popula um <select> na mesma row do field-list com options do banco.
     * Retorna MadResponse com op 'fl_combo' — processado pelo JS do field-list.
     *
     * Uso no onChange do componente:
     *   public function onChangeTipo($value) {
     *       return FieldListColumn::loadOptions('produto_id', 'Produto', 'id', 'nome', 'nome');
     *   }
     *
     * @param string          $target   Nome do campo-alvo (ex: 'produto_id')
     * @param string          $model    Classe do model Eloquent (a conexão vem do model)
     * @param string          $key      Campo PK (ex: 'id')
     * @param string          $display  Campo a exibir (ex: 'nome')
     * @param string|null     $orderBy  Ordenação (null = display)
     * @return MadResponse
     */
    public static function loadOptions(
        string      $target,
        string      $model,
        string      $key      = 'id',
        string      $display  = 'nome',
        ?string     $orderBy  = null
    ): MadResponse {
        $items = \Mad\Form\ModelOptionsLoader::items(
            $model, $key, $display, $orderBy
        );

        $response = new MadResponse();
        $response->ops[] = [
            'op'      => 'fl_combo',
            'target'  => $target,
            'options' => $items,
        ];
        return $response;
    }

    /**
     * Popula um <select> na mesma row com options já prontas (array PHP).
     *
     * @param string $target  Nome do campo-alvo
     * @param array  $options ['val' => 'Label', ...]
     * @return MadResponse
     */
    public static function loadOptionsArray(string $target, array $options): MadResponse
    {
        $response = new MadResponse();
        $response->ops[] = [
            'op'      => 'fl_combo',
            'target'  => $target,
            'options' => $options,
        ];
        return $response;
    }

    /**
     * Define o valor de um campo (input/select/textarea) na mesma row do field-list.
     * Também atualiza o Alpine x-model para manter o estado reativo sincronizado.
     *
     * Uso no onChange do componente:
     *   public function onChangeProduto($value) {
     *       $produto = new \Produto($value);
     *       return FieldListColumn::setValue('valor', $produto->preco)
     *                ->merge(FieldListColumn::setValue('unidade', $produto->unidade));
     *   }
     *
     * @param string $target Nome do campo-alvo (ex: 'valor', 'descricao')
     * @param mixed  $value  Valor a definir
     * @return MadResponse
     */
    public static function setValue(string $target, mixed $value): MadResponse
    {
        $response = new MadResponse();
        $response->ops[] = [
            'op'      => 'fl_val',
            'target'  => $target,
            'content' => (string) $value,
        ];
        return $response;
    }

    /**
     * Substitui todas as rows de um field-list nomeado via Alpine (sem re-render).
     *
     * Uso:
     *   return FieldListColumn::setRows('parcelas', $this->parcelas);
     *
     * @param string $target Nome do field-list (atributo name do componente)
     * @param array  $rows   Rows a definir (serão normalizadas com __id)
     * @return MadResponse
     */
    public static function setRows(string $target, array $rows): MadResponse
    {
        $response = new MadResponse();
        $response->ops[] = [
            'op'     => 'fl_rows',
            'target' => $target,
            'rows'   => self::normalizeRows($rows),
        ];
        return $response;
    }

    // ── Helpers estáticos ───────────────────────────────────────────────────────

    /**
     * Normaliza rows vindas do banco (stdClass, model Eloquent, array) para array de arrays.
     * Garante que cada row tenha '__id' para estabilidade do x-for :key no Alpine.
     *
     * @param  array $rows  Array de stdClass|array|object
     * @return array        [ ['field' => 'val', ..., '__id' => 'uid'], ... ]
     */
    public static function normalizeRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (is_object($row)) {
                $arr = method_exists($row, 'toArray') ? $row->toArray() : (array) $row;
            } else {
                $arr = (array) $row;
            }
            if (empty($arr['__id'])) {
                $arr['__id'] = uniqid('row_', true);
            }
            $out[] = $arr;
        }
        return $out;
    }

    /**
     * Gera N linhas com valores pré-preenchidos a partir de um callback.
     *
     * Cada iteração recebe o índice (0-based) e deve retornar um array associativo
     * com os valores de cada campo. Ideal para gerar parcelas, itens calculados, etc.
     *
     * Exemplo — gerar 12 parcelas de pagamento:
     *   $this->parcelas = FieldListColumn::buildRows(12, function(int $i) use ($valorParcela, $dataBase) {
     *       return [
     *           'parcela'    => $i + 1,
     *           'valor'      => round($valorParcela, 2),
     *           'vencimento' => date('Y-m-d', strtotime("+".($i+1)." months", strtotime($dataBase))),
     *       ];
     *   });
     *
     * @param  int      $count    Número de linhas a gerar
     * @param  callable $callback fn(int $index): array — retorna os campos de cada linha
     * @return array              Rows normalizadas prontas para o field-list (com __id)
     */
    public static function buildRows(int $count, callable $callback): array
    {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = $callback($i);
        }
        return self::normalizeRows($rows);
    }

    /**
     * Limpa todas as linhas de um array de rows.
     *
     * Exemplo:
     *   $this->parcelas = FieldListColumn::clearRows();
     *
     * @return array Array vazio
     */
    public static function clearRows(): array
    {
        return [];
    }

    /**
     * Remove uma linha pelo índice (0-based).
     *
     * Exemplo:
     *   $this->parcelas = FieldListColumn::removeRow($this->parcelas, 2); // remove a 3ª linha
     *
     * @param  array $rows  Rows atuais
     * @param  int   $index Índice da linha a remover (0-based)
     * @return array        Rows sem a linha removida (re-indexado)
     */
    public static function removeRow(array $rows, int $index): array
    {
        if (isset($rows[$index])) {
            unset($rows[$index]);
        }
        return array_values($rows);
    }

    /**
     * Atualiza campos de uma linha pelo índice (0-based).
     * Faz merge dos dados — campos não informados são preservados.
     *
     * Exemplo:
     *   $this->parcelas = FieldListColumn::updateRow($this->parcelas, 0, [
     *       'valor'      => 150.00,
     *       'vencimento' => '2026-06-01',
     *   ]);
     *
     * @param  array $rows  Rows atuais
     * @param  int   $index Índice da linha a editar (0-based)
     * @param  array $data  Campos a atualizar (merge, não substitui a row inteira)
     * @return array        Rows com a linha atualizada
     */
    public static function updateRow(array $rows, int $index, array $data): array
    {
        if (isset($rows[$index])) {
            $rows[$index] = array_merge($rows[$index], $data);
        }
        return $rows;
    }

    // ── Where string → builder ─────────────────────────────────────────────

    /**
     * Parseia uma string de filtros e aplica no Query Builder fornecido.
     * Formato: "campo=valor|campo2:>:valor2|ativo:in:1,2,3"
     *
     * Operadores suportados: =, !=, >, <, >=, <=, LIKE, in, not in
     * Padrão: = (quando só campo=valor)
     */
    /** Versão builder (F5) — aplica where="a=1|b:>:2|c:in:1,2" num Eloquent/Query Builder. */
    public static function applyWhereStringToQuery($q, string $where): void
    {
        if (!$where) return;
        foreach (explode('|', $where) as $part) {
            $part = trim($part);
            if (!$part) continue;

            $segs = explode(':', $part, 3);
            if (count($segs) === 3) {
                $op = strtolower(trim($segs[1]));
                if ($op === 'in') {
                    $q->whereIn($segs[0], array_map('trim', explode(',', $segs[2])));
                } elseif ($op === 'not in') {
                    $q->whereNotIn($segs[0], array_map('trim', explode(',', $segs[2])));
                } else {
                    $q->where($segs[0], $segs[1], $segs[2]);
                }
            } else {
                $kv = explode('=', $part, 2);
                if (count($kv) === 2) {
                    $q->where($kv[0], '=', $kv[1]);
                }
            }
        }
    }

    // ── Auto-load dependente (Camada 3: chamado via _madFlDependentOptions) ──

    /**
     * Carrega options filtradas pelo valor do campo pai (depends-on).
     * Chamado internamente pelo MadComponentHandler quando o JS detecta
     * mudança em um campo que tem dependentes.
     *
     * SEGURANCA: a config da query (model, database, key, display, order,
     * column, where do dev) NUNCA vem do cliente. Ela e
     * criptografada no render do field-list.blade.php via
     * MadStateCrypt::encrypt() e embutida no DOM como data-mad-fl-dep-token.
     * O cliente envia apenas { target, token, value }. Mesma arquitetura do
     * <mad-dbcombo-field> (ver MadDbComboService::load).
     *
     * @param  array $params {target, token, value}
     * @return MadResponse   Resposta com fl_combo para o target
     */
    public static function _autoLoadDependentOptions(array $params): MadResponse
    {
        $target = (string)($params['target'] ?? '');
        $token  = (string)($params['token']  ?? '');
        $value  = (string)($params['value']  ?? '');

        if ($token === '' || $value === '') {
            return self::loadOptionsArray($target, []);
        }

        $config = \Mad\Http\MadStateCrypt::decryptFor('field-list-dep', $token);
        if (!is_array($config)) {
            return self::loadOptionsArray($target, []);
        }

        // O `database` do token não é lido: a cascata roda na conexão do
        // próprio model, igual à carga inicial das opções no field-list.blade.
        // O render grava ali o fallback 'business' quando a coluna não declara
        // banco — honrá-lo mandaria a consulta para outra conexão. (Até a
        // 5.96.3 este ponto lia `$config['database'] ?? MAIN_DATABASE`, valor
        // nunca usado; um token sem a chave derrubava a cascata com a
        // constante indefinida.)
        $model    = (string)($config['model']    ?? '');
        $key      = (string)($config['key']      ?? 'id');
        $display  = (string)($config['display']  ?? 'nome');
        $order    = (string)($config['order']    ?? '');
        // Token antigo não traz a direção → asc, idêntico ao de antes.
        $orderDir = (string)($config['order_dir'] ?? 'asc');
        $column   = (string)($config['column']   ?? '');
        $where    = (string)($config['where']    ?? '');

        if ($model === '' || $column === '') {
            return self::loadOptionsArray($target, []);
        }

        // builder-native — where="ativo=1|..." estático + filtro da cascata no Query Builder.
        $__m = class_exists($model) ? $model : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
        $__q = $__m::query();
        self::applyWhereStringToQuery($__q, $where);
        $__q->where($column, '=', $value);

        $items = \Mad\Form\ModelOptionsLoader::itemsFromQuery($__q, $key, $display, $order ?: null, $orderDir);

        $response = new MadResponse();
        $response->ops[] = [
            'op'      => 'fl_combo',
            'target'  => $target,
            'options' => $items,
        ];
        return $response;
    }

}