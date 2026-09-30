@php
    /**
     * Linha do <mad-comments>. Contrato do db-blocks row-view: recebe
     * $item (Model), $state (token do bloco), $name e $vars (nomes de campo
     * declarados pelo preset).
     */
    $item  = $item ?? null;
    $vars  = $vars ?? [];
    $state = $state ?? '';
    $name  = $name ?? 'comentarios';
    if (!$item) { return; }

    $id       = (int) ($item->id ?? 0);
    $fInt     = (string) ($vars['internal'] ?? '');
    $isInt    = $fInt !== '' ? !empty($item->{$fInt}) : false;
    $autorCol = (string) ($vars['authorName'] ?? 'author_name');
    $userCol  = (string) ($vars['author'] ?? 'user_id');
    $dateCol  = (string) ($vars['date'] ?? 'created_at');
    $bodyCol  = (string) ($vars['body'] ?? 'body');

    $autor    = $item->{$autorCol} ?? (!empty($item->{$userCol}) ? ('Usuário #' . $item->{$userCol}) : 'Sistema');
    $iniciais = strtoupper(mb_substr(preg_replace('/\s+/u', ' ', trim((string) $autor)), 0, 2));
    $avatarBg = $isInt ? 'var(--mad-color-secondary, #6c757d)' : 'var(--mad-color-primary, #0d6efd)';
@endphp
<mad-card id="block-row-{{ $name }}-{{ $id }}" class="mad-shadow-sm" style="border-radius:10px;">
    <div style="display:flex;align-items:center;gap:12px;padding:12px 14px;border-bottom:1px solid #f1f3f5;">
        <div style="display:flex;align-items:center;justify-content:center;width:44px;height:44px;border-radius:50%;font-size:15px;font-weight:600;color:#fff;background:{{ $avatarBg }};flex-shrink:0;">{{ $iniciais }}</div>
        <div style="display:flex;flex-direction:column;line-height:1.3;flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:600;color:#1f2937;">{{ $autor }}</span>
                @if($fInt !== '')
                    @if($isInt)
                        <mad-badge variant="secondary" icon="lock" size="sm">Interno</mad-badge>
                    @else
                        <mad-badge variant="primary" icon="message-square" size="sm">Público</mad-badge>
                    @endif
                @endif
            </div>
            <span style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $item->{$dateCol} ?? '' }}</span>
        </div>
        @if(!empty($vars['deletable']))
            <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm"
                    data-mad-click="blockRemove({{ $id }}, '{{ $state }}')"
                    @if(!empty($vars['confirmRemove'])) data-mad-confirm="{{ $vars['confirmRemove'] }}" @endif
                    aria-label="Excluir comentário">
                <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
            </button>
        @endif
    </div>
    <div style="padding:14px 16px;white-space:pre-wrap;line-height:1.6;font-size:14px;color:#374151;">{{ $item->{$bodyCol} ?? '' }}</div>
</mad-card>
