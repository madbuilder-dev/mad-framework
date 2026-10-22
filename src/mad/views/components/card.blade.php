@php
    // header/footer escapam por default (texto vindo de banco = XSS armazenado
    // com {!! !!}). HTML intencional: passe um Htmlable/HtmlString.
    // `title` é apelido de `header` (o Studio grava `header`; Blade escrito à
    // mão e a doc antiga usavam `title`, que sumia sem aviso).
    $header = $header ?? ($title ?? null);
    $footer = $footer ?? null;
    // Ícone Lucide antes do título do cabeçalho (sem título, não aparece).
    $icon   = is_string($icon ?? null) && preg_match('/^[a-z0-9-]+$/', trim($icon)) ? trim($icon) : '';
    $class  = $class  ?? '';
    $style  = $style  ?? '';
    $__cardEsc = function ($v) {
        return $v instanceof \Illuminate\Contracts\Support\Htmlable ? $v->toHtml() : e($v);
    };
@endphp

<div class="mad-card {{ $class }}" @if($style) style="{{ $style }}" @endif>
    @if($header)
        @if($icon !== '')
            <div class="mad-card-header"><span class="mad-card-heading"><i data-lucide="{{ $icon }}" aria-hidden="true"></i>{!! $__cardEsc($header) !!}</span></div>
        @else
            <div class="mad-card-header">{!! $__cardEsc($header) !!}</div>
        @endif
    @endif

    <div class="mad-card-content">
        {!! $slot !!}
    </div>

    @if($footer)
        <div class="mad-card-footer">{!! $__cardEsc($footer) !!}</div>
    @endif
</div>
