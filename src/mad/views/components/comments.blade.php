@php
    /**
     * <mad-comments> — feed de comentários de um registro, com a caixa de
     * inclusão embutida. Preset fino sobre <mad-db-blocks>: lista, gravação
     * item-a-item, re-render e limpeza do form vêm do motor; aqui moram só os
     * defaults e o layout do card.
     *
     * Antes deste componente cada tela repetia ~70 linhas (template numa const
     * PHP + render*() relendo o banco + action + ->html() + limpeza do campo).
     * Agora:
     *
     *   <mad-comments model="TicketComments" foreign-key="ticket_id" />
     *
     * O `record-id` é resolvido sozinho a partir do `$registroId` da tela.
     * Visual custom (opcional): `:item-template="$bladeString"` — o template
     * recebe `$item` (Model), `$vars` (os nomes de campo), `$state` e `$name`.
     *
     * Comentário interno (`internal-flag="coluna"`): `internal-visible` diz
     * quem vê — expressão (`:internal-visible="$ehDaEquipe"`) ou
     * `internal-visible="Classe::metodo"` (estático, devolve bool). Quem não
     * vê recebe o feed SEM os internos (filtro no servidor: lista, re-render,
     * editar e remover) e a caixa sem o switch; o comentário dele grava
     * público. Sem a prop, todos veem (como antes).
     *
     * Autor (`author-field`, padrão `user_id`): o comentário novo grava o
     * usuário logado nessa coluna, quando a tabela a tem. Vem da sessão, não
     * do formulário (fw#466).
     *
     * Tabela de comentários de várias entidades (fw#455): `:filters` restringe
     * o feed e `:defaults` preenche o comentário novo, como no
     * `<mad-whiteboard>` — `:filters="[['entidade', '=', 'projeto']]"`
     * `:defaults="['entidade' => 'projeto']"`.
     *
     * Data (`date-field`, padrão `created_at`): no fuso do app e no formato do
     * idioma (pt-BR: 21/10/2026 13:28). `date-format="d/m/Y \à\s H:i"` (formato
     * PHP) troca o formato (fw#478). `date-relative` mostra o comentário
     * recente como "há 5 min" / "ontem" / "há 3 dias", com a data completa na
     * dica; pelado vale para os últimos 7 dias, `date-relative="3"` para 3
     * (fw#484). Mais antigo que isso, a data completa.
     */
    $name        = $name        ?? 'comentarios';
    $model       = $model       ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $recordId    = $recordId    ?? null;
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

    $bodyField       = $bodyField       ?? 'body';
    $dateField       = $dateField       ?? 'created_at';
    $dateFormat      = $dateFormat      ?? '';
    $dateRelative    = \Mad\Support\RelativeTime::days($dateRelative ?? null);
    $authorField     = $authorField     ?? 'user_id';
    $authorNameField = $authorNameField ?? 'author_name';
    $internalFlag    = $internalFlag    ?? '';        // ex.: is_internal ('' = sem badge/switch)
    $internalVisible = $internalVisible ?? true;
    if (is_string($internalVisible) && str_contains($internalVisible, '::')) {
        // `Classe::metodo`: quem decide é o app. Método que não existe nega
        // (fail-closed) — nota interna exposta é o defeito que isto corrige.
        [$_ivClass, $_ivMethod] = explode('::', ltrim($internalVisible, '\\'), 2);
        if (is_callable([$_ivClass, $_ivMethod])) {
            $internalVisible = (bool) call_user_func([$_ivClass, $_ivMethod]);
        } else {
            error_log('[mad-comments] internal-visible="' . $internalVisible . '": método não encontrado — internos ocultos.');
            $internalVisible = false;
        }
    } elseif (is_string($internalVisible)) {
        $internalVisible = !in_array(strtolower(trim($internalVisible)), ['', '0', 'false', 'no', 'nao', 'não'], true);
    } else {
        $internalVisible = (bool) $internalVisible;
    }
    $hideInternal = $internalFlag !== '' && !$internalVisible;
    // Escopo do bloco: a coluna do filtro também fica fora do que o formulário
    // grava (MadDbBlocks::scopeColumns), então o comentário de quem não vê
    // não nasce interno nem por requisição forjada.
    $filters = array_merge(
        is_array($filters ?? null) ? $filters : [],
        $hideInternal ? [[$internalFlag, 'is not true', null]] : []
    );
    $defaults = is_array($defaults ?? null) ? $defaults : [];
    $orderDir        = $orderDir        ?? 'asc';
    $label           = $label           ?? '';
    $placeholder     = $placeholder     ?? 'Escreva um comentário...';
    $submitLabel     = $submitLabel     ?? 'Comentar';
    $emptyText       = $emptyText       ?? 'Sem comentários. Escreva o primeiro abaixo.';
    $deletable       = isset($deletable) ? !empty($deletable) : true;
    $editable        = !empty($editable);
    $rows            = (int) ($rows ?? 3);
    $itemTemplate    = $itemTemplate ?? '';
    $onAdd           = $onAdd    ?? '';
    $onRemove        = $onRemove ?? '';
    $onUpdate        = $onUpdate ?? '';

    // Nomes de campo entregues ao template do item como $vars.
    // Opt-in: `confirm-remove` pelado usa a mensagem padrão; com valor, a sua.
    $confirmRemove = \Mad\Form\MadDbBlocks::confirmMessage(
        $confirmRemove ?? '',
        'Excluir este comentário?'
    );

    $presetVars = [
        'body'          => $bodyField,
        'date'          => $dateField,
        'dateFormat'    => (string) $dateFormat,
        'dateRelative'  => $dateRelative,
        'author'        => $authorField,
        'authorName'    => $authorNameField,
        'internal'      => $hideInternal ? '' : $internalFlag,
        'deletable'     => $deletable,
        'confirmRemove' => $confirmRemove,
    ];

    // Template do item: partial dedicada (components.partials.comment-row).
    // NÃO usar heredoc aqui — o Blade compila o conteúdo do arquivo inteiro,
    // inclusive dentro da string, e um `@php` no template quebraria a view.
    // `item-template` (string Blade) segue disponível como override.
    // Caixa de inclusão (form fixo abaixo da lista).
    $formTpl = '<mad-textarea-field name="' . e($bodyField) . '" :rows="' . $rows . '" placeholder="' . e($placeholder) . '" />';
    if ($internalFlag !== '' && !$hideInternal) {
        $formTpl .= '<mad-switch-field name="' . e($internalFlag) . '" label="Comentário interno (só a equipe vê)" />';
    }

    // ⚠️ O slot é injetado com {!! !!} — string crua NÃO passa pelo compilador,
    // então as tags <mad-*> precisam ser compiladas AQUI (senão o form sai
    // vazio e o bloco fica sem caixa de inclusão).
    $formTpl = \Mad\View\MadBlade::renderString($formTpl);
@endphp

<mad-db-blocks
    :name="$name"
    :label="$label"
    mode="pivot"
    :pivot-model="$model"
    :database="$database"
    :foreign-key="$foreignKey"
    :record-id="$recordId"
    :filters="$filters"
    :defaults="$defaults"
    :row-view="$itemTemplate === '' ? 'components.partials.comment-row' : ''"
    :row-slot="$itemTemplate"
    :form-slot="$formTpl"
    :preset-vars="$presetVars"
    :user-field="$authorField"
    order-by="id"
    :order-dir="$orderDir"
    add-mode="inline"
    form-position="below"
    :submit-label="$submitLabel"
    submit-icon="send"
    layout="stack"
    :empty-text="$emptyText"
    :editable="$editable"
    :on-add="$onAdd"
    :on-remove="$onRemove"
    :on-update="$onUpdate" />
