{{--
    <mad-site-signup-form> — o cadastro self-service: empresa, administrador
    e senha num POST só.

    Mesma mecânica do contato (HTML puro + `@csrf` + armadilha + carimbo de
    tempo), mas com duas diferenças que importam:

     • **O plano e o ciclo vêm do link.** O botão da seção de preços manda
       `?plan=...&cycle=...`; os dois viajam em campo escondido para que o
       cadastro já crie a assinatura certa. Sem plano no link, o servidor
       decide (e é ele quem valida: campo escondido é sugestão, não ordem).
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
        if ($__bag === null || ! is_object($__bag) || ! method_exists($__bag, 'has')) {
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
        @else
            <p>{{ $lead !== '' ? $lead : mad_t('mad.site_signup_lead') }}</p>

            <form class="site-form" method="post" action="{{ site_url('/public/site/signup') }}">
                @csrf
                <input type="hidden" name="_t" value="{{ time() }}">
                <input type="hidden" name="plan_id" value="{{ $__planId }}">
                <input type="hidden" name="cycle" value="{{ $__cycle }}">

                <div style="{{ $__trapStyle }}" aria-hidden="true">
                    <label for="site-signup-website">Website</label>
                    <input type="text" id="site-signup-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="site-form__field">
                    <label for="site-signup-company">{{ mad_t('mad.site_field_company') }}</label>
                    <input type="text" id="site-signup-company" name="company" value="{{ $__old('company') }}"
                           required maxlength="120" autocomplete="organization">
                    @if($__error('company') !== '')
                        <p class="site-form__error">{{ $__error('company') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-name">{{ mad_t('mad.site_field_name') }}</label>
                    <input type="text" id="site-signup-name" name="name" value="{{ $__old('name') }}"
                           required maxlength="120" autocomplete="name">
                    @if($__error('name') !== '')
                        <p class="site-form__error">{{ $__error('name') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-email">{{ mad_t('mad.site_field_email') }}</label>
                    <input type="email" id="site-signup-email" name="email" value="{{ $__old('email') }}"
                           required maxlength="180" autocomplete="email">
                    @if($__error('email') !== '')
                        <p class="site-form__error">{{ $__error('email') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-password">{{ mad_t('mad.site_field_password') }}</label>
                    <input type="password" id="site-signup-password" name="password"
                           required autocomplete="new-password">
                    @if($__error('password') !== '')
                        <p class="site-form__error">{{ $__error('password') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-password-confirm">{{ mad_t('mad.site_field_password_confirm') }}</label>
                    <input type="password" id="site-signup-password-confirm" name="password_confirmation"
                           required autocomplete="new-password">
                    @if($__error('password_confirmation') !== '')
                        <p class="site-form__error">{{ $__error('password_confirmation') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-signup-terms">
                        <input type="checkbox" id="site-signup-terms" name="terms" value="1" required>
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
                        @if($__error('g-recaptcha-response') !== '')
                            <p class="site-form__error">{{ $__error('g-recaptcha-response') }}</p>
                        @endif
                    </div>
                    <script src="https://www.google.com/recaptcha/api.js" async defer></script>
                @endif

                @if($__error('plan_id') !== '')
                    <p class="site-form__error">{{ $__error('plan_id') }}</p>
                @endif

                <button type="submit" class="site-btn site-btn--primary">
                    {{ $submitLabel !== '' ? $submitLabel : mad_t('mad.site_signup_submit') }}
                </button>
            </form>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
