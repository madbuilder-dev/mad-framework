@php
    $name        = $name ?? '';
    $label       = $label ?? '';
    $value       = $value ?? '';
    $type        = $type ?? 'both';
    $placeholder = $placeholder ?? 'Escaneie ou digite...';
    $hint        = $hint ?? '';
    $error       = $error ?? '';
    $required    = !empty($required ?? false);
    $disabled    = !empty($disabled ?? false);
    $continuous  = !empty($continuous ?? false);
    $beep        = $beep ?? true;
    $beep        = !empty($beep);
    $attrs       = $attrs ?? '';
        $id = 'mad_' . $name . '_' . mt_rand(1000, 9999);

    if ($name && strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
        $_ctx   = \Mad\Component\MadRenderContext::current();
        $ctxVal = array_key_exists($name, $_ctx) ? (string)$_ctx[$name] : $value;
        $value  = $ctxVal;
    }

    $hasError  = !empty($error);
    $reqStar   = $required ? ' <span class="mad-required">*</span>' : '';

    // Extract mad:change or mad:scan from attrs
    $madAction = '';
    if (preg_match('/(?:mad:change|mad:scan)\s*=\s*"([^"]+)"/', $attrs, $_m)) {
        $madAction = $_m[1];
        $attrs = preg_replace('/\s*(?:mad:change|mad:scan)\s*=\s*"[^"]*"/', '', $attrs);
    }

    \Mad\Form\MadFormRegistry::register($name, 'scanner', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);

    $_jsonConfig = json_encode([
        'name'       => $name,
        'value'      => $value,
        'type'       => $type,
        'continuous' => $continuous,
        'beep'       => $beep,
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);
@endphp
<div class="mad-field mad-scanner-field{{ $hasError ? ' mad-field-error' : '' }}"
     x-data="madScanner({{ $_jsonConfig }})"
     :class="{ 'mad-scanner-field--success': detected }"
     @if($madAction) data-mad-scan-action="{{ $madAction }}" @endif
     x-on:keydown.escape="close()"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>

    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif

    <div class="mad-scanner-input-group">
        <input
            id="{{ $id }}"
            name="{{ $name }}"
            type="text"
            class="mad-input{{ $hasError ? ' mad-input-error' : '' }}"
            placeholder="{{ $placeholder }}"
            :value="value"
            @input="value = $event.target.value; detected = false;"
            @if($required) required @endif
            @if($disabled) disabled @endif
            mad:model="{{ $name }}"
            {!! $attrs !!}
        >
        <button type="button" class="mad-scanner-btn" @click="toggle()" @if($disabled) disabled @endif>
            <i data-lucide="scan"></i>
        </button>
    </div>

    {{-- Camera viewfinder (hidden by default) --}}
    <div class="mad-scanner-viewfinder" x-show="scanning" x-transition x-cloak>
        <div class="mad-scanner-camera-header">
            <span>Scanner ativo</span>
            <button type="button" class="mad-scanner-close-btn" @click="close()">&#10005;</button>
        </div>
        <div class="mad-scanner-camera" x-ref="camera"></div>
        <div class="mad-scanner-camera-footer">
            <span x-text="scanning ? 'Aponte para o código' : ''"></span>
            @if($type === 'both')
            <div class="mad-scanner-type-toggle">
                <button type="button" :class="{ 'active': _activeFilter === 'all' }" @click="setFilter('all')">Todos</button>
                <button type="button" :class="{ 'active': _activeFilter === 'qr' }" @click="setFilter('qr')">QR</button>
                <button type="button" :class="{ 'active': _activeFilter === 'barcode' }" @click="setFilter('barcode')">Barcode</button>
            </div>
            @endif
        </div>

        @if($continuous)
        <div class="mad-scanner-scan-list" x-show="scanList.length > 0">
            <template x-for="(item, idx) in scanList" :key="idx">
                <div class="mad-scanner-scan-list-item">
                    <span x-text="item.code"></span>
                    <span class="mad-scanner-scan-list-type" x-text="item.type"></span>
                </div>
            </template>
        </div>
        @endif
    </div>

    {{-- Success badge --}}
    <div class="mad-scanner-detected" x-show="detected" x-transition x-cloak>
        <i data-lucide="check-circle"></i>
        <span x-text="detectedType + ' detectado'"></span>
    </div>

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
