<?php
namespace Mad\Pdv;

/**
 * PdvPayment — VO de uma forma de pagamento do <mad-pdv>.
 *
 * Vem de <mad-pdv-payment method= label= icon= allow-change hotkey= /> (ordem
 * do documento = ordem dos botões) ou do fallback canônico quando o blade não
 * declara nenhuma.
 *
 * ## A regra da forma (Rev. 5)
 *
 * Além do rótulo e da tecla, a forma carrega a REGRA de para onde o dinheiro
 * vai — espelho do cadastro `forma_pagamento` de um ERP. O eixo não é à vista
 * × a prazo: é **quem deve e quando entra**. Dinheiro e pix ninguém deve, já
 * entraram. Débito e crédito a ADQUIRENTE deve, em D+1 e D+30. Crediário o
 * CLIENTE deve. É `sacado` que separa recebível de cartão de inadimplência de
 * cliente, e é por isso que os dois podem gerar título sem se misturarem.
 */
final class PdvPayment
{
    public string $method      = '';
    public string $label       = '';
    public string $icon        = '';
    public bool   $allowChange = false;
    public string $hotkey      = '';

    // ── Regra da forma (Rev. 5) ───────────────────────────────────────────

    /** Gera parcelas em contas a receber? */
    public bool   $geraTitulo  = false;
    /** Quem deve: nenhum | cliente | adquirente. */
    public string $sacado      = 'nenhum';
    /** Conta na conferência da gaveta (dinheiro sim, cartão não). */
    public bool   $entraNoCaixa = false;
    /** Bloqueia a finalização sem cliente identificado (crediário). */
    public bool   $exigeCliente = false;
    public int    $maxParcelas       = 1;
    /** Dias da venda até o 1º vencimento (0 = no ato, 1 = D+1). */
    public int    $prazoPrimeiraDias = 0;
    public int    $intervaloDias     = 30;
    /** Onde vai o resíduo de centavos: ultima (default) | primeira. */
    public string $ajusteResiduo     = 'ultima';
    public float  $taxaPercentual    = 0.0;
    public float  $taxaFixa          = 0.0;
    /** Rótulo/id do sacado quando sacado=adquirente. */
    public string $adquirente        = '';

    /** Ícones default por método conhecido (lucide). */
    private const DEFAULT_ICONS = [
        'dinheiro'       => 'banknote',
        'cartao_debito'  => 'credit-card',
        'cartao_credito' => 'credit-card',
        'pix'            => 'qr-code',
    ];

    public static function fromConfig(array $cfg): self
    {
        $p = new self();
        $p->method      = self::slug((string) ($cfg['method'] ?? ''));
        $p->label       = trim((string) ($cfg['label'] ?? ''));
        $p->icon        = trim((string) ($cfg['icon'] ?? ''));
        $p->allowChange = (bool) ($cfg['allowChange'] ?? false);
        $p->hotkey      = trim((string) ($cfg['hotkey'] ?? ''));

        $p->geraTitulo   = (bool) ($cfg['geraTitulo'] ?? false);
        $p->entraNoCaixa = (bool) ($cfg['entraNoCaixa'] ?? false);
        $p->exigeCliente = (bool) ($cfg['exigeCliente'] ?? false);
        $p->adquirente   = trim((string) ($cfg['adquirente'] ?? ''));

        $sacado     = strtolower(trim((string) ($cfg['sacado'] ?? 'nenhum')));
        $p->sacado  = in_array($sacado, ['cliente', 'adquirente'], true) ? $sacado : 'nenhum';

        $p->maxParcelas       = max(1, min(120, (int) ($cfg['maxParcelas'] ?? 1)));
        $p->prazoPrimeiraDias = max(0, (int) ($cfg['prazoPrimeiraDias'] ?? 0));
        $p->intervaloDias     = max(0, (int) ($cfg['intervaloDias'] ?? 30));
        $p->taxaPercentual    = max(0.0, (float) ($cfg['taxaPercentual'] ?? 0));
        $p->taxaFixa          = max(0.0, (float) ($cfg['taxaFixa'] ?? 0));

        $res = strtolower(trim((string) ($cfg['ajusteResiduo'] ?? 'ultima')));
        $p->ajusteResiduo = $res === 'primeira' ? 'primeira' : 'ultima';

        // Título sem sacado não tem de quem cobrar: assume adquirente quando
        // há parcelamento (cartão) e cliente quando não há (crediário 1x).
        if ($p->geraTitulo && $p->sacado === 'nenhum') {
            $p->sacado = $p->maxParcelas > 1 ? 'adquirente' : 'cliente';
        }

        if ($p->label === '' && $p->method !== '') {
            $p->label = ucfirst(str_replace('_', ' ', $p->method));
        }
        if ($p->icon === '') {
            $p->icon = self::DEFAULT_ICONS[$p->method] ?? 'wallet';
        }

        return $p;
    }

    /**
     * Fallback do runtime quando o blade não declara nenhuma
     * <mad-pdv-payment> (espelha os defaults do generator do builder).
     *
     * @return self[]
     */
    public static function defaults(): array
    {
        return array_map([self::class, 'fromConfig'], [
            // ⚠️ O fallback NÃO liga `gera-titulo`. O seed do ERP de
            // referência liga para débito e crédito, mas aqui isso tornaria
            // inválido todo PDV que não configurou contas a receber — e o
            // fallback existe justamente para quem não configurou nada.
            // Financeiro é opt-in explícito; o wizard é que sugere a regra.
            ['method' => 'dinheiro', 'label' => 'Dinheiro', 'allowChange' => true,
             'entraNoCaixa' => true, 'hotkey' => '1'],
            ['method' => 'cartao_debito',  'label' => 'Cartão de Débito',  'hotkey' => '2'],
            ['method' => 'cartao_credito', 'label' => 'Cartão de Crédito', 'hotkey' => '3'],
            ['method' => 'pix',            'label' => 'Pix',               'hotkey' => '4'],
        ]);
    }

    /** Shape que vai pro x-data (§6 da spec). */
    public function toClientConfig(): array
    {
        return [
            'method'      => $this->method,
            'label'       => $this->label,
            'icon'        => $this->icon,
            'allowChange' => $this->allowChange,
            'hotkey'      => $this->hotkey,
            // O cliente precisa disto para o seletor de parcelas e o preview.
            // Nada aqui é nome de tabela ou coluna.
            'maxInstallments'   => $this->maxParcelas,
            'firstDueDays'      => $this->prazoPrimeiraDias,
            'intervalDays'      => $this->intervaloDias,
            'residue'           => $this->ajusteResiduo,
            'requiresCustomer'  => $this->exigeCliente,
        ];
    }

    /** method vira código estável: minúsculo, [a-z0-9_]. */
    private static function slug(string $method): string
    {
        $method = strtolower(trim($method));
        return preg_replace('/[^a-z0-9_]+/', '_', $method) ?? '';
    }
}
