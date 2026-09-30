@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'accept' => '', 'multiple' => false, 'maxSize' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'storage' => '', 'folder' => 'uploads', 'name-column' => '', 'file-name' => 'prefix', 'auto-fill-name' => ''])
@php
    $multiple   = !empty($multiple);
    $required   = !empty($required);
    $disabled   = !empty($disabled);
    $storage    = $storage ?? '';
    $folder     = $folder ?? 'uploads';
    $nameColumn = $nameColumn ?? '';
    // Valida o diretório declarado pelo dev — diretório indevido (absoluto/.. /null)
    // lança e o MadComponent mostra erro rico em tela.
    \Mad\Form\MadUploadPath::assertValidFolder($folder, $storage, 'mad-file-field name="' . $name . '"');
    $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError   = !empty($error);
    $reqStar    = $required ? ' <span class="mad-required">*</span>' : '';
    $nameAttr   = $multiple ? $name . '[]' : $name;

    // Extract mad:change from attrs (same pattern as scanner-field)
    $madChangeAction = '';
    if (preg_match('/(?:mad:change|data-mad-change)\s*=\s*"([^"]+)"/', $attrs, $_m)) {
        $madChangeAction = $_m[1];
        $attrs = preg_replace('/\s*(?:mad:change|data-mad-change)\s*=\s*"[^"]*"/', '', $attrs);
    }

    \Mad\Form\MadFormRegistry::register($name, 'file', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'multiple'   => $multiple,
        'accept'     => $accept,
        'storage'    => $storage,
        'folder'     => $folder,
        'nameColumn' => $nameColumn,
        'fileName'   => $fileName ?? 'prefix',
    ]);

    $acceptHint = '';
    if ($accept) $acceptHint .= $accept;
    if ($maxSize) $acceptHint .= ($acceptHint ? ' · ' : '') . 'Máx. ' . $maxSize;

    // Valor existente (edição) — path do arquivo no servidor ou base64 (storage=db)
    $_ctx = \Mad\Component\MadRenderContext::current();
    $existingPath = '';
    $existingUrl  = '';
    $existingName = '';
    if ($name && array_key_exists($name, $_ctx) && !empty($_ctx[$name])) {
        $existingPath = (string) $_ctx[$name];
        $existingName = basename($existingPath);
        if (preg_match('/^[a-f0-9]{13}_(.+)$/i', $existingName, $m)) {
            $existingName = $m[1];
        }
    }
    if ($nameColumn && array_key_exists($nameColumn, $_ctx) && !empty($_ctx[$nameColumn])) {
        $existingName = (string) $_ctx[$nameColumn];
    }

    // Auto-extração: se storage="db" e o valor não é um file path, é base64 bruto do banco
    if ($storage === 'db' && $existingPath && strlen($existingPath) > 100 && !is_file($existingPath)) {
        $binary = base64_decode($existingPath, true);
        if ($binary !== false && strlen($binary) > 0) {
            $fileName = $existingName ?: 'arquivo';
            $folder = 'tmp/blob_cache/' . md5($name . '_' . strlen($existingPath));
            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            $tmpPath = $folder . '/' . $fileName;
            file_put_contents($tmpPath, $binary);
            $existingPath = $tmpPath;

            // Injeta o path direto no MadForm (mesma técnica de MadFieldListTrait::_injectIntoForm)
            // Isso garante que o estado serializado use o path em vez do base64 gigante
            \Mad\Component\MadRenderContext::injectIntoForm($name, $tmpPath);
        }
    }

    if ($existingPath) {
        $existingUrl = mad_download_url($existingPath, $existingName ?: null);
    }
    $fileCfg = json_encode([
        'existingName'    => $existingName,
        'existingUrl'     => $existingUrl,
        'fieldName'       => $name,
        'madChangeAction' => $madChangeAction,
        'autoFillName'    => $autoFillName ?? '',
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field" x-data="madFileField({{ $fileCfg }})"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-file-wrap"
        :class="{ 'mad-file-over': dragOver, 'mad-file-error': {{ $hasError ? 'true' : 'false' }} }"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop($event, $refs.fileInput)">
        <input
            type="file"
            id="{{ $id }}"
            name="{{ $nameAttr }}"
            class="mad-file-input"
            x-ref="fileInput"
            @if($accept)   accept="{{ $accept }}" @endif
            @if($multiple) multiple @endif
            @if($disabled) disabled @endif
            @change="onSelect($event)"
            {!! $attrs !!}
        >
        <input type="hidden" name="__mad_file_removed[{{ $name }}]" :value="removed ? '1' : ''">
        {{-- Dropzone (sem arquivo) --}}
        <div class="mad-file-body" x-show="!file" @click="$refs.fileInput.click()" style="cursor:pointer;">
            <i data-lucide="upload-cloud" style="width:24px;height:24px;color:var(--mad-text-subtle);"></i>
            <span class="mad-file-text">
                <strong>Clique para enviar</strong> ou arraste aqui
            </span>
            @if($acceptHint)
                <span class="mad-file-hint">{{ $acceptHint }}</span>
            @endif
        </div>
        {{-- Preview card (com arquivo — novo ou existente) --}}
        <div class="mad-file-card" x-show="file" x-cloak>
            {{-- Thumbnail area --}}
            <div class="mad-file-card-thumb" @click="openPreview()">
                <img x-show="file && file.isImage && file.thumb" :src="file?.thumb" class="mad-file-card-img" alt="">
                <div x-show="file && !file.isImage" class="mad-file-card-icon" :style="'background:' + (file ? file.color : '')">
                    <i :data-lucide="file ? file.icon : 'file'" x-init="$watch('file', () => $nextTick(() => $lucide($el)))"></i>
                    <span x-text="file?.ext"></span>
                </div>
            </div>
            {{-- Info --}}
            <div class="mad-file-card-body">
                <span class="mad-file-card-name" x-text="file?.name" :title="file?.name"></span>
                <span class="mad-file-card-size" x-text="file?.sizeText"></span>
            </div>
            {{-- Toolbar --}}
            <div class="mad-file-card-toolbar">
                <button type="button" @click="openPreview()" title="Visualizar">
                    <i data-lucide="eye"></i> <span>Visualizar</span>
                </button>
                <a x-show="file && file.existing && file.existingUrl"
                    :href="file?.existingUrl" target="_blank" download title="Baixar" x-cloak>
                    <i data-lucide="download"></i> <span>Baixar</span>
                </a>
                <button type="button" @click="$refs.fileInput.click()" title="Trocar"
                    @if($disabled) disabled @endif>
                    <i data-lucide="refresh-cw"></i> <span>Trocar</span>
                </button>
                <button type="button" class="mad-file-card-toolbar-danger"
                    @click="removeFile($refs.fileInput)" title="Remover"
                    @if($disabled) disabled @endif>
                    <i data-lucide="trash-2"></i> <span>Remover</span>
                </button>
            </div>
        </div>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
{{-- Modal de preview --}}
<div x-data="madFilePreview('{{ $name }}')">
    <div class="mad-modal-overlay" x-show="open" x-transition.opacity
        @click.self="close()" style="z-index:1060;display:none;"
        x-effect="$el.style.display = open ? '' : 'none'">
        <div class="mad-modal" style="max-width:900px;" @click.stop>
            <div class="mad-modal-header">
                <div class="mad-modal-title" x-text="fname"></div>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon"
                    @click.stop="close()" style="pointer-events:auto;position:relative;z-index:2;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="mad-modal-body" style="text-align:center;padding:12px;">
                <img x-show="ftype === 'image'" :src="src" style="max-width:100%;max-height:70vh;border-radius:8px;" alt="">
                <iframe x-show="ftype === 'pdf'" :src="src" style="width:100%;height:70vh;border:none;border-radius:8px;"></iframe>
                <div x-show="ftype === 'other'" style="padding:60px 20px;color:var(--mad-text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                    <p>Preview nao disponivel para este tipo de arquivo.</p>
                    <a x-show="downloadUrl" :href="downloadUrl" target="_blank" download
                        class="mad-btn mad-btn-primary mad-btn-sm" style="margin-top:12px;">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:6px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/></svg>
                        Baixar arquivo
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
