<?php

/**
 * Helpers HTTP do MAD — carregado via composer files (sempre disponível,
 * inclusive em render standalone do MadBlade sem o app Laravel bootado).
 */

if (!function_exists('mad_download_url')) {
    /**
     * URL para servir um arquivo de upload via rota mad.download.
     *
     * @param string      $path     Path relativo persistido pelo MadForm (ex: "uploads/abc_foto.jpg")
     * @param string|null $basename Nome de exibição — presença força download (attachment)
     */
    function mad_download_url(string $path, ?string $basename = null): string
    {
        $params = ['file' => $path];
        if ($basename !== null && $basename !== '') {
            $params['basename'] = $basename;
        }

        try {
            if (function_exists('app') && app()->bound('url')) {
                // URL ASSINADA temporária (~6h): torna o path infalsificável e
                // não-enumerável. A rota mad.download roda 'signed:relative', que
                // rejeita (403) qualquer URL sem assinatura válida — assim um
                // usuário só consegue baixar arquivos cujas URLs assinadas a
                // página que ele pode ver renderizou (fecha o IDOR cross-tenant).
                // Assinatura RELATIVA (4º arg false) = host-agnóstica (funciona
                // atrás de proxy / com APP_URL divergente do host servido).
                return \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'mad.download',
                    now()->addHours(6),
                    $params,
                    false
                );
            }
        } catch (\Throwable) {
            // sem container (render standalone) — cai no literal abaixo
        }

        return '/app/_mad-download?' . http_build_query($params);
    }
}

if (!function_exists('mad_upload_is_file')) {
    /**
     * O valor persistido de um campo de arquivo aponta para algo servível?
     *
     * Substitui os gates is_file() das blades (image/avatar/signature-field),
     * que quebrariam com uploads em disco remoto (S3). Disco local (default
     * storage/app/mad): exists() via Storage. Disco custom (S3 etc): retorna
     * true para qualquer chave relativa plausível SEM tocar a rede — um
     * exists() remoto por campo renderizado viraria N round-trips por página;
     * valor dangling só rende um link 404, mesma UX de um path órfão.
     */
    function mad_upload_is_file(string $path): bool
    {
        if ($path === '') {
            return false;
        }
        // URL/data-uri ou path absoluto não são chaves de upload.
        if (preg_match('#^(https?:|data:)#i', $path) || $path[0] === '/' || preg_match('#^[a-zA-Z]:[/\\\\]#', $path)) {
            return false;
        }

        try {
            if (\Mad\Service\MadUploadStorage::usesCustomDisk()) {
                return true; // sem HEAD remoto por render
            }

            return \Mad\Service\MadUploadStorage::exists($path);
        } catch (\Throwable) {
            return false; // disco misconfigurado não pode derrubar o render
        }
    }
}

if (!function_exists('mad_blob_url')) {
    /**
     * URL para baixar um BLOB base64 guardado no banco (rota mad.blob).
     * model+col são validados contra config('mad.blob_downloads') no controller.
     *
     * @param string          $model Nome curto (App\Models\*) ou FQCN do model
     * @param int|string      $id    PK da linha
     * @param string          $col   Coluna BLOB (base64)
     * @param string|null     $name  Nome de exibição do arquivo
     */
    function mad_blob_url(string $model, int|string $id, string $col, ?string $name = null): string
    {
        $params = ['model' => $model, 'id' => $id, 'col' => $col];
        if ($name !== null && $name !== '') {
            $params['name'] = $name;
        }

        try {
            if (function_exists('app') && app()->bound('url')) {
                return route('mad.blob', $params, false);
            }
        } catch (\Throwable) {
            // sem container (render standalone) — cai no literal abaixo
        }

        return '/app/_mad-blob?' . http_build_query($params);
    }
}
