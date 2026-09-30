{{-- Imagem do projeto — as enviadas pelo editor (Novo arquivo -> Imagem) sao
     deployadas em public/images/<nome>.<ext>, servidas em /images/<nome>.<ext>.

     Uso:
       mad-image(src="images/logo.png", alt="Logo", width="160px")
       mad-image(src="images/foto.jpg", circle, width="64px")

     `src` aceita caminho publico relativo ("images/logo.png" ou
     "/images/logo.png" — mesma URL), URL absoluta (http/https///) e data: URI.
     Sem src o componente NAO quebra: emite um placeholder discreto. --}}
@props([
    'src'     => '',
    'alt'     => '',
    'width'   => '',
    'height'  => '',
    'fit'     => '',
    'rounded' => false,
    'circle'  => false,
    'align'   => '',
    'class'   => '',
    'lazy'    => true,
    'id'      => '',
    'title'   => '',
    'attrs'   => '',
])

@php
    $srcRaw = trim((string) $src);

    // Absoluta (http/https/protocol-relative) e data: passam VERBATIM; o resto
    // vira asset() — "images/logo.png" e "/images/logo.png" dao a MESMA URL.
    $isAbsoluteSrc = $srcRaw !== '' && (
        str_starts_with($srcRaw, 'data:')
        || str_starts_with($srcRaw, '//')
        || preg_match('#^https?://#i', $srcRaw) === 1
    );
    $srcFinal = $srcRaw === ''
        ? ''
        : ($isAbsoluteSrc ? $srcRaw : asset(ltrim($srcRaw, '/')));

    // Numero puro vira px ("200" -> "200px"); qualquer length CSS passa direto.
    $__madImgSize = static function ($v): string {
        $v = trim((string) $v);
        if ($v === '') {
            return '';
        }
        return is_numeric($v) ? $v . 'px' : $v;
    };
    $w = $__madImgSize($width);
    $h = $__madImgSize($height);

    $isCircle  = filter_var($circle,  FILTER_VALIDATE_BOOLEAN);
    $isRounded = filter_var($rounded, FILTER_VALIDATE_BOOLEAN);
    $isLazy    = filter_var($lazy,    FILTER_VALIDATE_BOOLEAN);

    // object-fit so sai quando informado (e valido). Circulo FORCA cover — sem
    // recorte a imagem deforma dentro da mascara redonda.
    $fitSafe = in_array((string) $fit, ['contain', 'cover', 'fill', 'none', 'scale-down'], true)
        ? (string) $fit
        : '';
    if ($isCircle) {
        $fitSafe = 'cover';
        // Circulo com so uma dimensao seria elipse: espelha a largura na altura.
        if ($h === '' && $w !== '') {
            $h = $w;
        }
    }

    $alignSafe = in_array((string) $align, ['left', 'center', 'right'], true) ? (string) $align : '';

    // `height:auto` primeiro: a altura explicita (declarada depois) vence.
    $styleParts = ['max-width:100%', 'height:auto'];
    if ($w !== '')       { $styleParts[] = 'width:' . $w; }
    if ($h !== '')       { $styleParts[] = 'height:' . $h; }
    if ($fitSafe !== '') { $styleParts[] = 'object-fit:' . $fitSafe; }
    if ($isCircle)       { $styleParts[] = 'border-radius:50%'; }
    elseif ($isRounded)  { $styleParts[] = 'border-radius:.5rem'; }
    $styleAttr = implode(';', $styleParts) . ';';

    $classExtra = trim((string) $class);
    $classParts = ['mad-image'];
    if ($isCircle)          { $classParts[] = 'mad-image-circle'; }
    elseif ($isRounded)     { $classParts[] = 'mad-image-rounded'; }
    if ($classExtra !== '') { $classParts[] = $classExtra; }
    $classAttr = implode(' ', $classParts);

    // Passthrough de atributos extras. No MadBlade NAO existe $attributes bag
    // (o ComponentTagCompiler nativo fica desligado) — todo atributo da tag vira
    // VARIAVEL, em camelCase. A passagem se faz por tres vias:
    //   1. `attrs` — string HTML crua (onde o compilador ja despeja mad:click & cia);
    //   2. `id` / `title` — props explicitas;
    //   3. familias data-* / aria-* / x-* / mad-* — recuperadas do escopo por prefixo
    //      e re-kebabizadas (dataRole -> data-role, xData -> x-data, madShow -> mad-show).
    $__madImgTail = [];
    if ($isLazy) {
        $__madImgTail[] = 'loading="lazy"';
    }
    $idAttr = trim((string) $id);
    if ($idAttr !== '') {
        $__madImgTail[] = 'id="' . e($idAttr) . '"';
    }
    $titleAttr = trim((string) $title);
    if ($titleAttr !== '') {
        $__madImgTail[] = 'title="' . e($titleAttr) . '"';
    }
    foreach (get_defined_vars() as $__k => $__v) {
        if (! preg_match('/^(?:data|aria|x|mad)[A-Z0-9]/', $__k)) {
            continue;
        }
        if ($__v === false || $__v === null || (! is_scalar($__v) && ! $__v instanceof \Stringable)) {
            continue;
        }
        $__name = strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $__k));
        $__madImgTail[] = $__v === true ? $__name : $__name . '="' . e((string) $__v) . '"';
    }
    $attrsRaw = trim((string) $attrs);
    if ($attrsRaw !== '') {
        $__madImgTail[] = $attrsRaw;
    }
    $tailAttr = $__madImgTail ? ' ' . implode(' ', $__madImgTail) : '';

    // Placeholder mantem o buraco no layout quando `src` esta vazio.
    $emptyStyle = 'display:inline-block;'
        . ($w !== '' ? 'width:' . $w . ';'   : 'min-width:64px;')
        . ($h !== '' ? 'height:' . $h . ';'  : 'min-height:64px;')
        . 'border:1px dashed var(--mad-border,#e5e7eb);'
        . 'border-radius:' . ($isCircle ? '50%' : ($isRounded ? '.5rem' : '4px')) . ';'
        . 'background:var(--mad-surface-2,#f8fafc);';
    $emptyClass = 'mad-image mad-image-empty' . ($classExtra !== '' ? ' ' . $classExtra : '');
@endphp
@if($alignSafe !== '')
<div class="mad-image-wrap" style="text-align:{{ $alignSafe }};">
@endif
@if($srcFinal === '')
<div class="{{ $emptyClass }}" aria-hidden="true" style="{{ $emptyStyle }}"></div>
@else
<img class="{{ $classAttr }}" src="{{ $srcFinal }}" alt="{{ $alt }}" style="{{ $styleAttr }}"{!! $tailAttr !!}>
@endif
@if($alignSafe !== '')
</div>
@endif
