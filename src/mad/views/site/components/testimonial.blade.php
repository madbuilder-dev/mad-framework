{{--
    <mad-site-testimonial> — UM depoimento, dentro de
    <mad-site-testimonials>.

    Sem `name`, o bloco se declara exemplo em vez de assinar uma frase com um
    nome que não existe. Publique só o que o cliente autorizou.
--}}
@props([
    'name'    => '',
    'role'    => '',
    'company' => '',
    'avatar'  => '',
    'text'    => '',
    'class'   => '',
])

@php
    $__avatar = trim((string) $avatar);
    if ($__avatar !== ''
        && ! str_starts_with($__avatar, 'data:')
        && ! str_starts_with($__avatar, '//')
        && ! preg_match('#^https?://#i', $__avatar)) {
        $__avatar = asset(ltrim($__avatar, '/'));
    }

    $__who = trim(implode(' · ', array_filter([
        trim((string) $name),
        trim(implode(', ', array_filter([trim((string) $role), trim((string) $company)]))),
    ])));

    $__text = trim((string) $text);
    $__slot = trim((string) ($slot ?? ''));
    $__isPlaceholder = $__text === '' && $__slot === '';
@endphp

<figure class="site-testimonial {{ $class }}">
    <blockquote>
        @if($__isPlaceholder)
            {{ mad_t('mad.site_testimonial_placeholder') }}
        @else
            {{ $__text }}{{ $slot ?? '' }}
        @endif
    </blockquote>

    <figcaption>
        @if($__avatar !== '')
            <img src="{{ $__avatar }}" alt="" loading="lazy">
        @endif
        @if($__who !== '')
            <span>{{ $__who }}</span>
        @else
            <span>{{ mad_t('mad.site_example_content') }}</span>
        @endif
    </figcaption>
</figure>
