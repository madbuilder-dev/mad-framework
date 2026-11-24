<?php

namespace Mad\Form;

use Mad\Component\MadRenderContext;

/**
 * Formulário em leitura (fw#498): a ficha de um registro mostra rótulo e valor,
 * não controles — nada de "Selecione..." vazio, toggle desligado ou caixa de
 * texto para quem só pode ler.
 *
 * Liga de dois jeitos:
 *   <mad-form read>              todos os campos, "—" no vazio
 *   <mad-form read="filled">     só os campos preenchidos
 *   $this->form->readMode()      o mesmo pelo PHP (ex.: no onEdit, por perfil);
 *   $this->form->readMode('filled')
 *
 * Cada campo que conhece o modo (input, textarea, select, dbcombo, date,
 * datetime, money, number, switch, checkbox, radio, dbradio e, desde o fórum
 * #394, numeric, cep, cnpj, time, spinner, range, color, password, daterange,
 * checkbox-group, select-check, dbcheckbox-group, dbselect-check) chama field() no
 * lugar do controle: rótulo e valor no visual do <mad-display-field> e o
 * valor num <input type="hidden">, para a ação da tela que lê o formulário
 * continuar recebendo o mesmo dado. Os outros campos ficam travados pelo CSS
 * (.mad-form-read).
 */
final class ReadMode
{
    public const ALL    = 'all';
    public const FILLED = 'filled';

    /**
     * Modo do formulário em volta do campo: '' (edição), 'all' ou 'filled'.
     * O PHP (`$this->form->readMode()`) vence a tag; a tag é achada na pilha de
     * componentes do Blade, porque o campo é desenhado antes do <mad-form>.
     *
     * @param object|null $env  o `$__env` da view do campo
     */
    public static function mode($env = null): string
    {
        $form = MadRenderContext::getForm();
        if ($form && $form->readMode !== '') {
            return $form->readMode;
        }
        if ($env && method_exists($env, 'getConsumableComponentData')) {
            return self::normalize($env->getConsumableComponentData('read', null));
        }

        return '';
    }

    /** Valor da prop `read` / do readMode(): true|'all' → 'all', 'filled' → 'filled', resto → ''. */
    public static function normalize($v): string
    {
        if ($v === true) {
            return self::ALL;
        }
        if (! is_string($v) && ! is_int($v)) {
            return '';
        }
        $v = strtolower(trim((string) $v));
        if ($v === self::FILLED) {
            return self::FILLED;
        }

        return in_array($v, ['1', 'true', 'all', 'read', 'yes', 'sim'], true) ? self::ALL : '';
    }

    /**
     * Campo em leitura.
     *
     * @param array{
     *     name?: string, label?: string, text?: string|null, html?: string|null,
     *     raw?: string|array|null, hidden?: bool, bool?: bool, on?: bool, class?: string, style?: string,
     *     empty?: bool, noHidden?: bool
     * } $o  `text` (escapado) ou `html` (já seguro); `raw` vai no hidden; `bool`
     *       = campo sim/não, ligado conforme `on` (desligado conta como vazio no
     *       modo 'filled'); `empty` decide o vazio quando o html não tem texto;
     *       `noHidden` = sem o valor no formulário.
     */
    public static function field(string $mode, array $o): string
    {
        $name  = (string) ($o['name'] ?? '');
        $raw   = $o['raw'] ?? '';
        $isBool = ! empty($o['bool']);
        $html  = $o['html'] ?? null;
        $text  = $o['text'] ?? null;
        $empty = $html === null
            ? ($text === null || trim((string) $text) === '')
            : trim(strip_tags((string) $html)) === '';
        if (array_key_exists('empty', $o)) {
            $empty = (bool) $o['empty'];   // html sem texto que vale (uma <img>)
        }
        if ($isBool) {
            $on    = array_key_exists('on', $o) ? (bool) $o['on'] : self::truthy($raw);
            $text  = $on ? mad_t('mad.field.yes') : mad_t('mad.field.no');
            $html  = null;
            $empty = ! $on;
        }

        // Campos de arquivo (`noHidden`): sem o valor no formulário — um path ou
        // um vazio no lugar do arquivo poderia ser lido como "trocar/remover"
        // por uma ação que grave o formulário.
        $hiddenInputs = empty($o['noHidden']) ? self::hiddenInputs($name, $raw) : '';
        if ($mode === self::FILLED && $empty) {
            // Fora da tela, mas o valor continua no formulário.
            return '<div class="mad-field-read-skip" hidden'
                . ($name !== '' ? ' data-mad-field="' . e($name) . '"' : '') . '>' . $hiddenInputs . '</div>';
        }

        if ($empty && ! $isBool) {
            $value = '<span class="mad-display-field-empty">—</span>';
        } else {
            $value = $html !== null ? (string) $html : nl2br(e((string) $text));
        }
        $label = (string) ($o['label'] ?? '');
        $class = 'mad-field mad-display-field mad-field-read'
            . (! empty($o['hidden']) ? ' mad-hidden' : '')
            . (! empty($o['class']) ? ' ' . $o['class'] : '');

        return '<div class="' . e($class) . '"'
            . ($name !== '' ? ' data-mad-field="' . e($name) . '"' : '')
            . (! empty($o['style']) ? ' style="' . e($o['style']) . '"' : '') . '>'
            . ($label !== '' ? '<div class="mad-display-field-header"><span class="mad-display-field-label">' . $label . '</span></div>' : '')
            . '<div class="mad-display-field-value">' . $value . '</div>'
            . $hiddenInputs
            . '</div>';
    }

    /**
     * Campo de arquivo em leitura (imagem, arquivo, vários arquivos, avatar,
     * assinatura): rótulo e o arquivo para ver/baixar — nada de enviar,
     * trocar, girar, recortar, tirar foto ou remover. Sem arquivo, "—".
     *
     * @param array{name?: string, label?: string, image?: string, files?: list<array{name?: string, url?: string}>,
     *              round?: bool, style?: string, class?: string} $o
     */
    public static function fileField(string $mode, array $o): string
    {
        $html = '';
        if (($o['image'] ?? '') !== '') {
            $html = self::imageHtml((string) $o['image'], ! empty($o['round']));
        } elseif (! empty($o['files'])) {
            $html = self::fileLinksHtml((array) $o['files']);
        }

        return self::field($mode, [
            'name'     => (string) ($o['name'] ?? ''),
            'label'    => (string) ($o['label'] ?? ''),
            'html'     => $html !== '' ? $html : null,
            'empty'    => $html === '',
            'noHidden' => true,
            'class'    => trim('mad-field-read-file ' . ($o['class'] ?? '')),
            'style'    => (string) ($o['style'] ?? ''),
        ]);
    }

    /** `<img>` só com endereço de imagem: http(s), data:image/, caminho do site. Outro esquema (`javascript:`) não vira imagem. */
    public static function imageHtml(string $src, bool $round = false): string
    {
        $src = trim($src);
        if ($src === '' || ! self::safeUrl($src, true)) {
            return '';
        }

        return '<img class="mad-field-read-image' . ($round ? ' is-round' : '') . '" src="' . e($src) . '" alt="" loading="lazy">';
    }

    /** Links para ver/baixar os arquivos (`[['name' => …, 'url' => …]]`); sem endereço, só o nome. */
    public static function fileLinksHtml(array $files): string
    {
        $items = [];
        foreach ($files as $f) {
            $name = trim((string) ($f['name'] ?? ''));
            $url  = trim((string) ($f['url'] ?? ''));
            if ($name === '' && $url === '') {
                continue;
            }
            $label = e($name !== '' ? $name : basename(parse_url($url, PHP_URL_PATH) ?: $url));
            $items[] = $url !== '' && self::safeUrl($url, false)
                ? '<a class="mad-field-read-file-link" href="' . e($url) . '" target="_blank" rel="noopener">' . $label . '</a>'
                : '<span class="mad-field-read-file-link">' . $label . '</span>';
        }

        return $items === [] ? '' : '<div class="mad-field-read-files">' . implode('', $items) . '</div>';
    }

    /** Endereço que pode ir num src/href: http(s), caminho relativo, ou (`$image`) data:image/. */
    private static function safeUrl(string $url, bool $image): bool
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url, $m) !== 1) {
            return true;   // relativo (ou //host): o navegador usa o esquema da página
        }
        $scheme = strtolower(rtrim($m[0], ':'));
        if ($scheme === 'http' || $scheme === 'https') {
            return true;
        }

        return $image && $scheme === 'data' && preg_match('#^data:image/[a-z0-9.+-]+[;,]#i', $url) === 1;
    }

    /** Rótulos das opções escolhidas (`[valor => rótulo]`), na ordem da escolha. */
    public static function optionText(array $options, $selected): string
    {
        // Lista de objetos (`[['value' => …, 'label' => …]]`, a forma do radio) vira mapa.
        if (isset($options[0]) && is_array($options[0]) && array_key_exists('value', $options[0])) {
            $options = array_column($options, 'label', 'value');
        }
        $keys = is_array($selected) ? $selected : MadForm::selectionKeys($selected);
        $out = [];
        foreach ($keys as $k) {
            $k = (string) $k;
            if ($k === '') {
                continue;
            }
            $label = $options[$k] ?? null;
            if (is_array($label)) {
                $label = $label['label'] ?? $label['text'] ?? null;
            }
            $out[] = $label !== null ? strip_tags((string) $label) : $k;
        }

        return implode(', ', $out);
    }

    /** `[valor => rótulo]` das `<option>` de um slot (select montado com opções no Blade). */
    public static function slotOptions(string $slot): array
    {
        $out = [];
        if (preg_match_all('#<option\b([^>]*)>(.*?)</option>#si', $slot, $m, PREG_SET_ORDER)) {
            foreach ($m as $o) {
                $val = preg_match('#\bvalue\s*=\s*(["\'])(.*?)\1#si', $o[1], $v)
                    ? html_entity_decode($v[2], ENT_QUOTES)
                    : trim(html_entity_decode(strip_tags($o[2]), ENT_QUOTES));
                $out[$val] = trim(html_entity_decode(strip_tags($o[2]), ENT_QUOTES));
            }
        }

        return $out;
    }

    /** Número cru ("1830.5") ou já formatado ("1.830,50") com prefixo e separadores. */
    public static function money(string $raw, string $prefix = 'R$', int $decimals = 2, string $dec = ',', string $thou = '.', string $suffix = ''): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $num = is_numeric($raw) ? (float) $raw : (float) str_replace([$thou, $dec], ['', '.'], $raw);

        return trim(($prefix !== '' ? $prefix . ' ' : '') . number_format($num, $decimals, $dec, $thou) . ($suffix !== '' ? ' ' . $suffix : ''));
    }

    /**
     * Número cru com o separador decimal do idioma, sem separador de milhar
     * (fórum #394): "0.00" → "0,00", "2026" → "2026". O `<mad-number-field>`
     * guarda o que foi digitado — código e ano não ganham ponto de milhar.
     */
    public static function decimal(string $raw): string
    {
        $raw = trim($raw);
        if (! preg_match('/^-?\d+(\.\d+)?$/', $raw)) {
            return $raw;
        }

        return str_replace('.', \Mad\Support\ValueFormatter::separators()[0], $raw);
    }

    /**
     * Número com as casas e os separadores do idioma ("2.5" → "2,50"), com
     * prefixo/sufixo — o `<mad-numeric-field>` em leitura (fórum #394).
     */
    public static function numeric(string $raw, int $decimals = 2, string $prefix = '', string $suffix = ''): string
    {
        [$dec, $thou] = \Mad\Support\ValueFormatter::separators();

        return self::money($raw, $prefix, $decimals, $dec, $thou, $suffix);
    }

    /**
     * Data gravada ("2026-03-01", "2026-03-01 10:00:00") na máscara de exibição
     * do campo (`dd/mm/yyyy`). Valor que não é data volta como veio.
     */
    public static function dateText(string $raw, string $displayMask = 'dd/mm/yyyy'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        $dt = \DateTime::createFromFormat('!Y-m-d', substr($raw, 0, 10));
        if (! $dt || $dt->format('Y-m-d') !== substr($raw, 0, 10)) {
            return $raw;
        }
        $php = strtr($displayMask !== '' ? $displayMask : 'dd/mm/yyyy', ['yyyy' => 'Y', 'yy' => 'y', 'mm' => 'm', 'dd' => 'd']);

        return $dt->format($php);
    }

    /**
     * Dígitos do valor na máscara (`00000-000`, `00.000.000/0000-00`) quando a
     * quantidade bate; senão o valor como veio (já formatado ou fora do padrão).
     */
    public static function digitsMask(string $raw, string $mask): string
    {
        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '' || strlen($digits) !== substr_count($mask, '0')) {
            return trim($raw);
        }
        $out = '';
        $i = 0;
        foreach (str_split($mask) as $ch) {
            $out .= $ch === '0' ? $digits[$i++] : $ch;
        }

        return $out;
    }

    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if (is_array($v)) {
            return $v !== [];
        }

        return ! in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'f', 'n', 'no', 'nao', 'não', 'off', 'null'], true);
    }

    private static function hiddenInputs(string $name, $raw): string
    {
        if ($name === '') {
            return '';
        }
        if (is_array($raw)) {
            $out = '';
            foreach ($raw as $v) {
                if (is_scalar($v)) {
                    $out .= '<input type="hidden" name="' . e($name) . '[]" value="' . e((string) $v) . '">';
                }
            }

            return $out;
        }
        if (is_bool($raw)) {
            $raw = $raw ? '1' : '';
        }

        return '<input type="hidden" name="' . e($name) . '" value="' . e(is_scalar($raw) ? (string) $raw : '') . '">';
    }
}
