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
    $width            = $width ?? '100%';
    $height           = \Mad\Support\CssUnits::length((string) ($height ?? ''), '200px');
    $modes            = $modes ?? 'draw,type,upload';
    $penColor         = $penColor ?? '#000000';
    $penColors        = $penColors ?? ['#000000', '#1e40af', '#dc2626'];
    $penWidth         = $penWidth ?? 2;
    $penWidths        = $penWidths ?? [1, 2, 4];
    $fonts            = $fonts ?? ['Dancing Script', 'Great Vibes', 'Courier Prime'];
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    // O que foi escrito na tag (painel: Tamanho máximo / Tipos aceitos) também
    // vale no servidor; o padrão do campo continua sendo só do navegador.
    $_maxDeclared     = (string) ($maxSize ?? $max_size ?? '');
    $_acceptDeclared  = trim((string) ($accept ?? ''));
    $max_size         = $_maxDeclared !== '' ? $_maxDeclared : '5MB';
    $accept           = $_acceptDeclared !== '' ? $_acceptDeclared : 'image/png,image/jpeg';
    $camera           = !empty($camera ?? false);
    $fileNameMode     = $fileName ?? 'prefix';
    $storage          = $storage ?? '';
    $folder           = $folder ?? 'uploads';
    $nameColumn       = $nameColumn ?? '';
    \Mad\Form\MadUploadPath::assertValidFolder($folder, $storage, 'mad-signature-field name="' . $name . '"');

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

    // With storage: value is a file path, convert to accessible URL for preview
    // (mad_upload_is_file covers local AND the configured upload disk — S3 etc)
    if ($storage && $value && !str_starts_with($value, 'data:') && mad_upload_is_file($value)) {
        $value = mad_download_url($value, null, \Mad\Component\MadRenderContext::getForm()?->fileOwnerFor((string) $name, $value));
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

    $modesArray = array_map('trim', explode(',', $modes));

    // Register as 'image' type so MadForm::save() handles storage automatically
    // `public` (#244): grava em _public/ com endereço fixo e sem login
    // (mad_public_url). Sem ela, o arquivo segue privado.
    $public = !empty($public ?? false) && $public !== 'false';

    \Mad\Form\MadFormRegistry::register($name, 'image', [
        'public'     => $public,
        'label'      => strip_tags($label),
        'required'   => $required,
        'fileName'   => $fileNameMode,
        'storage'    => $storage,
        'folder'     => $folder,
        'nameColumn' => $nameColumn,
        // Só o que a tag declarou: o servidor confere no Salvar (MadUploadRules::check).
        // A assinatura desenhada ou digitada sai num tipo que o campo aceita (mad-ui.js).
        'accept'     => $_acceptDeclared,
        'maxBytes'   => $_declaredBytes ?: '',
        // As regras que o NAVEGADOR aplica — as da tag ou, sem elas, o padrão
        // do campo. Servem só para conferir o arquivo que ele recusou e tirou
        // do envio (`__mad_file_refused`, fw#297): o arquivo que chega continua
        // conferido só pelo que a tag declarou.
        'browserAccept'   => $accept,
        'browserMaxBytes' => $maxBytes,
    ]);

    $_disabledJs = $disabled ? 'true' : 'false';

    $_jsonConfig = json_encode([
        'name'            => $name,
        'value'           => $value,
        'modes'           => $modesArray,
        'penColor'        => $penColor,
        'penColors'       => $penColors,
        'penWidth'        => (int)$penWidth,
        'penWidths'       => array_map('intval', $penWidths),
        'fonts'           => $fonts,
        'maxBytes'        => $maxBytes,
        'maxSizeLabel'    => $max_size,
        'accept'          => $accept,
        'camera'          => $camera,
        'madChangeAction' => $madChangeAction,
        'storage'         => $storage,
        'height'          => $height,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field{{ $hasError ? ' mad-field-error' : '' }}"
     x-data="madSignatureField({{ $_jsonConfig }})"
     @if($_dimStyle) style="{{ $_dimStyle }}"@endif>

    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif

    @if($storage)
        {{-- Com storage: hidden só para preview/mad:model, file input com name para $_FILES --}}
        <input type="hidden" :value="signatureData" mad:model="{{ $name }}" {!! $attrs !!}>
        <input type="hidden" name="__mad_file_removed[{{ $name }}]" :value="removed ? '1' : ''">
        <input type="file" x-ref="fileInput" name="{{ $name }}" accept="{{ $accept }}" class="mad-sr-only" @change="onUploadSelect($event)" @if($disabled) disabled @endif>
    @else
        {{-- Sem storage: base64 enviado direto no hidden (comportamento original) --}}
        <input type="hidden" name="{{ $name }}" :value="signatureData" mad:model="{{ $name }}" {!! $attrs !!}>
        <input type="file" x-ref="fileInput" accept="{{ $accept }}" class="mad-sr-only" @change="onUploadSelect($event)" @if($disabled) disabled @endif>
    @endif

    <div class="mad-signature{{ $disabled ? ' mad-signature--disabled' : '' }}">

        {{-- Mode pills (only if more than 1 mode) --}}
        @if(count($modesArray) > 1)
        <div class="mad-signature-modes">
            @if(in_array('draw', $modesArray))
            <button type="button" class="mad-signature-pill"
                    :class="{ 'active': mode === 'draw' }"
                    @click="switchMode('draw')">
                <i data-lucide="pen-line"></i> {{ mad_t('mad.ui.sig_draw') }}
            </button>
            @endif
            @if(in_array('type', $modesArray))
            <button type="button" class="mad-signature-pill"
                    :class="{ 'active': mode === 'type' }"
                    @click="switchMode('type')">
                <i data-lucide="type"></i> {{ mad_t('mad.ui.sig_type') }}
            </button>
            @endif
            @if(in_array('upload', $modesArray))
            <button type="button" class="mad-signature-pill"
                    :class="{ 'active': mode === 'upload' }"
                    @click="switchMode('upload')">
                <i data-lucide="upload"></i> {{ mad_t('mad.ui.sig_upload') }}
            </button>
            @endif
        </div>
        @endif

        {{-- Preview state --}}
        <div x-show="hasSignature && !editing" x-cloak>
            <div class="mad-signature-preview">
                <div class="mad-signature-preview-img" style="height:{{ $height }};">
                    <img :src="signatureData" alt="{{ mad_t('mad.ui.signature') }}">
                </div>
                <div class="mad-signature-preview-actions">
                    <button type="button" class="mad-signature-toolbar-btn" @click="edit()" title="{{ mad_t('mad.ui.edit') }}">
                        <i data-lucide="pencil"></i> {{ mad_t('mad.ui.edit') }}
                    </button>
                    <div class="mad-signature-sep"></div>
                    <button type="button" class="mad-signature-toolbar-btn mad-signature-toolbar-btn--danger" @click="clear()" title="{{ mad_t('mad.ui.clear') }}">
                        <i data-lucide="trash-2"></i> {{ mad_t('mad.ui.clear') }}
                    </button>
                </div>
            </div>
        </div>

        {{-- Draw mode --}}
        @if(in_array('draw', $modesArray))
        <div x-show="mode === 'draw' && (!hasSignature || editing)" x-cloak>
            <div class="mad-signature-canvas-wrap" style="height:{{ $height }};" x-ref="canvasWrap">
                <canvas x-ref="canvas"></canvas>
                <button type="button" class="mad-signature-expand-btn" @click="enterFullscreen()" title="{{ mad_t('mad.ui.fullscreen') }}">
                    <i data-lucide="maximize-2"></i>
                </button>
            </div>
            <div class="mad-signature-toolbar">
                <div class="mad-signature-colors">
                    @foreach($penColors as $c)
                    <button type="button" class="mad-signature-color"
                            :class="{ 'active': penColor === '{{ $c }}' }"
                            style="background:{{ $c }};"
                            @click="setPenColor('{{ $c }}')"
                            title="{{ $c }}"></button>
                    @endforeach
                </div>
                <div class="mad-signature-sep"></div>
                <div class="mad-signature-widths">
                    @foreach($penWidths as $w)
                    <button type="button" class="mad-signature-width"
                            :class="{ 'active': penWidth === {{ $w }} }"
                            @click="setPenWidth({{ $w }})"
                            title="{{ mad_t('mad.ui.sig_thickness', ['w' => $w]) }}">
                        <span class="mad-signature-width-dot" style="width:{{ $w + 2 }}px;height:{{ $w + 2 }}px;"></span>
                    </button>
                    @endforeach
                </div>
                <div class="mad-signature-sep"></div>
                <button type="button" class="mad-signature-toolbar-btn" @click="undo()" title="{{ mad_t('mad.ui.undo') }}">
                    <i data-lucide="undo-2"></i> {{ mad_t('mad.ui.undo') }}
                </button>
                <button type="button" class="mad-signature-toolbar-btn mad-signature-toolbar-btn--danger" @click="clearPad()" title="{{ mad_t('mad.ui.clear') }}">
                    <i data-lucide="eraser"></i> {{ mad_t('mad.ui.clear') }}
                </button>
                <div style="flex:1;"></div>
                <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="confirmDraw()">
                    <i data-lucide="check"></i> {{ mad_t('mad.ui.confirm') }}
                </button>
            </div>
        </div>
        @endif

        {{-- Type mode --}}
        @if(in_array('type', $modesArray))
        <div x-show="mode === 'type' && (!hasSignature || editing)" x-cloak>
            <div class="mad-signature-type">
                <input type="text" class="mad-signature-type-input"
                       placeholder="{{ mad_t('mad.ui.type_your_name') }}"
                       x-model="typedText"
                       @input="onTextInput()">

                <div class="mad-signature-fonts">
                    @foreach($fonts as $f)
                    <div class="mad-signature-font-card"
                         :class="{ 'active': selectedFont === '{{ $f }}' }"
                         @click="selectFont('{{ $f }}')">
                        <div class="mad-signature-font-card-preview"
                             style="font-family:'{{ $f }}',cursive;"
                             x-text="typedText || 'Assinatura'"></div>
                        <div class="mad-signature-font-card-name">{{ $f }}</div>
                    </div>
                    @endforeach
                </div>

                <div class="mad-signature-type-colors">
                    @foreach($penColors as $c)
                    <button type="button" class="mad-signature-color"
                            :class="{ 'active': penColor === '{{ $c }}' }"
                            style="background:{{ $c }};"
                            @click="setPenColor('{{ $c }}')"
                            title="{{ $c }}"></button>
                    @endforeach
                </div>

                <div style="margin-top:12px;text-align:right;">
                    <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="confirmType()"
                            :disabled="!typedText.trim()">
                        <i data-lucide="check"></i> {{ mad_t('mad.ui.confirm') }}
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- Upload mode --}}
        @if(in_array('upload', $modesArray))
        <div x-show="mode === 'upload' && (!hasSignature || editing)" x-cloak>
            <div class="mad-signature-upload"
                 :class="{ 'mad-signature-upload--dragover': dragover }"
                 @click="$refs.fileInput.click()"
                 @dragover.prevent="dragover = true"
                 @dragleave="dragover = false"
                 @drop.prevent="onUploadDrop($event)"
                 style="height:{{ $height }};">
                <i data-lucide="upload"></i>
                <div class="mad-signature-upload-text">{{ mad_t('mad.ui.drag_image_or_click') }}</div>
                <div class="mad-signature-upload-hint">{{ strtoupper(str_replace(['image/', ','], ['', ', '], $accept)) }} &bull; {!! htmlentities(mad_t('mad.ui.max_size'), ENT_QUOTES, 'UTF-8') !!} {{ $max_size }}</div>
            </div>
        </div>
        @endif

        {{-- Empty placeholder (when no mode is active yet) --}}
        <div x-show="!hasSignature && !editing && !mode" x-cloak
             class="mad-signature-empty" @click="startEditing()">
            <i data-lucide="pen-line"></i>
            <div class="mad-signature-empty-text">{{ mad_t('mad.ui.click_to_sign') }}</div>
        </div>

    </div>

    {{-- Fullscreen overlay --}}
    <template x-teleport="body">
        <div class="mad-signature-fullscreen" x-show="isFullscreen" x-cloak x-transition.opacity>
            <div class="mad-signature-fullscreen-header">
                <div class="mad-signature-fullscreen-header-title">{{ mad_t('mad.ui.signature') }}</div>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon" @click="exitFullscreen()">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <canvas x-ref="fullscreenCanvas"></canvas>
            <div class="mad-signature-fullscreen-footer">
                <div class="mad-signature-colors">
                    @foreach($penColors as $c)
                    <button type="button" class="mad-signature-color"
                            :class="{ 'active': penColor === '{{ $c }}' }"
                            style="background:{{ $c }};"
                            @click="setPenColor('{{ $c }}')"
                            title="{{ $c }}"></button>
                    @endforeach
                </div>
                <div class="mad-signature-sep"></div>
                <div class="mad-signature-widths">
                    @foreach($penWidths as $w)
                    <button type="button" class="mad-signature-width"
                            :class="{ 'active': penWidth === {{ $w }} }"
                            @click="setPenWidth({{ $w }})"
                            title="{{ mad_t('mad.ui.sig_thickness', ['w' => $w]) }}">
                        <span class="mad-signature-width-dot" style="width:{{ $w + 2 }}px;height:{{ $w + 2 }}px;"></span>
                    </button>
                    @endforeach
                </div>
                <div class="mad-signature-sep"></div>
                <button type="button" class="mad-signature-toolbar-btn" @click="undo()" title="{{ mad_t('mad.ui.undo') }}">
                    <i data-lucide="undo-2"></i>
                </button>
                <button type="button" class="mad-signature-toolbar-btn mad-signature-toolbar-btn--danger" @click="clearPad()" title="{{ mad_t('mad.ui.clear') }}">
                    <i data-lucide="eraser"></i>
                </button>
                <div style="flex:1;"></div>
                <button type="button" class="mad-btn mad-btn-primary mad-btn-sm" @click="confirmFullscreen()">
                    <i data-lucide="check"></i> {{ mad_t('mad.ui.confirm') }}
                </button>
            </div>
        </div>
    </template>

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
