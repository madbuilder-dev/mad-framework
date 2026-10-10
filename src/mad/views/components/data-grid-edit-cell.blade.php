{{-- ====================================================================
    Editor "ativavel" para os modos edit-mode="click" e "dblclick".
    Esta dentro do <div x-show="isEditing(...)"> — usa x-model="editValue"
    e commitEdit().

    Variaveis esperadas:
      $col            \Mad\Grid\GridColumn
      $cellVal        mixed (valor cru, usado em color/dbunique-search)
      $editComboOptions    array (cache para dbcombo)
      $editSearchToken     array (token para dbunique-search)
      $editSearchPreloaded array
==================================================================== --}}
@if($col->editType === 'select')
    <select class="mad-select mad-dg-cell-input" data-mad-dg-edit
            x-model="editValue"
            @change="commitEdit()" @keydown.escape="cancelEdit()">
        @foreach($col->editOpts as $ov => $ol)
        <option value="{{ $ov }}">{{ $ol }}</option>
        @endforeach
    </select>
@elseif($col->editType === 'dbcombo')
    @php $opts = $editComboOptions[$col->field] ?? []; @endphp
    <select class="mad-select mad-dg-cell-input"
            data-mad-select data-mad-dg-edit
            x-model="editValue"
            @change="commitEdit()" @keydown.escape="cancelEdit()">
        <option value="">—</option>
        @foreach($opts as $ov => $ol)
        <option value="{{ $ov }}">{{ $ol }}</option>
        @endforeach
    </select>
@elseif($col->editType === 'dbunique-search')
    @php
        $tok    = $editSearchToken[$col->field] ?? '';
        $preMap = $editSearchPreloaded[$col->field] ?? [];
        $preLbl = $preMap[(string)$cellVal] ?? '';
    @endphp
    <select class="mad-dg-cell-input"
            data-mad-dbsearch data-mad-dg-edit
            data-mad-search-token="{{ $tok }}"
            data-min-length="{{ $col->editMinLength }}"
            data-placeholder="Digite para buscar..."
            x-model="editValue"
            @change="commitEdit()" @keydown.escape="cancelEdit()">
        <option value=""></option>
        @if((string)$cellVal !== '' && $preLbl !== '')
        <option value="{{ $cellVal }}" selected>{{ $preLbl }}</option>
        @endif
    </select>
@elseif($col->editType === 'date')
    @php
        $eid = 'dgct_' . preg_replace('/[^a-z0-9_]/i', '_', $col->field) . '_' . mt_rand(1000, 9999);
        // A mesma chave que o startEdit() desta célula recebeu (ver x-effect abaixo).
        $dpRowJs = $rowIdJs ?? \Mad\Grid\MadDataGrid::rowIdJs($rowId ?? '');
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
                var root = this.$el.closest('[x-data*=\'madDataGrid\']');
                if (root && window.Alpine) {
                    try { var d = Alpine.$data(root); d.editValue = iso; d.commitEdit(); } catch (e) {}
                }
            }
         }))"
         {{-- O calendário abre com o valor EM EDIÇÃO (editValue do grid), não com o
              initialValue embutido no HTML da linha: reaberto antes de a resposta
              do salvamento anterior chegar, ele mostrava a data antiga; depois de
              um Esc, a data digitada e descartada. --}}
         x-effect="isEditing({{ $dpRowJs }},'{{ $col->field }}') && setValue(editValue)"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input id="{{ $eid }}" type="text" autocomplete="off" inputmode="numeric"
               class="mad-input mad-dg-cell-input mad-dg-datepicker"
               placeholder="dd/mm/aaaa"
               @keydown.enter="$event.target.blur()" @keydown.escape="cancelEdit()">
        <button type="button" class="mad-datepicker-btn" tabindex="-1" @click="toggle()">
            <i data-lucide="calendar" style="width:14px;height:14px;"></i>
        </button>
        @include('components.partials.date-popup', ['withTime' => false])
    </div>
@elseif($col->editType === 'datetime')
    @php
        $eid = 'dgct_' . preg_replace('/[^a-z0-9_]/i', '_', $col->field) . '_' . mt_rand(1000, 9999);
        $dpRowJs = $rowIdJs ?? \Mad\Grid\MadDataGrid::rowIdJs($rowId ?? '');
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
                var root = this.$el.closest('[x-data*=\'madDataGrid\']');
                if (root && window.Alpine) {
                    try { var d = Alpine.$data(root); d.editValue = iso; d.commitEdit(); } catch (e) {}
                }
            }
         }))"
         {{-- O calendário abre com o valor EM EDIÇÃO (editValue do grid), não com o
              initialValue embutido no HTML da linha: reaberto antes de a resposta
              do salvamento anterior chegar, ele mostrava a data antiga; depois de
              um Esc, a data digitada e descartada. --}}
         x-effect="isEditing({{ $dpRowJs }},'{{ $col->field }}') && setValue(editValue)"
         @keydown.escape.window="close()"
         @click.outside="close()">
        <input id="{{ $eid }}" type="text" autocomplete="off" inputmode="numeric"
               class="mad-input mad-dg-cell-input mad-dg-datetimepicker"
               placeholder="dd/mm/aaaa hh:mm"
               @keydown.enter="$event.target.blur()" @keydown.escape="cancelEdit()">
        <button type="button" class="mad-datepicker-btn" tabindex="-1" @click="toggle()">
            <i data-lucide="calendar-clock" style="width:14px;height:14px;"></i>
        </button>
        @include('components.partials.date-popup', ['withTime' => true])
    </div>
@elseif($col->editType === 'number')
    <input type="number" class="mad-input mad-dg-cell-input" x-model="editValue"
           step="{{ $col->editStep ?? ($col->editDecimals > 0 ? '0.'.str_repeat('0', max($col->editDecimals - 1, 0)).'1' : '1') }}"
           @if($col->editMin !== null) min="{{ $col->editMin }}" @endif
           @if($col->editMax !== null) max="{{ $col->editMax }}" @endif
           @keydown.enter="$event.target.reportValidity() && commitEdit()" @keydown.escape="cancelEdit()" @blur="commitEdit()">
@elseif($col->editType === 'numeric')
    <div style="display:flex;align-items:center;gap:4px;">
        @if($col->editPrefix)<span class="mad-dg-edit-prefix">{{ $col->editPrefix }}</span>@endif
        <input type="text" inputmode="decimal" class="mad-input mad-dg-cell-input"
               data-edit-money data-edit-decimals="{{ $col->editDecimals }}"
               data-edit-row-id="{{ $rowId ?? '' }}" data-edit-field="{{ $col->field }}"
               @focus="madSelectOnFocus($event)"
               @input="editValue = madDgMoneyMask($event.target, {{ $col->editDecimals }})"
               @blur="commitEdit()"
               @keydown.enter="$event.target.blur()"
               @keydown.escape="cancelEdit()">
        @if($col->editSuffix)<span class="mad-dg-edit-prefix">{{ $col->editSuffix }}</span>@endif
    </div>
@elseif($col->editType === 'money')
    <div style="display:flex;align-items:center;gap:4px;">
        @if($col->editPrefix)<span class="mad-dg-edit-prefix">{{ $col->editPrefix }}</span>@endif
        <input type="text" inputmode="decimal" class="mad-input mad-dg-cell-input"
               data-edit-money data-edit-decimals="{{ $col->editDecimals }}"
               data-edit-row-id="{{ $rowId ?? '' }}" data-edit-field="{{ $col->field }}"
               @focus="madSelectOnFocus($event)"
               @input="editValue = madDgMoneyMask($event.target, {{ $col->editDecimals }})"
               @blur="commitEdit()"
               @keydown.enter="$event.target.blur()"
               @keydown.escape="cancelEdit()">
    </div>
@elseif($col->editType === 'spinner')
    <div class="mad-input-group" x-data="madSpinnerField({ min: {{ $col->editMin !== null ? $col->editMin : 'null' }}, max: {{ $col->editMax !== null ? $col->editMax : 'null' }}, step: {{ $col->editStep ?? 1 }} })" style="display:inline-flex;align-items:center;">
        <button type="button" class="mad-spinner-btn" @click="decrement()" :disabled="isAtMin()"><i data-lucide="minus" style="width:12px;height:12px;"></i></button>
        <input type="number" class="mad-input mad-dg-cell-input" x-ref="input" x-model="editValue"
               step="{{ $col->editStep ?? 1 }}"
               @if($col->editMin !== null) min="{{ $col->editMin }}" @endif
               @if($col->editMax !== null) max="{{ $col->editMax }}" @endif
               @change="commitEdit()"
               @keydown.escape="cancelEdit()">
        <button type="button" class="mad-spinner-btn" @click="increment()" :disabled="isAtMax()"><i data-lucide="plus" style="width:12px;height:12px;"></i></button>
    </div>
@elseif($col->editType === 'color')
    <div class="mad-colorfield-wrap" x-data="madColorField(editValue || '#000000')" x-init="init(); $watch('color', v => editValue = v)">
        <input type="text" class="mad-input mad-colorfield-input mad-dg-cell-input"
               x-model="color"
               @input="syncFromInput()"
               @blur="commitEdit()"
               @keydown.enter="commitEdit()" @keydown.escape="cancelEdit()"
               placeholder="#000000" autocomplete="off">
        <button type="button" class="mad-colorfield-swatch"
                x-ref="swatch" :style="'background:' + color"
                @click.prevent="pickr && pickr.show()"></button>
    </div>
@elseif($col->editType === 'textarea')
    <textarea class="mad-input mad-dg-cell-input" x-model="editValue"
              rows="{{ $col->editRows }}"
              @keydown.escape="cancelEdit()" @blur="commitEdit()"></textarea>
@else
    <input type="text" class="mad-input mad-dg-cell-input" x-model="editValue"
           @keydown.enter="commitEdit()" @keydown.escape="cancelEdit()" @blur="commitEdit()">
@endif
