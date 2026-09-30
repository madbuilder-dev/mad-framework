@php
    $heading  = $heading  ?? '';
    $expanded = !empty($expanded);
    $class    = $class    ?? '';
@endphp
<div class="mad-accordion-item {{ $class }}"
     x-data="{ open: {{ $expanded ? 'true' : 'false' }} }">
    <button type="button" class="mad-accordion-trigger" @click="open = !open">
        <span style="flex:1;">{!! $heading !!}</span>
        <i data-lucide="chevron-down"
           style="width:16px;height:16px;flex-shrink:0;transition:transform var(--mad-t);"
           :style="open ? 'transform:rotate(180deg)' : 'transform:rotate(0deg)'"></i>
    </button>
    <div x-show="open" x-cloak>
        <div class="mad-accordion-body">
            {!! $slot !!}
        </div>
    </div>
</div>
