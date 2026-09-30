<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\Request;
use Mad\Service\MadUploadStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * MadDownloadController — serve arquivos de upload (rota mad.download).
 *
 * Substitui o download.php do framework legado. Os campos de arquivo (file-field,
 * image-field, avatar-field, multi-file-field, signature-field) persistem
 * paths RELATIVOS à raiz do projeto (ex: "uploads/abc123_foto.jpg" — ver
 * MadForm::_normalizeUploadFolder) e geram URLs via mad_download_url().
 *
 * Segurança:
 *  - rota protegida por web + mad.auth (só usuário logado) + signed:relative
 *    (URL infalsificável, ~6h — o usuário só possui URLs que uma página que
 *    ele pode ver renderizou);
 *  - path precisa ser relativo, sem null byte, sem "..", sem backslash;
 *  - prefixos protegidos config('mad.download_protected_dirs',
 *    ['files', 'app-uuid']) NUNCA são servidos — GED tem ACL própria e
 *    endpoint dedicado. Fora isso qualquer pasta do disco de uploads serve:
 *    o `folder=` dos campos é livre (MadUploadPath) e o IO é rooted no disco
 *    privado, então allowlist de primeiro segmento só quebrava preview de
 *    pasta custom (pessoas/avatares → 403) sem ganho real;
 *  - blocklist de extensões executáveis/sensíveis (espelha
 *    MadForm::_sanitizeUploadName — defesa em profundidade caso algo entre
 *    no diretório por fora do upload sanitizado).
 *
 * Todo IO passa pela API de Filesystem do Laravel via MadUploadStorage
 * (default 'mad_uploads' em storage/app/mad, ou MAD_UPLOAD_DISK=s3 etc) —
 * sempre streamado pelo app (StreamedResponse), nunca URL direta de bucket.
 */
class MadDownloadController
{
    /** Extensões nunca servidas, mesmo dentro da allowlist. */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'cgi', 'pl', 'sh', 'htaccess', 'htpasswd', 'env', 'ini',
        // Conteúdo ATIVO (renderiza/executa same-origin → stored XSS): nunca servir.
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'mathml', 'vtt',
    ];

    /**
     * Únicas extensões servidas INLINE (preview de <img>/<embed>), com o MIME
     * explícito usado no serve a partir do disco de uploads (nunca confiar em
     * metadata remota). Qualquer outra é forçada a attachment — nunca
     * renderizar tipo ativo same-origin.
     */
    private const SAFE_INLINE_MIME = [
        'pdf'  => 'application/pdf',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif'  => 'image/gif',
        'webp' => 'image/webp',
    ];

    public function __invoke(Request $request): Response
    {
        $file = (string) $request->query('file', '');

        $relative = $this->validatedRelativePath($file);

        // Nome de exibição: param basename (sanitizado) ou o nome real.
        // Calculado do path RELATIVO — idêntico ao basename do absoluto legado.
        $basename = (string) $request->query('basename', '');
        $basename = $basename !== '' ? basename(str_replace('\\', '/', $basename)) : basename($relative);

        // dl=1 (ou basename explícito) força attachment; extensões fora do
        // allowlist inline também são forçadas a attachment (nunca renderizar
        // tipo ativo same-origin). Só png/jpg/gif/webp/pdf servem inline.
        $extension  = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
        $safeInline = array_key_exists($extension, self::SAFE_INLINE_MIME);
        $asAttachment = $request->boolean('dl')
            || $request->query('basename') !== null
            || !$safeInline;

        // nosniff sempre: impede o browser de "adivinhar" e renderizar como
        // outro tipo (defesa contra content-type sniffing / MIME confusion).
        // Content-Type SÓ da extensão (nunca metadata do storage).
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Type'           => $safeInline ? self::SAFE_INLINE_MIME[$extension] : 'application/octet-stream',
        ];
        $disposition = $asAttachment ? 'attachment' : 'inline';

        // Disco de uploads (default storage/app/mad ou MAD_UPLOAD_DISK=s3),
        // streamando pelo app — modelo signed-URL + mad.auth + bucket privado.
        if (!MadUploadStorage::exists($relative)) {
            abort(404);
        }

        $streamed = MadUploadStorage::response($relative, $basename, $headers, $disposition);
        $streamed->setPrivate();

        return $streamed;
    }

    /** Valida a forma do path relativo; aborta 400/403 se inválido. */
    private function validatedRelativePath(string $file): string
    {
        if ($file === '' || str_contains($file, "\0")) {
            abort(400, 'Invalid file parameter.');
        }

        $file = str_replace('\\', '/', $file);

        // Absoluto (Unix ou drive Windows) ou traversal → fora.
        if ($file[0] === '/' || preg_match('#^[a-zA-Z]:/#', $file) || str_contains($file, '..')) {
            abort(403, 'Path not allowed.');
        }

        $file = ltrim($file, './');

        // Nenhum segmento oculto (.git, .env, .anything).
        foreach (explode('/', $file) as $segment) {
            if ($segment === '' || $segment[0] === '.') {
                abort(403, 'Path not allowed.');
            }
        }

        // Prefixos NUNCA servidos pela rota genérica, mesmo com assinatura
        // válida: GED tem ACL por-documento + endpoint dedicado (ged.download);
        // app-uuid é metadado de instalação. Deny absoluto.
        //
        // Isto SUBSTITUI a allowlist mad.download_dirs (['uploads']): o
        // `folder=` dos campos aceita qualquer pasta segura do disco de uploads
        // (MadUploadPath), então um avatar em pessoas/avatares/ salvava e o
        // preview voltava 403 calado. Todo IO já é rooted no disco privado
        // (MadUploadStorage), traversal/dot-segment morrem acima e a URL é
        // assinada — servir o resto do disco tem a mesma confiança do uploads/.
        $protected = (array) config('mad.download_protected_dirs', ['files', 'app-uuid']);
        foreach ($protected as $dir) {
            $dir = trim(str_replace('\\', '/', (string) $dir), '/');
            if ($dir !== '' && ($file === $dir || str_starts_with($file, $dir . '/'))) {
                abort(403, 'Directory not allowed.');
            }
        }

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (in_array($extension, self::FORBIDDEN_EXTENSIONS, true)) {
            abort(403, 'File type not allowed.');
        }

        return $file;
    }

}
