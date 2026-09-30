@php
    // header/footer escapam por default (texto vindo de banco = XSS armazenado
    // com {!! !!}). HTML intencional: passe um Htmlable/HtmlString.
    $header = $header ?? null;
    $footer = $footer ?? null;
    $class  = $class  ?? '';
    $style  = $style  ?? '';
    $__cardEsc = function ($v) {
        return $v instanceof \Illuminate\Contracts\Support\Htmlable ? $v->toHtml() : e($v);
    };
@endphp

<div class="mad-card {{ $class }}" @if($style) style="{{ $style }}" @endif>
    @if($header)
        <div class="mad-card-header">{!! $__cardEsc($header) !!}</div>
    @endif

    <div class="mad-card-content">
        {!! $slot !!}
    </div>

    @if($footer)
        <div class="mad-card-footer">{!! $__cardEsc($footer) !!}</div>
    @endif
</div>
