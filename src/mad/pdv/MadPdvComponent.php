<?php
namespace Mad\Pdv;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Laravel\SerializableClosure\SerializableClosure;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Database\TenantContext;
use Mad\Database\UnitContext;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;
use Mad\Http\MadStateCrypt;

/**
 * MadPdvComponent — frente de caixa (POS) genérica e configurável.
 *
 * Carrinho 100% client-side (Alpine, centavos inteiros); o servidor é tocado
 * em exatamente 2 momentos: lookup de produto (bip/busca) e finalização
 * transacional (venda + itens + pagamentos + baixa de estoque). Contrato
 * completo (props, payloads, fórmulas, erros): spec `docs/specs/mad-pdv-v1.md`
 * (Rev. 2) no monorepo da plataforma.
 *
 * Segurança:
 *   - config vem SEMPRE do cache de sessão ($pdvCfgKey) — o client nunca
 *     envia model/tabela/coluna/conexão;
 *   - models resolvidos via ModelOptionsLoader::resolveModelClass (registry);
 *   - persistência via fill() → respeita $fillable/$guarded; só campos
 *     MAPEADOS são escritos; operador vem da sessão, nunca do payload;
 *   - closures (:where) vão pra sessão embrulhadas em SerializableClosure
 *     (padrão MadOrgChart — nunca closure crua);
 *   - config incompleta → painel de erro sempre visível (nunca tela branca).
 */
abstract class MadPdvComponent extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    // ── Produto (busca/bip) ───────────────────────────────────────────────

    /** Model Eloquent do produto (short name ou FQCN). Obrigatório. */
    protected string $model = '';

    /** Connection. Default: MAIN_DATABASE. */
    protected string $database = '';

    protected string $nameField    = '';
    protected string $priceField   = '';
    protected string $codeField    = '';
    protected string $barcodeField = '';
    protected string $stockField   = '';
    protected string $unitField    = '';
    protected string $imageField   = '';
    /** Coluna de "ativo" do produto. Vazio = sem filtro de ativo. */
    protected string $activeField  = '';
    /**
     * Marcador de "ativo". Com o marcador booleano padrão (`1`/`true`/`T`) a
     * coluna é lida de forma TOLERANTE — ver `_activeMarkers()`. Um marcador
     * próprio (ex.: `A` de uma coluna de status) continua exigindo igualdade
     * exata.
     */
    protected string $activeValue  = '1';
    protected string $orderBy      = '';

    /** Escopo do produto: DSL string ('ativo=1|tipo=P') OU Closure via :where. */
    protected string    $where        = '';
    protected ?\Closure $whereClosure = null;

    protected int $searchMinLength = 2;
    protected int $searchLimit     = 10;

    /** Cap rígido do servidor pro lookup (o attr nunca passa disso). */
    protected const SEARCH_LIMIT_CAP = 20;

    /** Teto do modo `browse` (catálogo sem termo). Maior que a busca porque a
     *  tela é uma grade navegável, mas ainda um teto — sem ele, `browse` num
     *  catálogo grande vira varredura de tabela por clique. */
    protected const BROWSE_LIMIT_CAP = 60;

    /** Teto de entradas de pagamento por venda (× parcelas = inserts). */
    protected const MAX_PAYMENT_ENTRIES = 20;

    // ── Persistência da venda (defaults = esquema canônico) ───────────────

    protected string $saleModel         = '';
    /** Conexões das tabelas de venda (fallback; a transação usa a do model). */
    protected string $saleDatabase      = '';
    protected string $itemDatabase      = '';
    protected string $paymentDatabase   = '';
    protected string $saleTotalField    = 'total';
    protected string $saleSubtotalField = 'subtotal';
    protected string $saleDiscountField = 'desconto';
    protected string $saleDatetimeField = 'data_hora';
    protected string $saleCustomerField = 'cliente_id';
    protected string $saleOperatorField = 'operador_id';
    protected string $saleStatusField   = 'status';
    protected string $saleStatusDone    = 'concluida';
    protected string $saleDocumentField = 'documento';
    protected string $saleChangeField   = 'troco';
    protected string $saleUuidField     = 'client_uuid';

    protected string $itemModel         = '';
    protected string $itemSaleField     = 'venda_id';
    protected string $itemProductField  = 'produto_id';
    protected string $itemQtyField      = 'quantidade';
    protected string $itemPriceField    = 'preco_unitario';
    protected string $itemDiscountField = 'desconto';
    protected string $itemTotalField    = 'total';

    protected string $paymentModel         = '';
    protected string $paymentSaleField     = 'venda_id';
    protected string $paymentMethodField   = 'forma';
    protected string $paymentAmountField   = 'valor';
    protected string $paymentTenderedField = 'valor_recebido';

    /** Snapshot da regra na linha de pagamento (Rev. 5) — só se mapeado. */
    protected string $paymentGeneratesTitleField = '';
    protected string $paymentSacadoField         = '';
    protected string $paymentInstallmentsField   = '';
    protected string $paymentNetField            = '';

    // ── Contas a receber (Rev. 5) ─────────────────────────────────────────
    //
    // Cada linha É UMA PARCELA — não há tabela de "título" separada. O
    // cabeçalho natural é a linha de pagamento que a gerou; o agrupamento
    // humano é o `documento`. Vazio em receivable-model = feature desligada.

    protected string $receivableModel        = '';
    protected string $receivableDatabase     = '';
    protected string $receivableSaleField    = 'venda_id';
    protected string $receivablePaymentField = '';
    protected string $receivableCustomerField = 'cliente_id';
    protected string $receivableAcquirerField = '';
    protected string $receivableSacadoField  = 'sacado_tipo';
    protected string $receivableDocField     = 'documento';
    protected string $receivableNumberField  = 'parcela';
    protected string $receivableCountField   = 'total_parcelas';
    protected string $receivableAmountField  = 'valor';
    protected string $receivableFeeField     = '';
    protected string $receivableDueField     = 'vencimento';
    protected string $receivableDueOriginalField = '';
    protected string $receivableIssueField   = '';
    protected string $receivableMethodField  = 'forma';
    protected string $receivableStatusField  = 'status';
    protected string $receivableStatusOpen   = 'aberto';
    protected string $receivableOriginField  = '';
    protected string $receivableUuidField    = '';

    // ── Busca de produto sem leitor (Rev. 5) ──────────────────────────────

    /** 'auto' funde o mesmo produto re-bipado; 'off' = uma linha por bip. */
    protected string $mergeLines            = 'auto';

    /**
     * Catálogo navegável ligado por padrão: caixa sem leitor (e o operador
     * que não sabe o código de cor) só tinha a scan bar, que exige termo.
     * `product-picker="false"` desliga.
     */
    protected bool   $productPicker         = true;
    /** F2 é a tecla que a legenda do rodapé sempre anunciou como "Produto". */
    protected string $productPickerHotkey   = 'F2';
    protected int    $productPickerPageSize = 24;

    // ── Cliente (opcional) ────────────────────────────────────────────────

    protected string    $customerModel        = '';
    /** Conexão da tabela de clientes (fallback). */
    protected string    $customerDatabase     = '';
    protected string    $customerKey          = '';
    protected string    $customerDisplay      = '{nome}';
    /** Ordem do dropdown de clientes ("col [asc|desc]"; '' = ordem do banco). */
    protected string    $customerOrderBy      = '';
    protected string    $customerWhere        = '';
    protected ?\Closure $customerWhereClosure = null;
    protected bool      $customerRequired     = false;
    protected ?int      $customerDefaultId    = null;

    // ── Fontes RELACIONADAS de preço/estoque (Rev. 3 §3.1-bis) ────────────
    // Default '' = price-field/stock-field são colunas da tabela do PRODUTO
    // (comportamento v1). Model setado REDIRECIONA a fonte: o *-field passa
    // a ser coluna da tabela relacionada, ligada ao produto pela *-key.

    protected string    $priceModel        = '';
    /** Conexão da tabela de preço (fallback; o model manda quando declara). */
    protected string    $priceDatabase     = '';
    protected string    $priceKey          = 'produto_id';
    protected string    $priceWhere        = '';
    protected ?\Closure $priceWhereClosure = null;
    /** Qual linha vence com várias por produto ("col dir"; '' = só PK desc). */
    protected string    $priceOrder        = '';

    protected string    $stockModel        = '';
    /** Conexão da tabela de saldo (fallback). */
    protected string    $stockDatabase     = '';
    protected string    $stockKey          = 'produto_id';
    protected string    $stockWhere        = '';
    protected ?\Closure $stockWhereClosure = null;

    // ── Comportamento ─────────────────────────────────────────────────────

    /** none|item|total|both — ONDE o desconto pode ser aplicado. */
    protected string $discountMode = 'both';

    /**
     * COMO o operador digita o desconto: money|percent|both|off.
     * `both` mostra um toggle R$/% no campo; `off` desliga o desconto
     * inteiro (vence o discount-mode). A conversão %→R$ é client-side —
     * o payload do finalize só conhece valores ABSOLUTOS (§7.6).
     */
    protected string $discountInput = 'money';

    /** Teto único de desconto (% sobre o subtotal). null = sem teto. */
    protected ?float $maxDiscountPercent = null;

    protected bool $allowPriceOverride = false;
    protected bool $allowFraction      = false;

    /** off|warn|block. null = auto: warn com stock-field, off sem. */
    protected ?string $stockMode = null;

    /** null = auto: true quando stock-field mapeado. */
    protected ?bool $stockDecrement = null;

    protected bool   $askDocument   = false;
    protected bool   $holdSales     = true;
    protected int    $holdLimit     = 10;
    protected string $printMode     = 'browser';
    protected int    $receiptWidth  = 80;
    protected string $receiptHeader = '';
    protected string $receiptFooter = '';
    protected bool   $autoPrint     = false;

    /** Método do host chamado pós-commit (além do hook afterSale). */
    protected string $onFinalized = '';

    // ── Visual ────────────────────────────────────────────────────────────

    /** Attr `title` da tag (MadComponent::$title é static — nome próprio aqui). */
    protected string $pdvTitle         = '';
    protected bool   $fullscreenToggle = true;
    protected string $currencySymbol   = 'R$';
    protected string $locale           = '';
    protected string $density          = 'normal';

    /** null = auto: true quando image-field mapeado. */
    protected ?bool $showImages = null;

    // ── Declarativos (sub-tags) ───────────────────────────────────────────

    /** Arrays crus vindos do compiler (<mad-pdv-payment>). */
    protected array $payments = [];

    /** Arrays crus vindos do compiler (<mad-pdv-action>). */
    protected array $actions = [];

    /** Config crua de `<mad-pdv-column>` (Rev. 5); vira VO em getColumns(). */
    protected array $columns = [];

    /** @var array<string, PdvColumn>|null memo chave opaca → coluna */
    private ?array $columnVOs = null;

    /**
     * Chave do cache de sessão da config inline. Pública de propósito:
     * entra no state do wire (mesmo padrão MadSheet::$shCfgKey).
     */
    public string $pdvCfgKey = '';

    /** Host externo quando standalone — actions custom roteiam pra ele. */
    protected ?object $_externalHost = null;

    /** Memo de configErrors() (avaliação única por request + log único). */
    private ?array $_cfgErrors = null;

    /** Cache por request de column-listing (conexão|tabela → colunas). */
    private static array $_colListing = [];

    /** @internal — usado pelo MadPdvCompiler quando o host não é MadPdvComponent. */
    public function _setExternalHost(object $host): void
    {
        $this->_externalHost = $host;
    }

    // ── Lifecycle ─────────────────────────────────────────────────────────

    /**
     * `pdvCfgKey` aponta para a config deste PDV guardada na sessão e viaja no
     * estado cifrado: só o servidor a escreve. Como prop pública, ela também
     * aceitava valor mandado pelo navegador junto dos campos — e a requisição
     * seguinte montava esta tela com a config de OUTRA aberta na mesma sessão.
     */
    protected function _lockedStateProps(): array
    {
        return array_merge(parent::_lockedStateProps(), ['pdvCfgKey']);
    }

    public function hydrate(): void
    {
        // Caixa lê produto, preço, estoque e cliente da empresa/unidade ATIVA,
        // mesmo para o administrador com a visão de todas as empresas.
        \Mad\Database\AdminScope::strict();

        // O state do wire só carrega props públicas — a config declarativa
        // vem dos attrs do <mad-pdv> e é cacheada em sessão pelo
        // _renderInlinePdv. Restaura antes de qualquer action.
        $cfg = $this->pdvCfgKey !== ''
            ? session('mad_pdv_cfg.' . $this->pdvCfgKey)
            : null;
        if (!is_array($cfg)) {
            $latest = session('mad_pdv_cfg_latest.' . static::class);
            $cfg    = is_string($latest) && $latest !== '' ? session('mad_pdv_cfg.' . $latest) : null;
        }
        if (is_array($cfg)) {
            $this->_applyInlineConfig($cfg);
        }
    }

    protected function view(): string|array
    {
        return ['components.pdv', ['__component' => $this]];
    }

    // ── Render inline (MadPdvCompiler) ────────────────────────────────────

    public function _renderInlinePdv(array $config): string
    {
        // Antes de qualquer leitura (cliente padrão, SQL da busca de cliente
        // que vai no token): o caixa é sempre da empresa/unidade ATIVA.
        \Mad\Database\AdminScope::strict();

        $this->_applyInlineConfig($config);

        // Closures não passam por json_encode (chave) nem serialize cru
        // (sessão) — vão embrulhadas em SerializableClosure; a chave usa um
        // hash estável do wrap (padrão MadOrgChart, NÃO a closure crua do
        // sheet/kanban).
        $cfgSession = $config;
        foreach (['where', 'customerWhere', 'priceWhere', 'stockWhere'] as $k) {
            if (($config[$k] ?? null) instanceof \Closure) {
                $cfgSession[$k] = new SerializableClosure($config[$k]);
                $config[$k]     = md5(serialize($cfgSession[$k]));
            }
        }

        $this->pdvCfgKey = md5(static::class . '|' . json_encode($config));
        session([
            'mad_pdv_cfg.' . $this->pdvCfgKey     => $cfgSession,
            'mad_pdv_cfg_latest.' . static::class => $this->pdvCfgKey,
        ]);

        return \Mad\View\MadBlade::render('components.pdv', ['__component' => $this]);
    }

    protected function _applyInlineConfig(array $config): void
    {
        static $strings = [
            'model', 'database', 'nameField', 'priceField', 'codeField',
            'barcodeField', 'stockField', 'unitField', 'imageField',
            'activeField', 'activeValue', 'orderBy',
            'productPickerHotkey', 'mergeLines',
            'paymentGeneratesTitleField', 'paymentSacadoField',
            'paymentInstallmentsField', 'paymentNetField',
            'receivableModel', 'receivableDatabase', 'receivableSaleField',
            'receivablePaymentField', 'receivableCustomerField',
            'receivableAcquirerField', 'receivableSacadoField',
            'receivableDocField', 'receivableNumberField', 'receivableCountField',
            'receivableAmountField', 'receivableFeeField', 'receivableDueField',
            'receivableDueOriginalField', 'receivableIssueField',
            'receivableMethodField', 'receivableStatusField',
            'receivableStatusOpen', 'receivableOriginField', 'receivableUuidField',
            'priceModel', 'priceDatabase', 'priceKey', 'priceOrder',
            'stockModel', 'stockDatabase', 'stockKey',
            'saleDatabase', 'itemDatabase', 'paymentDatabase', 'customerDatabase',
            'saleModel', 'saleTotalField', 'saleSubtotalField',
            'saleDiscountField', 'saleDatetimeField', 'saleCustomerField',
            'saleOperatorField', 'saleStatusField', 'saleStatusDone',
            'saleDocumentField', 'saleChangeField', 'saleUuidField',
            'itemModel', 'itemSaleField', 'itemProductField', 'itemQtyField',
            'itemPriceField', 'itemDiscountField', 'itemTotalField',
            'paymentModel', 'paymentSaleField', 'paymentMethodField',
            'paymentAmountField', 'paymentTenderedField',
            'customerModel', 'customerKey', 'customerDisplay',
            'customerOrderBy', 'discountMode', 'discountInput', 'printMode', 'receiptHeader',
            'receiptFooter', 'onFinalized', 'currencySymbol',
            'locale', 'density',
        ];
        static $bools = [
            'customerRequired', 'allowPriceOverride', 'allowFraction',
            'askDocument', 'holdSales', 'autoPrint', 'fullscreenToggle',
            'productPicker',
        ];
        static $ints = ['searchMinLength', 'searchLimit', 'holdLimit', 'receiptWidth', 'productPickerPageSize'];

        foreach ($strings as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (string) $config[$k];
            }
        }
        foreach ($bools as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (bool) $config[$k];
            }
        }
        foreach ($ints as $k) {
            if (array_key_exists($k, $config)) {
                $this->$k = (int) $config[$k];
            }
        }

        if (array_key_exists('title', $config)) {
            $this->pdvTitle = (string) $config['title'];
        }
        if (array_key_exists('stockMode', $config)) {
            $this->stockMode = $config['stockMode'] === null ? null : (string) $config['stockMode'];
        }
        if (array_key_exists('stockDecrement', $config)) {
            $this->stockDecrement = $config['stockDecrement'] === null ? null : (bool) $config['stockDecrement'];
        }
        if (array_key_exists('showImages', $config)) {
            $this->showImages = $config['showImages'] === null ? null : (bool) $config['showImages'];
        }
        if (array_key_exists('maxDiscountPercent', $config)) {
            $v = $config['maxDiscountPercent'];
            $this->maxDiscountPercent = ($v === null || $v === '') ? null : max(0.0, (float) $v);
        }
        if (array_key_exists('customerDefaultId', $config)) {
            $v = $config['customerDefaultId'];
            $this->customerDefaultId = ($v === null || $v === '') ? null : (int) $v;
        }
        if (isset($config['payments']) && is_array($config['payments'])) {
            $this->payments = $config['payments'];
        }
        if (isset($config['columns']) && is_array($config['columns'])) {
            $this->columns   = $config['columns'];
            $this->columnVOs = null;
        }
        if (isset($config['actions']) && is_array($config['actions'])) {
            $this->actions = $config['actions'];
        }

        // where/customerWhere: string DSL OU Closure — do cache de sessão
        // volta como SerializableClosure (wire): desembrulha.
        foreach ([
            'where'         => ['where', 'whereClosure'],
            'customerWhere' => ['customerWhere', 'customerWhereClosure'],
            'priceWhere'    => ['priceWhere', 'priceWhereClosure'],
            'stockWhere'    => ['stockWhere', 'stockWhereClosure'],
        ] as $key => [$strProp, $closProp]) {
            if (!array_key_exists($key, $config)) {
                continue;
            }
            $w = $config[$key];
            if ($w instanceof SerializableClosure) {
                $w = $w->getClosure();
            }
            if ($w instanceof \Closure) {
                $this->$closProp = $w;
                $this->$strProp  = '';
            } elseif (is_string($w)) {
                $this->$strProp  = $w;
                $this->$closProp = null;
            }
        }

        $this->_cfgErrors = null;
    }

    // ── Pagamentos / ações ────────────────────────────────────────────────

    /** @return PdvPayment[] indexados por method, ordem do documento. */
    /**
     * Colunas do carrinho, chaveadas pela chave OPACA (`c0`, `c1`, …).
     *
     * A chave é posicional na ordem de declaração e é o único identificador
     * que cruza para o cliente — ver o docblock de PdvColumn.
     *
     * @return array<string, PdvColumn>
     */
    public function getColumns(): array
    {
        if ($this->columnVOs !== null) {
            return $this->columnVOs;
        }

        $out = [];
        $i   = 0;
        foreach ($this->columns as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $col = PdvColumn::fromConfig($cfg, 'c' . $i);
            if (!$col->isUsable()) {
                continue;   // sem `field` e sem entrada não há o que mostrar
            }
            $out[$col->key] = $col;
            $i++;
        }

        return $this->columnVOs = $out;
    }

    /**
     * Colunas marcadas com `receipt` para a linha do cupom.
     *
     * Opt-in por coluna de propósito: cupom de 58mm não comporta tudo, e uma
     * coluna útil na tela não é automaticamente útil no papel.
     *
     * @return array<int, array{label: string, value: string}>
     */
    protected function _receiptCols(array $line): array
    {
        $out = [];
        foreach ($this->getColumns() as $key => $col) {
            if (!$col->receipt) {
                continue;
            }
            $val = $col->isEditable()
                ? (string) ($line['cols'][$key] ?? '')
                : $col->display($col->resolveValue($line['rec']), $line['rec']);
            if ($val !== '') {
                $out[] = ['label' => $col->label, 'value' => $val];
            }
        }

        return $out;
    }

    /**
     * Preço unitário EFETIVO — o preço da fonte depois das colunas que
     * declaram `affects="price"`.
     *
     * Este método é chamado em DOIS lugares: ao montar o item do lookup e ao
     * reconferir o preço na finalização. Rodar em um só faria `price-changed`
     * estourar em toda venda, com o carrinho "certo" na tela e o servidor
     * comparando contra o preço cru — o tipo de bug que só aparece em
     * produção, porque no teste de unidade cada lado está correto sozinho.
     *
     * Saída não-numérica ou exceção ⇒ devolve o preço CRU. Nunca zero: um
     * typo de transformer não pode vender de graça.
     */
    protected function _effectiveUnitPrice(object $rec, float $base): float
    {
        $price = $base;
        foreach ($this->getColumns() as $col) {
            if (!$col->affectsPrice()) {
                continue;
            }
            $out = $col->applyTransform($price, $rec);
            if ($out === '' || !is_numeric($out)) {
                continue;   // já logado pelo VO; segue com o preço anterior
            }
            $price = (float) $out;
        }

        return $this->_r($price);
    }

    /**
     * Gera as parcelas de UMA entrada de pagamento.
     *
     * Roda dentro da transação da venda. A idempotência tem duas camadas: o
     * guard de replay do `client_uuid` devolve a venda existente antes de
     * chegar aqui, e cada parcela leva uma chave determinística própria —
     * então mesmo um replay que passasse pelo primeiro guard não duplicaria.
     */
    protected function _createReceivables(
        mixed $saleId,
        object $pay,
        array $pm,
        ?int $customerId,
        string $clientSaleId,
        array &$out,
    ): void {
        $fqcn = $this->_resolveModelFqcn($this->receivableModel);
        if ($fqcn === '') {
            return;
        }

        /** @var PdvPayment $rule */
        $rule = $pm['rule'];
        $plan = PdvInstallmentPlan::build(
            (int) round($pm['amount'] * 100),
            (int) round($pm['down'] * 100),
            $pm['installments'],
            new \DateTimeImmutable(),
            $rule->prazoPrimeiraDias,
            $rule->intervaloDias,
            $rule->ajusteResiduo,
        );
        if ($plan === []) {
            return;
        }

        $doc   = str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
        $hoje  = date('Y-m-d');
        $seq   = count($out);

        foreach ($plan as $parc) {
            $valor = $this->_r($parc['amountC'] / 100);
            $data  = [
                $this->receivableSaleField   => $saleId,
                $this->receivableDocField    => $doc,
                $this->receivableNumberField => $parc['n'],
                $this->receivableCountField  => $parc['total'],
                $this->receivableAmountField => $valor,
                $this->receivableDueField    => $parc['due'],
                $this->receivableMethodField => $pm['method'],
                $this->receivableStatusField => $this->receivableStatusOpen,
            ];
            if ($this->receivableSacadoField !== '') {
                $data[$this->receivableSacadoField] = $rule->sacado;
            }
            if ($this->receivablePaymentField !== '') {
                $data[$this->receivablePaymentField] = $pay->getKey();
            }
            if ($this->receivableCustomerField !== '' && $rule->sacado === 'cliente') {
                $data[$this->receivableCustomerField] = $customerId;
            }
            if ($this->receivableAcquirerField !== '' && $rule->sacado === 'adquirente') {
                $data[$this->receivableAcquirerField] = $rule->adquirente;
            }
            if ($this->receivableFeeField !== '') {
                $data[$this->receivableFeeField] = $this->_r(
                    $valor * ($rule->taxaPercentual / 100) + $rule->taxaFixa
                );
            }
            if ($this->receivableDueOriginalField !== '') {
                $data[$this->receivableDueOriginalField] = $parc['due'];
            }
            if ($this->receivableIssueField !== '') {
                $data[$this->receivableIssueField] = $hoje;
            }
            if ($this->receivableOriginField !== '') {
                $data[$this->receivableOriginField] = 'venda';
            }
            if ($this->receivableUuidField !== '') {
                // Determinístico: o mesmo replay produziria a mesma chave.
                $data[$this->receivableUuidField] = md5(
                    $clientSaleId . '|' . $seq . '|' . $parc['n']
                );
            }

            $t = new $fqcn();
            $t->fill($data);
            $this->_stampScope($t);
            $t->save();
            $out[] = $t;
        }
    }

    /** A feature de contas a receber está ligada? */
    public function receivableEnabled(): bool
    {
        return $this->receivableModel !== '';
    }

    /** `item-field` das colunas de entrada, para o check de coluna existente. */
    protected function _columnItemFields(): array
    {
        $out = [];
        foreach ($this->getColumns() as $key => $col) {
            if ($col->isEditable() && $col->itemField !== '') {
                $out["column-{$key}-item-field"] = $col->itemField;
            }
        }

        return $out;
    }

    /** Relações a pré-carregar por causa de coluna com chain (mata o N+1). */
    protected function _columnEagerLoads(): array
    {
        $rels = [];
        foreach ($this->getColumns() as $col) {
            $root = $col->chainRoot();
            if ($root !== '') {
                $rels[$root] = true;
            }
        }

        return array_keys($rels);
    }

    /**
     * Valores das colunas para UM produto: cru em `cols`, renderizado em `disp`.
     *
     * @return array{cols: array<string, mixed>, disp: array<string, string>}
     */
    protected function _columnValuesFor(object $rec): array
    {
        $cols = [];
        $disp = [];
        foreach ($this->getColumns() as $key => $col) {
            if ($col->isEditable()) {
                // Entrada nasce com o default; o operador é quem preenche.
                $cols[$key] = $col->default;
                $disp[$key] = $col->default;
                continue;
            }
            $raw        = $col->resolveValue($rec);
            $cols[$key] = $raw;
            $disp[$key] = $col->display($raw, $rec);
        }

        return ['cols' => $cols, 'disp' => $disp];
    }

    public function getPayments(): array
    {
        $out = [];
        foreach ($this->payments as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $p = PdvPayment::fromConfig($cfg);
            if ($p->method === '' || isset($out[$p->method])) {
                continue; // method é a identidade — vazio/duplicado sai
            }
            $out[$p->method] = $p;
        }
        if ($out === [] && $this->payments === []) {
            foreach (PdvPayment::defaults() as $p) {
                $out[$p->method] = $p;
            }
        }
        return $out;
    }

    /** Ações custom sanitizadas: label+method obrigatórios; method vai pro wire. */
    public function getActions(): array
    {
        $out = [];
        foreach ($this->actions as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }
            $label  = trim((string) ($cfg['label'] ?? ''));
            $method = trim((string) ($cfg['method'] ?? ''));
            if ($label === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                continue;
            }
            $out[] = [
                'label'   => $label,
                'method'  => $method,
                'icon'    => trim((string) ($cfg['icon'] ?? '')),
                'hotkey'  => trim((string) ($cfg['hotkey'] ?? '')),
                'confirm' => trim((string) ($cfg['confirm'] ?? '')),
            ];
        }
        return $out;
    }

    // ── Efetivos (config cruzada) ─────────────────────────────────────────

    public function stockModeEffective(): string
    {
        if ($this->stockField === '') {
            return 'off';
        }
        $m = $this->stockMode ?? 'warn';
        return in_array($m, ['off', 'warn', 'block'], true) ? $m : 'warn';
    }

    public function stockDecrementEffective(): bool
    {
        return $this->stockField !== '' && ($this->stockDecrement ?? true);
    }

    public function showImagesEffective(): bool
    {
        return $this->imageField !== '' && ($this->showImages ?? true);
    }

    /** money|percent|both|off — normalizado. */
    public function discountInputEffective(): string
    {
        return in_array($this->discountInput, ['money', 'percent', 'both', 'off'], true)
            ? $this->discountInput
            : 'money';
    }

    /** @return array{item: bool, total: bool} */
    public function discountFlags(): array
    {
        if ($this->discountInputEffective() === 'off') {
            return ['item' => false, 'total' => false]; // vence o discount-mode
        }
        $mode = in_array($this->discountMode, ['none', 'item', 'total', 'both'], true)
            ? $this->discountMode
            : 'both';
        return [
            'item'  => $this->itemDiscountField !== '' && in_array($mode, ['item', 'both'], true),
            'total' => $this->saleDiscountField !== '' && in_array($mode, ['total', 'both'], true),
        ];
    }

    public function askDocumentEffective(): bool
    {
        return $this->askDocument && $this->saleDocumentField !== '';
    }

    public function receiptWidthEffective(): int
    {
        return in_array($this->receiptWidth, [58, 80], true) ? $this->receiptWidth : 80;
    }

    // ── Validação de config (§10.3 — fail-loud, nunca tela branca) ────────

    /**
     * Pendências de configuração. Vazio = pronto pra operar. Painel de erro
     * do blade renderiza a lista; o log acontece UMA vez por request.
     */
    public function configErrors(): array
    {
        if ($this->_cfgErrors !== null) {
            return $this->_cfgErrors;
        }

        $e = [];

        if ($this->model === '')     $e[] = "model (entity de produto) é obrigatório";
        if ($this->nameField === '') $e[] = "name-field é obrigatório";
        if ($this->priceField === '') $e[] = "price-field é obrigatório";
        if ($this->codeField === '' && $this->barcodeField === '') {
            $e[] = "informe code-field e/ou barcode-field (lookup do bip)";
        }

        // Colunas do carrinho (Rev. 5). O check de coluna-existe-na-tabela
        // vem de graça pelo _mappedFieldsByModel; aqui ficam as regras que
        // ele não cobre.
        foreach ($this->getColumns() as $key => $col) {
            if ($col->isEditable() && $col->inputType === 'combo' && $col->optionList() === []) {
                $e[] = "coluna '{$col->label}': input=combo exige options (a:Rótulo|b:Outro)";
            }
            if (!$col->isEditable() && $col->itemField !== '') {
                $e[] = "coluna '{$col->label}': item-field só faz sentido em coluna de entrada (mode=input)";
            }
            if ($col->affects === 'price' && $col->transform === '') {
                $e[] = "coluna '{$col->label}': affects=price exige transform";
            }
            if ($col->affects === 'price' && $col->transformTarget !== 'stored') {
                $e[] = "coluna '{$col->label}': affects=price exige transform-target=stored";
            }
            if ($col->itemField !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col->itemField)) {
                $e[] = "coluna '{$col->label}': item-field '{$col->itemField}' não é um nome de coluna válido";
            }
        }

        foreach ([
            'saleModel'    => 'sale-model',
            'itemModel'    => 'item-model',
            'paymentModel' => 'payment-model',
        ] as $prop => $attr) {
            if ($this->$prop === '') $e[] = "{$attr} é obrigatório";
        }

        // ── Contas a receber (Rev. 5) ──
        if ($this->receivableEnabled()) {
            foreach ([
                'receivableSaleField'   => 'receivable-sale-field',
                'receivableAmountField' => 'receivable-amount-field',
                'receivableDueField'    => 'receivable-due-field',
                'receivableNumberField' => 'receivable-number-field',
            ] as $prop => $attr) {
                if ($this->$prop === '') $e[] = "{$attr} não pode ser vazio com receivable-model";
            }
            // Alguma forma precisa gerar título — senão a tabela nasce vazia e
            // ninguém entende por quê.
            $geraAlguma = false;
            foreach ($this->getPayments() as $pay) {
                if ($pay->geraTitulo) { $geraAlguma = true; break; }
            }
            if (!$geraAlguma) {
                $e[] = 'receivable-model configurado, mas nenhuma forma de pagamento tem gera-titulo';
            }
        }
        foreach ($this->getPayments() as $pay) {
            if ($pay->geraTitulo && !$this->receivableEnabled()) {
                $e[] = "forma '{$pay->method}' tem gera-titulo mas receivable-model não está configurado";
            }
            if ($pay->maxParcelas > 1 && !$pay->geraTitulo) {
                $e[] = "forma '{$pay->method}': max-parcelas > 1 sem gera-titulo não parcela nada";
            }
        }

        foreach ([
            'saleTotalField'     => 'sale-total-field',
            'itemSaleField'      => 'item-sale-field',
            'itemProductField'   => 'item-product-field',
            'itemQtyField'       => 'item-qty-field',
            'itemPriceField'     => 'item-price-field',
            'itemTotalField'     => 'item-total-field',
            'paymentSaleField'   => 'payment-sale-field',
            'paymentMethodField' => 'payment-method-field',
            'paymentAmountField' => 'payment-amount-field',
        ] as $prop => $attr) {
            if ($this->$prop === '') $e[] = "{$attr} não pode ser vazio";
        }

        if ($this->askDocument && $this->saleDocumentField === '') {
            $e[] = "ask-document exige sale-document-field mapeado";
        }
        if ($this->payments !== [] && $this->getPayments() === []) {
            $e[] = "nenhuma <mad-pdv-payment> válida (method é obrigatório)";
        }

        // Fontes relacionadas (Rev. 3): field obrigatório junto do model,
        // keys/order pelos gates de identificador.
        $ident = '/^[A-Za-z_][A-Za-z0-9_]*$/';
        if ($this->stockIsRelated() && $this->stockField === '') {
            $e[] = "stock-model exige stock-field (coluna do saldo na tabela relacionada)";
        }
        if ($this->priceIsRelated() && ! preg_match($ident, $this->priceKey)) {
            $e[] = "price-key inválida (identificador de coluna)";
        }
        if ($this->stockIsRelated() && ! preg_match($ident, $this->stockKey)) {
            $e[] = "stock-key inválida (identificador de coluna)";
        }
        if ($this->priceOrder !== '') {
            $col = preg_split('/\s+/', trim($this->priceOrder))[0] ?? '';
            if (! preg_match($ident, $col)) {
                $e[] = "price-order inválida (\"coluna [asc|desc]\")";
            }
        }
        // Sem isto o dropdown de clientes simplesmente não ordena — nada
        // quebra, nada loga, e quem configurou jura ter pedido a ordenação.
        if ($this->customerOrderBy !== '') {
            $col = preg_split('/\s+/', trim($this->customerOrderBy))[0] ?? '';
            if (! preg_match($ident, $col)) {
                $e[] = "customer-order-by inválida (\"coluna [asc|desc]\")";
            }
        }

        // Resolução de models + conexão única + colunas — só quando a
        // estrutura básica está ok (senão vira ruído).
        if ($e === []) {
            $instances = [];
            $models = [
                'model'        => $this->model,
                'saleModel'    => $this->saleModel,
                'itemModel'    => $this->itemModel,
                'paymentModel' => $this->paymentModel,
            ];
            if ($this->customerModel !== '') {
                $models['customerModel'] = $this->customerModel;
            }
            if ($this->priceIsRelated()) {
                $models['priceModel'] = $this->priceModel;
            }
            if ($this->stockIsRelated()) {
                $models['stockModel'] = $this->stockModel;
            }
            if ($this->receivableEnabled()) {
                $models['receivableModel'] = $this->receivableModel;
            }
            foreach ($models as $prop => $name) {
                $fqcn = $this->_resolveModelFqcn($name);
                if ($fqcn === '') {
                    $e[] = "model '{$name}' ({$prop}) não resolve pra um Eloquent";
                    continue;
                }
                $instances[$prop] = new $fqcn();
            }

            if (isset($instances['saleModel'], $instances['itemModel'], $instances['paymentModel'])) {
                $conns = [];
                foreach (['saleModel', 'itemModel', 'paymentModel'] as $prop) {
                    $conns[$prop] = $instances[$prop]->getConnectionName() ?: $this->_db();
                }
                if (count(array_unique($conns)) > 1) {
                    $e[] = 'venda/item/pagamento precisam estar na MESMA conexão (transação única); '
                        . 'encontrado: ' . json_encode($conns);
                }

                // Decremento roda DENTRO da transação da venda → o modelo-ALVO
                // (produto no modo v1, stock-model no relacionado) precisa da
                // MESMA conexão do sale-model. Fecha também o buraco do modo
                // legado (produto em conexão divergente = baixa sem rollback).
                // Os títulos nascem DENTRO da transação da venda. Model em
                // outra conexão commitaria fora dela e não voltaria no
                // rollback — venda desfeita, parcelas de pé.
                if ($this->receivableEnabled() && isset($instances['receivableModel'])) {
                    $recConn = $instances['receivableModel']->getConnectionName() ?: $this->_db();
                    if ($recConn !== $conns['saleModel']) {
                        $e[] = 'contas a receber precisa da MESMA conexão da venda '
                            . "(títulos em '{$recConn}', venda em '{$conns['saleModel']}')";
                    }
                }

                if ($this->stockDecrementEffective()) {
                    $targetProp = $this->stockIsRelated() ? 'stockModel' : 'model';
                    if (isset($instances[$targetProp])) {
                        $saleConn = $conns['saleModel'];
                        $decConn  = $instances[$targetProp]->getConnectionName() ?: $this->_db();
                        if ($decConn !== $saleConn) {
                            $e[] = 'decremento de estoque precisa da MESMA conexão da venda '
                                . "(alvo '{$targetProp}' em '{$decConn}', venda em '{$saleConn}')";
                        }
                    }
                }
            }

            // Colunas mapeadas existem? (best-effort — schema indisponível pula)
            foreach ($this->_mappedFieldsByModel() as $prop => $fields) {
                if (!isset($instances[$prop])) {
                    continue;
                }
                $cols = $this->_columnListing($instances[$prop]);
                if ($cols === []) {
                    continue;
                }
                foreach ($fields as $attr => $field) {
                    if ($field !== '' && !in_array($field, $cols, true)) {
                        $e[] = "coluna '{$field}' ({$attr}) não existe em '"
                            . $instances[$prop]->getTable() . "'";
                    }
                }
            }
        }

        if ($e !== []) {
            error_log('[MadPdv] config incompleta em ' . static::class . ': ' . implode(' | ', $e));
        }

        return $this->_cfgErrors = $e;
    }

    /** @return array<string, array<string, string>> prop do model → [attr → coluna] */
    protected function _mappedFieldsByModel(): array
    {
        return [
            'model' => array_filter([
                'name-field'    => $this->nameField,
                // price/stock só são colunas do PRODUTO no modo v1 — com
                // *-model relacionado, checar aqui seria falso-positivo.
                'price-field'   => $this->priceIsRelated() ? '' : $this->priceField,
                'code-field'    => $this->codeField,
                'barcode-field' => $this->barcodeField,
                'stock-field'   => $this->stockIsRelated() ? '' : $this->stockField,
                'unit-field'    => $this->unitField,
                'image-field'   => $this->imageField,
                'active-field'  => $this->activeField,
            ]),
            'priceModel' => $this->priceIsRelated() ? array_filter([
                'price-field' => $this->priceField,
                'price-key'   => $this->priceKey,
            ]) : [],
            'stockModel' => $this->stockIsRelated() ? array_filter([
                'stock-field' => $this->stockField,
                'stock-key'   => $this->stockKey,
            ]) : [],
            'saleModel' => array_filter([
                'sale-total-field'    => $this->saleTotalField,
                'sale-subtotal-field' => $this->saleSubtotalField,
                'sale-discount-field' => $this->saleDiscountField,
                'sale-datetime-field' => $this->saleDatetimeField,
                'sale-customer-field' => $this->customerModel !== '' ? $this->saleCustomerField : '',
                'sale-operator-field' => $this->saleOperatorField,
                'sale-status-field'   => $this->saleStatusField,
                'sale-document-field' => $this->askDocumentEffective() ? $this->saleDocumentField : '',
                'sale-change-field'   => $this->saleChangeField,
                'sale-uuid-field'     => $this->saleUuidField,
            ]),
            'itemModel' => array_filter(array_merge([
                'item-sale-field'     => $this->itemSaleField,
                'item-product-field'  => $this->itemProductField,
                'item-qty-field'      => $this->itemQtyField,
                'item-price-field'    => $this->itemPriceField,
                'item-discount-field' => $this->itemDiscountField,
                'item-total-field'    => $this->itemTotalField,
            ], $this->_columnItemFields())),
            'receivableModel' => $this->receivableEnabled() ? array_filter([
                'receivable-sale-field'     => $this->receivableSaleField,
                'receivable-payment-field'  => $this->receivablePaymentField,
                'receivable-customer-field' => $this->receivableCustomerField,
                'receivable-acquirer-field' => $this->receivableAcquirerField,
                'receivable-sacado-field'   => $this->receivableSacadoField,
                'receivable-doc-field'      => $this->receivableDocField,
                'receivable-number-field'   => $this->receivableNumberField,
                'receivable-count-field'    => $this->receivableCountField,
                'receivable-amount-field'   => $this->receivableAmountField,
                'receivable-fee-field'      => $this->receivableFeeField,
                'receivable-due-field'      => $this->receivableDueField,
                'receivable-due-original-field' => $this->receivableDueOriginalField,
                'receivable-issue-field'    => $this->receivableIssueField,
                'receivable-method-field'   => $this->receivableMethodField,
                'receivable-status-field'   => $this->receivableStatusField,
                'receivable-origin-field'   => $this->receivableOriginField,
                'receivable-uuid-field'     => $this->receivableUuidField,
            ]) : [],
            'paymentModel' => array_filter([
                'payment-sale-field'     => $this->paymentSaleField,
                'payment-method-field'   => $this->paymentMethodField,
                'payment-amount-field'   => $this->paymentAmountField,
                'payment-tendered-field' => $this->paymentTenderedField,
            ]),
        ];
    }

    /** Column listing com cache por request; falha de schema → [] (pula check). */
    protected function _columnListing(object $model): array
    {
        try {
            $key = ($model->getConnectionName() ?: $this->_db()) . '|' . $model->getTable();
            if (!array_key_exists($key, self::$_colListing)) {
                self::$_colListing[$key] = $model->getConnection()
                    ->getSchemaBuilder()
                    ->getColumnListing($model->getTable());
            }
            return self::$_colListing[$key];
        } catch (\Throwable $e) {
            return [];
        }
    }

    // ── Config do client (x-data — §6: split client/server) ───────────────

    /**
     * JSON do x-data. NUNCA carrega model/tabela/coluna/conexão — só
     * comportamento, labels e tokens opacos (construídos NO RENDER, nunca
     * cacheados na sessão).
     */
    public function getClientConfig(): array
    {
        $labels = Lang::get('pdv');
        if (!is_array($labels)) {
            $labels = [];
        }
        // App gerado antes da chave existir no `lang/` mostraria um aviso VAZIO
        // no fallback de impressão — o default do pacote fecha esse buraco.
        $labels += [
            'print_blocked'  => 'Printing is blocked in this environment (preview). The receipt is shown on screen.',
            'choose_product' => 'Choose product',
            'stock_badge'    => 'stock: :n',
        ];
        $flags = $this->discountFlags();

        return [
            'id'       => $this->pdvCfgKey,
            'title'    => $this->pdvTitle !== '' ? $this->pdvTitle : ($labels['title_default'] ?? 'PDV'),
            'operator' => (string) (session('username') ?? session('userlogin') ?? ''),
            // Idioma do PDV (o mesmo que decide os separadores do dinheiro): a
            // data/hora do cupom sai nele, no fuso do navegador do caixa.
            'locale'   => $this->locale !== '' ? $this->locale : (string) app()->getLocale(),
            'i18n'     => $labels,
            'currency' => $this->_currencyConfig(),
            'behavior' => [
                'discountItem'       => $flags['item'],
                'discountTotal'      => $flags['total'],
                'discountInput'      => $this->discountInputEffective(),
                'maxDiscountPercent' => $this->maxDiscountPercent,
                'allowPriceOverride' => $this->allowPriceOverride,
                'allowFraction'      => $this->allowFraction,
                'stockMode'          => $this->stockModeEffective(),
                'askDocument'        => $this->askDocumentEffective(),
                'holdSales'          => $this->holdSales,
                'holdLimit'          => max(1, $this->holdLimit),
                'printMode'          => $this->printMode === 'off' ? 'off' : 'browser',
                'receiptWidth'       => $this->receiptWidthEffective(),
                'autoPrint'          => $this->autoPrint,
                'customerRequired'   => $this->customerModel !== '' && $this->customerRequired,
                'mergeLines'         => $this->mergeLines === 'off' ? 'off' : 'auto',
                'productPicker'      => $this->productPicker,
                'productPickerHotkey'=> $this->productPickerHotkey,
                'productPickerSize'  => min(max(1, $this->productPickerPageSize), self::BROWSE_LIMIT_CAP),
                'fullscreenToggle'   => $this->fullscreenToggle,
                'density'            => $this->density === 'compact' ? 'compact' : 'normal',
                'showImages'         => $this->showImagesEffective(),
                'searchMinLength'    => max(1, $this->searchMinLength),
                'searchDebounceMs'   => 300,
            ],
            'payments' => array_values(array_map(
                fn (PdvPayment $p) => $p->toClientConfig(),
                $this->getPayments()
            )),
            'columns'  => array_values(array_map(
                fn (PdvColumn $c) => $c->toClientConfig(),
                $this->getColumns(),
            )),
            'customer' => $this->_customerClientConfig(),
            'actions'  => $this->getActions(),
            'receipt'  => [
                'header' => $this->receiptHeader,
                'footer' => $this->receiptFooter !== '' ? $this->receiptFooter : ($labels['thanks'] ?? ''),
                'width'  => $this->receiptWidthEffective(),
            ],
            'wire' => ['lookup' => 'onProductLookup', 'finalize' => 'onFinalizeSale'],
        ];
    }

    protected function _currencyConfig(): array
    {
        $loc   = $this->locale !== '' ? $this->locale : (string) app()->getLocale();
        $comma = (bool) preg_match('/^(pt|es)/i', $loc);
        return [
            'symbol'   => $this->currencySymbol,
            'decimal'  => $comma ? ',' : '.',
            'thousand' => $comma ? '.' : ',',
        ];
    }

    /**
     * Bloco `customer` do client. Busca via infraestrutura existente
     * (data-mad-dbsearch + MadDbSearchService) — token opaco, receita do
     * SheetColumn::buildSearchToken. Nenhum endpoint novo.
     */
    protected function _customerClientConfig(): array
    {
        if ($this->customerModel === '') {
            return ['enabled' => false];
        }

        $token = '';
        $key   = $this->customerKey;
        try {
            $fqcn = ModelOptionsLoader::resolveModelClass($this->customerModel);
            $inst = new $fqcn();
            if ($key === '') {
                $key = $inst->getKeyName();
            }
            $qb = $fqcn::query();
            $this->_applyCustomerWhere($qb);
            [$querySql, $queryBindings] = QuerySource::compileSql($qb);

            // "nome desc" → ORDER BY nome DESC. O MadDbSearchService monta
            // `orderBy($order, $order_dir)` com a coluna CRUA — mandar o spec
            // inteiro em `order` viraria `ORDER BY "nome desc"` (erro de SQL
            // no PG, coluna inexistente no MySQL). Coluna inválida cai em ''
            // e o serviço simplesmente não ordena, em vez de explodir.
            [$orderCol, $orderDir] = $this->_relOrderSpec($this->customerOrderBy);

            $token = MadStateCrypt::encrypt([
                'database'       => $inst->getConnectionName() ?: $this->_db(),
                'model'          => $this->customerModel,
                'key'            => $key,
                'display'        => $this->customerDisplay,
                'order'          => $orderCol,
                'order_dir'      => $orderDir,
                'column'         => '',
                'query_sql'      => $querySql,
                'query_bindings' => $queryBindings,
                'limit'          => 30,
            ]);
        } catch (\Throwable $e) {
            // cliente vira busca sem token (desabilitada); erro real aparece
            // no configErrors quando o model não resolve
        }

        $defaultLabel = '';
        if ($this->customerDefaultId !== null) {
            try {
                $fqcn = ModelOptionsLoader::resolveModelClass($this->customerModel);
                $rec  = $fqcn::query()->find($this->customerDefaultId);
                if ($rec) {
                    $defaultLabel = $this->_applyDisplayMask($rec, $this->customerDisplay);
                }
            } catch (\Throwable $e) {
            }
        }

        return [
            'enabled'      => true,
            'required'     => $this->customerRequired,
            'defaultId'    => $this->customerDefaultId,
            'defaultLabel' => $defaultLabel,
            'displayMask'  => $this->customerDisplay,
            'searchToken'  => $token,
            'minLength'    => 1,
        ];
    }

    /** Aplica escopo do cliente: Closure OU DSL 'col=val|col2=val2'. */
    protected function _applyCustomerWhere(object $q): void
    {
        if ($this->customerWhereClosure instanceof \Closure) {
            ($this->customerWhereClosure)($q);
            return;
        }
        $this->_applyDslWhere($q, $this->customerWhere);
    }

    /** Aplica escopo do produto: Closure OU DSL. */
    protected function _applyProductWhere(object $q): void
    {
        if ($this->whereClosure instanceof \Closure) {
            ($this->whereClosure)($q);
            return;
        }
        $this->_applyDslWhere($q, $this->where);
    }

    /** DSL do field-list: 'ativo=1|tipo=P' → andWhere por par. */
    protected function _applyDslWhere(object $q, string $dsl): void
    {
        if (trim($dsl) === '') {
            return;
        }
        foreach (explode('|', $dsl) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || strpos($pair, '=') === false) {
                continue;
            }
            [$col, $val] = array_map('trim', explode('=', $pair, 2));
            if ($col !== '' && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                $q->where($col, $val);
            }
        }
    }

    /** Máscara de display '{col} - {col2}' sobre um record. */
    protected function _applyDisplayMask(object $rec, string $mask): string
    {
        if (strpos($mask, '{') === false) {
            return (string) ($rec->{$mask} ?? '');
        }
        return trim((string) preg_replace_callback(
            '/\{([A-Za-z_][A-Za-z0-9_]*)\}/',
            fn ($m) => (string) ($rec->{$m[1]} ?? ''),
            $mask
        ));
    }

    // ── Actions (wire) — §7 da spec ───────────────────────────────────────

    /**
     * Lookup de produto (§7.1). `scan` = match exato (barcode vence code);
     * `search` = LIKE em name/code/barcode com escopo + ativos. Resposta via
     * CustomEvent `mad-pdv:lookup-result`; `requestId` deixa o client
     * descartar respostas out-of-order da busca digitada.
     */
    /**
     * Lookup de produto (§7.1).
     *
     * `$after` (Rev. 5) é o cursor KEYSET do modo `browse` — nunca um offset.
     * Com preço relacionado o filtro de "sem preço" é pós-fetch, e offset com
     * filtro pós-fetch sobrepõe ou pula linhas entre páginas.
     *
     * O 4º parâmetro é opcional de propósito: chamada antiga de 3 argumentos
     * continua válida.
     */
    public function onProductLookup(
        string $term,
        string $mode = 'search',
        int $requestId = 0,
        string $after = '',
    ): MadResponse {
        $term = trim($term);
        // `browse` precisa ser modo EXPLÍCITO: modo desconhecido cai em
        // `search`, e `browse` sem este branch viraria busca de termo vazio.
        $mode = in_array($mode, ['scan', 'browse'], true) ? $mode : 'search';

        $items    = [];
        $reason   = null;
        $nextFrom = '';
        $hasMore  = false;

        $fqcn = $this->_resolveModelFqcn($this->model);
        if ($fqcn === '') {
            $reason = 'not-found';
        } elseif ($mode === 'browse') {
            [$items, $nextFrom, $hasMore] = $this->_browseLookup($fqcn, $term, $after);
            $reason = $items === [] ? 'not-found' : null;
        } elseif ($term === '') {
            $reason = 'not-found';
        } elseif ($mode === 'scan') {
            [$items, $reason] = $this->_scanLookup($fqcn, $term);
        } else {
            $items  = $this->_searchLookup($fqcn, $term);
            $reason = $items === [] ? 'not-found' : null;
        }

        return (new MadResponse())->script($this->_pdvEvent('mad-pdv:lookup-result', [
            'requestId' => $requestId,
            'mode'      => $mode,
            'items'     => $items,
            'reason'    => $reason,
            'after'     => $nextFrom,
            'hasMore'   => $hasMore,
        ]));
    }

    /**
     * Finaliza a venda (§7.2). O payload NUNCA carrega model/coluna — toda
     * regra (preço, teto de desconto, estoque, soma de pagamentos, cliente)
     * revalida aqui com a config da sessão; o client é só UX. Erro de domínio
     * ⇒ rollback total + `mad-pdv:sale-error` (carrinho intacto no client).
     */
    public function onFinalizeSale(string $payloadJson): MadResponse
    {
        if ($this->configErrors() !== []) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'config', 'msg' => 'configuração do PDV incompleta'],
            ]]);
        }

        $p = json_decode($payloadJson, true);
        if (!is_array($p)) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'payload', 'msg' => 'payload inválido'],
            ]]);
        }

        $clientSaleId = trim((string) ($p['clientSaleId'] ?? ''));
        $customerId   = (isset($p['customerId']) && $p['customerId'] !== null && $p['customerId'] !== '')
            ? (int) $p['customerId'] : null;
        $document     = trim((string) ($p['document'] ?? ''));
        $saleDiscount = $this->_r($p['saleDiscount'] ?? 0);
        $rawItems     = is_array($p['items'] ?? null) ? $p['items'] : [];
        $rawPayments  = is_array($p['payments'] ?? null) ? $p['payments'] : [];

        if ($rawItems === [] || count($rawItems) > 500) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'items', 'msg' => 'carrinho vazio ou acima do limite'],
            ]]);
        }
        if (!$this->askDocumentEffective()) {
            $document = '';
        }

        $saleFqcn = $this->_resolveModelFqcn($this->saleModel);
        $itemFqcn = $this->_resolveModelFqcn($this->itemModel);
        $payFqcn  = $this->_resolveModelFqcn($this->paymentModel);
        $prodFqcn = $this->_resolveModelFqcn($this->model);

        // ── Idempotência (§7.7): replay barato antes de qualquer trabalho ──
        if ($this->saleUuidField !== '' && $clientSaleId !== '') {
            $existing = $saleFqcn::query()->where($this->saleUuidField, $clientSaleId)->first();
            if ($existing) {
                return $this->_saleDone($existing, true, $this->_receiptFromDb($existing));
            }
        }

        // ── Passos 2-3: recarrega produtos e valida itens ──
        $prodPk = (new $prodFqcn())->getKeyName();
        $ids    = [];
        foreach ($rawItems as $it) {
            if (is_array($it)) {
                $ids[] = (int) ($it['productId'] ?? 0);
            }
        }
        $pq = $prodFqcn::query();
        $this->_applyProductWhere($pq);
        $recs = $pq->whereIn($prodPk, array_values(array_unique($ids)))
            ->get()
            ->keyBy(fn ($r) => (string) $r->getKey());

        $flags = $this->discountFlags();
        $notFound = $inactive = $noPrice = $priceChanged = $fieldErrors = [];
        $clean        = [];
        $qtyByProduct = [];

        // Preço relacionado (Rev. 3): resolve em batch ANTES do loop — o
        // re-check de preço do finalize usa a MESMA fonte do lookup.
        $relPrices = $this->priceIsRelated()
            ? $this->_resolvePrices(array_values(array_unique($ids)))
            : [];

        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid   = (int) ($it['productId'] ?? 0);
            $qty   = (float) ($it['qty'] ?? 0);
            $price = $this->_r($it['unitPrice'] ?? 0);
            $disc  = $this->_r($it['discount'] ?? 0);

            $rec = $recs[(string) $pid] ?? null;
            if (!$rec) {
                $notFound[] = ['productId' => $pid];
                continue;
            }
            if (!$this->_isActive($rec)) {
                $inactive[] = ['productId' => $pid, 'name' => (string) $rec->{$this->nameField}];
                continue;
            }

            if ($this->priceIsRelated()) {
                $rel = $relPrices[(string) $pid] ?? null;
                if ($rel === null) {
                    // Linha de preço sumiu/NULL entre o bip e o finalize —
                    // code próprio (§7.3 Rev. 3), não price-changed com null.
                    $noPrice[] = ['productId' => $pid];
                    continue;
                }
                $current = $this->_r($rel);
            } else {
                $current = $this->_r($rec->{$this->priceField});
            }
            // MESMA função do lookup — ver o docblock de _effectiveUnitPrice.
            $current = $this->_effectiveUnitPrice($rec, $current);
            if (!$this->allowPriceOverride && abs($price - $current) > 0.005) {
                $priceChanged[] = ['productId' => $pid, 'oldPrice' => $price, 'newPrice' => $current];
                continue;
            }
            $effPrice = $this->allowPriceOverride ? $price : $current;

            if ($qty <= 0) {
                $fieldErrors[] = ['field' => "items.{$pid}.qty", 'msg' => 'quantidade inválida'];
                continue;
            }
            if (!$this->allowFraction && abs($qty - round($qty)) > 1e-9) {
                $fieldErrors[] = ['field' => "items.{$pid}.qty", 'msg' => 'quantidade fracionada desabilitada'];
                continue;
            }
            if ($disc < 0 || ($disc > 0 && !$flags['item'])) {
                $fieldErrors[] = ['field' => "items.{$pid}.discount", 'msg' => 'desconto por item não permitido'];
                continue;
            }

            // Colunas de ENTRADA: itera a lista do SERVIDOR e lê a chave
            // correspondente do payload. Chave inventada pelo cliente nunca é
            // gravada porque nunca é procurada — não há allowlist de nomes.
            $lineCols = [];
            $colErr   = false;
            $sent     = is_array($it['cols'] ?? null) ? $it['cols'] : [];
            foreach ($this->getColumns() as $key => $col) {
                if (!$col->isEditable()) {
                    continue;   // exibição: o servidor re-resolve, não lê o payload
                }
                $val = trim((string) ($sent[$key] ?? ''));
                if ($val === '' && $col->default !== '') {
                    $val = $col->default;
                }
                if ($col->required && $val === '') {
                    $fieldErrors[] = ['field' => "items.{$pid}.cols.{$key}", 'msg' => "'{$col->label}' é obrigatória"];
                    $colErr = true;
                    break;
                }
                if (mb_strlen($val) > $col->maxlength) {
                    $fieldErrors[] = ['field' => "items.{$pid}.cols.{$key}", 'msg' => "'{$col->label}' excede {$col->maxlength} caracteres"];
                    $colErr = true;
                    break;
                }
                if ($val !== '' && $col->inputType === 'combo') {
                    $allowed = array_column($col->optionList(), 'value');
                    if ($allowed !== [] && !in_array($val, $allowed, true)) {
                        $fieldErrors[] = ['field' => "items.{$pid}.cols.{$key}", 'msg' => "valor fora da lista de '{$col->label}'"];
                        $colErr = true;
                        break;
                    }
                }
                // `stored`: o que grava é a saída do transform, não o digitado.
                if ($val !== '' && $col->storesTransformed()) {
                    $val = $col->applyTransform($val, $rec);
                }
                $lineCols[$key] = $val;
            }
            if ($colErr) {
                continue;
            }

            $qtyByProduct[$pid] = $this->_r(($qtyByProduct[$pid] ?? 0) + $qty);
            $clean[] = [
                'productId' => $pid,
                'name'      => (string) $rec->{$this->nameField},
                'unit'      => $this->unitField !== '' ? (string) $rec->{$this->unitField} : '',
                'qty'       => $qty,
                'price'     => $effPrice,
                'disc'      => $disc,
                'cols'      => $lineCols,
                'rec'       => $rec,
            ];
        }

        if ($notFound !== [])     return $this->_saleError('product-not-found', ['items' => $notFound]);
        if ($inactive !== [])     return $this->_saleError('product-inactive', ['items' => $inactive]);
        if ($noPrice !== [])      return $this->_saleError('no-price', ['items' => $noPrice]);
        if ($priceChanged !== []) return $this->_saleError('price-changed', ['items' => $priceChanged]);
        if ($fieldErrors !== [])  return $this->_saleError('validation', ['fields' => $fieldErrors]);

        // ── Recompute (§7.6 — half-up 2 casas, ordem fixa) ──
        $subtotalBruto = 0.0;
        $subtotal      = 0.0;
        $itemDiscSum   = 0.0;
        foreach ($clean as &$l) {
            $gross = $this->_r($l['qty'] * $l['price']);
            $total = $this->_r($gross - $l['disc']);
            if ($total < 0) {
                return $this->_saleError('validation', ['fields' => [
                    ['field' => "items.{$l['productId']}.discount", 'msg' => 'desconto maior que a linha'],
                ]]);
            }
            $l['total']    = $total;
            $subtotalBruto = $this->_r($subtotalBruto + $gross);
            $subtotal      = $this->_r($subtotal + $total);
            $itemDiscSum   = $this->_r($itemDiscSum + $l['disc']);
        }
        unset($l);

        if ($saleDiscount < 0 || ($saleDiscount > 0 && !$flags['total'])) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'saleDiscount', 'msg' => 'desconto na venda não permitido'],
            ]]);
        }
        $total = $this->_r($subtotal - $saleDiscount);
        if ($total < 0) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'saleDiscount', 'msg' => 'desconto maior que o subtotal'],
            ]]);
        }
        if ($this->maxDiscountPercent !== null && $subtotalBruto > 0) {
            $pct = ($itemDiscSum + $saleDiscount) / $subtotalBruto * 100;
            if ($pct > $this->maxDiscountPercent + 1e-9) {
                return $this->_saleError('discount-exceeded', [
                    'maxPercent'       => $this->maxDiscountPercent,
                    'attemptedPercent' => round($pct, 2),
                ]);
            }
        }

        // ── Cliente + documento ──
        $customerLabel = '';
        if ($this->customerModel !== '' && $this->customerRequired && $customerId === null) {
            return $this->_saleError('customer-required');
        }
        if ($customerId !== null && $this->customerModel !== '') {
            $crec = null;
            try {
                $cf = ModelOptionsLoader::resolveModelClass($this->customerModel);
                $cq = $cf::query();
                $this->_applyCustomerWhere($cq);
                $crec = $cq->find($customerId);
            } catch (\Throwable $e) {
            }
            if (!$crec) {
                return $this->_saleError('validation', ['fields' => [
                    ['field' => 'customer', 'msg' => 'cliente não encontrado'],
                ]]);
            }
            $customerLabel = $this->_applyDisplayMask($crec, $this->customerDisplay);
        }
        if ($document !== '' && !$this->_validDocument($document)) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'document', 'msg' => 'CPF/CNPJ inválido'],
            ]]);
        }

        // ── Pagamentos (§7.2 passo 4) ──
        $methods  = $this->getPayments();
        $payClean = [];
        $paid     = 0.0;
        $changeSum = 0.0;
        // Teto de entradas: N parcelas × M entradas multiplica inserts dentro
        // da transação. Os itens já tinham teto; os pagamentos não.
        if (count($rawPayments) > self::MAX_PAYMENT_ENTRIES) {
            return $this->_saleError('validation', ['fields' => [
                ['field' => 'payments', 'msg' => 'excesso de formas de pagamento na mesma venda'],
            ]]);
        }
        foreach ($rawPayments as $pm) {
            if (!is_array($pm)) {
                continue;
            }
            $method = (string) ($pm['method'] ?? '');
            if (!isset($methods[$method])) {
                return $this->_saleError('invalid-payment-method', ['method' => $method]);
            }
            $amount = $this->_r($pm['amount'] ?? 0);
            if ($amount <= 0) {
                return $this->_saleError('validation', ['fields' => [
                    ['field' => 'payments.amount', 'msg' => 'valor de pagamento inválido'],
                ]]);
            }
            $tendered = null;
            if ($methods[$method]->allowChange
                && isset($pm['tendered']) && $pm['tendered'] !== null && $pm['tendered'] !== '') {
                $tendered = $this->_r($pm['tendered']);
                if ($tendered + 0.005 < $amount) {
                    return $this->_saleError('validation', ['fields' => [
                        ['field' => 'payments.tendered', 'msg' => 'valor recebido menor que o aplicado'],
                    ]]);
                }
                $changeSum = $this->_r($changeSum + max(0, $tendered - $amount));
            }
            // ── Parcelamento (Rev. 5) ──
            $rule  = $methods[$method];
            $nInst = max(1, (int) ($pm['installments'] ?? 1));
            $down  = $this->_r($pm['downPayment'] ?? 0);

            if ($nInst > $rule->maxParcelas) {
                return $this->_saleError('validation', ['fields' => [
                    ['field' => 'payments.installments',
                     'msg'   => "'{$rule->label}' aceita no máximo {$rule->maxParcelas} parcela(s)"],
                ]]);
            }
            // Entrada IGUAL ao valor não é parcelamento — é pagar tudo no ato,
            // e aí a forma escolhida está errada. Recusa em vez de gravar uma
            // linha de pagamento parcelada que não gera parcela nenhuma.
            if ($down < 0 || $down + 0.005 >= $amount) {
                return $this->_saleError('validation', ['fields' => [
                    ['field' => 'payments.downPayment', 'msg' => 'entrada precisa ser menor que o valor da forma'],
                ]]);
            }
            if ($rule->exigeCliente && $this->customerModel !== '' && $customerId === null) {
                return $this->_saleError('customer-required', ['method' => $method]);
            }

            $paid       = $this->_r($paid + $amount);
            $payClean[] = [
                'method'       => $method,
                'label'        => $rule->label,
                'amount'       => $amount,
                'tendered'     => $tendered,
                'installments' => $nInst,
                'down'         => $down,
                'rule'         => $rule,
            ];
        }
        if ($paid + 0.005 < $total) {
            return $this->_saleError('payment-incomplete', [
                'total' => $total, 'paid' => $paid, 'remaining' => $this->_r($total - $paid),
            ]);
        }
        if (abs($paid - $total) > 0.005) {
            return $this->_saleError('payment-mismatch', ['expected' => $total, 'received' => $paid]);
        }

        // ── Dados da venda (só campos MAPEADOS entram) ──
        $saleData = [$this->saleTotalField => $total];
        if ($this->saleSubtotalField !== '') $saleData[$this->saleSubtotalField] = $subtotal;
        if ($this->saleDiscountField !== '') $saleData[$this->saleDiscountField] = $saleDiscount;
        if ($this->saleDatetimeField !== '') $saleData[$this->saleDatetimeField] = date('Y-m-d H:i:s');
        if ($this->saleCustomerField !== '' && $this->customerModel !== '') {
            $saleData[$this->saleCustomerField] = $customerId;
        }
        if ($this->saleOperatorField !== '') {
            $saleData[$this->saleOperatorField] = ((int) session('userid')) ?: null;
        }
        if ($this->saleStatusField !== '')   $saleData[$this->saleStatusField] = $this->saleStatusDone;
        if ($this->saleDocumentField !== '' && $document !== '') {
            $saleData[$this->saleDocumentField] = $document;
        }
        if ($this->saleChangeField !== '')   $saleData[$this->saleChangeField] = $changeSum;
        if ($this->saleUuidField !== '' && $clientSaleId !== '') {
            $saleData[$this->saleUuidField] = $clientSaleId;
        }

        // ── Transação na conexão REAL do sale-model (padrão gantt — NÃO o
        //    `_db()` do sheet, que deixa o save fora quando divergem) ──
        $conn = (new $saleFqcn())->getConnectionName() ?: $this->_db();
        $venda = null;
        $savedItems = [];
        $savedPayments = [];
        $savedTitles   = [];

        // Alvo do estoque (Rev. 3): tabela de saldo relacionada quando
        // stock-model setado; senão a própria tabela do produto (v1).
        $stockFqcn = $this->stockIsRelated()
            ? $this->_resolveModelFqcn($this->stockModel)
            : '';
        $nameByPid = [];
        foreach ($clean as $l) {
            $nameByPid[(string) $l['productId']] = $l['name'];
        }

        try {
            DB::connection($conn)->transaction(function () use (
                $saleFqcn, $itemFqcn, $payFqcn, $prodFqcn, $prodPk,
                $stockFqcn, $nameByPid,
                $saleData, $clean, $payClean, $qtyByProduct, $p,
                $customerId, $clientSaleId,
                &$venda, &$savedItems, &$savedPayments, &$savedTitles
            ) {
                $block = $this->stockModeEffective() === 'block';

                // Pré-check com lock = erro amigável (§7.3); o guard do
                // decremento lá embaixo é o INVARIANTE (sqlite ignora FOR
                // UPDATE — a serialização vem do lock de arquivo). Com
                // stock-model setado, o lock/leitura é nas linhas da tabela
                // de SALDO (A3 — ler stock-field no produto devolveria null
                // → 0 e bloquearia 100% das vendas).
                if ($block) {
                    $insufficient = [];
                    if ($this->stockIsRelated()) {
                        $sq = $stockFqcn::query();
                        $this->_applyStockScope($sq);
                        $locked = $sq->whereIn($this->stockKey, array_keys($qtyByProduct))
                            ->lockForUpdate()
                            ->get();
                        $byPid = [];
                        foreach ($locked as $r) {
                            $spid = (string) $r->{$this->stockKey};
                            if (! isset($byPid[$spid])) {
                                $byPid[$spid] = $r; // 1ª linha; múltiplas = guard do UPDATE
                            }
                        }
                        foreach ($qtyByProduct as $pid => $qty) {
                            $row   = $byPid[(string) $pid] ?? null;
                            $avail = $row ? (float) $row->{$this->stockField} : 0.0;
                            if ($qty > $avail + 1e-9) {
                                $insufficient[] = [
                                    'productId' => (int) $pid,
                                    'name'      => $nameByPid[(string) $pid] ?? ('#' . $pid),
                                    'requested' => $qty,
                                    'available' => $avail,
                                ];
                            }
                        }
                    } else {
                        $locked = $prodFqcn::query()
                            ->whereIn($prodPk, array_keys($qtyByProduct))
                            ->lockForUpdate()
                            ->get()
                            ->keyBy(fn ($r) => (string) $r->getKey());
                        foreach ($qtyByProduct as $pid => $qty) {
                            $rec   = $locked[(string) $pid] ?? null;
                            $avail = $rec ? (float) $rec->{$this->stockField} : 0.0;
                            if ($qty > $avail + 1e-9) {
                                $insufficient[] = [
                                    'productId' => (int) $pid,
                                    'name'      => $rec ? (string) $rec->{$this->nameField} : ('#' . $pid),
                                    'requested' => $qty,
                                    'available' => $avail,
                                ];
                            }
                        }
                    }
                    if ($insufficient !== []) {
                        throw new PdvSaleException('insufficient-stock', ['items' => $insufficient]);
                    }
                }

                $venda = new $saleFqcn();
                $venda->fill($saleData);
                $this->_stampScope($venda);
                $this->beforeSaveSale($venda, [
                    'items' => $clean, 'payments' => $payClean, 'payload' => $p,
                ]);
                $venda->save();
                $saleId = $venda->getKey();

                foreach ($clean as $l) {
                    $idata = [
                        $this->itemSaleField    => $saleId,
                        $this->itemProductField => $l['productId'],
                        $this->itemQtyField     => $l['qty'],
                        $this->itemPriceField   => $l['price'],
                        $this->itemTotalField   => $l['total'],
                    ];
                    if ($this->itemDiscountField !== '') {
                        $idata[$this->itemDiscountField] = $l['disc'];
                    }
                    // Colunas de entrada mapeadas: o NOME da coluna vem da
                    // config do servidor, nunca do payload. Continua passando
                    // por fill(), então $fillable/$guarded do model seguem
                    // valendo como última barreira.
                    foreach ($this->getColumns() as $key => $col) {
                        if ($col->isEditable() && $col->itemField !== '') {
                            $idata[$col->itemField] = $l['cols'][$key] ?? null;
                        }
                    }
                    $item = new $itemFqcn();
                    $item->fill($idata);
                    $this->_stampScope($item);
                    $item->save();
                    $savedItems[] = $item;
                }

                foreach ($payClean as $pm) {
                    $pdata = [
                        $this->paymentSaleField   => $saleId,
                        $this->paymentMethodField => $pm['method'],
                        $this->paymentAmountField => $pm['amount'],
                    ];
                    if ($this->paymentTenderedField !== '' && $pm['tendered'] !== null) {
                        $pdata[$this->paymentTenderedField] = $pm['tendered'];
                    }
                    // Snapshot da regra: o cadastro da forma muda com o tempo,
                    // mas a linha de pagamento precisa continuar contando a
                    // história que era verdade quando a venda fechou.
                    if ($this->paymentGeneratesTitleField !== '') {
                        $pdata[$this->paymentGeneratesTitleField] = $pm['rule']->geraTitulo ? 1 : 0;
                    }
                    if ($this->paymentSacadoField !== '') {
                        $pdata[$this->paymentSacadoField] = $pm['rule']->sacado;
                    }
                    if ($this->paymentInstallmentsField !== '') {
                        $pdata[$this->paymentInstallmentsField] = $pm['installments'];
                    }
                    if ($this->paymentNetField !== '') {
                        $pdata[$this->paymentNetField] = $pm['amount'];
                    }

                    $pay = new $payFqcn();
                    $pay->fill($pdata);
                    $this->_stampScope($pay);
                    $pay->save();
                    $savedPayments[] = $pay;

                    // ── Contas a receber (Rev. 5) ──
                    // Dentro da MESMA transação, de propósito: em afterSale a
                    // exceção vira toast e a venda ficaria sem título, sem
                    // erro, descoberta no fechamento do mês.
                    if ($this->receivableEnabled() && $pm['rule']->geraTitulo) {
                        $this->_createReceivables($saleId, $pay, $pm, $customerId, $clientSaleId, $savedTitles);
                    }
                }

                // Decremento atômico (§7.8) — o WHERE `estoque >= qty` é a
                // garantia real em `block`; 0 linhas afetadas = corrida.
                // Rev. 3: com stock-model, a baixa é na LINHA da tabela de
                // saldo (key=pid + escopo); estoque relacionado exige linha
                // ÚNICA — `affected > 1` = config errada (várias linhas
                // casaram), aborta em vez de baixa múltipla silenciosa.
                if ($this->stockDecrementEffective()) {
                    foreach ($qtyByProduct as $pid => $qty) {
                        if ($this->stockIsRelated()) {
                            $uq = $stockFqcn::query();
                            $this->_applyStockScope($uq);
                            $uq->where($this->stockKey, $pid);
                        } else {
                            $uq = $prodFqcn::query()->where($prodPk, $pid);
                        }
                        if ($block) {
                            $uq->where($this->stockField, '>=', $qty);
                        }
                        $affected = $uq->decrement($this->stockField, $qty);
                        if ($affected > 1) {
                            throw new PdvSaleException('internal', ['items' => [
                                ['productId' => (int) $pid],
                            ]]);
                        }
                        if ($block && $affected === 0) {
                            throw new PdvSaleException('conflict', ['items' => [
                                ['productId' => (int) $pid, 'requested' => $qty],
                            ]]);
                        }
                        if (! $block && $affected === 0 && $this->stockIsRelated()) {
                            error_log('[MadPdv] saldo ausente pro produto ' . $pid
                                . ' — decremento pulado (warn)');
                        }
                    }
                }
            });
        } catch (PdvSaleException $e) {
            return $this->_saleError($e->errorCode, $e->details);
        } catch (\Throwable $e) {
            // Corrida do replay: o UNIQUE do uuid estourou entre o pré-check e
            // o insert → devolve a venda que venceu.
            if ($this->saleUuidField !== '' && $clientSaleId !== '') {
                $existing = $saleFqcn::query()->where($this->saleUuidField, $clientSaleId)->first();
                if ($existing) {
                    return $this->_saleDone($existing, true, $this->_receiptFromDb($existing));
                }
            }
            error_log('[MadPdv] finalize falhou em ' . static::class . ': ' . $e->getMessage());
            return $this->_saleError('internal', [], false);
        }

        // ── Pós-commit: recibo + hooks (falha aqui NÃO desfaz a venda) ──
        $receipt = [
            'header'   => $this->receiptHeader,
            'footer'   => $this->receiptFooter !== '' ? $this->receiptFooter : (string) __('pdv.thanks'),
            'datetime' => date('c'),
            'operator' => (string) (session('username') ?? ''),
            'customer' => $customerLabel,
            'document' => $document,
            'lines'    => array_map(fn ($l) => [
                'qty'       => $l['qty'],
                'unit'      => $l['unit'],
                'name'      => $l['name'],
                'unitPrice' => $l['price'],
                'discount'  => $l['disc'],
                'total'     => $l['total'],
                'cols'      => $this->_receiptCols($l),
            ], $clean),
            'totals'   => [
                'subtotal' => $subtotal,
                'discount' => $this->_r($itemDiscSum + $saleDiscount),
                'total'    => $total,
            ],
            'payments' => array_map(fn ($pm) => [
                'label'    => $pm['label'],
                'amount'   => $pm['amount'],
                'tendered' => $pm['tendered'],
            ], $payClean),
            'change' => $changeSum,
            'width'  => $this->receiptWidthEffective(),
        ];

        $warn = false;
        try {
            $this->afterSale($venda, $savedItems, $savedPayments);
            $this->_fireOnFinalized($venda);
        } catch (\Throwable $e) {
            $warn = true;
            error_log('[MadPdv] afterSale/onFinalized falhou (venda mantida): ' . $e->getMessage());
        }

        $resp = $this->_saleDone($venda, false, $receipt);
        if ($warn) {
            $resp->toast((string) __('pdv.err_after_sale'), 'warning');
        }
        return $resp;
    }

    // ── Hooks (subclasse / casca gerada) ──────────────────────────────────

    /**
     * Muta o record da venda antes do save — DENTRO da transação.
     * $ctx = ['items' => array, 'payments' => array, 'payload' => array].
     */
    protected function beforeSaveSale(object $venda, array $ctx): void
    {
    }

    /**
     * Pós-commit (integrações: fiscal via provider pluga AQUI). Exceção é
     * logada e vira toast warning — a venda NUNCA é desfeita por falha aqui.
     */
    protected function afterSale(object $venda, array $itens, array $pagamentos): void
    {
    }

    /** Callback declarativo on-finalized="metodo" (host ou externo). */
    protected function _fireOnFinalized(object $venda): void
    {
        $m = $this->onFinalized;
        if ($m === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $m)) {
            return;
        }
        $target = method_exists($this, $m)
            ? $this
            : (($this->_externalHost && method_exists($this->_externalHost, $m)) ? $this->_externalHost : null);
        if ($target) {
            $target->{$m}($venda->getKey());
        }
    }

    // ── Lookup internals ──────────────────────────────────────────────────

    /** @return array{0: array, 1: ?string} [items, reason] */
    protected function _scanLookup(string $fqcn, string $term): array
    {
        $q = $fqcn::query();
        $this->_applyProductWhere($q);
        $q->where(function ($w) use ($term) {
            $first = true;
            foreach ([$this->barcodeField, $this->codeField] as $col) {
                if ($col === '') {
                    continue;
                }
                $first ? $w->where($col, $term) : $w->orWhere($col, $term);
                $first = false;
            }
        });
        $recs = $q->limit(10)->get();
        if ($recs->isEmpty()) {
            return [[], 'not-found'];
        }

        // Barcode exato vence code exato (prioridade do bip).
        $recs = $recs->sortBy(fn ($r) => ($this->barcodeField !== ''
            && (string) $r->{$this->barcodeField} === $term) ? 0 : 1)->values();

        $active = $recs->filter(fn ($r) => $this->_isActive($r))->values();
        if ($active->isEmpty()) {
            return [[], 'inactive'];
        }

        [$prices, $stocks] = $this->_relatedMapsFor($active->all());
        if ($this->priceIsRelated()) {
            // no-price ≠ not-found: o produto EXISTE, só não tem linha de
            // preço válida (ausente ou valor NULL) — feedback distinto no UI.
            $active = $active->filter(
                fn ($r) => ($prices[(string) $r->getKey()] ?? null) !== null
            )->values();
            if ($active->isEmpty()) {
                return [[], 'no-price'];
            }
        }

        return [
            $active->map(fn ($r) => $this->_lookupItem($r, $prices, $stocks))->all(),
            null,
        ];
    }

    /**
     * Catálogo navegável do modal (Rev. 5): lista SEM termo, paginada por
     * KEYSET sobre a tupla (coluna de ordenação, PK).
     *
     * Por que keyset e não offset: `_orderSpec()` cai em `nameField asc`, que
     * NÃO é único — com offset, dois produtos de mesmo nome fazem a página
     * seguinte repetir ou pular linha. E com preço relacionado o filtro de
     * "sem preço" roda pós-fetch, o que desalinha offset de vez. O tiebreak
     * por PK torna a ordem total, e o cursor carrega a tupla inteira.
     *
     * `$term` é opcional: o modal também busca dentro dele, e aí o browse vira
     * uma busca paginada (o `_searchLookup` normal não pagina).
     *
     * @return array{0: array<int, array<string, mixed>>, 1: string, 2: bool}
     *         itens, cursor da próxima página, e se há próxima
     */
    protected function _browseLookup(string $fqcn, string $term = '', string $after = ''): array
    {
        $q = $fqcn::query();
        $this->_applyProductWhere($q);
        $this->_applyActiveWhere($q);

        $term = trim($term);
        if ($term !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
            $q->where(function ($w) use ($like) {
                $w->whereLike($this->nameField, $like);
                foreach ([$this->codeField, $this->barcodeField] as $col) {
                    if ($col !== '') {
                        $w->orWhereLike($col, $like);
                    }
                }
            });
        }

        [$col, $dir] = $this->_orderSpec();
        $pk          = (new $fqcn())->getKeyName();
        $cmp         = $dir === 'desc' ? '<' : '>';

        $cursor = $this->_decodeCursor($after);
        if ($cursor !== null) {
            [$lastVal, $lastId] = $cursor;
            $q->where(function ($w) use ($col, $pk, $cmp, $lastVal, $lastId) {
                $w->where($col, $cmp, $lastVal)
                  ->orWhere(function ($w2) use ($col, $pk, $cmp, $lastVal, $lastId) {
                      $w2->where($col, '=', $lastVal)->where($pk, $cmp, $lastId);
                  });
            });
        }

        $q->orderBy($col, $dir)->orderBy($pk, $dir);
        $this->_eagerLoadColumns($q);

        $size  = min(max(1, $this->productPickerPageSize), self::BROWSE_LIMIT_CAP);
        // limit+1 responde "tem próxima?" sem um count() na tabela inteira.
        $fetch = $this->priceIsRelated() ? min(($size + 1) * 2, self::BROWSE_LIMIT_CAP * 2) : $size + 1;
        $recs  = $q->limit($fetch)->get();

        [$prices, $stocks] = $this->_relatedMapsFor($recs->all());
        if ($this->priceIsRelated()) {
            $recs = $recs->filter(
                fn ($r) => ($prices[(string) $r->getKey()] ?? null) !== null
            )->values();
        }

        $hasMore = $recs->count() > $size;
        $page    = $recs->take($size)->values();
        $last    = $page->last();
        $next    = $last === null ? '' : $this->_encodeCursor((string) $last->{$col}, (string) $last->getKey());

        return [
            $page->map(fn ($r) => $this->_lookupItem($r, $prices, $stocks))->all(),
            $hasMore ? $next : '',
            $hasMore,
        ];
    }

    /**
     * Pré-carrega as relações usadas por coluna de chain.
     *
     * Sem isso, `{categoria->nome}` num catálogo de 60 produtos vira 60
     * queries: o lazy-load do Eloquent dispara ao acessar a propriedade,
     * dentro do map que monta os itens.
     */
    protected function _eagerLoadColumns(object $q): void
    {
        $rels = $this->_columnEagerLoads();
        if ($rels !== []) {
            $q->with($rels);
        }
    }

    /** Cursor keyset: tupla (valor da coluna de ordem, PK) em base64 de JSON. */
    protected function _encodeCursor(string $val, string $id): string
    {
        return base64_encode(json_encode([$val, $id], JSON_UNESCAPED_UNICODE) ?: '[]');
    }

    /** @return array{0: string, 1: string}|null */
    protected function _decodeCursor(string $cursor): ?array
    {
        if ($cursor === '') {
            return null;
        }
        $raw = base64_decode($cursor, true);
        if ($raw === false) {
            return null;
        }
        $parsed = json_decode($raw, true);

        return is_array($parsed) && count($parsed) === 2
            ? [(string) $parsed[0], (string) $parsed[1]]
            : null;
    }

    protected function _searchLookup(string $fqcn, string $term): array
    {
        if (mb_strlen($term) < max(1, $this->searchMinLength)) {
            return [];
        }
        $q = $fqcn::query();
        $this->_applyProductWhere($q);
        $this->_applyActiveWhere($q);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $term) . '%';
        $q->where(function ($w) use ($like) {
            $w->whereLike($this->nameField, $like);
            foreach ([$this->codeField, $this->barcodeField] as $col) {
                if ($col !== '') {
                    $w->orWhereLike($col, $like);
                }
            }
        });
        [$col, $dir] = $this->_orderSpec();
        $q->orderBy($col, $dir);
        $this->_eagerLoadColumns($q);

        $limit = min(max(1, $this->searchLimit), self::SEARCH_LIMIT_CAP);
        // Preço relacionado: overfetch limitado ANTES do filtro de sem-preço,
        // senão a exclusão pós-limit devolve menos resultados do que existem.
        $fetch = $this->priceIsRelated() ? min($limit * 2, 40) : $limit;
        $recs  = $q->limit($fetch)->get();

        [$prices, $stocks] = $this->_relatedMapsFor($recs->all());
        if ($this->priceIsRelated()) {
            $recs = $recs->filter(
                fn ($r) => ($prices[(string) $r->getKey()] ?? null) !== null
            )->take($limit)->values();
        }

        return $recs->map(fn ($r) => $this->_lookupItem($r, $prices, $stocks))->all();
    }

    /**
     * Mapas batch de preço/estoque relacionados pros records dados
     * ([[], []] quando a fonte é a própria tabela do produto).
     *
     * @param  array<int, object> $recs
     * @return array{0: array<string, float|null>, 1: array<string, float|null>}
     */
    protected function _relatedMapsFor(array $recs): array
    {
        $pids = array_map(fn ($r) => $r->getKey(), $recs);

        return [
            $this->priceIsRelated() ? $this->_resolvePrices($pids) : [],
            $this->stockIsRelated() ? $this->_resolveStocks($pids) : [],
        ];
    }

    /**
     * @param array<string, float|null> $prices mapa relacionado (Rev. 3)
     * @param array<string, float|null> $stocks idem
     */
    protected function _lookupItem(object $rec, array $prices = [], array $stocks = []): array
    {
        $pid = (string) $rec->getKey();

        if ($this->priceIsRelated()) {
            // chamadores filtram no-price antes; o ?? 0 é só defensivo
            $price = $this->_r($prices[$pid] ?? 0);
        } else {
            $price = $this->_r($rec->{$this->priceField});
        }

        if ($this->stockIsRelated()) {
            $stock = (float) ($stocks[$pid] ?? 0.0); // linha ausente = 0
        } else {
            $stock = $this->stockField !== '' ? (float) $rec->{$this->stockField} : null;
        }

        $price = $this->_effectiveUnitPrice($rec, (float) $price);

        return [
            'id'      => $rec->getKey(),
            'code'    => $this->codeField !== '' ? (string) $rec->{$this->codeField} : null,
            'barcode' => $this->barcodeField !== '' ? (string) $rec->{$this->barcodeField} : null,
            'name'    => (string) $rec->{$this->nameField},
            'price'   => $price,
            'stock'   => $stock,
            'unit'    => $this->unitField !== '' ? (string) $rec->{$this->unitField} : null,
            'image'   => $this->imageField !== '' ? (string) $rec->{$this->imageField} : null,
        ] + $this->_columnValuesFor($rec);
    }

    /**
     * Marcadores que o autor pode escrever em `active-value` para dizer "esta
     * é a coluna booleana de ativo" (normalizados: minúsculo e sem espaço).
     */
    protected const ACTIVE_BOOL_MARKERS = ['1', 'true', 't'];

    /**
     * Família aceita como "ativo" quando o marcador é o booleano padrão.
     * Normalizados: minúsculo e sem espaço nas pontas.
     */
    protected const ACTIVE_TRUTHY_MARKERS = ['1', 'true', 't', 's', 'y', 'sim', 'yes'];

    /**
     * Valores que contam como "ativo" nesta configuração.
     *
     * POR QUE existe (incidente de 10/set/2026, 4 sessões de teste do PDV):
     * a carga inicial de produtos gravou a coluna `ativo` com o `T`/`F` do
     * legado Adianti, enquanto o PDV comparava com `1`. Resultado: TODO o
     * catálogo sumia da busca e do bip ("Produto não encontrado"), sem erro
     * nem log. Os geradores já gravam 1/0, mas o dado antigo continua nos
     * ambientes de teste, e app importado do legado pode trazer `T`/`S`/`true`
     * pra sempre. Daí o cinto: com o marcador booleano padrão, a coluna é lida
     * de forma tolerante.
     *
     * Marcador PRÓPRIO (ex.: `active-value="A"` numa coluna de status) NÃO é
     * afetado: devolve só ele, e a comparação segue exata como antes.
     *
     * @return array<int, string> 1 item = comparação exata; vários = tolerante
     */
    protected function _activeMarkers(): array
    {
        return in_array($this->_normalizeMarker($this->activeValue), self::ACTIVE_BOOL_MARKERS, true)
            ? self::ACTIVE_TRUTHY_MARKERS
            : [$this->activeValue];
    }

    /** O valor gravado na linha conta como "ativo" na família booleana? */
    protected function _isTruthyMarker(mixed $v): bool
    {
        return in_array($this->_normalizeMarker($v), self::ACTIVE_TRUTHY_MARKERS, true);
    }

    /** Forma canônica de um marcador: bool → 1/0, escalar → minúsculo aparado. */
    protected function _normalizeMarker(mixed $v): string
    {
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        if (!is_scalar($v)) { // null, array, objeto
            return '';
        }

        return strtolower(trim((string) $v));
    }

    /**
     * Filtro de "ativo" na consulta de produto (busca e catálogo).
     *
     * Marcador próprio → `where` exato, igual a sempre.
     *
     * Marcador booleano padrão → a família toda. E aqui a comparação NÃO pode
     * ser `whereIn($col, [1, 'T', 'S', ...])`: no MySQL, comparar coluna
     * numérica com string não-numérica converte a string pra 0, então
     * `tinyint 0` (inativo) casaria com `'T'` e produto desativado voltaria a
     * vender; no Postgres é pior, comparar `integer` com `'T'` aborta a query.
     * Por isso o `whereIn` roda sobre o TEXTO da coluna
     * (`lower(cast(col as char|text))`), que dá o mesmo resultado nos três
     * bancos — inclusive na coluna `boolean` do Postgres, que vira
     * `'true'`/`'false'`.
     */
    protected function _applyActiveWhere(object $q): void
    {
        if ($this->activeField === '') {
            return;
        }
        $markers = $this->_activeMarkers();

        // Marcador próprio (ou nome de coluna fora do padrão, que não pode
        // virar SQL cru): comparação exata, comportamento histórico.
        if (count($markers) === 1
            || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $this->activeField)) {
            $q->where($this->activeField, $this->activeValue);

            return;
        }

        $driver = $q->getConnection()->getDriverName();
        $type   = match ($driver) {
            'mysql', 'mariadb' => 'char',
            'sqlsrv'           => 'varchar(255)',
            default            => 'text',
        };
        $col = $q->getGrammar()->wrap($this->activeField);

        $q->whereIn(new \Illuminate\Database\Query\Expression("lower(cast({$col} as {$type}))"), $markers);
    }

    protected function _isActive(object $rec): bool
    {
        if ($this->activeField === '') {
            return true;
        }
        $raw = $rec->{$this->activeField} ?? null;

        // Família booleana: tolerante (ver _activeMarkers). Marcador próprio:
        // igualdade exata, como antes.
        if (count($this->_activeMarkers()) > 1) {
            return $this->_isTruthyMarker($raw);
        }

        return (string) $raw === (string) $this->activeValue;
    }

    /** @return array{0: string, 1: string} [coluna, direção] */
    protected function _orderSpec(): array
    {
        $spec = trim($this->orderBy);
        if ($spec !== '') {
            $parts = preg_split('/\s+/', $spec);
            $col   = $parts[0] ?? '';
            $dir   = strtolower($parts[1] ?? 'asc');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
                return [$col, $dir === 'desc' ? 'desc' : 'asc'];
            }
        }
        return [$this->nameField, 'asc'];
    }

    // ── Fontes relacionadas (Rev. 3 §3.1-bis) ─────────────────────────────

    public function priceIsRelated(): bool
    {
        return $this->priceModel !== '';
    }

    public function stockIsRelated(): bool
    {
        return $this->stockModel !== '';
    }

    /**
     * Resolução batch top-1-por-produto: 1 query `whereIn(key)+where+
     * orderBy(order, PK desc)` → get([key, field]) (só 2 colunas, SEM limit
     * — limit global famintaria pids) → colapso PHP primeira-ocorrência.
     * O tiebreak PK desc é SEMPRE anexado (empate seria não-determinístico
     * entre drivers). Valor NULL ou linha ausente → null (≠ 0.0 — o cast
     * `(float) null === 0.0` é a armadilha; testar null ANTES).
     *
     * @param  array<int|string> $pids
     * @return array<string, float|null> keyed por (string) id do produto
     */
    protected function _resolveRelated(
        string $model,
        string $key,
        string $field,
        string $whereStr,
        ?\Closure $whereClosure,
        string $order,
        array $pids,
    ): array {
        if ($pids === [] || $field === ''
            || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
            return [];
        }
        $fqcn = $this->_resolveModelFqcn($model);
        if ($fqcn === '') {
            return [];
        }
        $inst = new $fqcn();

        $q = $fqcn::query();
        if ($whereClosure instanceof \Closure) {
            ($whereClosure)($q);
        } else {
            $this->_applyDslWhere($q, $whereStr);
        }
        $q->whereIn($key, array_values($pids));

        [$oCol, $oDir] = $this->_relOrderSpec($order);
        if ($oCol !== '') {
            $q->orderBy($oCol, $oDir);
        }
        $q->orderBy($inst->getKeyName(), 'desc'); // tiebreak obrigatório

        $out = [];
        foreach ($q->get([$key, $field]) as $row) {
            $pid = (string) $row->{$key};
            if (array_key_exists($pid, $out)) {
                continue; // primeira ocorrência (na ordem) vence
            }
            $v = $row->{$field};
            $out[$pid] = $v === null ? null : (float) $v;
        }

        return $out;
    }

    /** @return array<string, float|null> preço por produto (null = no-price) */
    protected function _resolvePrices(array $pids): array
    {
        return $this->_resolveRelated(
            $this->priceModel, $this->priceKey, $this->priceField,
            $this->priceWhere, $this->priceWhereClosure, $this->priceOrder, $pids,
        );
    }

    /** @return array<string, float|null> saldo por produto (ausente = sem linha) */
    protected function _resolveStocks(array $pids): array
    {
        return $this->_resolveRelated(
            $this->stockModel, $this->stockKey, $this->stockField,
            $this->stockWhere, $this->stockWhereClosure, '', $pids,
        );
    }

    /** "col dir" com gate de identificador; '' ou inválido = só o tiebreak. */
    private function _relOrderSpec(string $spec): array
    {
        $spec = trim($spec);
        if ($spec === '') {
            return ['', 'asc'];
        }
        $parts = preg_split('/\s+/', $spec);
        $col   = $parts[0] ?? '';
        $dir   = strtolower($parts[1] ?? 'asc');
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $col)) {
            return ['', 'asc'];
        }

        return [$col, $dir === 'desc' ? 'desc' : 'asc'];
    }

    /** Aplica o escopo da tabela de SALDO numa query (decremento/pré-check). */
    protected function _applyStockScope(object $q): void
    {
        if ($this->stockWhereClosure instanceof \Closure) {
            ($this->stockWhereClosure)($q);

            return;
        }
        $this->_applyDslWhere($q, $this->stockWhere);
    }

    // ── Persistência internals ────────────────────────────────────────────

    /**
     * Stamp de escopo unit/tenant SÓ-QUANDO-VAZIO: as traits
     * BelongsToUnit/BelongsToTenant são opt-in por model e as flags nascem
     * OFF — auto-preenchimento não é garantido (red-team §18 item 4). Quando
     * as traits existem, o `creating()` delas também só preenche null — sem
     * conflito.
     */
    protected function _stampScope(object $model): void
    {
        try {
            $cols = $this->_columnListing($model);
            if ($cols === []) {
                return;
            }
            $uid = UnitContext::id();
            if ($uid !== null && in_array('unit_id', $cols, true)
                && $model->getAttribute('unit_id') === null) {
                $model->setAttribute('unit_id', $uid);
            }
            $tid = TenantContext::id();
            if ($tid !== null && in_array('tenant_id', $cols, true)
                && $model->getAttribute('tenant_id') === null) {
                $model->setAttribute('tenant_id', $tid);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Recibo remontado do banco (replay idempotente §7.7). */
    protected function _receiptFromDb(object $venda): array
    {
        $lines = [];
        $payments = [];
        $change = $this->saleChangeField !== '' ? (float) $venda->{$this->saleChangeField} : 0.0;

        try {
            $itemFqcn = $this->_resolveModelFqcn($this->itemModel);
            $prodFqcn = $this->_resolveModelFqcn($this->model);
            $payFqcn  = $this->_resolveModelFqcn($this->paymentModel);
            $prodPk   = (new $prodFqcn())->getKeyName();

            $items = $itemFqcn::query()->where($this->itemSaleField, $venda->getKey())->get();
            $prods = $prodFqcn::query()
                ->whereIn($prodPk, $items->pluck($this->itemProductField)->all())
                ->get()
                ->keyBy(fn ($r) => (string) $r->getKey());

            foreach ($items as $it) {
                $rec = $prods[(string) $it->{$this->itemProductField}] ?? null;
                $lines[] = [
                    'qty'       => (float) $it->{$this->itemQtyField},
                    'unit'      => ($rec && $this->unitField !== '') ? (string) $rec->{$this->unitField} : '',
                    'name'      => $rec ? (string) $rec->{$this->nameField} : ('#' . $it->{$this->itemProductField}),
                    'unitPrice' => (float) $it->{$this->itemPriceField},
                    'discount'  => $this->itemDiscountField !== '' ? (float) $it->{$this->itemDiscountField} : 0.0,
                    'total'     => (float) $it->{$this->itemTotalField},
                ];
            }

            $methods = $this->getPayments();
            foreach ($payFqcn::query()->where($this->paymentSaleField, $venda->getKey())->get() as $pay) {
                $method = (string) $pay->{$this->paymentMethodField};
                $payments[] = [
                    'label'    => isset($methods[$method]) ? $methods[$method]->label : $method,
                    'amount'   => (float) $pay->{$this->paymentAmountField},
                    'tendered' => $this->paymentTenderedField !== ''
                        ? ($pay->{$this->paymentTenderedField} !== null ? (float) $pay->{$this->paymentTenderedField} : null)
                        : null,
                ];
            }
        } catch (\Throwable $e) {
        }

        $subtotal = $this->saleSubtotalField !== ''
            ? (float) $venda->{$this->saleSubtotalField}
            : $this->_r(array_sum(array_column($lines, 'total')));
        $discount = $this->saleDiscountField !== '' ? (float) $venda->{$this->saleDiscountField} : 0.0;

        return [
            'header'   => $this->receiptHeader,
            'footer'   => $this->receiptFooter !== '' ? $this->receiptFooter : (string) __('pdv.thanks'),
            'datetime' => date('c'),
            'operator' => (string) (session('username') ?? ''),
            'customer' => '',
            'document' => $this->saleDocumentField !== '' ? (string) ($venda->{$this->saleDocumentField} ?? '') : '',
            'lines'    => $lines,
            'totals'   => [
                'subtotal' => $subtotal,
                'discount' => $discount,
                'total'    => (float) $venda->{$this->saleTotalField},
            ],
            'payments' => $payments,
            'change'   => $change,
            'width'    => $this->receiptWidthEffective(),
        ];
    }

    // ── Eventos / respostas ───────────────────────────────────────────────

    protected function _saleDone(object $venda, bool $already, array $receipt): MadResponse
    {
        // O cupom lê o número de DENTRO do recibo (`lastReceipt.number`) — ele
        // só vinha no detail do evento e o impresso saía "Venda nº undefined".
        $receipt['number'] = (string) ($receipt['number'] ?? $venda->getKey());

        return (new MadResponse())
            ->toast((string) __('pdv.sale_done'), 'success')
            ->script($this->_pdvEvent('mad-pdv:sale-done', [
                'saleId'           => $venda->getKey(),
                'number'           => (string) $venda->getKey(),
                'change'           => (float) ($receipt['change'] ?? 0),
                'alreadyProcessed' => $already,
                'receipt'          => $receipt,
            ]));
    }

    protected function _saleError(string $code, array $details = [], bool $recoverable = true): MadResponse
    {
        $msg = (string) __('pdv.err_' . str_replace('-', '_', $code));
        return (new MadResponse())
            ->toast($msg, 'error')
            ->script($this->_pdvEvent('mad-pdv:sale-error', [
                'code'        => $code,
                'message'     => $msg,
                'recoverable' => $recoverable,
                'details'     => $details,
            ]));
    }

    /** JS que despacha um CustomEvent no container deste PDV (espelha _sheetEvent). */
    protected function _pdvEvent(string $name, array $detail): string
    {
        $key   = json_encode($this->pdvCfgKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $event = json_encode($name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $data  = json_encode($detail, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
        return "document.querySelectorAll('[data-mad-pdv=' + JSON.stringify({$key}) + ']')"
            . ".forEach(function (el) { el.dispatchEvent(new CustomEvent({$event}, { detail: {$data} })); });";
    }

    // ── Validação de documento (CPF/CNPJ) ─────────────────────────────────

    protected function _validDocument(string $doc): bool
    {
        $d = preg_replace('/\D/', '', $doc) ?? '';
        if (strlen($d) === 11) {
            return $this->_validCpf($d);
        }
        if (strlen($d) === 14) {
            return $this->_validCnpj($d);
        }
        return false;
    }

    private function _validCpf(string $d): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $d)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $d[$t] !== $digit) {
                return false;
            }
        }
        return true;
    }

    private function _validCnpj(string $d): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $d)) {
            return false;
        }
        $weights = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        for ($t = 12; $t < 14; $t++) {
            $w   = $t === 12 ? $weights : array_merge([6], $weights);
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $d[$i] * $w[$i];
            }
            $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ((int) $d[$t] !== $digit) {
                return false;
            }
        }
        return true;
    }

    /** Arredondamento normativo (§7.6): half-up, 2 casas. */
    protected function _r(mixed $v): float
    {
        return round((float) $v, 2);
    }

    // ── Internals ─────────────────────────────────────────────────────────

    protected function _db(): string
    {
        if (!empty($this->database)) {
            return $this->database;
        }
        return defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business';
    }

    protected function _resolveModelFqcn(string $model): string
    {
        if ($model === '') {
            return '';
        }
        try {
            return ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            return '';
        }
    }
}
