{{--
    <mad-site-contact-form> — o formulário de contato do site.

    É um POST de HTML puro (`@csrf` + `method="post"`), não um componente
    reativo: funciona sem JavaScript, o navegador valida os campos
    obrigatórios sozinho e o throttle é declarado na rota. Numa página que
    recebe tráfego anônimo, isso é menos superfície e menos coisa para dar
    errado.

    Três campos que o visitante não vê e que decidem se o lead é real:
      • `website` — armadilha (honeypot). Fora da ordem de tabulação e fora
        do leitor de tela: quem preenche é robô, e o servidor descarta em
        silêncio.
      • `_t` — carimbo de quando a página foi renderizada. Envio em menos de
        alguns segundos é automação.
      • `origin` + `utm_*` — de qual página e de qual campanha veio o lead,
        preservados do link que o trouxe.

    Depois do envio a rota volta com `site_lead_sent` e o bloco troca para a
    confirmação — sem reenviar o formulário e sem mostrar de novo os campos
    já preenchidos.
--}}
@props([
    'id'           => 'contato',
    'title'        => '',
    'lead'         => '',
    'submitLabel'  => '',
    'successTitle' => '',
    'successText'  => '',
    'tone'         => 'plain',
    'class'        => '',
])

@php
    $__sent = false;
    try {
        $__sent = session('site_lead_sent') === true;
    } catch (\Throwable $e) {
        $__sent = false;
    }

    // Erros de validação: o ErrorBag do framework quando a página é servida
    // pelo ViewResponse, a bag da sessão quando vem de um redirect-back.
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

    // De onde veio o lead e por qual campanha — lido do request atual.
    $__req    = null;
    try { $__req = request(); } catch (\Throwable $e) { $__req = null; }
    $__origin = '';
    $__utm    = ['utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '', 'utm_term' => '', 'utm_content' => ''];
    if ($__req !== null) {
        try {
            $__origin = trim((string) $__req->path(), '/');
            foreach (array_keys($__utm) as $__k) {
                $__utm[$__k] = (string) ($__req->query($__k) ?? '');
            }
        } catch (\Throwable $e) {
            // request indisponível (render fora de uma requisição) — campos vazios
        }
    }

    // reCAPTCHA v2 só quando o dono ligou E configurou as duas chaves.
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

    // Fora da tabulação E fora do leitor de tela, mas presente no DOM.
    $__trapStyle = 'position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;';
@endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        <h2>{{ $title !== '' ? $title : mad_t('mad.site_contact_title') }}</h2>

        @if($__sent)
            <p class="site-form__success">
                <strong>{{ $successTitle !== '' ? $successTitle : mad_t('mad.site_lead_sent_title') }}</strong>
                {{ $successText !== '' ? $successText : mad_t('mad.site_lead_sent_text') }}
            </p>
        @else
            <p>{{ $lead !== '' ? $lead : mad_t('mad.site_contact_lead') }}</p>

            <form class="site-form" method="post" action="{{ site_url('/public/site/lead') }}">
                @csrf
                <input type="hidden" name="_t" value="{{ time() }}">
                <input type="hidden" name="origin" value="{{ $__origin }}">
                @foreach($__utm as $__utmKey => $__utmValue)
                    <input type="hidden" name="{{ $__utmKey }}" value="{{ $__utmValue }}">
                @endforeach

                <div style="{{ $__trapStyle }}" aria-hidden="true">
                    <label for="site-lead-website">Website</label>
                    <input type="text" id="site-lead-website" name="website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="site-form__field">
                    <label for="site-lead-name">{{ mad_t('mad.site_field_name') }}</label>
                    <input type="text" id="site-lead-name" name="name" value="{{ $__old('name') }}"
                           required maxlength="120" autocomplete="name">
                    @if($__error('name') !== '')
                        <p class="site-form__error">{{ $__error('name') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-lead-email">{{ mad_t('mad.site_field_email') }}</label>
                    <input type="email" id="site-lead-email" name="email" value="{{ $__old('email') }}"
                           required maxlength="180" autocomplete="email">
                    @if($__error('email') !== '')
                        <p class="site-form__error">{{ $__error('email') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-lead-phone">{{ mad_t('mad.site_field_phone') }} <small>({{ mad_t('mad.site_optional') }})</small></label>
                    <input type="tel" id="site-lead-phone" name="phone" value="{{ $__old('phone') }}"
                           maxlength="40" autocomplete="tel">
                    @if($__error('phone') !== '')
                        <p class="site-form__error">{{ $__error('phone') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-lead-company">{{ mad_t('mad.site_field_company') }} <small>({{ mad_t('mad.site_optional') }})</small></label>
                    <input type="text" id="site-lead-company" name="company" value="{{ $__old('company') }}"
                           maxlength="120" autocomplete="organization">
                    @if($__error('company') !== '')
                        <p class="site-form__error">{{ $__error('company') }}</p>
                    @endif
                </div>

                <div class="site-form__field">
                    <label for="site-lead-message">{{ mad_t('mad.site_field_message') }}</label>
                    <textarea id="site-lead-message" name="message" rows="5" required maxlength="4000">{{ $__old('message') }}</textarea>
                    @if($__error('message') !== '')
                        <p class="site-form__error">{{ $__error('message') }}</p>
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

                <button type="submit" class="site-btn site-btn--primary">
                    {{ $submitLabel !== '' ? $submitLabel : mad_t('mad.site_send') }}
                </button>
            </form>
        @endif

        {{ $slot ?? '' }}
    </div>
</section>
