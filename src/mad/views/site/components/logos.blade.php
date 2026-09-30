{{--
    <mad-site-logos> — a faixa de "quem usa".

    Sem slot, saem cinco espaços vazios escritos "Sua marca aqui": o dono vê
    onde colocar os logos reais e o visitante não vê logo de empresa que não é
    cliente. Para os logos de verdade, passe <img> no slot.
--}}
@props([
    'id'    => '',
    'title' => '',
    'tone'  => 'plain',
    'class' => '',
])

@php $__slot = trim((string) ($slot ?? '')); @endphp

<section class="site-section {{ trim(($tone === 'tint' ? 'site-section--tint ' : '') . $class) }}"
         @if($id) id="{{ $id }}" @endif>
    <div class="site-container">
        @if($title !== '')
            <h2>{{ $title }}</h2>
        @endif

        <div class="site-logos">
            @if($__slot !== '')
                {{ $slot ?? '' }}
            @else
                @for($__i = 1; $__i <= 5; $__i++)
                    <span class="site-logo-placeholder">{{ mad_t('mad.site_logo_placeholder') }}</span>
                @endfor
            @endif
        </div>
    </div>
</section>
