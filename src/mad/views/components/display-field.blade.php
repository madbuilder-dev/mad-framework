@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '', 'name' => '', 'label' => '', 'value' => null, 'format' => '', 'badge' => '', 'icon' => '', 'variant' => '', 'copyable' => false, 'expandable' => false, 'empty' => '—', 'hint' => '', 'class' => ''])
@php
    $copyable   = !empty($copyable);
    $expandable = !empty($expandable);

    // Resolve valor: prop value > MadRenderContext
    if ($value === null && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        $value = array_key_exists($name, $_ctx) ? $_ctx[$name] : '';
    }
    // Selo de coluna boolean: `(string) false` é '' — o campo saía "—" em vez
    // do "0:danger:Inativo" do mapa. Com selo, boolean vira '1'/'0'.
    $rawValue  = (is_bool($value) && !empty($badge)) ? ($value ? '1' : '0') : (string) $value;
    $isEmpty   = ($rawValue === '' || $rawValue === null);
    $displayHtml = '';

    if ($isEmpty) {
        $displayHtml = '<span class="mad-display-field-empty">' . htmlspecialchars($empty, ENT_QUOTES) . '</span>';
    } elseif (!empty($badge)) {
        // Badge map: "val:variant:label|val2:variant2:label2"
        $badgeMap = [];
        foreach (explode('|', $badge) as $entry) {
            $parts = explode(':', $entry, 3);
            if (count($parts) >= 2) {
                $badgeMap[$parts[0]] = [
                    'variant' => $parts[1],
                    // Rótulo no idioma do app (lang/{idioma}.json exportado pelo
                    // MadBuilder); sem rótulo mostra o valor gravado, sem traduzir.
                    'label'   => isset($parts[2]) ? \Mad\I18n\AppText::translate($parts[2]) : $parts[0],
                ];
            }
        }
        $match = $badgeMap[$rawValue] ?? null;
        if ($match) {
            $displayHtml = '<span class="mad-badge mad-badge-' . htmlspecialchars($match['variant'], ENT_QUOTES)
                         . '" style="display:inline-flex;align-items:center;gap:5px;">'
                         . '<span style="width:6px;height:6px;border-radius:50%;background:var(--mad-'
                         . htmlspecialchars($match['variant'], ENT_QUOTES) . ');flex-shrink:0;"></span>'
                         . htmlspecialchars($match['label'], ENT_QUOTES) . '</span>';
        } else {
            $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
        }
    } elseif (!empty($format)) {
        $fmtParts = explode(':', $format, 2);
        $fmtType  = $fmtParts[0];
        $fmtParam = $fmtParts[1] ?? '';

        switch ($fmtType) {
            case 'money':
                $prefix = $fmtParam ?: 'R$';
                $num = (float) str_replace(['.', ','], ['', '.'], $rawValue);
                $displayHtml = htmlspecialchars($prefix, ENT_QUOTES) . ' ' . number_format($num, 2, ',', '.');
                break;

            case 'date':
                try {
                    $dt = new \DateTime($rawValue);
                    $displayHtml = $dt->format('d/m/Y');
                } catch (\Throwable $e) {
                    $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
                }
                break;

            case 'datetime':
                try {
                    $dt = new \DateTime($rawValue);
                    $displayHtml = $dt->format('d/m/Y H:i');
                } catch (\Throwable $e) {
                    $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
                }
                break;

            case 'email':
                $safe = htmlspecialchars($rawValue, ENT_QUOTES);
                $displayHtml = '<a href="mailto:' . $safe . '">' . $safe . '</a>';
                break;

            case 'phone':
                $digits = preg_replace('/\D/', '', $rawValue);
                $safe = htmlspecialchars($rawValue, ENT_QUOTES);
                $displayHtml = '<a href="tel:+55' . $digits . '">' . $safe . '</a>';
                break;

            case 'url':
                $safe = htmlspecialchars($rawValue, ENT_QUOTES);
                $href = (strpos($rawValue, '://') === false) ? 'https://' . $safe : $safe;
                $displayLabel = preg_replace('#^https?://(www\.)?#', '', $rawValue);
                $displayHtml = '<a href="' . $href . '" target="_blank" rel="noopener">'
                             . htmlspecialchars($displayLabel, ENT_QUOTES) . '</a>';
                break;

            case 'cpf':
                $d = preg_replace('/\D/', '', $rawValue);
                if (strlen($d) === 11) {
                    $displayHtml = substr($d,0,3) . '.' . substr($d,3,3) . '.' . substr($d,6,3) . '-' . substr($d,9,2);
                } else {
                    $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
                }
                break;

            case 'cnpj':
                // sanitize preserva LETRAS: o CNPJ alfanumerico da Receita
                // (julho/2026) caia no else e era exibido cru, sem mascara.
                $d = \Mad\Support\MadCnpj::sanitize($rawValue);
                if (strlen($d) === 14) {
                    $displayHtml = \Mad\Support\MadCnpj::format($d);
                } else {
                    $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
                }
                break;

            default:
                $displayHtml = htmlspecialchars($rawValue, ENT_QUOTES);
        }
    } else {
        $displayHtml = nl2br(htmlspecialchars($rawValue, ENT_QUOTES));
    }

    $variantClass = $variant ? ' mad-display-field-' . $variant : '';
    $copyVal = htmlspecialchars($rawValue, ENT_QUOTES);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle('', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false);
@endphp
<div class="mad-display-field {{ $class }}"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
    <div class="mad-display-field-header">
        @if($icon)
            <i data-lucide="{{ $icon }}" class="mad-display-field-icon"></i>
        @endif
        <span class="mad-display-field-label">{!! $label !!}</span>
        @if($copyable && !$isEmpty)
            <button type="button" class="mad-display-field-copy" title="Copiar"
                onclick="navigator.clipboard.writeText('{{ $copyVal }}');var _b=this;_b.classList.add('mad-display-field-copied');setTimeout(function(){_b.classList.remove('mad-display-field-copied')},1500)">
                <i data-lucide="copy" style="width:12px;height:12px;"></i>
            </button>
        @endif
    </div>
    @endif
    @if($expandable && !$isEmpty && mb_strlen($rawValue) > 200)
        <div x-data="{ expanded: false }">
            <div class="mad-display-field-value{{ $variantClass }}"
                 :class="expanded ? '' : 'mad-display-field-clamped'">
                {!! $displayHtml !!}
            </div>
            <button type="button" class="mad-display-field-toggle" @click="expanded = !expanded"
                x-text="expanded ? 'ver menos' : 'ver mais'"></button>
        </div>
    @else
        <div class="mad-display-field-value{{ $variantClass }}">
            {!! $displayHtml !!}
        </div>
    @endif
    @if($hint)
        <p class="mad-display-field-hint">{!! $hint !!}</p>
    @endif
</div>
