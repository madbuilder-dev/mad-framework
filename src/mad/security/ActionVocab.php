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
            // conciliação
            'onAutoMatch',
            'onManualMatch',
            'onConfirmGroup',
            'onConfirmAll',
            'onRejectGroup',
            'onUnmatch',
        ],
        'delete' => [
            'onDelete',
            'onMadGridDelete',     // exclusão embutida do <mad-grid del>
            'blockRemove',
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
     * Chave fora do vocabulário é ação própria: vira nome legível a partir do
     * método (`onAprovarOrcamento` → "Aprovar orcamento"), nunca o nome cru.
     */
    public static function label(string $key): string
    {
        if (in_array($key, self::KEYS, true)) {
            return MadLang::t('mad.action_' . $key);
        }

        return self::humanize($key);
    }

    /** Dica do botão recusado: "Sem permissão para excluir". */
    public static function denyTitle(string $key): string
    {
        return MadLang::t('mad.no_permission_to', [
            'action' => self::lcfirst(self::label($key)),
        ]);
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
        return mb_strtolower(mb_substr($texto, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($texto, 1, null, 'UTF-8');
    }
}
