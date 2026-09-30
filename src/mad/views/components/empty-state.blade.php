@php
    $title       = $title       ?? '';
    $description = $description ?? null;
    $icon        = $icon        ?? 'inbox';
    $class       = $class       ?? '';
@endphp

<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:48px 24px;text-align:center;" class="{{ $class }}">
    <div style="width:56px;height:56px;border-radius:14px;background:var(--mad-bg-muted);display:flex;align-items:center;justify-content:center;margin-bottom:16px;">
        <i data-lucide="{{ $icon }}" style="width:26px;height:26px;color:var(--mad-text-subtle);"></i>
    </div>

    <p style="font-size:var(--mad-text-base);font-weight:600;color:var(--mad-text);margin:0 0 6px;">{{ $title }}</p>

    @if($description)
        <p style="font-size:var(--mad-text-sm);color:var(--mad-text-muted);margin:0 0 20px;max-width:320px;line-height:1.5;">{{ $description }}</p>
    @endif

    {!! $slot !!}
</div>
