@php
    $name       = $name       ?? '';
    $label      = $label      ?? '';
    $description = $description ?? '';
    $value      = $value      ?? '';
    $hint       = $hint       ?? '';
    $error      = $error      ?? '';
    $required   = !empty($required ?? false);
    $disabled   = !empty($disabled ?? false);
    $attrs      = $attrs      ?? '';
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $size       = \Mad\Support\CssUnits::length((string) ($size ?? ''), '96px');
    $accept     = $accept     ?? 'image/png,image/jpeg';
    $maxSize    = $maxSize    ?? '5MB';
    $btnText    = $btnText    ?? 'Trocar foto';
    $btnRemove  = $btnRemove  ?? 'Remover';
    $removable  = !empty($removable ?? true);
    $storage    = $storage    ?? '';
    $folder     = $folder     ?? 'uploads';
    $nameColumn = $nameColumn ?? '';
    \Mad\Form\MadUploadPath::assertValidFolder($folder, $storage, 'mad-avatar-field name="' . $name . '"');
    $fileNameMode = $fileName ?? 'prefix';

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

    // Com storage: valor é path de arquivo, converter para URL acessível
    $previewUrl = '';
    if ($value) {
        if (str_starts_with($value, 'data:') || str_starts_with($value, 'http')) {
            $previewUrl = $value;
        } elseif (mad_upload_is_file($value)) {
            $previewUrl = mad_download_url($value);
        } elseif ($value) {
            $previewUrl = $value;
        }
    }

    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';

    // Parse max-size to bytes
    $maxBytes = 5 * 1024 * 1024;
    if (preg_match('/^(\d+)\s*(MB|KB|GB)$/i', $maxSize, $_sm)) {
        $_n = (int)$_sm[1];
        $_u = strtoupper($_sm[2]);
        if ($_u === 'KB')      $maxBytes = $_n * 1024;
        elseif ($_u === 'MB')  $maxBytes = $_n * 1024 * 1024;
        elseif ($_u === 'GB')  $maxBytes = $_n * 1024 * 1024 * 1024;
    }

    // Hint de formatos (ex.: "PNG ou JPEG")
    $acceptParts = array_map(function($t) {
        return strtoupper(str_replace(['image/', 'application/', '+xml'], '', trim($t)));
    }, explode(',', $accept));
    $acceptHint = count($acceptParts) > 1
        ? implode(', ', array_slice($acceptParts, 0, -1)) . ' ou ' . end($acceptParts)
        : ($acceptParts[0] ?? '');

    \Mad\Form\MadFormRegistry::register($name, 'image', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'fileName'   => $fileNameMode,
        'storage'    => $storage,
        'folder'     => $folder,
        'nameColumn' => $nameColumn,
    ]);

    $_jsonConfig = json_encode([
        'name'            => $name,
        'preview'         => $previewUrl,
        'maxBytes'        => $maxBytes,
        'maxSizeLabel'    => $maxSize,
        'accept'          => $accept,
        'madChangeAction' => $madChangeAction,
        'removable'       => $removable,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field mad-avatar-field{{ $hasError ? ' mad-field-error' : '' }}"
     x-data="madAvatarField({{ $_jsonConfig }})"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>

    {{-- Header: label + description --}}
    @if($label || $description)
        <div class="mad-avatar-field-header">
            @if($label)
                <div class="mad-label" style="margin-bottom:0;">{!! $label !!}{!! $reqStar !!}</div>
            @endif
            @if($description)
                <div class="mad-avatar-field-desc">{{ $description }}</div>
            @endif
        </div>
    @endif

    {{-- Avatar-primeiro: o círculo É o controle. Hover mostra overlay de
         câmera ("trocar"); o X de remover é uma bolinha no canto. Nada de
         card, botões nem lista de dicas — uma linha muted resume formatos. --}}
    <div class="mad-avatar-field-wrap" style="width:{{ $size }};"@if($disabled) data-disabled @endif>
        <label for="{{ $id }}_input" class="mad-avatar-field-circle"
               :class="!preview && 'is-empty'"
               style="width:{{ $size }};height:{{ $size }};@if($disabled)pointer-events:none;opacity:.5;@endif"
               title="{{ strip_tags($btnText) }}">
            <template x-if="preview">
                <img :src="preview" alt="Avatar">
            </template>
            <template x-if="!preview">
                <div class="mad-avatar-field-placeholder">
                    <i data-lucide="user"></i>
                </div>
            </template>
            <span class="mad-avatar-field-overlay" aria-hidden="true">
                <i data-lucide="camera"></i>
            </span>
        </label>
        <template x-if="preview && {{ $removable ? 'true' : 'false' }}">
            <button type="button" class="mad-avatar-field-remove"
                @click.prevent.stop="remove()" aria-label="{{ strip_tags($btnRemove) }}"
                title="{{ strip_tags($btnRemove) }}">
                <i data-lucide="x"></i>
            </button>
        </template>
    </div>
    <div class="mad-avatar-field-hint">{{ $acceptHint }}@if($acceptHint) · @endif máx. {{ $maxSize }}</div>

    {{-- Hidden file input --}}
    <input type="file" id="{{ $id }}_input" name="{{ $name }}" accept="{{ $accept }}"
        style="display:none;" @change="onSelect($event)"
        @if($disabled) disabled @endif>

    {{-- Hidden para tracking de remoção --}}
    <input type="hidden" name="__mad_file_removed[{{ $name }}]" :value="removed ? '1' : ''">

    {{-- Slot de erro SEMPRE presente (MadResponse::fieldError() escreve nele) --}}
    <div class="mad-hint mad-hint-error{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{{ $hasError ? $error : '' }}</div>
</div>
