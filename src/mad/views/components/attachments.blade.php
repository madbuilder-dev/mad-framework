@php
    /**
     * <mad-attachments> — anexos de um registro: lista + upload embutido +
     * baixar + excluir. Preset fino sobre <mad-db-blocks>, que já grava
     * item-a-item, re-renderiza a lista e (agora) ingere o arquivo.
     *
     *   <mad-attachments model="TicketAttachments" foreign-key="ticket_id"
     *                    folder="uploads/tickets" />
     *
     * Um arquivo = um registro (soltar 3 arquivos cria 3 linhas). As colunas
     * de metadado (`original_name`, `size`, `mime_type`, `disk`) são
     * preenchidas por convenção quando existem na tabela — ou mapeadas nas
     * props `original-name-column`, `size-column`, `mime-column`, `disk-column`.
     *
     * Download SEMPRE por `mad_download_url()` (rota assinada do framework);
     * excluir remove a linha E o arquivo do disco de uploads.
     */
    $name        = $name        ?? 'anexos';
    $model       = $model       ?? '';
    $foreignKey  = $foreignKey  ?? '';
    $recordId    = $recordId    ?? null;
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');

    $folder      = $folder      ?? 'uploads';
    $pathColumn  = $pathColumn  ?? 'path';
    $nameColumn  = $nameColumn  ?? '';          // '' = usa original_name/filename
    // `name-column` é a coluna que a tela usa pra EXIBIR o nome do arquivo.
    // Ela também é o destino natural do nome original — sem esse elo, uma
    // coluna `filename NOT NULL` estourava no insert (nada a preenchia).
    $originalNameColumn = $originalNameColumn ?? '';
    $sizeColumn  = $sizeColumn  ?? '';
    $mimeColumn  = $mimeColumn  ?? '';
    $diskColumn  = $diskColumn  ?? '';
    $fileName    = $fileName    ?? 'prefix';

    $accept      = $accept      ?? '*';
    $maxFiles    = (int) ($maxFiles ?? 10);
    $label       = $label       ?? '';
    $submitLabel = $submitLabel ?? 'Enviar';
    $emptyText   = $emptyText   ?? 'Sem anexos. Envie o primeiro abaixo.';
    $orderDir    = $orderDir    ?? 'desc';
    $deletable   = isset($deletable) ? !empty($deletable) : true;
    $itemTemplate = $itemTemplate ?? '';
    $onAdd       = $onAdd    ?? '';
    $onRemove    = $onRemove ?? '';

    // Campo de arquivo do form — o motor lê `$_FILES[fileField]` no blockAdd.
    $fileField = $name . '_file';

    if ($originalNameColumn === '' && $nameColumn !== '') {
        $originalNameColumn = $nameColumn;
    }

    // Opt-in: `confirm-remove` pelado usa a mensagem padrão; com valor, a sua.
    // Aqui o padrão avisa do arquivo — o blockRemove apaga a linha E o arquivo
    // do disco, e isso não volta.
    $confirmRemove = \Mad\Form\MadDbBlocks::confirmMessage(
        $confirmRemove ?? '',
        'Excluir este anexo? O arquivo será removido do servidor.'
    );

    $presetVars = [
        'path'          => $pathColumn,
        'nameCol'       => $nameColumn,
        'deletable'     => $deletable,
        'confirmRemove' => $confirmRemove,
    ];

    // Item: partial dedicada (heredoc não serve — o Blade compila o arquivo
    // todo, inclusive o conteúdo da string).
    $formTpl = '<mad-multi-file-field name="' . e($fileField) . '" label="Arquivos"'
        . ' accept="' . e($accept) . '" :max-files="' . $maxFiles . '" />';

    // Compila as tags <mad-*> do slot (o {!! !!} do motor imprime cru).
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
    :row-view="$itemTemplate === '' ? 'components.partials.attachment-row' : ''"
    :row-slot="$itemTemplate"
    :form-slot="$formTpl"
    :preset-vars="$presetVars"
    order-by="id"
    :order-dir="$orderDir"
    add-mode="inline"
    form-position="below"
    :submit-label="$submitLabel"
    submit-icon="upload"
    layout="stack"
    :empty-text="$emptyText"
    :file-field="$fileField"
    :folder="$folder"
    :path-column="$pathColumn"
    :file-name="$fileName"
    :original-name-column="$originalNameColumn"
    :size-column="$sizeColumn"
    :mime-column="$mimeColumn"
    :disk-column="$diskColumn"
    :on-add="$onAdd"
    :on-remove="$onRemove" />
