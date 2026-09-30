{{-- ====================================================================
    Editor inline para o modo edit-mode="inline" do MadDataGrid.

    Variaveis esperadas:
      $col            \Mad\Grid\GridColumn
      $rowId          int|string
      $cellVal        mixed (valor cru da celula)
      $editInitVal    mixed (valor formatado para edicao — date pode vir Y-m-d)
      $editComboOptions    array (cache de options para dbcombo, indexado por $col->field)
      $editSearchToken     array (token para dbunique-search, indexado por $col->field)
      $editSearchPreloaded array ([colField => [id => label]] dos labels precarregados)
==================================================================== --}}
@php
    // Literal JS do id da linha (inteiro cru, resto como string JSON) — sem
    // isto uma PK de texto saia `commitInline(0198f1e2-…,…)`: SyntaxError que o
    // Alpine engole e a edicao inline nao salvava nada. Ver MadDataGrid::rowIdJs().
    $rowIdJs = \Mad\Grid\MadDataGrid::rowIdJs($rowId);
@endphp
@if($col->editType === 'select')
    <select class="mad-select mad-dg-inline-input" data-mad-dg-edit
            @change="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)">
        @foreach($col->editOpts as $ov => $ol)
        <option value="{{ $ov }}" {{ (string)$cellVal===(string)$ov?'selected':'' }}>{{ $ol }}</option>
        @endforeach
    </select>
@elseif($col->editType === 'dbcombo')
    @php $opts = $editComboOptions[$col->field] ?? []; @endphp
    <select class="mad-select mad-dg-inline-input"
            data-mad-select data-mad-dg-edit
            @change="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)">
        <option value="">—</option>
        @foreach($opts as $ov => $ol)
        <option value="{{ $ov }}" {{ (string)$cellVal===(string)$ov?'selected':'' }}>{{ $ol }}</option>
        @endforeach
    </select>
@elseif($col->editType === 'dbunique-search')
    @php
        $tok    = $editSearchToken[$col->field] ?? '';
        $preMap = $editSearchPreloaded[$col->field] ?? [];
        $preLbl = $preMap[(string)$cellVal] ?? '';
    @endphp
    <select class="mad-dg-inline-input"
            data-mad-dbsearch data-mad-dg-edit
            data-mad-search-token="{{ $tok }}"
            data-min-length="{{ $col->editMinLength }}"
            data-placeholder="Digite para buscar..."
            @change="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)">
        <option value=""></option>
        @if((string)$cellVal !== '' && $preLbl !== '')
        <option value="{{ $cellVal }}" selected>{{ $preLbl }}</option>
        @endif
    </select>
@elseif($col->editType === 'date')
    @php
        $eid = 'dgdt_' . $rowId . '_' . preg_replace('/[^a-z0-9_]/i', '_', $col->field);
        $iso = !empty($cellVal) ? (string)$cellVal : '';
        $dpCfg = [
            'displayMask'  => 'dd/mm/yyyy',
            'databaseMask' => 'yyyy-mm-dd',
            'initialValue' => $iso,
        ];
    @endphp
    <div class="mad-input-group mad-dg-datepicker-wrap" style="position:relative;"
         x-data="madDatePicker(Object.assign({{ json_encode($dpCfg, JSON_UNESCAPED_UNICODE) }}, {
            onCommit: function(iso) {
                var w = this.$el.closest('[mad-component]');
                if (w && window.MadWire) MadWire.call(w, 'onInlineSave', [{{ $rowIdJs }}, '{{ $col->field }}', iso]);
            }
         }))"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input id="{{ $eid }}" type="text" autocomplete="off" inputmode="numeric"
               class="mad-input mad-dg-inline-input mad-dg-datepicker"
               placeholder="dd/mm/aaaa"
               @keydown.enter="$event.target.blur()">
        <button type="button" class="mad-datepicker-btn" tabindex="-1" @click="toggle()">
            <i data-lucide="calendar" style="width:14px;height:14px;"></i>
        </button>
        @include('components.partials.date-popup', ['withTime' => false])
    </div>
@elseif($col->editType === 'datetime')
    @php
        $eid = 'dgdt_' . $rowId . '_' . preg_replace('/[^a-z0-9_]/i', '_', $col->field);
        $iso = !empty($cellVal) ? (string)$cellVal : '';
        $dpCfg = [
            'displayMask'  => 'dd/mm/yyyy hh:ii',
            'databaseMask' => 'yyyy-mm-dd hh:ii',
            'initialValue' => $iso,
        ];
    @endphp
    <div class="mad-input-group mad-dg-datepicker-wrap" style="position:relative;"
         x-data="madDateTimePicker(Object.assign({{ json_encode($dpCfg, JSON_UNESCAPED_UNICODE) }}, {
            onCommit: function(iso) {
                var w = this.$el.closest('[mad-component]');
                if (w && window.MadWire) MadWire.call(w, 'onInlineSave', [{{ $rowIdJs }}, '{{ $col->field }}', iso]);
            }
         }))"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input id="{{ $eid }}" type="text" autocomplete="off" inputmode="numeric"
               class="mad-input mad-dg-inline-input mad-dg-datetimepicker"
               placeholder="dd/mm/aaaa hh:mm"
               @keydown.enter="$event.target.blur()">
        <button type="button" class="mad-datepicker-btn" tabindex="-1" @click="toggle()">
            <i data-lucide="calendar-clock" style="width:14px;height:14px;"></i>
        </button>
        @include('components.partials.date-popup', ['withTime' => true])
    </div>
@elseif($col->editType === 'number')
    <input type="number" class="mad-input mad-dg-inline-input" value="{{ $editInitVal }}"
           step="{{ $col->editStep ?? ($col->editDecimals > 0 ? '0.'.str_repeat('0', max($col->editDecimals - 1, 0)).'1' : '1') }}"
           @if($col->editMin !== null) min="{{ $col->editMin }}" @endif
           @if($col->editMax !== null) max="{{ $col->editMax }}" @endif
           @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)"
           @keydown.enter="$event.target.blur()">
@elseif($col->editType === 'numeric')
    @php $fmtVal = number_format((float)$cellVal, $col->editDecimals, ',', '.'); @endphp
    <div style="display:flex;align-items:center;gap:4px;" x-data="{ rawV: {{ json_encode((float)$cellVal) }} }">
        @if($col->editPrefix)<span class="mad-dg-edit-prefix">{{ $col->editPrefix }}</span>@endif
        <input type="text" inputmode="decimal" class="mad-input mad-dg-inline-input" value="{{ $fmtVal }}"
               data-edit-money data-edit-decimals="{{ $col->editDecimals }}"
               @focus="madSelectOnFocus($event)"
               @input="rawV = madDgMoneyMask($event.target, {{ $col->editDecimals }})"
               @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',rawV)"
               @keydown.enter="$event.target.blur()">
        @if($col->editSuffix)<span class="mad-dg-edit-prefix">{{ $col->editSuffix }}</span>@endif
    </div>
@elseif($col->editType === 'money')
    @php $fmtVal = number_format((float)$cellVal, $col->editDecimals, ',', '.'); @endphp
    <div style="display:flex;align-items:center;gap:4px;" x-data="{ rawV: {{ json_encode((float)$cellVal) }} }">
        @if($col->editPrefix)<span class="mad-dg-edit-prefix">{{ $col->editPrefix }}</span>@endif
        <input type="text" inputmode="decimal" class="mad-input mad-dg-inline-input" value="{{ $fmtVal }}"
               data-edit-money data-edit-decimals="{{ $col->editDecimals }}"
               @focus="madSelectOnFocus($event)"
               @input="rawV = madDgMoneyMask($event.target, {{ $col->editDecimals }})"
               @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',rawV)"
               @keydown.enter="$event.target.blur()">
    </div>
@elseif($col->editType === 'spinner')
    <div class="mad-input-group" x-data="madSpinnerField({ min: {{ $col->editMin !== null ? $col->editMin : 'null' }}, max: {{ $col->editMax !== null ? $col->editMax : 'null' }}, step: {{ $col->editStep ?? 1 }} })" style="display:inline-flex;align-items:center;">
        <button type="button" class="mad-spinner-btn" @click="decrement()" :disabled="isAtMin()"><i data-lucide="minus" style="width:12px;height:12px;"></i></button>
        <input type="number" class="mad-input mad-dg-inline-input" x-ref="input" value="{{ $editInitVal }}"
               step="{{ $col->editStep ?? 1 }}"
               @if($col->editMin !== null) min="{{ $col->editMin }}" @endif
               @if($col->editMax !== null) max="{{ $col->editMax }}" @endif
               @change="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)"
               @keydown.enter="$event.target.blur()">
        <button type="button" class="mad-spinner-btn" @click="increment()" :disabled="isAtMax()"><i data-lucide="plus" style="width:12px;height:12px;"></i></button>
    </div>
@elseif($col->editType === 'color')
    <div class="mad-colorfield-wrap" x-data="madColorField('{{ $cellVal ?: '#000000' }}')" x-init="init()">
        <input type="text" class="mad-input mad-colorfield-input mad-dg-inline-input"
               x-model="color"
               @input="syncFromInput()"
               @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',color)"
               placeholder="#000000" autocomplete="off">
        <button type="button" class="mad-colorfield-swatch"
                x-ref="swatch" :style="'background:' + color"
                @click.prevent="pickr && pickr.show()"></button>
    </div>
@elseif($col->editType === 'textarea')
    <textarea class="mad-input mad-dg-inline-input" rows="{{ $col->editRows }}"
              @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)">{{ $cellVal }}</textarea>
@else
    <input type="text" class="mad-input mad-dg-inline-input" value="{{ $cellVal }}"
           @blur="commitInline({{ $rowIdJs }},'{{ $col->field }}',$event.target.value)"
           @keydown.enter="$event.target.blur()">
@endif
