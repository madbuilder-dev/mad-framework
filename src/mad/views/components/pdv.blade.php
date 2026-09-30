@php
    // Template interno — só renderiza via MadPdvComponent (view()/_renderInlinePdv),
    // que injeta $__component. Sem host (render direto/preview), aborta cedo.
    if (!isset($__component) || !$__component instanceof \Mad\Pdv\MadPdvComponent) {
        return;
    }

    // §10.3 — config incompleta: painel de erro SEMPRE visível (nunca tela
    // branca; MadGridCompiler::warn é APP_DEBUG-gated e não serve de precedente
    // aqui — o público é quem está configurando a tela).
    $cfgErrors = $__component->configErrors();
@endphp

@if (!empty($cfgErrors))
    <div class="mad-alert mad-alert-danger mad-pdv-config-error">
        <div class="mad-alert-title">&lt;mad-pdv&gt; — configuração incompleta</div>
        <ul>
            @foreach ($cfgErrors as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
    </div>
    @php return; @endphp
@endif

@php
    $pdvKey    = $__component->pdvCfgKey;
    $clientCfg = $__component->getClientConfig();
    $customer  = $clientCfg['customer'];
    $cfgJson   = json_encode($clientCfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
@endphp

{{-- x-data com aspas SIMPLES: o JSON usa aspas duplas e JSON_HEX_APOS garante
     que nenhum apóstrofo cru escapa do atributo (padrão mad-sheet). --}}
<div class="mad-pdv"
     data-mad-pdv="{{ $pdvKey }}"
     x-data='madPdv({!! $cfgJson !!})'
     :class="{ 'mad-pdv--fullscreen': fullscreen, 'mad-pdv--compact': cfg.behavior.density === 'compact' }"
     @keydown.window="onHotkey($event)">

    {{-- ═══ HEADER ═══ --}}
    <header class="mad-pdv-header">
        <div class="mad-pdv-header-left">
            <i data-lucide="shopping-cart"></i>
            <span class="mad-pdv-title" x-text="cfg.title"></span>
        </div>
        <div class="mad-pdv-header-right">
            <span class="mad-pdv-meta" x-show="cfg.operator">
                {{ __('pdv.operator') }}: <strong x-text="cfg.operator"></strong>
            </span>
            <span class="mad-pdv-meta mad-pdv-clock" x-text="clock"></span>
            <button type="button" class="mad-pdv-chip" x-show="cfg.behavior.holdSales && heldCount > 0" x-cloak
                    @click="toggleHeld()">
                <i data-lucide="pause-circle"></i>
                <span x-text="heldCount"></span> {{ __('pdv.held_sales') }}
            </button>
            <button type="button" class="mad-pdv-iconbtn" x-show="cfg.behavior.fullscreenToggle"
                    :aria-label="fullscreen ? cfg.i18n.close : cfg.i18n.fullscreen"
                    @click="toggleFullscreen()">
                <i data-lucide="maximize-2" x-show="!fullscreen"></i>
                <i data-lucide="minimize-2" x-show="fullscreen" x-cloak></i>
            </button>
        </div>
    </header>

    <div class="mad-pdv-body">

        {{-- ═══ COLUNA PRINCIPAL: scan + carrinho ═══ --}}
        <section class="mad-pdv-main">

            {{-- Scan bar — coração do caixa; foco volta pra cá após TODA operação --}}
            <div class="mad-pdv-scanbar">
                <i data-lucide="scan-line"></i>
                <input type="text" class="mad-pdv-scan-input" x-ref="scan"
                       autocomplete="off" spellcheck="false"
                       :placeholder="cfg.i18n.scan_placeholder"
                       x-model="scanTerm"
                       @keydown="onScanKey($event)"
                       @input="onScanInput()">

                {{-- Atalho de mouse pro catálogo: quem não tem leitor não
                     descobre o modal olhando só pra barra de código. --}}
                <button type="button" class="mad-pdv-scan-pick"
                        x-show="cfg.behavior.productPicker" x-cloak
                        :title="cfg.i18n.choose_product + ' (' + cfg.behavior.productPickerHotkey + ')'"
                        :aria-label="cfg.i18n.choose_product"
                        @click="openPicker()">
                    <i data-lucide="list"></i>
                    <span class="mad-pdv-scan-pick-label" x-text="cfg.i18n.choose_product"></span>
                    <kbd x-text="cfg.behavior.productPickerHotkey"></kbd>
                </button>

                {{-- Dropdown de resultados da busca textual --}}
                <div class="mad-pdv-results" x-show="searchOpen" x-cloak @click.outside="closeSearch()">
                    <template x-for="(p, i) in results" :key="p.id">
                        <button type="button" class="mad-pdv-result"
                                :class="{ 'is-active': i === hi }"
                                @click="pick(i)">
                            <img class="mad-pdv-result-img" x-show="cfg.behavior.showImages && p.image" :src="p.image" alt="">
                            <span class="mad-pdv-result-name" x-text="p.name"></span>
                            <span class="mad-pdv-result-code" x-text="p.code || p.barcode || ''"></span>
                            <span class="mad-pdv-result-price" x-text="money(toC(p.price))"></span>
                        </button>
                    </template>
                    <div class="mad-pdv-result-empty" x-show="!results.length" x-text="searchReason"></div>
                </div>
            </div>

            {{-- Carrinho --}}
            <div class="mad-pdv-cart">
                <table class="mad-pdv-cart-table">
                    <thead>
                        <tr>
                            <th class="mad-pdv-col-num">#</th>
                            <th>{{ __('pdv.product') }}</th>
                            <template x-for="c in colsAt('after-product')" :key="c.key">
                                <th :style="c.width ? ('width:' + c.width) : ''"
                                    :class="'mad-pdv-col-align-' + c.align" x-text="c.label"></th>
                            </template>
                            <th class="mad-pdv-col-qty">{{ __('pdv.qty') }}</th>
                            <th class="mad-pdv-col-money">{{ __('pdv.unit_price') }}</th>
                            <th class="mad-pdv-col-money" x-show="cfg.behavior.discountItem">{{ __('pdv.item_discount') }}</th>
                            <template x-for="c in colsAt('before-total')" :key="c.key">
                                <th :style="c.width ? ('width:' + c.width) : ''"
                                    :class="'mad-pdv-col-align-' + c.align" x-text="c.label"></th>
                            </template>
                            <th class="mad-pdv-col-money">{{ __('pdv.item_total') }}</th>
                            <th class="mad-pdv-col-actions"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(l, i) in cart" :key="l.uid">
                            <tr :class="{ 'is-selected': i === selLine, 'has-stock-warn': l.stockWarn }"
                                @click="selLine = i">
                                <td class="mad-pdv-col-num" x-text="i + 1"></td>
                                <td class="mad-pdv-cell-product">
                                    <img class="mad-pdv-line-img" x-show="cfg.behavior.showImages && l.image" :src="l.image" alt="">
                                    <div class="mad-pdv-line-name">
                                        <span x-text="l.name"></span>
                                        <small x-text="l.code || l.barcode || ''"></small>
                                        <small class="mad-pdv-stock-badge" x-show="l.stockWarn" x-cloak
                                               x-text="cfg.i18n.stock_short"></small>
                                    </div>
                                </td>
                                <template x-for="c in colsAt('after-product')" :key="c.key">
                                    <td :class="'mad-pdv-col-align-' + c.align">
                                        <span x-show="!c.input" x-text="l.disp[c.key]"></span>
                                        <input x-show="c.input && c.input !== 'combo'" x-cloak
                                               class="mad-pdv-col-input" :type="c.input"
                                               :maxlength="c.maxlength" :required="c.required"
                                               :value="l.cols[c.key]" @click.stop
                                               @input="setLineCol(i, c, $event.target.value)">
                                        <select x-show="c.input === 'combo'" x-cloak
                                                class="mad-pdv-col-input" @click.stop
                                                @change="setLineCol(i, c, $event.target.value)">
                                            <option value=""></option>
                                            <template x-for="o in (c.options || [])" :key="o.value">
                                                <option :value="o.value" :selected="l.cols[c.key] === o.value"
                                                        x-text="o.label"></option>
                                            </template>
                                        </select>
                                    </td>
                                </template>
                                <td class="mad-pdv-col-qty">
                                    <div class="mad-pdv-qty">
                                        <button type="button" tabindex="-1" @click.stop="incQty(i, -1)">&minus;</button>
                                        <input type="text" inputmode="decimal"
                                               :value="fmtQty(l.qty)"
                                               @change="setQty(i, $event.target.value)"
                                               @click.stop>
                                        <button type="button" tabindex="-1" @click.stop="incQty(i, 1)">+</button>
                                    </div>
                                    <small class="mad-pdv-unit" x-show="l.unit" x-text="l.unit"></small>
                                </td>
                                <td class="mad-pdv-col-money">
                                    <template x-if="cfg.behavior.allowPriceOverride">
                                        <input type="text" inputmode="decimal" class="mad-pdv-money-input"
                                               :value="money(l.priceC)"
                                               @change="setLinePrice(i, $event.target.value)"
                                               @click.stop>
                                    </template>
                                    <template x-if="!cfg.behavior.allowPriceOverride">
                                        <span x-text="money(l.priceC)"></span>
                                    </template>
                                </td>
                                <td class="mad-pdv-col-money" x-show="cfg.behavior.discountItem">
                                    <div class="mad-pdv-disc-wrap">
                                        <input type="text" inputmode="decimal" class="mad-pdv-money-input"
                                               :value="l.discInput"
                                               :placeholder="discPlaceholder(l.discUnit)"
                                               @change="setLineDisc(i, $event.target.value)"
                                               @click.stop>
                                        <button type="button" class="mad-pdv-disc-unit" tabindex="-1"
                                                x-show="discountBoth"
                                                :aria-label="(l.discUnit === 'percent' ? '%' : cfg.currency.symbol)"
                                                @click.stop="toggleLineDiscUnit(i)"
                                                x-text="l.discUnit === 'percent' ? '%' : cfg.currency.symbol"></button>
                                        <span class="mad-pdv-disc-unit is-static" x-show="!discountBoth"
                                              x-text="cfg.behavior.discountInput === 'percent' ? '%' : cfg.currency.symbol"></span>
                                    </div>
                                </td>
                                <template x-for="c in colsAt('before-total')" :key="c.key">
                                    <td :class="'mad-pdv-col-align-' + c.align">
                                        <span x-show="!c.input" x-text="l.disp[c.key]"></span>
                                        <input x-show="c.input && c.input !== 'combo'" x-cloak
                                               class="mad-pdv-col-input" :type="c.input"
                                               :maxlength="c.maxlength" :required="c.required"
                                               :value="l.cols[c.key]" @click.stop
                                               @input="setLineCol(i, c, $event.target.value)">
                                        <select x-show="c.input === 'combo'" x-cloak
                                                class="mad-pdv-col-input" @click.stop
                                                @change="setLineCol(i, c, $event.target.value)">
                                            <option value=""></option>
                                            <template x-for="o in (c.options || [])" :key="o.value">
                                                <option :value="o.value" :selected="l.cols[c.key] === o.value"
                                                        x-text="o.label"></option>
                                            </template>
                                        </select>
                                    </td>
                                </template>
                                <td class="mad-pdv-col-money mad-pdv-line-total" x-text="money(lineTotalC(l))"></td>
                                <td class="mad-pdv-col-actions">
                                    <button type="button" class="mad-pdv-iconbtn" tabindex="-1"
                                            :aria-label="cfg.i18n.remove_item"
                                            @click.stop="removeLine(i)">
                                        <i data-lucide="trash-2"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr class="mad-pdv-cart-empty" x-show="!cart.length">
                            {{-- Contagem, não literal: com coluna custom (e com o
                                 desconto por item desligado) o 7 fixo já mentia. --}}
                            <td :colspan="cartColCount">
                                <i data-lucide="scan-barcode"></i>
                                <span x-text="cfg.i18n.scan_placeholder"></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            {{-- Legenda de hotkeys --}}
            <footer class="mad-pdv-hotkeys" aria-hidden="true">
                <span x-show="cfg.behavior.productPicker">
                    <kbd x-text="cfg.behavior.productPickerHotkey"></kbd>
                    <span x-text="cfg.i18n.choose_product"></span>
                </span>
                <span x-show="!cfg.behavior.productPicker"><kbd>F2</kbd> {{ __('pdv.product') }}</span>
                <span x-show="customerEnabled"><kbd>F4</kbd> {{ __('pdv.customer') }}</span>
                <span x-show="cfg.behavior.discountTotal"><kbd>F6</kbd> {{ __('pdv.discount') }}</span>
                <span x-show="cfg.behavior.holdSales"><kbd>F8</kbd> {{ __('pdv.hold') }}</span>
                <span><kbd>F10</kbd> {{ __('pdv.finalize') }}</span>
                <span><kbd>Del</kbd> {{ __('pdv.remove_item') }}</span>
                <span><kbd>Ctrl+Del</kbd> {{ __('pdv.clear_sale') }}</span>
            </footer>
        </section>

        {{-- ═══ COLUNA LATERAL: totais + cliente + pagamento ═══ --}}
        <aside class="mad-pdv-side">

            {{-- Totais --}}
            <div class="mad-pdv-totals">
                <div class="mad-pdv-total-row">
                    <span>{{ __('pdv.subtotal') }}</span>
                    <span x-text="money(subtotalC)"></span>
                </div>
                <div class="mad-pdv-total-row" x-show="cfg.behavior.discountTotal">
                    <span>{{ __('pdv.discount') }}</span>
                    <span class="mad-pdv-total-disc">
                        <div class="mad-pdv-disc-wrap">
                            <input type="text" inputmode="decimal" class="mad-pdv-money-input" x-ref="saleDisc"
                                   :value="saleDiscInput"
                                   :placeholder="discPlaceholder(saleDiscUnit)"
                                   @change="setSaleDisc($event.target.value)">
                            <button type="button" class="mad-pdv-disc-unit" tabindex="-1"
                                    x-show="discountBoth"
                                    :aria-label="(saleDiscUnit === 'percent' ? '%' : cfg.currency.symbol)"
                                    @click="toggleSaleDiscUnit()"
                                    x-text="saleDiscUnit === 'percent' ? '%' : cfg.currency.symbol"></button>
                            <span class="mad-pdv-disc-unit is-static" x-show="!discountBoth"
                                  x-text="cfg.behavior.discountInput === 'percent' ? '%' : cfg.currency.symbol"></span>
                        </div>
                        <small class="mad-pdv-disc-hint" x-show="saleDiscUnit === 'percent' && saleDiscC > 0"
                               x-text="'= ' + money(saleDiscC)"></small>
                    </span>
                </div>
                <div class="mad-pdv-total-row mad-pdv-total-grand">
                    <span>{{ __('pdv.total') }}</span>
                    <span x-text="money(totalC)"></span>
                </div>
            </div>

            {{-- Cliente (opcional — dbsearch existente, endpoint zero) --}}
            @if (($customer['enabled'] ?? false))
                <div class="mad-pdv-customer">
                    <label>
                        {{ $customer['required'] ? __('pdv.customer') : __('pdv.customer_optional') }}
                        <span class="mad-pdv-req" @if (!$customer['required']) style="display:none" @endif>*</span>
                    </label>
                    <select class="mad-pdv-customer-select" x-ref="customer" data-mad-dbsearch
                            data-mad-search-token="{{ $customer['searchToken'] }}"
                            data-min-length="{{ $customer['minLength'] }}"
                            data-placeholder="{{ __('pdv.customer_optional') }}"
                            @change="customerId = $event.target.value || null">
                        <option value=""></option>
                        @if (!empty($customer['defaultId']))
                            <option value="{{ $customer['defaultId'] }}" selected>{{ $customer['defaultLabel'] !== '' ? $customer['defaultLabel'] : ('#' . $customer['defaultId']) }}</option>
                        @endif
                    </select>
                </div>
            @endif

            {{-- Pagamento --}}
            <div class="mad-pdv-pay">
                <div class="mad-pdv-pay-title">{{ __('pdv.payment') }}</div>

                <div class="mad-pdv-pay-methods">
                    <template x-for="p in cfg.payments" :key="p.method">
                        <button type="button" class="mad-pdv-pay-method"
                                :class="{ 'is-active': payMethod && payMethod.method === p.method }"
                                :disabled="!cart.length || remainingC <= 0"
                                @click="choosePay(p)">
                            <i :data-lucide="p.icon"></i>
                            <span x-text="p.label"></span>
                            <kbd x-show="p.hotkey" x-text="p.hotkey"></kbd>
                        </button>
                    </template>
                </div>

                {{-- Entrada de valor da forma escolhida --}}
                <div class="mad-pdv-pay-entry" x-show="payMethod" x-cloak>
                    <label x-text="payMethod ? payMethod.label : ''"></label>
                    <input type="text" inputmode="decimal" class="mad-pdv-pay-amount" x-ref="payAmount"
                           :value="payAmount"
                           @input="payAmount = $event.target.value"
                           @keydown.enter.prevent="addPayment()"
                           @keydown.escape.prevent="payMethod = null">
                    <button type="button" class="mad-pdv-btn" @click="addPayment()">
                        {{ __('pdv.add_payment') }}
                    </button>

                    {{-- Parcelamento (Rev. 5): só aparece na forma que aceita --}}
                    <div class="mad-pdv-installments"
                         x-show="payMethod && payMethod.maxInstallments > 1" x-cloak>
                        <label>
                            <span x-text="cfg.i18n.installments"></span>
                            <select x-model.number="payInstallments">
                                <template x-for="n in (payMethod ? payMethod.maxInstallments : 1)" :key="n">
                                    <option :value="n" x-text="installmentOptionLabel(n)"></option>
                                </template>
                            </select>
                        </label>
                        <label>
                            <span x-text="cfg.i18n.down_payment"></span>
                            <input type="text" inputmode="decimal" x-model="payDown"
                                   :placeholder="cfg.currency.symbol + ' 0,00'">
                        </label>
                        <ul class="mad-pdv-installment-preview" x-show="installmentPreview.length" x-cloak>
                            <template x-for="p in installmentPreview" :key="p.n">
                                <li>
                                    <span x-text="p.n + '/' + p.total"></span>
                                    <span x-text="p.dueLabel"></span>
                                    <strong x-text="money(p.amountC)"></strong>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                {{-- Pagamentos adicionados --}}
                <ul class="mad-pdv-pay-list" x-show="payments.length" x-cloak>
                    <template x-for="(pm, i) in payments" :key="i">
                        <li>
                            <span x-text="payLabel(pm.method)"></span>
                            <span x-text="money(pm.amountC)"></span>
                            <small x-show="pm.tenderedC > pm.amountC"
                                   x-text="'{{ __('pdv.change') }} ' + money(pm.tenderedC - pm.amountC)"></small>
                            <button type="button" class="mad-pdv-iconbtn" tabindex="-1"
                                    @click="removePayment(i)"><i data-lucide="x"></i></button>
                        </li>
                    </template>
                </ul>

                <div class="mad-pdv-pay-status">
                    {{-- Com 3 entradas na tela, só o "Restante" obrigava o operador
                         a somar de cabeça pra conferir o que já entrou. --}}
                    <div class="mad-pdv-total-row" x-show="payments.length" x-cloak>
                        <span>{{ __('pdv.paid') }}</span>
                        <strong class="mad-pdv-paid" x-text="money(paidC)"></strong>
                    </div>
                    <div class="mad-pdv-total-row" x-show="remainingC > 0">
                        <span>{{ __('pdv.remaining') }}</span>
                        <strong class="mad-pdv-remaining" x-text="money(remainingC)"></strong>
                    </div>
                    <div class="mad-pdv-total-row" x-show="changeNowC > 0">
                        <span>{{ __('pdv.change') }}</span>
                        <strong class="mad-pdv-change" x-text="money(changeNowC)"></strong>
                    </div>
                </div>

                <button type="button" class="mad-pdv-btn mad-pdv-btn--finalize"
                        :disabled="!canFinalize || saving"
                        @click="finalize()">
                    <i data-lucide="check-circle-2"></i>
                    <span x-text="saving ? '…' : cfg.i18n.finalize + ' (F10)'"></span>
                </button>

                {{-- Ações custom + utilitários --}}
                <div class="mad-pdv-actions">
                    {{-- Quem não tem leitor de código depende de saber o nome ou
                         o código de cor: a scan bar exige termo. --}}
                    <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                            x-show="cfg.behavior.productPicker"
                            @click="openPicker()">
                        <i data-lucide="search"></i> <span x-text="cfg.i18n.choose_product"></span>
                        <kbd x-text="cfg.behavior.productPickerHotkey"></kbd>
                    </button>
                    <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                            x-show="cfg.behavior.holdSales" :disabled="!cart.length"
                            @click="holdCurrent()">
                        <i data-lucide="pause"></i> {{ __('pdv.hold') }} <kbd>F8</kbd>
                    </button>
                    <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                            x-show="cfg.behavior.printMode !== 'off'" :disabled="!lastReceipt"
                            @click="printReceipt()">
                        <i data-lucide="printer"></i> {{ __('pdv.reprint') }}
                    </button>
                    <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost mad-pdv-btn--danger"
                            :disabled="!cart.length"
                            @click="askClear()">
                        <i data-lucide="trash"></i> {{ __('pdv.clear_sale') }}
                    </button>
                    <template x-for="a in cfg.actions" :key="a.method">
                        <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                                @click="runAction(a)">
                            <i x-show="a.icon" :data-lucide="a.icon"></i>
                            <span x-text="a.label"></span>
                        </button>
                    </template>
                </div>
            </div>
        </aside>
    </div>

    {{-- ═══ OVERLAYS ═══ --}}

    {{-- CPF/CNPJ na nota --}}
    <div class="mad-pdv-overlay" x-show="docPrompt.open" x-cloak>
        <div class="mad-pdv-modal">
            <div class="mad-pdv-modal-title">{{ __('pdv.document_prompt') }}</div>
            <input type="text" inputmode="numeric" x-ref="docInput"
                   x-model="docPrompt.value"
                   @keydown.enter.prevent="confirmDoc()"
                   @keydown.escape.prevent="skipDoc()">
            <div class="mad-pdv-modal-actions">
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost" @click="skipDoc()">{{ __('pdv.cancel') }}</button>
                <button type="button" class="mad-pdv-btn" @click="confirmDoc()">{{ __('pdv.confirm_sale') }}</button>
            </div>
        </div>
    </div>

    {{-- Confirmação de cancelar venda --}}
    <div class="mad-pdv-overlay" x-show="clearConfirm" x-cloak>
        <div class="mad-pdv-modal">
            <div class="mad-pdv-modal-title">{{ __('pdv.clear_sale_confirm') }}</div>
            <div class="mad-pdv-modal-actions">
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost" @click="clearConfirm = false">{{ __('pdv.cancel') }}</button>
                <button type="button" class="mad-pdv-btn mad-pdv-btn--danger" @click="doClear()">{{ __('pdv.clear_sale') }}</button>
            </div>
        </div>
    </div>

    {{-- Catálogo de produtos (Rev. 5) --}}
    <div class="mad-pdv-overlay" x-show="picker.open" x-cloak @keydown.escape.window="closePicker()">
        <div class="mad-pdv-modal mad-pdv-modal--picker">
            <div class="mad-pdv-modal-title" x-text="cfg.i18n.choose_product"></div>

            <input type="text" class="mad-pdv-picker-search" x-model="picker.term"
                   :placeholder="cfg.i18n.search_placeholder"
                   @input.debounce.300ms="pickerSearch()"
                   @keydown.enter.prevent="pickerPickFirst()"
                   x-ref="pickerSearch">

            <div class="mad-pdv-picker-grid">
                <template x-for="(p, i) in picker.items" :key="p.id">
                    <button type="button" class="mad-pdv-picker-card" @click="pickerPick(p)">
                        <img class="mad-pdv-picker-img" x-show="cfg.behavior.showImages && p.image"
                             :src="p.image" alt="">
                        <span class="mad-pdv-picker-name" x-text="p.name"></span>
                        <span class="mad-pdv-picker-code" x-text="p.code || p.barcode || ''"></span>
                        <span class="mad-pdv-picker-price" x-text="money(toC(p.price))"></span>
                        {{-- Saldo: só existe quando stock-field (ou stock-model)
                             está mapeado — sem mapeamento o lookup manda null. --}}
                        <span class="mad-pdv-picker-stock" x-show="hasStock(p)" x-cloak
                              :class="{ 'is-empty': hasStock(p) && Number(p.stock) <= 0 }"
                              x-text="stockLabel(p)"></span>
                    </button>
                </template>
            </div>

            <div class="mad-pdv-picker-empty" x-show="!picker.items.length && !picker.loading"
                 x-text="cfg.i18n.not_found"></div>
            <div class="mad-pdv-picker-empty" x-show="picker.loading" x-text="'…'"></div>

            <div class="mad-pdv-modal-actions">
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                        :disabled="!picker.page" @click="pickerPrev()"
                        x-text="cfg.i18n.page_prev"></button>
                <span class="mad-pdv-picker-page" x-text="(picker.page + 1)"></span>
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                        :disabled="!picker.hasMore" @click="pickerNext()"
                        x-text="cfg.i18n.page_next"></button>
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                        @click="closePicker()">{{ __('pdv.close') }}</button>
            </div>
        </div>
    </div>

    {{-- Vendas em espera --}}
    <div class="mad-pdv-overlay" x-show="heldOpen" x-cloak @keydown.escape.window="heldOpen = false">
        <div class="mad-pdv-modal mad-pdv-modal--held">
            <div class="mad-pdv-modal-title">{{ __('pdv.held_sales') }}</div>
            <ul class="mad-pdv-held-list">
                <template x-for="h in held" :key="h.holdId">
                    <li>
                        <span x-text="h.label"></span>
                        <button type="button" class="mad-pdv-btn" @click="resumeHeld(h.holdId)">{{ __('pdv.resume') }}</button>
                        <button type="button" class="mad-pdv-iconbtn" @click="deleteHeld(h.holdId)"><i data-lucide="trash-2"></i></button>
                    </li>
                </template>
            </ul>
            <div class="mad-pdv-held-empty" x-show="!held.length" x-text="cfg.i18n.held_empty"></div>
            <div class="mad-pdv-modal-actions">
                <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost" @click="heldOpen = false">{{ __('pdv.close') }}</button>
            </div>
        </div>
    </div>

    {{-- Troco (pós-venda) --}}
    <div class="mad-pdv-overlay mad-pdv-overlay--change" x-show="changeOverlay.open" x-cloak
         @click="closeChange()" @keydown.enter.window="changeOverlay.open && closeChange()">
        <div class="mad-pdv-change-card">
            <div class="mad-pdv-change-label">{{ __('pdv.change') }}</div>
            <div class="mad-pdv-change-value" x-text="money(changeOverlay.valueC)"></div>
            <div class="mad-pdv-change-hint" x-text="cfg.i18n.sale_done"></div>
        </div>
    </div>

    {{-- ═══ CUPOM ═══
         Fora do print só aparece no FALLBACK: quando o `print()` é ignorado
         (iframe sandbox sem `allow-modals` — preview do MadBuilder), o cupom
         vira uma folha na tela em vez de sumir sem aviso. --}}
    <div class="mad-pdv-receipt-wrap" :class="{ 'is-preview': receiptPreview }">
        <div class="mad-pdv-receipt-note" x-show="receiptPreview" x-cloak>
            <span x-text="cfg.i18n.print_blocked"></span>
            <button type="button" class="mad-pdv-btn mad-pdv-btn--ghost"
                    @click="closeReceiptPreview()">{{ __('pdv.close') }}</button>
        </div>
        <div class="mad-pdv-receipt" :class="'mad-pdv-receipt--w' + cfg.receipt.width" aria-hidden="true">
            <template x-if="lastReceipt">
                <div>
                    <pre class="mad-pdv-receipt-header" x-show="cfg.receipt.header" x-text="cfg.receipt.header"></pre>
                    <div class="mad-pdv-receipt-fiscal">{{ __('pdv.non_fiscal') }}</div>
                    <div class="mad-pdv-receipt-meta">
                        <span x-text="cfg.i18n.sale_number.replace(':n', lastReceipt.number)"></span>
                        <span x-text="fmtDateTime(lastReceipt.datetime)"></span>
                        <span x-show="lastReceipt.operator" x-text="cfg.i18n.operator + ': ' + lastReceipt.operator"></span>
                        <span x-show="lastReceipt.customer" x-text="cfg.i18n.customer + ': ' + lastReceipt.customer"></span>
                        <span x-show="lastReceipt.document" x-text="lastReceipt.document"></span>
                    </div>
                    <div class="mad-pdv-receipt-lines">
                        {{-- Um único elemento raiz por <template x-for>: o Alpine clona só o
                             firstElementChild — com o <template> das colunas como 2º irmão,
                             ele era descartado (aviso "x-for templates require a single root
                             element") e as colunas extras nunca saíam no cupom. --}}
                        <template x-for="(ln, i) in lastReceipt.lines" :key="i">
                            <div class="mad-pdv-receipt-entry">
                                <div class="mad-pdv-receipt-line">
                                    <span class="rl-name" x-text="ln.name"></span>
                                    <span class="rl-calc" x-text="fmtQty(ln.qty) + (ln.unit ? ln.unit : '') + ' x ' + money(toC(ln.unitPrice))"></span>
                                    <span class="rl-total" x-text="money(toC(ln.total))"></span>
                                </div>
                                <template x-for="cc in (ln.cols || [])" :key="cc.label">
                                    <div class="mad-pdv-receipt-line mad-pdv-receipt-col">
                                        <span class="rl-name" x-text="cc.label + ': ' + cc.value"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                    <div class="mad-pdv-receipt-totals">
                        <div><span>{{ __('pdv.subtotal') }}</span><span x-text="money(toC(lastReceipt.totals.subtotal))"></span></div>
                        <div x-show="lastReceipt.totals.discount > 0"><span>{{ __('pdv.discount') }}</span><span x-text="'-' + money(toC(lastReceipt.totals.discount))"></span></div>
                        <div class="rt-grand"><span>{{ __('pdv.total') }}</span><span x-text="money(toC(lastReceipt.totals.total))"></span></div>
                    </div>
                    <div class="mad-pdv-receipt-payments">
                        <template x-for="(pm, i) in lastReceipt.payments" :key="i">
                            <div>
                                <span x-text="pm.label"></span>
                                <span x-text="money(toC(pm.amount))"></span>
                            </div>
                        </template>
                        <div x-show="lastReceipt.change > 0"><span>{{ __('pdv.change') }}</span><span x-text="money(toC(lastReceipt.change))"></span></div>
                    </div>
                    <pre class="mad-pdv-receipt-footer" x-show="cfg.receipt.footer" x-text="cfg.receipt.footer"></pre>
                </div>
            </template>
        </div>
    </div>
</div>
