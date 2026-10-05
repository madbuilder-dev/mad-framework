@php
    $model        = $model        ?? '';
    $database     = $database     ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $title_field  = $titleField  ?? $title_field  ?? 'titulo';
    $body_field   = $bodyField   ?? $body_field   ?? 'descricao';
    $date_field   = $dateField   ?? $date_field   ?? 'created_at';
    $icon_field   = $iconField   ?? $icon_field   ?? '';
    $color_field  = $colorField  ?? $color_field  ?? '';
    $filters      = $filters      ?? [];
    $order        = $order        ?? 'desc';
    $both_sides   = !empty($bothSides ?? $both_sides ?? false);
    $__groupByDate = $groupByDate ?? $group_by_date ?? null;
    $group_by_date = $__groupByDate === null ? true : !empty($__groupByDate);
    $date_format  = $dateFormat  ?? $date_format  ?? 'd/m/Y';
    $time_format  = $timeFormat  ?? $time_format  ?? 'H:i';
    $limit        = (int)($limit  ?? 0);
    $load_more    = !empty($loadMore ?? $load_more ?? false);
    $per_page     = (int)($perPage ?? $per_page ?? 10);
    $cards        = !empty($cards);
    $class        = $class        ?? '';
    $items        = $items        ?? [];

    // Model query (if model set and no manual items) — 100% Query Builder
    if ($model && empty($items)) {
        try {
            $rowLimit = $limit > 0 ? $limit : ($load_more ? $per_page : null);
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            $records  = \Mad\Database\QuerySource::recordsFromQuery(
                $__qb, "{$date_field} {$order}", $rowLimit
            );

            foreach ($records as $rec) {
                // Valores vindos do banco são ESCAPADOS aqui (e()) — nunca confiar em
                // conteúdo de coluna como HTML. Como já saem HTML-safe, marcamos
                // title_html/body_html p/ o template renderizar as-is; sem esse flag o
                // default {{ }} escaparia de novo (duplo-escape corromperia & < > " ').
                $item = [
                    'title'      => e($rec->{$title_field} ?? ''),
                    'body'       => e($rec->{$body_field}  ?? ''),
                    'title_html' => true,
                    'body_html'  => true,
                    'date'       => $rec->{$date_field}  ?? '',
                ];
                if ($icon_field && isset($rec->{$icon_field})) {
                    $item['icon'] = $rec->{$icon_field};
                }
                if ($color_field && isset($rec->{$color_field})) {
                    $item['color'] = $rec->{$color_field};
                }
                $items[] = $item;
            }
        } catch (\Throwable $e) {
            $items = [];
        }
    }

    // Group items by date
    $groupedItems = [];
    foreach ($items as $item) {
        $item['title'] = $item['title'] ?? '';
        $item['body']  = $item['body']  ?? '';
        $item['date']  = $item['date']  ?? '';
        $item['icon']  = $item['icon']  ?? '';
        $item['color'] = $item['color'] ?? '';

        $groupKey = '';
        $item['time'] = '';
        if (!empty($item['date'])) {
            try {
                $dt = new \DateTime($item['date']);
                $groupKey = $dt->format($date_format);
                $item['time'] = $dt->format($time_format);
            } catch (\Throwable $e) {
                $groupKey = '';
            }
        }

        $groupedItems[$groupKey][] = $item;
    }

    $bothClass  = $both_sides ? ' mad-timeline--both' : '';
    $cardsClass = $cards ? ' mad-timeline--cards' : '';
    $itemIndex  = 0;
@endphp

<div class="mad-timeline{{ $bothClass }}{{ $cardsClass }} {{ $class }}">
    @foreach($groupedItems as $dateLabel => $dateItems)
        @if($group_by_date && $dateLabel)
            <div class="mad-tl-date-sep">
                <span class="mad-tl-date-sep-label">{{ $dateLabel }}</span>
            </div>
        @endif

        @foreach($dateItems as $item)
            @php
                $sideClass = '';
                if ($both_sides) {
                    $sideClass = ($itemIndex % 2 === 0) ? ' mad-tl-item--left' : ' mad-tl-item--right';
                }
                // Cor: variante (success/info/warning/danger) vira classe; hex —
                // o que tabela de domínio guarda (tipo, etapa, categoria) — vira
                // a variável --mad-tl-c no próprio marcador. Antes o hex virava
                // a classe `mad-tl-dot--#1E55E8`, que não existe: marcador cinza.
                $dotColor = '';
                $dotStyle = '';
                $_c = trim((string) ($item['color'] ?? ''));
                if (preg_match('/^#(?:[0-9a-f]{3,4}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $_c)) {
                    $dotColor = ' mad-tl-dot--custom';
                    $dotStyle = '--mad-tl-c:' . $_c;
                } elseif ($_c !== '' && preg_match('/^[a-z][a-z0-9_-]*$/i', $_c)) {
                    $dotColor = ' mad-tl-dot--' . $_c;
                }
                $itemIndex++;
            @endphp
            <div class="mad-tl-item{{ $sideClass }}">
                <div class="mad-tl-dot{{ $dotColor }}"@if($dotStyle) style="{{ $dotStyle }}"@endif>
                    @if($item['icon'])
                        <i data-lucide="{{ $item['icon'] }}"></i>
                    @endif
                </div>
                @if($cards)<div class="mad-tl-item-card">@endif
                @if($item['time'])
                    <div class="mad-tl-time">{{ $item['time'] }}</div>
                @endif
                @if($item['title'])
                    {{-- Escapa por PADRÃO (dados de usuário/DB). Opt-in title_html só p/
                         quem realmente passa HTML já sanitizado (espelha o tituloHtml do kanban). --}}
                    <div class="mad-tl-title">@if(!empty($item['title_html'])){!! $item['title'] !!}@else{{ $item['title'] }}@endif</div>
                @endif
                @if($item['body'])
                    {{-- idem: escapa por padrão; opt-in body_html p/ HTML confiável. --}}
                    <div class="mad-tl-body">@if(!empty($item['body_html'])){!! $item['body'] !!}@else{{ $item['body'] }}@endif</div>
                @endif
                @if($cards)</div>@endif
            </div>
        @endforeach
    @endforeach
</div>
