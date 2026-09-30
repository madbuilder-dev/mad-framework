<?php

namespace Mad\Form;

use Illuminate\Support\Carbon;

/**
 * MadFillTransform — DSL declarativa server-side aplicada ao valor de um
 * `<fill ... transform="...">` antes de devolvê-lo ao formulário.
 *
 * Vocabulário fixo (nada de eval), encadeável com `|` (aplica esquerda→direita):
 *
 *   upper            → MAIÚSCULAS
 *   lower            → minúsculas
 *   title            → Primeira Letra De Cada Palavra
 *   trim             → remove espaços das pontas
 *   date:FMT         → reformata data (Carbon::parse → format($fmt); default d/m/Y)
 *   money            → number_format pt-BR, 2 casas (1234.5 → "1.234,50")
 *   number:N         → number_format pt-BR, N casas
 *   mask:PADRÃO      → preenche placeholders (#, 9, A) do padrão; ex mask:###.###-##
 *   Classe::metodo   → callable do dev: metodo(string $valor, ?object $registro): string
 *                      Forma curta gerada pelo MadBuilder: 'FillTransformer::formataCpf'
 *                      (sem namespace) → resolvida para \App\Transformer\FillTransformer.
 *
 * Regras de borda:
 *   - input é SEMPRE string (MadRecordPath casta via (string));
 *   - valor vazio  → retorna '' (não tenta transformar);
 *   - spec vazio   → passthrough (valor inalterado);
 *   - spec desconhecido / callable inválido / callable que lança → passthrough +
 *     error_log (um typo não pode dar 500 e matar TODO o auto-fill).
 *
 * Segurança do callable: o spec viaja CIFRADO no token (MadStateCrypt), gerado no
 * render a partir do <fill> do Blade — o cliente não injeta. Mesmo nível de
 * confiança do navigate="Classe::metodo" do framework.
 */
class MadFillTransform
{
    /**
     * @param array|null $warnings Buffer por-referência: se fornecido, cada falha
     *                             (callable inválido/que lança, spec desconhecido,
     *                             data não-parseável) é acumulada aqui p/ o caller
     *                             devolver ao dev (além do error_log).
     */
    public static function apply(string $spec, string $value, ?object $record = null, ?array &$warnings = null): string
    {
        $spec = trim($spec);
        if ($spec === '')   return $value;
        if ($value === '')  return '';

        foreach (explode('|', $spec) as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;

            // Callable do dev: "Classe::metodo" (um '::' nunca aparece na DSL).
            if (strpos($seg, '::') !== false) {
                // Forma curta das classes de contexto geradas pelo MadBuilder
                // ('FillTransformer::formataCpf') → prefixa \App\Transformer\.
                if (preg_match('/^(DocumentTransformer|GridTransformer|FillTransformer)::[A-Za-z0-9_]+$/', $seg)) {
                    $seg = '\\App\\Transformer\\' . $seg;
                }
                if (is_callable($seg)) {
                    try {
                        $value = (string) call_user_func($seg, $value, $record);
                    } catch (\Throwable $e) {
                        self::warn($warnings, "callable '{$seg}' lançou: " . $e->getMessage() . ' (passthrough)');
                    }
                } else {
                    self::warn($warnings, "callable inválido: '{$seg}' (passthrough)");
                }
                continue;
            }

            [$name, $arg] = array_pad(explode(':', $seg, 2), 2, '');
            $value = self::applyOne(strtolower(trim($name)), $arg, $value, $warnings);
        }

        return $value;
    }

    /** Acumula um aviso no buffer do caller (se houver) e mantém o trilho no error_log. */
    private static function warn(?array &$warnings, string $msg): void
    {
        if ($warnings !== null) {
            $warnings[] = $msg;
        }
        error_log('[MadFillTransform] ' . $msg);
    }

    private static function applyOne(string $name, string $arg, string $value, ?array &$warnings = null): string
    {
        switch ($name) {
            case 'upper':
                return mb_strtoupper($value, 'UTF-8');
            case 'lower':
                return mb_strtolower($value, 'UTF-8');
            case 'title':
                return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
            case 'trim':
                return trim($value);

            case 'date':
                $fmt = $arg !== '' ? $arg : 'd/m/Y';
                try {
                    return Carbon::parse($value)->format($fmt);
                } catch (\Throwable $e) {
                    self::warn($warnings, "date: valor não parseável '{$value}' (passthrough)");
                    return $value;
                }

            case 'money':
                return number_format((float) $value, 2, ',', '.');

            case 'number':
                $n = ctype_digit($arg) ? (int) $arg : 0;
                return number_format((float) $value, $n, ',', '.');

            case 'mask':
                return $arg !== '' ? self::applyMask($arg, $value) : $value;

            default:
                self::warn($warnings, "transform desconhecido: '{$name}' (passthrough)");
                return $value;
        }
    }

    /**
     * Preenche os placeholders (#, 9, A) do padrão com os caracteres
     * alfanuméricos do valor, copiando os literais do padrão.
     * Ex: applyMask('###.###.###-##', '12345678909') => '123.456.789-09'
     */
    private static function applyMask(string $pattern, string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9]/', '', $value);
        $len   = strlen($clean);
        if ($len === 0) return '';

        $out = '';
        $i   = 0;
        foreach (str_split($pattern) as $pc) {
            $isPlaceholder = ($pc === '#' || $pc === '9' || $pc === 'A');
            if ($i >= $len) break;        // sem mais chars: para (não emite literais órfãos)
            if ($isPlaceholder) {
                $out .= $clean[$i];
                $i++;
            } else {
                $out .= $pc;
            }
        }

        return $out;
    }
}
