@props([
    'recordTable' => '',   // tabela do registro-alvo (+ recordId) — acha a última instância
    'recordId'    => null,  // chave do registro — int OU texto (UUID/ULID/código)
    'instanceId'  => 0,    // OU a instância exata
])

@php
    // Mapa do fluxo de aprovação — desenha o grafo publicado (bloco `ui` da
    // definição: posições do canvas + arestas com label) com os passos ATIVOS
    // pulsando e os já percorridos marcados, mais a linha do tempo com os
    // eventos traduzidos pra linguagem humana. Dados via
    // WorkflowEngine::instanceMap (definição pinada; def órfã cai no snapshot).
    // `record_id` é coluna de TEXTO: a chave do registro pode ser UUID/ULID/
    // código. O gate é "chave não vazia", não `(int) > 0` — com PK de texto o
    // cast zerava e o mapa nunca aparecia.
    $__wfHasRecordId = $recordId !== null && $recordId !== '' && $recordId !== 0 && $recordId !== '0';
    $__wfInstance = null;
    try {
        if ((int) $instanceId > 0) {
            $__wfInstance = \App\Models\Wf\Instance::query()->find((int) $instanceId);
        } elseif ($recordTable !== '' && $__wfHasRecordId) {
            $__wfInstance = \App\Models\Wf\Instance::query()
                ->where('record_table', $recordTable)
                ->where('record_id', (string) $recordId)
                ->orderByDesc('id')
                ->first();
        }
    } catch (\Throwable) {
        $__wfInstance = null;
    }

    $__wfMap = null;
    if ($__wfInstance) {
        try {
            $__wfMap = app(\App\Service\Workflow\WorkflowEngine::class)->instanceMap($__wfInstance);
        } catch (\Throwable) {
            $__wfMap = null;
        }
    }

    $__wfNodes = (array) ($__wfMap['ui']['nodes'] ?? []);
    $__wfEdges = (array) ($__wfMap['ui']['edges'] ?? []);
    $__wfActive = (array) ($__wfMap['active_nodes'] ?? []);
    $__wfHistory = (array) ($__wfMap['history'] ?? []);
    $__wfActors = (array) ($__wfMap['actors'] ?? []);
    $__wfRoles = (array) ($__wfMap['roles'] ?? []);
    $__wfOutcomeMeta = (array) ($__wfMap['outcome_meta'] ?? []);

    // Nós já percorridos (eventos com payload.node).
    $__wfVisited = [];
    foreach ($__wfHistory as $__h) {
        $__n = $__h['payload']['node'] ?? null;
        if (is_string($__n) && $__n !== '') {
            $__wfVisited[$__n] = true;
        }
    }

    // Normaliza coordenadas do canvas → viewBox (nós sem posição caem em grade).
    $__NW = 168; $__NH = 48; $__PAD = 24;
    $__i = 0;
    foreach ($__wfNodes as $__k => &$__n) {
        if (empty($__n['x']) && empty($__n['y']) && $__i > 0) {
            $__n['x'] = ($__i % 4) * 220;
            $__n['y'] = intdiv($__i, 4) * 120;
        }
        $__i++;
    }
    unset($__n);
    $__minX = $__wfNodes === [] ? 0 : min(array_map(fn ($n) => (int) ($n['x'] ?? 0), $__wfNodes));
    $__minY = $__wfNodes === [] ? 0 : min(array_map(fn ($n) => (int) ($n['y'] ?? 0), $__wfNodes));
    $__maxX = $__wfNodes === [] ? 0 : max(array_map(fn ($n) => (int) ($n['x'] ?? 0), $__wfNodes));
    $__maxY = $__wfNodes === [] ? 0 : max(array_map(fn ($n) => (int) ($n['y'] ?? 0), $__wfNodes));
    $__w = $__maxX - $__minX + $__NW + $__PAD * 2;
    $__h = $__maxY - $__minY + $__NH + $__PAD * 2;
    $__pos = fn (array $n) => [
        (int) ($n['x'] ?? 0) - $__minX + $__PAD,
        (int) ($n['y'] ?? 0) - $__minY + $__PAD,
    ];

    $__kindColor = [
        'start' => '#3DA86F', 'human' => '#2f6fed', 'approval' => '#2f6fed',
        'condition' => '#0ea5c6', 'auto' => '#7a8699', 'notify' => '#7a8699',
        'fork' => '#8a63d2', 'join' => '#8a63d2', 'end' => '#3DA86F',
    ];
    // Textos no idioma do app (catálogo mad.ui.wf_*).
    $__t = fn (string $k, array $r = []) => mad_t('mad.ui.wf_' . $k, $r);
    $__kindLabel = [];
    foreach (['start', 'human', 'approval', 'condition', 'auto', 'notify', 'fork', 'join', 'end'] as $__k) {
        $__kindLabel[$__k] = $__t('kind_' . $__k);
    }
    $__statusLabel = [];
    foreach (['running', 'approved', 'rejected', 'returned', 'cancelled'] as $__k) {
        $__statusLabel[$__k] = $__t('st_' . $__k);
    }

    // ── De-para do histórico: evento técnico → linha humana ──────────────
    $__nodeName = fn (?string $key) => $__wfNodes[$key]['name'] ?? $key ?? '';
    $__userName = fn ($id) => $id !== null && isset($__wfActors[(int) $id])
        ? $__wfActors[(int) $id] : ($id !== null ? $__t('user_n', ['id' => $id]) : null);
    $__roleName = fn ($code) => is_string($code) && $code !== ''
        ? ($__wfRoles[$code] ?? \Illuminate\Support\Str::headline($code)) : null;
    $__intentTone = ['success' => 'ok', 'danger' => 'danger', 'warning' => 'warn', 'default' => 'info'];

    // Retorna null (evento interno, some da linha do tempo) ou
    // {tone, title, detail} pro item já traduzido.
    $__wfDescribe = function (array $h) use ($__t, $__nodeName, $__userName, $__roleName, $__wfOutcomeMeta, $__intentTone, $__statusLabel): ?array {
        $p = (array) ($h['payload'] ?? []);
        $node = is_string($p['node'] ?? null) ? $p['node'] : null;
        $step = (string) ($p['step'] ?? $__nodeName($node));

        return match ((string) $h['event']) {
            'started' => ['tone' => 'info', 'title' => $__t('ev_started'), 'detail' => null],
            'task_created' => ['tone' => 'info', 'title' => $__t('ev_task_created', ['node' => $__nodeName($node)]),
                'detail' => isset($p['user'])
                    ? $__t('owner', ['name' => $__userName($p['user'])])
                    : (($r = $__roleName($p['role'] ?? null)) ? $__t('owner_role', ['role' => $r]) : null)],
            'decided' => (function () use ($p, $node, $step, $__wfOutcomeMeta, $__intentTone) {
                $meta = $__wfOutcomeMeta[$node][$p['outcome'] ?? ''] ?? null;
                $label = $meta['label'] ?? \Illuminate\Support\Str::headline((string) ($p['outcome'] ?? ''));
                return ['tone' => $__intentTone[$meta['intent'] ?? 'default'] ?? 'info',
                    'title' => "{$step}: {$label}",
                    'detail' => ! empty($p['comment']) ? '“'.$p['comment'].'”' : null];
            })(),
            'record_changed' => ['tone' => 'danger', 'title' => $__t('ev_record_changed'),
                'detail' => $step !== '' ? $step : null],
            'record_rehashed' => ['tone' => 'info', 'title' => $__t('ev_record_rehashed', ['node' => $__nodeName($node)]), 'detail' => null],
            'approved' => ['tone' => 'ok', 'title' => $__t('ev_approved', ['step' => $step]),
                'detail' => ! empty($p['comment']) ? '“'.$p['comment'].'”' : null],
            'rejected' => ['tone' => 'danger', 'title' => $__t('ev_rejected', ['step' => $step]),
                'detail' => ! empty($p['comment']) ? '“'.$p['comment'].'”' : null],
            'returned' => ['tone' => 'warn', 'title' => $__t('ev_returned', ['step' => $step]),
                'detail' => ! empty($p['comment']) ? '“'.$p['comment'].'”' : null],
            'awaiting_peers' => ['tone' => 'muted', 'title' => $__t('ev_awaiting_peers', ['node' => $__nodeName($node)]), 'detail' => null],
            'task_skipped' => ['tone' => 'muted', 'title' => $__t('ev_task_skipped'), 'detail' => $__nodeName($node)],
            'skipped_by_condition' => ['tone' => 'muted', 'title' => $__t('ev_skipped_by_condition'), 'detail' => $__nodeName($node)],
            'forked' => ['tone' => 'branch', 'title' => $__t('ev_forked', ['n' => count((array) ($p['branches'] ?? []))]), 'detail' => null],
            'join_fired' => ['tone' => 'branch', 'title' => $__t('ev_join_fired'), 'detail' => null],
            'branch_cancelled' => ['tone' => 'muted', 'title' => $__t('ev_branch_cancelled'), 'detail' => $__nodeName($node)],
            'cancelled_by_end' => ['tone' => 'muted', 'title' => $__t('ev_cancelled_by_end'), 'detail' => $__nodeName($node)],
            'auto_applied' => ['tone' => 'info', 'title' => $__t('ev_auto_applied', ['node' => $__nodeName($node)]),
                'detail' => ! empty($p['fields']) ? $__t('fields', ['list' => implode(', ', (array) $p['fields'])]) : null],
            'reassigned' => ['tone' => 'info', 'title' => $__t('ev_reassigned', ['node' => $__nodeName($node)]),
                'detail' => isset($p['to_user']) ? $__t('new_owner', ['name' => $__userName($p['to_user'])]) : null],
            'escalated' => ['tone' => 'warn', 'title' => $__t('ev_escalated'),
                'detail' => trim(($__nodeName($node) ? $__nodeName($node).' · ' : '').$__t('new_owner', ['name' =>
                    ($__userName($p['to_user'] ?? null) ?? (($r = $__roleName($p['to_role'] ?? null)) ? $__t('role', ['role' => $r]) : '—'))]))],
            'reminded' => ['tone' => 'warn', 'title' => $__t('ev_reminded', ['node' => $__nodeName($node)]), 'detail' => null],
            'assignee_unresolved' => ['tone' => 'danger', 'title' => $__t('ev_assignee_unresolved', ['node' => $__nodeName($node)]), 'detail' => null],
            'completed' => ['tone' => match ($p['status'] ?? '') {
                    'approved' => 'ok', 'rejected' => 'danger', 'returned' => 'warn', default => 'info',
                }, 'title' => $__t('ev_completed', ['status' => $__statusLabel[$p['status'] ?? ''] ?? ($p['status'] ?? '')]), 'detail' => null],
            // Ruído interno: fora da linha do tempo.
            'join_arrival', 'notified', 'sla_skip_orphan' => null,
            default => ['tone' => 'muted', 'title' => \Illuminate\Support\Str::headline((string) $h['event']), 'detail' => null],
        };
    };

    // ⚠️ NÃO usar $__h como var de loop aqui — $__h é a ALTURA do viewBox.
    $__wfTimeline = [];
    foreach (array_reverse($__wfHistory) as $__ev) {
        $__d = $__wfDescribe($__ev);
        if ($__d === null) {
            continue;
        }
        $__meta = [];
        if (($__actor = $__userName($__ev['actor_id'])) !== null) {
            $__meta[] = $__t('by', ['name' => $__actor]);
        }
        if (! empty($__ev['at'])) {
            $__meta[] = \Illuminate\Support\Carbon::parse($__ev['at'])->format(\Mad\Support\ValueFormatter::dateFormat(true));
        }
        // Prova da decisão: de onde veio e qual versão foi decidida.
        $__evp = (array) ($__ev['payload'] ?? []);
        if (! empty($__evp['ip'])) {
            $__meta[] = 'IP '.$__evp['ip'];
        }
        if (! empty($__evp['record_hash']) && in_array($__ev['event'], ['decided', 'approved', 'rejected', 'returned'], true)) {
            $__meta[] = $__t('version', ['hash' => substr((string) $__evp['record_hash'], 0, 8)]);
        }
        $__d['meta'] = implode(' · ', $__meta);
        $__wfTimeline[] = $__d;
    }
@endphp

<div class="mad-wf-map">
    @if(! $__wfInstance)
        <p class="mad-wf-map-empty">{{ mad_t('mad.ui.wf_not_sent') }}</p>
    @elseif($__wfNodes === [])
        <p class="mad-wf-map-empty">Fluxo sem mapa publicado (republique o fluxo no MadBuilder).</p>
    @else
        <div class="mad-wf-map-head">
            <span class="mad-wf-map-status mad-wf-map-status-{{ $__wfMap['status'] }}">
                {{ $__statusLabel[$__wfMap['status']] ?? $__wfMap['status'] }}
            </span>
            @if($__wfInstance->record_label)
                <span class="mad-wf-map-record">{{ $__wfInstance->record_label }}</span>
            @endif
        </div>

        <div class="mad-wf-map-layout">
            <div class="mad-wf-map-canvas">
                <svg viewBox="0 0 {{ $__w }} {{ $__h }}" preserveAspectRatio="xMidYMid meet" role="img" aria-label="{{ mad_t('mad.ui.wf_map') }}">
                    <defs>
                        <marker id="wfArrow" markerWidth="8" markerHeight="8" refX="7" refY="3" orient="auto">
                            <path d="M0,0 L7,3 L0,6 Z" fill="#9aa4b2" />
                        </marker>
                    </defs>

                    {{-- Arestas (por baixo dos nós) --}}
                    @foreach($__wfEdges as $__e)
                        @php
                            $__from = $__wfNodes[$__e['from'] ?? ''] ?? null;
                            $__to = $__wfNodes[$__e['to'] ?? ''] ?? null;
                            if (! $__from || ! $__to) { continue; }
                            [$__fx, $__fy] = $__pos($__from);
                            [$__tx, $__ty] = $__pos($__to);
                            $__x1 = $__fx + $__NW; $__y1 = $__fy + $__NH / 2;
                            $__x2 = $__tx;         $__y2 = $__ty + $__NH / 2;
                            if ($__x2 < $__x1) { // aresta de retorno: sai por baixo
                                $__x1 = $__fx + $__NW / 2; $__y1 = $__fy + $__NH;
                                $__x2 = $__tx + $__NW / 2; $__y2 = $__ty + $__NH;
                            }
                            $__mx = ($__x1 + $__x2) / 2; $__my = ($__y1 + $__y2) / 2 + ($__x2 < $__x1 ? 34 : 0);
                        @endphp
                        <path d="M{{ $__x1 }},{{ $__y1 }} Q{{ $__mx }},{{ $__my }} {{ $__x2 }},{{ $__y2 }}"
                            fill="none" stroke="#9aa4b2" stroke-width="1.6" marker-end="url(#wfArrow)" />
                        @if(! empty($__e['label']))
                            <text x="{{ $__mx }}" y="{{ $__my - 5 }}" text-anchor="middle" class="mad-wf-map-edge-label">{{ $__e['label'] }}</text>
                        @endif
                    @endforeach

                    {{-- Nós --}}
                    @foreach($__wfNodes as $__key => $__node)
                        @php
                            [$__x, $__y] = $__pos($__node);
                            $__isActive = in_array($__key, $__wfActive, true) && ($__wfMap['status'] === 'running');
                            $__isVisited = isset($__wfVisited[$__key]);
                            $__color = $__kindColor[$__node['kind'] ?? ''] ?? '#7a8699';
                        @endphp
                        <g class="mad-wf-map-node {{ $__isActive ? 'is-active' : '' }} {{ $__isVisited ? 'is-visited' : '' }}">
                            <rect x="{{ $__x }}" y="{{ $__y }}" width="{{ $__NW }}" height="{{ $__NH }}" rx="10"
                                fill="var(--mad-surface, #fff)" stroke="{{ $__color }}"
                                stroke-width="{{ $__isActive ? 3 : ($__isVisited ? 2.2 : 1.4) }}"
                                @if(! $__isActive && ! $__isVisited) opacity="0.55" @endif />
                            <text x="{{ $__x + $__NW / 2 }}" y="{{ $__y + $__NH / 2 - 2 }}" text-anchor="middle" class="mad-wf-map-node-name">{{ \Illuminate\Support\Str::limit((string) ($__node['name'] ?? $__key), 22) }}</text>
                            <text x="{{ $__x + $__NW / 2 }}" y="{{ $__y + $__NH / 2 + 13 }}" text-anchor="middle" class="mad-wf-map-node-kind" fill="{{ $__color }}">{{ $__kindLabel[$__node['kind'] ?? ''] ?? ($__node['kind'] ?? '') }}</text>
                        </g>
                    @endforeach
                </svg>
            </div>

            <div class="mad-wf-map-timeline">
                <div class="mad-wf-map-timeline-title">{{ mad_t('mad.ui.history') }}</div>
                @if($__wfTimeline === [])
                    <p class="mad-wf-map-empty" style="padding: 0 14px;">{{ mad_t('mad.ui.wf_no_events') }}</p>
                @else
                    <ul class="mad-wf-map-history">
                        @foreach($__wfTimeline as $__t)
                            <li>
                                <span class="mad-wf-map-dot mad-wf-map-dot-{{ $__t['tone'] }}"></span>
                                <div class="mad-wf-map-entry">
                                    <div class="mad-wf-map-entry-title">{{ $__t['title'] }}</div>
                                    @if(! empty($__t['detail']))
                                        <div class="mad-wf-map-entry-detail">{{ $__t['detail'] }}</div>
                                    @endif
                                    @if($__t['meta'] !== '')
                                        <div class="mad-wf-map-entry-meta">{{ $__t['meta'] }}</div>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </div>
    @endif
</div>
