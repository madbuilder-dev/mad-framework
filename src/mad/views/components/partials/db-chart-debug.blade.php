@php
    /**
     * Debug panel para <mad-db-chart> — exibido quando general.debug = 1
     * em config/mad.php. Mostra SQL gerado pra facilitar diagnostico.
     *
     * Vars esperadas (passadas via @include):
     *   $debugSql       string  SQL com placeholders (? ou :par_N)
     *   $debugInlined   string  SQL com binds inlined (debug, nao-executavel)
     *   $debugBinds     array   valores dos binds
     *   $chartName      string  nome do chart (id DOM)
     */
    $debugSql     = $debugSql     ?? '';
    $debugInlined = $debugInlined ?? '';
    $debugBinds   = $debugBinds   ?? [];
    $chartName    = $chartName    ?? 'chart';
@endphp
<details class="mad-chart-debug" style="margin:0;border-top:1px dashed var(--mad-border,#e5e7eb);background:var(--mad-bg-subtle,#fafbfc);">
    <summary style="padding:.5rem .75rem;cursor:pointer;font-size:.72rem;font-weight:600;color:var(--mad-text-muted,#6b7280);text-transform:uppercase;letter-spacing:.04em;display:flex;align-items:center;gap:.4rem;user-select:none;">
        <i data-lucide="bug" style="width:13px;height:13px;"></i>
        {{ mad_t('mad.chart.debug.title') }} — {{ $chartName }}
    </summary>
    <div style="padding:.5rem .75rem .75rem;font-family:ui-monospace,Consolas,Menlo,monospace;font-size:.72rem;line-height:1.45;">
        @if($debugInlined)
            <div style="margin-bottom:.4rem;color:var(--mad-text-subtle,#9ca3af);text-transform:uppercase;letter-spacing:.04em;font-size:.65rem;">
                {{ mad_t('mad.chart.debug.inlined') }}
            </div>
            <pre style="margin:0 0 .75rem;padding:.6rem .75rem;background:var(--mad-bg,#fff);border:1px solid var(--mad-border,#e5e7eb);border-radius:4px;white-space:pre-wrap;word-break:break-word;color:var(--mad-text,#111827);overflow:auto;max-height:240px;">{{ $debugInlined }}</pre>
        @endif

        <div style="margin-bottom:.4rem;color:var(--mad-text-subtle,#9ca3af);text-transform:uppercase;letter-spacing:.04em;font-size:.65rem;">
            {{ mad_t('mad.chart.debug.prepared') }}
        </div>
        <pre style="margin:0;padding:.6rem .75rem;background:var(--mad-bg,#fff);border:1px solid var(--mad-border,#e5e7eb);border-radius:4px;white-space:pre-wrap;word-break:break-word;color:var(--mad-text-muted,#374151);overflow:auto;max-height:200px;">{{ $debugSql }}</pre>

        @if(!empty($debugBinds))
            <div style="margin:.75rem 0 .25rem;color:var(--mad-text-subtle,#9ca3af);text-transform:uppercase;letter-spacing:.04em;font-size:.65rem;">
                {{ mad_t('mad.chart.debug.binds') }} ({{ count($debugBinds) }})
            </div>
            <table style="width:100%;border-collapse:collapse;background:var(--mad-bg,#fff);border:1px solid var(--mad-border,#e5e7eb);border-radius:4px;overflow:hidden;">
                <thead>
                    <tr style="background:var(--mad-bg-muted,#f3f4f6);">
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--mad-border,#e5e7eb);color:var(--mad-text-muted,#6b7280);font-weight:600;width:30%;">{{ mad_t('mad.chart.debug.param') }}</th>
                        <th style="text-align:left;padding:.3rem .5rem;border-bottom:1px solid var(--mad-border,#e5e7eb);color:var(--mad-text-muted,#6b7280);font-weight:600;">{{ mad_t('mad.chart.debug.value') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($debugBinds as $k => $v)
                        <tr>
                            <td style="padding:.3rem .5rem;border-bottom:1px solid #f3f4f6;color:var(--mad-text-muted,#6b7280);">{{ is_int($k) ? '?'.($k+1) : ':'.ltrim((string)$k,':') }}</td>
                            <td style="padding:.3rem .5rem;border-bottom:1px solid var(--mad-border,#f3f4f6);color:var(--mad-text,#111827);">{{ is_null($v) ? 'NULL' : (string) $v }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</details>
