@php
    $config  = $config ?? [];
    $calId   = $config['calendarId'] ?? ('mad-fc-' . uniqid());
    $cfgJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG);
@endphp
<div class="mad-fc-wrap" id="{{ $calId }}" x-data="madFullCalendar({{ $cfgJson }})">
    <div x-ref="calendarEl" class="mad-fc"></div>
</div>
