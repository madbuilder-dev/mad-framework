@php
    // db-blocks row-view contract: receives $item (GedDocumentTag pivot), $state, $name
    $item  = $item ?? null;
    $state = $state ?? '';
    $name  = $name ?? 'tags';
    $vars  = $vars ?? [];   // `confirm-remove` do bloco chega por aqui
    if (!$item) return;

    // Resolve master GedTag from pivot (Eloquent lookup)
    $tag   = null;
    try { $tag = $item->tag_id ? GedTag::find($item->tag_id) : null; } catch (\Throwable $e) { $tag = null; }
    $color = $tag->color ?? '#888';
    $label = $tag->name ?? '';
    $id    = (int) ($item->id ?? 0);
@endphp
<span class="mad-tag-chip" id="block-row-{{ $name }}-{{ $id }}"
      style="background:{{ $color }}1a;color:{{ $color }};border-color:{{ $color }}40;">
    {{ $label }}
    <button type="button"
        data-mad-click="blockRemove({{ $id }}, '{{ $state }}')"
        @if(!empty($vars['confirmRemove'])) data-mad-confirm="{{ $vars['confirmRemove'] }}" @endif
        aria-label="Remover">×</button>
</span>
