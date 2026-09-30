{{--
    <mad-site-pricing> — os planos DE VERDADE, lidos da vitrine do dono
    (PlanCatalog) pelo PlanShowcase. Nenhum preço é escrito no Blade: o que o
    visitante lê é o mesmo número que a tela de assinatura vai cobrar.

    Três decisões que parecem detalhe e não são:

     • **Sem cobrança configurada, a seção não some nem quebra** — mostra um
       texto dizendo que os planos ainda não foram publicados. Home de SaaS
       que devolve 500 por falta de tabela é pior do que home sem preço.
     • **O alternador Mensal/Anual só aparece quando existe preço anual**, e o
       desconto anunciado é calculado dos dois preços do plano.
     • **O botão de cada plano vem da política de cadastro** (Onda D): com
       teste grátis liberado ele leva ao cadastro; sem isso, leva ao contato.
       Nunca um "Assinar" que termina numa tela de login.

    A troca de ciclo acontece no navegador (`site.js` alterna `hidden` entre
    `[data-price-monthly]` e `[data-price-yearly]` e reescreve o `cycle=` de
    `[data-plan-cta]`) — sem ida ao servidor e sem Alpine.
--}}
@props([
    'id'          => '',
    'title'       => '',
    'lead'        => '',
    'cycle'       => 'monthly',
    'ctaMode'     => 'auto',
    'contactHref' => '',
    'tone'        => 'plain',
    'class'       => '',
])

@php
    $__cycle = $cycle === 'yearly' ? 'yearly' : 'monthly';
    $__title = $title !== '' ? $title : mad_t('mad.site_plans_title');

    $__contactHref = trim((string) $contactHref);
    if ($__contactHref === '') { $__contactHref = site_url('/contato'); }

    // Vitrine. Fail-safe em duas camadas: a classe pode não existir (app sem
    // cobrança) e a consulta pode falhar (schema antigo).
    $__cards  = [];
    $__plans  = [];
    $__yearly = false;
    if (class_exists(\App\Service\Billing\PlanShowcase::class)) {
        try {
            $__cards  = \App\Service\Billing\PlanShowcase::cards($__cycle);
            $__plans  = array_column($__cards, 'plan');
            $__yearly = \App\Service\Billing\PlanShowcase::yearlyAvailable($__plans);
        } catch (\Throwable $e) {
            $__cards = [];
        }
    }

    $__bestSaving = null;
    if ($__yearly) {
        try {
            $__bestSaving = \App\Service\Billing\PlanShowcase::bestYearlySaving($__plans);
        } catch (\Throwable $e) {
            $__bestSaving = null;
        }
    }

    $__hasPolicy = class_exists(\App\Service\Site\SiteSignupPolicy::class);

    /**
     * O botão de UM plano. `auto` pergunta à política de cadastro; `signup` e
     * `contact` forçam o caminho (o dono pode querer só leads mesmo tendo
     * cadastro ligado). Sem a política instalada, o padrão é sempre contato —
     * mandar o visitante a uma tela que talvez não exista seria pior.
     */
    $__ctaFor = function (array $card) use ($ctaMode, $__cycle, $__contactHref, $__hasPolicy): array {
        $fallback = [
            'mode'  => 'contact',
            'href'  => $__contactHref,
            'label' => mad_t('mad.site_talk_to_sales'),
        ];

        if ($ctaMode === 'contact') {
            return $fallback;
        }

        if ($ctaMode === 'signup') {
            return [
                'mode'  => 'signup',
                'href'  => site_url('/cadastro') . '?plan=' . (int) $card['id'] . '&cycle=' . $__cycle,
                'label' => mad_t('mad.site_start_free'),
            ];
        }

        if (! $__hasPolicy || ! ($card['plan'] ?? null) instanceof \App\Models\Iam\Plan) {
            return $fallback;
        }

        try {
            $cta = \App\Service\Site\SiteSignupPolicy::ctaFor($card['plan'], $__cycle);
        } catch (\Throwable $e) {
            return $fallback;
        }

        if (! is_array($cta) || ($cta['mode'] ?? '') === '') {
            return $fallback;
        }

        return [
            'mode'  => (string) $cta['mode'],
            'href'  => (string) ($cta['href'] ?? $__contactHref),
            'label' => (string) ($cta['label'] ?? mad_t('mad.site_talk_to_sales')),
        ];
    };
@endphp

{{-- A `<section>` é só a faixa (título, texto de apoio, alternador). A GRADE
     (`site-pricing`) fica num bloco interno, como em `<mad-site-features>`:
     na seção, que ocupa a largura da tela, os cartões saíam espremidos e
     sobrepostos. --}}
<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $__title }}</h2>

        @if($lead !== '')
            <p>{{ $lead }}</p>
        @endif

        @if($__cards === [])
            <p>{{ mad_t('mad.site_plans_empty') }}</p>
            <p><a class="site-btn site-btn--ghost" href="{{ $__contactHref }}">{{ mad_t('mad.site_talk_to_sales') }}</a></p>
        @else
            @if($__yearly)
                <div class="site-pricing__toggle" role="group" aria-label="{{ mad_t('mad.site_cycle_switch') }}">
                    <button type="button" data-site-cycle="monthly"
                            aria-pressed="{{ $__cycle === 'monthly' ? 'true' : 'false' }}">{{ mad_t('mad.site_monthly') }}</button>
                    <button type="button" data-site-cycle="yearly"
                            aria-pressed="{{ $__cycle === 'yearly' ? 'true' : 'false' }}">{{ mad_t('mad.site_yearly') }}</button>
                    @if($__bestSaving !== null)
                        <span>{{ mad_t('mad.site_yearly_saving', ['percent' => (string) $__bestSaving]) }}</span>
                    @endif
                </div>
            @endif

            <div class="site-pricing site-grid-3">
                @foreach($__cards as $__card)
                    @php $__cta = $__ctaFor($__card); @endphp
                    <article class="site-plan {{ $__card['featured'] ? 'site-plan--featured' : '' }}"
                             data-plan-id="{{ $__card['id'] }}">
                        <h3>{{ $__card['name'] }}</h3>

                        @if($__card['featured'])
                            <p>{{ mad_t('mad.site_plan_featured') }}</p>
                        @endif

                        <p class="site-plan__price">
                            <span data-price-monthly @if($__cycle !== 'monthly') hidden @endif>
                                @if($__card['price_label_monthly'] !== '')
                                    {{ $__card['price_label_monthly'] }}<small>{{ mad_t('mad.site_per_month') }}</small>
                                @else
                                    {{ mad_t('mad.site_price_on_request') }}
                                @endif
                            </span>
                            @if($__yearly)
                                <span data-price-yearly @if($__cycle !== 'yearly') hidden @endif>
                                    @if($__card['price_label_yearly'] !== '')
                                        {{ $__card['price_label_yearly'] }}<small>{{ mad_t('mad.site_per_year') }}</small>
                                    @else
                                        {{ mad_t('mad.site_price_on_request') }}
                                    @endif
                                </span>
                            @endif
                        </p>

                        @if($__card['description'] !== '')
                            <p>{{ $__card['description'] }}</p>
                        @endif

                        <ul class="site-plan__list">
                            <li>
                                @include('site.partials.icon', ['name' => 'check', 'size' => 16])
                                <span>{{ $__card['max_users'] > 0
                                    ? mad_t('mad.site_plan_users', ['max' => (string) $__card['max_users']])
                                    : mad_t('mad.site_plan_users_unlimited') }}</span>
                            </li>
                            @if($__card['trial_days'] > 0)
                                <li>
                                    @include('site.partials.icon', ['name' => 'check', 'size' => 16])
                                    <span>{{ mad_t('mad.site_plan_trial', ['days' => (string) $__card['trial_days']]) }}</span>
                                </li>
                            @endif
                            @foreach($__card['modules'] as $__module)
                                <li>
                                    @include('site.partials.icon', ['name' => 'check', 'size' => 16])
                                    <span>{{ $__module }}</span>
                                </li>
                            @endforeach
                        </ul>

                        @if($__cta['mode'] !== 'none')
                            <a class="site-btn {{ $__card['featured'] ? 'site-btn--primary' : 'site-btn--ghost' }}"
                               data-plan-cta href="{{ $__cta['href'] }}">{{ $__cta['label'] }}</a>
                        @endif
                    </article>
                @endforeach
            </div>

            {{ $slot ?? '' }}
        @endif
    </div>
</section>
