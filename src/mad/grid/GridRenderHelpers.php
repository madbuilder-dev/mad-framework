<?php

namespace Mad\Grid;

use Mad\View\MadBlade;

/**
 * Helpers estaticos compartilhados entre MadDataGrid e mad-data-table.
 *
 * Extrai logica reusavel para que componentes leves (read-only) possam
 * gerar tabelas sem precisar herdar MadDataGrid.
 */
class GridRenderHelpers
{
    /**
     * Detecta padroes {relacao->campo} em col->field.
     * Retorna [field => pattern] para uso no render de campos do registro.
     *
     * @param GridColumn[] $columns
     * @return array<string, string>
     */
    public static function detectRenderFields(array $columns): array
    {
        $out = [];
        foreach ($columns as $col) {
            $f = (string) $col->field;
            if ($f === '') {
                continue;
            }
            if (str_contains($f, '{')) {
                $out[$f] = $f;
                continue;
            }
            // Chain NUA (`cidade->nome`, sem chaves): a celula ficava vazia
            // porque nada materializava a chave. A chave da linha continua
            // sendo o `field` como declarado; so o PATTERN ganha as chaves,
            // que e o que o resolveTemplate entende.
            if (str_contains($f, '->')) {
                $out[$f] = '{' . $f . '}';
            }
        }
        return $out;
    }

    /**
     * Campos de QUEBRA que sao chain (`rubrica->codigo`) como renderFields.
     *
     * A linha e indexada pela chave NUA — exatamente a string declarada no
     * `group-by` — porque e por ela que o achatamento de grupos, o reset do
     * saldo acumulado e a mascara procuram o valor. O pattern vai embrulhado
     * (`{rubrica->codigo}`) porque e a forma que o resolveTemplate percorre.
     *
     * @param string[] $groupFields
     * @return array<string, string>
     */
    public static function groupRenderFields(array $groupFields): array
    {
        $out = [];
        foreach ($groupFields as $field) {
            $f = trim((string) $field);
            if ($f === '') {
                continue;
            }

            // Granularidade (`data_venda|day`): a chave da linha e a string
            // DECLARADA inteira — e por ela que o achatamento, o reset do saldo
            // e a mascara procuram o balde. O pattern aponta o campo BASE; quem
            // transforma o valor cru em chave normalizada e o normalizeRows.
            [$base, $grain] = self::splitGroupGrain($f);
            if ($grain !== null) {
                $b = self::stripBraces($base);
                $out[$f] = '{' . $b . '}';
                continue;
            }

            if (! str_contains($f, '->')) {
                continue;
            }
            $out[$f] = (str_starts_with($f, '{') && str_ends_with($f, '}')) ? $f : '{' . $f . '}';
        }
        return $out;
    }

    // -- Granularidade da quebra (`group-by="data_venda|day"`) ---------------

    /** Sufixos de granularidade aceitos no `group-by`. */
    public const GROUP_GRAINS = ['day', 'week', 'month', 'year'];

    /**
     * Separa `campo|fn` em [campo base, granularidade].
     *
     * A granularidade so e reconhecida quando o sufixo e uma das
     * `GROUP_GRAINS` — assim `{data|date}` (token de MASCARA, que termina em
     * `}`) e um `|foo` digitado errado nunca viram campo base truncado.
     *
     * @return array{0:string,1:?string} fn `null` = campo sem sufixo
     */
    public static function splitGroupGrain(string $field): array
    {
        $f = trim($field);
        if ($f === '' || str_ends_with($f, '}')) {
            return [$f, null];
        }

        $pos = strrpos($f, '|');
        if ($pos === false) {
            return [$f, null];
        }

        $grain = strtolower(trim(substr($f, $pos + 1)));
        $base  = rtrim(substr($f, 0, $pos));
        if ($base === '' || ! in_array($grain, self::GROUP_GRAINS, true)) {
            return [$f, null];
        }

        return [$base, $grain];
    }

    /** Campo BASE de um campo de quebra (`data|day` -> `data`). */
    public static function groupGrainBase(string $field): string
    {
        return self::splitGroupGrain($field)[0];
    }

    /**
     * Normaliza um campo de quebra: sufixo `|fn` DESCONHECIDO e descartado com
     * aviso (fail-closed).
     *
     * Sem isto, `group-by="data|dai"` viraria uma chave de linha que ninguem
     * materializa e jogaria TODOS os registros num unico grupo vazio — em
     * silencio. Cair no campo sem granularidade e menos pior do que a tela
     * mentir.
     */
    public static function normalizeGroupField(string $field): string
    {
        $f = trim($field);
        if ($f === '' || str_ends_with($f, '}')) {
            return $f;
        }

        $pos = strrpos($f, '|');
        if ($pos === false) {
            return $f;
        }

        $grain = strtolower(trim(substr($f, $pos + 1)));
        $base  = rtrim(substr($f, 0, $pos));
        if ($base === '' || in_array($grain, self::GROUP_GRAINS, true)) {
            return $f;
        }

        self::warnOnce(
            'group-grain:' . $f,
            'quebra por "' . $f . '": granularidade "' . $grain . '" desconhecida (aceitas: '
            . implode(', ', self::GROUP_GRAINS) . '). A quebra usa "' . $base . '" sem granularidade.'
        );

        return $base;
    }

    /**
     * CHAVE do balde de um valor cru — o que identifica o grupo.
     *
     * `day` -> `Y-m-d` · `week` -> `2026-W11` (semana ISO) · `month` -> `Y-m` ·
     * `year` -> `Y`. Aceita `DateTimeInterface`/Carbon, `Y-m-d H:i:s`, `Y-m-d`,
     * epoch numerico — o mesmo parser dos formatadores `date*`
     * (`ValueFormatter::toDateTime`). Vazio/nulo -> `''`; valor que nao e data
     * volta CRU, para o grupo continuar distinguivel em vez de sumir dentro do
     * balde vazio.
     */
    public static function groupGrainKey(mixed $value, string $grain): string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return '';
        }

        $dt = \Mad\Support\ValueFormatter::toDateTime($value);
        if ($dt === null) {
            return is_scalar($value) ? (string) $value : '';
        }

        return match (strtolower(trim($grain))) {
            'day'   => $dt->format('Y-m-d'),
            'week'  => $dt->format('o-\WW'),
            'month' => $dt->format('Y-m'),
            'year'  => $dt->format('Y'),
            default => '',
        };
    }

    /**
     * ROTULO default do balde (sem `group-mask`), no locale ativo.
     *
     * `day` sai no formato de exibicao do locale (`mad.tempo.date_format`, o
     * mesmo do `{PERIOD}` das bandas do PDF), `month` como nome do mes + ano,
     * `year` como o proprio ano e `week` como "Semana N/AAAA". Chave que nao
     * casa com o formato (valor que nao era data) volta crua.
     */
    public static function groupGrainLabel(string $key, string $grain): string
    {
        if ($key === '') {
            return '';
        }

        switch (strtolower(trim($grain))) {
            case 'day':
                $dt = \DateTime::createFromFormat('!Y-m-d', $key);
                return $dt ? $dt->format(mad_t('mad.tempo.date_format')) : $key;

            case 'month':
                if (! preg_match('/^(\d{4})-(\d{2})$/', $key, $m)) {
                    return $key;
                }
                return mad_t('mad.tempo.m' . $m[2]) . '/' . $m[1];

            case 'week':
                if (! preg_match('/^(\d{4})-W(\d{2})$/', $key, $m)) {
                    return $key;
                }
                return mad_t('mad.tempo.week_label', ['n' => ltrim($m[2], '0'), 'y' => $m[1]]);

            case 'year':
            default:
                return $key;
        }
    }

    /**
     * Rotulo de um grupo SEM mascara: o valor cru, ou o rotulo da
     * granularidade quando o campo tem sufixo.
     */
    public static function groupDefaultLabel(string $field, string $value): string
    {
        [, $grain] = self::splitGroupGrain($field);

        return $grain === null ? $value : self::groupGrainLabel($value, $grain);
    }

    /** `{campo}` -> `campo` (qualquer conteudo, nao so chain). */
    public static function stripBraces(string $field): string
    {
        $f = trim($field);

        return (strlen($f) > 2 && $f[0] === '{' && substr($f, -1) === '}')
            ? substr($f, 1, -1)
            : $f;
    }

    /**
     * Tokens que a mascara da quebra pode usar para citar o campo granular:
     * `{data|day}`, `{data}` e a forma com chaves do proprio declarado.
     *
     * @return string[]
     */
    public static function groupMaskTokens(string $field): array
    {
        $tokens = ['{' . self::unbraceChain($field) . '}', '{' . $field . '}'];

        [$base, $grain] = self::splitGroupGrain($field);
        if ($grain !== null) {
            $b = self::stripBraces($base);
            $tokens[] = '{' . $b . '|' . $grain . '}';
            $tokens[] = '{' . $b . '}';
        }

        return array_values(array_unique($tokens));
    }

    /** Valor CRU do campo base de uma quebra granular, no item ainda nao normalizado. */
    private static function rawGroupValue(mixed $item, array $row, string $base): mixed
    {
        $bare = self::stripBraces($base);

        if (str_contains($bare, '->')) {
            return self::resolveObjectPath($item, $bare);
        }

        // Prop do objeto ANTES da chave do array: num model com cast de data a
        // prop devolve o Carbon no fuso do app, enquanto o toArray() devolve
        // ISO em UTC — o dia do balde mudaria a oeste de Greenwich.
        if (is_object($item)) {
            try {
                $v = $item->{$bare} ?? null;
            } catch (\Throwable $e) {
                $v = null;
            }
            if ($v !== null) {
                return $v;
            }
        }

        return $row[$bare] ?? $row[$base] ?? null;
    }

    /** Avisa uma vez por processo (molde do _warnOnce do MadDataGrid). */
    private static function warnOnce(string $tag, string $msg): void
    {
        static $warned = [];
        if (! empty($warned[$tag])) {
            return;
        }
        $warned[$tag] = true;
        \Illuminate\Support\Facades\Log::warning('Mad\\Grid\\GridRenderHelpers: ' . $msg);
    }

    /**
     * Valor de um campo numa linha normalizada, tolerando as DUAS grafias.
     *
     * A mesma relacao pode chegar na linha como `{cidade->nome}` (coluna do
     * editor, que persiste o field embrulhado) ou como `cidade->nome` (chain
     * nua do `group-by`). Quem le — achatamento de grupos, mascara, reset do
     * saldo — nao sabe qual das duas o autor escreveu. Ultimo recurso: percorre
     * o proprio registro (`__record`), que e o que faz a linha do manage_row e
     * a linha que nunca passou por normalizeRows continuarem resolvendo.
     */
    public static function rowValue(array $row, string $field): mixed
    {
        if (array_key_exists($field, $row)) {
            return $row[$field];
        }

        $bare   = self::unbraceChain($field);
        $braced = '{' . $bare . '}';
        if (array_key_exists($bare, $row)) {
            return $row[$bare];
        }
        if (array_key_exists($braced, $row)) {
            return $row[$braced];
        }

        // Quebra granular (`data_venda|day`) que ninguem materializou: linha
        // criada no cliente, `items` inline, linha do manage_row. Recalcula o
        // balde a partir do campo base em vez de devolver null — e o que faz o
        // reset do saldo e a banda continuarem batendo nesses caminhos.
        [$gBase, $grain] = self::splitGroupGrain($field);
        if ($grain !== null) {
            $raw = self::rowValue($row, $gBase);
            if ($raw === null) {
                $plain = self::stripBraces($gBase);
                if ($plain !== $gBase) {
                    $raw = self::rowValue($row, $plain);
                }
            }
            return self::groupGrainKey($raw, $grain);
        }

        if (str_contains($bare, '->') && isset($row['__record'])) {
            try {
                return self::resolveObjectPath($row['__record'], $bare);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return null;
    }

    /**
     * A linha TEM esse campo (mesmo que o valor seja null)?
     *
     * Espelha as grafias que o `rowValue` aceita. Serve para a máscara
     * distinguir "campo vazio" (renderiza vazio) de "token que não existe"
     * (fica cru, sinalizando o erro) — sem isso uma coluna de data nula
     * imprimia `{data|date}` literal na banda e no PDF.
     */
    public static function rowHasField(array $row, string $field): bool
    {
        $bare = self::unbraceChain($field);
        if (array_key_exists($field, $row)
            || array_key_exists($bare, $row)
            || array_key_exists('{' . $bare . '}', $row)) {
            return true;
        }

        $plain = self::stripBraces($field);
        if ($plain !== $field && array_key_exists($plain, $row)) {
            return true;
        }

        [$gBase, $grain] = self::splitGroupGrain($field);

        return $grain !== null && self::rowHasField($row, $gBase);
    }

    /** `{a->b}` → `a->b`; o resto passa intacto. */
    public static function unbraceChain(string $field): string
    {
        $f = trim($field);
        if (strlen($f) > 2 && $f[0] === '{' && substr($f, -1) === '}') {
            $inner = substr($f, 1, -1);
            if (str_contains($inner, '->')) {
                return $inner;
            }
        }
        return $f;
    }

    /**
     * Chave da LINHA correspondente ao `field` de uma coluna.
     *
     * `field` e template de EXIBICAO (`{col}`, `{rel->col}`, `"{a}/{b}"`), nao
     * chave de linha. A linha carregada do servidor traz o template resolvido na
     * propria chave (normalizeRows/autoLoadDetailRows), mas a linha criada no
     * cliente (detail-form) so tem as chaves dos inputs — o `name` do campo, nu.
     * Token simples vira a chave nua; chain e template composto continuam
     * identificados pelo template inteiro (so o servidor resolve).
     */
    public static function rowDataKey(string $field): string
    {
        $f = trim($field);
        if ($f === '' || !str_contains($f, '{') || str_contains($f, '->')) {
            return $field;
        }
        if (preg_match('/^\{([^{}]+)\}$/', $f, $m)) {
            return trim($m[1]);
        }
        return $field;
    }

    /**
     * Expressao JS que le o valor de uma coluna numa linha do detail-form.
     *
     * O `field` e template de EXIBICAO; a linha tem chaves de DUAS origens:
     * a do servidor traz o template resolvido na propria chave (`{licenca_id}`),
     * a criada no navegador traz so as chaves dos inputs do sub-form (`licenca_id`,
     * nu — ver `bareNameAttrs` do BladeRefResolver). Token simples le a nua
     * primeiro e cai na do template; chain e template composto so o servidor
     * resolve, entao continuam lendo o template inteiro.
     *
     * Emitida INLINE no Blade de proposito: o card "Mad Framework" da Central
     * atualiza `vendor/mad/framework/**` mas NAO republica o JS servido sob
     * `public/`, entao uma celula que dependesse de funcao nova do mad-ui.js
     * quebraria nesse meio-termo.
     */
    public static function rowValueJs(string $field, string $rowVar = 'row'): string
    {
        $whole = self::jsString($field);
        $key   = self::rowDataKey($field);

        $expr = $key === $field
            ? "{$rowVar}[{$whole}] ?? ''"
            : "{$rowVar}[" . self::jsString($key) . "] ?? {$rowVar}[{$whole}] ?? ''";

        return '(' . $expr . ')';
    }

    /**
     * Literal JS do `field` de uma coluna — a CHAVE de leitura do mapa `__fmt`
     * (valor pre-formatado no servidor para transform callable / token de midia).
     *
     * Publico porque o Blade do detail-form precisa da MESMA regra de escape que
     * o `rowValueJs` usa internamente; duplicar a regra seria garantir que as
     * duas divirjam e a celula lesse uma chave que o servidor nunca escreveu.
     */
    public static function fieldKeyJs(string $field): string
    {
        return self::jsString($field);
    }

    /**
     * Gemeo CLIENTE de `Mad\Support\ValueFormatter::apply` — expressao JS
     * autocontida que formata `$valueExpr` com um token built-in do
     * formatter-select. Devolve '' quando o token nao e built-in (o chamador
     * cai no valor cru, sem 500).
     *
     * POR QUE INLINE (e nao uma funcao no mad-ui.js): mesmo motivo do
     * `rowValueJs` — o card "Mad Framework" da Central atualiza
     * `vendor/mad/framework/**` mas NAO republica o JS servido sob `public/`.
     * Uma celula que dependesse de funcao nova do mad-ui.js ficaria quebrada
     * nesse meio-termo.
     *
     * PARIDADE com o PHP: os grupos numerico, mascara (cpf/cnpj/cep/telefone) e
     * logico batem 1:1 com o ValueFormatter. Nas datas, a string ISO
     * (`2026-03-09`, `2026-03-09 14:05:07`) e parseada por COMPONENTES de
     * proposito: `new Date('2026-03-09')` seria meia-noite UTC e voltaria o dia
     * ANTERIOR em qualquer fuso a oeste de Greenwich. Valor numerico puro
     * (epoch) segue o fuso do navegador — a mesma assimetria que a celula de
     * data ja tem hoje.
     *
     * So aspas SIMPLES na expressao: ela e emitida dentro de `x-text="…"`.
     */
    public static function builtinFormatJs(string $token, string $valueExpr): string
    {
        $t = strtolower(trim($token));
        if (! \Mad\Support\ValueFormatter::supports($t)) {
            return '';
        }

        // n = numero (0 quando nao-numerico, igual ao num() do ValueFormatter)
        $num = "const n=(v===''||v===null||v===undefined||isNaN(Number(v)))?0:Number(v);";
        $fmt = static fn (string $loc, int $dec): string =>
            "n.toLocaleString('{$loc}',{minimumFractionDigits:{$dec},maximumFractionDigits:{$dec}})";

        // s = string escalar (igual ao scalarString(): objeto/array viram '')
        $str = "const s=(v===null||v===undefined||typeof v==='object')?'':String(v);";
        $dig = $str . "const d=s.replace(/\\D+/g,'');";

        $date = $str . "if(s==='')return '';"
              . "const _m=/^(\\d{4})-(\\d{2})-(\\d{2})(?:[T ](\\d{2}):(\\d{2})(?::(\\d{2}))?)?/.exec(s);"
              . "const _t=_m?new Date(+_m[1],+_m[2]-1,+_m[3],+(_m[4]||0),+(_m[5]||0),+(_m[6]||0))"
              . ":(/^\\d+$/.test(s)?new Date(Number(s)*1000):new Date(s));"
              . "if(isNaN(_t))return s;const p=(x)=>String(x).padStart(2,'0');";

        $monthsLong  = "const M=['janeiro','fevereiro','mar\u{E7}o','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];";
        $monthsShort = "const M=['Jan','Fev','Mar','Abr','Mai','Jun','Jul','Ago','Set','Out','Nov','Dez'];";
        $weekdays    = "const W=['domingo','segunda-feira','ter\u{E7}a-feira','quarta-feira','quinta-feira','sexta-feira','s\u{E1}bado'];";

        $dmy  = "p(_t.getDate())+'/'+p(_t.getMonth()+1)+'/'+_t.getFullYear()";
        $hm   = "p(_t.getHours())+':'+p(_t.getMinutes())";

        $body = match ($t) {
            // `money` SEM simbolo (contrato do ValueFormatter); os currency-* carregam.
            'money'        => $num . 'return ' . $fmt('pt-BR', 2) . ';',
            'currency-usd' => $num . "return 'US\$ '+" . $fmt('en-US', 2) . ';',
            'currency-eur' => $num . "return '\u{20AC} '+" . $fmt('pt-BR', 2) . ';',
            'currency-gbp' => $num . "return '\u{A3} '+" . $fmt('en-US', 2) . ';',
            'currency-jpy' => $num . "return '\u{A5} '+" . $fmt('en-US', 0) . ';',

            'number'    => $num . 'return ' . $fmt('pt-BR', 2) . ';',
            'number-en' => $num . 'return ' . $fmt('en-US', 2) . ';',
            'integer'   => $num . 'return ' . $fmt('pt-BR', 0) . ';',
            'percent'   => $num . 'return ' . $fmt('pt-BR', 1) . "+'%';",
            'decimal-4' => $num . 'return ' . $fmt('pt-BR', 4) . ';',

            'date'             => $date . 'return ' . $dmy . ';',
            'date-iso'         => $date . "return _t.getFullYear()+'-'+p(_t.getMonth()+1)+'-'+p(_t.getDate());",
            'date-long'        => $date . $monthsLong . "return _t.getDate()+' de '+M[_t.getMonth()]+' de '+_t.getFullYear();",
            'date-month-year'  => $date . $monthsShort . "return M[_t.getMonth()]+'/'+_t.getFullYear();",
            'date-weekday'     => $date . $weekdays . 'return W[_t.getDay()];',
            'datetime'         => $date . 'return ' . $dmy . "+' '+" . $hm . ';',
            'datetime-seconds' => $date . 'return ' . $dmy . "+' '+" . $hm . "+':'+p(_t.getSeconds());",
            'time'             => $date . 'return ' . $hm . ';',

            'cpf'  => $dig . "return d.length===11?d.slice(0,3)+'.'+d.slice(3,6)+'.'+d.slice(6,9)+'-'+d.slice(9):s;",
            // Alfanumerico (Receita, julho/2026): preserva letras, igual ao MadCnpj::sanitize.
            'cnpj' => $str . "const c=s.toUpperCase().replace(/[^0-9A-Z]/g,'');"
                    . "return c.length===14?c.slice(0,2)+'.'+c.slice(2,5)+'.'+c.slice(5,8)+'/'+c.slice(8,12)+'-'+c.slice(12):s;",
            'cep'  => $dig . "return d.length===8?d.slice(0,5)+'-'+d.slice(5):s;",
            'phone-br' => $dig
                    . "if(d.length===11)return '('+d.slice(0,2)+') '+d.slice(2,7)+'-'+d.slice(7);"
                    . "if(d.length===10)return '('+d.slice(0,2)+') '+d.slice(2,6)+'-'+d.slice(6);return s;",

            'boolean' => "if(typeof v==='string')return ['1','t','true','sim','s','y','yes'].includes(v.trim().toLowerCase())?'Sim':'N\u{E3}o';"
                       . "return v?'Sim':'N\u{E3}o';",

            // 'text' e qualquer token novo que o ValueFormatter aceite mas aqui
            // nao tenha ramo proprio: mesmo default do PHP (switch → text).
            default => $str . "if(Array.isArray(v))return v.map(String).join(', ');"
                     . "if(typeof v==='boolean')return v?'Sim':'N\u{E3}o';return s;",
        };

        return '(()=>{const v=' . $valueExpr . ';' . $body . '})()';
    }

    /** Literal JS entre aspas simples, seguro dentro de atributo HTML. */
    private static function jsString(string $v): string
    {
        $v = str_replace(['\\', "'"], ['\\\\', "\\'"], $v);
        $v = str_replace(['&', '"'], ['&amp;', '&quot;'], $v);

        return "'" . $v . "'";
    }

    /**
     * Normaliza itens (model Eloquent/stdClass/array) para array assoc com __record.
     * Resolve campos {relacao->campo} via ->render() do registro (lazy-load do Eloquent).
     *
     * @param array $items
     * @param array<string, string> $renderFields
     * @return array<int, array>
     */
    public static function normalizeRows(array $items, array $renderFields = []): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (is_object($item) && method_exists($item, 'toArray')) {
                $row = $item->toArray();
                if (!empty($renderFields)) {
                    $hasRender = method_exists($item, 'render');
                    foreach ($renderFields as $field => $pattern) {
                        try {
                            // Quebra granular: a chave e o BALDE (`2026-03-09`),
                            // nao o valor resolvido — `data|day` tem que cair num
                            // grupo so, com horarios diferentes no mesmo dia. Sai
                            // do valor CRU (Carbon/DateTime preservado) em vez do
                            // template, que ja teria virado string.
                            [$base, $grain] = self::splitGroupGrain((string) $field);
                            if ($grain !== null) {
                                $row[$field] = self::groupGrainKey(
                                    self::rawGroupValue($item, $row, $base),
                                    $grain
                                );
                                continue;
                            }
                            // Objeto com render() proprio usa render(). Eloquent/stdClass
                            // nao tem render() — resolve {relacao->campo} traversando
                            // props/relacoes (acesso a prop dispara lazy-load no Eloquent).
                            $row[$field] = $hasRender
                                ? $item->render($pattern)
                                : self::resolveTemplate($pattern, $item, $row);
                        } catch (\Throwable $e) {
                            $row[$field] = '';
                        }
                    }
                }
                $row['__record'] = $item;
                $rows[] = $row;
            } elseif (is_object($item)) {
                $row = (array) $item;
                $row['__record'] = $item;
                $rows[] = $row;
            } else {
                $rows[] = (array) $item;
            }
        }
        return $rows;
    }

    /**
     * Resolve um pattern de display tipo "{estado->nome}" (com texto literal e/ou
     * varios tokens, ex.: "{cidade->nome}/{uf}") contra um item Eloquent /
     * stdClass / array — SEM depender de render().
     *
     * Cada {token} com '->' percorre relacoes/props (lazy-load do Eloquent);
     * tokens simples leem array key ou prop do objeto.
     *
     * @param object|array $item
     */
    public static function resolveTemplate(string $pattern, $item, array $row = []): string
    {
        return (string) preg_replace_callback('/\{([^}]+)\}/', function ($m) use ($item, $row) {
            $key = trim($m[1]);
            if ($key === '') {
                return '';
            }
            if (str_contains($key, '->')) {
                $v = self::resolveObjectPath($item, $key);
            } elseif (array_key_exists($key, $row)) {
                $v = $row[$key];
            } elseif (is_object($item)) {
                try { $v = $item->$key ?? null; } catch (\Throwable $e) { $v = null; }
            } else {
                $v = null;
            }
            return $v === null ? '' : (string) $v;
        }, $pattern);
    }

    /**
     * Percorre um caminho 'relacao->campo[->...]' em objeto/array.
     * Em Eloquent, acessar a prop da relacao dispara lazy-load.
     *
     * @param object|array $obj
     */
    public static function resolveObjectPath($obj, string $path): mixed
    {
        $current = $obj;
        foreach (explode('->', $path) as $part) {
            $part = trim($part);
            if (is_object($current)) {
                try { $next = $current->$part ?? null; } catch (\Throwable $e) { $next = null; }
                // Segmento não resolveu como prop/relação declarada: tenta a
                // variante de nome (snake↔camel) e, por fim, VAI AO BANCO pela
                // convenção de FK (`<segmento>_id`). É o que faz `{produto->
                // categoria_produto->nome}` funcionar quando o model só declara
                // `categoriaProduto()` — ou não declara relação nenhuma.
                if ($next === null && $current instanceof \Illuminate\Database\Eloquent\Model) {
                    $next = self::resolveRelationFallback($current, $part);
                }
                $current = $next;
            } elseif (is_array($current)) {
                $current = $current[$part] ?? null;
            } else {
                return null;
            }
            if ($current === null) {
                return null;
            }
        }
        return $current;
    }

    /** Bucket do memo por request (DataScope) dos lookups por FK: "FQCN#id" => Model|null. */
    private const FK_LOOKUP_MEMO = 'grid.fk_lookup';

    /**
     * Resolve um segmento de chain que NÃO bateu em prop/relação do model.
     *
     * Por que existe: o `field` da coluna é escrito por quem monta a tela
     * (Studio emite o nome da coluna FK sem `_id`: `categoria_produto`), mas o
     * model gerado declara a relação em camelCase (`categoriaProduto()`) — ou
     * nem declara. O Eloquent não faz essa ponte: `$produto->categoria_produto`
     * é null, sem erro, e a célula fica vazia. Nem sempre o dado está no
     * formulário; a resolução tem que ir ao banco pela FK.
     *
     * Ordem: (1) método com o outro casing (`categoriaProduto`/`categoria_produto`);
     * (2) convenção de FK — `<segmento>_id` (ou `id_<segmento>`) preenchido +
     * model `<Segmento>` no ModelRegistry → `find()` na conexão do PRÓPRIO model
     * alvo (escopos globais/tenant valem). Cache por request: a mesma categoria
     * em 50 linhas é 1 query.
     *
     * O cache é POR REQUEST e por unidade/tenant (DataScope::memo). Era um
     * `static`: num worker persistente o rótulo que a query escopada achou para
     * a unidade A continuava servido à unidade B (e um nome alterado só
     * aparecia depois de reiniciar o worker).
     */
    protected static function resolveRelationFallback(\Illuminate\Database\Eloquent\Model $model, string $part): mixed
    {
        if ($part === '' || preg_match('/[^A-Za-z0-9_]/', $part)) {
            return null;
        }

        // (1) snake ↔ camel do método de relação
        foreach (array_unique([\Illuminate\Support\Str::camel($part), \Illuminate\Support\Str::snake($part)]) as $alt) {
            if ($alt === $part || !method_exists($model, $alt)) continue;
            try {
                $v = $model->$alt;
                if ($v !== null) return $v;
            } catch (\Throwable $e) {
                // relação declarada mas quebrada: segue pro fallback de FK
            }
        }

        // (2) convenção de FK → busca no banco
        $attrs = $model->getAttributes();
        foreach ([$part . '_id', 'id_' . $part] as $fk) {
            $id = $attrs[$fk] ?? null;
            if ($id === null || $id === '' || is_array($id)) continue;

            $studly = \Illuminate\Support\Str::studly($part);
            $cls    = \Mad\Database\ModelRegistry::resolve($studly);
            if ($cls === null) {
                $candidate = 'App\\Models\\' . $studly;
                $cls = class_exists($candidate) ? $candidate : null;
            }
            if ($cls === null || !is_subclass_of($cls, \Illuminate\Database\Eloquent\Model::class)) {
                error_log('[GridRenderHelpers] chain "' . $part . '": FK ' . $fk . '=' . $id
                    . ' mas nenhum model "' . $studly . '" no registry (' . get_class($model) . ').');
                return null;
            }

            return \Mad\Database\DataScope::memo(self::FK_LOOKUP_MEMO, $cls . '#' . $id, function () use ($cls, $id, $part) {
                try {
                    return $cls::query()->find($id);
                } catch (\Throwable $e) {
                    error_log('[GridRenderHelpers] chain "' . $part . '": ' . $cls . '::find(' . $id . ') falhou: ' . $e->getMessage());

                    return null;
                }
            });
        }

        return null;
    }

    /** Limpa o cache de lookups por FK (testes / long-running workers). */
    public static function flushFkLookupCache(): void
    {
        \Mad\Database\DataScope::flushMemo(self::FK_LOOKUP_MEMO);
        self::$relationNameCache = [];
    }

    // ── Eager-load derivado das chains ───────────────────────────────────

    /** @var array<string, array{0:string,1:?string}|null> "Fqcn::segmento" => [metodo, classe alvo] */
    private static array $relationNameCache = [];

    /**
     * Nome REAL do metodo de relacao para um segmento de chain, ou null quando
     * o segmento nao e relacao declarada.
     *
     * O `field`/`group-by` e escrito por quem monta a tela e costuma trazer o
     * nome da coluna FK sem `_id` (`categoria_produto`), enquanto o model
     * declara `categoriaProduto()`. Mesma ponte snake↔camel do
     * `resolveRelationFallback` — sem ela o eager-load pediria uma relacao que
     * o Eloquent nao conhece e derrubaria a listagem.
     *
     * @return array{0:string,1:?string}|null [metodo, FQCN do relacionado]
     */
    public static function relationOn(?string $modelClass, string $segment): ?array
    {
        if ($modelClass === null || $segment === ''
            || ! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return null;
        }

        $key = $modelClass . '::' . $segment;
        if (array_key_exists($key, self::$relationNameCache)) {
            return self::$relationNameCache[$key];
        }

        $found = null;
        try {
            $model = new $modelClass();
            $names = array_unique([
                $segment,
                \Illuminate\Support\Str::camel($segment),
                \Illuminate\Support\Str::snake($segment),
            ]);
            foreach ($names as $name) {
                if ($name === '' || ! method_exists($model, $name)) {
                    continue;
                }
                $rel = $model->$name();
                if ($rel instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                    $found = [$name, get_class($rel->getRelated())];
                    break;
                }
            }
        } catch (\Throwable $e) {
            $found = null;
        }

        return self::$relationNameCache[$key] = $found;
    }

    /**
     * Relacoes a passar pro `with()` a partir dos caminhos de chain das colunas
     * e da quebra: `a->b->c` vira `a.b` (o ultimo segmento e COLUNA, nao
     * relacao).
     *
     * Valida segmento a segmento e ABORTA o caminho no primeiro que nao e
     * relacao declarada — o prefixo ja validado continua valendo. Assim um
     * segmento que so o fallback de FK resolve (sem metodo no model) nao vira
     * `with()` invalido, e o resto do caminho segue pelo lazy-load de sempre.
     *
     * @param string[] $paths caminhos crus (`{a->b}`, `a->b->c`, `nome`)
     * @return string[] relacoes em notacao de ponto, sem repeticao
     */
    public static function deriveEagerLoads(array $paths, ?string $modelClass): array
    {
        if ($modelClass === null || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return [];
        }

        $out = [];
        foreach ($paths as $raw) {
            // `venda->data|day`: o sufixo de granularidade nao faz parte do
            // caminho de relacao — sai antes do desembrulho das chaves.
            [$base] = self::splitGroupGrain(trim((string) $raw));
            $path   = self::unbraceChain(trim($base, " \t{}"));
            if (! str_contains($path, '->')) {
                continue;
            }
            $segments = array_map('trim', explode('->', $path));
            array_pop($segments);            // ultimo segmento = coluna
            if (empty($segments)) {
                continue;
            }

            $rels   = [];
            $cursor = $modelClass;
            foreach ($segments as $segment) {
                $rel = self::relationOn($cursor, $segment);
                if ($rel === null) {
                    break;                   // aborta o caminho; o prefixo fica
                }
                $rels[]  = $rel[0];
                $cursor  = $rel[1];
            }
            if (! empty($rels)) {
                $out[implode('.', $rels)] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * O primeiro salto da chain tem como resolver neste model?
     *
     * Duas rotas, as mesmas do `resolveRelationFallback`: relacao declarada
     * (snake↔camel) ou convencao de FK com um model homonimo registrado.
     * Nenhuma das duas = caminho que nunca vai devolver valor — quem chama
     * trata como fail-closed em vez de produzir um grupo vazio mudo.
     */
    public static function isResolvableChain(?string $modelClass, string $path): bool
    {
        [$path] = self::splitGroupGrain(trim($path));
        $path   = self::unbraceChain(trim($path, " \t{}"));
        if (! str_contains($path, '->')) {
            return true;
        }
        if ($modelClass === null || ! class_exists($modelClass)
            || ! is_subclass_of($modelClass, \Illuminate\Database\Eloquent\Model::class)) {
            return true;                     // sem model nao da pra afirmar nada
        }

        $segment = trim(explode('->', $path)[0]);
        if (self::relationOn($modelClass, $segment) !== null) {
            return true;
        }

        // Convencao de FK: `<segmento>_id` + model `<Segmento>` conhecido.
        $studly = \Illuminate\Support\Str::studly($segment);
        if (\Mad\Database\ModelRegistry::resolve($studly) !== null) {
            return true;
        }

        return class_exists('App\\Models\\' . $studly);
    }

    /**
     * Calcula totalizadores por coluna. Retorna [field => htmlFormatado].
     *
     * @param array<int, array> $rows
     * @param GridColumn[] $columns
     * @return array<string, string>
     */
    public static function computeTotals(array $rows, array $columns): array
    {
        $totals = [];
        foreach (self::computeRawTotals($rows, $columns) as $i => $result) {
            $totals[$columns[$i]->field] = self::renderTotal($columns[$i], $result);
        }
        return $totals;
    }

    /**
     * Mesmo cálculo do computeTotals, SEM formatar: índice da coluna em
     * `$columns` => número. É o que o XLSX grava na célula de total — a
     * string "R$ 1.234,50" não soma no Excel.
     *
     * @param GridColumn[] $columns
     * @return array<int, int|float>
     */
    public static function computeRawTotals(array $rows, array $columns): array
    {
        $totals = [];
        foreach ($columns as $i => $col) {
            if (empty($col->totalFunc)) continue;

            $vals = array_column($rows, $col->field);
            $vals = array_map(fn($v) => is_numeric($v) ? (float)$v : 0.0, $vals);

            // Saldo acumulado não soma: somar os saldos linha a linha daria um
            // número sem significado. `sum` numa coluna `running` devolve o
            // ÚLTIMO saldo — que é o que o rodapé precisa mostrar.
            $func = $col->totalFunc;
            if ($func === 'sum' && $col->isRunning()) {
                $func = 'last';
            }

            $result = match ($func) {
                'sum'   => array_sum($vals),
                'avg'   => count($vals) > 0 ? array_sum($vals) / count($vals) : 0,
                'count' => count($vals),
                'min'   => count($vals) > 0 ? min($vals) : 0,
                'max'   => count($vals) > 0 ? max($vals) : 0,
                // Último valor NA ORDEM DE EXIBIÇÃO (as linhas já chegam nela).
                'last'  => count($vals) > 0 ? end($vals) : 0,
                default => 0,
            };

            $totals[$i] = $result;
        }
        return $totals;
    }

    /**
     * Texto do total como a grid mostra: `total-mask` ou o render da coluna.
     *
     * O número passa antes por um arredondamento de 10 casas — só o ruído do
     * ponto flutuante. O rodapé da tela soma no banco e a exportação soma em
     * PHP: as duas contas dão o mesmo valor a menos de 0,0000000000001, e sem
     * isto uma média que cai exatamente em meio centavo (179,315) saía 179,31
     * num lugar e 179,32 no outro.
     */
    public static function renderTotal(GridColumn $col, int|float $result): string
    {
        if (!empty($col->totalMask)) {
            return str_replace('{value}', (string)$result, $col->totalMask);
        }
        return $col->renderValue(is_float($result) ? round($result, 10) : $result, []);
    }

    /**
     * Consulta base de `model=` (+ a conexao do proprio model). Devolve null
     * quando a tag nao declara `model`.
     *
     * @return \Illuminate\Database\Eloquent\Builder|null
     */
    private static function _modelBaseQuery(array $config)
    {
        $model = is_string($config['model'] ?? null) ? trim($config['model']) : '';
        if ($model === '') {
            return null;
        }
        $cls = class_exists($model)
            ? $model
            : \Mad\Form\ModelOptionsLoader::resolveModelClass($model);

        return $cls::query();
    }

    /**
     * Resolve o `:query` quando ele chega como Closure. Duas formas:
     *
     *  - 0 parametros  → PROVEDOR lazy: `$q()` devolve o Builder pronto.
     *  - >=1 parametro → MODIFICADOR sobre a base de `model=`, a mesma
     *    semantica do `:where` (org-chart / sheet-col / PDV) e do `$filter` do
     *    MadDocRuntime: recebe o Builder, pode mutar in-place (retorno
     *    ignorado) ou devolver outro.
     *
     * Antes, QUALQUER closure era chamada sem argumento — uma de 1 parametro
     * (`fn($q) => $q->where(...)`, a forma que os geradores de filtro emitem)
     * derrubava a tela inteira com ArgumentCountError. Sem `model=` nao ha base
     * pra modificar: loga e devolve null, que o chamador degrada em tabela
     * vazia (mesma graca de uma fonte invalida) em vez de fatal.
     *
     * @return mixed Builder, ou null quando nao ha base pra modificar.
     */
    private static function _queryFromClosure(\Closure $q, array $config)
    {
        if ((new \ReflectionFunction($q))->getNumberOfParameters() === 0) {
            return $q();
        }

        $base = self::_modelBaseQuery($config);
        if ($base === null) {
            error_log(
                '[GridRenderHelpers::renderDataTable] :query recebeu uma closure de 1 argumento '
                . '(modificador) numa <mad-data-table> sem `model=`: sem consulta base pra filtrar, '
                . 'a tabela renderiza vazia. Passe um Builder pronto (:query="Model::query()->where(...)") '
                . 'ou declare model="...".'
            );

            return null;
        }

        $out = $q($base);

        return \Mad\Database\QuerySource::isQuery($out) ? $out : $base;
    }

    /**
     * Entry point do compilador <mad-data-table>.
     *
     * Resolve fonte de dados (precedencia: :query Builder pronto > model auto-query
     * > $items inline), normaliza, calcula totals, delega ao Blade `components.data-table`.
     * Com :query, herda periodo + filtros do <mad-dash-filters> como os demais widgets.
     *
     * @param array $config       Atributos do <mad-data-table> (query, items, model, database, filters, order-by, limit, group-by, no-totals, etc)
     * @param array $colConfigs   Array de configs de colunas (output do buildColConfig)
     * @return string HTML
     */
    public static function renderDataTable(array $config, array $colConfigs): string
    {
        // Construir GridColumn instances
        $columns = array_map(fn($c) => MadDataGrid::_colFromConfig($c), $colConfigs);

        // Resolve dados — precedencia: query (Builder pronto) > model (auto-query) > items (inline).
        $items = [];

        $order = !empty($config['order-by']) ? $config['order-by'] : null;
        $limit = (!empty($config['limit']) && (int)$config['limit'] > 0) ? (int)$config['limit'] : null;

        // A quebra e resolvida ANTES da consulta: chain em `group-by` precisa
        // virar renderField (pra materializar a chave na linha) e eager-load
        // (pra nao consultar a relacao uma vez por linha).
        $groupFields = self::_normalizeGroupFields($config['group-by'] ?? '');

        if (!empty($config['query'])) {
            // builder-native widget de dashboard: :query="$that->baseQuery('Model')->where(...)"
            // ja traz periodo + filtros do <mad-dash-filters> (igual mad-db-chart/metric-card).
            $q = $config['query'];
            if ($q instanceof \Closure) {
                $q = self::_queryFromClosure($q, $config);
            }
            if (\Mad\Database\QuerySource::isQuery($q)) {
                // clone-no-mutate: a mesma fonte pode alimentar outros widgets do painel.
                $q = clone $q;
                if (!empty($config['filters']) && is_array($config['filters'])) {
                    \Mad\Database\QuerySource::applyArrayFilters($q, $config['filters']);
                }
                self::_eagerLoadChains($q, $columns, $groupFields);
                $items = \Mad\Database\QuerySource::recordsFromQuery($q, $order, $limit);
            }
        } elseif (!empty($config['model'])) {
            // builder-native: aplica :filters no Query Builder via DSL.
            $q = self::_modelBaseQuery($config);

            if (!empty($config['filters']) && is_array($config['filters'])) {
                \Mad\Database\QuerySource::applyArrayFilters($q, $config['filters']);
            }
            self::_eagerLoadChains($q, $columns, $groupFields);
            // QuerySource usa a conexao do model (Eloquent) — abre conexao lazy.
            $items = \Mad\Database\QuerySource::recordsFromQuery($q, $order, $limit);
        } elseif (isset($config['items']) && is_array($config['items'])) {
            $items = $config['items'];
        }

        // Normaliza — render fields {relacao->campo} resolvem via lazy-load do Eloquent.
        // A chain da quebra entra junto: sem isso o `group-by="rel->col"` procuraria
        // uma chave que nao existe na linha e cairia num grupo vazio so.
        $renderFields = self::detectRenderFields($columns) + self::groupRenderFields($groupFields);
        $rows = self::normalizeRows($items, $renderFields);

        // Filtra colunas com display-condition false
        $visibleColumns = array_values(array_filter($columns, fn($c) => $c->checkDisplay()));

        // Totals (a menos que no-totals)
        $totals = [];
        if (empty($config['no-totals'])) {
            $totals = self::computeTotals($rows, $visibleColumns);
        }

        // Group hierarquico (suporta array para N niveis)
        $groupMaskRaw= $config['group-mask'] ?? '';
        $groupTotal  = !empty($config['group-total']);

        $groupMasks  = self::_normalizeGroupMasks($groupMaskRaw, count($groupFields));

        $groupData = !empty($groupFields)
            ? self::_flattenGroups($rows, $groupFields, $groupMasks, 0, $visibleColumns, $groupTotal)
            : [];

        $html = MadBlade::render('components.data-table', [
            'columns'    => $visibleColumns,
            'rows'       => $rows,
            'totals'     => $totals,
            'groupData'  => $groupData,
            'groupBy'    => $groupFields,
            'groupTotal' => $groupTotal,
            'emptyText'  => (string)($config['empty-text'] ?? 'Sem registros'),
            'zebra'      => $config['zebra'] ?? true,
            'bordered'   => !empty($config['bordered']),
            'compact'    => !empty($config['compact']),
            'class'      => (string)($config['class'] ?? ''),
        ]);

        return $html;
    }

    /**
     * Aplica o `with()` derivado das chains (colunas + quebra) num builder do
     * <mad-data-table>. Silencioso quando o builder nao e Eloquent.
     *
     * @param GridColumn[] $columns
     * @param string[]     $groupFields
     */
    private static function _eagerLoadChains($q, array $columns, array $groupFields): void
    {
        if (! ($q instanceof \Illuminate\Database\Eloquent\Builder)) {
            return;
        }
        $paths = array_merge(
            array_map(fn($c) => (string) $c->field, $columns),
            $groupFields
        );
        $with = self::deriveEagerLoads($paths, get_class($q->getModel()));
        if (! empty($with)) {
            $q->with($with);
        }
    }

    /**
     * Normaliza prop group-by: string com virgula OR array OR string simples.
     * @return string[]
     */
    private static function _normalizeGroupFields(mixed $raw): array
    {
        if (is_array($raw)) {
            $fields = array_values(array_filter(array_map('strval', $raw), fn($f) => $f !== ''));
        } else {
            $s = trim((string) $raw);
            if ($s === '') return [];
            $fields = str_contains($s, ',')
                ? array_values(array_filter(array_map('trim', explode(',', $s)), fn($f) => $f !== ''))
                : [$s];
        }

        // Sufixo de granularidade (`data|month`): desconhecido cai fora com aviso.
        return array_map(fn ($f) => self::normalizeGroupField((string) $f), $fields);
    }

    private static function _normalizeGroupMasks(mixed $raw, int $expected): array
    {
        if (is_array($raw)) return array_values($raw);
        $s = (string) $raw;
        if ($s === '') return array_fill(0, $expected, '');

        // `|` separa os NIVEIS ('{ano}|Mes {mes}'), mas so fora de `{...}`: o
        // mesmo caractere separa o formatador dentro do token ('{data|date}') e
        // um explode cru partia a mascara no meio, deixando o nivel 0 com o
        // pedaco '{data'.
        $levels = self::_splitMaskLevels($s);
        if (count($levels) > 1) {
            return $levels;
        }

        return array_fill(0, $expected, $s);
    }

    /**
     * Quebra a string de mascaras por nivel no `|` que esta FORA de `{...}`.
     *
     * @return string[]
     */
    private static function _splitMaskLevels(string $s): array
    {
        $out   = [];
        $buf   = '';
        $depth = 0;

        for ($i = 0, $n = strlen($s); $i < $n; $i++) {
            $ch = $s[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                if ($depth > 0) $depth--;
            } elseif ($ch === '|' && $depth === 0) {
                $out[] = trim($buf);
                $buf   = '';
                continue;
            }
            $buf .= $ch;
        }
        $out[] = trim($buf);

        return $out;
    }

    /**
     * Achata grupos aninhados em lista sequencial.
     *
     * Cada item:
     *   ['type'=>'group',       'level'=>N, 'label'=>'...', 'field'=>'...', 'value'=>'...', 'count'=>N, 'totals'=>[...]]
     *   ['type'=>'row',         'level'=>N, 'data'=>[...row...]]
     *   ['type'=>'group-total', 'level'=>N, 'label'=>'...', 'totals'=>[...]]
     */
    public static function _flattenGroups(
        array $rows, array $fields, array $masks, int $depth, array $columns, bool $groupTotal
    ): array {
        if (empty($fields)) {
            $items = [];
            foreach ($rows as $row) {
                $items[] = ['type' => 'row', 'level' => $depth, 'data' => $row];
            }
            return $items;
        }

        $field           = $fields[0];
        $mask            = $masks[0] ?? '';
        $remainingFields = array_slice($fields, 1);
        $remainingMasks  = array_slice($masks, 1);

        // Agrupa preservando ordem de aparicao
        $grouped = [];
        $order   = [];
        foreach ($rows as $row) {
            $val = (string)(self::rowValue($row, $field) ?? '');
            if (!array_key_exists($val, $grouped)) {
                $grouped[$val] = [];
                $order[]       = $val;
            }
            $grouped[$val][] = $row;
        }

        $items = [];
        foreach ($order as $val) {
            $groupRows = $grouped[$val];
            // Sem mascara, o rotulo e o proprio valor — ou a data do balde no
            // formato do locale, quando a quebra tem granularidade.
            $shown = self::groupDefaultLabel($field, $val);
            // A mascara pode citar o campo com ou sem chaves — `{rel->col}` e o
            // que o editor persiste, `rel->col` e o que o group-by declara — e,
            // na quebra granular, tambem `{data|day}` / `{data}`.
            $label = $mask
                ? str_replace(self::groupMaskTokens($field), $shown, $mask)
                : $shown;

            $groupTotals = $groupTotal ? self::computeTotals($groupRows, $columns) : [];

            $items[] = [
                'type'   => 'group',
                'level'  => $depth,
                'label'  => $label,
                'field'  => $field,
                'value'  => $val,
                'count'  => count($groupRows),
                'totals' => $groupTotals,
            ];

            foreach (self::_flattenGroups($groupRows, $remainingFields, $remainingMasks, $depth + 1, $columns, $groupTotal) as $child) {
                $items[] = $child;
            }

            if ($groupTotal) {
                $items[] = [
                    'type'   => 'group-total',
                    'level'  => $depth,
                    'label'  => $label,
                    'totals' => $groupTotals,
                ];
            }
        }

        return $items;
    }
}
