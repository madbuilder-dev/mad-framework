@php
    $name        = $name ?? '';
    $label       = $label ?? '';
    $mode        = $mode ?? 'pivot';      // pivot = 1:N vinculado a parent | flat = lista master
    $pivotModel  = $pivotModel ?? '';
    $database    = $database ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $foreignKey  = $foreignKey ?? '';
    $recordId    = $recordId ?? null;
    $rowView     = $rowView ?? '';
    $orderBy     = $orderBy ?? 'id';
    $orderDir    = $orderDir ?? 'asc';
    // popover | modal | drawer | inline (form FIXO na tela, sem overlay —
    // caso de comentários/anexos numa aba de detalhe)
    $addMode     = $addMode ?? 'popover';
    $formPosition = $formPosition ?? 'below';   // below | above (só no inline)
    $submitLabel  = $submitLabel  ?? 'Salvar';
    $submitIcon   = $submitIcon   ?? 'check';
    $addLabel    = $addLabel ?? '+ Adicionar';
    $addIcon     = $addIcon ?? 'plus';
    $addVariant  = $addVariant ?? 'ghost';
    $layout      = $layout ?? 'inline';   // inline (chips, wrap) | stack (rows 100%)
    $emptyText   = $emptyText ?? 'Nenhum item';
    $hideAddButton = !empty($hideAddButton) || $addMode === 'inline';
    // no-list: modo "só adicionar" — não pré-renderiza nem re-renderiza a lista
    // (a exibição dos itens é de outro componente, ex.: a tree de pastas do GED).
    $noList = !empty($noList);
    // Upload por item (opcional): quando declarados, o blockAdd grava o(s)
    // arquivo(s) do campo `file-field` no disco de uploads e preenche
    // path/metadados no próprio item. Multi-arquivo = N itens.
    // Editar item no lugar (mesmo form do adder, preenchido)
    $editable    = !empty($editable);
    $editFields  = $editFields  ?? [];

    // Valores livres que o preset (mad-comments/mad-attachments) injeta no
    // template do item — ele os recebe como $vars.
    $presetVars  = $presetVars  ?? [];

    // Confirmação antes de remover o item. OPT-IN: `confirm-remove` pelado usa a
    // mensagem padrão, com valor usa a sua. Chega ao template do item como
    // $vars['confirmRemove'] — os row-views do framework emitem `data-mad-confirm`,
    // que o wire já intercepta antes de disparar a ação (mad-livewire.js).
    // Um preset que já resolveu a mensagem manda ela dentro de $presetVars e vence.
    $confirmRemove = \Mad\Form\MadDbBlocks::confirmMessage($confirmRemove ?? '', 'Remover este item?');
    if ($confirmRemove !== '' && ! array_key_exists('confirmRemove', $presetVars)) {
        $presetVars['confirmRemove'] = $confirmRemove;
    }

    $fileField   = $fileField   ?? '';
    $folder      = $folder      ?? 'uploads';
    $pathColumn  = $pathColumn  ?? '';
    $fileName    = $fileName    ?? 'prefix';
    $originalNameColumn = $originalNameColumn ?? '';
    $sizeColumn  = $sizeColumn  ?? '';
    $mimeColumn  = $mimeColumn  ?? '';
    $diskColumn  = $diskColumn  ?? '';

    $onAdd         = $onAdd         ?? '';
    $onRemove      = $onRemove      ?? '';
    $onUpdate      = $onUpdate      ?? '';
    $onAfterAdd    = $onAfterAdd    ?? '';
    $onAfterRemove = $onAfterRemove ?? '';
    $filters     = $filters ?? [];
    $formSlot    = $formSlot ?? '';
    $rowSlot     = $rowSlot ?? '';   // inline row template (overrides rowView if present)

    $class = $class ?? '';

    // Sem `record-id` na tag, o pai vem do componente que está renderizando
    // (registroId → recordId → id). A view é anônima e NÃO enxerga as variáveis
    // da tela, então sem isso a FK do filho sairia nula.
    if ($mode !== 'flat' && ($recordId === null || $recordId === '')) {
        $recordId = \Mad\Form\MadDbBlocks::resolveRecordId(null);
    }

    $cfg = [
        'name'                 => $name,
        'mode'                 => $mode,
        'pivotModel'           => $pivotModel,
        'database'             => $database,
        'foreignKey'           => $foreignKey,
        'recordId'             => $recordId,
        'rowView'              => $rowView,
        'rowSlot'              => $rowSlot,     // inline row template (overrides rowView)
        'orderBy'              => $orderBy,
        'orderDir'             => $orderDir,
        'addMode'              => $addMode,
        'formPosition'         => $formPosition,
        'emptyText'            => $emptyText,
        'onAdd'                => $onAdd,
        'onRemove'             => $onRemove,
        'onUpdate'             => $onUpdate,
        'onAfterAdd'           => $onAfterAdd,
        'onAfterRemove'        => $onAfterRemove,
        'presetVars'           => $presetVars,
        'editable'             => $editable,
        'editFields'           => $editFields,
        'fileField'            => $fileField,
        'folder'               => $folder,
        'pathColumn'           => $pathColumn,
        'fileName'             => $fileName,
        'originalNameColumn'   => $originalNameColumn,
        'sizeColumn'           => $sizeColumn,
        'mimeColumn'           => $mimeColumn,
        'diskColumn'           => $diskColumn,
        'filters'              => $filters,
        'noList'               => $noList,
    ];
    $stateToken = \Mad\Form\MadDbBlocks::encodeState($cfg);

    \Mad\Form\MadFormRegistry::register($name, 'db-blocks', [
        'label' => strip_tags($label),
    ]);

    // Pre-render initial list
    $listHtml = '';
    $hasRowView = !$noList && ($rowSlot !== '' || $rowView !== '');
    if ($pivotModel && $hasRowView) {
        try {
            // loadItems é dual (QuerySource) e cuida da própria conexão
            if ($rowSlot !== '') {
                $listHtml = \Mad\Form\MadDbBlocks::renderListInline($cfg, $rowSlot);
            } else {
                $listHtml = \Mad\Form\MadDbBlocks::renderList($cfg);
            }
        } catch (\Throwable $e) {
        }
    }
@endphp

<div class="mad-db-blocks {{ $class }}"
     @if($addMode === 'inline') style="display:flex;flex-direction:column;gap:14px;" @endif
     x-data="madDbBlocks({ field: '{{ $name }}', addMode: '{{ $addMode }}' })">
    @if($label)
        <label class="mad-label">{!! $label !!}</label>
    @endif

    <div class="mad-db-blocks-list{{ $layout === 'stack' ? ' mad-db-blocks-stack' : '' }}">
        <span class="mad-db-blocks-items" id="mad-db-blocks-list-{{ $name }}" style="display:contents;">{!! $listHtml !!}</span>
        @if(!$hideAddButton)
            <button type="button" class="mad-db-blocks-add" x-ref="addBtn" @click="openAdder()">{{ $addLabel }}</button>
        @endif
    </div>

    @if($addMode === 'inline')
        {{-- Form FIXO: sem overlay, sem botão de abrir. `order` decide se
             aparece acima ou abaixo da lista sem duplicar markup. --}}
        @if($formSlot)
            <div class="mad-db-blocks-inline-form" style="order:{{ $formPosition === 'above' ? -1 : 1 }};">
                <form data-mad-submit="blockAdd" data-mad-block-form="{{ $name }}" novalidate>
                    <input type="hidden" name="__mad_db_blocks_state" value="{{ $stateToken }}">
                    {!! $formSlot !!}
                    <div style="margin-top:10px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="mad-btn mad-btn-primary mad-btn-sm">
                            <i data-lucide="{{ $submitIcon }}"></i> {{ $submitLabel }}
                        </button>
                    </div>
                </form>
            </div>
        @endif
    @elseif($addMode === 'popover')
        <div class="mad-db-blocks-popover" x-ref="popover" x-show="open" x-cloak @click.away="open = false" x-transition>
            @if($formSlot)
                <form data-mad-submit="blockAdd" novalidate>
                    <input type="hidden" name="__mad_db_blocks_state" value="{{ $stateToken }}">
                    {!! $formSlot !!}
                    <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="mad-btn mad-btn-primary mad-btn-sm">
                            <i data-lucide="check"></i> Salvar
                        </button>
                    </div>
                </form>
            @endif
        </div>
    @elseif($addMode === 'modal')
        <mad-modal name="db-blocks-{{ $name }}-modal" :title="'Adicionar'" size="md">
            @if($formSlot)
                <form data-mad-submit="blockAdd" novalidate>
                    <input type="hidden" name="__mad_db_blocks_state" value="{{ $stateToken }}">
                    {!! $formSlot !!}
                    <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="mad-btn mad-btn-primary mad-btn-sm">
                            <i data-lucide="check"></i> Salvar
                        </button>
                    </div>
                </form>
            @endif
        </mad-modal>
    @else
        <mad-drawer name="db-blocks-{{ $name }}-drawer" :title="'Adicionar'" size="md">
            @if($formSlot)
                <form data-mad-submit="blockAdd" novalidate>
                    <input type="hidden" name="__mad_db_blocks_state" value="{{ $stateToken }}">
                    {!! $formSlot !!}
                    <div style="margin-top:8px;display:flex;justify-content:flex-end;">
                        <button type="submit" class="mad-btn mad-btn-primary">
                            <i data-lucide="check"></i> Salvar
                        </button>
                    </div>
                </form>
            @endif
        </mad-drawer>
    @endif
</div>
