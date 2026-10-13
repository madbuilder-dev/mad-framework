{{--
    <mad-site-signup-form> — o cadastro self-service: empresa, administrador
    e senha num POST só.

    Mesma mecânica do contato (HTML puro + `@csrf` + armadilha + carimbo de
    tempo), mas com duas diferenças que importam:

     • **O plano e o ciclo vêm do link — ou do próprio formulário.** O botão
       da seção de preços manda `?plan=...&cycle=...`; os dois viajam em campo
       escondido para que o cadastro já crie a assinatura certa. Sem plano no
       link ("Criar conta" do menu e do rodapé), o formulário mostra os planos
       contratáveis pelo site com o primeiro marcado; com um plano só, ele é
       assumido; sem nenhum, o bloco avisa e aponta o contato. Quem valida é
       sempre o servidor: campo de formulário é sugestão, não ordem.
     • **Quando o dono fechou o cadastro pelo site**, o bloco não mostra um
       formulário que vai ser recusado — mostra o caminho do contato. Quem
       quiser decidir isso na mão usa `open="1"` / `open="0"`.

    O aceite dos termos é um checkbox obrigatório de verdade (`required`), com
    links para as páginas quando elas existem.
--}}
@props([
    'id'          => '',
    'title'       => '',
    'lead'        => '',
    'planId'      => '',
    'cycle'       => '',
    'open'        => null,
    'submitLabel' => '',
    'termsHref'   => '',
    'privacyHref' => '',
    'contactHref' => '',
    'tone'        => 'plain',
    'class'       => '',
])

@php
    $__req = null;
    try { $__req = request(); } catch (\Throwable $e) { $__req = null; }

    // Plano/ciclo: prop > link (?plan=&cycle=) > vazio.
    $__planId = trim((string) $planId);
    $__cycle  = trim((string) $cycle);
    if ($__req !== null) {
        try {
            if ($__planId === '') { $__planId = (string) ($__req->query('plan') ?? ''); }
            if ($__cycle === '')  { $__cycle  = (string) ($__req->query('cycle') ?? ''); }
        } catch (\Throwable $e) {
            // sem request — os dois ficam vazios e o servidor decide
        }
    }
    $__planId = ctype_digit($__planId) ? $__planId : '';
    $__cycle  = $__cycle === 'yearly' ? 'yearly' : 'monthly';

    // Cadastro aberto? Quem responde é a política (licenciamento ligado,
    // preferência do dono, banco por cliente). `open` na tag vence — é a saída
    // para quem monta a página numa instalação que ainda vai ligar o
    // self-service. Sem a política instalada, o formulário aparece: quem pôs o
    // componente na página foi quem escolheu oferecê-lo.
    $__open = true;
    if ($open === null || $open === '') {
        if (class_exists(\App\Service\Site\SiteSignupPolicy::class)) {
            try {
                $__open = (bool) \App\Service\Site\SiteSignupPolicy::enabled();
            } catch (\Throwable $e) {
                $__open = true;
            }
        }
    } else {
        $__open = filter_var($open, FILTER_VALIDATE_BOOLEAN);
    }

    $__contactHref = trim((string) $contactHref);
    if ($__contactHref === '') { $__contactHref = site_url('/contato'); }

    $__routeHas = function (string $name): bool {
        try {
            return \Illuminate\Support\Facades\Route::has($name);
        } catch (\Throwable $e) {
            return false;
        }
    };
    $__terms = trim((string) $termsHref);
    if ($__terms === '' && $__routeHas('site.termos')) { $__terms = site_url('/termos'); }
    $__privacy = trim((string) $privacyHref);
    if ($__privacy === '' && $__routeHas('site.privacidade')) { $__privacy = site_url('/privacidade'); }

    $__bag = $errors ?? null;
    if ($__bag === null) {
        try { $__bag = session('errors'); } catch (\Throwable $e) { $__bag = null; }
    }
    $__error = function (string $field) use ($__bag): string {
        // `is_callable`, não `method_exists`: a ViewErrorBag do redirect só
        // repassa has()/first() para a bag padrão por __call (fw#296).
        if ($__bag === null || ! is_object($__bag) || ! is_callable([$__bag, 'has'])) {
            return '';
        }
        try {
            return $__bag->has($field) ? (string) $__bag->first($field) : '';
        } catch (\Throwable $e) {
            return '';
        }
    };
    $__old = function (string $field): string {
        try { return (string) old($field, ''); } catch (\Throwable $e) { return ''; }
    };

    // ── Plano: do link, do seletor ou o único que existe (fw#320) ──────────
    // `$__options === null` é o modo antigo (só campo escondido): app sem a
    // política de cadastro, ou cadastro fechado com `open="1"` forçado na tag
    // — ali não há lista de planos contratáveis para oferecer.
    $__policy  = \App\Service\Site\SiteSignupPolicy::class;
    $__options = null;
    if ($__open && class_exists($__policy) && method_exists($__policy, 'signupPlans')) {
        try {
            if ($__policy::enabled()) {
                $__options = array_values((array) $__policy::signupPlans());
            }
        } catch (\Throwable $e) {
            $__options = null;
        }
    }

    $__planError  = $__error('plan_id');
    $__cycleError = $__error('cycle');

    $__planMode   = 'legacy';   // legacy | hidden | single | select | empty
    $__chosen     = null;       // o plano que vai marcado/escondido
    $__cycleRadio = [];         // períodos oferecidos como rádio (vazio = campo escondido)

    if (is_array($__options)) {
        $__byId = [];
        foreach ($__options as $__option) {
            $__byId[(string) $__option['id']] = $__option;
        }

        $__fromLink = $__planId !== '' && isset($__byId[$__planId]);
        $__oldPlan  = $__old('plan_id');
        $__oldCycle = $__old('cycle');
        // Período pedido: o que a pessoa já tinha escolhido vence o do link.
        $__wanted   = in_array($__oldCycle, ['monthly', 'yearly'], true) ? $__oldCycle : $__cycle;

        if ($__options === []) {
            $__planMode = 'empty';
        } elseif ($__fromLink && $__planError === '' && $__cycleError === '') {
            // Chegou pelo botão de um plano: como sempre foi, sem seletor.
            $__planMode = 'hidden';
            $__chosen   = $__byId[$__planId];
        } elseif (count($__options) === 1) {
            $__planMode = 'single';
            $__chosen   = $__options[0];
            if (count($__chosen['cycles']) > 1) { $__cycleRadio = $__chosen['cycles']; }
        } else {
            $__planMode = 'select';
            $__chosen   = $__byId[$__oldPlan] ?? ($__fromLink ? $__byId[$__planId] : $__options[0]);
            foreach (['monthly', 'yearly'] as $__c) {
                foreach ($__options as $__option) {
                    if (in_array($__c, $__option['cycles'], true)) { $__cycleRadio[] = $__c; break; }
                }
            }
            if (count($__cycleRadio) < 2) { $__cycleRadio = []; }
        }

        if ($__chosen !== null) {
            $__planId = (string) $__chosen['id'];
            // Período que o plano não oferece cai no primeiro que ele oferece
            // (mensal quando existe): nada de contrato anual sem preço anual.
            $__cycle  = in_array($__wanted, $__chosen['cycles'], true) ? $__wanted : (string) ($__chosen['cycles'][0] ?? 'monthly');
        }
    }

    // "R$ 199,00/mês · R$ 1.990,00/ano" — os preços de um plano, nos períodos
    // que ele oferece. Os dois ficam à vista: a troca de período não depende
    // de JavaScript para a pessoa saber quanto vai pagar.
    $__priceParts = function (array $option): array {
        $parts = [];
        foreach ((array) ($option['cycles'] ?? []) as $cycle) {
            $label   = (string) ($option['prices'][$cycle] ?? '');
            $parts[] = [
                'label'  => $label !== '' ? $label : mad_t('mad.site_price_on_request'),
                'suffix' => $label !== '' ? mad_t($cycle === 'yearly' ? 'mad.site_per_year' : 'mad.site_per_month') : '',
            ];
        }

        return $parts;
    };

    $__captchaKey = '';
    if (class_exists(\App\Service\Sys\PreferenceService::class)) {
        try {
            if (\App\Service\Sys\PreferenceService::isGoogleRecaptchaEnabled()) {
                $__prefs      = \App\Service\Sys\PreferenceService::getPreferences();
                $__captchaKey = (string) ($__prefs['google_recaptcha_site_key'] ?? '');
            }
        } catch (\Throwable $e) {
            $__captchaKey = '';
        }
    }

    $__trapStyle = 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;';
@endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $title !== '' ? $title : mad_t('mad.site_signup_title') }}</h2>

        @if(! $__open)
            <p>{{ mad_t('mad.site_signup_closed') }}</p>
            <p><a class="site-btn site-btn--primary" href="{{ $__contactHref }}">{{ mad_t('mad.site_talk_to_sales') }}</a></p>
        @elseif($__planMode === 'empty')
            {{-- Nenhum plano contratável pelo site: um formulário aqui só
                 terminaria em recusa. --}}
            <p>{{ mad_t('mad.site_signup_no_plans') }}</p>
            <p><a class="site-btn site-btn--primary" href="{{ $__contactHref }}">{{ mad_t('mad.site_talk_to_sales') }}</a></p>
        @else
            <p>{{ $lead !== '' ? $lead : mad_t('mad.site_signup_lead') }}</p>

            <form class="site-form" method="post" action="{{ site_url('/public/site/signup') }}">
                @csrf
                <input type="hidden" name="_t" value="{{ time() }}">
                @if($__planMode !== 'select')
                    <input type="hidden" name="plan_id" value="{{ $__planId }}">
                @endif
                @if($__cycleRadio === [])
                    <input type="hidden" name="cycle" value="{{ $__cycle }}">
                @endif

                {{-- Aviso que não é de um campo: limite de tentativas, envio
                     rápido demais, falha ao criar a conta. --}}
                @if($__error('form') !== '')
                    <p class="site-form__error" role="alert">{{ $__error('form') }}</p>
                @endif

                <div style="{{ $__trapStyle }}" aria-hidden="true">
                    <label for="site-signup-website">Website</label>
                    <input type="text" id="site-signup-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                @if($__planMode === 'select')
                    {{-- Sem plano no link: a pessoa escolhe aqui. A recusa do
                         plano aparece DENTRO deste bloco, junto de onde
                         escolher outro. --}}
                    <fieldset class="site-form__plans" data-site-signup-plans
                              @if($__planError !== '') aria-invalid="true" @endif>
                        <legend>{{ mad_t('mad.site_signup_choose_plan') }}</legend>
                        @foreach($__options as $__option)
                            <label class="site-form__plan" for="site-signup-plan-{{ $__option['id'] }}">
                                <input type="radio" name="plan_id" value="{{ $__option['id'] }}"
                                       id="site-signup-plan-{{ $__option['id'] }}"
                                       data-cycles="{{ implode(' ', $__option['cycles']) }}" required
                                       @if((string) $__option['id'] === $__planId) checked @endif>
                                <span class="site-form__plan-body">
                                    <span class="site-form__plan-name">{{ $__option['name'] }}</span>
                                    <span class="site-form__plan-price">
                                        @foreach($__priceParts($__option) as $__i => $__part)
                                            @if($__i > 0)<span aria-hidden="true"> · </span>@endif
                                            <span>{{ $__part['label'] }}@if($__part['suffix'] !== '')<small>{{ $__part['suffix'] }}</small>@endif</span>
                                        @endforeach
                                    </span>
                                    @if($__option['trial_days'] > 0)
                                        <span class="site-form__plan-note">{{ mad_t('mad.site_plan_trial', ['days' => (string) $__option['trial_days']]) }}</span>
                                    @endif
                                </span>
                            </label>
                        @endforeach
                        @if($__planError !== '')
                            <p class="site-form__error" role="alert">{{ $__planError }}</p>
                        @endif
                    </fieldset>
                @elseif($__planMode === 'single')
                    {{-- Um plano só: ele é assumido. O resumo diz em que plano
                         a conta nasce — não é um seletor. --}}
                    <div class="site-form__plans" data-site-signup-plan>
                        <span class="site-form__plans-label">{{ mad_t('mad.site_signup_plan') }}</span>
                        <div class="site-form__plan site-form__plan--only">
                            <span class="site-form__plan-body">
                                <span class="site-form__plan-name">{{ $__chosen['name'] }}</span>
                                <span class="site-form__plan-price">
                                    @foreach($__priceParts($__chosen) as $__i => $__part)
                                        @if($__i > 0)<span aria-hidden="true"> · </span>@endif
                                        <span>{{ $__part['label'] }}@if($__part['suffix'] !== '')<small>{{ $__part['suffix'] }}</small>@endif</span>
                                    @endforeach
                                </span>
                                @if($__chosen['trial_days'] > 0)
                                    <span class="site-form__plan-note">{{ mad_t('mad.site_plan_trial', ['days' => (string) $__chosen['trial_days']]) }}</span>
                                @endif
                            </span>
                        </div>
                        @if($__planError !== '')
                            <p class="site-form__error" role="alert">{{ $__planError }}</p>
                        @endif
                    </div>
                @endif

                @if($__cycleRadio !== [])
                    <fieldset class="site-form__plans" data-site-signup-cycles
                              @if($__cycleError !== '') aria-invalid="true" @endif>
                        <legend>{{ mad_t('mad.site_cycle_switch') }}</legend>
                        <div class="site-form__cycles">
                            @foreach($__cycleRadio as $__c)
                                <label class="site-form__cycle" for="site-signup-cycle-{{ $__c }}">
                                    <input type="radio" name="cycle" value="{{ $__c }}" id="site-signup-cycle-{{ $__c }}" required
                                           @if($__c === $__cycle) checked @endif>
                                    <span>{{ mad_t($__c === 'yearly' ? 'mad.site_yearly' : 'mad.site_monthly') }}</span>
                                </label>
                            @endforeach
                        </div>
                        @if($__cycleError !== '')
                            <p class="site-form__error" role="alert">{{ $__cycleError }}</p>
                        @endif
                    </fieldset>
                @elseif($__cycleError !== '' && $__planMode !== 'legacy')
                    <p class="site-form__error" role="alert">{{ $__cycleError }}</p>
                @endif

                <div class="site-form__field">
                    <label for="site-signup-company">{{ mad_t('mad.site_field_company') }}</label>
                    <input type="text" id="site-signup-company" name="company" value="{{ $__old('company') }}"
                           @if($__error('company') !== '') aria-invalid="true" @endif
                           required maxlength="120" autocomplete="organization">
                    @if($__error('company') !== '')
                        <p class="site-form__error">{{ $__error('company') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-name">{{ mad_t('mad.site_field_name') }}</label>
                    <input type="text" id="site-signup-name" name="name" value="{{ $__old('name') }}"
                           @if($__error('name') !== '') aria-invalid="true" @endif
                           required maxlength="120" autocomplete="name">
                    @if($__error('name') !== '')
                        <p class="site-form__error">{{ $__error('name') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-email">{{ mad_t('mad.site_field_email') }}</label>
                    <input type="email" id="site-signup-email" name="email" value="{{ $__old('email') }}"
                           @if($__error('email') !== '') aria-invalid="true" @endif
                           required maxlength="180" autocomplete="email">
                    @if($__error('email') !== '')
                        <p class="site-form__error">{{ $__error('email') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-password">{{ mad_t('mad.site_field_password') }}</label>
                    <input type="password" id="site-signup-password" name="password"
                           @if($__error('password') !== '') aria-invalid="true" @endif
                           required autocomplete="new-password">
                    @if($__error('password') !== '')
                        <p class="site-form__error">{{ $__error('password') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-password-confirm">{{ mad_t('mad.site_field_password_confirm') }}</label>
                    <input type="password" id="site-signup-password-confirm" name="password_confirmation"
                           @if($__error('password_confirmation') !== '') aria-invalid="true" @endif
                           required autocomplete="new-password">
                    @if($__error('password_confirmation') !== '')
                        <p class="site-form__error">{{ $__error('password_confirmation') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-terms">
                        <input type="checkbox" id="site-signup-terms" name="terms" value="1" required
                               @if($__old('terms') !== '') checked @endif>
                        <span>{{ mad_t('mad.site_accept_terms') }}</span>
                    </label>
                    @if($__terms !== '' || $__privacy !== '')
                        <span>
                            @if($__terms !== '')
                                <a href="{{ $__terms }}" target="_blank" rel="noopener">{{ mad_t('mad.site_terms') }}</a>
                            @endif
                            @if($__privacy !== '')
                                <a href="{{ $__privacy }}" target="_blank" rel="noopener">{{ mad_t('mad.site_privacy') }}</a>
                            @endif
                        </span>
                    @endif
                    @if($__error('terms') !== '')
                        <p class="site-form__error">{{ $__error('terms') }}</p>
                    @endif
                </div>

                @if($__captchaKey !== '')
                    <div class="site-form__field">
                        <div class="g-recaptcha" data-sitekey="{{ $__captchaKey }}"></div>
                        @php $__captchaError = $__error('captcha') ?: $__error('g-recaptcha-response'); @endphp
                        @if($__captchaError !== '')
                            <p class="site-form__error">{{ $__captchaError }}</p>
                        @endif
                    </div>
                    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                @endif

                {{-- Modo antigo (sem lista de planos): a recusa do plano não tem
                     bloco próprio e fica aqui, antes do botão. --}}
                @if($__planMode === 'legacy' && $__planError !== '')
                    <p class="site-form__error">{{ $__planError }}</p>
                @endif
                @if($__planMode === 'legacy' && $__cycleError !== '')
                    <p class="site-form__error">{{ $__cycleError }}</p>
                @endif

                <button type="submit" class="site-btn site-btn--primary">
                    {{ $submitLabel !== '' ? $submitLabel : mad_t('mad.site_signup_submit') }}
                </button>
            </form>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
