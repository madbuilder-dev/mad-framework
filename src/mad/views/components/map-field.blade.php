{{--
    <mad-map-field> — posição no mapa para o formulário: grava latitude e
    longitude em duas colunas.

    <mad-map-field name="latitude" lng-name="longitude" label="Localização"
        geocode-from="cep,logradouro,numero,cidade,uf" height="300" />

    - arrasta o pino ou clica no mapa para posicionar;
    - busca de endereço/CEP pelo Nominatim (OpenStreetMap), com espera entre
      teclas e aviso de que o texto vai para esse serviço;
    - `geocode-from`: campos do formulário que montam o endereço; ao sair de
      um deles, o mapa procura a posição (só se ela ainda estiver vazia);
    - `search="false"` esconde a caixa de busca.
    Desenho: lib/mad/mad-map.js (mesmo Leaflet do <mad-map>). Guia: docs/mad-map.md
--}}
@php
    $__b = static fn ($v, bool $d): bool => \Mad\Chart\MadMap::bool($v, $d);

    $name     = (string) ($name ?? '');
    $lngName  = (string) ($lngName ?? '');
    if ($lngName === '' && $name !== '') {
        // latitude → longitude, end_lat → end_lng; sem padrão, <name>_lng.
        $lngName = preg_match('/latitude$/i', $name) ? (string) preg_replace('/latitude$/i', 'longitude', $name)
            : (preg_match('/lat$/i', $name) ? (string) preg_replace('/lat$/i', 'lng', $name) : $name . '_lng');
    }
    $label    = (string) ($label ?? '');
    $hint     = (string) ($hint ?? '');
    $error    = (string) ($error ?? '');
    $required = $__b($required ?? null, false);
    $disabled = $__b($disabled ?? null, false);
    $search   = $__b($search ?? null, true);
    $mapHeight = \Mad\Support\CssUnits::length((string) ($height ?? ''), '280px');
    $geocodeFrom = array_values(array_filter(array_map('trim', is_array($geocodeFrom ?? null) ? $geocodeFrom : explode(',', (string) ($geocodeFrom ?? ''))),
        static fn ($f) => preg_match('/^[A-Za-z_][A-Za-z0-9_.\[\]-]*$/', $f)));
    $country  = strtolower(trim((string) ($country ?? 'br')));
    $country  = preg_match('/^[a-z]{2}(,[a-z]{2})*$/', $country) ? $country : '';

    $_ctx = \Mad\Component\MadRenderContext::current();
    $latValue = $name !== '' && array_key_exists($name, $_ctx) ? (string) ($_ctx[$name] ?? '') : (string) ($value ?? '');
    $lngValue = $lngName !== '' && array_key_exists($lngName, $_ctx) ? (string) ($_ctx[$lngName] ?? '') : (string) ($lngValue ?? '');
    [$__center, $__zoom] = \Mad\Chart\MadMap::view($center ?? null, $zoom ?? null);

    if ($name !== '') {
        \Mad\Form\MadFormRegistry::register($name, 'input', ['label' => strip_tags($label !== '' ? $label : mad_t('mad.map.lat')) . ' (' . mad_t('mad.map.lat') . ')', 'required' => $required]);
    }
    if ($lngName !== '') {
        \Mad\Form\MadFormRegistry::register($lngName, 'input', ['label' => strip_tags($label !== '' ? $label : mad_t('mad.map.lng')) . ' (' . mad_t('mad.map.lng') . ')', 'required' => $required]);
    }

    $hasError = $error !== '';
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $uid = \Mad\Chart\MadMap::domId('field|' . $name . '|' . $lngName);
    $__cfg = [
        'field' => true, 'name' => $name, 'lngName' => $lngName, 'center' => $__center, 'zoom' => $__zoom,
        'disabled' => $disabled, 'geocodeFrom' => $geocodeFrom, 'country' => $country,
        'tiles' => '', 'layers' => [],
        'text' => [
            'loadError' => mad_t('mad.map.load_error'), 'searching' => mad_t('mad.map.searching'),
            'notFound' => mad_t('mad.map.not_found'),
        ],
    ];
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field mad-map-field" id="{{ $uid }}" data-mad-map @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label !== '')
        <div class="mad-label">{!! $label !!}{!! $reqStar !!}</div>
    @endif

    @if($search && !$disabled)
        <div class="mad-map-search">
            <input type="search" class="mad-input{{ $hasError ? ' mad-input-error' : '' }}" data-map-search autocomplete="off"
                   placeholder="{{ mad_t('mad.map.search') }}" aria-label="{{ mad_t('mad.map.search') }}">
            <button type="button" class="mad-btn mad-btn-light" data-map-search-btn>
                <i data-lucide="search" style="width:14px;height:14px;"></i><span>{{ mad_t('mad.map.search_btn') }}</span>
            </button>
        </div>
        <p class="mad-map-notice">{{ mad_t('mad.map.notice') }}</p>
    @endif

    <div class="mad-map-canvas{{ $hasError ? ' mad-input-error' : '' }}" data-map-canvas style="height:{{ $mapHeight }}" role="application"
         aria-label="{{ $label !== '' ? strip_tags($label) : mad_t('mad.map.hint') }}">
        <div class="mad-map-status" data-map-status>{{ mad_t('mad.map.loading') }}</div>
    </div>

    <div class="mad-map-field-foot">
        <span class="mad-map-coords" data-map-coords>{{ $latValue !== '' && $lngValue !== '' ? $latValue . ', ' . $lngValue : '' }}</span>
        @if(!$disabled)
            <button type="button" class="mad-map-clear" data-map-clear>{{ mad_t('mad.map.clear') }}</button>
        @endif
    </div>

    <input type="hidden" name="{{ $name }}" id="{{ $name }}" value="{{ $latValue }}" data-map-lat mad:model="{{ $name }}">
    <input type="hidden" name="{{ $lngName }}" id="{{ $lngName }}" value="{{ $lngValue }}" data-map-lng mad:model="{{ $lngName }}">

    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : ($hint !== '' ? e($hint) : (!$disabled ? e(mad_t('mad.map.hint')) : '')) !!}</p>

    <script type="application/json" data-map-config>{!! json_encode($__cfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR) !!}</script>
    <script>
    (function () {
        var el = document.getElementById({!! json_encode($uid, JSON_HEX_TAG) !!});
        if (!el) return;
        if (window.MadMap && typeof window.MadMap.mount === 'function') { window.MadMap.mount(el); return; }
        (window.__madMapQueue = window.__madMapQueue || []).push(el);
        if (!document.querySelector('script[data-mad-map-lib]')) {
            var s = document.createElement('script');
            s.src = {!! json_encode(\Mad\Support\MadFramework::asset('lib/mad/mad-map.js'), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) !!};
            s.setAttribute('data-mad-map-lib', '');
            document.head.appendChild(s);
        }
    })();
    </script>
</div>
