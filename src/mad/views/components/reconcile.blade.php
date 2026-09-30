@php
    // Template interno — só renderiza via MadReconcile::_renderInlineReconcile.
    if (!isset($__component) || !$__component instanceof \Mad\Reconcile\MadReconcile) {
        return;
    }
    $leftRows   = $__component->getLeftRows();
    $rightRows  = $__component->getRightRows();
    $suggested  = $__component->getSuggested();
    $confirmed  = $__component->getConfirmed();
    $rcKey      = $__component->rcCfgKey;
    $rcCfg      = ['key' => $rcKey, 'tolerance' => $__component->getTolerance()];
    $cfgJson    = json_encode($rcCfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
@endphp

{{-- x-data com aspas SIMPLES (JSON estrutural usa aspas duplas; HEX_APOS cobre o resto) --}}
<div class="mad-reconcile" data-mad-reconcile="{{ $rcKey }}" x-data='madReconcile({!! $cfgJson !!})'>

    {{-- Toolbar --}}
    <div class="mad-rc-toolbar">
        <div class="mad-rc-toolbar-left">
            <button type="button" class="mad-rc-btn" @click="autoMatch()">
                <i data-lucide="wand-sparkles"></i> {{ mad_t('mad.reconcile.auto_match') }}
            </button>
            @if (!empty($suggested))
                <button type="button" class="mad-rc-btn" @click="confirmAll()">
                    <i data-lucide="check-check"></i> {{ mad_t('mad.reconcile.confirm_all') }}
                    <span class="mad-rc-chip">{{ count($suggested) }}</span>
                </button>
            @endif
        </div>
        <div class="mad-rc-toolbar-status">
            <span x-show="selL.length || selR.length" x-cloak>
                <span x-text="selL.length"></span> × <span x-text="selR.length"></span> —
                {{ mad_t('mad.reconcile.difference') }}:
                <strong :class="diffOk ? 'mad-rc-ok' : 'mad-rc-bad'" x-text="fmt(diff)"></strong>
            </span>
            <button type="button" class="mad-rc-btn mad-rc-btn--primary"
                    x-show="selL.length && selR.length" x-cloak
                    :disabled="!diffOk"
                    @click="manualMatch()">
                <i data-lucide="link"></i> {{ mad_t('mad.reconcile.match_selected') }}
            </button>
        </div>
    </div>

    {{-- Painéis --}}
    <div class="mad-rc-panels">
        @foreach ([['L', $leftRows], ['R', $rightRows]] as [$side, $rows])
        <div class="mad-rc-panel">
            <div class="mad-rc-panel-header">
                <strong>{{ $__component->getPanelTitle($side) }}</strong>
                <span class="mad-rc-chip">{{ count($rows) }}</span>
                <input type="search" class="mad-rc-filter"
                       placeholder="{{ mad_t('mad.reconcile.filter') }}"
                       x-model="filter{{ $side }}">
            </div>
            <div class="mad-rc-panel-body">
                <table class="mad-rc-table">
                    <tbody>
                    @forelse ($rows as $record)
                        @php $rc = $__component->rowData($record, $side); @endphp
                        <tr class="mad-rc-row"
                            data-rc-side="{{ $side }}"
                            data-rc-id="{{ $rc['id'] }}"
                            data-rc-amount="{{ $rc['amount'] }}"
                            data-rc-text="{{ mb_strtolower(trim(($rc['doc'] ?? '') . ' ' . $rc['label'] . ' ' . $rc['amount'] . ' ' . ($rc['date'] ?? ''))) }}"
                            @php $rcIdJs = \Mad\Grid\MadDataGrid::rowIdJs($rc['id']); @endphp
                            :class="{ 'mad-rc-row--selected': sel{{ $side }}.includes({{ $rcIdJs }}) }"
                            x-show="rowVisible($el, '{{ $side }}')"
                            @click="toggle('{{ $side }}', {{ $rcIdJs }}, {{ (float) $rc['amount'] }})">
                            <td class="mad-rc-cell-date">{{ $rc['date'] ?? '—' }}</td>
                            @if ($rc['doc'] !== null)
                                <td class="mad-rc-cell-doc">{{ $rc['doc'] }}</td>
                            @endif
                            <td class="mad-rc-cell-label" title="{{ $rc['label'] }}">{{ $rc['label'] }}</td>
                            <td class="mad-rc-cell-amount">{{ $__component->formatAmount($rc['amount']) }}</td>
                        </tr>
                    @empty
                        <tr><td class="mad-rc-empty">{{ mad_t('mad.reconcile.all_matched') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Sugestões do auto-match --}}
    @if (!empty($suggested))
    <div class="mad-rc-section">
        <div class="mad-rc-section-title">{{ mad_t('mad.reconcile.suggestions') }}</div>
        @foreach ($suggested as $group)
            <div class="mad-rc-group">
                <span class="mad-rc-score" title="score">{{ $group->score }}%</span>
                <span class="mad-rc-group-sums">
                    {{ $__component->formatAmount($__component->groupSum($group, 'L')) }}
                    <i data-lucide="arrow-left-right"></i>
                    {{ $__component->formatAmount($__component->groupSum($group, 'R')) }}
                </span>
                <span class="mad-rc-group-detail">
                    @php
                        $nL = count(array_filter($group->items, fn ($i) => $i->side === 'L'));
                        $nR = count($group->items) - $nL;
                    @endphp
                    {{ $nL }} × {{ $nR }}
                </span>
                <span class="mad-rc-group-actions">
                    <button type="button" class="mad-rc-btn mad-rc-btn--sm" @click="confirmGroup({{ (int) $group->id }})">
                        <i data-lucide="check"></i> {{ mad_t('mad.reconcile.confirm') }}
                    </button>
                    <button type="button" class="mad-rc-btn mad-rc-btn--sm mad-rc-btn--ghost" @click="rejectGroup({{ (int) $group->id }})">
                        <i data-lucide="x"></i> {{ mad_t('mad.reconcile.reject') }}
                    </button>
                </span>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Conciliados (últimos) --}}
    @if (!empty($confirmed))
    <div class="mad-rc-section mad-rc-section--muted">
        <div class="mad-rc-section-title">{{ mad_t('mad.reconcile.confirmed_title') }}</div>
        @foreach ($confirmed as $group)
            <div class="mad-rc-group mad-rc-group--confirmed">
                <i data-lucide="check-circle-2" class="mad-rc-ok"></i>
                <span class="mad-rc-group-sums">
                    {{ $__component->formatAmount($__component->groupSum($group, 'L')) }}
                    <i data-lucide="arrow-left-right"></i>
                    {{ $__component->formatAmount($__component->groupSum($group, 'R')) }}
                </span>
                <span class="mad-rc-group-detail">#{{ $group->id }}</span>
                <span class="mad-rc-group-actions">
                    <button type="button" class="mad-rc-btn mad-rc-btn--sm mad-rc-btn--ghost" @click="unmatch({{ (int) $group->id }})">
                        <i data-lucide="undo-2"></i> {{ mad_t('mad.reconcile.undo') }}
                    </button>
                </span>
            </div>
        @endforeach
    </div>
    @endif
</div>
