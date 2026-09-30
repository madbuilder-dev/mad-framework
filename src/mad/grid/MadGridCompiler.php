<?php
namespace Mad\Grid;
use Mad\Component\MadRenderContext;
use Mad\Form\FieldListColumn;
use Mad\Http\MadResponse;
use Mad\Ui\MadToast;
use Mad\View\MadBlade;


/**
 * MadGridCompiler — compila <mad-grid>...</mad-grid> para PHP puro em compile-time.
 *
 * Chamado por MadBladeOne::compileString() ANTES do BladeOne processar qualquer coisa,
 * portanto as tags nunca chegam ao sistema de componentes Blade.
 *
 * ┌── Sintaxe suportada ────────────────────────────────────────────────────────┐
 * │                                                                              │
 * │  <mad-grid> — Atributos do grid                                             │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  model="Pessoa"           Classe do model Eloquent para auto-query                    │
 * │  per-page="15"            Registros por página                              │
 * │  self                     Grid inline (colunas definidas no Blade)          │
 * │  searchable               Barra de busca rápida                             │
 * │  :search-columns="[...]"  Campos para busca (notação ponto: 'rel.campo')   │
 * │  sticky                   Thead fixo ao rolar                               │
 * │  no-export                Desabilita botões de exportação                   │
 * │  no-auto-load             Abre vazio; consulta só após Buscar/filtro/sort   │
 * │  require-filter           Abre vazio e só consulta com ao menos 1 filtro    │
 * │  require-filter-fields="a,b"  Só estes campos liberam a consulta            │
 * │  no-load-button           Oculta o botão "Carregar registros" (vazio)       │
 * │  load-hint="..."          Texto da listagem vazia (no-auto-load/require)    │
 * │  action-side="left"       Coluna de ações à esquerda (default: right)       │
 * │  group-by="ano"           Campo(s) para agrupamento                         │
 * │  :group-mask="'{ano}'"    Template de exibição do grupo                     │
 * │  group-total              Subtotais por grupo                               │
 * │  card-view               Habilita visualização em cards                     │
 * │  card-default             Cards como visualização padrão                    │
 * │  card-cols="3"            Colunas do grid de cards (default: 3)             │
 * │  selectable               Coluna de seleção (checkbox) + ações em lote      │
 * │                                                                              │
 * │  <mad-bulk-action> — Ação em lote (exige <mad-grid selectable>)             │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  Botão na barra de seleção do grid (contador "N selecionados" + Limpar).     │
 * │  Marca-se a linha no checkbox (o do cabeçalho marca a página inteira); a    │
 * │  seleção sobrevive a paginação, ordenação, busca, filtro e à reabertura da   │
 * │  tela (espelhada na sessão por classe do grid).                              │
 * │                                                                              │
 * │  method="onQuitarLote"    Chama o método do GRID: onQuitarLote(array $ids)  │
 * │  target="QuitarLoteForm"  Abre a tela (drawer/modal/página, como mad-nav)   │
 * │                           com o param `ids` ("1,2,3"); aceita Classe::metodo│
 * │  navigate="…"             Alias de target                                    │
 * │  label="Quitar em lote"   Texto (ou o corpo da tag)                         │
 * │  icon="check"             Ícone Lucide                                      │
 * │  variant="primary"        primary (default) | danger | ghost | secondary    │
 * │  confirm="Quitar?"        Confirmação antes (":count" = nº selecionados)    │
 * │  min="1"                  Mínimo de linhas marcadas (default 1; 0 = sempre) │
 * │                                                                              │
 * │  Seleção no servidor — formato [id => id] (foreach / whereIn / isset):       │
 * │    $this->selectedIds()                        no próprio grid              │
 * │    $this->clearSelection() / setSelectedIds()  no próprio grid              │
 * │    MadGridSelection::get(ContaList::class)     de QUALQUER tela             │
 * │    MadGridSelection::fromParams($params)       o `ids` recebido pelo target │
 * │    MadGridSelection::clear(ContaList::class)   depois de processar          │
 * │                                                                              │
 * │  <mad-grid self selectable>                                                 │
 * │      <mad-col field="id" label="Cód." />                                    │
 * │      <mad-bulk-action target="QuitarLoteForm" icon="check"                  │
 * │                       label="Quitar em lote" />                             │
 * │      <mad-bulk-action method="onExcluirLote" icon="trash-2" danger          │
 * │                       label="Excluir" confirm="Excluir :count registros?" />│
 * │  </mad-grid>                                                                │
 * │                                                                              │
 * │  <mad-custom-filters> — Filtro avançado do usuário final                    │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  O dev declara QUAIS colunas o usuário pode filtrar; em runtime o usuário    │
 * │  monta linhas [Coluna][Operador][Valor], combinadas por "todas" (AND) ou    │
 * │  "qualquer uma" (OR), e salva filtros com nome (seus ou compartilhados).     │
 * │  Campo/operador nunca vêm do cliente: a chave da regra aponta pra uma def   │
 * │  desta allowlist, e o operador precisa estar na lista do tipo.              │
 * │                                                                              │
 * │  save="shared|user|off"   Filtros salvos (default shared; off desliga)      │
 * │  match="all|any"          Combinação inicial (default all)                  │
 * │  max="15"                 Máximo de condições (teto duro 20)                │
 * │  share="admin|everyone"   Quem pode compartilhar (default admin)            │
 * │  label="Filtros"          Texto do botão (default traduzido)                │
 * │                                                                              │
 * │  <mad-custom-filter field="{cidade->nome}" label="Cidade" />                │
 * │    field       coluna, ref {x} ou chain {a->b->c} (até 3 saltos)            │
 * │    type        text|number|date|datetime|bool|select|dbcombo|dbsearch      │
 * │    ops="=,in"  restringe os operadores do tipo (intersecção)                │
 * │    opts / :opts        select — "A:Ativo|I:Inativo" ou expressão PHP        │
 * │    true / false        bool — valores gravados (default 1/0)                │
 * │    model / display / key / order-by / database / :filters / min-length      │
 * │                        fonte do dbcombo/dbsearch (mesmo vocabulário do       │
 * │                        <mad-col filter-*>)                                   │
 * │    placeholder                                                               │
 * │                                                                              │
 * │  <mad-col> — Colunas                                                        │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  field="id"               Campo do model (suporta {relacao->campo})         │
 * │  label="Cód."             Texto do cabeçalho                                │
 * │  width="60"               Largura em px                                     │
 * │  center / right           Alinhamento                                       │
 * │  sort                     Ordenação por clique                              │
 * │  filter                   Filtro simples (text/select/date)                 │
 * │  col-filter="="           Filtro de coluna seguro (operador: =, like, >=)   │
 * │  money="R$"               Formato monetário com prefixo                     │
 * │  date="d/m/Y"             Formato de data                                  │
 * │  total="sum"              Totaliza no rodapé (sum, avg, count, min, max)    │
 * │  badge="A:success:Ativo"  Badge colorido por valor                          │
 * │  :badge="$badgeMap"       Badge via expressão PHP                           │
 * │  hide / hidden            Coluna oculta                                     │
 * │  edit                     Edição inline                                     │
 * │  edit-type="money"        Tipo de edição (text, money, select, date...)     │
 * │  edit-mode="click"        Modo de edição (dblclick, click, inline)          │
 * │  transform="Cls::metodo"  Transforma valor exibido (recebe $value, $row)    │
 * │  display-condition="Cls::metodo"  Mostra/esconde coluna (retorna bool)      │
 * │                                                                              │
 * │  filter-type="dbcombo"    Filtro TIPADO seguro (recomendado) — ver abaixo   │
 * │                                                                              │
 * │  <mad-col filter-type> — Filtro tipado seguro (contrato do editor)          │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  Campo e operador viajam num TOKEN ASSINADO cunhado no render; o cliente     │
 * │  manda só o valor. Por isso `in`/`between` existem sem abrir injeção — e     │
 * │  por isso o operador nunca sai errado como no `filter` legado.               │
 * │                                                                              │
 * │  Tipo            op default      SQL                                        │
 * │  text            like            col LIKE '%v%'                             │
 * │  select          =               col = ?                                    │
 * │  dbcombo         =               col = ?   (options carregadas da tabela)    │
 * │  dbsearch        =               col = ?   (busca lazy server-side)          │
 * │  multi           in              col IN (?,?)                               │
 * │  date            date            whereDate(col,'=',?)                       │
 * │  date-range      date between    whereBetween c/ fronteiras de dia          │
 * │  number          =               col = ?                                    │
 * │  number-range    between         whereBetween(col,[?,?])                    │
 * │  bool            =               col = ?   (filter-true / filter-false)     │
 * │                                                                              │
 * │  filter-op="="            Sobrescreve o operador (allowlist POR TIPO;        │
 * │                           valor fora da lista cai no default do tipo)        │
 * │  filter-field="estado_id" Coluna real filtrada (default: o field da coluna)  │
 * │  filter-opts="A:Ativo|I:Inativo"    Opções fixas (shorthand)                 │
 * │  :filter-opts="['A'=>'Ativo']"      Opções fixas (expressão PHP)             │
 * │  filter-model / filter-display / filter-key / filter-database                │
 * │  filter-order-by / :filter-filters  Fonte e regras de carregamento           │
 * │  filter-min-length="3"    dbsearch: chars mínimos pra buscar                 │
 * │  filter-true="S" filter-false="N"   bool: valores gravados no banco          │
 * │                                                                              │
 * │  <mad-col field="{estado->nome}" label="Estado"                             │
 * │           filter-type="dbcombo" filter-field="estado_id"                    │
 * │           filter-model="Estado" filter-display="{nome} ({sigla})"           │
 * │           :filter-filters="[['ativo','=',1]]" />                            │
 * │                                                                              │
 * │  <mad-col-filter> — Filtro avançado (dentro de mad-col)                     │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  Açúcar equivalente às props acima (gera o MESMO config):                    │
 * │      <mad-col-filter type="dbcombo" field="estado_id" model="Estado" />      │
 * │                                                                              │
 * │  Ou, com corpo livre, a UI do filtro é o HTML que você escrever — nesse      │
 * │  caso o corpo VENCE e o `type=` é ignorado:                                  │
 * │  <mad-col field="{estado->nome}" label="Status">                            │
 * │      <mad-col-filter op="=" field="estado_id">                              │
 * │          <mad-dbcombo-field name="filter_value" model="Estado" ... />        │
 * │      </mad-col-filter>                                                      │
 * │  </mad-col>                                                                 │
 * │                                                                              │
 * │  <mad-nav> — Navegação (abre outra tela, NÃO faz AJAX no grid)             │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  Abre outro controller. NÃO chama método do grid.                           │
 * │  Auto-detecta se destino é DRAWER/MODAL → usa Mad.overlay().                │
 * │                                                                              │
 * │  target="PessoaForm"      Classe destino (obrigatório)                      │
 * │  icon="pencil"            Ícone Lucide                                      │
 * │  label="Editar"           Texto do botão                                    │
 * │  drawer                   Força abertura como drawer                        │
 * │  row                      Abre o alvo ANEXADO à linha (quick-edit inline;  │
 * │                           toggle no clique; some em sort/filtro/paginação; │
 * │                           em card-view cai no comportamento overlay).       │
 * │                           O form NÃO muda: $wrapper segue sendo o padrão   │
 * │                           standalone; a view pode se adaptar ao contexto   │
 * │                           com $that->isRowAttach() (layout compacto)        │
 * │  method="onEdit"          Método no destino (default: show)                 │
 * │  confirm="Tem certeza?"   Confirmação antes de navegar                      │
 * │  display-condition="Cls::metodo"  Exibição condicional                      │
 * │                                                                              │
 * │  Parâmetros do registro — duas sintaxes:                                    │
 * │                                                                              │
 * │  1. navigate inline (concisa):                                              │
 * │     navigate="Classe::metodo({campo1}, {campo2})"                           │
 * │     {campo} é substituído pelo valor do registro (sintaxe de render legada)                 │
 * │                                                                              │
 * │  2. :params JSON (nomeados):                                                │
 * │     :params="{'param_name': '{campo}', 'fixo': 'valor'}"                   │
 * │     {campo} → valor do registro, sem {} → valor literal                    │
 * │     :params tem prioridade sobre params inline do navigate                  │
 * │                                                                              │
 * │  Sem params → envia {id: row.id} (compatível com código existente)          │
 * │                                                                              │
 * │  Exemplos:                                                                  │
 * │  <mad-nav icon="pencil" label="Editar" target="PessoaForm" />              │
 * │  <mad-nav icon="eye" label="Ver" target="PessoaForm" drawer />             │
 * │  <mad-nav navigate="PessoaForm::onEdit({id})" icon="pencil" />             │
 * │  <mad-nav navigate="PessoaForm::onEdit({id})" icon="pencil" row />         │
 * │  <mad-nav navigate="DetalheForm::show({id}, {cliente_id})" icon="eye" />   │
 * │  <mad-nav target="RelatorioForm" icon="file"                               │
 * │           :params="{'pedido_id': '{id}', 'modo': 'visualizar'}" />         │
 * │                                                                              │
 * │  <mad-act> — Ação no grid (chama método PHP do grid via AJAX/MadWire)       │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  Chama um método público do controller do grid. O grid re-renderiza após.   │
 * │  O método recebe o ID do registro como parâmetro.                           │
 * │  Retorna MadResponse ou MadToast.                                           │
 * │                                                                              │
 * │  method="onAprovar"       Método PHP a chamar (obrigatório)                 │
 * │  mad:click="onAprovar"    Alias equivalente a method= (mesmo efeito)        │
 * │  icon="check"             Ícone Lucide                                      │
 * │  label="Aprovar"          Texto do botão                                    │
 * │  primary / danger         Estilo visual                                     │
 * │  confirm="Confirmar?"     Confirmação antes de executar                     │
 * │  display-condition="Cls::metodo"  Exibição condicional ($row => bool)       │
 * │  when-field="status"      Condição por campo do registro                    │
 * │  when-value="P"           Exibe se campo = valor                            │
 * │  when-in="1,2,3"          Exibe se campo está na lista                      │
 * │  when-nin="8,9,10"        Exibe se campo NÃO está na lista                 │
 * │  transform="Cls::metodo"  Muda label/icon dinamicamente ($row => array)     │
 * │                                                                              │
 * │  Exemplos:                                                                  │
 * │  <mad-act method="onDelete" icon="trash-2" label="Excluir" danger           │
 * │           confirm="Excluir?" />                                             │
 * │  <mad-act method="onAprovar" icon="check" label="Aprovar" primary           │
 * │           display-condition="Cls::podeAprovar"                              │
 * │           confirm="Aprovar?" when-field="status" when-value="P" />          │
 * │                                                                              │
 * │  <mad-action-group> — Grupo de ações (dropdown)                             │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  <mad-action-group icon="more-horizontal" label="Ações">                    │
 * │      <mad-act method="onAprovar" icon="check" label="Aprovar" />            │
 * │      <mad-act method="onCancelar" icon="ban" label="Cancelar" danger />     │
 * │  </mad-action-group>                                                        │
 * │                                                                              │
 * │  <mad-del> — Atalho para exclusão                                           │
 * │  ──────────────────────────────────────────────────────────────────────────  │
 * │  <mad-del confirm="Excluir este registro?" />                               │
 * │                                                                              │
 * │  Diferença mad-nav vs mad-act:                                              │
 * │  ┌────────────┬──────────────────────┬──────────────────────┐               │
 * │  │            │ <mad-nav>            │ <mad-act>            │               │
 * │  ├────────────┼──────────────────────┼──────────────────────┤               │
 * │  │ Propósito  │ Abrir outra tela     │ Executar ação no grid│               │
 * │  │ AJAX       │ Não (navegação)      │ Sim (MadWire)        │               │
 * │  │ Re-render  │ Não                  │ Sim                  │               │
 * │  │ Método PHP │ No controller DESTINO│ No controller DO GRID│               │
 * │  │ Parâmetros │ Apenas id            │ id + extras          │               │
 * │  └────────────┴──────────────────────┴──────────────────────┘               │
 * │                                                                              │
 * │  Valores dinâmicos usam o prefixo ':'  →  :badge="$map"  :per-page="$n"    │
 * └──────────────────────────────────────────────────────────────────────────────┘
 */
class MadGridCompiler
{
    /** Registra HTML dos filter popovers customizados durante a compilação. */
    protected static array $_filterPopovers = [];

    /**
     * Registra as expressões PHP de `<mad-col-filter :opts / :filters>`.
     *
     * Irmão de $_filterPopovers e pelo mesmo motivo: expressão PHP crua não
     * cabe num atributo `data-*` (viraria string literal). Fica aqui, e a tag
     * carrega só a chave em `data-col-filter-expr`.
     *
     * @var array<string, array{opts?: string, filters?: string}>
     */
    protected static array $_filterExprs = [];

    // ── Fronteira de tag: corrida de atributos CIENTE DE ASPAS ────────────────

    /**
     * Corrida de atributos que fecha no PRÓPRIO '>' da tag.
     *
     * O padrão ingênuo (`[^>]*>` / `[\s\S]*?>`) para no PRIMEIRO '>' do texto —
     * inclusive num '>' que mora DENTRO do valor de um atributo. Dois '>' são
     * rotina em Blade e viravam parse truncado SILENCIOSO:
     *
     *   :items="[1 => 'A']"           → corta em `[1 =`  → :items vira bool true
     *   group-mask="{categoria->nome}" → corta em `{categoria-` → máscara vira "1"
     *
     * A alternância abaixo consome interpolação `{{ }}` / `{!! !!}` e valores
     * entre aspas COMO BLOCO, e só aceita '>' fora deles como fim da tag. É a
     * mesma técnica já usada em FL_CHILD_RE e em <mad-data-table> — aqui virou
     * constante pra que toda fronteira do compilador conte a mesma história.
     */
    protected const ATTR_RUN =
        '(?:\{\{[\s\S]*?\}\}|\{!![\s\S]*?!!\}|"[^"]*"|\'[^\']*\'|[^>"\'])*';

    /**
     * Irmã da ATTR_RUN para tags que também podem ser SELF-CLOSING.
     *
     * Aqui o '/' de '/>' não pode ser engolido pela corrida de atributos, senão
     * a alternativa `\s*\/>` nunca casa e o regex cai na alternativa de par
     * aberto/fechado — passando por cima de tudo até um `</tag>` posterior de
     * OUTRA instância. Por isso `\/(?!>)`: barra só é atributo quando não é o
     * fecho da tag.
     */
    protected const ATTR_RUN_SC =
        '(?:\{\{[\s\S]*?\}\}|\{!![\s\S]*?!!\}|"[^"]*"|\'[^\']*\'|\/(?!>)|[^>"\'\/])*';

    /**
     * Fecho de um container: `>corpo</tag>` OU `/>` (corpo vazio).
     *
     * Motivo: todo bloco que exigia `</tag>` usava a ATTR_RUN (que ENGOLE o '/'
     * de '/>'), então a forma self-closing não casava com NADA — o pass não
     * reconhecia a tag, ela sobrava crua no HTML e o alias pass morria com
     * "components.x not found". Na tela dava BRANCO, sem erro nem log.
     *
     * Usar junto com a ATTR_RUN_SC na corrida de atributos (senão o '/' vira
     * atributo e a alternativa `\s*\/>` nunca é alcançada) e ler o corpo com
     * `$match[N] ?? ''` — no self-closing o grupo do corpo não participa.
     */
    protected static function pairOrSelfClose(string $tag, string $bodyGroup = '([\s\S]*?)'): string
    {
        return '(?:>' . $bodyGroup . '<\/' . $tag . '>|\s*\/>)';
    }

    // ── Entry point ───────────────────────────────────────────────────────────

    public static function compile(string $template): string
    {
        $entrada = $template;

        // Protege comentarios Blade {{-- --}} dos regex de <mad-*>: esta compilacao
        // roda no template CRU (antes do Blade remover o comentario), entao uma tag
        // <mad-*> literal dentro de um comentario seria compilada por engano (abriria
        // a tag e engoliria o conteudo real ate o fechamento seguinte). Mascara aqui,
        // restaura no fim — o Blade remove o comentario normalmente no estagio seguinte.
        $comments = [];
        $template = preg_replace_callback('/\{\{--.*?--\}\}/s', function ($m) use (&$comments) {
            $token = 'MAD__BLADE_COMMENT__' . count($comments) . '__';
            $comments[$token] = $m[0];
            return $token;
        }, $template);

        // Protege blocos @php ... @endphp pelo MESMO motivo: codigo/comentario PHP
        // pode citar uma tag <mad-*> literal (ex.: "// o :query de <mad-grid ...>").
        // O regex da tag encontra a abertura, nao acha fechamento e entra em
        // backtracking catastrofico — preg_replace_callback devolve NULL e a pagina
        // inteira compilava para VAZIO, sem erro (medido em 12/08/2026, painel DRE
        // do Eye of Mad). Tag <mad-*> legitima nunca vive dentro de @php.
        $template = preg_replace_callback('/@php\b[\s\S]*?@endphp/s', function ($m) use (&$comments) {
            $token = 'MAD__BLADE_PHPBLOCK__' . count($comments) . '__';
            $comments[$token] = $m[0];
            return $token;
        }, $template);

        // IMPORTANTE: <mad-detail-form> deve ser compilado ANTES de <mad-grid>,
        // porque pode conter <mad-grid> interno como wrapper de colunas.
        $template = preg_replace_callback(
            '/<mad-detail-form(\s' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-detail-form') . '/s',
            [static::class, 'compileDetailFormBlock'],
            $template
        );

        // Compila <mad-data-table> ANTES de <mad-grid> para evitar conflito de regex.
        // Atributos podem conter > dentro de aspas (ex: :filters="[..., $x->y, ...]")
        $template = preg_replace_callback(
            '/<mad-data-table(' . self::ATTR_RUN_SC . ')(>[\s\S]*?<\/mad-data-table>|\s*\/>)/s',
            [static::class, 'compileDataTableBlock'],
            $template
        );

        // Compila <mad-grid> (standalone — os que estavam dentro de detail-form já foram consumidos)
        $template = preg_replace_callback(
            '/<mad-grid((?:\s' . self::ATTR_RUN_SC . ')?)(>[\s\S]*?<\/mad-grid>|\s*\/>)/s',
            [static::class, 'compileBlock'],
            $template
        );

        // Compila <mad-seek>
        $template = preg_replace_callback(
            '/<mad-seek((?:\s' . self::ATTR_RUN_SC . ')?)(>[\s\S]*?<\/mad-seek>|\s*\/>)/s',
            [static::class, 'compileSeekBlock'],
            $template
        );

        // Compila <mad-field-list ...>...</mad-field-list>
        // Lista de atributos OPCIONAL (como <mad-grid> e <mad-seek> já eram):
        // com `(\s...)` obrigatório, um <mad-field-list> sem atributo nenhum não
        // casava, escapava do compilador e morria no alias pass com
        // "components.field-list-column not found".
        $template = preg_replace_callback(
            '/<mad-field-list((?:\s' . self::ATTR_RUN_SC . ')?)' . static::pairOrSelfClose('mad-field-list') . '/s',
            [static::class, 'compileFieldListBlock'],
            $template
        );

        // Compila <mad-col> dentro de <mad-checklist-field> e <mad-dbchecklist-field>
        $template = preg_replace_callback(
            '/<mad-(db)?checklist-field(\s' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-(?:db)?checklist-field') . '/s',
            [static::class, 'compileChecklistCols'],
            $template
        );

        // Compila <mad-wizard ...>...</mad-wizard> (ANTES do mad-steps: emite
        // <x-steps> + painéis; os corpos dos steps seguem no template pras
        // passes seguintes compilarem os campos normalmente)
        $template = preg_replace_callback(
            // `(?![\w-])`: <mad-wizard-step> começa com <mad-wizard e a corrida
            // de atributos não é ancorada em \s — sem o guard, um step
            // self-closing solto seria compilado como se fosse o wizard inteiro.
            '/<mad-wizard(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-wizard') . '/s',
            [static::class, 'compileWizardBlock'],
            $template
        );

        // Compila <mad-steps ...>...</mad-steps>
        $template = preg_replace_callback(
            '/<mad-steps((?:\s' . self::ATTR_RUN_SC . ')?)' . static::pairOrSelfClose('mad-steps') . '/s',
            [static::class, 'compileStepsBlock'],
            $template
        );

        // Compila <mad-timeline ...>...</mad-timeline> (com filhos manuais)
        $template = preg_replace_callback(
            '/<mad-timeline((?:\s' . self::ATTR_RUN_SC . ')?)' . static::pairOrSelfClose('mad-timeline') . '/s',
            [static::class, 'compileTimelineBlock'],
            $template
        );

        // Compila <mad-pivot-table ...>...</mad-pivot-table>
        $template = preg_replace_callback(
            '/<mad-pivot-table(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-pivot-table') . '/s',
            [static::class, 'compilePivotTableBlock'],
            $template
        );

        // Compila <mad-tree-view ...>...</mad-tree-view> ou <mad-tree-view ... />
        $template = preg_replace_callback(
            '/<mad-tree-view((?:\s' . self::ATTR_RUN_SC . ')?)(>[\s\S]*?<\/mad-tree-view>|\s*\/>)/s',
            [static::class, 'compileTreeViewBlock'],
            $template
        );

        // Compila <mad-db-blocks ...>...</mad-db-blocks> ou <mad-db-blocks ... />
        $template = preg_replace_callback(
            '/<mad-db-blocks((?:\s' . self::ATTR_RUN_SC . ')?)(>[\s\S]*?<\/mad-db-blocks>|\s*\/>)/s',
            [static::class, 'compileDbBlocksBlock'],
            $template
        );

        // Defesa: se algum pass acima estourar o PCRE (backtrack/recursion),
        // preg_replace_callback devolve NULL e os passes seguintes degradam o
        // template para string vazia — a tela renderia em BRANCO sem nenhum erro.
        // Melhor devolver o template ORIGINAL sem compilar (a tag crua aparece na
        // tela e denuncia o problema) e logar, do que sumir com a pagina.
        if (($template === null || $template === '') && $entrada !== '') {
            \Illuminate\Support\Facades\Log::error(
                '[MadGridCompiler] Compilacao abortada (' . preg_last_error_msg() . ') — template devolvido sem compilar.'
            );

            return $entrada;
        }

        // Restaura os comentarios Blade mascarados (o Blade os remove em seguida).
        if ($comments) {
            $template = strtr($template, $comments);
        }

        return $template;
    }

    protected static function compileDbBlocksBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $inner = preg_replace('/^\s*>|<\/mad-db-blocks>\s*$/s', '', $body);
        $inner = trim($inner);

        $parentAttrs = static::parseAttrs($attrStr);

        // Extract <mad-db-blocks-form>...</mad-db-blocks-form> slot
        $formSlot = '';
        if (preg_match('/<mad-db-blocks-form\b' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-db-blocks-form') . '/s', $inner, $fm)) {
            $formSlot = trim($fm[1] ?? '');
        }

        // Extract <mad-db-blocks-row>...</mad-db-blocks-row> slot (inline row template)
        $rowSlot = '';
        if (preg_match('/<mad-db-blocks-row\b' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-db-blocks-row') . '/s', $inner, $rm)) {
            $rowSlot = trim($rm[1] ?? '');
        }

        // String props (kebab → camelCase)
        $stringProps = [
            'name'                => 'name',
            'label'               => 'label',
            'mode'                => 'mode',
            'pivot-model'         => 'pivotModel',
            'database'            => 'database',
            'foreign-key'         => 'foreignKey',
            'row-view'            => 'rowView',
            'order-by'            => 'orderBy',
            'order-dir'           => 'orderDir',
            'add-mode'            => 'addMode',
            'add-label'           => 'addLabel',
            'add-icon'            => 'addIcon',
            'add-variant'         => 'addVariant',
            'layout'              => 'layout',
            'empty-text'          => 'emptyText',
            'on-add'              => 'onAdd',
            'on-remove'           => 'onRemove',
            'on-update'           => 'onUpdate',
            'on-after-add'        => 'onAfterAdd',
            'on-after-remove'     => 'onAfterRemove',
            'class'               => 'class',
            // 5.4.0 — form inline, edição de item e upload por item.
            // ⚠️ Esta lista é ALLOWLIST: prop nova que não entre aqui é
            // silenciosamente descartada na compilação (o componente nunca a vê).
            'form-position'       => 'formPosition',
            'submit-label'        => 'submitLabel',
            'submit-icon'         => 'submitIcon',
            'file-field'          => 'fileField',
            'folder'              => 'folder',
            'path-column'         => 'pathColumn',
            'file-name'           => 'fileName',
            'original-name-column'=> 'originalNameColumn',
            'size-column'         => 'sizeColumn',
            'mime-column'         => 'mimeColumn',
            'disk-column'         => 'diskColumn',
        ];

        $parts = [];
        foreach ($stringProps as $htmlAttr => $bladeAttr) {
            if (isset($parentAttrs[$htmlAttr])) {
                $parts[] = ':' . $bladeAttr . '="' . static::emit($parentAttrs[$htmlAttr]) . '"';
            }
        }

        // Bool props — e `confirm-remove`, que é bool OU string: pelado vira
        // `true` (mensagem padrão no componente), com valor forwarda a string.
        foreach ([
            'hide-add-button' => 'hideAddButton',
            'no-list'         => 'noList',
            'editable'        => 'editable',
            'confirm-remove'  => 'confirmRemove',
        ] as $h => $b) {
            if (!static::has($parentAttrs, $h)) {
                continue;
            }
            // Atributo com valor (`:editable="$editable"`) forwarda a EXPRESSÃO;
            // atributo pelado (`editable`) vira `true`.
            $parts[] = isset($parentAttrs[$h])
                ? ':' . $b . '="' . static::emit($parentAttrs[$h]) . '"'
                : ':' . $b . '="true"';
        }

        // PHP expression for record-id (allows :record-id="$id")
        if (isset($parentAttrs['record-id'])) {
            $parts[] = ':recordId="' . static::emit($parentAttrs['record-id']) . '"';
        }

        // Props que são EXPRESSÃO PHP (array/string montada pelo caller).
        // `form-slot`/`row-slot` como prop existem pros PRESETS (mad-comments,
        // mad-attachments), que montam o slot em PHP em vez de usar as tags
        // filhas <mad-db-blocks-form>/<mad-db-blocks-row>.
        foreach ([
            'preset-vars' => 'presetVars',
            'edit-fields' => 'editFields',
            'form-slot'   => 'formSlot',
            'row-slot'    => 'rowSlot',
        ] as $h => $b) {
            if (isset($parentAttrs[$h])) {
                $parts[] = ':' . $b . '="' . static::emit($parentAttrs[$h]) . '"';
            }
        }

        // filters as PHP expression (array)
        if (isset($parentAttrs['filters'])) {
            $parts[] = ':filters="' . static::emit($parentAttrs['filters']) . '"';
        }

        $propsStr = !empty($parts) ? ' ' . implode(' ', $parts) : '';

        $phpOpen  = '<' . '?php';
        $phpClose = '?' . '>';
        $prefix   = '';
        if ($formSlot !== '') {
            $prefix .= $phpOpen . ' ob_start(); ' . $phpClose
                     . $formSlot
                     . $phpOpen . ' $_mad_db_blocks_form_slot = ob_get_clean(); ' . $phpClose;
            $propsStr .= ' :formSlot="$_mad_db_blocks_form_slot"';
        }

        if ($rowSlot !== '') {
            $prefix .= $phpOpen . ' $_mad_db_blocks_row_slot = ' . var_export($rowSlot, true) . '; ' . $phpClose;
            $propsStr .= ' :rowSlot="$_mad_db_blocks_row_slot"';
        }

        return $prefix . '<x-db-blocks' . $propsStr . ' />';
    }

    // ── Block compiler ────────────────────────────────────────────────────────

    protected static function compileBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $gridAttrs  = static::parseAttrs($attrStr);
        $configParts = static::buildGridConfig($gridAttrs);

        // Strip outer '>' and '</mad-grid>' to get children
        $inner = preg_replace('/^\s*>|<\/mad-grid>\s*$/s', '', $body);

        // Remove tags organizacionais (sem semântica para o compiler)
        $inner = preg_replace('/<\/?mad-(columns|actions|nav-actions|bulk-actions)\b' . self::ATTR_RUN . '>/s', '', $inner);

        // Ações em lote (<mad-grid selectable>): extraídas ANTES do resto — o
        // passe de @if/@endif das ações de linha removeria a tag junto com o
        // bloco, e o match genérico de filhos não a reconhece.
        $bulkConfigs = [];
        $inner = preg_replace_callback(
            '/<mad-bulk-action(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-bulk-action') . '/s',
            function (array $m) use (&$bulkConfigs): string {
                $cfg = static::buildBulkConfig(static::parseAttrs($m[1]), (string) ($m[2] ?? ''));
                if ($cfg !== null) {
                    $bulkConfigs[] = $cfg;
                }
                return '';
            },
            $inner
        );

        // Filtro avançado (<mad-custom-filters>): mesmo tratamento das ações em
        // lote — extraído ANTES do resto, porque o match genérico de filhos não
        // o reconhece e a tag não pode sobrar crua no HTML (o alias pass
        // morreria com "components.custom-filters not found"). Só o PRIMEIRO
        // bloco vale: dois containers no mesmo grid seriam ambíguos.
        $customFiltersCfg = null;
        $inner = preg_replace_callback(
            '/<mad-custom-filters(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-custom-filters') . '/s',
            function (array $m) use (&$customFiltersCfg): string {
                if ($customFiltersCfg === null) {
                    $customFiltersCfg = static::buildCustomFiltersConfig(static::parseAttrs($m[1]), (string) ($m[2] ?? ''));
                } else {
                    static::warn('<mad-grid> com mais de um <mad-custom-filters> — só o primeiro vale.');
                }
                return '';
            },
            $inner
        );
        // <mad-custom-filter> fora do container não tem config a que pertencer:
        // sai do HTML com aviso em vez de virar tag crua.
        $inner = preg_replace_callback(
            '/<mad-custom-filter(?![\w-])' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-custom-filter', '[\s\S]*?') . '/s',
            function (): string {
                static::warn('<mad-custom-filter> fora de <mad-custom-filters> — ignorado.');
                return '';
            },
            $inner
        );

        // Pré-processa <mad-col attrs>...</mad-col> ou <mad-column attrs>...</mad-column>
        // → extrai <mad-col-filter> e <mad-col-edit> do corpo e converte em atributos
        // Regex: atributos permitem '>' dentro de aspas (ex: field="{rel->campo}")
        $inner = preg_replace_callback(
            '/<mad-col(?:umn)?((?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*)>([\s\S]*?)<\/mad-col(?:umn)?>/s',
            function (array $m): string {
                $attrs = $m[1];
                $body  = $m[2];

                // ── <mad-col-filter> (bloco com conteúdo customizado) ───────
                // Regex CIENTE DE ASPAS: `[^>]*` truncava no '>' de um valor
                // como field="{estado->nome}" e os atributos seguintes sumiam
                // sem erro nenhum.
                if (preg_match('/<mad-col-filter\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>([\s\S]*?)<\/mad-col-filter>/s', $body, $fm)) {
                    $filterAttrs = static::parseAttrs($fm[1]);
                    $html = trim($fm[2]);

                    // No contexto do filter-popover, mad:model é Alpine x-model (não mad-livewire).
                    $html = strtr($html, [
                        'mad:model.live=' => 'x-model=',
                        'mad:model='      => 'x-model=',
                        'mad:change='     => '@change=',
                        'mad:click='      => '@click=',
                    ]);

                    // Protege tags <mad-*> contra compilação pelo BladeOne.
                    // O conteúdo será renderizado em runtime via MadBlade::renderString().
                    $html = preg_replace('/<(\/?)mad-/', '<$1MAD__DEFER__', $html);

                    $key = 'fpop_' . count(static::$_filterPopovers);
                    static::$_filterPopovers[$key] = $html;
                    $attrs .= " data-fpop-key=\"{$key}\"";

                    // Extrai field e op da tag <mad-col-filter> para criptografia
                    if (isset($filterAttrs['field']) || isset($filterAttrs['op'])) {
                        $fField = static::str($filterAttrs, 'field', '');
                        $fOp    = static::str($filterAttrs, 'op', 'like');
                        $fSub   = static::str($filterAttrs, 'subselect', '');
                        $attrs .= " data-col-filter-field=\"{$fField}\" data-col-filter-op=\"{$fOp}\"";
                        if ($fSub) $attrs .= " data-col-filter-sub=\"{$fSub}\"";
                    }
                    // Corpo livre + type= não faz sentido (o corpo JÁ é a UI do
                    // filtro): o corpo vence, e o type é ignorado de propósito.
                }
                // ── <mad-col-filter .../> (self-closing, sem conteúdo) ──────
                elseif (preg_match('/<mad-col-filter\b((?:[^>"\'\/]|"[^"]*"|\'[^\']*\'|\/(?!>))*)\/>/s', $body, $fm)) {
                    $filterAttrs = static::parseAttrs($fm[1]);
                    $fField = static::str($filterAttrs, 'field', '');
                    $fOp    = static::str($filterAttrs, 'op', 'like');
                    $fSub   = static::str($filterAttrs, 'subselect', '');
                    $attrs .= " data-col-filter-field=\"{$fField}\" data-col-filter-op=\"{$fOp}\"";
                    if ($fSub) $attrs .= " data-col-filter-sub=\"{$fSub}\"";

                    // Açúcar tipado: <mad-col-filter type="dbcombo" model="..." />
                    // vira exatamente o mesmo config das props achatadas.
                    $attrs .= static::hoistTypedFilterAttrs($filterAttrs);
                }

                // ── <mad-col-edit> (bloco ou self-closing) ──────────────────
                // Bloco PRIMEIRO (testa antes do self-closing pra evitar captura
                // gulosa: <mad-col-edit><dbcombo .../></mad-col-edit> nao deve
                // ser tratado como <mad-col-edit ... />).
                //
                // Block: <mad-col-edit> <dbcombo .../> ou <date/> etc. </mad-col-edit>
                if (preg_match('/<mad-col-edit\b' . self::ATTR_RUN . '>([\s\S]*?)<\/mad-col-edit>/s', $body, $em)) {
                    $extra = static::_editAttrsFromBody(trim($em[1]));
                    $attrs .= $extra;
                }
                // Self-closing: <mad-col-edit type="date" />
                //               <mad-col-edit type="select" :opts="$expr" decimals="2" rows="3" />
                // Atencao: proibe '<' no captured group pra impedir greedy match em
                // blocos — mas CIENTE DE ASPAS, senão um `:opts="[1 => 'a']"` no
                // proprio col-edit truncava no '>' do '=>' e o edit sumia.
                elseif (preg_match('/<mad-col-edit((?:"[^"]*"|\'[^\']*\'|[^<>"\'])*?)\s*\/>/s', $body, $em)) {
                    $ea = static::parseAttrs($em[1]);
                    $attrs .= static::_editAttrsFromConfig($ea);
                }

                return "<mad-col{$attrs} />";
            },
            $inner
        );

        $colConfigs = [];
        $actConfigs = [];   // actions sem condicional
        $grpConfigs = [];
        // Grupos condicionais de actions: [['cond' => 'php-expr', 'actions' => [...]]]
        // Usados para suportar @if/@elseif/@else dentro de <mad-actions>
        $condActBlocks = [];

        // Extrai <mad-action-group>...</mad-action-group> antes do match de self-closing
        $inner = preg_replace_callback(
            '/<mad-action-group(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-action-group') . '/s',
            function (array $m) use (&$grpConfigs): string {
                $grpAttrs = static::parseAttrs($m[1]);
                $grpBody  = $m[2] ?? '';

                $icon  = static::qs(static::str($grpAttrs, 'icon', 'more-horizontal'));
                $label = static::textPhp($grpAttrs, 'label') ?? static::qs('');

                $actParts = [];
                preg_match_all('/<mad-(act(?:ion)?|nav)([\s\S]*?)\s*\/>/s', $grpBody, $am, PREG_SET_ORDER);
                foreach ($am as $am2) {
                    $tagType   = $am2[1];
                    $tagAttrs  = static::parseAttrs($am2[2] ?? '');
                    $actParts[] = ($tagType === 'nav')
                        ? static::buildNavConfig($tagAttrs)
                        : static::buildActConfig($tagAttrs);
                }
                $actsStr  = empty($actParts) ? '[]' : "[\n            " . implode(",\n            ", $actParts) . "\n        ]";
                $grpConfigs[] = "['icon' => {$icon}, 'label' => {$label}, 'actions' => {$actsStr}]";
                return '';
            },
            $inner
        );

        // ── Extrai blocos @if/@elseif/@else/@endif com actions dentro ────
        // Converte cada branch em um bloco condicional que sera incluido
        // no actConfigs via array_merge em runtime.
        $inner = preg_replace_callback(
            '/@if\s*\(([^)]*)\)([\s\S]*?)@endif/s',
            function (array $m) use (&$condActBlocks): string {
                $firstCond = trim($m[1]);
                $body      = $m[2];

                // Divide o corpo em branches: @if → primeiro branch, @elseif → branches seguintes, @else → ultimo
                $branches = [];
                $cursor   = 0;
                $prevCond = $firstCond;

                if (preg_match_all('/@elseif\s*\(([^)]*)\)|@else\b/', $body, $marks, PREG_OFFSET_CAPTURE)) {
                    $positions = [];
                    foreach ($marks[0] as $i => $mark) {
                        $positions[] = [
                            'offset' => $mark[1],
                            'len'    => strlen($mark[0]),
                            'cond'   => isset($marks[1][$i][0]) && $marks[1][$i][0] !== '' ? trim($marks[1][$i][0]) : null,
                        ];
                    }
                    foreach ($positions as $pos) {
                        $branchBody = substr($body, $cursor, $pos['offset'] - $cursor);
                        $branches[] = ['cond' => $prevCond, 'body' => $branchBody];
                        $prevCond   = $pos['cond']; // null para @else
                        $cursor     = $pos['offset'] + $pos['len'];
                    }
                    $branches[] = ['cond' => $prevCond, 'body' => substr($body, $cursor)];
                } else {
                    $branches[] = ['cond' => $firstCond, 'body' => $body];
                }

                // Para cada branch, extrai as actions e registra como bloco condicional
                $notConds = []; // acumulado para o @else (negacao de todas as anteriores)
                foreach ($branches as $br) {
                    $br['body'] = trim($br['body']);
                    $actParts = [];
                    preg_match_all('/<mad-(act(?:ion)?|nav|del)([\s\S]*?)\s*\/>/s', $br['body'], $am, PREG_SET_ORDER);
                    foreach ($am as $am2) {
                        $tagType  = $am2[1];
                        $tagAttrs = static::parseAttrs($am2[2] ?? '');
                        $actParts[] = match ($tagType) {
                            'nav'     => static::buildNavConfig($tagAttrs),
                            'del'     => static::buildDelConfig($tagAttrs),
                            default   => static::buildActConfig($tagAttrs),
                        };
                    }
                    if (empty($actParts)) continue;

                    // Condicao real: @if/@elseif usam a propria, @else usa !prev1 && !prev2 ...
                    if ($br['cond'] === null) {
                        $realCond = empty($notConds) ? 'true' : '!(' . implode(') && !(', $notConds) . ')';
                    } else {
                        $realCond = $br['cond'];
                        $notConds[] = $br['cond'];
                    }

                    $condActBlocks[] = [
                        'cond' => $realCond,
                        'acts' => $actParts,
                    ];
                }

                return ''; // remove do $inner para nao re-processar
            },
            $inner
        );

        // Match all self-closing child tags (multiline attributes OK)
        // Aceita tanto <mad-col> quanto <mad-column>, <mad-act> quanto <mad-action>
        preg_match_all('/<mad-(col(?:umn)?|nav|del|act(?:ion)?)([\s\S]*?)\s*\/>/s', $inner, $children, PREG_SET_ORDER);

        foreach ($children as $child) {
            $type       = $child[1];
            $childAttrs = static::parseAttrs($child[2] ?? '');

            switch ($type) {
                case 'col':
                case 'column': $colConfigs[] = static::buildColConfig($childAttrs);  break;
                case 'nav':    $actConfigs[] = static::buildNavConfig($childAttrs);  break;
                case 'del':    $actConfigs[] = static::buildDelConfig($childAttrs);  break;
                case 'act':
                case 'action': $actConfigs[] = static::buildActConfig($childAttrs);  break;
            }
        }

        if (!empty($colConfigs)) {
            $configParts[] = "'colConfigs' => [\n        " . implode(",\n        ", $colConfigs) . "\n    ]";
        }

        // Monta 'actConfigs' — combina actions incondicionais + blocos @if
        if (!empty($actConfigs) || !empty($condActBlocks)) {
            $unconditionalStr = empty($actConfigs)
                ? '[]'
                : "[\n        " . implode(",\n        ", $actConfigs) . "\n    ]";

            if (empty($condActBlocks)) {
                $configParts[] = "'actConfigs' => {$unconditionalStr}";
            } else {
                $mergeParts = [$unconditionalStr];
                foreach ($condActBlocks as $block) {
                    $actsArr = "[\n            " . implode(",\n            ", $block['acts']) . "\n        ]";
                    $mergeParts[] = "(({$block['cond']}) ? {$actsArr} : [])";
                }
                $configParts[] = "'actConfigs' => array_merge(\n        " . implode(",\n        ", $mergeParts) . "\n    )";
            }
        }

        if (!empty($grpConfigs)) {
            $configParts[] = "'actGroupConfigs' => [\n        " . implode(",\n        ", $grpConfigs) . "\n    ]";
        }

        if (!empty($bulkConfigs)) {
            $configParts[] = "'bulkActions' => [\n        " . implode(",\n        ", $bulkConfigs) . "\n    ]";
        }

        if ($customFiltersCfg !== null) {
            $configParts[] = "'customFilters' => {$customFiltersCfg}";
        }

        // Tela DONA do grid — quem manda nas permissões das ações dele.
        // O grid declarativo executa numa classe do framework, liberada a todo
        // usuário logado; sem registrar aqui quem o contém, a exclusão embutida
        // não seria checada contra perfil nenhum. Viaja selado no estado
        // criptografado do componente, junto com colunas e ações.
        $configParts[] = "'owner' => isset(\$__component) ? get_class(\$__component) : ''";

        $configStr = "[\n    " . implode(",\n    ", $configParts) . "\n]";

        // <mad-grid self> — delega para $__component->_renderInlineGrid() (subclasse MadDataGrid)
        if (static::has($gridAttrs, 'self')) {
            return "<?php echo \$__component->_renderInlineGrid({$configStr}); ?>";
        }

        return "<?php echo \\Mad\\Grid\\MadGrid::_renderFromConfig({$configStr}); ?>";
    }

    // ── Seek block compiler ────────────────────────────────────────────────────

    /**
     * Compila <mad-seek model="..." name="..." ...> com <mad-column> e <mad-action>.
     * Aceita tanto <mad-col>/<mad-act> quanto <mad-column>/<mad-action>.
     */
    protected static function compileSeekBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $seekAttrs   = static::parseAttrs($attrStr);
        $configParts = static::buildSeekConfig($seekAttrs);

        // Strip outer '>' and '</mad-seek>'
        $inner = preg_replace('/^\s*>|<\/mad-seek>\s*$/s', '', $body);

        $colConfigs = [];
        $actConfigs = [];

        // Match <mad-col>, <mad-column>, <mad-act>, <mad-action>, <mad-nav>, <mad-del>, <mad-fill>
        preg_match_all('/<mad-(col(?:umn)?|act(?:ion)?|nav|del|fill)([\s\S]*?)\s*\/>/s', $inner, $children, PREG_SET_ORDER);

        $auxParts = [];

        foreach ($children as $child) {
            $type       = $child[1];
            $childAttrs = static::parseAttrs($child[2] ?? '');

            if ($type === 'col' || $type === 'column') {
                $colConfigs[] = static::buildColConfig($childAttrs);
            } elseif ($type === 'act' || $type === 'action') {
                $actConfigs[] = static::buildActConfig($childAttrs);
            } elseif ($type === 'nav') {
                $actConfigs[] = static::buildNavConfig($childAttrs);
            } elseif ($type === 'del') {
                $actConfigs[] = static::buildDelConfig($childAttrs);
            } elseif ($type === 'fill') {
                if (isset($childAttrs['target']) && isset($childAttrs['source'])) {
                    $auxParts[] = static::emit($childAttrs['target']) . " => " . static::emit($childAttrs['source']);
                }
            }
        }

        if (!empty($colConfigs)) {
            $configParts[] = "'colConfigs' => [\n        " . implode(",\n        ", $colConfigs) . "\n    ]";
        }
        if (!empty($actConfigs)) {
            $configParts[] = "'actConfigs' => [\n        " . implode(",\n        ", $actConfigs) . "\n    ]";
        }
        if (!empty($auxParts)) {
            $configParts[] = "'auxiliaries' => [" . implode(", ", $auxParts) . "]";
        }

        $configStr = "[\n    " . implode(",\n    ", $configParts) . "\n]";

        return "<?php echo \\Mad\\Seek\\MadSeek::_renderFromConfig({$configStr}); ?>";
    }

    /**
     * Constrói as opções de config do <mad-seek>.
     */
    protected static function buildSeekConfig(array $a): array
    {
        $c = [];

        if (isset($a['model']))       $c[] = static::kv('model',       static::emit($a['model']));
        if (isset($a['name']))        $c[] = static::kv('seekName',    static::emit($a['name']));
        if (isset($a['label']))       $c[] = static::kv('label',       static::emit($a['label']));
        if (isset($a['display']))     $c[] = static::kv('display',     static::emit($a['display']));
        if (isset($a['value']))       $c[] = static::kv('value',       static::emit($a['value']));
        if (isset($a['text']))        $c[] = static::kv('text',        static::emit($a['text']));
        if (isset($a['placeholder'])) $c[] = static::kv('placeholder', static::emit($a['placeholder']));
        if (isset($a['hint']))        $c[] = static::kv('hint',        static::emit($a['hint']));
        if (isset($a['modal-title'])) $c[] = static::kv('modalTitle',  static::emit($a['modal-title']));
        if (isset($a['modal-size']))  $c[] = static::kv('modalSize',   static::emit($a['modal-size']));
        if (isset($a['database']))    $c[] = static::kv('database',    static::emit($a['database']));
        if (isset($a['handler']))     $c[] = static::kv('handler',     static::emit($a['handler']));
        if (isset($a['empty-as']))    $c[] = static::kv('emptyAs',     static::emit($a['empty-as']));
        // "Novo": abre o cadastro (Classe::metodo) e o registro salvo volta
        // selecionado no campo (returnToCombo do form alvo).
        if (isset($a['create']))       $c[] = static::kv('create',      static::emit($a['create']));
        if (isset($a['create-label'])) $c[] = static::kv('createLabel', static::emit($a['create-label']));
        if (isset($a['create-icon']))  $c[] = static::kv('createIcon',  static::emit($a['create-icon']));
        // Base da busca: ordem e filtro fixos (mesmas props do <mad-grid>) e
        // :query (Builder; compilado em SQL pelo MadSeek::_renderFromConfig).
        if (isset($a['order-by']))    $c[] = static::kv('orderBy',     static::emit($a['order-by']));
        if (isset($a['filters']))     $c[] = static::kv('filters',     static::emit($a['filters']));
        if (isset($a['query']))       $c[] = static::kv('query',       static::emit($a['query']));

        if (isset($a['per-page'])) {
            $attr = $a['per-page'];
            $val  = $attr['type'] === 'php' ? $attr['value'] : (int)$attr['value'];
            $c[]  = static::kv('perPage', (string)$val);
        }

        if (static::has($a, 'required')) $c[] = "'required' => true";
        if (static::has($a, 'disabled')) $c[] = "'disabled' => true";

        return $c;
    }

    // ── Detail Form block compiler ──────────────────────────────────────────

    /**
     * Compila <mad-detail-form name="..." mode="..." ...>
     *     <mad-detail-fields>...campos Blade...</mad-detail-fields>
     *     <mad-col ... />
     *     <mad-act ... />
     * </mad-detail-form>
     */
    protected static function compileDetailFormBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $attrs = static::parseAttrs($attrStr);

        // ── Atributos do <mad-detail-form> ──────────────────────────────
        $name      = static::str($attrs, 'name', 'detail');
        $mode      = static::str($attrs, 'mode', 'inline');
        $formCols  = static::str($attrs, 'form-cols', '2');
        $perPage   = static::str($attrs, 'per-page', '0');
        $sticky    = static::has($attrs, 'sticky') ? 'true' : 'false';

        // form-title e add-label suportam expressões PHP (:form-title="__('key')")
        $formTitleExpr = isset($attrs['form-title']) ? static::emit($attrs['form-title']) : "''";
        $addLabelExpr  = isset($attrs['add-label'])  ? static::emit($attrs['add-label'])  : "'Adicionar'";
        $beforeAdd     = static::str($attrs, 'before-add', '');
        $beforeDelete  = static::str($attrs, 'before-delete', '');
        $model         = static::str($attrs, 'model', '');
        $foreignKey    = static::str($attrs, 'foreign-key', '');
        $detailDb      = static::str($attrs, 'database', '');

        // Largura do overlay (mode="modal"|"drawer"). Aceita token do mapa do
        // x-drawer/x-modal (sm|md|lg|xl|full) ou medida livre ("70%", "820px",
        // "640"). Alias `size` porque é o nome da prop nos dois componentes.
        $overlayWidth  = static::str($attrs, 'width', static::str($attrs, 'size', ''));

        // ── Extrair <mad-detail-fields>...</mad-detail-fields> ──────────
        $fieldSlot = '';
        if (preg_match('/<mad-detail-fields\b' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-detail-fields') . '/s', $body, $fm)) {
            $fieldSlot = trim($fm[1] ?? '');
            $body = str_replace($fm[0], '', $body);
        }

        // Remove mad:model dos campos do detail para evitar conflito com MadWire do pai
        $fieldSlot = str_replace('mad:model.live=', 'data-df-bind=', $fieldSlot);
        $fieldSlot = str_replace('mad:model=', 'data-df-bind=', $fieldSlot);

        // Auto-wrap em <mad-form-grid> — opt-out via form-layout="custom" (ou "none")
        // e skip automatico quando o usuario ja declarou um container de layout conhecido
        // (form-grid, form-stack, form-section, tabs, tabs-list, accordion, card).
        $formLayout = strtolower(static::str($attrs, 'form-layout', 'grid'));
        $autoGrid   = ($formLayout === 'grid');
        $hasLayout  = (bool) preg_match(
            '/<mad-(form-grid|form-stack|form-section|tabs|tabs-list|accordion|card)\b/i',
            $fieldSlot
        );

        if ($autoGrid && $fieldSlot && !$hasLayout) {
            $fieldSlot = '<mad-form-grid :cols="' . (int)$formCols . '">' . $fieldSlot . '</mad-form-grid>';
        }

        // ── Extrair <mad-grid> wrapper (se existir) ─────────────────────
        // Permite: <mad-grid sticky per-page="10"> <mad-col .../> </mad-grid>
        if (preg_match('/<mad-grid(' . self::ATTR_RUN . ')>([\s\S]*?)<\/mad-grid>/s', $body, $gm)) {
            $gridAttrs = static::parseAttrs($gm[1]);
            $body      = $gm[2]; // cols/acts ficam dentro do grid body
            // Herda atributos da grid
            if (static::has($gridAttrs, 'sticky'))    $sticky  = 'true';
            if (isset($gridAttrs['per-page']))        $perPage = static::str($gridAttrs, 'per-page', $perPage);
        }

        // Remove tags organizacionais
        $body = preg_replace('/<\/?mad-(columns|actions)\b' . self::ATTR_RUN . '>/s', '', $body);

        // ── Extrair <mad-col> e <mad-act> ───────────────────────────────
        $colConfigs = [];
        $actConfigs = [];

        // Ciente de aspas e fechando no PRÓPRIO '>' (mesmo motivo do FL_CHILD_RE):
        // com `\s*\/>` no fim, `<mad-col ...></mad-col>` seguido de outra coluna
        // fundia as duas numa só. E sem o `\b` no nome, um `<mad-color-field />`
        // solto no corpo do detail-form casava como `col` (attrs "or-field ...")
        // e virava COLUNA FANTASMA na grid.
        preg_match_all(
            '~<(/?)mad-(col(?:umn)?|act(?:ion)?)\b(' . self::ATTR_RUN . ')>~s',
            $body,
            $children,
            PREG_SET_ORDER
        );
        foreach ($children as $child) {
            if ($child[1] === '/') continue;   // tag de fechamento
            $type       = $child[2];
            $childAttrs = static::parseAttrs(rtrim($child[3] ?? '', " \t\r\n/"));

            if ($type === 'col' || $type === 'column') {
                $colConfigs[] = static::buildColConfig($childAttrs);
            } elseif ($type === 'act' || $type === 'action') {
                $actConfigs[] = static::buildActConfig($childAttrs);
            }
        }

        // ── Gerar PHP output ────────────────────────────────────────────
        $colsStr = empty($colConfigs) ? '[]' : "[\n        " . implode(",\n        ", $colConfigs) . "\n    ]";
        $actsStr = empty($actConfigs) ? '[]' : "[\n        " . implode(",\n        ", $actConfigs) . "\n    ]";

        $rowsExpr = '$' . $name . ' ?? []';

        $fieldSlotB64 = base64_encode($fieldSlot);

        return "<?php \$_df_{$name}_fs = base64_decode('{$fieldSlotB64}'); "
             . "\$__env->startComponent('components.detail-form', ["
             . "'name' => " . static::qs($name) . ", "
             . "'mode' => " . static::qs($mode) . ", "
             . "'formTitle' => {$formTitleExpr}, "
             . "'formCols' => " . (int)$formCols . ", "
             . "'addLabel' => {$addLabelExpr}, "
             . "'beforeAdd' => " . static::qs($beforeAdd) . ", "
             . "'beforeDelete' => " . static::qs($beforeDelete) . ", "
             . "'model' => " . static::qs($model) . ", "
             . "'foreignKey' => " . static::qs($foreignKey) . ", "
             . "'database' => " . static::qs($detailDb) . ", "
             . "'width' => " . static::qs($overlayWidth) . ", "
             . "'perPage' => " . (int)$perPage . ", "
             . "'sticky' => {$sticky}, "
             . "'fieldSlot' => \$_df_{$name}_fs, "
             . "'colConfigs' => {$colsStr}, "
             . "'actConfigs' => {$actsStr}, "
             . "'rows' => {$rowsExpr}"
             . "]); ?>"
             . "<?php echo \$__env->renderComponent(); ?>";
    }

    // ── Attribute parser ──────────────────────────────────────────────────────

    /**
     * Analisa uma string de atributos HTML e retorna um array:
     *   name="value"  → ['name' => ['type'=>'string', 'value'=>'value']]
     *   :name="$expr" → ['name' => ['type'=>'php',    'value'=>'$expr']]
     *   name          → ['name' => ['type'=>'bool',   'value'=>true]]
     *
     * Suporta aspas duplas, aspas simples, valores multi-linha e
     * expressões PHP com colchetes/chaves aninhados.
     */
    protected static function parseAttrs(string $str): array
    {
        $result = [];
        $pos    = 0;
        $len    = strlen($str);

        while ($pos < $len) {
            // Pula espaços/quebras
            if (preg_match('/\G\s+/', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                continue;
            }
            // (:?)name = "..." ou (:?)name = '...'
            if (preg_match('/\G(:?)([a-zA-Z][a-zA-Z0-9_-]*)\s*=\s*(["\'])((?:(?!\3)[\s\S])*?)\3/s', $str, $m, 0, $pos)) {
                $pos  += strlen($m[0]);
                $key   = $m[2];
                $val   = $m[4];
                $type  = $m[1] === ':' ? 'php' : 'string';
                $result[$key] = ['type' => $type, 'value' => $val];
                continue;
            }
            // Atributo booleano (sem =value)
            if (preg_match('/\G([a-zA-Z][a-zA-Z0-9_-]*)/', $str, $m, 0, $pos)) {
                $pos += strlen($m[0]);
                $result[$m[1]] = ['type' => 'bool', 'value' => true];
                continue;
            }
            $pos++; // caractere desconhecido — pula
        }

        return $result;
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Retorna valor string estático ou default. */
    protected static function str(array $attrs, string $key, string $default = ''): string
    {
        return isset($attrs[$key]) && $attrs[$key]['type'] === 'string'
            ? $attrs[$key]['value']
            : $default;
    }

    /**
     * Emite expressao PHP do atributo (string literal quotada OU expr crua).
     * Use no lugar de qs(str(...)) quando o atributo aceitar `:prop="expr"`
     * com bind PHP (ex: `:confirm="__('chave')"`, `:label="__('chave')"`).
     * Retorna $defaultExpr (literal PHP) quando atributo ausente.
     */
    protected static function strExpr(array $attrs, string $key, string $defaultExpr = "''"): string
    {
        return isset($attrs[$key]) ? static::emit($attrs[$key]) : $defaultExpr;
    }

    /**
     * Texto que entra num array montado como CÓDIGO PHP dentro de um atributo
     * bind (`:steps="[...]"`, `:items="[...]"`, config do grupo de ações):
     * literal vira string PHP (addslashes, igual ao que já se emitia);
     * `:attr="expr"` entra como expressão — é o que deixa
     * `:label="__('grupo.chave')"` traduzível. Antes esses pontos liam só a
     * forma literal (`str()`) e a expressão virava rótulo vazio.
     *
     * null = ausente, literal vazio ou expressão inutilizável (aspas duplas
     * fechariam o atributo que envolve o array) — o chamador decide o default.
     */
    protected static function textPhp(array $attrs, string $key): ?string
    {
        if (!isset($attrs[$key])) {
            return null;
        }
        $a = $attrs[$key];
        if ($a['type'] === 'php') {
            $expr = trim(html_entity_decode((string) $a['value'], ENT_QUOTES | ENT_HTML5));
            return ($expr === '' || str_contains($expr, '"')) ? null : '(' . $expr . ')';
        }
        if ($a['type'] === 'string' && $a['value'] !== '') {
            return "'" . addslashes($a['value']) . "'";
        }
        return null;
    }

    /** Verifica se algum dos nomes de atributo existe (booleano ou qualquer valor). */
    protected static function has(array $attrs, string ...$keys): bool
    {
        foreach ($keys as $k) {
            if (isset($attrs[$k])) return true;
        }
        return false;
    }

    /**
     * Emite a expressão PHP para um valor de atributo:
     *   string → 'valor escapado'
     *   php    → expressão PHP literal (não escapada)
     *   bool   → true
     */
    protected static function emit(array $attr): string
    {
        return match ($attr['type']) {
            // Decodifica entidades HTML (&quot;, &amp;, &lt;, &gt;) — necessário
            // quando o valor de um :prop="..." contém aspas embutidas via &quot;
            // (técnica recomendada em blade-attribute-quotes-rules.md).
            'php'  => html_entity_decode((string)$attr['value'], ENT_QUOTES | ENT_HTML5),
            'bool' => 'true',
            default => "'" . str_replace("'", "\\'", html_entity_decode((string)$attr['value'], ENT_QUOTES | ENT_HTML5)) . "'",
        };
    }

    /** Emite um par chave => valor para o array PHP. */
    protected static function kv(string $key, string $expr): string
    {
        return "'{$key}' => {$expr}";
    }

    /** Emite uma string literal PHP escapada. */
    protected static function qs(string $s): string
    {
        return "'" . str_replace("'", "\\'", $s) . "'";
    }

    // ── Filtro tipado (filter-type=) ─────────────────────────────────────────

    /**
     * Matriz tipo → [operador default, allowlist de filter-op].
     *
     * A validação roda em COMPILE-TIME: um operador inválido nunca chega ao
     * token assinado, então o runtime não precisa confiar em nada.
     * `date` e `date between` são pseudo-ops MAD (whereDate / fronteiras de dia).
     */
    protected const FILTER_KIND_OPS = [
        'text'         => ['like',         ['like', 'not like', '=', '!=']],
        'select'       => ['=',            ['=', '!=']],
        'dbcombo'      => ['=',            ['=', '!=']],
        'dbsearch'     => ['=',            ['=', '!=']],
        'multi'        => ['in',           ['in', 'not in']],
        'date'         => ['date',         ['date', '>=', '<=', '>', '<', '!=']],
        'date-range'   => ['date between', []],
        'number'       => ['=',            ['=', '!=', '>', '>=', '<', '<=']],
        'number-range' => ['between',      []],
        'bool'         => ['=',            ['=', '!=']],
    ];

    /** Nomes legados de `filter=` aceitos também em `filter-type=`. */
    protected const FILTER_KIND_ALIASES = [
        'boolean'     => 'bool',
        'daterange'   => 'date-range',
        'numberrange' => 'number-range',
        'combo'       => 'dbcombo',
        'search'      => 'dbsearch',
    ];

    /**
     * Resolve tipo + operador declarados. Tipo desconhecido cai em `text`;
     * operador fora da allowlist do tipo cai no default do tipo.
     *
     * @return array{0: string, 1: string} [kind, op]
     */
    protected static function resolveFilterKind(string $type, string $op): array
    {
        $kind = strtolower(trim($type));
        $kind = static::FILTER_KIND_ALIASES[$kind] ?? $kind;
        if (! isset(static::FILTER_KIND_OPS[$kind])) {
            $kind = 'text';
        }

        [$defaultOp, $allowed] = static::FILTER_KIND_OPS[$kind];

        $op = strtolower(trim($op));
        if ($op === '' || $allowed === [] || ! in_array($op, $allowed, true)) {
            $op = $defaultOp;
        }

        return [$kind, $op];
    }

    /**
     * Opções do filtro. Aceita as duas formas:
     *   :filter-opts="['A' => 'Ativo']"  → expressão PHP crua
     *   filter-opts="A:Ativo|I:Inativo"  → shorthand, resolvido em runtime
     *
     * Sem o segundo caminho, a forma string chegava crua num parâmetro tipado
     * `array` e estourava TypeError.
     */
    protected static function emitOptsAttr(array $attr): string
    {
        if ($attr['type'] === 'php') {
            return static::emit($attr);
        }
        $raw = html_entity_decode((string) $attr['value'], ENT_QUOTES | ENT_HTML5);
        return '\\Mad\\Grid\\GridColumn::parseOptsMap(' . static::qs($raw) . ')';
    }

    /**
     * Iça as props do açúcar `<mad-col-filter type="..." />` para os `data-*`
     * que o buildColConfig lê. `:opts`/`:filters` são expressões PHP e não
     * cabem em atributo — vão para $_filterExprs, referenciadas por chave.
     */
    protected static function hoistTypedFilterAttrs(array $filterAttrs): string
    {
        if (! isset($filterAttrs['type'])) {
            return '';
        }

        $out = '';
        $map = [
            'type'       => 'type',
            'model'      => 'model',
            'display'    => 'display',
            'key'        => 'key',
            'database'   => 'database',
            'order-by'   => 'order-by',
            'order'      => 'order',
            'min-length' => 'min-length',
            'true'       => 'true',
            'false'      => 'false',
            'opts'       => 'opts',
            'placeholder' => 'placeholder',
        ];
        foreach ($map as $src => $dst) {
            if (! isset($filterAttrs[$src]) || $filterAttrs[$src]['type'] === 'php') continue;
            $val  = htmlspecialchars(static::str($filterAttrs, $src, ''), ENT_QUOTES);
            $out .= " data-col-filter-{$dst}=\"{$val}\"";
        }

        // Expressões PHP (:opts / :filters) por referência.
        $exprs = [];
        foreach (['opts', 'filters'] as $k) {
            if (isset($filterAttrs[$k]) && $filterAttrs[$k]['type'] === 'php') {
                $exprs[$k] = static::emit($filterAttrs[$k]);
            }
        }
        if ($exprs !== []) {
            $key = 'fexpr_' . count(static::$_filterExprs);
            static::$_filterExprs[$key] = $exprs;
            $out .= " data-col-filter-expr=\"{$key}\"";
        }

        return $out;
    }

    /**
     * Filhos diretos de <mad-field-list>.
     *
     * Alternância CIENTE DE ASPAS (mesma técnica do <mad-data-table>): a tag
     * fecha no PRÓPRIO '>', então self-closing e par abre/fecha valem igual e
     * duas colunas nunca se fundem. A regex antiga terminava em `\s*\/>`, e com
     * `<mad-field-list-column ...></mad-field-list-column>` seguido de outra
     * coluna o lazy corria até o `/>` da SEGUINTE e fundia as duas numa só (a
     * última chave ganhava) — coluna sumia da tela, sem log.
     *
     * `{{ }}` / `{!! !!}` vêm ANTES da classe de char pra não morrer num '>'
     * dentro de interpolação.
     */
    protected const FL_CHILD_RE =
        '~<(/?)mad-field-list-(group|column|action)\b(' . self::ATTR_RUN . ')>~s';

    /**
     * APP_DEBUG ligado? Mesma intenção do MadComponentHandler::_debugEnabled(),
     * mas com try/catch: `function_exists('config')` é TRUE assim que os helpers
     * do Laravel entram no autoload, e chamar `config()` sem o container bootado
     * lança BindingResolutionException. Sem o catch, um diagnóstico de authoring
     * derrubaria o compilador em contexto não-bootado (teste unitário, CLI) —
     * exatamente o oposto do que `warn()` promete.
     */
    protected static function debugEnabled(): bool
    {
        if (function_exists('config')) {
            try {
                return (bool) config('app.debug', false);
            } catch (\Throwable $e) {
                // sem container: cai no env abaixo
            }
        }
        $env = getenv('APP_DEBUG');
        return $env !== false && filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Diagnóstico de authoring.
     *
     * Trilho SEMPRE no error_log (convenção do pacote: MadFillTransform,
     * MadFormRegistry, MadDbBlocksTrait); banner visível SÓ com APP_DEBUG.
     * NUNCA lança: o precompiler roda em TODA view e uma exception aqui
     * derrubaria páginas que hoje renderizam (mal, mas renderizam).
     *
     * Devolve HTML pro caller prefixar na saída compilada.
     *
     * ATENÇÃO — o banner volta pro TEMPLATE, não pro browser: este HTML é
     * reinjetado no template e ainda passa pelo compilador Blade. Um `@foreach`
     * citado como PROSA na mensagem (é o caso do texto que explica que o corpo
     * do <mad-field-list> é lido em compile-time) chegava CRU nesse passe e o
     * Blade morria com "Malformed @foreach statement" — a página INTEIRA
     * quebrava por causa do diagnóstico que existia pra ajudar. Por isso, além
     * do htmlspecialchars, o '@' e o '{' viram entidade: rendem idênticos na
     * tela e são inertes para o Blade.
     */
    protected static function warn(string $msg): string
    {
        @error_log('[MadGridCompiler] ' . $msg);
        if (!static::debugEnabled()) return '';
        $safe = strtr(htmlspecialchars($msg, ENT_QUOTES), [
            '@' => '&#64;',    // @if/@foreach/@php citados na mensagem
            '{' => '&#123;',   // {{ }} / {!! !!} citados na mensagem
        ]);

        return '<div class="mad-alert mad-alert-danger" role="alert" style="margin-bottom:8px">'
             . '<div class="mad-alert-title">MadGridCompiler</div>'
             . '<div class="mad-alert-desc">' . $safe . '</div></div>';
    }

    // ── Grid config ───────────────────────────────────────────────────────────

    protected static function buildGridConfig(array $a): array
    {
        $c = [];

        if (isset($a['model']))     $c[] = static::kv('model',      static::emit($a['model']));
        if (isset($a['database']))  $c[] = static::kv('database',   static::emit($a['database']));
        if (isset($a['handler']))   $c[] = static::kv('handler',    static::emit($a['handler']));
        if (isset($a['group-by']))  $c[] = static::kv('groupBy',    static::emit($a['group-by']));
        if (isset($a['group-mask'])) $c[] = static::kv('groupMask', static::emit($a['group-mask']));
        // Ordenação/filtros declarativos — sobrevivem ao AJAX (props públicas
        // do MadDataGrid), ao contrário do :query closure (ver queryIgnored).
        if (isset($a['order-by']))  $c[] = static::kv('orderBy',    static::emit($a['order-by']));
        if (isset($a['filters']))   $c[] = static::kv('filters',    static::emit($a['filters']));
        // :query em grid paginado não funciona (closure não serializa no
        // mad_state) — o runtime loga warning apontando order-by/:filters.
        if (isset($a['query']))     $c[] = "'queryIgnored' => true";

        if (isset($a['per-page'])) {
            $attr = $a['per-page'];
            $val  = $attr['type'] === 'php' ? $attr['value'] : (int)$attr['value'];
            $c[]  = static::kv('perPage', (string)$val);
        }

        if (static::has($a, 'group-total'))  $c[] = "'groupTotal' => true";
        // ── Relatório ────────────────────────────────────────────────────
        // group-band="cells" alinha os totais da quebra sob as colunas;
        // group-total-label troca o rótulo do sub-total; row-detail acrescenta
        // a 2ª linha descritiva. Fora desta allowlist o atributo é engolido.
        if (isset($a['group-band']))        $c[] = static::kv('groupBand',       static::qs(static::str($a, 'group-band')));
        if (isset($a['group-total-label'])) $c[] = static::kv('groupTotalLabel', static::emit($a['group-total-label']));
        if (isset($a['row-detail']))        $c[] = static::kv('rowDetail',       static::emit($a['row-detail']));
        // Título/subtítulo/arquivo da exportação declarados na própria tag
        // (o $exportTitle protegido do PHP não sobrevive ao AJAX de export).
        if (isset($a['export-title']))      $c[] = static::kv('exportTitle',     static::emit($a['export-title']));
        if (isset($a['export-subtitle']))   $c[] = static::kv('exportSubtitle',  static::emit($a['export-subtitle']));
        if (isset($a['export-filename']))   $c[] = static::kv('exportFilename',  static::emit($a['export-filename']));
        if (static::has($a, 'actions-left')) {
            $c[] = "'actionSide' => 'left'";
        } elseif (isset($a['action-side'])) {
            $c[] = static::kv('actionSide', static::qs(static::str($a, 'action-side', 'right')));
        }
        if (static::has($a, 'searchable'))    $c[] = "'searchable' => true";
        if (static::has($a, 'refreshable'))   $c[] = "'refreshable' => true";
        if (isset($a['search-columns']))     $c[] = "'searchColumns' => " . static::emit($a['search-columns']);
        if (static::has($a, 'no-export'))          $c[] = "'exportable' => false";
        if (static::has($a, 'no-column-chooser')) $c[] = "'columnChooser' => false";
        if (static::has($a, 'sticky'))             $c[] = "'sticky' => true";
        // Carga adiada ("Carregar registros ao abrir = Não" do 4.0): o grid abre
        // vazio e só consulta o banco na primeira ação explícita do usuário
        // (Buscar/filtro/sort). Flag negativa nua, mesma convenção do no-export.
        // require-filter implica carga adiada: abrir cheio violaria a regra.
        if (static::has($a, 'no-auto-load', 'require-filter')) $c[] = "'autoLoad' => false";
        // Filtro obrigatório: nenhuma ação consulta o banco sem ao menos um
        // filtro do usuário preenchido (lista opcional restringe os campos).
        // Os nomes são identificadores de runtime (nome do input), nunca id-ref.
        if (static::has($a, 'require-filter'))     $c[] = "'requireFilter' => true";
        if (isset($a['require-filter-fields'])) {
            $fields = array_values(array_filter(array_map('trim', explode(',', static::str($a, 'require-filter-fields'))), 'strlen'));
            $c[] = "'requireFilterFields' => [" . implode(', ', array_map(fn($f) => static::qs($f), $fields)) . ']';
        }
        if (static::has($a, 'no-load-button'))     $c[] = "'loadButton' => false";
        if (isset($a['load-hint']))               $c[] = static::kv('loadHint', static::emit($a['load-hint']));

        // Card view
        if (static::has($a, 'card-view'))    $c[] = "'cardView' => true";
        if (static::has($a, 'card-default')) $c[] = "'cardDefault' => true";
        if (isset($a['card-cols']))          $c[] = "'cardCols' => " . static::qs(static::str($a, 'card-cols'));

        // Seleção de linhas (checkbox) — ações em lote vêm dos <mad-bulk-action>.
        // `selectable="false"` (valor explícito) desliga, como no editor visual.
        if (static::has($a, 'selectable') && strtolower(static::str($a, 'selectable')) !== 'false') {
            $c[] = "'selectable' => true";
        }

        return $c;
    }

    /**
     * `<mad-bulk-action>` → config da ação em lote (ver docblock da classe).
     *
     * Método OU alvo, nunca os dois: `method` vence (é a ação no próprio grid).
     * Sem nenhum dos dois o botão não tem o que fazer — sai com aviso de
     * authoring, em vez de virar um botão morto.
     *
     * @return string|null  literal PHP do array; null = descartada
     */
    protected static function buildBulkConfig(array $a, string $body = ''): ?string
    {
        $c = [];
        $method = static::str($a, 'method');
        $nav    = static::str($a, 'target', static::str($a, 'navigate'));

        if ($method !== '' && preg_match('/^[A-Za-z_]\w*$/', $method)) {
            $c[] = static::kv('method', static::qs($method));
        } elseif ($nav !== '' && preg_match('/^\\\\?([A-Za-z_][\w\\\\]*)(?:::(\w+))?\s*(?:\(\s*\))?$/', trim($nav), $nm)) {
            $c[] = static::kv('target', static::qs(ltrim($nm[1], '\\')));
            $c[] = static::kv('targetMethod', static::qs(!empty($nm[2]) ? $nm[2] : 'show'));
        } else {
            static::warn('<mad-bulk-action> sem method= nem target= válido — ação em lote ignorada.');
            return null;
        }

        $text = trim(strip_tags($body));
        if (isset($a['label'])) {
            $c[] = static::kv('label', static::strExpr($a, 'label'));
        } else {
            $c[] = static::kv('label', static::qs($text !== '' ? $text : ($method !== '' ? $method : $nav)));
        }
        if (isset($a['icon']))    $c[] = static::kv('icon',    static::strExpr($a, 'icon'));
        if (isset($a['confirm'])) $c[] = static::kv('confirm', static::strExpr($a, 'confirm'));

        $variant = match (true) {
            static::has($a, 'danger')    => 'danger',
            static::has($a, 'primary')   => 'primary',
            static::has($a, 'secondary') => 'secondary',
            static::has($a, 'ghost')     => 'ghost',
            default                      => static::str($a, 'variant', 'primary'),
        };
        $c[] = static::kv('variant', static::qs(in_array($variant, ['primary', 'danger', 'secondary', 'ghost', 'success', 'warning'], true) ? $variant : 'primary'));

        $min = isset($a['min']) && $a['min']['type'] === 'string' && is_numeric($a['min']['value'])
            ? max(0, (int) $a['min']['value'])
            : 1;
        $c[] = "'min' => {$min}";

        return '[' . implode(', ', $c) . ']';
    }

    // ── Filtro avançado (<mad-custom-filters>) ───────────────────────────────

    /**
     * Operadores por tipo do filtro avançado — ALLOWLIST de compile-time e de
     * runtime (o MadGridCustomFilters revalida contra ela em toda requisição).
     * A ordem é a ordem da UI; o primeiro é o default.
     *
     * `empty`/`not empty` só existem em texto: comparar coluna inteira com ''
     * derruba a query no Postgres. Pública porque a trait do grid consome a
     * MESMA lista — duas listas divergiriam em silêncio.
     */
    public const CUSTOM_FILTER_KIND_OPS = [
        'text'     => ['like', 'not like', '=', '!=', 'starts', 'ends', 'in', 'empty', 'not empty'],
        'number'   => ['=', '!=', '>', '>=', '<', '<=', 'between', 'in', 'is null', 'is not null'],
        'date'     => ['date', '<', '>', 'date between', 'date preset', 'is null', 'is not null'],
        'datetime' => ['date', '<', '>', 'date between', 'date preset', 'is null', 'is not null'],
        'bool'     => ['='],
        'select'   => ['=', '!=', 'in', 'not in'],
        'dbcombo'  => ['=', '!=', 'in', 'not in', 'is null', 'is not null'],
        'dbsearch' => ['=', '!=', 'in', 'not in', 'is null', 'is not null'],
    ];

    /**
     * Nomes aceitos em `type=` além dos canônicos. Inclui os aliases do
     * `filter-type=` (FILTER_KIND_ALIASES) — os de intervalo caem no tipo base,
     * porque aqui o intervalo é um OPERADOR (`between`/`date between`), não um
     * tipo à parte.
     */
    public const CUSTOM_FILTER_KIND_ALIASES = [
        'boolean'         => 'bool',
        'daterange'       => 'date',
        'date-range'      => 'date',
        'numberrange'     => 'number',
        'number-range'    => 'number',
        'combo'           => 'dbcombo',
        'search'          => 'dbsearch',
        'multi'           => 'select',
        'dbselect'        => 'dbcombo',
        'dbunique-search' => 'dbsearch',
        'timestamp'       => 'datetime',
        'string'          => 'text',
        'numeric'         => 'number',
        'int'             => 'number',
        'integer'         => 'number',
        'decimal'         => 'number',
        'money'           => 'number',
    ];

    /** Tipo canônico do filtro avançado; desconhecido cai em `text`. */
    public static function customFilterKind(string $type): string
    {
        $kind = strtolower(trim($type));
        $kind = static::CUSTOM_FILTER_KIND_ALIASES[$kind] ?? $kind;

        return isset(static::CUSTOM_FILTER_KIND_OPS[$kind]) ? $kind : 'text';
    }

    /**
     * Operadores efetivos do tipo: `ops` (lista separada por vírgula ou array)
     * INTERSECTADO com a allowlist, na ordem da allowlist. Intersecção vazia
     * (ou `ops` ausente) = lista completa do tipo — um `ops` todo inválido não
     * pode deixar a coluna sem operador nenhum.
     *
     * @param  array<int, string>|string $ops
     * @return list<string>
     */
    public static function customFilterOps(string $kind, array|string $ops = []): array
    {
        $allowed = static::CUSTOM_FILTER_KIND_OPS[$kind] ?? static::CUSTOM_FILTER_KIND_OPS['text'];

        $asked = is_array($ops) ? $ops : explode(',', $ops);
        $want  = [];
        foreach ($asked as $op) {
            if (! is_string($op)) continue;
            $canon = MadDataGrid::canonicalFilterOp($op);
            if ($canon !== '') $want[$canon] = true;
        }

        $out = array_values(array_filter($allowed, fn ($op) => isset($want[$op])));

        return $out === [] ? $allowed : $out;
    }

    /**
     * Normaliza o `field` de um filtro avançado: aceita coluna crua, ref do
     * editor (`{nome}`) e chain (`{cidade->estado->nome}`, até 3 saltos).
     * Devolve a forma SEM chaves (`cidade->estado->nome`) — é ela que vira a
     * chave da regra —, ou null quando não é identificador/caminho válido.
     */
    public static function normalizeCustomFilterField(string $field): ?string
    {
        $f = trim($field);
        if (strlen($f) > 2 && $f[0] === '{' && substr($f, -1) === '}') {
            $f = trim(substr($f, 1, -1));
        }
        $ident = '[A-Za-z_][A-Za-z0-9_]*';

        return preg_match('/^' . $ident . '(?:->' . $ident . '){0,3}$/', $f) ? $f : null;
    }

    /**
     * `<mad-custom-filters>` → literal PHP do config (container + defs).
     *
     * Tudo que dá para decidir em compile-time é decidido aqui (tipo, lista de
     * operadores, campo normalizado, chave duplicada); o runtime revalida o
     * mesmo contrato porque o config também pode chegar montado à mão.
     */
    protected static function buildCustomFiltersConfig(array $a, string $body): string
    {
        $save = strtolower(static::str($a, 'save', 'shared'));
        if (! in_array($save, ['shared', 'user', 'off'], true)) $save = 'shared';

        $match = strtolower(static::str($a, 'match', 'all'));
        if (! in_array($match, ['all', 'any'], true)) $match = 'all';

        $share = strtolower(static::str($a, 'share', 'admin'));
        if (! in_array($share, ['admin', 'everyone'], true)) $share = 'admin';

        $max = 15;
        $rawMax = static::str($a, 'max', '');
        if ($rawMax !== '' && is_numeric($rawMax) && (int) $rawMax > 0) {
            $max = min(20, (int) $rawMax);
        }

        $defs = [];
        $seen = [];
        preg_match_all(
            '/<mad-custom-filter(?![\w-])(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-custom-filter') . '/s',
            $body,
            $children,
            PREG_SET_ORDER
        );
        foreach ($children as $child) {
            $ca    = static::parseAttrs($child[1]);
            $field = static::normalizeCustomFilterField(static::str($ca, 'field'));
            if ($field === null) {
                static::warn('<mad-custom-filter> com field inválido ("' . static::str($ca, 'field') . '") — ignorado.');
                continue;
            }
            // A chave da regra é o campo: duas defs no mesmo campo seriam
            // indistinguíveis no estado. A primeira vence.
            if (isset($seen[$field])) {
                static::warn('<mad-custom-filter field="' . $field . '"> duplicado — só o primeiro vale.');
                continue;
            }
            $seen[$field] = true;
            $defs[] = static::buildCustomFilterDef($ca, $field);
        }

        $c = [
            static::kv('save',  static::qs($save)),
            static::kv('match', static::qs($match)),
            "'max' => {$max}",
            static::kv('share', static::qs($share)),
            static::kv('label', isset($a['label']) && $a['label']['type'] !== 'bool' ? static::emit($a['label']) : "''"),
            "'defs' => " . ($defs === [] ? '[]' : "[\n        " . implode(",\n        ", $defs) . "\n    ]"),
        ];

        return '[' . implode(', ', $c) . ']';
    }

    /** Um `<mad-custom-filter>` → literal PHP da def (campo já normalizado). */
    protected static function buildCustomFilterDef(array $a, string $field): string
    {
        $kind = static::customFilterKind(static::str($a, 'type', 'text'));
        $ops  = static::customFilterOps($kind, static::str($a, 'ops', ''));

        $c = [
            static::kv('key',   static::qs($field)),
            static::kv('field', static::qs($field)),
            static::kv('label', isset($a['label']) && $a['label']['type'] !== 'bool' ? static::emit($a['label']) : static::qs($field)),
            static::kv('kind',  static::qs($kind)),
            "'ops' => [" . implode(', ', array_map(fn ($op) => static::qs($op), $ops)) . ']',
        ];

        if (isset($a['placeholder']) && $a['placeholder']['type'] !== 'bool') {
            $c[] = static::kv('placeholder', static::emit($a['placeholder']));
        }

        if ($kind === 'select') {
            // Mesmas duas formas do <mad-col filter-opts>: shorthand resolvido em
            // runtime ou expressão PHP crua.
            $c[] = static::kv('opts', isset($a['opts']) && $a['opts']['type'] !== 'bool'
                ? static::emitOptsAttr($a['opts'])
                : '[]');
        }

        if ($kind === 'bool') {
            // Valores GRAVADOS no banco (S/N, 1/0…). Vazio = default: '' como
            // valor de "Sim" tornaria as duas opções indistinguíveis.
            $true  = static::str($a, 'true', '');
            $false = static::str($a, 'false', '');
            $c[] = static::kv('true',  static::qs($true !== '' ? $true : '1'));
            $c[] = static::kv('false', static::qs($false !== '' ? $false : '0'));
        }

        if ($kind === 'dbcombo' || $kind === 'dbsearch') {
            foreach (['model' => 'model', 'display' => 'display', 'key' => 'keyField', 'database' => 'database'] as $src => $dst) {
                $val = static::str($a, $src, '');
                if ($val !== '') $c[] = static::kv($dst, static::qs($val));
            }
            // order-by="nome desc" (coluna + direção opcional) ou order="desc".
            $orderBy = trim(static::str($a, 'order-by', ''));
            $order   = strtolower(static::str($a, 'order', ''));
            if ($orderBy !== '') {
                $bits    = preg_split('/\s+/', $orderBy) ?: [$orderBy];
                $orderBy = (string) $bits[0];
                if (isset($bits[1]) && $order === '') $order = strtolower((string) $bits[1]);
                $c[] = static::kv('orderBy', static::qs($orderBy));
            }
            if (in_array($order, ['asc', 'desc'], true)) {
                $c[] = static::kv('order', static::qs($order));
            }
            $minLen = static::str($a, 'min-length', '');
            if ($minLen !== '' && is_numeric($minLen)) {
                $c[] = "'minLength' => " . max(0, (int) $minLen);
            }
            // Regras de carregamento das opções (expressão PHP), como :filter-filters.
            if (isset($a['filters']) && $a['filters']['type'] === 'php') {
                $c[] = static::kv('filters', static::emit($a['filters']));
            }
        }

        return '[' . implode(', ', $c) . ']';
    }

    // ── Col config ────────────────────────────────────────────────────────────

    protected static function buildColConfig(array $a): string
    {
        $c = [];

        $c[] = static::kv('field', static::qs(static::str($a, 'field')));
        $c[] = static::kv('label', isset($a['label']) ? static::emit($a['label']) : "''");

        // Width
        if (isset($a['width'])) {
            if ($a['width']['type'] === 'string') {
                $w = $a['width']['value'];
                if (is_numeric($w)) $w .= 'px';
                $c[] = static::kv('width', static::qs($w));
            } else {
                $c[] = static::kv('width', static::emit($a['width']));
            }
        }

        // Align
        if (static::has($a, 'center'))       $c[] = "'align' => 'center'";
        elseif (static::has($a, 'right'))    $c[] = "'align' => 'right'";
        elseif (isset($a['align']))          $c[] = static::kv('align', static::qs(static::str($a, 'align')));

        // Flags
        if (static::has($a, 'sort', 'sortable'))  $c[] = "'sortable' => true";
        if (static::has($a, 'hide', 'hidden'))     $c[] = "'hidden' => true";
        if (static::has($a, 'edit', 'editable'))   $c[] = "'editable' => true";

        // Card role
        if (isset($a['card-role'])) $c[] = "'cardRole' => " . static::qs(static::str($a, 'card-role'));

        // Tipo de edição inline
        if (isset($a['edit-type'])) {
            $c[] = static::kv('editType', static::qs(static::str($a, 'edit-type', 'text')));
        }
        if (isset($a['edit-opts'])) {
            $c[] = static::kv('editOpts', static::emit($a['edit-opts']));
        }
        if (isset($a['edit-decimals'])) {
            $c[] = "'editDecimals' => " . (int)$a['edit-decimals']['value'];
        }
        if (isset($a['edit-rows'])) {
            $c[] = "'editRows' => " . (int)$a['edit-rows']['value'];
        }
        if (isset($a['edit-mode'])) {
            $c[] = static::kv('editMode', static::qs(static::str($a, 'edit-mode', 'dblclick')));
        }
        if (isset($a['edit-prefix'])) {
            $c[] = static::kv('editPrefix', static::qs(static::str($a, 'edit-prefix', '')));
        }
        if (isset($a['edit-suffix'])) {
            $c[] = static::kv('editSuffix', static::qs(static::str($a, 'edit-suffix', '')));
        }
        // dbcombo / dbunique-search
        if (isset($a['edit-model'])) {
            $c[] = static::kv('editModel', static::qs(static::str($a, 'edit-model')));
        }
        if (isset($a['edit-database'])) {
            $c[] = static::kv('editDatabase', static::qs(static::str($a, 'edit-database')));
        }
        if (isset($a['edit-key'])) {
            $c[] = static::kv('editKey', static::qs(static::str($a, 'edit-key', 'id')));
        }
        if (isset($a['edit-display'])) {
            $c[] = static::kv('editDisplay', static::qs(static::str($a, 'edit-display', 'nome')));
        }
        if (isset($a['edit-order-by'])) {
            $c[] = static::kv('editOrderBy', static::qs(static::str($a, 'edit-order-by')));
        }
        if (isset($a['edit-filters'])) {
            $c[] = static::kv('editFilters', static::emit($a['edit-filters']));
        }
        if (isset($a['edit-min-length'])) {
            $c[] = "'editMinLength' => " . (int)$a['edit-min-length']['value'];
        }
        // spinner / number / numeric
        if (isset($a['edit-min'])) {
            $c[] = "'editMin' => " . static::emit($a['edit-min']);
        }
        if (isset($a['edit-max'])) {
            $c[] = "'editMax' => " . static::emit($a['edit-max']);
        }
        if (isset($a['edit-step'])) {
            $c[] = "'editStep' => " . static::emit($a['edit-step']);
        }
        // color
        if (isset($a['edit-colors'])) {
            $c[] = static::kv('editColors', static::emit($a['edit-colors']));
        }

        // Total / Group
        if (isset($a['total'])) $c[] = static::kv('totalFunc', static::qs(static::str($a, 'total')));
        // total-mask="Total: {value}" — máscara do rodapé e do sub-total. Sem
        // esta linha a prop existia no GridColumn e no Blade mas nunca chegava
        // nele: o atributo era engolido pela allowlist em silêncio.
        if (isset($a['total-mask'])) $c[] = static::kv('totalMask', static::emit($a['total-mask']));
        if (isset($a['group'])) $c[] = static::kv('group',     static::qs(static::str($a, 'group')));

        // ── Saldo acumulado (running balance) ────────────────────────────────
        // `running` nua acumula o próprio campo; com valor, acumula o delta da
        // expressão ('{credito} - {debito}', mesma DSL do evaluate).
        if (isset($a['running'])) {
            $expr = $a['running']['type'] === 'bool' ? 'self' : static::str($a, 'running', 'self');
            $c[] = $a['running']['type'] === 'php'
                ? static::kv('running', static::emit($a['running']))
                : static::kv('running', static::qs($expr !== '' ? $expr : 'self'));
        }
        if (isset($a['running-reset'])) $c[] = static::kv('runningReset', static::qs(static::str($a, 'running-reset')));
        if (isset($a['running-start'])) $c[] = static::kv('runningStart', static::emit($a['running-start']));

        // Render type
        if (static::has($a, 'html')) {
            $c[] = "'renderType' => 'html'";
        } elseif (isset($a['badge'])) {
            $c[] = "'renderType' => 'badge'";
            $attr = $a['badge'];
            if ($attr['type'] === 'php') {
                // :badge="$map" → PHP expression
                $c[] = static::kv('badgeMap', $attr['value']);
            } elseif ($attr['type'] === 'bool') {
                // badge (sem valor) → mapa vazio
                $c[] = "'badgeMap' => []";
            } else {
                // badge="A:success:Ativo|I:danger:Inativo"
                $c[] = static::kv('badgeMap', static::parseBadgeString($attr['value']));
            }
        } elseif (isset($a['money'])) {
            $c[] = "'renderType' => 'money'";
            $prefix = $a['money']['type'] === 'string' ? $a['money']['value'] : '';
            $c[] = static::kv('moneyPrefix', static::qs($prefix));
        } elseif (isset($a['date'])) {
            $c[] = "'renderType' => 'date'";
            $fmt = $a['date']['type'] === 'string' && $a['date']['value'] ? $a['date']['value'] : 'd/m/Y';
            $c[] = static::kv('dateFormat', static::qs($fmt));
        } elseif (isset($a['num']) || isset($a['number'])) {
            $c[] = "'renderType' => 'number'";
            $key  = isset($a['num']) ? 'num' : 'number';
            $dec  = $a[$key]['type'] === 'string' ? (int)$a[$key]['value'] : 2;
            $c[] = "'numberDecimals' => {$dec}";
        }

        // Display condition: 'Classe::metodo' estático
        if (isset($a['display-condition'])) {
            $c[] = static::kv('displayCondition', static::qs(static::str($a, 'display-condition')));
        }

        // Transform estático: 'Classe::metodo'
        if (isset($a['transform'])) {
            $c[] = static::kv('transform', static::qs(static::str($a, 'transform')));
        }

        // Evaluate: expressão computada '{campo} * {campo}' ou '{rel->campo}'
        if (isset($a['evaluate'])) {
            $c[] = static::kv('evaluate', static::emit($a['evaluate']));
        }

        // Filter — atributo simples (comportamento existente: live debounced)
        if (isset($a['filter'])) {
            $c[] = "'filterable' => true";
            $ftype = ($a['filter']['type'] === 'string' && $a['filter']['value'])
                ? $a['filter']['value']
                : 'text';
            $c[] = static::kv('filterType', static::qs($ftype));
            if (isset($a['filter-opts'])) {
                $c[] = static::kv('filterOpts', static::emitOptsAttr($a['filter-opts']));
            }
        }

        // ── Filtro tipado seguro (filter-type=) ──────────────────────────────
        // Contrato do editor visual. Diferente do `filter` legado, aqui campo e
        // operador viajam num token assinado (cunhado no render por
        // MadDataGrid::_normalizeFilterTokens), então o cliente manda só o valor.
        //
        // ⚠️ Esta cadeia de ifs é ALLOWLIST: prop de filtro que não entre aqui é
        // descartada em silêncio — o componente nunca a vê.
        $ftKind = static::str($a, 'filter-type', '')
            ?: static::str($a, 'data-col-filter-type', '');

        if ($ftKind !== '') {
            [$kind, $fop] = static::resolveFilterKind(
                $ftKind,
                static::str($a, 'filter-op', '') ?: static::str($a, 'data-col-filter-op', '')
            );
            $c[] = static::kv('filterKind', static::qs($kind));
            $c[] = static::kv('filterOp',   static::qs($fop));

            $ffield = static::str($a, 'filter-field', '')
                ?: static::str($a, 'data-col-filter-field', '');
            if ($ffield !== '') $c[] = static::kv('filterField', static::qs($ffield));

            // Opções: prop achatada vence; senão o data-* do açúcar.
            if (isset($a['filter-opts'])) {
                $c[] = static::kv('filterOpts', static::emitOptsAttr($a['filter-opts']));
            } elseif (isset($a['data-col-filter-opts'])) {
                $c[] = static::kv('filterOpts', static::emitOptsAttr($a['data-col-filter-opts']));
            }

            $strPairs = [
                'filterModel'    => ['filter-model',    'data-col-filter-model',    ''],
                'filterDisplay'  => ['filter-display',  'data-col-filter-display',  'nome'],
                'filterKey'      => ['filter-key',      'data-col-filter-key',      'id'],
                'filterDatabase' => ['filter-database', 'data-col-filter-database', ''],
                'filterOrderBy'  => ['filter-order-by', 'data-col-filter-order-by', ''],
                'filterTrue'     => ['filter-true',     'data-col-filter-true',     '1'],
                'filterFalse'    => ['filter-false',    'data-col-filter-false',    '0'],
            ];
            foreach ($strPairs as $target => [$flat, $hoisted, $default]) {
                $val = static::str($a, $flat, '') ?: static::str($a, $hoisted, '');
                if ($val !== '') {
                    $c[] = static::kv($target, static::qs($val));
                }
            }

            // Direção do order-by das options — validada aqui (allowlist):
            // valor fora de asc|desc é descartado em compile-time, nunca chega
            // no orderBy() do carregamento.
            $fOrder = strtolower(static::str($a, 'filter-order', '')
                ?: static::str($a, 'data-col-filter-order', ''));
            if (in_array($fOrder, ['asc', 'desc'], true)) {
                $c[] = static::kv('filterOrder', static::qs($fOrder));
            }

            $minLen = static::str($a, 'filter-min-length', '')
                ?: static::str($a, 'data-col-filter-min-length', '');
            if ($minLen !== '') $c[] = "'filterMinLength' => " . (int) $minLen;

            // Placeholder: prop achatada vence; senão o içado do açúcar
            // <mad-col-filter placeholder=...> (string-only, como os demais).
            if (isset($a['filter-placeholder'])) {
                $c[] = static::kv('filterPlaceholder', static::strExpr($a, 'filter-placeholder'));
            } elseif (isset($a['data-col-filter-placeholder'])) {
                $c[] = static::kv('filterPlaceholder', static::qs(static::str($a, 'data-col-filter-placeholder')));
            }

            // Regras de carregamento das opções (expressão PHP).
            if (isset($a['filter-filters'])) {
                $c[] = static::kv('filterFilters', static::emit($a['filter-filters']));
            }

            // Expressões do açúcar <mad-col-filter :opts / :filters>.
            if (isset($a['data-col-filter-expr'])) {
                $ekey  = static::str($a, 'data-col-filter-expr');
                $exprs = static::$_filterExprs[$ekey] ?? [];
                if (isset($exprs['opts']) && !isset($a['filter-opts']) && !isset($a['data-col-filter-opts'])) {
                    $c[] = static::kv('filterOpts', $exprs['opts']);
                }
                if (isset($exprs['filters']) && !isset($a['filter-filters'])) {
                    $c[] = static::kv('filterFilters', $exprs['filters']);
                }
            }
        }

        // Filter Popover — novo popover com botões Filtrar/Limpar
        // Via atributo booleano:  filter-popover
        // Via conteúdo customizado: injetado por <mad-col-filter> como data-fpop-key
        if (static::has($a, 'filter-popover') || isset($a['data-fpop-key'])) {
            if (isset($a['data-fpop-key'])) {
                $key  = static::str($a, 'data-fpop-key');
                $html = static::$_filterPopovers[$key] ?? '';
                $c[]  = static::kv('filterPopover', static::qs($html));
            } else {
                $c[] = "'filterPopover' => '__default__'";
            }
            if (static::has($a, 'filter-op-select')) {
                $c[] = "'filterOpSelect' => true";
            }
        }

        // Col-filter seguro (criptografado) — col-filter="op" ou <mad-col-filter>
        //
        // ⚠️ O guard `$ftKind === ''` é obrigatório: o açúcar
        // <mad-col-filter type="dbcombo" op="="> iça data-col-filter-op, e sem o
        // guard este bloco emitiria um SEGUNDO 'colFilterToken' no MESMO array
        // literal. PHP aceita a chave duplicada, a última vence — o bug só
        // apareceria em runtime, com o operador errado e nenhum aviso.
        $hasColFilter = $ftKind === ''
            && (static::has($a, 'col-filter') || isset($a['data-col-filter-op']));
        if ($hasColFilter) {
            $colField = static::str($a, 'field');  // field da coluna

            // col-filter="op" direto no <mad-col> → usa field da coluna
            if (static::has($a, 'col-filter')) {
                $cfOp    = static::str($a, 'col-filter', 'like');
                if ($cfOp === '' || $cfOp === '1') $cfOp = 'like'; // booleano sem valor
                $cfField = $colField;
                $cfSub   = '';
            }
            // <mad-col-filter field="x" op="y" subselect="z"> → attrs em data-col-filter-*
            else {
                $cfField = static::str($a, 'data-col-filter-field', '') ?: $colField;
                $cfOp    = static::str($a, 'data-col-filter-op', 'like');
                $cfSub   = static::str($a, 'data-col-filter-sub', '');
            }

            // Gera token criptografado em runtime PHP
            $def = "['field' => " . static::qs($cfField) . ", 'op' => " . static::qs($cfOp) . "]";
            if ($cfSub) {
                $def = "['field' => " . static::qs($cfField) . ", 'op' => " . static::qs($cfOp) . ", 'sub' => " . static::qs($cfSub) . "]";
            }
            $c[] = "'colFilterToken' => \\Mad\\Http\\MadStateCrypt::encrypt({$def})";
            $c[] = "'colFilterField' => " . static::qs($cfField);

            // Se tem conteúdo customizado (fpop), reutiliza
            if (isset($a['data-fpop-key'])) {
                $key  = static::str($a, 'data-fpop-key');
                $html = static::$_filterPopovers[$key] ?? '';
                $c[]  = static::kv('filterPopoverHtml', static::qs($html));
            }
        }

        return '[' . implode(', ', $c) . ']';
    }

    /**
     * Parseia shorthand de badge: "A:success:Ativo|I:danger:Inativo"
     * Formatos por segmento (split em '|'):
     *   valor           → 'valor' => 'secondary'
     *   valor:variant   → 'valor' => 'variant'
     *   valor:variant:label → 'valor' => 'variant:label'
     */
    protected static function parseBadgeString(string $str): string
    {
        $items = [];
        foreach (explode('|', $str) as $part) {
            $segs = explode(':', trim($part), 3);
            $val  = addslashes(trim($segs[0]));
            if (count($segs) === 1) {
                $items[] = "'{$val}' => 'secondary'";
            } elseif (count($segs) === 2) {
                $variant = addslashes(trim($segs[1]));
                $items[] = "'{$val}' => '{$variant}'";
            } else {
                $variant = addslashes(trim($segs[1]));
                $label   = addslashes(trim($segs[2]));
                $items[] = "'{$val}' => '{$variant}:{$label}'";
            }
        }
        return '[' . implode(', ', $items) . ']';
    }

    // ── Action configs ────────────────────────────────────────────────────────

    protected static function buildNavConfig(array $a): string
    {
        $c = [];
        $c[] = "'isNav' => true";

        // Parseia navigate="Classe::metodo({campo1}, {campo2})" ou target="Classe"
        $nav = static::str($a, 'navigate', static::str($a, 'target'));
        // `(.*)` e não `(.+)`: `Classe::onShow()` (parênteses vazios, gravados
        // pelo studio) não casava e caía no else, onde a string INTEIRA virava o
        // nome da classe e o método virava 'show'.
        if (preg_match('/^(\w+)(?:::(\w+))?\s*(?:\((.*)\))?$/', $nav, $nm)) {
            $c[] = static::kv('navClass', static::qs($nm[1]));
            $c[] = static::kv('navMethod', static::qs(!empty($nm[2]) ? $nm[2] : static::str($a, 'method', 'show')));

            // Params inline: navigate="Form::onEdit({id}, {tipo})"
            if (!empty($nm[3])) {
                $inlineParams = array_map('trim', explode(',', $nm[3]));
                $pairs = [];
                foreach ($inlineParams as $p) {
                    $paramKey = trim($p, '{} ');
                    $pairs[] = "'{$paramKey}' => " . static::qs($p);
                }
                $c[] = "'navParams' => [" . implode(', ', $pairs) . "]";
            }
        } else {
            $c[] = static::kv('navClass', static::qs($nav));
            $c[] = static::kv('navMethod', static::qs(static::str($a, 'method', 'show')));
        }

        // :params="{'id': '{id}', 'modo': 'edit'}" — tem prioridade sobre inline
        if (isset($a['params'])) {
            $c[] = "'navParams' => " . static::emit($a['params']);
        }

        if (static::has($a, 'drawer'))      $c[] = "'navDrawer' => true";
        if (static::has($a, 'row'))         $c[] = "'navRow' => true";
        if (isset($a['icon']))              $c[] = static::kv('icon',    static::strExpr($a, 'icon'));
        if (isset($a['label']))             $c[] = static::kv('label',   static::strExpr($a, 'label'));
        if (isset($a['confirm']))           $c[] = static::kv('confirm', static::strExpr($a, 'confirm'));
        if (isset($a['confirm-popover']))   $c[] = static::kv('confirmPopover', static::strExpr($a, 'confirm-popover'));
        array_push($c, ...static::actVariantFlags($a));
        if (isset($a['display-condition'])) $c[] = static::kv('when', static::qs(static::str($a, 'display-condition')));
        if (isset($a['transform']))         $c[] = static::kv('transform', static::qs(static::str($a, 'transform')));
        return '[' . implode(', ', $c) . ']';
    }

    /**
     * Variante visual de uma ação de linha (`<mad-act>`/`<mad-nav>`) → flags do
     * GridAction. Aceita a flag booleana (`danger`, `primary`) E `variant="…"` —
     * o gerador de listagens da plataforma emite `variant="danger"` no botão de
     * excluir, e só a flag era lida: o ícone saía neutro em vez de vermelho.
     *
     * O GridAction só pinta `danger` e `primary`; os demais valores (`success`,
     * `info`, `warning`, `default`…) continuam no botão neutro, como antes.
     * Ordem primary→danger preservada (é a ordem histórica das duas flags).
     *
     * @return list<string> pares `'chave' => true` prontos para o array config
     */
    protected static function actVariantFlags(array $a): array
    {
        $variant = strtolower(trim(static::str($a, 'variant')));
        $flags   = [];
        if (static::has($a, 'primary') || $variant === 'primary') $flags[] = "'primary' => true";
        if (static::has($a, 'danger')  || $variant === 'danger')  $flags[] = "'danger' => true";
        return $flags;
    }

    protected static function buildDelConfig(array $a): string
    {
        return '[' . implode(', ', [
            "'method'  => 'onMadGridDelete'",
            "'danger'  => true",
            static::kv('confirm', static::strExpr($a, 'confirm', static::qs('Excluir este registro?'))),
            static::kv('icon',    static::strExpr($a, 'icon',    static::qs('trash-2'))),
            static::kv('label',   static::strExpr($a, 'label',   static::qs('Excluir'))),
        ]) . ']';
    }

    protected static function buildActConfig(array $a): string
    {
        $c = [];

        // method="X" OU mad:click="X" (alias). parseAttrs pode partir
        // "mad:click" em "mad" (bool) + "click" (php), entao checamos ambas formas.
        $method = static::str($a, 'method');
        if ($method === '') {
            if (isset($a['mad:click'])) {
                $method = (string) $a['mad:click']['value'];
            } elseif (isset($a['click']) && static::has($a, 'mad')) {
                $method = (string) $a['click']['value'];
            }
        }
        $c[] = static::kv('method', static::qs($method));
        if (isset($a['icon']))     $c[] = static::kv('icon',    static::strExpr($a, 'icon'));
        if (isset($a['label']))    $c[] = static::kv('label',   static::strExpr($a, 'label'));
        if (isset($a['confirm']))         $c[] = static::kv('confirm', static::strExpr($a, 'confirm'));
        if (isset($a['confirm-popover'])) $c[] = static::kv('confirmPopover', static::strExpr($a, 'confirm-popover'));
        array_push($c, ...static::actVariantFlags($a));
        if (isset($a['transform']))     $c[] = static::kv('transform', static::qs(static::str($a, 'transform')));

        // Display condition como string callable 'Classe::metodo'
        if (isset($a['display-condition'])) {
            $c[] = static::kv('when', static::qs(static::str($a, 'display-condition')));
        }

        // Condição de visibilidade: when-field + when-value/when-in/when-nin + when-op
        $whenField = static::str($a, 'when-field');
        if ($whenField) {
            if (isset($a['when-in']) || isset($a['when-nin'])) {
                $isNin   = isset($a['when-nin']);
                $csvVal  = static::str($a, $isNin ? 'when-nin' : 'when-in');
                $vals    = array_map('trim', explode(',', $csvVal));
                $valsStr = "['" . implode("','", array_map('addslashes', $vals)) . "']";
                $c[] = "'when' => ['field' => " . static::qs($whenField)
                     . ", 'op' => " . ($isNin ? "'nin'" : "'in'")
                     . ", 'value' => " . $valsStr . "]";
            } else {
                $whenVal = static::str($a, 'when-value');
                $whenOp  = static::str($a, 'when-op', 'eq');
                $c[] = "'when' => ['field' => " . static::qs($whenField)
                     . ", 'op' => " . static::qs($whenOp)
                     . ", 'value' => " . static::qs($whenVal) . "]";
            }
        }

        // Condição de desabilitado: disabled-field + disabled-value/disabled-in/disabled-nin
        $disField = static::str($a, 'disabled-field');
        if ($disField) {
            if (isset($a['disabled-in']) || isset($a['disabled-nin'])) {
                $isNin   = isset($a['disabled-nin']);
                $csvVal  = static::str($a, $isNin ? 'disabled-nin' : 'disabled-in');
                $vals    = array_map('trim', explode(',', $csvVal));
                $valsStr = "['" . implode("','", array_map('addslashes', $vals)) . "']";
                $c[] = "'disabled' => ['field' => " . static::qs($disField)
                     . ", 'op' => " . ($isNin ? "'nin'" : "'in'")
                     . ", 'value' => " . $valsStr . "]";
            } else {
                $disVal = static::str($a, 'disabled-value');
                $disOp  = static::str($a, 'disabled-op', 'eq');
                $c[] = "'disabled' => ['field' => " . static::qs($disField)
                     . ", 'op' => " . static::qs($disOp)
                     . ", 'value' => " . static::qs($disVal) . "]";
            }
        }

        return '[' . implode(', ', $c) . ']';
    }

    // ── Field-list block compiler ─────────────────────────────────────────────

    /**
     * Compila <mad-field-list name="itens" addable removable ...>
     *   <mad-field-list-column field="nome" label="Nome" type="text" ... />
     * </mad-field-list>
     *
     * Gera PHP com array de FieldListColumn + <x-field-list /> tag.
     * Rows auto-resolvidos do contexto MadRenderContext (form fields achatados).
     */
    protected static function compileFieldListBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $inner   = $match[2] ?? '';

        $attrs    = static::parseAttrs($attrStr);
        $name     = static::str($attrs, 'name', 'field_list');
        $safeName = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);

        // ── Scan ORDENADO dos filhos: group / column / action ────────────────
        // Uma passada só, em ordem de documento, registrando os trechos
        // reconhecidos pra apurar o que sobrou (ver "conteúdo descartado" abaixo).
        $colChains    = [];
        $actionChains = [];
        $grpStack     = [];   // pilha de <mad-field-list-group> (1 nível suportado)
        $grpSeq       = 0;
        $spans        = [];   // [offset, len] de cada filho reconhecido
        $warn         = [];   // erros de ESTRUTURA do corpo (filho inválido, sobra)
        $warnAttr     = [];   // erros de ATRIBUTO numa coluna (ex: width inválido)

        preg_match_all(static::FL_CHILD_RE, $inner, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tokens as $t) {
            $spans[]   = [$t[0][1], strlen($t[0][0])];
            $isClose   = $t[1][0] === '/';
            $kind      = $t[2][0];
            $childAttr = static::parseAttrs(rtrim($t[3][0], " \t\r\n/"));

            if ($kind === 'group') {
                if ($isClose) { array_pop($grpStack); continue; }
                if ($grpStack) {
                    $warn[] = '<mad-field-list-group> aninhado não é suportado — vale o rótulo mais interno.';
                }
                $grpStack[] = [
                    'label' => isset($childAttr['label']) ? static::emit($childAttr['label']) : "''",
                    'key'   => 'g' . $grpSeq++,
                ];
                continue;
            }
            if ($isClose) continue;   // </...-column> / </...-action>: nada a fazer

            if ($kind === 'column') {
                // width literal fora do vocabulário de track: o render degrada
                // pra 1fr (FieldListColumn::cssTrack), mas em SILÊNCIO um typo
                // aqui é indistinguível de largura mal escolhida. Numérico não
                // avisa — '140' é o contrato documentado (px implícito).
                if (isset($childAttr['width']) && $childAttr['width']['type'] === 'string') {
                    $wRaw = trim((string) $childAttr['width']['value']);
                    if ($wRaw !== '' && !is_numeric($wRaw)
                        && \Mad\Form\FieldListColumn::cssTrack($wRaw) !== $wRaw) {
                        $fld = static::str($childAttr, 'field');
                        $warnAttr[] = "coluna '{$fld}': width=\"{$wRaw}\" não é track CSS válido"
                            . ' (140, 140px, 30%, 1fr, auto, minmax(80px,1fr)) — a coluna vai usar 1fr.';
                    }
                }

                $chain = static::buildFieldListColumnConfig($childAttr);
                if ($grpStack) {
                    // Wrapper vence um group="..." escrito na própria coluna.
                    $g      = end($grpStack);
                    $chain .= "->group({$g['label']})->groupKey('{$g['key']}')";
                }
                $colChains[] = $chain;
            } else {
                $actionChains[] = static::buildFieldListActionConfig($childAttr);
            }
        }
        if ($grpStack) {
            $warn[] = '<mad-field-list-group> sem fechamento — o grupo vai até o fim da lista.';
        }

        // ── Sobra no corpo = erro de authoring (antes sumia sem diagnóstico) ──
        $rest = $inner;
        foreach (array_reverse($spans) as [$off, $len]) {   // de trás pra frente: offsets não deslocam
            $rest = substr_replace($rest, '', $off, $len);
        }
        // compile() já mascarou {{-- --}} em MAD__BLADE_COMMENT__N__ (ver o topo
        // do compile) — grepar por '{{--' aqui nunca casaria.
        $rest = preg_replace('/MAD__BLADE_COMMENT__\d+__/', '', (string) $rest);
        $rest = preg_replace('/<!--[\s\S]*?-->/s', '', (string) $rest);
        if (trim((string) $rest) !== '') {
            $offender = preg_match('/<\s*(\/?[a-zA-Z][\w:.-]*)/', $rest, $om)
                ? '<' . $om[1] . '>'
                : '"' . trim(preg_replace('/\s+/', ' ', mb_substr(trim($rest), 0, 40))) . '"';
            $warn[] = "conteúdo descartado: {$offender}.";
        }

        // ── Gerar PHP: array de colunas ──────────────────────────────────────
        $php = "<?php \$_field_list_{$safeName}_cols = [\n";
        foreach ($colChains as $i => $chain) {
            $comma = $i < count($colChains) - 1 ? ',' : '';
            $php  .= "    {$chain}{$comma}\n";
        }
        $php .= "]; ?>\n";

        // ── Gerar PHP: array de acoes (sempre emitido, mesmo vazio) ──────────
        $php .= "<?php \$_field_list_{$safeName}_actions = [\n";
        foreach ($actionChains as $i => $chain) {
            $comma = $i < count($actionChains) - 1 ? ',' : '';
            $php  .= "    {$chain}{$comma}\n";
        }
        $php .= "]; ?>\n";

        // ── Rows: auto do contexto ou :rows explícito ────────────────────────
        $rowsExpr = isset($attrs['rows'])
            ? static::emit($attrs['rows'])
            : "\${$safeName} ?? []";

        // ── Montar tag <x-field-list /> ──────────────────────────────────────
        $tag  = '<x-field-list';
        $tag .= ' name="' . addslashes($name) . '"';
        $tag .= " :columns=\"\$_field_list_{$safeName}_cols\"";
        $tag .= " :actions=\"\$_field_list_{$safeName}_actions\"";
        $tag .= " :rows=\"{$rowsExpr}\"";

        // Flags booleanas
        foreach (['addable', 'removable', 'sortable'] as $flag) {
            if (static::has($attrs, $flag)) {
                $tag .= " {$flag}=\"1\"";
            }
        }

        // Strings passthrough
        foreach (['label', 'hint', 'add-label', 'class', 'error', 'on-add', 'on-remove', 'on-totalize'] as $prop) {
            if (isset($attrs[$prop])) {
                $val  = static::str($attrs, $prop);
                $tag .= ' ' . $prop . '="' . addslashes($val) . '"';
            }
        }

        // model, foreign-key, database → auto-save/load de details
        if (isset($attrs['model'])) {
            $tag .= ' model="' . addslashes(static::str($attrs, 'model')) . '"';
        }
        if (isset($attrs['foreign-key'])) {
            $tag .= ' foreignKey="' . addslashes(static::str($attrs, 'foreign-key')) . '"';
        }
        if (isset($attrs['database'])) {
            $tag .= ' database="' . addslashes(static::str($attrs, 'database')) . '"';
        }

        // max-rows → maxRows
        if (isset($attrs['max-rows'])) {
            $v = $attrs['max-rows'];
            $tag .= ' maxRows="' . ($v['type'] === 'php' ? $v['value'] : (int)$v['value']) . '"';
        }

        $tag .= ' />';

        $diag = '';
        foreach ($warn as $w) {
            $diag .= static::warn(
                '<mad-field-list name="' . $name . '"> ' . $w
                . ' Filhos válidos: <mad-field-list-column />, <mad-field-list-action />,'
                . ' <mad-field-list-group label="...">. O corpo é lido em tempo de COMPILAÇÃO —'
                . ' @if/@foreach ali dentro não são executados.'
            );
        }
        // Sem o rodapé de "filhos válidos": aqui a estrutura está certa, o valor
        // de um atributo é que não serve.
        foreach ($warnAttr as $w) {
            $diag .= static::warn('<mad-field-list name="' . $name . '"> ' . $w);
        }

        return $diag . $php . $tag;
    }

    /**
     * Constrói a cadeia fluent PHP de um FieldListColumn a partir dos atributos parseados.
     *
     * Exemplo de saída:
     *   \Mad\Form\FieldListColumn::make('valor', 'Valor')->type('money')->width('130px')->decimals(2)->required()
     */
    protected static function buildFieldListColumnConfig(array $attrs): string
    {
        $field = static::emit($attrs['field'] ?? ['type' => 'string', 'value' => '']);
        $label = static::emit($attrs['label'] ?? ['type' => 'string', 'value' => '']);
        $chain = "\\Mad\\Form\\FieldListColumn::make({$field}, {$label})";

        // String setters (nome do atributo = nome do método)
        foreach (['type', 'width', 'placeholder', 'hint', 'group', 'default', 'prefix', 'database', 'model', 'display', 'accept', 'compute', 'where', 'attrs', 'storage', 'folder', 'mask', 'icon'] as $prop) {
            if (isset($attrs[$prop])) {
                $chain .= "->{$prop}(" . static::emit($attrs[$prop]) . ")";
            }
        }

        // Kebab-case → camelCase
        $kebabMap = [
            'type-field'     => 'typeField',
            'key-field'      => 'keyField',
            'order-by'       => 'orderBy',
            // `order` (não `order-dir`): é o nome que o painel do builder já
            // emite há tempo. Sem esta entrada o atributo não tinha setter e
            // caía fora em silêncio — a "Direção" do combo nunca funcionou.
            'order'          => 'orderDir',
            'on-change'      => 'onChange',
            'depends-on'     => 'dependsOn',
            'depends-column' => 'dependsColumn',
            'file-name'      => 'fileName',
            'name-column'    => 'nameColumn',
            'foreign-key'    => 'foreignKey',
            'path-column'    => 'pathColumn',
            'disabled-when'  => 'disabledWhen',
            'readonly-when'  => 'readonlyWhen',
            'required-when'  => 'requiredWhen',
            'visible-when'   => 'visibleWhen',
            'force-case'     => 'forceCase',
            'icon-color'     => 'iconColor',
            'icon-side'      => 'iconSide',
            'max-width'      => 'maxWidth',
        ];
        foreach ($kebabMap as $k => $method) {
            if (isset($attrs[$k])) {
                $chain .= "->{$method}(" . static::emit($attrs[$k]) . ")";
            }
        }

        // Float setters
        foreach (['min', 'max', 'step'] as $prop) {
            if (isset($attrs[$prop])) {
                $v = $attrs[$prop];
                $chain .= "->{$prop}(" . ($v['type'] === 'php' ? $v['value'] : (float)$v['value']) . ")";
            }
        }

        // Int setters
        foreach (['decimals', 'max-size', 'maxlength'] as $prop) {
            if (isset($attrs[$prop])) {
                $v      = $attrs[$prop];
                $method = $prop === 'max-size' ? 'maxSize' : $prop;
                $chain .= "->{$method}(" . ($v['type'] === 'php' ? $v['value'] : (int)$v['value']) . ")";
            }
        }

        // Boolean flags (sem argumentos). O nome do atributo é o nome do método,
        // exceto os kebab, que precisam do mapa (o setter é camelCase).
        $boolFlags = [
            'required'        => 'required',
            'readonly'        => 'readonly',
            'disabled'        => 'disabled',
            'sum'             => 'sum',
            'count'           => 'count',
            'strip-mask'      => 'stripMask',
            'toggle-password' => 'togglePassword',
        ];
        foreach ($boolFlags as $flag => $method) {
            if (static::has($attrs, $flag)) {
                $chain .= "->{$method}()";
            }
        }

        // PHP expression setters
        // 'items' is accepted as alias for 'options'
        if (isset($attrs['items']) && !isset($attrs['options'])) {
            $attrs['options'] = $attrs['items'];
        }
        foreach (['options'] as $prop) {
            if (isset($attrs[$prop])) {
                $chain .= "->{$prop}(" . static::emitOptions($attrs[$prop]) . ")";
            }
        }

        return $chain;
    }

    /**
     * Emite o argumento de `->options(...)`.
     *
     * `:options="['a' => 'b']"` (bind) já sai como expressão PHP pelo emit()
     * normal. O caso tratado aqui é `options="['a' => 'b']"` SEM os dois-pontos:
     * o emit() devolveria a STRING quotada e o setter receberia texto onde
     * esperava array. Antes disso derrubar a view inteira, reconhecemos o
     * literal de array PHP e emitimos a expressão crua.
     *
     * O reconhecimento é por TOKEN, não por regex: só passa array composto de
     * literais (`[`, `]`, `,`, `=>`, string quotada, número, true/false/null).
     * Variável, chamada de função, concatenação e constante ficam de fora — o
     * atributo é conteúdo gerado, não código de confiança.
     *
     * O que NÃO for literal de array (JSON, forma compacta "a:A,b:B", texto
     * solto) segue como string — `FieldListColumn::normalizeOptions()` resolve.
     */
    protected static function emitOptions(array $attr): string
    {
        if (($attr['type'] ?? '') === 'string') {
            $raw = html_entity_decode((string)$attr['value'], ENT_QUOTES | ENT_HTML5);
            if (static::isSafeArrayLiteral($raw)) {
                return $raw;
            }
        }

        return static::emit($attr);
    }

    /** `['a' => 'b', 'c' => 1]` → true. Qualquer coisa com variável/chamada → false. */
    protected static function isSafeArrayLiteral(string $raw): bool
    {
        if (preg_match('/^\s*\[[\s\S]*\]\s*$/', $raw) !== 1 || !str_contains($raw, '=>')) {
            return false;
        }

        try {
            $tokens = token_get_all('<?php ' . $raw . ';', TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }

        $allowedIds = [T_OPEN_TAG, T_WHITESPACE, T_DOUBLE_ARROW, T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER];
        foreach ($tokens as $t) {
            if (is_string($t)) {
                if (!in_array($t, ['[', ']', ',', ';'], true)) {
                    return false;
                }
                continue;
            }
            if (in_array($t[0], $allowedIds, true)) {
                continue;
            }
            // true/false/null são T_STRING — únicos identificadores aceitos.
            if ($t[0] === T_STRING && in_array(strtolower($t[1]), ['true', 'false', 'null'], true)) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Constrói a cadeia fluent PHP de um FieldListAction a partir dos atributos parseados.
     *
     * Suporta dois modos:
     *   1) navigate="Classe" ou navigate="Classe::metodo({campo})" → FieldListAction::makeNav(...)
     *   2) method="onXyz"                                          → FieldListAction::make('onXyz')
     *
     * Se ambos estiverem presentes, navigate ganha.
     *
     * Exemplos de saída:
     *   \Mad\Form\FieldListAction::make('onDuplicar')->icon('copy')->title('Duplicar')
     *   \Mad\Form\FieldListAction::makeNav('ProdutoDetalhe', 'onShow')->params(['id' => '{id}'])->icon('eye')
     *   \Mad\Form\FieldListAction::make('onExcluir')->icon('trash-2')->danger()->confirm('Tem certeza?')
     */
    protected static function buildFieldListActionConfig(array $attrs): string
    {
        $isNav = isset($attrs['navigate']) || isset($attrs['target']);

        if ($isNav) {
            $nav       = static::str($attrs, 'navigate', static::str($attrs, 'target'));
            $navClass  = '';
            $navMethod = static::str($attrs, 'method', 'show');
            $navParamsCode = '';

            // `(.*)`: sem isso, `Classe::onShow()` não casava e o navClass saía
            // vazio (FieldListAction::makeNav('', 'show')) — ação morta.
            if (preg_match('/^(\w+)(?:::(\w+))?\s*(?:\((.*)\))?$/', $nav, $nm)) {
                $navClass  = $nm[1] ?? '';
                if (!empty($nm[2])) {
                    $navMethod = $nm[2];
                }
                if (!empty($nm[3])) {
                    // Inline params: {campo1}, {campo2} — cada um vira 'campo1' => '{campo1}' (placeholder pro JS)
                    $inlineParams = array_map('trim', explode(',', $nm[3]));
                    $pairs = [];
                    foreach ($inlineParams as $p) {
                        $key = trim($p, '{} ');
                        if ($key === '') continue;
                        $pairs[] = static::qs($key) . ' => ' . static::qs('{' . $key . '}');
                    }
                    if (!empty($pairs)) {
                        $navParamsCode = '[' . implode(', ', $pairs) . ']';
                    }
                }
            }

            $chain = "\\Mad\\Form\\FieldListAction::makeNav("
                   . static::qs($navClass) . ", " . static::qs($navMethod) . ")";

            if ($navParamsCode !== '') {
                $chain .= "->params({$navParamsCode})";
            }
            if (static::has($attrs, 'drawer')) {
                $chain .= "->drawer()";
            }
        } else {
            $method = static::str($attrs, 'method');
            $chain  = "\\Mad\\Form\\FieldListAction::make(" . static::qs($method) . ")";
        }

        // :params="[...]" (PHP) ou params="{a: 'x', b: {campo}}" (string) — sobrescreve inline
        if (isset($attrs['params'])) {
            $p = $attrs['params'];
            if ($p['type'] === 'php') {
                $chain .= "->params(" . static::emit($attrs['params']) . ")";
            } else {
                // String format: parseia JS-like object literal para PHP array
                $arrayCode = static::parseInlineParamsToPhp($p['value']);
                if ($arrayCode !== null) {
                    $chain .= "->params({$arrayCode})";
                }
            }
        }

        // String setters
        foreach (['icon', 'label', 'title', 'variant', 'color', 'confirm'] as $prop) {
            if (isset($attrs[$prop])) {
                $chain .= "->{$prop}(" . static::emit($attrs[$prop]) . ")";
            }
        }

        // Flags shorthand (variants como atributos booleanos)
        foreach (['danger', 'primary', 'success', 'warning', 'info'] as $v) {
            if (static::has($attrs, $v)) $chain .= "->{$v}()";
        }

        return $chain;
    }

    /**
     * Parse de string JS-like `{key: value, key2: 'texto', key3: {placeholder}}`
     * para codigo PHP array `['key' => '...', ...]`.
     *
     * Valores suportados:
     *   - placeholder `{campo}` — vira string literal `'{campo}'` (resolvido em JS)
     *   - string com aspas 'x' ou "x"
     *   - string sem aspas (identificador)
     *   - numero (literal)
     *
     * Retorna null se nao conseguir parsear.
     */
    protected static function parseInlineParamsToPhp(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') return "[]";

        // Remove { } externos
        if ($s[0] === '{' && substr($s, -1) === '}') {
            $s = substr($s, 1, -1);
        }
        $s = trim($s);
        if ($s === '') return "[]";

        $pairs = [];
        $parts = static::splitTopLevel($s, ',');

        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') continue;

            // Separa key: value no primeiro ':' que nao esta dentro de {} ou ''
            $kv = static::splitTopLevel($part, ':', 2);
            if (count($kv) !== 2) return null;

            $key = trim($kv[0]);
            $val = trim($kv[1]);

            // Remove aspas da chave se tiver
            if ((($key[0] ?? '') === "'" && substr($key, -1) === "'")
             || (($key[0] ?? '') === '"' && substr($key, -1) === '"')) {
                $key = substr($key, 1, -1);
            }

            // Valor: placeholder {campo} ou string literal ou numero
            if ($val !== '' && $val[0] === '{' && substr($val, -1) === '}') {
                // Placeholder puro — mantem como string (JS resolve)
                $phpVal = static::qs($val);
            } elseif ((($val[0] ?? '') === "'" && substr($val, -1) === "'")
                   || (($val[0] ?? '') === '"' && substr($val, -1) === '"')) {
                $phpVal = static::qs(substr($val, 1, -1));
            } elseif (is_numeric($val)) {
                $phpVal = $val;
            } else {
                // Identificador sem aspas — tratar como string
                $phpVal = static::qs($val);
            }

            $pairs[] = static::qs($key) . ' => ' . $phpVal;
        }

        return '[' . implode(', ', $pairs) . ']';
    }

    /**
     * Divide string em partes pelo delimitador de 1 char, respeitando chaves {} e aspas.
     * Retorna ate $limit partes quando especificado (>= 1).
     */
    protected static function splitTopLevel(string $s, string $delim, int $limit = 0): array
    {
        $out      = [];
        $buf      = '';
        $braces   = 0;
        $quote    = null;
        $len      = strlen($s);

        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];

            if ($quote !== null) {
                $buf .= $ch;
                if ($ch === $quote && ($i === 0 || $s[$i - 1] !== '\\')) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $buf  .= $ch;
                continue;
            }

            if ($ch === '{') { $braces++; $buf .= $ch; continue; }
            if ($ch === '}') { $braces--; $buf .= $ch; continue; }

            if ($braces === 0 && $ch === $delim) {
                if ($limit > 0 && count($out) === $limit - 1) {
                    // Ultimo segmento recebe tudo restante
                    $buf .= substr($s, $i + 1);
                    break;
                }
                $out[] = $buf;
                $buf   = '';
                continue;
            }

            $buf .= $ch;
        }

        $out[] = $buf;
        return $out;
    }

    // ── Edit field helpers ────────────────────────────────────────────────────

    /**
     * Converte atributos de <mad-col-edit type="..." :opts="..." decimals="..." rows="...">
     * para string de atributos a ser embutida no <mad-col>.
     */
    protected static function _editAttrsFromConfig(array $ea): string
    {
        $out  = ' edit';
        $type = static::str($ea, 'type', 'text');
        if ($type !== 'text') $out .= " edit-type=\"{$type}\"";

        if (isset($ea['opts'])) {
            $p    = $ea['opts']['type'] === 'php' ? ':' : '';
            $val  = $ea['opts']['value'];
            $out .= " {$p}edit-opts=\"{$val}\"";
        }
        if (isset($ea['decimals'])) $out .= ' edit-decimals="' . (int)$ea['decimals']['value'] . '"';
        if (isset($ea['rows']))     $out .= ' edit-rows="' . (int)$ea['rows']['value'] . '"';
        if (isset($ea['prefix']))   $out .= ' edit-prefix="' . htmlspecialchars($ea['prefix']['value'], ENT_QUOTES) . '"';
        if (isset($ea['suffix']))   $out .= ' edit-suffix="' . htmlspecialchars($ea['suffix']['value'], ENT_QUOTES) . '"';
        if (isset($ea['model']))    $out .= ' edit-model="'  . htmlspecialchars($ea['model']['value'],  ENT_QUOTES) . '"';
        if (isset($ea['database'])) $out .= ' edit-database="' . htmlspecialchars($ea['database']['value'], ENT_QUOTES) . '"';
        if (isset($ea['key']))      $out .= ' edit-key="'      . htmlspecialchars($ea['key']['value'],      ENT_QUOTES) . '"';
        if (isset($ea['display']))  $out .= ' edit-display="'  . htmlspecialchars($ea['display']['value'],  ENT_QUOTES) . '"';
        if (isset($ea['order-by'])) $out .= ' edit-order-by="' . htmlspecialchars($ea['order-by']['value'], ENT_QUOTES) . '"';
        if (isset($ea['filters'])) {
            $p    = $ea['filters']['type'] === 'php' ? ':' : '';
            $val  = $ea['filters']['value'];
            $out .= " {$p}edit-filters=\"{$val}\"";
        }
        if (isset($ea['min-length'])) $out .= ' edit-min-length="' . (int)$ea['min-length']['value'] . '"';
        if (isset($ea['min'])) {
            $p   = $ea['min']['type'] === 'php' ? ':' : '';
            $out .= " {$p}edit-min=\"{$ea['min']['value']}\"";
        }
        if (isset($ea['max'])) {
            $p   = $ea['max']['type'] === 'php' ? ':' : '';
            $out .= " {$p}edit-max=\"{$ea['max']['value']}\"";
        }
        if (isset($ea['step'])) {
            $p   = $ea['step']['type'] === 'php' ? ':' : '';
            $out .= " {$p}edit-step=\"{$ea['step']['value']}\"";
        }
        if (isset($ea['colors'])) {
            $p   = $ea['colors']['type'] === 'php' ? ':' : '';
            $out .= " {$p}edit-colors=\"{$ea['colors']['value']}\"";
        }
        if (isset($ea['mode'])) $out .= ' edit-mode="' . htmlspecialchars($ea['mode']['value'], ENT_QUOTES) . '"';

        return $out;
    }

    /**
     * Converte o corpo de <mad-col-edit>...</mad-col-edit> (com shorthands)
     * para string de atributos a ser embutida no <mad-col>.
     *
     * Shorthands suportados dentro do bloco:
     *   <select :opts="$expr" />                                → select estatico
     *   <dbcombo model="X" display="nome" />                    → dbcombo (MAD Select)
     *   <dbunique-search model="X" display="nome" />            → busca AJAX
     *   <date />                                                 → date
     *   <datetime />                                             → datetime
     *   <number decimals="2" min="0" max="100" />                → number nativo
     *   <numeric decimals="2" prefix="R$" />                     → mad-numeric-field
     *   <money decimals="2" prefix="R$" />                       → money mask
     *   <spinner min="0" max="10" step="1" />                    → mad-spinner-field
     *   <color :colors="['#f00','#0f0']" />                      → mad-color-field
     *   <textarea rows="3" />                                    → textarea
     *   <text />                                                 → text (default)
     */
    protected static function _editAttrsFromBody(string $body): string
    {
        // <dbunique-search model="X" display="Y" /> — checa antes de <dbcombo> e <select>
        if (preg_match('/<dbunique-search([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="dbunique-search"';
            $out .= static::_emitDbAttrs($da);
            if (isset($da['min-length'])) $out .= ' edit-min-length="' . (int)$da['min-length']['value'] . '"';
            return $out;
        }

        // <dbcombo model="X" display="Y" /> — versao "nova" com auto-query
        if (preg_match('/<dbcombo([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da = static::parseAttrs($m[1]);
            // Compat: se nao tem model mas tem opts, e o shorthand antigo (select estatico)
            if (!isset($da['model']) && isset($da['opts'])) {
                $out = ' edit edit-type="select"';
                $p    = $da['opts']['type'] === 'php' ? ':' : '';
                $val  = $da['opts']['value'];
                $out .= " {$p}edit-opts=\"{$val}\"";
                return $out;
            }
            $out = ' edit edit-type="dbcombo"';
            $out .= static::_emitDbAttrs($da);
            return $out;
        }

        // <select :opts="$expr" />
        if (preg_match('/<select([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="select"';
            if (isset($da['opts'])) {
                $p    = $da['opts']['type'] === 'php' ? ':' : '';
                $val  = $da['opts']['value'];
                $out .= " {$p}edit-opts=\"{$val}\"";
            }
            return $out;
        }

        // <datetime />
        if (preg_match('/<datetime\b[\s\S]*?\/>/s', $body)) {
            return ' edit edit-type="datetime"';
        }

        // <date />
        if (preg_match('/<date\b[\s\S]*?\/>/s', $body)) {
            return ' edit edit-type="date"';
        }

        // <numeric decimals="2" prefix="R$" suffix="kg" min="0" max="100" />
        if (preg_match('/<numeric([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="numeric"';
            if (isset($da['decimals'])) $out .= ' edit-decimals="' . (int)$da['decimals']['value'] . '"';
            if (isset($da['prefix']))   $out .= ' edit-prefix="' . htmlspecialchars($da['prefix']['value'], ENT_QUOTES) . '"';
            if (isset($da['suffix']))   $out .= ' edit-suffix="' . htmlspecialchars($da['suffix']['value'], ENT_QUOTES) . '"';
            if (isset($da['min'])) {
                $p   = $da['min']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-min=\"{$da['min']['value']}\"";
            }
            if (isset($da['max'])) {
                $p   = $da['max']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-max=\"{$da['max']['value']}\"";
            }
            return $out;
        }

        // <money decimals="2" prefix="R$" />
        if (preg_match('/<money([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="money"';
            if (isset($da['decimals'])) $out .= ' edit-decimals="' . (int)$da['decimals']['value'] . '"';
            if (isset($da['prefix']))   $out .= ' edit-prefix="' . htmlspecialchars($da['prefix']['value'], ENT_QUOTES) . '"';
            return $out;
        }

        // <number decimals="2" min="0" max="100" step="1" />
        if (preg_match('/<number([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="number"';
            if (isset($da['decimals'])) $out .= ' edit-decimals="' . (int)$da['decimals']['value'] . '"';
            if (isset($da['min'])) {
                $p   = $da['min']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-min=\"{$da['min']['value']}\"";
            }
            if (isset($da['max'])) {
                $p   = $da['max']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-max=\"{$da['max']['value']}\"";
            }
            if (isset($da['step'])) {
                $p   = $da['step']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-step=\"{$da['step']['value']}\"";
            }
            return $out;
        }

        // <spinner min="0" max="100" step="1" />
        if (preg_match('/<spinner([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="spinner"';
            if (isset($da['min'])) {
                $p   = $da['min']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-min=\"{$da['min']['value']}\"";
            }
            if (isset($da['max'])) {
                $p   = $da['max']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-max=\"{$da['max']['value']}\"";
            }
            if (isset($da['step'])) {
                $p   = $da['step']['type'] === 'php' ? ':' : '';
                $out .= " {$p}edit-step=\"{$da['step']['value']}\"";
            }
            return $out;
        }

        // <color :colors="['#f00','#0f0']" />
        if (preg_match('/<color([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="color"';
            if (isset($da['colors'])) {
                $p    = $da['colors']['type'] === 'php' ? ':' : '';
                $val  = $da['colors']['value'];
                $out .= " {$p}edit-colors=\"{$val}\"";
            }
            return $out;
        }

        // <textarea rows="3" />
        if (preg_match('/<textarea([\s\S]*?)\s*\/>/s', $body, $m)) {
            $da  = static::parseAttrs($m[1]);
            $out = ' edit edit-type="textarea"';
            if (isset($da['rows'])) $out .= ' edit-rows="' . (int)$da['rows']['value'] . '"';
            return $out;
        }

        // <text />
        if (preg_match('/<text\b[\s\S]*?\/>/s', $body)) {
            return ' edit';
        }

        // Fallback: texto simples
        return ' edit';
    }

    /**
     * Helper: emite atributos comuns de db (model, database, key, display, order-by, filters)
     * para os shorthands <dbcombo> e <dbunique-search>.
     */
    protected static function _emitDbAttrs(array $da): string
    {
        $out = '';
        if (isset($da['model']))    $out .= ' edit-model="'    . htmlspecialchars($da['model']['value'],    ENT_QUOTES) . '"';
        if (isset($da['database'])) $out .= ' edit-database="' . htmlspecialchars($da['database']['value'], ENT_QUOTES) . '"';
        if (isset($da['key']))      $out .= ' edit-key="'      . htmlspecialchars($da['key']['value'],      ENT_QUOTES) . '"';
        if (isset($da['display']))  $out .= ' edit-display="'  . htmlspecialchars($da['display']['value'],  ENT_QUOTES) . '"';
        if (isset($da['order-by'])) $out .= ' edit-order-by="' . htmlspecialchars($da['order-by']['value'], ENT_QUOTES) . '"';
        if (isset($da['filters'])) {
            $p    = $da['filters']['type'] === 'php' ? ':' : '';
            $val  = $da['filters']['value'];
            $out .= " {$p}edit-filters=\"{$val}\"";
        }
        return $out;
    }

    // ── Checklist columns compiler ──────────────────────────────────────────

    /**
     * Extrai <mad-col> de dentro de <mad-checklist-field> / <mad-dbchecklist-field>
     * e converte para o atributo :columns="[...]".
     */
    protected static function compileChecklistCols(array $match): string
    {
        $isDb     = !empty($match[1]); // 'db' ou ''
        $attrStr  = $match[2] ?? '';
        $body     = $match[3] ?? '';
        $tagName  = $isDb ? 'mad-dbchecklist-field' : 'mad-checklist-field';

        // Extrai <mad-col .../>
        $cols = [];
        $body = preg_replace_callback(
            '/<mad-col\b(' . self::ATTR_RUN_SC . ')\/>/s',
            function ($m) use (&$cols) {
                $a = static::parseAttrs($m[1]);
                $col = [];
                if (isset($a['field'])) $col['key']   = static::str($a, 'field');
                // {type,value} cru: suporta label="Texto" (estático) E :label="__('chave')"
                // (bind PHP). str() só devolvia type==='string', então :label virava ''
                // (header vazio). A serialização abaixo resolve via emit().
                if (isset($a['label'])) $col['_label'] = $a['label'];
                if (isset($a['width'])) $col['width'] = static::str($a, 'width');
                if (static::has($a, 'center')) $col['align'] = 'center';
                if (static::has($a, 'right'))  $col['align'] = 'right';
                // Colunas com field começando com __ são slots (container para conteúdo dinâmico)
                $fieldKey = $col['key'] ?? '';
                if (str_starts_with($fieldKey, '__') || static::has($a, 'slot')) {
                    $col['type'] = 'slot';
                }
                // Transform: callable string ou expressão PHP (:transform)
                if (isset($a['transform'])) {
                    $col['_transform'] = $a['transform']; // guarda raw para serialização especial
                }
                $cols[] = $col;
                return ''; // remove da tag
            },
            $body
        );

        // Se encontrou colunas e o atributo :columns não existe, injeta
        if (!empty($cols) && stripos($attrStr, 'columns') === false) {
            $colsPhp = '[' . implode(', ', array_map(function ($c) {
                $parts = [];
                foreach ($c as $k => $v) {
                    if ($k === '_transform') {
                        // Expressão PHP raw ou callable string
                        if ($v['type'] === 'php') {
                            $parts[] = "'transform' => " . $v['value'];
                        } else {
                            $parts[] = "'transform' => '" . addslashes($v['value']) . "'";
                        }
                        continue;
                    }
                    if ($k === '_label') {
                        // label="Texto" (literal quotado) OU :label="__('chave')" (expr
                        // PHP crua) — emit() resolve os dois casos.
                        $parts[] = "'label' => " . static::emit($v);
                        continue;
                    }
                    $parts[] = "'{$k}' => '{$v}'";
                }
                return '[' . implode(', ', $parts) . ']';
            }, $cols)) . ']';
            $attrStr .= ' :columns="' . $colsPhp . '"';
        }

        // rtrim: na forma self-closing a corrida de atributos engole o espaço
        // antes do '/>', e o re-emit somava outro (`... "  />"`).
        $attrStr = rtrim($attrStr);

        $body = trim($body);
        if ($body === '') {
            return "<{$tagName}{$attrStr} />";
        }
        return "<{$tagName}{$attrStr}>{$body}</{$tagName}>";
    }

    // ── Wizard compiler ──────────────────────────────────────────────────────

    /**
     * Compila
     *   <mad-wizard :current="$this->wizardStep" variant="numbers" [clickable mad:click="onWizardGoto"]>
     *       <mad-wizard-step key="dados" title="Dados gerais" icon="clipboard-list">BODY</mad-wizard-step>
     *       ...
     *   </mad-wizard>
     * em: indicador <x-steps> (reuso do componente existente) + painéis
     * `.mad-wizard-step`. Server-driven: o painel INATIVO ganha `.mad-hidden`
     * mas PERMANECE no DOM — o mad_model do MadWire coleta os campos de todos
     * os steps em toda action, então avançar/voltar nunca perde valor digitado
     * (um x-show/if removeria os inputs e zeraria o estado no round-trip).
     * Os corpos dos steps ficam inline no output pras passes seguintes do
     * compiler resolverem os campos normalmente.
     */
    protected static function compileWizardBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $parentAttrs = static::parseAttrs($attrStr);

        // current: expressão PHP (`:current="$this->wizardStep"`) ou literal.
        $currentExpr = '1';
        if (isset($parentAttrs['current'])) {
            $currentExpr = $parentAttrs['current']['type'] === 'php'
                ? $parentAttrs['current']['value']
                : var_export(static::str($parentAttrs, 'current'), true);
        }

        // Extrai steps preservando o corpo. Attr-block quote-aware — valores
        // de atributo podem conter '>' (chains {a->b}); [^>]* truncaria.
        $steps = [];
        preg_replace_callback(
            '/<mad-wizard-step\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>([\s\S]*?)<\/mad-wizard-step>/s',
            function ($m) use (&$steps) {
                $a = static::parseAttrs($m[1]);
                $steps[] = [
                    'key'         => static::str($a, 'key'),
                    'label'       => static::textPhp($a, 'title') ?? static::textPhp($a, 'label') ?? "''",
                    'description' => static::textPhp($a, 'description'),
                    'icon'        => static::str($a, 'icon'),
                    'body'        => $m[2],
                ];
                return '';
            },
            $body
        );
        if (empty($steps)) {
            return '';
        }

        // Indicador — mesmo shape do compileStepsBlock (<x-steps>).
        $variant = isset($parentAttrs['variant'])
            ? static::emit($parentAttrs['variant'])
            : "'numbers'";
        $metaPhp = '[' . implode(', ', array_map(function ($s, $i) {
            $pairs = [
                "'key' => '" . addslashes($s['key'] !== '' ? $s['key'] : (string) ($i + 1)) . "'",
                "'label' => " . $s['label'],
            ];
            if ($s['icon'] !== '')          $pairs[] = "'icon' => '" . addslashes($s['icon']) . "'";
            if ($s['description'] !== null) $pairs[] = "'description' => " . $s['description'];
            return '[' . implode(', ', $pairs) . ']';
        }, $steps, array_keys($steps))) . ']';

        $indicatorParts = [
            ':variant="' . $variant . '"',
            ':current="' . $currentExpr . '"',
            ':steps="' . $metaPhp . '"',
        ];
        if (static::has($parentAttrs, 'clickable')) {
            $indicatorParts[] = ':clickable="true"';
        }
        // mad:click — parseAttrs nao aceita ':' no nome; extrai da string crua.
        if (preg_match('/\bmad:click\s*=\s*(["\'])((?:(?!\1)[\s\S])*?)\1/s', $attrStr, $_mc)) {
            $indicatorParts[] = 'mad-click="' . addslashes($_mc[2]) . '"';
        }

        // Painéis (1-based, casa com o current do indicador).
        $panels = '';
        foreach ($steps as $i => $s) {
            $n = $i + 1;
            $key = $s['key'] !== '' ? $s['key'] : (string) $n;
            $panels .= "\n" . '<div class="mad-wizard-step @if((int)(' . $currentExpr . ') !== ' . $n . ') mad-hidden @endif" data-mad-wizard-step="' . htmlspecialchars($key, ENT_QUOTES) . '">'
                . $s['body']
                . '</div>';
        }

        return '<div class="mad-wizard">'
            . "\n" . '<x-steps ' . implode(' ', $indicatorParts) . ' />'
            . "\n" . '<div class="mad-wizard-panels">' . $panels . "\n" . '</div>'
            . "\n" . '</div>';
    }

    // ── Steps compiler ───────────────────────────────────────────────────────

    /**
     * Compila <mad-steps ...><mad-step .../> ...</mad-steps> → <x-steps ... />
     */
    protected static function compileStepsBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $parentAttrs = static::parseAttrs($attrStr);

        // Extrai <mad-step ... /> filhos
        $steps = [];
        preg_replace_callback(
            '/<mad-step\b(' . self::ATTR_RUN_SC . ')\/>/s',
            function ($m) use (&$steps) {
                $a = static::parseAttrs($m[1]);
                // Valores já como CÓDIGO PHP: label/description aceitam
                // `:attr="__('g.k')"` (textPhp); o resto segue literal.
                $lit  = fn (string $k) => "'" . addslashes(static::str($a, $k)) . "'";
                $step = [];
                if (isset($a['key']))         $step['key']         = $lit('key');
                if (isset($a['label']))       $step['label']       = static::textPhp($a, 'label') ?? "''";
                if (isset($a['description'])) $step['description'] = static::textPhp($a, 'description') ?? "''";
                if (isset($a['icon']))        $step['icon']        = $lit('icon');
                if (isset($a['status']))      $step['status']      = $lit('status');
                $steps[] = $step;
                return '';
            },
            $body
        );

        // Sem <mad-step> filho, quem manda é o autor: `<mad-steps :steps="[...]" />`
        // é a forma DECLARATIVA do componente e a lista de props abaixo é uma
        // allowlist — ela não repassa `:steps`, então compilar aqui apagaria a
        // trilha inteira (o indicador saía vazio, sem erro). Devolve a tag como
        // está e deixa o alias pass entregar as props ao <x-steps>.
        if (empty($steps)) {
            return $match[0];
        }

        // Monta props para <x-steps>
        $parts = [];

        // Props simples (string)
        foreach (['variant', 'size', 'class'] as $prop) {
            if (isset($parentAttrs[$prop])) {
                $parts[] = ':' . $prop . '="' . static::emit($parentAttrs[$prop]) . '"';
            }
        }

        // current — pode ser string ou php expression
        if (isset($parentAttrs['current'])) {
            $parts[] = ':current="' . static::emit($parentAttrs['current']) . '"';
        }

        // Bool props
        if (static::has($parentAttrs, 'clickable')) {
            $parts[] = ':clickable="true"';
        }

        // mad:click — parseAttrs nao aceita ":" no nome, entao extraimos
        // direto da string de atributos. Emite como "mad-click" (kebab),
        // que o parseParams do BladeOne converte em $madClick (camelCase).
        if (preg_match('/\bmad:click\s*=\s*(["\'])((?:(?!\1)[\s\S])*?)\1/s', $attrStr, $_mcMatch)) {
            $parts[] = 'mad-click="' . addslashes($_mcMatch[2]) . '"';
        }

        // Steps array
        if (!empty($steps)) {
            $stepsPhp = '[' . implode(', ', array_map(function ($s) {
                $pairs = [];
                foreach ($s as $k => $v) {
                    $pairs[] = "'" . $k . "' => " . $v;
                }
                return '[' . implode(', ', $pairs) . ']';
            }, $steps)) . ']';
            $parts[] = ':steps="' . $stepsPhp . '"';
        }

        $propsStr = !empty($parts) ? ' ' . implode(' ', $parts) : '';
        return '<x-steps' . $propsStr . ' />';
    }

    // ── Timeline compiler ────────────────────────────────────────────────────

    /**
     * Compila <mad-timeline ...><mad-timeline-item ...>body</mad-timeline-item> ...</mad-timeline>
     * → <x-timeline ... />
     */
    protected static function compileTimelineBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $parentAttrs = static::parseAttrs($attrStr);

        // Extrai <mad-timeline-item ...>body</mad-timeline-item> filhos
        $items = [];
        preg_replace_callback(
            '/<mad-timeline-item\b(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-timeline-item') . '/s',
            function ($m) use (&$items) {
                $a = static::parseAttrs($m[1]);
                // Valores já como CÓDIGO PHP: title aceita `:title="__('g.k')"`
                // (textPhp); o resto segue literal.
                $lit  = fn (string $v) => "'" . addslashes($v) . "'";
                $item = [];
                if (isset($a['date']))  $item['date']  = $lit(static::str($a, 'date'));
                if (isset($a['title'])) $item['title'] = static::textPhp($a, 'title') ?? "''";
                if (isset($a['icon']))  $item['icon']  = $lit(static::str($a, 'icon'));
                if (isset($a['color'])) $item['color'] = $lit(static::str($a, 'color'));
                $itemBody = trim($m[2] ?? '');
                if ($itemBody !== '') {
                    $item['body'] = $lit($itemBody);
                }
                $items[] = $item;
                return '';
            },
            $body
        );

        // Monta props para <x-timeline>
        $parts = [];

        // String props (kebab → snake_case for Blade)
        $stringProps = [
            'model'       => 'model',
            'database'    => 'database',
            'title-field' => 'title_field',
            'body-field'  => 'body_field',
            'date-field'  => 'date_field',
            'icon-field'  => 'icon_field',
            'color-field' => 'color_field',
            'order'       => 'order',
            'date-format' => 'date_format',
            'time-format' => 'time_format',
            'limit'       => 'limit',
            'per-page'    => 'per_page',
            'class'       => 'class',
        ];

        foreach ($stringProps as $htmlAttr => $bladeAttr) {
            if (isset($parentAttrs[$htmlAttr])) {
                $parts[] = ':' . $bladeAttr . '="' . static::emit($parentAttrs[$htmlAttr]) . '"';
            }
        }

        // Bool props
        foreach (['both-sides' => 'both_sides', 'group-by-date' => 'group_by_date', 'load-more' => 'load_more', 'cards' => 'cards'] as $htmlAttr => $bladeAttr) {
            if (static::has($parentAttrs, $htmlAttr)) {
                $parts[] = ':' . $bladeAttr . '="true"';
            }
        }

        // PHP expression props — pass through. `items` entra aqui porque a
        // timeline também tem forma declarativa (`:items="$linhas"`, sem
        // <mad-timeline-item> filho): a allowlist de cima não a repassava e a
        // lista saía vazia, sem erro nenhum.
        foreach (['filters', 'items'] as $prop) {
            if (isset($parentAttrs[$prop]) && $parentAttrs[$prop]['type'] === 'php') {
                $parts[] = ':' . $prop . '="' . $parentAttrs[$prop]['value'] . '"';
            }
        }

        // Manual items array
        if (!empty($items)) {
            $itemsPhp = '[' . implode(', ', array_map(function ($item) {
                $pairs = [];
                foreach ($item as $k => $v) {
                    $pairs[] = "'" . $k . "' => " . $v;
                }
                return '[' . implode(', ', $pairs) . ']';
            }, $items)) . ']';
            $parts[] = ':items="' . $itemsPhp . '"';
        }

        $propsStr = !empty($parts) ? ' ' . implode(' ', $parts) : '';
        return '<x-timeline' . $propsStr . ' />';
    }

    // ── Pivot Table compiler ─────────────────────────────────────────────────

    /**
     * Monta array de attrs para 1 pivot field a partir dos attrs HTML parseados.
     * Compartilhado entre tags legacy (row/col/value/filter) e novo (field area="...").
     */
    protected static function buildPivotFieldAttrs(array $a, string $area, int $order): array
    {
        $f = [];
        $f['field'] = static::str($a, 'field');
        $f['label'] = static::str($a, 'label', $f['field']);
        $f['area']  = $area;
        $f['type']  = static::str($a, 'type', $area === 'values' ? 'number' : 'string');
        $f['order'] = $order;

        // Aggregation — aceita 'aggregation' (canonical) e 'aggregate' (alias do editor).
        if ($area === 'values') {
            $agg = static::str($a, 'aggregation') ?: static::str($a, 'aggregate', 'sum');
            $f['aggregation'] = $agg;
            $f['format'] = static::str($a, 'format', 'number');
            if (isset($a['currency'])) $f['currency'] = static::str($a, 'currency');
            if (isset($a['locale']))   $f['locale']   = static::str($a, 'locale');
        }

        // Date/DateTime-specific — editor usa kebab; Blade espera camelCase dateFormat.
        if (isset($a['date-format']))     $f['dateFormat'] = static::str($a, 'date-format');
        if (isset($a['datetime-format'])) $f['dateFormat'] = static::str($a, 'datetime-format');
        if (isset($a['date-locale']) && !isset($f['locale']))     $f['locale'] = static::str($a, 'date-locale');
        if (isset($a['datetime-locale']) && !isset($f['locale'])) $f['locale'] = static::str($a, 'datetime-locale');
        if (isset($a['locale']) && !isset($f['locale']))          $f['locale'] = static::str($a, 'locale');

        // Per-field extras (consumidos pelo Blade ou repassados ao frontend pivot).
        if (isset($a['mask']))        $f['mask']        = static::str($a, 'mask');
        if (isset($a['transformer'])) $f['transformer'] = static::str($a, 'transformer');
        if (isset($a['total']))       $f['total']       = static::str($a, 'total');
        if (isset($a['sort']))        $f['sort']        = static::str($a, 'sort');

        return $f;
    }

    /**
     * Compila <mad-pivot-table ...> com 3 schemas suportados:
     *   - Novo (editor): <mad-pivot-field/> no catálogo + <mad-pivot-view> com row/col/value
     *     referenciando fields por nome (area vem da view default).
     *   - Legacy: <mad-pivot-row|col|value|filter/> direto, area implícita do tag.
     *   - Misto: ambos suportados na mesma tabela.
     * → <x-pivot-table :pivot_fields="[...]" ... />
     */
    protected static function compilePivotTableBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $parentAttrs = static::parseAttrs($attrStr);

        // ── Extrai child tags ────────────────────────────────────────────────
        $fields = [];
        $areaCounters = ['rows' => 0, 'columns' => 0, 'values' => 0, 'filters' => 0];

        $tagAreaMap = [
            'row'    => 'rows',
            'col'    => 'columns',
            'value'  => 'values',
            'filter' => 'filters',
        ];

        // ── Step 1: extrai blocos <mad-pivot-view> e monta mapa de areas ────
        // O editor (PivotConfigModal) gera estrutura: <mad-pivot-field/> no
        // catálogo + <mad-pivot-view> com <mad-pivot-row|col|value/> que
        // REFERENCIAM os fields por nome (não os definem).
        $viewAreaMap = [];   // field-name => 'rows'|'columns'|'values'|'filters'  (DEFAULT view)
        $viewSortMap = [];   // field-name => 'asc'|'desc'                          (DEFAULT view)
        $viewBlocks  = [];   // todos os blocos de view encontrados (attrs + body)
        $viewMaps    = [];   // [viewIdx => ['name','desc','isDefault','areaMap','sortMap','orderMap']]

        $bodyNoViews = preg_replace_callback(
            '/<mad-pivot-view\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)>([\s\S]*?)<\/mad-pivot-view>/s',
            function ($m) use (&$viewBlocks) {
                $viewBlocks[] = ['attrs' => static::parseAttrs($m[1]), 'body' => $m[2]];
                return '';
            },
            $body
        );

        if (!empty($viewBlocks)) {
            // Constroi mapa por view (todas) — usado depois para emitir presets.
            $defaultIdx = 0;
            foreach ($viewBlocks as $i => $vb) {
                if (static::has($vb['attrs'], 'default')) { $defaultIdx = $i; break; }
            }
            foreach ($viewBlocks as $i => $vb) {
                $areaCnt = ['rows' => 0, 'columns' => 0, 'values' => 0, 'filters' => 0];
                $am = []; $sm = []; $om = [];
                preg_replace_callback(
                    '/<mad-pivot-(row|col|value|filter)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)\/>/s',
                    function ($mm) use (&$am, &$sm, &$om, &$areaCnt, $tagAreaMap) {
                        $a = static::parseAttrs($mm[2]);
                        $fieldName = static::str($a, 'field');
                        if ($fieldName === '') return '';
                        $area = $tagAreaMap[$mm[1]];
                        $am[$fieldName] = $area;
                        $om[$fieldName] = $areaCnt[$area]++;
                        $sortVal = static::str($a, 'sort');
                        if ($sortVal !== '') $sm[$fieldName] = $sortVal;
                        return '';
                    },
                    $vb['body']
                );
                $viewMaps[$i] = [
                    'name'        => static::str($vb['attrs'], 'name', 'View ' . ($i + 1)),
                    'description' => static::str($vb['attrs'], 'description'),
                    'isDefault'   => $i === $defaultIdx,
                    'areaMap'     => $am,
                    'sortMap'     => $sm,
                    'orderMap'    => $om,
                ];
            }
            // Default view alimenta o area-lookup do Step 2 (comportamento atual).
            $viewAreaMap = $viewMaps[$defaultIdx]['areaMap'];
            $viewSortMap = $viewMaps[$defaultIdx]['sortMap'];
        }

        // ── Step 2: novo schema <mad-pivot-field> — definição de campo ──────
        // Lookup do viewAreaMap aceita match exato OU por sufixo apos ultimo '.'.
        // Ex: field "pedido_venda_item.valor_total" matcha view ref "valor_total".
        $lookupViewArea = function (string $fieldName) use ($viewAreaMap): ?string {
            if (isset($viewAreaMap[$fieldName])) return $viewAreaMap[$fieldName];
            $short = strrchr($fieldName, '.');
            if ($short !== false) {
                $short = ltrim($short, '.');
                if (isset($viewAreaMap[$short])) return $viewAreaMap[$short];
            }
            return null;
        };
        $lookupViewSort = function (string $fieldName) use ($viewSortMap): ?string {
            if (isset($viewSortMap[$fieldName])) return $viewSortMap[$fieldName];
            $short = strrchr($fieldName, '.');
            if ($short !== false) {
                $short = ltrim($short, '.');
                if (isset($viewSortMap[$short])) return $viewSortMap[$short];
            }
            return null;
        };

        preg_replace_callback(
            '/<mad-pivot-field\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)\/>/s',
            function ($m) use (&$fields, &$areaCounters, $lookupViewArea, $lookupViewSort) {
                $a = static::parseAttrs($m[1]);
                $fieldName = static::str($a, 'field');
                // Precedência de area: mapa da view default > attr `area=` > 'rows'.
                $area = $lookupViewArea($fieldName) ?? static::str($a, 'area', 'rows');
                if (!isset($areaCounters[$area])) $area = 'rows';
                $f = static::buildPivotFieldAttrs($a, $area, $areaCounters[$area]++);
                $sortMatched = $lookupViewSort($fieldName);
                if ($sortMatched !== null && !isset($f['sort'])) {
                    $f['sort'] = $sortMatched;
                }
                $fields[] = $f;
                return '';
            },
            $bodyNoViews
        );

        // ── Step 3 (legacy): <mad-pivot-row|col|value|filter> direto ────────
        // Mantido para retrocompat com Blades escritos à mão.
        preg_replace_callback(
            '/<mad-pivot-(row|col|value|filter)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)\/>/s',
            function ($m) use (&$fields, &$areaCounters, $tagAreaMap) {
                $tagType = $m[1];
                $area    = $tagAreaMap[$tagType];
                $a       = static::parseAttrs($m[2]);
                $fields[] = static::buildPivotFieldAttrs($a, $area, $areaCounters[$area]++);
                return '';
            },
            $bodyNoViews
        );

        // ── Step 4: Monta presets (views) a partir do catalogo de fields ────
        // Cada preset = view declarada. preset.fields contem apenas os fields
        // referenciados pela view, com area/sort/order do mapa da view.
        $presetsArr = [];
        if (!empty($viewMaps) && !empty($fields)) {
            $matchField = function (string $needle, array $haystack): ?string {
                if (isset($haystack[$needle])) return $needle;
                $short = strrchr($needle, '.');
                if ($short !== false) {
                    $short = ltrim($short, '.');
                    if (isset($haystack[$short])) return $short;
                }
                return null;
            };
            foreach ($viewMaps as $vm) {
                $presetFields = [];
                foreach ($fields as $f) {
                    $fieldName = $f['field'] ?? '';
                    if ($fieldName === '') continue;
                    $matchedKey = $matchField($fieldName, $vm['areaMap']);
                    if ($matchedKey === null) continue;
                    $pf = $f;
                    $pf['area']  = $vm['areaMap'][$matchedKey];
                    $pf['order'] = $vm['orderMap'][$matchedKey] ?? ($pf['order'] ?? 0);
                    if (isset($vm['sortMap'][$matchedKey]) && $vm['sortMap'][$matchedKey] !== '') {
                        $pf['sort'] = $vm['sortMap'][$matchedKey];
                    }
                    $presetFields[] = $pf;
                }
                $presetsArr[] = [
                    'id'          => 'view_' . count($presetsArr),
                    'name'        => $vm['name'],
                    'description' => $vm['description'],
                    'isDefault'   => $vm['isDefault'],
                    'fields'      => $presetFields,
                ];
            }
        }

        // ── Monta props para <x-pivot-table> ─────────────────────────────────
        $parts = [];

        // String props
        $stringProps = [
            'model'              => 'model',
            'database'           => 'database',
            'title'              => 'title',
            'subtitle'           => 'subtitle',
            'width'              => 'width',
            'language'           => 'language',
            'currency'           => 'currency',
            'locale'             => 'locale',
            'theme'              => 'theme',
            'class'              => 'class',
            'no-data-label'      => 'no_data_label',
            'field-list-layout'  => 'field_list_layout',
            'subtotals-position' => 'subtotals_position',
        ];

        foreach ($stringProps as $htmlAttr => $bladeAttr) {
            if (isset($parentAttrs[$htmlAttr])) {
                $parts[] = ':' . $bladeAttr . '="' . static::emit($parentAttrs[$htmlAttr]) . '"';
            }
        }

        // Numeric props
        foreach (['height' => 'height', 'rows-per-page' => 'rows_per_page'] as $htmlAttr => $bladeAttr) {
            if (isset($parentAttrs[$htmlAttr])) {
                $parts[] = ':' . $bladeAttr . '="' . static::emit($parentAttrs[$htmlAttr]) . '"';
            }
        }

        // Bool props
        $boolProps = [
            'field-list'        => 'field_list',
            'virtual-scrolling' => 'virtual_scrolling',
            'subtotals'         => 'subtotals',
            'grand-totals'      => 'grand_totals',
            'row-totals'        => 'row_totals',
            'column-totals'     => 'column_totals',
            'no-panel'          => 'no_panel',
            'compact'           => 'compact',
        ];

        foreach ($boolProps as $htmlAttr => $bladeAttr) {
            if (static::has($parentAttrs, $htmlAttr)) {
                $parts[] = ':' . $bladeAttr . '="true"';
            }
        }

        // PHP expression props
        foreach (['filters', 'joins', 'data', 'presets', 'transformers'] as $prop) {
            if (isset($parentAttrs[$prop]) && $parentAttrs[$prop]['type'] === 'php') {
                $parts[] = ':' . $prop . '="' . static::emit($parentAttrs[$prop]) . '"';
            }
        }

        // Helper: emite array PHP literal recursivo (suporta bool/int/string/array).
        $emitPhpValue = null;
        $emitPhpValue = function ($v) use (&$emitPhpValue): string {
            if (is_bool($v))   return $v ? 'true' : 'false';
            if (is_int($v))    return (string)$v;
            if (is_float($v))  return (string)$v;
            if ($v === null)   return 'null';
            if (is_array($v)) {
                $isList = array_keys($v) === range(0, count($v) - 1);
                $parts = [];
                foreach ($v as $k => $vv) {
                    if ($isList) {
                        $parts[] = $emitPhpValue($vv);
                    } else {
                        $parts[] = "'" . addslashes((string)$k) . "' => " . $emitPhpValue($vv);
                    }
                }
                return '[' . implode(', ', $parts) . ']';
            }
            return "'" . addslashes((string)$v) . "'";
        };

        // Pivot fields array
        if (!empty($fields)) {
            $parts[] = ':pivot_fields="' . $emitPhpValue($fields) . '"';
        }

        // Presets array (auto-built from views) — só emite se user nao passou :presets
        if (!empty($presetsArr) && !(isset($parentAttrs['presets']) && $parentAttrs['presets']['type'] === 'php')) {
            $parts[] = ':presets="' . $emitPhpValue($presetsArr) . '"';
        }

        $propsStr = !empty($parts) ? ' ' . implode(' ', $parts) : '';
        return '<x-pivot-table' . $propsStr . ' />';
    }

    // ── Tree View compiler ───────────────────────────────────────────────────

    /**
     * Compila <mad-tree-view ...>
     *     <mad-tree-actions position="top">...</mad-tree-actions>
     *     <mad-tree-context-menu>...</mad-tree-context-menu>
     * </mad-tree-view>
     * → <x-tree-view :prop="val" ... />
     */
    protected static function compileTreeViewBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        // Strip outer '>' and '</mad-tree-view>' or '/>'
        $inner = preg_replace('/^\s*>|<\/mad-tree-view>\s*$/s', '', $body);
        $inner = trim($inner);

        $parentAttrs = static::parseAttrs($attrStr);

        // ── Extract <mad-tree-actions position="top|bottom">...</mad-tree-actions>
        $actionsTop    = '';
        $actionsBottom = '';
        $inner = preg_replace_callback(
            '/<mad-tree-actions\b(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-tree-actions') . '/s',
            function ($m) use (&$actionsTop, &$actionsBottom) {
                $a = static::parseAttrs($m[1]);
                $pos = static::str($a, 'position', 'top');
                $html = trim($m[2] ?? '');
                if ($pos === 'bottom') {
                    $actionsBottom = $html;
                } else {
                    $actionsTop = $html;
                }
                return '';
            },
            $inner
        );

        // ── Extract <mad-tree-context-menu>...</mad-tree-context-menu>
        $contextMenu = '';
        $inner = preg_replace_callback(
            '/<mad-tree-context-menu\b' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-tree-context-menu') . '/s',
            function ($m) use (&$contextMenu) {
                $contextMenu = trim($m[1] ?? '');
                return '';
            },
            $inner
        );

        // ── Build props
        $parts = [];

        // String props (kebab → snake_case)
        $stringProps = [
            'name'           => 'name',
            'model'          => 'model',
            'database'       => 'database',
            'display'        => 'display',
            'key'            => 'key_field',
            'parent-field'   => 'parent_field',
            'icon'           => 'icon',
            'active-icon'    => 'active_icon',
            'expanded-icon'  => 'expanded_icon',
            'icon-field'     => 'icon_field',
            'order-by'       => 'order_by',
            'count-field'    => 'count_field',
            'expanded'       => 'expanded',
            'class'          => 'class',
        ];

        foreach ($stringProps as $htmlAttr => $bladeAttr) {
            if (isset($parentAttrs[$htmlAttr])) {
                $parts[] = ':' . $bladeAttr . '="' . static::emit($parentAttrs[$htmlAttr]) . '"';
            }
        }

        // PHP expression props (filters, items, active)
        foreach (['filters', 'items', 'active'] as $prop) {
            if (isset($parentAttrs[$prop])) {
                $parts[] = ':' . $prop . '="' . static::emit($parentAttrs[$prop]) . '"';
            }
        }

        // mad:click → click prop
        // parseAttrs splits "mad:click" into boolean "mad" + php ":click"
        // so we check both forms
        $clickAttr = $parentAttrs['mad:click'] ?? $parentAttrs['click'] ?? null;
        if ($clickAttr) {
            // Ensure it emits as a quoted string, not a PHP expression
            if ($clickAttr['type'] === 'php') {
                $parts[] = ':click="\'' . str_replace("'", "\\'", $clickAttr['value']) . '\'"';
            } else {
                $parts[] = ':click="' . static::emit($clickAttr) . '"';
            }
        }

        // Bool props
        foreach (['persist'] as $htmlAttr) {
            if (static::has($parentAttrs, $htmlAttr)) {
                $parts[] = ':' . $htmlAttr . '="true"';
            }
        }

        $propsStr = !empty($parts) ? ' ' . implode(' ', $parts) : '';

        // Actions top/bottom: output via ob_start so BladeOne compiles any components inside.
        // Concatenate PHP open/close tags to avoid parser confusion.
        $phpOpen  = '<' . '?php';
        $phpClose = '?' . '>';
        $prefix = '';
        if ($actionsTop !== '') {
            $prefix .= $phpOpen . ' ob_start(); ' . $phpClose . $actionsTop . $phpOpen . ' $_mad_tree_actions_top = ob_get_clean(); ' . $phpClose;
            $propsStr .= ' :actions_top="$_mad_tree_actions_top"';
        }
        if ($actionsBottom !== '') {
            $prefix .= $phpOpen . ' ob_start(); ' . $phpClose . $actionsBottom . $phpOpen . ' $_mad_tree_actions_bottom = ob_get_clean(); ' . $phpClose;
            $propsStr .= ' :actions_bottom="$_mad_tree_actions_bottom"';
        }

        // Context menu: compile <mad-menu-item> / <mad-menu-separator> into final HTML
        // at compile time. This avoids BladeOne trying to evaluate {id} placeholders
        // as PHP code. The tree-view component replaces {id}, {name} etc. per-node.
        if ($contextMenu !== '') {
            $compiledMenu = static::compileTreeContextMenu($contextMenu);
            // The compiled menu is a PHP expression that may include __() calls.
            // We inject it as a variable before the component tag.
            $prefix .= $phpOpen . ' $_mad_tree_ctx_menu = ' . $compiledMenu . '; ' . $phpClose;
            $propsStr .= ' :context_menu="$_mad_tree_ctx_menu"';
        }

        return $prefix . '<x-tree-view' . $propsStr . ' />';
    }

    /**
     * Compiles <mad-menu-item> and <mad-menu-separator> tags inside a tree context menu
     * into their final HTML, keeping {field} placeholders as literal strings.
     *
     * Returns a valid PHP expression (string concatenation with __() calls for i18n).
     * This avoids BladeOne trying to evaluate {id} placeholders as PHP.
     */
    protected static function compileTreeContextMenu(string $html): string
    {
        // Replace <mad-menu-separator /> or <mad-menu-separator></mad-menu-separator>
        $html = preg_replace(
            '/<mad-menu-separator\s*\/?>(\s*<\/mad-menu-separator>)?/s',
            '<div class="mad-context-sep" role="separator"></div>',
            $html
        );

        // Replace <mad-menu-label>text</mad-menu-label>
        $html = preg_replace(
            '/<mad-menu-label\b' . self::ATTR_RUN_SC . static::pairOrSelfClose('mad-menu-label') . '/s',
            '<div class="mad-context-label">$1</div>',
            $html
        );

        // Replace <mad-menu-item ...>label</mad-menu-item>
        $html = preg_replace_callback(
            '/<mad-menu-item\b(' . self::ATTR_RUN_SC . ')' . static::pairOrSelfClose('mad-menu-item') . '/s',
            function ($m) {
                $attrs = static::parseAttrs($m[1]);
                $label = trim($m[2] ?? '');
                $icon     = static::str($attrs, 'icon');
                $variant  = static::str($attrs, 'variant');
                $disabled = static::has($attrs, 'disabled');

                $variantClass = $variant ? ' mad-context-item-' . $variant : '';
                $disabledAttr = $disabled ? ' disabled' : '';

                // Build the onclick/data-mad-click attribute
                $actionAttr = '';

                // navigate prop → onclick
                $navigate = static::str($attrs, 'navigate');
                if (!$navigate) $navigate = static::str($attrs, 'target');
                if ($navigate) {
                    $navClass  = $navigate;
                    $navMethod = 'show';
                    $jsParams  = '{}';
                    $hasMethodArgs = false;

                    // Parse Class::method(params) format
                    // `(.*)`: `Classe::onShow()` não casava em nenhum dos dois
                    // ramos e a string inteira virava o nome da classe.
                    if (preg_match('/^(\w+)::(\w+)\s*\((.*)\)$/', $navigate, $nm)) {
                        $navClass  = $nm[1];
                        $navMethod = $nm[2];
                        $hasMethodArgs = trim($nm[3]) !== '';
                        if ($hasMethodArgs) {
                            $jsParams = static::buildTreeNavParams($nm[3]);
                        }
                    } elseif (preg_match('/^(\w+)::(\w+)$/', $navigate, $nm)) {
                        $navClass  = $nm[1];
                        $navMethod = $nm[2];
                    }

                    // Check if has params attr
                    $paramsStr = static::str($attrs, 'params');
                    if ($paramsStr) {
                        $jsParams = $paramsStr;
                    }

                    // Modo web: assa a URL amigavel (/app/slug) no servidor,
                    // preservando os placeholders {field} (o tree-view os substitui
                    // por no, server-side). Sem isso o client nao teria a rota
                    // amigavel e cairia em 404 (mapa de rotas removido do client).
                    $navFriendly = static::friendlyTreeNavUrl($navClass, $navMethod, $jsParams);

                    // Auto-detect de wrapper (mesma regra do MadAction::auto()):
                    // alvo DRAWER/MODAL abre como OVERLAY (Mad.get) — navegar de
                    // pagina (Mad.go) pra um drawer troca a URL e rende o drawer
                    // sobre um shell vazio (F5/fechar => tela morta). Ex.: "Nova
                    // pasta"/"Novo documento" do menu de contexto da arvore do GED
                    // divergiam do botao da sidebar (builder #72).
                    $isOverlay = false;
                    if (class_exists($navClass) && is_subclass_of($navClass, \Mad\Component\MadComponent::class)) {
                        $w = $navClass::getWrapper();
                        $isOverlay = ($w === \Mad\Component\MadComponent::DRAWER || $w === \Mad\Component\MadComponent::MODAL);
                    }

                    if ($hasMethodArgs || $isOverlay) {
                        // Overlay (drawer/modal) ou Class::method(args) → Mad.get()
                        $urlArg = $navFriendly !== null ? ",null,'" . addslashes($navFriendly) . "'" : '';
                        $actionAttr = "onclick=\"Mad.get('" . $navClass . "@" . $navMethod . "'," . $jsParams . $urlArg . ")\"";
                    } else {
                        // Class or Class::method → Mad.go() for page navigation
                        $urlArg = $navFriendly !== null ? ",'" . addslashes($navFriendly) . "'" : '';
                        $actionAttr = "onclick=\"Mad.go('" . $navClass . "','" . $navMethod . "'," . $jsParams . $urlArg . ")\"";
                    }
                }

                // mad:click → data-mad-click (parseAttrs may split "mad" + "click")
                $madClick = static::str($attrs, 'mad:click');
                if (!$madClick) {
                    // parseAttrs splits "mad:click=..." into boolean "mad" + ":click=..."
                    // — o ':' órfão vira prefixo de bind, então 'click' sai com type
                    // 'php' e str() (que filtra por type 'string') devolvia '' e o
                    // action era DROPADO silenciosamente. Ler o valor cru aqui.
                    if (static::has($attrs, 'mad') && isset($attrs['click']) && is_string($attrs['click']['value'] ?? null)) {
                        $madClick = $attrs['click']['value'];
                    }
                }
                if ($madClick) {
                    $actionAttr = 'data-mad-click="' . htmlspecialchars($madClick, ENT_QUOTES) . '"';
                }

                $iconHtml = $icon
                    ? '<i data-lucide="' . $icon . '" class="mad-context-item-icon"></i>'
                    : '';

                return '<button type="button" class="mad-context-item' . $variantClass . '" role="menuitem"'
                    . $disabledAttr
                    . ($actionAttr ? ' ' . $actionAttr : '')
                    . '>'
                    . $iconHtml
                    . '<span class="mad-context-item-label">' . $label . '</span>'
                    . '</button>';
            },
            $html
        );

        // Convert {!! __('key') !!} Blade i18n expressions → PHP concatenation fragments
        // So the final output is a valid PHP string expression: 'html' . __('key') . 'html'
        $html = trim($html);

        // Split on {!! __('...') !!} patterns and build PHP concatenation
        $parts = preg_split('/(\{!!\s*__\([\'"][^\'"]+[\'"]\)\s*!!\})/', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        $phpParts = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{!!\s*__\([\'"]([^\'"]+)[\'"]\)\s*!!\}$/', $part, $im)) {
                $phpParts[] = "__(" . static::qs($im[1]) . ")";
            } else {
                if ($part !== '') {
                    $phpParts[] = static::qs($part);
                }
            }
        }

        return implode(' . ', $phpParts) ?: "''";
    }

    /**
     * Assa a URL amigavel (/app/slug) de um item de context-menu de tree, no modo
     * web. Os params vem como object literal JS com placeholders {field} que o
     * tree-view substitui por no (server-side, str_replace). Para sobreviver ao
     * urlencode do urlFor, os {field} sao protegidos com sentinelas
     * (MADLB/MADRB) e restaurados depois.
     */
    protected static function friendlyTreeNavUrl(string $navClass, string $navMethod, string $jsParams): ?string
    {
        // Extrai pares key:value do object literal JS (valor pode ser {field}, 'str', num)
        $pairs = [];
        if (preg_match_all('/(\w+)\s*:\s*(\{[^}]*\}|\'[^\']*\'|"[^"]*"|[^,}\s]+)/', $jsParams, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $p) {
                $v = $p[2];
                $c = $v[0] ?? '';
                if ($c === "'" || $c === '"') {
                    $v = substr($v, 1, -1);
                }
                $pairs[$p[1]] = $v;
            }
        }

        $LB = 'MADLB'; $RB = 'MADRB';
        $isMad = class_exists($navClass) && is_subclass_of($navClass, '\Mad\Component\MadComponent');

        $params = [];
        if (!$isMad) {
            $params['static'] = '1';
        }
        foreach ($pairs as $k => $v) {
            $params[$k] = str_replace(['{', '}'], [$LB, $RB], $v);
        }

        $friendly = \Mad\Routing\MadRoutes::urlFor($navClass, $navMethod, $params);
        return str_replace([$LB, $RB], ['{', '}'], $friendly);
    }

    /**
     * Builds a JS params object string from navigate(params) format.
     * E.g. "{id}" → "{id}" (kept as literal for tree placeholder replacement).
     */
    /**
     * Builds a JS params object from navigate(params) format.
     * Examples:
     *   "{id}"           → "{id: {id}}"  (single placeholder → wrap as {id: value})
     *   "{id},{tipo}"    → "{id: {id}, tipo: {tipo}}"
     *   "{parent_id: 5}" → "{parent_id: 5}" (already an object → pass through)
     */
    protected static function buildTreeNavParams(string $raw): string
    {
        $raw = trim($raw);
        // Already looks like a JS object with key:value pairs
        if (preg_match('/^\{[^}]*:/', $raw)) return $raw;
        // Single or comma-separated values like "{id}" or "{id},{tipo}"
        $values = array_map('trim', explode(',', $raw));
        $pairs = [];
        foreach ($values as $v) {
            // Extract param name from {name} placeholder
            if (preg_match('/^\{(\w+)\}$/', $v, $m)) {
                $pairs[] = $m[1] . ': ' . $v;
            } else {
                $pairs[] = 'id: ' . $v;
            }
        }
        return '{' . implode(', ', $pairs) . '}';
    }

    // ── mad-data-table compiler ──────────────────────────────────────────────

    /**
     * Compila <mad-data-table>...<mad-col .../>...</mad-data-table>
     * → <?php echo \Mad\Grid\GridRenderHelpers::renderDataTable([config], [colConfigs]); ?>
     *
     * Read-only: sem actions, sem edit, sem search, sem paginacao.
     * Fonte (precedencia): :query Builder pronto > model auto-query > items inline.
     */
    protected static function compileDataTableBlock(array $match): string
    {
        $attrStr = $match[1] ?? '';
        $body    = $match[2] ?? '';

        $tableAttrs = static::parseAttrs($attrStr);

        // Strip outer '>' and '</mad-data-table>'
        $inner = preg_replace('/^\s*>|<\/mad-data-table>\s*$/s', '', $body);

        // Self-closing: sem corpo
        if ($inner === null) $inner = '';

        // Remove tags organizacionais
        $inner = preg_replace('/<\/?mad-columns\b' . self::ATTR_RUN . '>/s', '', $inner);

        // Extrai colunas filhas — apenas <mad-col .../> self-closing por simplicidade
        // (data-table nao tem actions/edit/filter dentro de col)
        $colConfigs = [];
        if ($inner) {
            preg_match_all(
                '/<mad-col(?:umn)?([\s\S]*?)\s*\/>/s',
                $inner,
                $colMatches,
                PREG_SET_ORDER
            );
            foreach ($colMatches as $cm) {
                $colAttrs = static::parseAttrs($cm[1] ?? '');
                $colConfigs[] = static::buildColConfig($colAttrs);
            }
        }

        // Monta config array PHP
        $cfgParts = [];

        // items: PHP expression OR string ignored
        if (isset($tableAttrs['items'])) {
            $cfgParts[] = "'items' => " . static::emit($tableAttrs['items']);
        }
        // query: Eloquent/Query Builder pronto (precedencia sobre model) — herda
        // periodo + filtros do <mad-dash-filters>, igual mad-db-chart/metric-card.
        if (isset($tableAttrs['query'])) {
            $cfgParts[] = "'query' => " . static::emit($tableAttrs['query']);
        }
        // model + database
        if (isset($tableAttrs['model'])) {
            $cfgParts[] = "'model' => " . static::qs(static::str($tableAttrs, 'model'));
        }
        if (isset($tableAttrs['database'])) {
            $cfgParts[] = "'database' => " . static::qs(static::str($tableAttrs, 'database'));
        }
        // filters — PHP expressions
        if (isset($tableAttrs['filters'])) {
            $cfgParts[] = "'filters' => " . static::emit($tableAttrs['filters']);
        }
        // order-by / limit
        if (isset($tableAttrs['order-by'])) {
            $cfgParts[] = "'order-by' => " . static::qs(static::str($tableAttrs, 'order-by'));
        }
        if (isset($tableAttrs['limit'])) {
            $cfgParts[] = "'limit' => " . static::emit($tableAttrs['limit']);
        }
        // group-by — string simples ou array PHP (`:group-by="['ano','mes']"`)
        if (isset($tableAttrs['group-by'])) {
            if ($tableAttrs['group-by']['type'] === 'php') {
                $cfgParts[] = "'group-by' => " . static::emit($tableAttrs['group-by']);
            } else {
                $cfgParts[] = "'group-by' => " . static::qs(static::str($tableAttrs, 'group-by'));
            }
        }
        // group-mask — string simples ou array PHP (`:group-mask="['Ano {ano}','Mes {mes}']"`)
        if (isset($tableAttrs['group-mask'])) {
            if ($tableAttrs['group-mask']['type'] === 'php') {
                $cfgParts[] = "'group-mask' => " . static::emit($tableAttrs['group-mask']);
            } else {
                $cfgParts[] = "'group-mask' => " . static::qs(static::str($tableAttrs, 'group-mask'));
            }
        }
        // empty-text
        if (isset($tableAttrs['empty-text'])) {
            $cfgParts[] = "'empty-text' => " . static::qs(static::str($tableAttrs, 'empty-text'));
        }
        // bool flags
        foreach (['no-totals', 'bordered', 'compact', 'group-total'] as $flag) {
            if (static::has($tableAttrs, $flag)) {
                $cfgParts[] = "'{$flag}' => true";
            }
        }
        // zebra default true; permitir desligar com :zebra="false"
        if (isset($tableAttrs['zebra'])) {
            $cfgParts[] = "'zebra' => " . static::emit($tableAttrs['zebra']);
        }
        // class extra
        if (isset($tableAttrs['class'])) {
            $cfgParts[] = "'class' => " . static::emit($tableAttrs['class']);
        }

        $cfgPhp  = '[' . implode(', ', $cfgParts) . ']';
        // buildColConfig() ja retorna '[...]' — apenas concatenar
        $colsPhp = '[' . implode(', ', $colConfigs) . ']';

        return "<?php echo \\Mad\\Grid\\GridRenderHelpers::renderDataTable({$cfgPhp}, {$colsPhp}); ?>";
    }
}