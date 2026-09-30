{{--
    Popup compartilhado do madDatePicker / madDateTimePicker.
    Esperado: estar dentro de um x-data Alpine que expoe
    monthLabel, days, dayClasses, prevMonth, nextMonth, selectDate,
    selectToday, clear, isOpen + (quando $withTime) hh, mi, setHour, setMinute, apply.

    Vars:
      $withTime  bool — mostra seletor de hora + botoes Agora/Aplicar
      $opens     string (left|right|center, default 'left')
--}}
@php $opens = $opens ?? 'left'; @endphp
<div class="mad-drp-popup mad-drp-popup--single mad-drp-popup--{{ $opens }}"
     x-show="isOpen"
     x-transition:enter="mad-drp-enter"
     x-transition:enter-start="mad-drp-enter-start"
     x-transition:enter-end="mad-drp-enter-end"
     x-transition:leave="mad-drp-leave"
     x-transition:leave-start="mad-drp-leave-start"
     x-transition:leave-end="mad-drp-leave-end"
     x-cloak>
    <div class="mad-drp-calendars">
        <div class="mad-drp-cal">
            <div class="mad-drp-nav">
                <button type="button" class="mad-drp-nav-btn" @click="prevMonth()">
                    <i data-lucide="chevron-left" style="width:16px;height:16px;"></i>
                </button>
                <span class="mad-drp-month-label" x-text="monthLabel"></span>
                <button type="button" class="mad-drp-nav-btn" @click="nextMonth()">
                    <i data-lucide="chevron-right" style="width:16px;height:16px;"></i>
                </button>
            </div>
            <div class="mad-drp-weekdays">
                <span>D</span><span>S</span><span>T</span><span>Q</span><span>Q</span><span>S</span><span>S</span>
            </div>
            <div class="mad-drp-days">
                <template x-for="(day, di) in days" :key="di">
                    <button type="button" class="mad-drp-day"
                            :class="dayClasses(day)"
                            :disabled="day.disabled || !day.currentMonth"
                            @click="day.currentMonth && selectDate(day.date)"
                            x-text="day.day"></button>
                </template>
            </div>
        </div>
    </div>

    @if(!empty($withTime))
    <div class="mad-dp-time">
        <span class="mad-dp-time-label">Hora:</span>
        <input type="number" class="mad-dp-time-input" min="0" max="23" step="1"
               :value="String(hh).padStart(2,'0')"
               @input="setHour($event.target.value)"
               @blur="$event.target.value = String(hh).padStart(2,'0')">
        <span class="mad-dp-time-sep">:</span>
        <input type="number" class="mad-dp-time-input" min="0" max="59" step="1"
               :value="String(mi).padStart(2,'0')"
               @input="setMinute($event.target.value)"
               @blur="$event.target.value = String(mi).padStart(2,'0')">
    </div>
    @endif

    <div class="mad-dp-footer">
        <button type="button" class="mad-dp-footer-btn mad-dp-footer-btn--danger" @click="clear()">
            <i data-lucide="x" style="width:13px;height:13px;"></i>
            Limpar
        </button>
        @if(!empty($withTime))
        <div style="display:flex;gap:6px;">
            <button type="button" class="mad-dp-footer-btn" @click="selectToday()">
                <i data-lucide="calendar-check" style="width:13px;height:13px;"></i>
                Agora
            </button>
            <button type="button" class="mad-dp-footer-btn mad-dp-footer-btn--primary" @click="apply()">
                <i data-lucide="check" style="width:13px;height:13px;"></i>
                Aplicar
            </button>
        </div>
        @else
        <button type="button" class="mad-dp-footer-btn mad-dp-footer-btn--primary" @click="selectToday()">
            <i data-lucide="calendar-check" style="width:13px;height:13px;"></i>
            Hoje
        </button>
        @endif
    </div>
</div>
