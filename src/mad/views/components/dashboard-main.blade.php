{{-- <mad-dashboard-main> — content slot paired with sidebar-style <mad-dash-filters>. --}}
{{-- In non-sidebar modes this just passes through the slot. --}}
@php
    $class = $class ?? '';
@endphp
<main class="mad-dashf-main {{ $class }}" style="order:3;min-width:0;">
    {!! $slot !!}
</main>
