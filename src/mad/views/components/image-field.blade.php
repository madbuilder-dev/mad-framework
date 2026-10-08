@php
    $name             = $name ?? '';
    $label            = $label ?? '';
    $value            = $value ?? '';
    $hint             = $hint ?? '';
    $error            = $error ?? '';
    $required         = !empty($required ?? false);
    $disabled         = !empty($disabled ?? false);
    $attrs            = $attrs ?? '';
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $width            = $width ?? '240px';
    $height           = \Mad\Support\CssUnits::length((string) ($height ?? ''), '240px');
    $camera           = !empty($camera ?? false);
    $crop             = !empty($crop ?? false);
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $aspect_ratio     = $aspectRatio ?? $aspect_ratio ?? '';
    // O que foi escrito na tag (painel: Tamanho máximo / Tipos aceitos) também
    // vale no servidor; o padrão do campo continua sendo só do navegador.
    $_maxDeclared     = (string) ($maxSize ?? $max_size ?? '');
    $_acceptDeclared  = trim((string) ($accept ?? ''));
    $max_size         = $_maxDeclared !== '' ? $_maxDeclared : '5MB';
    $accept           = $_acceptDeclared !== '' ? $_acceptDeclared : 'image/png,image/jpeg,image/gif,image/webp';
    $output           = $output ?? 'base64';
    $placeholder_icon = $placeholderIcon ?? $placeholder_icon ?? 'image-plus';
    $fileNameMode     = $fileName ?? 'prefix';
    $storage          = $storage ?? '';
    $folder           = $folder ?? 'uploads';
    $nameColumn       = $nameColumn ?? '';
    \Mad\Form\MadUploadPath::assertValidFolder($folder, $storage, 'mad-image-field name="' . $name . '"');

    // Extract mad:change from attrs
    $madChangeAction = '';
    if (preg_match('/(?:mad:change|data-mad-change)\s*=\s*"([^"]+)"/', $attrs, $_m)) {
        $madChangeAction = $_m[1];
        $attrs = preg_replace('/\s*(?:mad:change|data-mad-change)\s*=\s*"[^"]*"/', '', $attrs);
    }

    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $_ctx   = \Mad\Component\MadRenderContext::current();
        $ctxVal = array_key_exists($name, $_ctx) ? (string)$_ctx[$name] : $value;
        $value  = $ctxVal;
    }
    // storage="disk": o arquivo que este campo está MOSTRANDO — o Salvar só
    // remove o que consta aqui (ver MadForm::fileShown).
    if ($storage === 'disk' && $name) {
        \Mad\Component\MadRenderContext::fileRendered($name, $value);
    }

    // Com storage: valor é path de arquivo, converter para URL acessível para preview
    // (mad_upload_is_file cobre local E disco de uploads configurado — S3 etc)
    if ($storage && $value && !str_starts_with($value, 'data:') && mad_upload_is_file($value)) {
        $value = mad_download_url($value);
    }

    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    // Tamanho máximo em bytes ("2MB", "500KB", "2,5 MB"). Texto que não é um
    // tamanho cai no padrão de 5 MB — e a dica mostra o que vale de fato.
    $_declaredBytes = \Mad\Form\MadUploadRules::bytes($_maxDeclared);
    $maxBytes       = $_declaredBytes ?: \Mad\Form\MadUploadRules::bytes($max_size) ?: 5 * 1024 * 1024;
    if ($_declaredBytes === 0 && \Mad\Form\MadUploadRules::bytes($max_size) === 0) {
        $max_size = '5MB';
    }

    // Format accept for display ("PNG, JPEG" · "Imagens" para `image/*`)
    $acceptDisplay = implode(', ', array_map(
        static fn ($t) => trim($t) === 'image/*' ? 'Imagens' : strtoupper(ltrim(str_replace('image/', '', trim($t)), '.')),
        array_filter(explode(',', $accept), static fn ($t) => trim($t) !== ''),
    ));

    \Mad\Form\MadFormRegistry::register($name, 'image', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'fileName'   => $fileNameMode,
        'storage'    => $storage,
        'folder'     => $folder,
        'nameColumn' => $nameColumn,
        // Só o que a tag declarou: o servidor confere no Salvar (MadUploadRules::check).
        'accept'     => $_acceptDeclared,
        'maxBytes'   => $_declaredBytes ?: '',
    ]);

    $_disabledJs = $disabled ? 'true' : 'false';

    $_jsonConfig = json_encode([
        'name'            => $name,
        'value'           => $value,
        'serverMax'       => $storage !== '' ? \Mad\Form\MadUploadRules::serverFileBytes() : 0,
        'maxBytes'        => $maxBytes,
        'maxSizeLabel'    => $max_size,
        'accept'          => $accept,
        'output'          => $output,
        'crop'            => $crop,
        'aspectRatio'     => $aspect_ratio,
        'camera'          => $camera,
        'madChangeAction' => $madChangeAction,
        'storage'         => $storage,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field mad-image-field{{ $hasError ? ' mad-field-error' : '' }}"
     x-data="madImageField({{ $_jsonConfig }})"
     @if($_dimStyle) style="{{ $_dimStyle }}"@endif>

    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif

    @if($storage)
        {{-- Com storage: hidden só para preview/mad:model, file input com name para $_FILES --}}
        <input type="hidden" :value="imageData" mad:model="{{ $name }}" {!! $attrs !!}>
        <input type="hidden" name="__mad_file_removed[{{ $name }}]" :value="removed ? '1' : ''">
        <input type="file" x-ref="fileInput" name="{{ $name }}" accept="{{ $accept }}" class="mad-sr-only" @change="onFileSelect($event)" @if($disabled) disabled @endif>
    @else
        {{-- Sem storage: base64 enviado direto no hidden (comportamento original) --}}
        <input type="hidden" name="{{ $name }}" :value="imageData" mad:model="{{ $name }}" {!! $attrs !!}>
        <input type="file" x-ref="fileInput" accept="{{ $accept }}" class="mad-sr-only" @change="onFileSelect($event)" @if($disabled) disabled @endif>
    @endif

    {{-- Dropzone (empty state) --}}
    <div class="mad-image-dropzone"
         x-show="!hasImage && !cameraActive"
         :class="{ 'mad-image-dropzone--dragover': dragover }"
         @click="!{{ $_disabledJs }} && $refs.fileInput.click()"
         @dragover.prevent="dragover = true"
         @dragleave="dragover = false"
         @drop.prevent="onDrop($event)"
         style="height:{{ $height }};">
        <i data-lucide="{{ $placeholder_icon }}"></i>
        <div class="mad-image-dropzone-text"><strong>Arraste uma imagem</strong><br>ou clique para selecionar</div>
        <div class="mad-image-dropzone-formats">{{ $acceptDisplay }} &bull; M&aacute;x {{ $max_size }}</div>
        @if($camera)
        <div class="mad-image-dropzone-actions">
            <button type="button" class="mad-btn mad-btn-secondary mad-btn-sm" @click.stop="openCamera()">
                <i data-lucide="camera" style="width:14px;height:14px;"></i> C&acirc;mera
            </button>
        </div>
        @endif
    </div>

    {{-- Preview state --}}
    <div class="mad-image-preview" x-show="hasImage && !cameraActive" x-cloak>
        <div class="mad-image-preview-img" x-ref="imageContainer" style="height:{{ $height }};">
            <img :src="previewSrc" alt="Preview">
        </div>
        <div class="mad-image-toolbar">
            @if($crop)
                <button type="button" class="mad-image-toolbar-btn" title="Recortar" @click="startCrop()">
                    <i data-lucide="crop"></i>
                </button>
            @endif
            <button type="button" class="mad-image-toolbar-btn" title="Girar esquerda" @click="rotateLeft()">
                <i data-lucide="rotate-ccw"></i>
            </button>
            <button type="button" class="mad-image-toolbar-btn" title="Girar direita" @click="rotateRight()">
                <i data-lucide="rotate-cw"></i>
            </button>
            <div class="mad-image-toolbar-sep"></div>
            <button type="button" class="mad-image-toolbar-btn mad-image-toolbar-btn--danger" title="Remover" @click="remove()">
                <i data-lucide="trash-2"></i>
            </button>
        </div>
    </div>

    {{-- Modal de crop --}}
    @if($crop)
    <div class="mad-modal-overlay" x-show="cropping" x-transition.opacity
         @click.self="cancelCrop()" style="z-index:1060;display:none;"
         x-effect="$el.style.display = cropping ? '' : 'none'">
        <div class="mad-modal" style="max-width:700px;" @click.stop>
            <div class="mad-modal-header">
                <div class="mad-modal-title">Recortar imagem</div>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon" @click="cancelCrop()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="mad-modal-body" style="padding:12px;background:#1a1a1a;">
                <div class="mad-image-crop-container">
                    <img x-ref="cropImage" :src="cropSrc" alt="Crop" style="max-width:100%;display:block;">
                </div>
            </div>
            <div class="mad-modal-footer">
                <button type="button" class="mad-image-toolbar-btn" title="Girar esquerda" @click="if(_cropper)_cropper.rotate(-90)">
                    <i data-lucide="rotate-ccw"></i>
                </button>
                <button type="button" class="mad-image-toolbar-btn" title="Girar direita" @click="if(_cropper)_cropper.rotate(90)">
                    <i data-lucide="rotate-cw"></i>
                </button>
                <button type="button" class="mad-image-toolbar-btn" title="Zoom +" @click="zoomIn()">
                    <i data-lucide="zoom-in"></i>
                </button>
                <button type="button" class="mad-image-toolbar-btn" title="Zoom -" @click="zoomOut()">
                    <i data-lucide="zoom-out"></i>
                </button>
                <div style="flex:1;"></div>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm" @click="cancelCrop()">Cancelar</button>
                <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="confirmCrop()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><polyline points="20 6 9 17 4 12"/></svg>
                    Aplicar
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Camera state --}}
    @if($camera)
    <div class="mad-image-camera" x-show="cameraActive" x-cloak>
        <video x-ref="video" autoplay playsinline style="height:{{ $height }};"></video>
        <canvas x-ref="canvas" class="mad-sr-only"></canvas>
        <div class="mad-image-camera-controls">
            <button type="button" class="mad-image-switch-btn" @click="switchCamera()">
                <i data-lucide="refresh-cw"></i>
            </button>
            <button type="button" class="mad-image-capture-btn" @click="capture()"></button>
            <div style="width:34px;"></div>
        </div>
    </div>
    @endif

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
