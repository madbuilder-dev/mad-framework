@props([ 'labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '', 'name' => '', 'label' => '', 'value' => null, 'format' => '', 'badge' => '', 'icon' => '', 'variant' => '', 'copyable' => false, 'expandable' => false, 'empty' => '—', 'hint' => '', 'class' => '', 'model' => '', 'display' => '', 'key' => '', 'attrs' => ''])
@php
    $copyable   = !empty($copyable);
    $expandable = !empty($expandable);

    // Resolve valor: prop value > MadRenderContext
    if ($value === null && $name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        $value = array_key_exists($name, $_ctx) ? $_ctx[$name] : '';
    }
    // Chave de outra tabela (`model` + `display`, como no combo): mostra o
    // rótulo do registro, não o código — um valor ou vários (seleção múltipla:
    // lista, JSON ou vírgula), numa consulta só. Registro que não existe mais
    // mostra a chave; model que não carrega não derruba a tela.
    if ($model !== '' && $display !== '' && $value !== null && $value !== '' && $value !== []) {
        $_keys = \Mad\Form\MadForm::selectionKeys($value);
        if ($_keys !== []) {
            try {
                $_labels = \Mad\Form\ModelOptionsLoader::labelsFor($model, (string) $key, $display, $_keys);
            } catch (\Throwable $e) {
                $_labels = [];
                \Illuminate\Support\Facades\Log::warning('[mad-display-field] rótulo de "' . $name . '" não carregou: '
                    . \Mad\Ui\MadErrorRedactor::message($e));
            }
            $value = implode(', ', array_map(fn ($k) => $_labels[$k] ?? $k, $_keys));
        }
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
                // O campo de dinheiro manda o número cru ("1234.56"), como o
                // banco guarda; só texto no padrão brasileiro ("1.234,56") é
                // convertido. Antes "1234.56" virava R$ 123.456,00.
                $num = is_numeric($rawValue)
                    ? (float) $rawValue
                    : (float) str_replace(['.', ','], ['', '.'], $rawValue);
                $displayHtml = htmlspecialchars($prefix, ENT_QUOTES) . ' ' . number_format($num, 2, ',', '.');
                break;

            case 'date':
            case 'datetime':
                // Dia primeiro quando vem com barra/ponto/hífen antes do ano
                // ("05/10/2026", como o campo de data manda): o DateTime lia
                // como mês/dia e trocava os dois até o dia 12.
                $dt = null;
                if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', trim($rawValue), $_dm)) {
                    $dt = checkdate((int) $_dm[2], (int) $_dm[1], (int) $_dm[3])
                        ? (new \DateTime())->setDate((int) $_dm[3], (int) $_dm[2], (int) $_dm[1])
                            ->setTime((int) ($_dm[4] ?? 0), (int) ($_dm[5] ?? 0), (int) ($_dm[6] ?? 0))
                        : null;
                } else {
                    try {
                        $dt = new \DateTime($rawValue);
                    } catch (\Throwable $e) {
                        $dt = null;
                    }
                }
                $displayHtml = $dt
                    ? $dt->format($fmtType === 'date' ? 'd/m/Y' : 'd/m/Y H:i')
                    : htmlspecialchars($rawValue, ENT_QUOTES);
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
<div class="mad-display-field {{ $class }}"@if($_dimStyle) style="{{ $_dimStyle }}"@endif {!! $attrs !!}>
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
