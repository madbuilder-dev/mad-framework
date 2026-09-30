{{-- <mad-goal-ladder> — "escada" Meta → Projeção → Ideal → Realizado → Saldo
     (widget assinatura de BI gerencial). HTML+CSS puro, sem chart.

     Props:
       title    string   título acima da escada (opcional)
       :steps   array    [['label'=>'Meta de Venda','value'=>'202,03 M',
                           'delta'=>['text'=>'56,4%','dir'=>'down|up|flat']?,
                           'muted'=>bool?, 'accent'=>'#hex'?], ...]
                         `value` JÁ formatado (string); `delta` desenha a seta
                         ENTRE o degrau anterior e este.
       accent   string   cor de fundo dos blocos (default #2F4F3F)
       class / style --}}
@php
    $title  = $title ?? null;
    $steps  = is_array($steps ?? null) ? array_values($steps) : [];
    $accent = $accent ?? '#2F4F3F';
    $class  = $class ?? '';
    $style  = $style ?? '';
@endphp
<div class="mad-goal-ladder {{ $class }}" @if($style) style="{{ $style }}" @endif>
    @if($title)
        <div class="mad-goal-ladder-title">{{ $title }}</div>
    @endif
    @foreach($steps as $i => $s)
        @php
            $delta = $s['delta'] ?? null;
            $dir   = $delta['dir'] ?? 'flat';
            $dArrow = $dir === 'up' ? '▲' : ($dir === 'down' ? '▼' : '•');
            $bg = $s['accent'] ?? $accent;
        @endphp
        @if($delta)
            <div class="mad-goal-ladder-delta mad-goal-ladder-delta-{{ $dir }}">
                <span>{{ $dArrow }}</span> {{ $delta['text'] ?? '' }} <span>{{ $dArrow }}</span>
            </div>
        @endif
        <div class="mad-goal-ladder-step{{ !empty($s['muted']) ? ' is-muted' : '' }}"
             style="background:{{ $bg }};--mad-gl-i:{{ $i }}">
            <span class="mad-goal-ladder-label">{{ $s['label'] ?? '' }}</span>
            <span class="mad-goal-ladder-value">{{ $s['value'] ?? '' }}</span>
        </div>
    @endforeach
</div>
