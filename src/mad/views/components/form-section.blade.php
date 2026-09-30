@php
    $title    = $title    ?? '';
    $subtitle = $subtitle ?? null;
    $icon     = $icon     ?? null;
    $class    = $class    ?? '';
    $style    = $style    ?? '';
    $attrs    = $attrs    ?? '';
    // Seção sem título, subtítulo nem ícone é só agrupamento (condição,
    // tabela vinculada): sem header, senão sobra uma faixa vazia com o
    // divisor no topo do formulário.
    $hasHeader = trim((string) $title) !== '' || filled($subtitle) || filled($icon);
@endphp

<section class="mad-form-section {{ $class }}" @if($style) style="{{ $style }}" @endif {!! $attrs !!}>
    @if($hasHeader)
    <div class="mad-form-section-header">
        <div class="mad-form-section-meta">
            @if($icon)
            <div class="mad-form-section-icon">
                <i data-lucide="{{ $icon }}"></i>
            </div>
            @endif
            <div class="mad-form-section-text">
                <div class="mad-form-section-title">{{ $title }}</div>
                @if($subtitle)
                <div class="mad-form-section-subtitle">{{ $subtitle }}</div>
                @endif
            </div>
        </div>
        <div class="mad-form-section-divider"></div>
    </div>
    @endif

    {!! $slot !!}
</section>
