{{-- Corpo do <mad-whiteboard> (Mad\Ui\MadWhiteboard::view). $wb = board(). --}}
@php
    $c = $wb['config'];
    $gid = 'wb' . substr(md5($c['owner'] . '|' . $c['name'] . '|' . $c['model'] . '|' . json_encode($c['filters'])), 0, 8);
    $colorLabel = static function (string $k): string {
        $key = 'mad.whiteboard.color_' . $k;
        $t = mad_t($key);
        return $t !== $key ? $t : mb_convert_case(str_replace(['_', '-'], ' ', $k), MB_CASE_TITLE);
    };
    $colorsJs = [];
    foreach ($c['colors'] as $k => $v) { $colorsJs[] = ['k' => $k, 'c' => $v, 'l' => $colorLabel($k)]; }
    $title = $c['title'];
    $placeholder = $c['placeholder'] !== '' ? $c['placeholder'] : mad_t('mad.whiteboard.placeholder');
    $noteAria = static function (array $n) use ($placeholder): string {
        $t = trim(preg_replace('/\s+/', ' ', $n['text']));
        return mad_t('mad.whiteboard.note') . ': ' . ($t !== '' ? mb_strimwidth($t, 0, 90, '…') : mad_t('mad.whiteboard.empty_note'));
    };
    $viewportStyle = $c['height'] !== '' && preg_match('/^\d+(px|vh|rem|em|%)?$/', $c['height'])
        ? 'height:' . (ctype_digit($c['height']) ? $c['height'] . 'px' : $c['height']) . ';' : '';
@endphp
<div class="mad-whiteboard{{ $wb['locked'] ? ' is-locked' : '' }}" id="{{ $gid }}" data-mad-whiteboard
     @if($c['name'] !== '') data-wb-name="{{ $c['name'] }}" @endif
     data-wb-grid="{{ $c['grid'] }}" @if($c['snap']) data-wb-snap @endif
     data-wb-min-w="{{ $c['minNoteWidth'] }}" data-wb-text-max="{{ $c['textMax'] }}" data-wb-max-w="{{ $c['maxNoteWidth'] }}"
     @if($c['heightField'] !== '') data-wb-has-h @endif
     data-wb-colors="{{ json_encode($colorsJs, JSON_UNESCAPED_UNICODE) }}"
     @if($wb['fresh'] !== '') data-wb-fresh="{{ $wb['fresh'] }}" @endif
     data-wb-t-saved="{{ mad_t('mad.whiteboard.saved') }}" data-wb-t-moved="{{ mad_t('mad.whiteboard.moved') }}"
     data-wb-t-failed="{{ mad_t('mad.whiteboard.failed_live') }}" data-wb-t-confirm="{{ mad_t('mad.whiteboard.confirm_delete') }}"
     data-wb-t-yes="{{ mad_t('mad.whiteboard.yes') }}" data-wb-t-no="{{ mad_t('mad.whiteboard.no') }}"
     data-wb-t-note="{{ mad_t('mad.whiteboard.note') }}" data-wb-t-empty="{{ mad_t('mad.whiteboard.empty_note') }}"
     data-wb-t-editing="{{ mad_t('mad.whiteboard.editing') }}">

    <div class="mad-wb-toolbar">
        @if($title !== '')<div class="mad-wb-title" id="{{ $gid }}-title">{{ $title }}</div>@endif
        @if($wb['locked'])
            <span class="mad-wb-lockbadge">
                <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2" fill="none" stroke="currentColor" stroke-width="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                {{ mad_t('mad.whiteboard.locked_badge') }}
            </span>
        @endif
        <span class="mad-wb-spacer"></span>
        @if($c['zoom'])
            <div class="mad-wb-zoom" role="group" aria-label="{{ mad_t('mad.whiteboard.zoom') }}">
                <button type="button" class="mad-wb-zbtn" data-wb-zoom="out" aria-label="{{ mad_t('mad.whiteboard.zoom_out') }}" title="{{ mad_t('mad.whiteboard.zoom_out') }}">−</button>
                <button type="button" class="mad-wb-zbtn mad-wb-zlevel" data-wb-zoom="reset" aria-label="{{ mad_t('mad.whiteboard.zoom_reset') }}" title="{{ mad_t('mad.whiteboard.zoom_reset') }}">100%</button>
                <button type="button" class="mad-wb-zbtn" data-wb-zoom="in" aria-label="{{ mad_t('mad.whiteboard.zoom_in') }}" title="{{ mad_t('mad.whiteboard.zoom_in') }}">+</button>
            </div>
        @endif
        @if($wb['canAdd'])
            <button type="button" class="mad-wb-add" data-wb-add>
                <svg viewBox="0 0 24 24" width="15" height="15" aria-hidden="true"><path d="M12 5v14M5 12h14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                <span>{{ mad_t('mad.whiteboard.add') }}</span>
            </button>
        @endif
    </div>
    @if(!$wb['locked'] && $wb['notes'] !== [])
        <p class="mad-wb-hint" id="{{ $gid }}-hint">{{ mad_t('mad.whiteboard.hint') }}</p>
    @endif

    @if($wb['error'])
        <div class="mad-wb-error" role="alert">{{ $wb['error'] }}</div>
    @else
        <div class="mad-wb-viewport" style="{{ $viewportStyle }}">
            <div class="mad-wb-stage" data-wb-stage style="width: {{ $wb['width'] }}px; height: {{ $wb['height'] }}px;">
                <div class="mad-wb-canvas{{ $c['grid'] > 0 ? ' has-grid' : '' }}" data-wb-canvas role="region"
                     aria-label="{{ $title !== '' ? $title : mad_t('mad.whiteboard.board') }}"
                     @if(!$wb['locked'] && $wb['notes'] !== []) aria-describedby="{{ $gid }}-hint" @endif
                     data-wb-w="{{ $wb['width'] }}" data-wb-h="{{ $wb['height'] }}"
                     style="width: {{ $wb['width'] }}px; height: {{ $wb['height'] }}px; --wb-grid: {{ max(8, $c['grid']) }}px;">
                    @forelse($wb['notes'] as $n)
                        <article class="mad-wb-note{{ $n['editable'] ? ' is-editable' : ' is-readonly' }}" data-wb-note="{{ $n['id'] }}"
                                 data-wb-x="{{ $n['x'] }}" data-wb-y="{{ $n['y'] }}" data-wb-w="{{ $n['w'] }}" data-wb-h="{{ $n['h'] }}"
                                 data-wb-color="{{ $n['color'] }}" tabindex="0" aria-roledescription="{{ mad_t('mad.whiteboard.note') }}"
                                 aria-label="{{ $noteAria($n) }}"
                                 style="left: {{ $n['x'] }}px; top: {{ $n['y'] }}px; width: {{ $n['w'] }}px;{{ $n['h'] > 0 ? ' height: ' . $n['h'] . 'px;' : '' }} --wb-color: {{ $c['colors'][$n['color']] ?? '#FFF3A3' }};">
                            <div class="mad-wb-text{{ $n['text'] === '' ? ' is-empty' : '' }}" data-wb-text data-placeholder="{{ $placeholder }}">{{ $n['text'] }}</div>
                            <footer class="mad-wb-foot">
                                <span class="mad-wb-meta">{{ $n['meta'] }}</span>
                                @if($n['editable'])
                                    <span class="mad-wb-tools">
                                        <button type="button" class="mad-wb-tool" data-wb-edit aria-label="{{ mad_t('mad.whiteboard.edit') }}" title="{{ mad_t('mad.whiteboard.edit') }}">
                                            <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16v4z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                                        </button>
                                        @if($c['colorField'] !== '')
                                            <button type="button" class="mad-wb-tool" data-wb-colorbtn aria-haspopup="true" aria-expanded="false" aria-label="{{ mad_t('mad.whiteboard.color') }}" title="{{ mad_t('mad.whiteboard.color') }}">
                                                <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><circle cx="12" cy="12" r="8" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="12" cy="12" r="3" fill="currentColor"/></svg>
                                            </button>
                                        @endif
                                        @if($wb['canDelete'] && $n['deletable'])
                                            <button type="button" class="mad-wb-tool" data-wb-del aria-label="{{ mad_t('mad.whiteboard.delete') }}" title="{{ mad_t('mad.whiteboard.delete') }}">
                                                <svg viewBox="0 0 24 24" width="13" height="13" aria-hidden="true"><path d="M5 7h14M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            </button>
                                        @endif
                                    </span>
                                @endif
                            </footer>
                            @if($n['editable'] && $c['widthField'] !== '')
                                <span class="mad-wb-resize" data-wb-resize aria-hidden="true"></span>
                            @endif
                        </article>
                    @empty
                        <div class="mad-wb-empty">{{ $c['emptyText'] !== '' ? $c['emptyText'] : ($wb['canAdd'] ? mad_t('mad.whiteboard.empty_add') : mad_t('mad.whiteboard.empty')) }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
    <div class="mad-wb-sr" aria-live="polite" data-wb-live></div>
</div>
