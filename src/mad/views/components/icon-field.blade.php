@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'inputBg' => '', 'inputColor' => '', 'inputWeight' => '', 'inputItalic' => false, 'width' => '', 'maxWidth' => '', 'label' => '', 'name' => '', 'value' => '', 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'placeholder' => '', 'size' => 'md', 'allowClear' => true])
@php
    $required = !empty($required);
    $disabled = !empty($disabled);
    $allowClear = !empty($allowClear);
    if ($name) {
        $_ctx = \Mad\Component\MadRenderContext::current();
        if (array_key_exists($name, $_ctx) && (!isset($value) || $value === '')) {
            $value = $_ctx[$name];
        }
        if (strpos($attrs, 'mad:model') === false && strpos($attrs, 'data-mad-model') === false) {
            $attrs = 'mad:model="' . $name . '" ' . $attrs;
        }
    }
    $value    = (string) ($value ?? '');
    $size     = in_array($size, ['sm','md','lg'], true) ? $size : 'md';
    $id       = 'mad_' . $name . '_' . mt_rand(1000, 9999);
    $hasError = !empty($error);
    $reqStar  = $required ? ' <span class="mad-required">*</span>' : '';
    $allowClearJs = $allowClear ? 'true' : 'false';
    $placeholder  = (string) $placeholder !== '' ? (string) $placeholder : mad_t('mad.icon_field.placeholder');

    \Mad\Form\MadFormRegistry::register($name, 'icon', [
        'label'    => strip_tags($label),
        'required' => $required,
    ]);
@endphp
@php $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false) . \Mad\Support\CssUnits::inputStyle($inputBg ?? '', $inputColor ?? '', $inputWeight ?? '', $inputItalic ?? false); @endphp
<div class="mad-field" @if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <label class="mad-label" for="{{ $id }}">{!! $label !!}{!! $reqStar !!}</label>
    @endif
    <div class="mad-iconfield-wrap mad-iconfield-size-{{ $size }}"
         x-data="madIconField('{{ addslashes($value) }}', { allowClear: {{ $allowClearJs }} })"
         x-init="init()"
         @keydown.escape.window="open && close()"
         @click.outside="close()">

        <input id="{{ $id }}" name="{{ $name }}" type="hidden" :value="selectedIcon" {!! $attrs !!}>

        <button type="button"
                class="mad-iconfield-trigger{{ $hasError ? ' mad-input-error' : '' }}"
                @click.prevent="toggle()"
                @if($disabled) disabled @endif
                :aria-expanded="open.toString()"
                aria-haspopup="dialog">
            <span class="mad-iconfield-preview" aria-hidden="true">
                <i x-show="selectedIcon" :data-lucide="selectedIcon"></i>
                <i x-show="!selectedIcon" data-lucide="image" class="mad-iconfield-empty-icon"></i>
            </span>
            <span class="mad-iconfield-name" x-text="selectedIcon || '{{ addslashes($placeholder) }}'"
                  :class="{ 'mad-iconfield-name-empty': !selectedIcon }"></span>
            <span class="mad-iconfield-caret" aria-hidden="true">
                <i data-lucide="chevron-down"></i>
            </span>
        </button>

        <div class="mad-iconfield-popover"
             x-show="open"
             x-cloak
             x-transition.opacity.duration.150ms
             role="dialog"
             aria-label="{{ mad_t('mad.icon_field.select') }}">

            <div class="mad-iconfield-search">
                <i data-lucide="search" aria-hidden="true"></i>
                <input type="text"
                       x-model="query"
                       @input="filter()"
                       placeholder="{{ mad_t('mad.icon_field.search') }}"
                       x-ref="searchInput"
                       autocomplete="off"
                       @keydown.escape.prevent="close()"
                       @keydown.arrow-down.prevent="moveCursor(1)"
                       @keydown.arrow-up.prevent="moveCursor(-1)"
                       @keydown.arrow-right.prevent="moveCursor(1)"
                       @keydown.arrow-left.prevent="moveCursor(-1)"
                       @keydown.enter.prevent="pickCursor()">
                <button type="button"
                        class="mad-iconfield-clear-btn"
                        x-show="query"
                        @click.prevent="query = ''; filter(); $refs.searchInput.focus()"
                        aria-label="{{ mad_t('mad.icon_field.clear_search') }}">
                    <i data-lucide="x"></i>
                </button>
            </div>

            <div class="mad-iconfield-tabs" role="tablist">
                <button type="button" role="tab"
                        :class="{ 'is-active': tab === 'all' }"
                        @click.prevent="tab = 'all'; filter()">
                    <i data-lucide="grid-3x3"></i>
                    <span>{{ mad_t('mad.icon_field.all') }}</span>
                    <span class="mad-iconfield-count" x-text="all.length"></span>
                </button>
                <button type="button" role="tab"
                        x-show="recents.length"
                        :class="{ 'is-active': tab === 'recent' }"
                        @click.prevent="tab = 'recent'; filter()">
                    <i data-lucide="clock"></i>
                    <span>{{ mad_t('mad.icon_field.recent') }}</span>
                    <span class="mad-iconfield-count" x-text="recents.length"></span>
                </button>
            </div>

            <div class="mad-iconfield-grid"
                 x-ref="grid"
                 @scroll="onScroll($event)"
                 role="listbox">
                <template x-for="(icon, idx) in visible" :key="icon">
                    <button type="button"
                            class="mad-iconfield-cell"
                            role="option"
                            :class="{ 'is-selected': icon === selectedIcon, 'is-cursor': idx === cursor }"
                            :title="icon"
                            :aria-selected="(icon === selectedIcon).toString()"
                            @click.prevent="pick(icon)"
                            @mouseenter="cursor = idx">
                        <i :data-lucide="icon"></i>
                    </button>
                </template>
                <div x-show="!visible.length" class="mad-iconfield-empty">
                    <i data-lucide="search-x" aria-hidden="true"></i>
                    <p>{{ mad_t('mad.icon_field.none') }}</p>
                    <small x-text="query ? @js(mad_t('mad.icon_field.term')).replace(':q', query) : ''"></small>
                </div>
            </div>

            <div class="mad-iconfield-footer">
                <span class="mad-iconfield-stats">
                    {{-- Uma frase só, com o plural traduzido ("1739 ícones"); o "s" ficava solto. --}}
                    <span x-text="(filtered.length === 1 ? @js(mad_t('mad.icon_field.count_one')) : @js(mad_t('mad.icon_field.count_many'))).replace(':n', filtered.length)"></span>
                    <span x-show="visible.length < filtered.length"
                          class="mad-iconfield-stats-extra"
                          x-text="@js(mad_t('mad.icon_field.loaded')).replace(':n', visible.length)"></span>
                </span>
                <button type="button"
                        class="mad-iconfield-clear-all"
                        @click.prevent="clear()"
                        x-show="selectedIcon && allowClear">
                    <i data-lucide="x-circle"></i>
                    {{ mad_t('mad.icon_field.clear') }}
                </button>
            </div>
        </div>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
