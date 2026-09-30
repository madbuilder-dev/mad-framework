{{-- <mad-change-timeline> — o log de mudança de objeto como LINHA DO TEMPO de
     gravações, não como lista de linhas. Cada item é um clique em Salvar/Excluir
     (mesmo transaction_id): quem, quando, de que tela; dentro dele, cada registro
     tocado (tabela #pk) com um diff coluna a coluna — valor antigo riscado em
     vermelho, valor novo em verde. Senha/token mascarados aparecem com cadeado.

     Uso — histórico de UM registro (aba "Histórico" de um formulário):
       <mad-change-timeline table="produto" :pk="$that->id" />

     Uso — feed geral com filtros (tela Logs → Alterações):
       <mad-change-timeline :groups="$that->timelineGroups()" trace-method="onViewTrace" />

     Props: table, pk, login, class-name, session-id, limit (30) — filtros lidos
     por Mad\Database\ChangeLog::timeline(); :groups já prontos pulam a consulta;
     trace-method = método do control chamado com o id da 1ª linha da gravação;
     empty = texto do vazio; compact = menos espaço. --}}
@php
    $groups      = $groups ?? null;
    $table       = $table ?? '';
    $pk          = $pk ?? null;
    $login       = $login ?? '';
    $className   = $className ?? $class_name ?? '';
    $sessionId   = $sessionId ?? $session_id ?? '';
    $limit       = (int) ($limit ?? 30);
    $traceMethod = $traceMethod ?? $trace_method ?? '';
    $emptyText   = $empty ?? __('log.timeline_empty');
    $compact     = ! empty($compact);
    $class       = $class ?? '';

    if (! is_array($groups)) {
        $groups = \Mad\Database\ChangeLog::timeline([
            'table'      => $table,
            'pk'         => $pk,
            'login'      => $login,
            'class_name' => $className,
            'session_id' => $sessionId,
        ], $limit);
    }

    $icons = ['created' => 'plus', 'changed' => 'pencil', 'deleted' => 'trash-2', 'mixed' => 'layers'];
    $fmt = function (string $dt, string $mask) {
        try { return (new \DateTime($dt))->format($mask); } catch (\Throwable) { return $dt; }
    };
    $lastDay = null;
    $mask = \Mad\Database\ChangeLog::MASK;
@endphp

<div class="mad-ctl{{ $compact ? ' mad-ctl--compact' : '' }} {{ $class }}">
@forelse ($groups as $g)
    @php
        $day = substr($g['logdate'], 0, 10);
        $op  = $g['operation'] ?: 'mixed';
    @endphp
    @if ($day !== $lastDay)
        @php $lastDay = $day; @endphp
        <div class="mad-ctl-day"><span>{{ $fmt($g['logdate'], 'd/m/Y') }}</span></div>
    @endif
    <article class="mad-ctl-item mad-ctl--{{ $op }}">
        <div class="mad-ctl-dot"><i data-lucide="{{ $icons[$op] ?? 'circle' }}"></i></div>
        <div class="mad-ctl-card">
            <header class="mad-ctl-head">
                <time class="mad-ctl-time">{{ $fmt($g['logdate'], 'H:i:s') }}</time>
                <span class="mad-ctl-login"><i data-lucide="user"></i>{{ $g['login'] ?: '—' }}</span>
                @if (! empty($g['class_name']))
                    <span class="mad-ctl-prog"><i data-lucide="app-window"></i>{{ $g['class_name'] }}</span>
                @endif
                <span class="mad-ctl-count">{{ trans_choice('log.fields_count', (int) $g['fields'], ['n' => (int) $g['fields']]) }}</span>
                @if ($traceMethod)
                    <button type="button" class="mad-ctl-trace" mad:click="{{ $traceMethod }}({{ (int) $g['trace_id'] }})" title="{{ __('log.trace') }}" aria-label="{{ __('log.trace') }}">
                        <i data-lucide="file-text"></i>
                    </button>
                @endif
            </header>

            @foreach ($g['records'] as $rec)
                @php $rop = $rec['operation'] ?: 'mixed'; @endphp
                <section class="mad-ctl-record">
                    <div class="mad-ctl-record-head">
                        <span class="mad-ctl-op mad-ctl-op--{{ $rop }}">{{ __('log.op_' . $rop) }}</span>
                        <span class="mad-ctl-table">{{ $rec['tablename'] }}</span>
                        @if ($rec['pkvalue'] !== null && $rec['pkvalue'] !== '')
                            <span class="mad-ctl-pk">#{{ $rec['pkvalue'] }}</span>
                        @endif
                    </div>
                    @if (! empty($rec['rows']))
                    <table class="mad-ctl-diff">
                        <tbody>
                        @foreach ($rec['rows'] as $row)
                            @php
                                $old = $row['old']; $new = $row['new'];
                                $isMasked = $row['masked'];
                            @endphp
                            <tr class="mad-ctl-row mad-ctl-row--{{ $row['operation'] }}">
                                <td class="mad-ctl-col">{{ $row['column'] !== '' ? $row['column'] : '—' }}</td>
                                <td class="mad-ctl-vals">
                                    @if ($isMasked)
                                        <span class="mad-ctl-mask" title="{{ __('log.masked_value') }}"><i data-lucide="lock"></i>{{ $mask }}</span>
                                    @elseif ($row['operation'] === 'changed')
                                        <del>{{ ($old === null || $old === '') ? __('log.empty_value') : $old }}</del><span class="mad-ctl-arrow" aria-hidden="true">→</span><ins>{{ ($new === null || $new === '') ? __('log.empty_value') : $new }}</ins>
                                    @elseif ($row['operation'] === 'deleted')
                                        <del>{{ ($old === null || $old === '') ? __('log.empty_value') : $old }}</del>
                                    @else
                                        <ins>{{ ($new === null || $new === '') ? __('log.empty_value') : $new }}</ins>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    @endif
                </section>
            @endforeach
        </div>
    </article>
@empty
    <div class="mad-ctl-empty"><i data-lucide="history"></i><span>{{ $emptyText }}</span></div>
@endforelse
</div>
