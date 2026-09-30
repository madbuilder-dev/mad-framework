@props(['name' => '', 'accept' => '*', 'multiple' => false, 'icon' => 'paperclip', 'max-files' => 0, 'max-size' => 0, 'disabled' => false, 'class' => '', 'attrs' => '', 'storage' => '', 'folder' => 'uploads', 'mode' => 'comma', 'model' => '', 'foreign-key' => '', 'path-column' => '', 'name-column' => '', 'file-name' => 'prefix'])
@php
    $multiple   = !empty($multiple);
    $disabled   = !empty($disabled);
    $maxFiles   = (int)($maxFiles ?? 0);
    $maxSize    = (int)($maxSize  ?? 0);
    $storage    = $storage ?? '';
    $folder     = $folder ?? 'uploads';
    $mode       = $mode ?? 'comma';
    $model      = $model ?? '';
    $foreignKey = $foreignKey ?? '';
    $pathColumn = $pathColumn ?? '';
    $nameColumn = $nameColumn ?? '';
    $inputName  = $multiple ? $name . '[]' : $name;

    // Registra no MadFormRegistry com mesma API do multi-file-field
    // para que form->save() processe automaticamente
    if ($name && $storage) {
        \Mad\Form\MadFormRegistry::register($name, 'multi-file', [
            'label'      => '',
            'required'   => false,
            'storage'    => $storage,
            'folder'     => $folder,
            'mode'       => $mode,
            'model'      => $model,
            'foreignKey' => $foreignKey,
            'pathColumn' => $pathColumn,
            'nameColumn' => $nameColumn,
            'fileName'   => $fileName ?? 'prefix',
        ]);
    }
@endphp
<div class="mad-attach-btn-wrap {{ $class }}"
     x-data="{
        files: [],
        addFiles(fileList) {
            for (let f of fileList) {
                if ({{ $maxFiles }} && this.files.length >= {{ $maxFiles }}) break;
                this.files.push({ name: f.name, size: f.size, _file: f });
            }
            this.syncInput();
        },
        removeFile(i) {
            this.files.splice(i, 1);
            this.syncInput();
        },
        syncInput() {
            const dt = new DataTransfer();
            this.files.forEach(f => { if (f._file) dt.items.add(f._file); });
            this.$refs.fileInput.files = dt.files;
        },
        formatSize(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
            return bytes + ' B';
        }
     }">
    <label class="mad-attach-btn {{ $disabled ? 'disabled' : '' }}" title="Anexar arquivo">
        <i data-lucide="{{ $icon }}" style="width:18px;height:18px;"></i>
        <input type="file"
               name="{{ $inputName }}"
               accept="{{ $accept }}"
               {{ $multiple ? 'multiple' : '' }}
               {{ $disabled ? 'disabled' : '' }}
               x-ref="fileInput"
               @change="addFiles($event.target.files)"
               style="display:none;"
               {!! $attrs !!} />
    </label>
    <template x-if="files.length > 0">
        <div class="mad-attach-btn-chips">
            <template x-for="(f, i) in files" :key="i">
                <span class="mad-attach-btn-chip">
                    <span x-effect="
                        $el.innerHTML = '<i data-lucide=&quot;file&quot; style=&quot;width:12px;height:12px;&quot;></i>';
                        lucide && lucide.createIcons({ nodes: [$el] });
                    "></span>
                    <span class="mad-attach-btn-chip-name" x-text="f.name"></span>
                    <span class="mad-attach-btn-chip-size" x-text="formatSize(f.size)"></span>
                    <button type="button" class="mad-attach-btn-chip-remove" @click.stop="removeFile(i)">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                    </button>
                </span>
            </template>
        </div>
    </template>
</div>
