@php
    $config  = $config ?? [];
    $cfgJson = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG);
    $uid     = 'mad-gantt-' . uniqid();
    $title   = $config['title'] ?? '';
@endphp
<div class="mad-gantt" id="{{ $uid }}"
     x-data="madGantt({{ $cfgJson }})"
     :class="['mad-gantt--zoom-' + zoom, 'mad-gantt--density-' + density, criticalMode ? 'mad-gantt--critmode' : '']">

    {{-- ══════════════════ TOPBAR ══════════════════ --}}
    <div class="mad-gantt-topbar">
        <div class="mad-gantt-title-block">
            <h3 class="mad-gantt-project-title" x-text="title || @js($title)"></h3>
            <div class="mad-gantt-project-meta">
                <span class="mad-gantt-chip" x-show="getTimeTitle()" style="display:none">
                    <span class="chip-key">{{ __('gantt.period') }}</span>
                    <span class="chip-val" x-text="getTimeTitle()"></span>
                </span>
                <template x-for="act in headerActions" :key="act.method || act.label">
                    <button type="button" class="mad-gantt-btn" @click="onHeaderAction(act.method || act.name)">
                        <template x-if="act.icon">
                            <i :data-lucide="act.icon" x-init="$nextTick(() => window.lucide && window.lucide.createIcons({ nameAttr: 'data-lucide' }))" style="width:14px;height:14px"></i>
                        </template>
                        <span x-text="act.label"></span>
                    </button>
                </template>
            </div>
        </div>

        <div class="mad-gantt-topbar-controls">
            {{-- Search --}}
            <template x-if="showSearch">
                <label class="mad-gantt-search">
                    <span class="mad-gantt-search-ico">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"></circle><path d="m21 21-4.3-4.3"/></svg>
                    </span>
                    <input type="text"
                           placeholder="{{ __('gantt.search_placeholder') }}"
                           aria-label="{{ __('gantt.search_placeholder') }}"
                           x-model="filterText"
                           @input.debounce.150ms="onFilterChanged()" />
                    <kbd>⌘K</kbd>
                </label>
            </template>

            {{-- Filtro de fases --}}
            <div class="mad-gantt-dropdown" x-show="phases && phases.length > 0" style="display:none">
                <button type="button" class="mad-gantt-btn"
                        :class="phaseFilterMap ? 'is-active' : ''"
                        aria-haspopup="true"
                        :aria-expanded="phaseFilterOpen ? 'true' : 'false'"
                        @click.stop="phaseFilterOpen = !phaseFilterOpen">
                    {{ __('gantt.phases') }}
                    <span class="mad-gantt-btn-count" x-text="activePhaseCount() + '/' + (phases||[]).length"></span>
                </button>
                <div x-show="phaseFilterOpen"
                     x-transition
                     @click.outside="phaseFilterOpen = false"
                     class="mad-gantt-dropdown-menu mad-gantt-phase-menu"
                     style="display:none">
                    <template x-for="p in phases" :key="p.id">
                        <label class="mad-gantt-phase-item">
                            <input type="checkbox"
                                   :checked="isPhaseActive(p.id)"
                                   @change="togglePhaseFilter(p.id)">
                            <span class="mad-gantt-phase-item-dot"
                                  :style="'background:oklch(0.62 0.16 ' + p.hue + ')'"></span>
                            <span class="mad-gantt-phase-item-name" x-text="p.name"></span>
                            <span class="mad-gantt-phase-item-code mono" x-text="p.code"></span>
                        </label>
                    </template>
                    <div class="mad-gantt-phase-menu-foot">
                        <button type="button" @click.stop="selectAllPhases()">{{ __('gantt.all') }}</button>
                        <button type="button" @click.stop="clearAllPhases()">{{ __('gantt.clear') }}</button>
                    </div>
                </div>
            </div>

            {{-- Segmented zoom --}}
            <div class="mad-gantt-seg" role="group" aria-label="{{ __('gantt.zoom') }}">
                <button type="button" class="mad-gantt-seg-btn" :class="zoom === 'hour'  ? 'is-active' : ''" :aria-pressed="zoom === 'hour'  ? 'true' : 'false'" @click="setZoom('hour')">{{ __('gantt.zoom_hour') }}</button>
                <button type="button" class="mad-gantt-seg-btn" :class="zoom === 'day'   ? 'is-active' : ''" :aria-pressed="zoom === 'day'   ? 'true' : 'false'" @click="setZoom('day')">{{ __('gantt.zoom_day') }}</button>
                <button type="button" class="mad-gantt-seg-btn" :class="zoom === 'week'  ? 'is-active' : ''" :aria-pressed="zoom === 'week'  ? 'true' : 'false'" @click="setZoom('week')">{{ __('gantt.zoom_week') }}</button>
                <button type="button" class="mad-gantt-seg-btn" :class="zoom === 'month' ? 'is-active' : ''" :aria-pressed="zoom === 'month' ? 'true' : 'false'" @click="setZoom('month')">{{ __('gantt.zoom_month') }}</button>
                <button type="button" class="mad-gantt-seg-btn" :class="zoom === 'year'  ? 'is-active' : ''" :aria-pressed="zoom === 'year'  ? 'true' : 'false'" @click="setZoom('year')">{{ __('gantt.zoom_year') }}</button>
            </div>

            {{-- Critical path toggle --}}
            <button type="button" class="mad-gantt-btn"
                    :class="criticalMode ? 'is-critical-active' : ''"
                    :aria-pressed="criticalMode ? 'true' : 'false'"
                    @click="toggleCriticalMode()">
                <svg width="14" height="14" viewBox="0 0 8 6" style="margin-right:2px">
                    <polygon points="0,6 4,0 8,6" fill="currentColor"/>
                </svg>
                {{ __('gantt.critical_path') }}
            </button>

        </div>
    </div>

    {{-- ══════════════════ HEADER ROW ══════════════════ --}}
    <div class="mad-gantt-top-row">
        <div class="mad-gantt-tasklist-head" x-ref="tasklistHead" :style="`width:${taskColWidth}px`">
            {{-- Filled by sidebar.renderHead() in JS --}}
        </div>
        <div class="mad-gantt-header-scroll" x-ref="headerScroll">
            <div class="mad-gantt-header" x-ref="headerInner">
                {{-- Filled by header.render() in JS --}}
            </div>
        </div>
    </div>

    {{-- ══════════════════ BODY ══════════════════ --}}
    <div class="mad-gantt-body">
        <div class="mad-gantt-tasklist mad-gantt-sidebar" x-ref="tasklist" :style="`width:${taskColWidth}px`">
            {{-- Filled by sidebar.render() in JS --}}
        </div>
        <div class="mad-gantt-chart-scroll" x-ref="chartScroll">
            <svg class="mad-gantt-chart-svg" x-ref="chartSvg" preserveAspectRatio="xMinYMin meet">
                {{-- Layers built by chartSvg.render() in JS --}}
            </svg>
        </div>
    </div>

    {{-- ══════════════════ WORKLOAD BAND (optional) ══════════════════ --}}
    <template x-if="showWorkload">
        <div class="mad-gantt-workload" x-ref="workload">
            <div class="mad-gantt-workload-header">
                <span class="mad-gantt-workload-title">{{ __('gantt.workload') }}</span>
                <template x-if="workloadMode === 'toggle'">
                    <div class="mad-gantt-workload-toggle">
                        <label>
                            {{-- name com $uid: name global colidia o radio-group entre 2 gantts na página --}}
                            <input type="radio" name="wl-mode-{{ $uid }}" value="hours" :checked="workloadModeActive === 'hours'" @change="setWorkloadActive('hours')">
                            {{ __('gantt.hours_per_day') }}
                        </label>
                        <label>
                            <input type="radio" name="wl-mode-{{ $uid }}" value="tasks" :checked="workloadModeActive === 'tasks'" @change="setWorkloadActive('tasks')">
                            {{ __('gantt.tasks_per_day') }}
                        </label>
                    </div>
                </template>
            </div>
            {{-- Alinhado com o chart: spacer da sidebar + scroller espelhado
                 pelo scrollSync — sem isso as colunas não batiam com os dias --}}
            <div class="mad-gantt-workload-row">
                <div class="mad-gantt-workload-spacer" :style="`width:${taskColWidth}px`"></div>
                <div class="mad-gantt-workload-scroll" x-ref="workloadScroll">
                    <div class="mad-gantt-workload-body" x-ref="workloadBody">
                        {{-- Filled by workload.render() in JS --}}
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- ══════════════════ FOOTER ══════════════════ --}}
    <div class="mad-gantt-footer">
        <div class="mad-gantt-footer-stats" x-ref="stats">
            {{-- Filled by component._renderStats() --}}
        </div>
        <div class="mad-gantt-minimap" x-show="showMinimap" x-ref="minimap"
             style="display:none">
            {{-- Filled by minimap.render() --}}
        </div>
    </div>

</div>
