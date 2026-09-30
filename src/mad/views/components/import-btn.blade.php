@php
    /**
     * mad-import-btn — Botão de importação via template.
     *
     * Busca um template de importação pelo código e renderiza um botão que abre
     * o drawer de importação (DataImportUseForm). Se o template não existe
     * ou está inativo, não renderiza nada.
     *
     * Emite HTML final auto-contido (botão + onclick resolvido por MadAction) em
     * vez de delegar a um <mad-btn navigate=...> aninhado: o pré-passe mad-* do
     * MadBlade (alias mad-→x- e navigate→onclick) NÃO roda dentro de views de
     * componente, então o <mad-btn> aninhado sairia cru. MadAction::to()->auto()
     * é a MESMA chamada que o pré-passe `navigate` gera no topo das views.
     *
     * Props:
     *   code     string   Código do template (obrigatório)
     *   variant  string   Variante do botão (default: 'outline')
     *   size     string   sm, '', lg
     *   icon     string   Ícone Lucide (default: 'upload')
     *   class    string   Classes CSS extras
     *   disabled bool     Desabilitado
     *   attrs    string   Atributos HTML extras
     *
     * Uso:
     *   <mad-import-btn code="importar-cidades">Importar Cidades</mad-import-btn>
     *   <mad-import-btn code="carga-produtos" variant="primary" icon="file-up">Importar</mad-import-btn>
     */

    $code     = $code ?? '';
    $variant  = $variant ?? 'outline';
    $size     = $size ?? '';
    $icon     = $icon ?? 'upload';
    $class    = $class ?? '';
    $disabled = !empty($disabled);
    $attrs    = $attrs ?? '';

    $_importTemplate = null;
    if ($code) {
        try {
            $_importTemplate = \App\Models\Sys\ImportTemplate::findByCode($code);
        } catch (\Throwable $e) {
            $_importTemplate = null;
        }
    }

    $_navAttrs = '';
    if ($_importTemplate) {
        // Mesma resolução do pré-passe navigate="DataImportUseForm" — gera
        // onclick="Mad.get('DataImportUseForm@onLoad', {id:N}, '/app/...')".
        $_navAttrs = \Mad\Ui\MadAction::to(
            'DataImportUseForm',
            'onLoad',
            ['id' => $_importTemplate->id]
        )->auto();
    }

    $_iconSz  = $size === 'sm' ? '12px' : '14px';
    $_sizeCls = $size ? " mad-btn-{$size}" : '';
@endphp
@if($_importTemplate)
<button type="button"
    class="mad-btn mad-btn-{{ $variant }}{{ $_sizeCls }} {{ $class }}"
    {!! $disabled ? 'disabled' : '' !!} {!! $_navAttrs !!} {!! $attrs !!}>
    @if($icon)<i data-lucide="{{ $icon }}" style="width:{{ $_iconSz }};height:{{ $_iconSz }};"></i>@endif
    {!! $slot !!}
</button>
@endif
