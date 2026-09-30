@php
    $width       = $width ?? '';
    $maxWidth    = $maxWidth ?? '';
    $name        = $name ?? '';
    $label       = $label ?? '';
    $value       = $value ?? '';
    $text        = $text ?? '';
    $display     = $display ?? '';
    $gridHtml    = $gridHtml ?? '';
    $required    = !empty($required ?? false);
    $disabled    = !empty($disabled ?? false);
    $placeholder = $placeholder ?? '';
    $hint        = $hint ?? '';
    $modalTitle  = $modalTitle ?? ($label ?: 'Buscar');
    $modalSize   = $modalSize ?? 'xl';
    $auxiliaries = $auxiliaries ?? [];
    // "Novo" (`create` do <mad-seek>): {class, method, url, label, icon, token}
    // montado pelo MadNoResultsHelper::createAction — null = sem botão.
    $create      = is_array($create ?? null) ? $create : null;
    $id          = $name;
    $reqStar     = $required ? ' <span class="mad-required">*</span>' : '';

    $sizes = ['sm' => '380px', 'md' => '520px', 'lg' => '680px', 'xl' => '900px'];
    $maxW  = \Mad\Support\CssUnits::length((string) ($sizes[$modalSize] ?? $modalSize), $sizes['md']);

    $auxJson   = htmlspecialchars(json_encode($auxiliaries, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
    $createJson = htmlspecialchars(json_encode($create ? array_intersect_key($create, array_flip(['class', 'method', 'url', 'token'])) : null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES);
    $createLabel = $create ? (string) ($create['label'] ?? 'Novo') : '';
    $createIcon  = $create ? ((string) ($create['icon'] ?? '') ?: 'plus') : '';
    $safeValue = htmlspecialchars(addslashes((string)$value), ENT_QUOTES);
    $safeText  = htmlspecialchars(addslashes((string)$text), ENT_QUOTES);

    // Em branco grava NULL (a chave de outra tabela nunca é ''): coluna
    // inteira recusava o '' e o salvar quebrava. empty-as="empty" = antigo.
    $emptyAs = strtolower(trim((string) ($emptyAs ?? 'null'))) ?: 'null';
    \Mad\Form\MadFormRegistry::register($name, 'seek', [
        'label'    => strip_tags($label),
        'required' => $required,
        'emptyAs'  => $emptyAs,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field mad-dbseek-field{{ $create ? ' mad-dbseek-has-create' : '' }}"
     x-data="madDbSeek({ name: '{{ $name }}', value: '{{ $safeValue }}', text: '{{ $safeText }}', auxiliaries: {!! $auxJson !!}, create: {!! $createJson !!} })" @if($_dimStyle) style="{{ $_dimStyle }}" @endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}_display">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-input-group">
        <input
            type="text"
            id="{{ $id }}_display"
            class="mad-input mad-dbseek-display"
            placeholder="{{ $placeholder }}"
            x-model="displayText"
            readonly
            @if($disabled) disabled @endif
        >
        <input
            type="hidden"
            id="{{ $id }}"
            name="{{ $name }}"
            x-model="selectedId"
            mad:model="{{ $name }}"
        >
        <button
            type="button"
            class="mad-seek-btn"
            :style="selectedId ? 'border-radius:0;border-right:none;' : ''"
            @click="openModal()"
            @if($disabled) disabled @endif
            aria-label="Buscar"
        >
            <i data-lucide="search"></i>
        </button>
        <button
            type="button"
            class="mad-seek-clear-btn"
            x-show="selectedId"
            x-cloak
            @click="clear()"
            @if($disabled) disabled @endif
            aria-label="Limpar"
        >
            <i data-lucide="x"></i>
        </button>
        @if($create)
        {{-- "Novo": cadastra e volta selecionado (returnToCombo do form alvo). --}}
        <button
            type="button"
            class="mad-seek-btn mad-seek-create-btn"
            @click="openCreate()"
            @if($disabled) disabled @endif
            aria-label="{{ $createLabel }}"
            title="{{ $createLabel }}"
        >
            <i data-lucide="{{ $createIcon }}"></i>
        </button>
        @endif
    </div>
    <p class="mad-field-hint" data-field-error="{{ $name }}">{!! $hint !!}</p>

    <!-- Modal com grid de busca -->
    <div class="mad-modal-overlay"
         x-show="modalOpen"
         x-transition
         @click.self="close()"
         style="display:none;z-index:1060;">
        <div class="mad-modal" style="max-width:{{ $maxW }};">
            <div class="mad-modal-header">
                <div class="mad-modal-title">{{ $modalTitle }}</div>
                @if($create)
                <button type="button"
                        class="mad-btn mad-btn-secondary mad-btn-sm mad-dbseek-modal-create"
                        @click="openCreate()">
                    <i data-lucide="{{ $createIcon }}" style="width:14px;height:14px;"></i> {{ $createLabel }}
                </button>
                @endif
                <button type="button"
                        class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon"
                        @click="close()">
                    <i data-lucide="x" style="width:16px;height:16px;"></i>
                </button>
            </div>
            <div class="mad-modal-body" style="max-height:70vh;overflow-y:auto;">
                {!! $gridHtml !!}
            </div>
        </div>
    </div>
</div>
