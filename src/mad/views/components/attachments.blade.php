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
     *
     * Tabela de anexos de várias entidades (fw#455): `:filters` restringe a
     * lista e `:defaults` preenche o item novo, como no `<mad-whiteboard>`:
     *
     *   <mad-attachments model="Anexo" foreign-key="registro_id"
     *       :filters="[['entidade', '=', 'projeto']]"
     *       :defaults="['entidade' => 'projeto']" />
     *
     * Campos por anexo (fw#457): os campos filhos vão no formulário de envio e
     * são gravados na linha de cada arquivo (soltar 3 arquivos = 3 linhas com
     * os mesmos valores). A linha da lista mostra o que foi gravado.
     *
     *   <mad-attachments model="FornecedorDocumento" foreign-key="fornecedor_id">
     *       <mad-select-field name="tipo" label="Tipo" :items="$tipos" />
     *       <mad-date-field name="validade" label="Validade" />
     *   </mad-attachments>
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
    $filters     = is_array($filters ?? null) ? $filters : [];
    $defaults    = is_array($defaults ?? null) ? $defaults : [];

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

    // Campos por anexo (fw#457): o slot chega já desenhado, e cada campo
    // deixou o tipo (data, número, escolha) e o rótulo no registro de campos
    // ao ser desenhado. É de lá, pelo `name`, que sai o schema do form do
    // bloco — o blockAdd converte a data digitada como o formulário da tela.
    $__fieldsHtml = isset($slot) ? trim((string) $slot) : '';
    $formSchema   = [];
    if ($__fieldsHtml !== '') {
        $__known = \Mad\Form\MadFormRegistry::getFields();
        preg_match_all('/\bname="([A-Za-z_][A-Za-z0-9_]*)"/', $__fieldsHtml, $__names);
        foreach (array_unique($__names[1]) as $__field) {
            if (isset($__known[$__field]) && $__field !== $fileField) {
                $formSchema[$__field] = $__known[$__field];
            }
        }
    }

    // campo => [rótulo, tipo, máscara de exibição, opções] — a linha mostra o
    // valor. Escolha: o rótulo da opção, não a chave gravada.
    $__rowFields = [];
    $__attrs     = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';   // atributos, `>` dentro de aspas incluso
    foreach ($formSchema as $__field => $__props) {
        $__rowFields[$__field] = [
            'label'   => (string) ($__props['label'] ?? ''),
            'type'    => (string) ($__props['type'] ?? ''),
            'mask'    => (string) ($__props['display_mask'] ?? ''),
            'options' => [],
        ];
        if (($__props['type'] ?? '') !== 'select'
            || !preg_match('/<select\b' . str_replace('*', '*?', $__attrs) . '\sname="' . preg_quote($__field, '/') . '(?:\[\])?"' . $__attrs . '>(.*?)<\/select>/s', $__fieldsHtml, $__sel)) {
            continue;
        }
        preg_match_all('/<option\b' . str_replace('*', '*?', $__attrs) . '\svalue="([^"]*)"' . $__attrs . '>(.*?)<\/option>/s', $__sel[1], $__opts, PREG_SET_ORDER);
        foreach (array_slice($__opts, 0, 500) as $__o) {
            if ($__o[1] !== '') {
                $__rowFields[$__field]['options'][html_entity_decode($__o[1], ENT_QUOTES)] = trim(html_entity_decode(strip_tags($__o[2]), ENT_QUOTES));
            }
        }
    }

    $presetVars = [
        'path'          => $pathColumn,
        'nameCol'       => $nameColumn,
        'deletable'     => $deletable,
        'confirmRemove' => $confirmRemove,
        'fields'        => $__rowFields,
    ];

    // Item: partial dedicada (heredoc não serve — o Blade compila o arquivo
    // todo, inclusive o conteúdo da string).
    $formTpl = '<mad-multi-file-field name="' . e($fileField) . '" label="Arquivos"'
        . ' accept="' . e($accept) . '" :max-files="' . $maxFiles . '" />';

    // Compila as tags <mad-*> do slot (o {!! !!} do motor imprime cru).
    $formTpl = $__fieldsHtml . \Mad\View\MadBlade::renderString($formTpl);
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
    :row-view="$itemTemplate === '' ? 'components.partials.attachment-row' : ''"
    :row-slot="$itemTemplate"
    :form-slot="$formTpl"
    :form-schema="$formSchema"
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
