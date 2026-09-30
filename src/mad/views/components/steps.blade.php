@php
    $variant  = $variant  ?? 'dots';
    $current  = (int)($current ?? 1);
    $steps    = $steps    ?? [];
    $clickable = !empty($clickable);
    $size     = $size     ?? '';
    $class    = $class    ?? '';
    $madClick = $madClick ?? '';
    $attrs    = $attrs    ?? '';

    // mad:click chega via $attrs como data-mad-click="..." quando vem do
    // parseParams do BladeOne (tanto <mad-steps> quanto <mad-db-steps>).
    // Extrai para $madClick se ainda nao foi populado.
    if ($madClick === '' && $attrs !== '' && preg_match('/data-mad-click="([^"]+)"/', $attrs, $_mc)) {
        $madClick = $_mc[1];
    }

    $totalSteps = count($steps);

    // Compute statuses
    foreach ($steps as $i => &$_step) {
        $_step['key']         = $_step['key']         ?? ($i + 1);
        $_step['label']       = $_step['label']       ?? '';
        $_step['description'] = $_step['description'] ?? '';
        $_step['icon']        = $_step['icon']        ?? '';
        $_step['color']       = $_step['color']       ?? '';
        $_step['status']      = $_step['status']      ?? '';

        if ($_step['status'] === 'error') {
            // keep explicit error
        } elseif (($i + 1) < $current) {
            $_step['status'] = 'completed';
        } elseif (($i + 1) === $current) {
            $_step['status'] = 'active';
        } else {
            $_step['status'] = 'pending';
        }
    }
    unset($_step);

    // Fill percent for dots/progress
    $fillPercent = $totalSteps > 1 ? (($current - 1) / ($totalSteps - 1)) * 100 : 0;

    // Size class
    $sizeClass = '';
    if ($size === 'sm') $sizeClass = ' mad-steps--sm';
    elseif ($size === 'lg') $sizeClass = ' mad-steps--lg';
@endphp

@if($variant === 'arrows')
<div class="mad-steps mad-steps--arrows{{ $sizeClass }} {{ $class }}">
    @foreach($steps as $i => $step)
        @php
            $_arrowStyles = [];
            if ($step['color'] && in_array($step['status'], ['completed', 'active'])) {
                $_arrowStyles[] = 'background:' . $step['color'];
                $_arrowStyles[] = 'color:#fff';
            }
            if ($clickable && $madClick) {
                $_arrowStyles[] = 'cursor:pointer';
            }
            $_arrowStyle = !empty($_arrowStyles) ? implode(';', $_arrowStyles) : '';
            $_stepKey = json_encode((string) $step['key']);
        @endphp
        <div class="mad-step mad-step--{{ $step['status'] }}"
            @if($clickable && $madClick)
                data-mad-click='{{ $madClick }}({{ $_stepKey }})'
            @endif
            @if($_arrowStyle) style="{{ $_arrowStyle }}" @endif
        >
            <span class="mad-step-circle">
                @if($step['status'] === 'completed')
                    @if($step['icon'])
                        <i data-lucide="{{ $step['icon'] }}"></i>
                    @else
                        ✓
                    @endif
                @elseif($step['status'] === 'active' && $step['icon'])
                    <i data-lucide="{{ $step['icon'] }}"></i>
                @else
                    {{ $i + 1 }}
                @endif
            </span>
            <span class="mad-step-arrow-label">{!! $step['label'] !!}</span>
        </div>
    @endforeach
</div>

@elseif($variant === 'dots')
<div class="mad-steps mad-steps--dots{{ $sizeClass }} {{ $class }}">
    <div class="mad-steps-connector"></div>
    <div class="mad-steps-connector-fill" style="width:{{ $fillPercent }}%"></div>
    @foreach($steps as $i => $step)
        @php $_stepKey = json_encode((string) $step['key']); @endphp
        <div class="mad-step mad-step--{{ $step['status'] }}"
            @if($clickable && $madClick)
                data-mad-click='{{ $madClick }}({{ $_stepKey }})'
                style="cursor:pointer;"
            @endif
        >
            <div class="mad-step-circle">
                @if($step['status'] === 'completed')
                    @if($step['icon'])
                        <i data-lucide="{{ $step['icon'] }}"></i>
                    @else
                        ✓
                    @endif
                @elseif($step['status'] === 'active' && $step['icon'])
                    <i data-lucide="{{ $step['icon'] }}"></i>
                @else
                    {{ $i + 1 }}
                @endif
            </div>
            <div class="mad-step-label">{!! $step['label'] !!}</div>
            @if($step['description'])
                <div class="mad-step-desc">{!! $step['description'] !!}</div>
            @endif
        </div>
    @endforeach
</div>

@elseif($variant === 'numbers')
<div class="mad-steps mad-steps--numbers{{ $sizeClass }} {{ $class }}">
    @foreach($steps as $i => $step)
        @php $_stepKey = json_encode((string) $step['key']); @endphp
        <div class="mad-step mad-step--{{ $step['status'] }}"
            @if($clickable && $madClick)
                data-mad-click='{{ $madClick }}({{ $_stepKey }})'
                style="cursor:pointer;"
            @endif
        >
            <span class="mad-step-circle">
                @if($step['status'] === 'completed')
                    @if($step['icon'])
                        <i data-lucide="{{ $step['icon'] }}"></i>
                    @else
                        ✓
                    @endif
                @elseif($step['status'] === 'active' && $step['icon'])
                    <i data-lucide="{{ $step['icon'] }}"></i>
                @else
                    {{ $i + 1 }}
                @endif
            </span>
            <span class="mad-step-label">{!! $step['label'] !!}</span>
        </div>
    @endforeach
</div>

@elseif($variant === 'progress')
<div class="mad-steps mad-steps--progress{{ $sizeClass }} {{ $class }}">
    <div class="mad-steps-progress-labels">
        @foreach($steps as $i => $step)
            @php $_stepKey = json_encode((string) $step['key']); @endphp
            <span class="mad-step--{{ $step['status'] }}"
                @if($clickable && $madClick)
                    data-mad-click='{{ $madClick }}({{ $_stepKey }})'
                    style="cursor:pointer;"
                @endif
            >
                @if($step['status'] === 'completed')
                    @if($step['icon'])
                        <i data-lucide="{{ $step['icon'] }}"></i>
                    @else
                        ✓
                    @endif
                @endif
                {!! $step['label'] !!}
            </span>
        @endforeach
    </div>
    <div class="mad-steps-progress-bar">
        <div class="mad-steps-progress-fill" style="width:{{ $fillPercent }}%"></div>
    </div>
</div>
@endif
