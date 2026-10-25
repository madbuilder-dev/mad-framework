@php
    /**
     * Linha do <mad-attachments>. Contrato do db-blocks row-view:
     * $item, $state, $name, $vars.
     *
     * Download SEMPRE por mad_download_url() — rota assinada do framework
     * (valida path, bloqueia extensão executável, streama do disco). Nunca
     * montar rota própria: o path do banco é relativo ao disco de uploads.
     */
    $item  = $item ?? null;
    $vars  = $vars ?? [];
    $state = $state ?? '';
    $name  = $name ?? 'anexos';
    if (!$item) { return; }

    $id      = (int) ($item->id ?? 0);
    $pathCol = (string) ($vars['path'] ?? 'path');
    $path    = (string) ($item->{$pathCol} ?? '');
    $nameCol = (string) ($vars['nameCol'] ?? '');
    $nome    = $nameCol !== ''
        ? (string) ($item->{$nameCol} ?? '')
        : (string) ($item->original_name ?? ($item->filename ?? basename($path)));

    $bytes = (int) ($item->size ?? 0);
    $tam   = $bytes >= 1048576
        ? number_format($bytes / 1048576, 1, ',', '.') . ' MB'
        : ($bytes > 0 ? number_format($bytes / 1024, 1, ',', '.') . ' KB' : '');

    $mime  = (string) ($item->mime_type ?? '');
    $icone = str_starts_with($mime, 'image/') ? 'image' : (str_contains($mime, 'pdf') ? 'file-text' : 'file');
    $criado = (string) ($item->created_at ?? '');

    $urlView = $path !== '' ? mad_download_url($path) : '#';
    $urlDl   = $path !== '' ? mad_download_url($path, $nome) : '#';

    // Campos por anexo (fw#457): "Tipo: Contrato · Validade: 31/12/2026".
    // Data no formato do campo; vazio não aparece.
    $extras = [];
    foreach ((array) ($vars['fields'] ?? []) as $field => $meta) {
        $valor = $item->{$field} ?? null;
        if ($valor === null || $valor === '') {
            continue;
        }
        $valor = (string) $valor;
        if (isset($meta['options'][$valor])) {
            $valor = (string) $meta['options'][$valor];
        } elseif (($meta['type'] ?? '') === 'date' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $d)) {
            $mask  = (string) ($meta['mask'] ?? '') ?: 'dd/mm/yyyy';
            $valor = strtr($mask, ['yyyy' => $d[1], 'mm' => $d[2], 'dd' => $d[3]]);
        }
        $rotulo   = trim((string) ($meta['label'] ?? ''));
        $extras[] = ($rotulo !== '' ? $rotulo . ': ' : '') . $valor;
    }
@endphp
<div id="block-row-{{ $name }}-{{ $id }}"
     style="display:flex;align-items:center;gap:12px;padding:10px 12px;border:1px solid #e9ecef;border-radius:8px;background:#fff;">
    <span style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;background:#f1f3f5;color:#495057;flex-shrink:0;">
        <i data-lucide="{{ $icone }}" style="width:18px;height:18px;"></i>
    </span>
    <div style="flex:1;min-width:0;">
        <a href="{{ $urlView }}" target="_blank"
           style="font-weight:500;color:#1f2937;text-decoration:none;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ $nome }}</a>
        <div style="font-size:12px;color:#6b7280;margin-top:2px;">{{ $tam }}{{ $tam !== '' && $criado !== '' ? ' · ' : '' }}{{ $criado }}</div>
        @if($extras !== [])
            <div style="font-size:12px;color:#374151;margin-top:2px;" data-attachment-fields>{{ implode(' · ', $extras) }}</div>
        @endif
    </div>
    <a href="{{ $urlDl }}" class="mad-btn mad-btn-outline mad-btn-sm" style="text-decoration:none;">
        <i data-lucide="download" style="width:14px;height:14px;"></i> Baixar
    </a>
    @if(!empty($vars['deletable']))
        <button type="button" class="mad-btn mad-btn-danger mad-btn-sm"
                data-mad-click="blockRemove({{ $id }}, '{{ $state }}')"
                @if(!empty($vars['confirmRemove'])) data-mad-confirm="{{ $vars['confirmRemove'] }}" @endif
                aria-label="Excluir anexo">
            <i data-lucide="trash-2" style="width:14px;height:14px;"></i>
        </button>
    @endif
</div>
