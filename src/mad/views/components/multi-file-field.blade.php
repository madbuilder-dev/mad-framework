@props(['labelGap' => '', 'labelColor' => '', 'labelSize' => '', 'labelWeight' => '', 'labelItalic' => false, 'width' => '', 'maxWidth' => '','label' => '', 'name' => '', 'accept' => '*', 'max-files' => 0, 'max-size' => 0, 'hint' => '', 'error' => '', 'required' => false, 'disabled' => false, 'attrs' => '', 'storage' => '', 'folder' => 'uploads', 'mode' => 'comma', 'model' => '', 'foreign-key' => '', 'path-column' => '', 'name-column' => '', 'file-name' => 'prefix', 'original-name-column' => '', 'size-column' => '', 'mime-column' => '', 'disk-column' => ''])
@php
    $required   = !empty($required);
    $disabled   = !empty($disabled);
    $storage    = $storage ?? '';
    $folder     = $folder ?? 'uploads';
    $mode       = $mode ?? 'comma';
    // Valida o diretório declarado pelo dev (erro rico em tela se indevido).
    \Mad\Form\MadUploadPath::assertValidFolder($folder, $storage, 'mad-multi-file-field name="' . $name . '"');
    $model      = $model ?? '';
    $foreignKey = $foreignKey ?? '';
    $pathColumn = $pathColumn ?? '';
    $nameColumn = $nameColumn ?? '';
    $hasError   = !empty($error);
    $reqStar    = $required ? ' <span class="mad-required">*</span>' : '';
    $maxFiles   = (int)($maxFiles ?? 0);
    $maxSize    = (int)($maxSize  ?? 0);

    // Extract mad:change from attrs (same pattern as scanner-field)
    $madChangeAction = '';
    if (preg_match('/(?:mad:change|data-mad-change)\s*=\s*"([^"]+)"/', $attrs, $_m)) {
        $madChangeAction = $_m[1];
        $attrs = preg_replace('/\s*(?:mad:change|data-mad-change)\s*=\s*"[^"]*"/', '', $attrs);
    }
    // Arquivos existentes (edição) — mode=comma: CSV de paths
    $_ctx = \Mad\Component\MadRenderContext::current();
    $existingFiles = [];
    if ($name && array_key_exists($name, $_ctx) && !empty($_ctx[$name])) {
        $val = (string) $_ctx[$name];
        $paths = array_filter(array_map('trim', explode(',', $val)));
        foreach ($paths as $p) {
            $bn = basename($p);
            $displayName = preg_match('/^[a-f0-9]{13,16}_(.+)$/i', $bn, $m) ? $m[1] : $bn;
            $url = mad_download_url($p, $displayName);
            $existingFiles[] = ['name' => $displayName, 'path' => $p, 'url' => $url];
        }
    }

    // mode=comma (disk): o que este campo está MOSTRANDO é a base do Salvar —
    // ele só tira da coluna (e do disco) o arquivo que consta aqui e que o
    // usuário removeu. O arquivo que outra aba anexou depois, e que esta tela
    // nunca mostrou, fica (ver MadForm::columnFilesShown).
    if ($mode !== 'table' && $storage === 'disk' && $name) {
        \Mad\Component\MadRenderContext::columnFilesRendered($name, array_column($existingFiles, 'path'));
    }

    // mode=table (disk): carrega os arquivos já gravados na tabela filha para
    // exibir/manter/remover na edição (chaveado por path, igual ao mode=comma).
    if ($mode === 'table' && $storage === 'disk' && empty($existingFiles)
        && $model && $foreignKey && $pathColumn) {
        $_form = \Mad\Component\MadRenderContext::getForm();
        $_src  = $_form ? $_form->getSourceRecord() : null;
        $_pid  = null;
        if ($_src) {
            $_pk  = method_exists($_src, 'getKeyName') ? $_src->getKeyName() : 'id';
            $_pid = $_src->$_pk ?? null;
        } elseif ($_form) {
            // REDESENHO (render depois de uma ação): o formulário veio do estado,
            // sem o registro. O campo saía VAZIO — e, se o HTML fosse entregue
            // (ação que redesenha a tela inteira), o Salvar seguinte apagava
            // todos os anexos como "removidos pelo usuário". O registro é o que
            // o formulário guardou quando entregou os anexos deste campo.
            $_pid = $_form->knownParent($name);
        }
        if ($_pid) {
            try {
                foreach ($model::where($foreignKey, $_pid)->get() as $_row) {
                    $p = (string) ($_row->$pathColumn ?? '');
                    if ($p === '') { continue; }
                    $nm = $nameColumn ? (string) ($_row->$nameColumn ?? '') : '';
                    if ($nm === '') {
                        $bn = basename($p);
                        $nm = preg_match('/^[a-f0-9]{13,16}_(.+)$/i', $bn, $m) ? $m[1] : $bn;
                    }
                    $existingFiles[] = ['name' => $nm, 'path' => $p, 'url' => mad_download_url($p, $nm)];
                }
                // O que este campo está MOSTRANDO: o Salvar só apaga o anexo
                // que consta aqui e que o usuário removeu. No redesenho só vale
                // se o HTML for entregue (o render de uma resposta parcial é
                // descartado, e o campo continua mostrando o que mostrava).
                // Com o nome exibido: é por ele que o aviso de anexo já removido o cita.
                $_src
                    ? $_form->rememberUploadFiles($name, $_pid, array_column($existingFiles, 'path'), array_column($existingFiles, 'name', 'path'))
                    : $_form->noteUploadFiles($name, $_pid, array_column($existingFiles, 'path'), array_column($existingFiles, 'name', 'path'));
            } catch (\Throwable $e) {
                // Tabela ausente / sem conexão no render: o campo abre SEM os
                // anexos. Não é inofensivo — "nenhum anexo mantido" no Salvar
                // seguinte apagaria todas as linhas e os arquivos do registro.
                // O formulário esquece o que tinha deste campo, e o Salvar
                // não apaga o que ele não mostrou.
                $existingFiles = [];
                $_src ? $_form->forgetChildRows($name) : $_form->noteUploadFiles($name, $_pid, null);
                error_log('[mad-multi-file-field] falha ao carregar os anexos de "' . $name
                    . '" (model=' . $model . ', fk=' . $foreignKey . ', parent=' . $_pid
                    . ') — o campo abre vazio e o Salvar NAO vai apagar anexos: ' . $e->getMessage());
            }
        }
    }
    $fileCfg    = json_encode([
        'maxFiles'        => $maxFiles,
        'maxSize'         => $maxSize,
        'fieldName'       => $name,
        'existingFiles'   => $existingFiles,
        'madChangeAction' => $madChangeAction,
        'accept'          => (string) $accept,
        'serverMax'       => \Mad\Form\MadUploadRules::serverFileBytes(),
        'stored'          => $storage !== '',
    ]);
    \Mad\Form\MadFormRegistry::register($name, 'multi-file', [
        'label'      => strip_tags($label),
        'required'   => $required,
        'storage'    => $storage,
        'folder'     => $folder,
        'mode'       => $mode,
        'model'      => $model,
        'foreignKey' => $foreignKey,
        'pathColumn' => $pathColumn,
        'nameColumn' => $nameColumn,
        'fileName'   => $fileName ?? 'prefix',
        // Limites: o servidor os confere no Salvar (MadUploadRules::check).
        // `max-size` é em KB; `*` (o padrão) aceita qualquer tipo.
        'accept'     => trim((string) $accept) === '*' ? '' : (string) $accept,
        'maxBytes'   => $maxSize > 0 ? $maxSize * 1024 : '',
        'maxFiles'   => $maxFiles > 0 ? $maxFiles : '',
        // Metadados do upload (mode="table"): mapeamento explícito. Sem estes,
        // MadForm preenche por CONVENÇÃO as colunas que existirem na tabela
        // filha (original_name / size / mime_type / disk).
        'originalNameColumn' => $originalNameColumn ?? '',
        'sizeColumn'         => $sizeColumn ?? '',
        'mimeColumn'         => $mimeColumn ?? '',
        'diskColumn'         => $diskColumn ?? '',
    ]);
    $_dimStyle = \Mad\Support\CssUnits::dim($width ?? '', $maxWidth ?? '', $labelGap ?? '') . \Mad\Support\CssUnits::labelStyle($labelColor ?? '', $labelSize ?? '', $labelWeight ?? '', $labelItalic ?? false);
@endphp
<div class="mad-field"@if($_dimStyle) style="{{ $_dimStyle }}"@endif>
    @if($label)
        <span class="mad-label">{!! $label !!}{!! $reqStar !!}</span>
    @endif
    <div
        class="mad-multi-file-wrap"
        x-data="madMultiFile({{ $fileCfg }})"
        data-mad-upload="{{ $name }}"
        @dragover.prevent="dragOver = true"
        @dragleave.prevent="dragOver = false"
        @drop.prevent="onDrop($event)"
        :class="{ 'mad-multi-file-over': dragOver }"
    >
        <input
            type="file"
            name="{{ $name }}[]"
            accept="{{ $accept }}"
            multiple
            class="mad-multi-file-input"
            x-ref="fileInput"
            @change="addFiles($event.target.files)"
            @if($disabled) disabled @endif
            {!! $attrs !!}
        >
        {{-- Hidden para sinalizar paths de arquivos existentes mantidos/removidos --}}
        <template x-for="(ef, ei) in existingFiles" :key="'ex_'+ei">
            <input type="hidden" :name="'__mad_existing_files[{{ $name }}][]'" :value="ef.path">
        </template>
        {{-- Identificador de cada arquivo NOVO, na ordem em que eles vão no POST.
             A resposta do Salvar devolve, por identificador, onde o arquivo foi
             gravado (op files_saved), e o campo deixa de tratá-lo como novo:
             sem isto ele era enviado — e regravado — a cada Salvar. --}}
        <template x-for="nf in newFiles" :key="'nw_'+nf.uid">
            <input type="hidden" :name="'__mad_new_files[{{ $name }}][]'" :value="nf.uid">
        </template>
        {{-- Dropzone --}}
        <div class="mad-file-body" @click="$refs.fileInput.click()" style="cursor:pointer;">
            <i data-lucide="upload-cloud" style="width:32px;height:32px;margin-bottom:8px;color:var(--mad-muted);"></i>
            <span>Arraste arquivos aqui ou <strong>clique para selecionar</strong></span>
            @if($maxFiles) <small style="color:var(--mad-muted);">Máx. {{ $maxFiles }} arquivo(s)</small> @endif
            @if($maxSize)  <small style="color:var(--mad-muted);">Máx. {{ round($maxSize / 1024, 1) }}MB por arquivo</small> @endif
        </div>
        {{-- Galeria de thumbnails --}}
        <div class="mad-multi-file-gallery" x-show="files.length" x-cloak>
            <template x-for="(f, i) in files" :key="i">
                <div class="mad-multi-file-card" @click="previewFile(f)">
                    {{-- Thumbnail: imagem ou ícone --}}
                    <template x-if="f.isImage">
                        <img :src="f.thumb" class="mad-multi-file-card-img" alt="">
                    </template>
                    <template x-if="!f.isImage">
                        <div class="mad-multi-file-card-icon" :style="'background:' + f.color">
                            <span x-effect="
                                $el.innerHTML = '<i data-lucide=&quot;' + f.icon + '&quot;></i>';
                                lucide && lucide.createIcons({ nodes: [$el] });
                            "></span>
                            <span class="mad-multi-file-card-ext" x-text="f.ext"></span>
                        </div>
                    </template>
                    {{-- Info --}}
                    <div class="mad-multi-file-card-info">
                        <span class="mad-multi-file-card-name" x-text="f.name" :title="f.name"></span>
                        <span class="mad-multi-file-card-size" x-text="f.sizeText"></span>
                    </div>
                    {{-- Remove --}}
                    <button type="button" class="mad-multi-file-card-remove"
                        @click.stop="removeFile(i)" title="Remover"
                        @if($disabled) disabled @endif>
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </div>
            </template>
            {{-- Card de adicionar mais --}}
            <div class="mad-multi-file-card mad-multi-file-card-add"
                @click="$refs.fileInput.click()"
                x-show="!maxFiles || files.length < maxFiles">
                <div class="mad-multi-file-card-icon" style="background:var(--mad-bg-muted);height:100%;">
                    <i data-lucide="plus" style="width:28px;height:28px;color:var(--mad-text-muted);"></i>
                    <span style="font-size:11px;color:var(--mad-text-muted);">Adicionar</span>
                </div>
            </div>
        </div>
    </div>
    <p class="mad-field-hint{{ $hasError ? ' mad-error' : '' }}" data-field-error="{{ $name }}">{!! $hasError ? $error : $hint !!}</p>
</div>
{{-- Modal de preview --}}
<div x-data="madFilePreview('{{ $name }}')">
    <div class="mad-modal-overlay" x-show="open" x-transition.opacity
        @click.self="close()" style="z-index:1060;display:none;"
        x-effect="$el.style.display = open ? '' : 'none'">
        <div class="mad-modal" style="max-width:900px;" @click.stop>
            <div class="mad-modal-header">
                <div class="mad-modal-title" x-text="fname"></div>
                <button type="button" class="mad-btn mad-btn-ghost mad-btn-sm mad-btn-icon"
                    @click.stop="close()" style="pointer-events:auto;position:relative;z-index:2;">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
            <div class="mad-modal-body" style="text-align:center;padding:12px;">
                <img x-show="ftype === 'image'" :src="src" style="max-width:100%;max-height:70vh;border-radius:8px;" alt="">
                <iframe x-show="ftype === 'pdf'" :src="src" style="width:100%;height:70vh;border:none;border-radius:8px;"></iframe>
                <div x-show="ftype === 'other'" style="padding:60px 20px;color:var(--mad-text-muted);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-bottom:12px;"><path d="M10.733 5.076a10.744 10.744 0 0 1 11.205 6.575 1 1 0 0 1 0 .696 10.747 10.747 0 0 1-1.444 2.49"/><path d="M14.084 14.158a3 3 0 0 1-4.242-4.242"/><path d="M17.479 17.499a10.75 10.75 0 0 1-15.417-5.151 1 1 0 0 1 0-.696 10.75 10.75 0 0 1 4.446-5.143"/><path d="m2 2 20 20"/></svg>
                    <p>Preview não disponível para este tipo de arquivo.</p>
                </div>
            </div>
        </div>
    </div>
</div>
