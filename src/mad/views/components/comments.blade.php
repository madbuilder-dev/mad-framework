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
     */
    $name        = $name        ?? 'comentarios';
    $model       = $model       ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $recordId    = $recordId    ?? null;
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

    $bodyField       = $bodyField       ?? 'body';
    $dateField       = $dateField       ?? 'created_at';
    $authorField     = $authorField     ?? 'user_id';
    $authorNameField = $authorNameField ?? 'author_name';
    $internalFlag    = $internalFlag    ?? '';        // ex.: is_internal ('' = sem badge/switch)
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
        'author'        => $authorField,
        'authorName'    => $authorNameField,
        'internal'      => $internalFlag,
        'deletable'     => $deletable,
        'confirmRemove' => $confirmRemove,
    ];

    // Template do item: partial dedicada (components.partials.comment-row).
    // NÃO usar heredoc aqui — o Blade compila o conteúdo do arquivo inteiro,
    // inclusive dentro da string, e um `@php` no template quebraria a view.
    // `item-template` (string Blade) segue disponível como override.
    // Caixa de inclusão (form fixo abaixo da lista).
    $formTpl = '<mad-textarea-field name="' . e($bodyField) . '" :rows="' . $rows . '" placeholder="' . e($placeholder) . '" />';
    if ($internalFlag !== '') {
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
    :row-view="$itemTemplate === '' ? 'components.partials.comment-row' : ''"
    :row-slot="$itemTemplate"
    :form-slot="$formTpl"
    :preset-vars="$presetVars"
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
