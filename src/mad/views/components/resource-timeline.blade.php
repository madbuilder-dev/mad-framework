@php
    $config  = $config ?? [];
    $calId   = $config['calendarId'] ?? ('mad-rt-' . uniqid());
    $cfgJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG);
@endphp
<div class="mad-rt" id="{{ $calId }}" x-data="madResourceTimeline({{ $cfgJson }})">

    {{-- Toolbar --}}
    <div class="mad-rt-toolbar">
        <div class="mad-rt-toolbar-nav">
            <button type="button" class="mad-rt-btn" @click="prev()">&#9664;</button>
            <button type="button" class="mad-rt-btn" @click="next()">&#9654;</button>
            <button type="button" class="mad-rt-btn" @click="goToday()">Hoje</button>
        </div>
        <div class="mad-rt-toolbar-title" x-text="periodLabel"></div>
        <div class="mad-rt-toolbar-views">
            <button type="button" class="mad-rt-btn" :class="numDays===1 && 'mad-rt-btn-active'" @click="switchView(1)">Dia</button>
            <button type="button" class="mad-rt-btn" :class="numDays>1 && 'mad-rt-btn-active'" @click="switchView(defaultNumDays)">
                <span x-text="defaultNumDays + ' dias'"></span>
            </button>
        </div>
    </div>

    {{-- Grid via div-based CSS Grid (Alpine x-for inside table has issues) --}}
    <div class="mad-rt-scroll">
        <div class="mad-rt-grid" :style="gridStyle()">

            {{-- Row 1: day headers --}}
            <div class="mad-rt-corner mad-rt-corner-top"></div>
            <template x-for="(day, di) in days" :key="'dh-'+di">
                <div class="mad-rt-day-header"
                     :class="day.isToday && 'mad-rt-today'"
                     :style="'grid-column: span ' + resources.length"
                     x-text="day.label"></div>
            </template>

            {{-- Row 2: resource headers --}}
            <div class="mad-rt-corner mad-rt-corner-bot"></div>
            <template x-for="(col, ci) in columns" :key="'rh-'+ci">
                <div class="mad-rt-res-header" x-text="col.resource.title"></div>
            </template>

            {{-- Time rows: for each slot, render time label + N cells --}}
            <template x-for="(slot, si) in timeSlots" :key="'ts-'+si">
                <div class="mad-rt-time" :style="'grid-row:' + (si+3) + ';grid-column:1'" x-text="slot.label"></div>
            </template>

            {{-- All cells: slot × column --}}
            <template x-for="(slot, si) in timeSlots" :key="'sr-'+si">
                <template x-for="(col, ci) in columns" :key="'sc-'+si+'-'+ci">
                    <div class="mad-rt-cell"
                         :class="col.day.isToday && 'mad-rt-today-col'"
                         :style="'grid-row:' + (si+3) + ';grid-column:' + (ci+2)"
                         @click="onCellClick(slot, col)">
                        <template x-for="ev in getEventsStartingInSlot(col.day.date, col.resource.id, slot)" :key="ev.id">
                            <div class="mad-rt-event"
                                 :style="eventStyle(ev)"
                                 :title="ev.title"
                                 @click.stop="onEventClick(ev)">
                                <div class="mad-rt-event-time" x-text="fmtTimeRange(ev)"></div>
                                <div class="mad-rt-event-title" x-text="ev.title"></div>
                            </div>
                        </template>
                    </div>
                </template>
            </template>

        </div>
    </div>
</div>
