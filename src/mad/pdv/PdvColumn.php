<?php

namespace Mad\Pdv;

use Mad\Grid\GridColumn;
use Mad\Grid\GridRenderHelpers;
use Mad\Support\CssUnits;

/**
 * Coluna do carrinho do PDV — vem de `<mad-pdv-column>`.
 *
 * ## A chave opaca
 *
 * O carrinho é renderizado no CLIENTE, então o valor de cada coluna precisa
 * chegar ao navegador. O NOME da coluna, não: `getClientConfig()` nunca
 * carrega model/tabela/coluna (§6 da spec, travado por teste). Cada coluna
 * recebe uma chave opaca (`c0`, `c1`, …) atribuída pelo componente na ordem de
 * declaração; o mapa chave → coluna vive só no cache de sessão.
 *
 * Isso não é só higiene: é o que fecha o mass-assignment das colunas de
 * entrada. A gravação itera a lista do SERVIDOR e lê `cols[chave]` — uma chave
 * inventada no payload nunca é gravada, porque nunca é procurada. Não há
 * allowlist de nomes para manter em dia.
 *
 * ## O transform
 *
 * Roda no servidor, sempre. E é contido como no documento e no autofill
 * (captura + valor cru + log), NÃO como no grid, que chama `call_user_func`
 * sem try/catch: um typo de transformer não pode fechar o caixa.
 */
final class PdvColumn
{
    /** Chave opaca (c0, c1…) — o único identificador que o cliente conhece. */
    public string $key = '';

    public string $field      = '';
    public string $label      = '';
    public string $width      = '';
    public string $align      = 'left';
    public string $slot       = 'before-total';
    public string $transform  = '';
    /** `display` (só a célula) | `stored` (a saída também é o valor gravado). */
    public string $transformTarget = 'display';
    /** `''` | `price` — com `stored`, a saída vira o preço unitário efetivo. */
    public string $affects    = '';
    public string $mode       = 'display';
    public string $inputType  = 'text';
    public string $options    = '';
    public string $itemField  = '';
    public bool   $required   = false;
    public int    $maxlength  = 120;
    public string $default    = '';
    public bool   $mergeIgnore = false;
    public bool   $receipt    = false;

    private const ALIGNS = ['left', 'center', 'right'];
    private const SLOTS  = ['after-product', 'before-total'];
    private const INPUTS = ['text', 'number', 'date', 'combo'];

    /** Transformers que já falharam neste request (log uma vez só por coluna). */
    private static array $warned = [];

    public static function fromConfig(array $cfg, string $key): self
    {
        $c = new self();
        $c->key = $key;

        $c->field     = trim((string) ($cfg['field'] ?? ''));
        $c->label     = trim((string) ($cfg['label'] ?? '')) ?: self::humanize($c->field);
        $c->width     = CssUnits::length((string) ($cfg['width'] ?? ''));
        $c->transform = trim((string) ($cfg['transform'] ?? ''));
        $c->transformTarget = ($cfg['transformTarget'] ?? '') === 'stored' ? 'stored' : 'display';
        $c->affects   = ($cfg['affects'] ?? '') === 'price' ? 'price' : '';
        $c->options   = trim((string) ($cfg['options'] ?? ''));
        $c->itemField = trim((string) ($cfg['itemField'] ?? ''));
        $c->default   = (string) ($cfg['default'] ?? '');

        $align = strtolower(trim((string) ($cfg['align'] ?? 'left')));
        $c->align = in_array($align, self::ALIGNS, true) ? $align : 'left';

        $slot = strtolower(trim((string) ($cfg['slot'] ?? 'before-total')));
        $c->slot = in_array($slot, self::SLOTS, true) ? $slot : 'before-total';

        $c->mode = ($cfg['mode'] ?? '') === 'input' ? 'input' : 'display';

        $it = strtolower(trim((string) ($cfg['inputType'] ?? 'text')));
        $c->inputType = in_array($it, self::INPUTS, true) ? $it : 'text';

        $c->required    = (bool) ($cfg['required'] ?? false);
        $c->receipt     = (bool) ($cfg['receipt'] ?? false);
        $c->mergeIgnore = (bool) ($cfg['mergeIgnore'] ?? false);
        $c->maxlength   = max(1, min(2000, (int) ($cfg['maxlength'] ?? 120)));

        return $c;
    }

    /** O transform desta coluna manda no preço unitário? */
    public function affectsPrice(): bool
    {
        return $this->affects === 'price'
            && $this->transformTarget === 'stored'
            && $this->transform !== '';
    }

    /** O valor transformado é o que grava (e não só o que aparece)? */
    public function storesTransformed(): bool
    {
        return $this->transformTarget === 'stored' && $this->transform !== '';
    }

    public function isEditable(): bool
    {
        return $this->mode === 'input';
    }

    /** `field` com `{}` / `->` é caminho por relacionamento, não coluna. */
    public function isChain(): bool
    {
        return str_contains($this->field, '{') || str_contains($this->field, '->');
    }

    /** Primeiro segmento da chain — o que precisa de eager-load. */
    public function chainRoot(): string
    {
        if (!$this->isChain()) {
            return '';
        }
        $inner = trim($this->field, '{}');
        $parts = explode('->', $inner);

        return count($parts) > 1 ? trim($parts[0]) : '';
    }

    /** Coluna declarada sem `field` e sem entrada não tem o que mostrar. */
    public function isUsable(): bool
    {
        if ($this->isEditable()) {
            return true;
        }
        if ($this->field === '') {
            return false;
        }

        return $this->isChain()
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->field) === 1;
    }

    /** Valor CRU lido do produto (chain resolvida por template). */
    public function resolveValue(object $rec): mixed
    {
        if ($this->field === '') {
            return null;
        }
        if (!$this->isChain()) {
            return $rec->{$this->field} ?? null;
        }

        // `resolveTemplate` não captura: uma relação quebrada explodiria o
        // lookup inteiro, e o caixa pararia por causa de uma coluna decorativa.
        try {
            $pattern = str_starts_with($this->field, '{') ? $this->field : '{' . $this->field . '}';

            return GridRenderHelpers::resolveTemplate($pattern, $rec, []);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Valor RENDERIZADO — passa pelo transform quando houver. */
    public function display(mixed $value, object $rec): string
    {
        if ($this->transform === '') {
            return $value === null ? '' : (string) $value;
        }

        return $this->applyTransform($value, $rec);
    }

    /**
     * Aplica o transformer. Degrada para o valor cru em qualquer falha —
     * padrão do documento (`MadDocRuntime`) e do autofill
     * (`MadFillTransform`), nunca o do grid, que deixa a exceção subir.
     */
    public function applyTransform(mixed $value, object $rec): string
    {
        $raw = $value === null ? '' : (string) $value;

        $fn = GridColumn::resolveTransformRef($this->transform);
        if (!is_callable($fn)) {
            $this->warnOnce("transformer '{$this->transform}' não é chamável");

            return $raw;
        }

        try {
            $row = method_exists($rec, 'toArray') ? $rec->toArray() : [];

            return (string) call_user_func($fn, $value, $rec, $row, null, null);
        } catch (\Throwable $e) {
            $this->warnOnce("transformer '{$this->transform}' lançou: " . $e->getMessage());

            return $raw;
        }
    }

    private function warnOnce(string $msg): void
    {
        $k = $this->key . '|' . $this->transform;
        if (isset(self::$warned[$k])) {
            return;
        }
        self::$warned[$k] = true;
        error_log("[mad-pdv] coluna {$this->key}: {$msg} — usando o valor cru.");
    }

    /** Pares `a:Rótulo|b:Outro` do input tipo lista. */
    public function optionList(): array
    {
        if ($this->options === '') {
            return [];
        }
        $out = [];
        foreach (explode('|', $this->options) as $pair) {
            $parts = explode(':', $pair, 2);
            $val   = trim($parts[0]);
            if ($val === '') {
                continue;
            }
            $out[] = ['value' => $val, 'label' => trim($parts[1] ?? $val)];
        }

        return $out;
    }

    /** O que o CLIENTE recebe — sem `field`, sem `itemField`, sem transform. */
    public function toClientConfig(): array
    {
        return [
            'key'       => $this->key,
            'label'     => $this->label,
            'width'     => $this->width,
            'align'     => $this->align,
            'slot'      => $this->slot,
            'input'     => $this->isEditable() ? $this->inputType : null,
            'options'   => $this->isEditable() && $this->inputType === 'combo' ? $this->optionList() : null,
            'required'  => $this->required,
            'maxlength' => $this->maxlength,
            'default'   => $this->default,
        ];
    }

    private static function humanize(string $field): string
    {
        $leaf = $field;
        if (str_contains($leaf, '->')) {
            $parts = explode('->', trim($leaf, '{}'));
            $leaf  = (string) end($parts);
        }
        $leaf = trim($leaf, '{} ');

        return $leaf === '' ? '' : ucfirst(str_replace('_', ' ', $leaf));
    }
}
