<?php

namespace Mad\Security;

use Mad\I18n\MadLang;

/**
 * ActionVocab — o dicionário entre a AÇÃO que o navegador pede e a CHAVE de
 * permissão que o usuário marca na tela de Perfis.
 *
 * O perfil marca cinco caixinhas — Visualizar, Incluir, Editar, Excluir,
 * Exportar — e elas são gravadas como `view`, `insert`, `edit`, `delete` e
 * `export`. O canal reativo, porém, pede métodos: `onDelete`, `onExportPDF`,
 * `blockRemove`, `onMadGridDelete`. Sem uma tradução entre os dois mundos o
 * gate comparava `onDelete` com `delete`, nunca casava, e um perfil "só
 * Visualizar" salvava, excluía e exportava à vontade.
 *
 * Três famílias de método:
 *
 *   1. **Estáticas** (`METHOD_MAP`) — o método já diz o que faz: `onDelete` é
 *      sempre exclusão. Resolvidas por {@see staticKey()}.
 *   2. **Contextuais** (`CONTEXTUAL`) — `onSave` é inclusão num cadastro novo e
 *      edição num registro aberto. Só dá para decidir DEPOIS de o componente
 *      estar hidratado; daí {@see contextualKey()} receber o contexto.
 *   3. **Navegação** (`NAVIGATION`) — buscar, paginar, ordenar, trocar o valor
 *      de um combo. Nunca são permissão: bloquear "próxima página" em quem pode
 *      ver a tela não faz sentido nenhum.
 *
 * Qualquer outro método público do control é uma **ação própria** (`onAprovar`,
 * `onDecide`): a chave dela é o próprio nome do método.
 */
final class ActionVocab
{
    /**
     * Rótulos de `#[MadPermission]` já lidos, por `Classe::metodo` (null = a
     * tela não batizou). O atributo é código: não muda entre requisições.
     *
     * @var array<string, string|null>
     */
    private static array $declared = [];

    /** As cinco chaves que a tela de Perfis marca, na ordem em que aparecem. */
    public const KEYS = ['view', 'insert', 'edit', 'delete', 'export'];

    /**
     * Chave → métodos das classes-base do framework.
     *
     * `view` tem UM método, e o nome dele engana: `onEdit` ABRE o registro no
     * formulário (`$form->fill(...)`) — quem grava é o `onSave`. Enquanto ele
     * valia por `edit`, um perfil só com Visualizar levava 403 ao clicar no
     * lápis da listagem e em `/app/usuarios/5/editar`: via a lista inteira e
     * não via registro nenhum. Visualizar continua sendo a permissão de ABRIR,
     * checada no gate de programa; esta linha só diz que abrir um registro
     * também é abrir.
     */
    public const METHOD_MAP = [
        'view' => [
            'onEdit',              // ABRE o registro no form; gravar é o onSave
        ],
        'insert' => [
            'blockAdd',            // <mad-comments>/<mad-attachments> (MadDbBlocksTrait)
            'onFinalizeSale',      // PDV
            'onNoteAdd',           // <mad-whiteboard>: nova nota
        ],
        'edit' => [
            'onInlineSave',        // edição na própria célula do grid
            'blockUpdate',
            'blockEdit',
            'onEventUpdate',       // agenda
            'onTaskUpdate',        // kanban
            'onCardMove',          // kanban
            'onStageMove',         // kanban: reordenar colunas
            'onSaveBatch',         // planilha
            'onReparent',          // organograma
            'onWeekCell',          // <mad-week-grid>: estado de um dia
            'onWeekNote',          // <mad-week-grid>: texto livre da linha
            'onWeekCopy',          // <mad-week-grid>: copiar semana anterior
            'onNoteMove',          // <mad-whiteboard>: posição/tamanho ao soltar
            'onNoteText',          // <mad-whiteboard>: texto editado no lugar
            'onNoteColor',         // <mad-whiteboard>: cor da nota
            // conciliação
            'onAutoMatch',
            'onManualMatch',
            'onConfirmGroup',
            'onConfirmAll',
            'onRejectGroup',
            'onUnmatch',
            'onRunRoutine',        // botão / ação de linha "Executar rotina" (Mad\Routine)
        ],
        'delete' => [
            'onDelete',
            'onMadGridDelete',     // exclusão embutida do <mad-grid del>
            'blockRemove',
            'onNoteDelete',        // <mad-whiteboard>
        ],
        'export' => [
            'onExportCSV',
            'onExportXLSX',
            'onExportPDF',
        ],
    ];

    /** Métodos cuja chave depende de haver ou não registro aberto. */
    public const CONTEXTUAL = ['onSave', 'onSaveDraft'];

    /**
     * Métodos de navegação/consulta: nunca viram permissão.
     *
     * Além desta lista, tudo que começa com `onChange` (combos dependentes) é
     * navegação — o usuário está preenchendo a tela, não gravando nada.
     */
    public const NAVIGATION = [
        'onSearch', 'onReload', 'onPage', 'onSort', 'onFilter', 'onClearFilter',
        'onClearAllFilters', 'onLimpar', 'onColFilter', 'onClearColFilter',
        'onSearchTerm', 'onPerPage', 'onFiltrar', 'onAtualizar', 'onRefresh',
        'onWeekGo',
        'onShow', 'onEventClick', 'onDayClick', 'onSlotClick', 'onLoadMore',
        'onLoadChildren', 'onValidateBatch', 'onProductLookup', 'onSelect',
        'onToggleWidget', 'onWizardNext', 'onWizardBack', 'onWizardGoto',
        // Só o rodapé de totais da listagem, pedido pelo navegador depois que
        // uma linha mudou (MadDataGrid::onMadGridTotals): é consulta.
        'onMadGridTotals',
    ];

    /** Prefixo dos combos dependentes (`onChangeCidade`, `onChangeEstadoId`…). */
    private const NAVIGATION_PREFIX = 'onChange';

    /**
     * Chave do método quando ela NÃO depende do contexto, ou null.
     *
     * `onSave`/`onSaveDraft` devolvem null aqui de propósito — use
     * {@see contextualKey()} ou {@see keyFor()}.
     */
    public static function staticKey(string $method): ?string
    {
        static $plano = null;

        if ($plano === null) {
            $plano = [];
            foreach (self::METHOD_MAP as $chave => $metodos) {
                foreach ($metodos as $m) {
                    $plano[$m] = $chave;
                }
            }
        }

        return $plano[$method] ?? null;
    }

    /**
     * Chave dos métodos contextuais: salvar um registro NOVO é incluir; salvar
     * um registro já aberto é editar.
     *
     * @param  bool  $isNew  a tela está sem registro carregado?
     */
    public static function contextualKey(string $method, bool $isNew): ?string
    {
        if (!in_array($method, self::CONTEXTUAL, true)) {
            return null;
        }

        return $isNew ? 'insert' : 'edit';
    }

    /** O método é navegação/consulta (nunca permissão)? */
    public static function isNavigation(string $method): bool
    {
        if (in_array($method, self::NAVIGATION, true)) {
            return true;
        }

        return str_starts_with($method, self::NAVIGATION_PREFIX);
    }

    /**
     * A chave de permissão do método, ou null quando ele não tem uma.
     *
     * @param  bool|null  $isNew  null = o chamador não sabe se é inclusão ou
     *                            edição; nesse caso os métodos contextuais
     *                            ficam sem chave (fail-open), o que é o certo
     *                            na fase precoce do gate.
     */
    public static function keyFor(string $method, ?bool $isNew = null): ?string
    {
        $chave = self::staticKey($method);
        if ($chave !== null) {
            return $chave;
        }

        if ($isNew !== null) {
            return self::contextualKey($method, $isNew);
        }

        return null;
    }

    /**
     * Rótulo da chave, como o usuário a vê ("Excluir").
     *
     * Chave fora do vocabulário é ação própria. Com a tela em mãos, vale o nome
     * que ela mesma deu ao método (`#[MadPermission('Cancelar comanda')]`) — é
     * o que a tela de Perfis mostra, e a recusa tem de falar a mesma palavra.
     * Sem tela, ou sem rótulo escrito, vira nome legível a partir do método
     * (`onAprovarOrcamento` → "Aprovar orcamento"), nunca o nome cru.
     *
     * @param  string|list<string>|null  $owners  Classe(s) onde o método mora —
     *                                            a tela e, num grid, também a
     *                                            classe de ações. Responde a
     *                                            primeira que batizou o método.
     */
    public static function label(string $key, string|array|null $owners = null): string
    {
        if (in_array($key, self::KEYS, true)) {
            return MadLang::t('mad.action_' . $key);
        }

        if ($owners !== null) {
            foreach ((array) $owners as $dono) {
                $escrito = is_string($dono) ? self::declaredLabel($dono, $key) : null;
                if ($escrito !== null) {
                    return self::translated($escrito);
                }
            }
        }

        return self::humanize($key);
    }

    /**
     * Dica do botão recusado: "Sem permissão para excluir".
     *
     * @param  string|list<string>|null  $owners  ver {@see label()}
     */
    public static function denyTitle(string $key, string|array|null $owners = null): string
    {
        return MadLang::t('mad.no_permission_to', [
            'action' => self::lcfirst(self::label($key, $owners)),
        ]);
    }

    /**
     * O rótulo escrito pode ser uma CHAVE de tradução (`admin.action_clone`) —
     * é o que as telas do próprio framework usam, para a palavra acompanhar o
     * idioma do app. A tela de Perfis a traduz ao mostrar; a recusa também.
     * Qualquer outro texto é literal e volta como veio.
     *
     * O formato (`grupo.chave`) é exigido antes de consultar o tradutor, para
     * um rótulo comum ("Trocar plano") nunca casar com uma frase solta.
     */
    private static function translated(string $label): string
    {
        if (preg_match('/^[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/', $label) !== 1 || !function_exists('__')) {
            return $label;
        }

        try {
            $texto = __($label);
        } catch (\Throwable $e) {
            return $label;
        }

        return is_string($texto) && $texto !== '' ? $texto : $label;
    }

    /**
     * O rótulo que a tela escreveu em `#[MadPermission('…')]` para o método, ou
     * null quando não há (método ausente, sem atributo, atributo sem rótulo ou
     * marcado `permission: false`).
     *
     * A mesma leitura que a tela de Perfis faz ao montar as caixinhas. Nunca
     * estoura: rótulo é conforto, e a recusa não pode virar erro por causa dele.
     */
    private static function declaredLabel(string $class, string $method): ?string
    {
        $class = ltrim(trim($class), '\\');
        if ($class === '' || $method === '') {
            return null;
        }

        $chave = $class . '::' . $method;
        if (array_key_exists($chave, self::$declared)) {
            return self::$declared[$chave];
        }

        $rotulo = null;
        try {
            // O dono chega como classe completa (tela do wire) ou como
            // identificador curto (`owner`/`handler` de um grid, destino de um
            // botão): o registro das telas resolve o segundo.
            $fqcn = class_exists($class) ? $class : null;
            if ($fqcn === null && class_exists(\Mad\Registry\ControlRegistry::class)) {
                $fqcn = \Mad\Registry\ControlRegistry::resolve($class);
            }

            if ($fqcn !== null && method_exists($fqcn, $method)) {
                $atributos = (new \ReflectionMethod($fqcn, $method))->getAttributes(MadPermission::class);
                foreach ($atributos as $atributo) {
                    $declarado = $atributo->newInstance();
                    $texto     = trim((string) $declarado->label);
                    if ($declarado->permission && $texto !== '') {
                        $rotulo = $texto;
                    }
                    break;
                }
            }
        } catch (\Throwable $e) {
            $rotulo = null; // atributo mal escrito / classe que não carrega
        }

        return self::$declared[$chave] = $rotulo;
    }

    /**
     * `onAprovarOrcamento` → "Aprovar orcamento".
     *
     * Tira o `on` da frente, separa as palavras do camelCase e devolve só a
     * primeira em maiúscula — texto de gente, não identificador de código.
     */
    public static function humanize(string $method): string
    {
        $nome = $method;
        if (preg_match('/^on[A-Z]/', $nome)) {
            $nome = substr($nome, 2);
        }

        $nome = preg_replace('/(?<!^)[A-Z]/', ' $0', $nome) ?? $nome;
        $nome = trim(preg_replace('/[_\-]+/', ' ', $nome) ?? $nome);
        $nome = preg_replace('/\s+/', ' ', $nome) ?? $nome;

        if ($nome === '') {
            return $method;
        }

        return self::ucfirst(mb_strtolower($nome, 'UTF-8'));
    }

    private static function ucfirst(string $texto): string
    {
        return mb_strtoupper(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($texto, 1, null, 'UTF-8');
    }

    private static function lcfirst(string $texto): string
    {
        // Sigla no começo ("PDF da comanda") fica como foi escrita.
        if (preg_match('/^\p{Lu}{2}/u', $texto) === 1) {
            return $texto;
        }

        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($texto, 1, null, 'UTF-8');
    }
}
