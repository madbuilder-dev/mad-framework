{{-- <mad-segment-stats> — faixa horizontal de contadores segmentados:
     "728 Abaixo da Meta 65.9% | 80 Dentro da Meta 7.2% | ...".

     Props:
       title    string  título da faixa (opcional)
       :items   array   [['value'=>728,'label'=>'Abaixo da Meta','pct'=>65.9,
                          'icon'=>'arrow-down'?,'color'=>'#DC2626'?], ...]
       class / style --}}
@php
    $title = $title ?? null;
    $items = is_array($items ?? null) ? array_values($items) : [];
    $class = $class ?? '';
    $style = $style ?? '';
@endphp
<div class="mad-segment-stats {{ $class }}" @if($style) style="{{ $style }}" @endif>
    @if($title)
        <div class="mad-segment-stats-title">{{ $title }}</div>
    @endif
    <div class="mad-segment-stats-row">
        @foreach($items as $it)
            @php $color = $it['color'] ?? 'var(--mad-primary)'; @endphp
            <div class="mad-segment-stat" style="--mad-seg-color:{{ $color }}">
                @if(!empty($it['icon']))<i data-lucide="{{ $it['icon'] }}" class="mad-segment-stat-icon"></i>@endif
                <span class="mad-segment-stat-value">{{ $it['value'] ?? '' }}</span>
                <span class="mad-segment-stat-label">{{ $it['label'] ?? '' }}
                    @if(isset($it['pct']))<em>{{ is_numeric($it['pct']) ? number_format((float) $it['pct'], 1, ',', '.') . '%' : $it['pct'] }}</em>@endif
                </span>
            </div>
        @endforeach
    </div>
</div>
