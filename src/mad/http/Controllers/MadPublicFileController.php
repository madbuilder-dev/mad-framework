<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\Request;
use Mad\Service\MadPublicUpload;
use Mad\Service\MadUploadStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * MadPublicFileController — `GET /arquivos/{path}`, sem login (mad-framework#244).
 *
 * Serve SÓ o arquivo gravado por um campo de upload com `public`: caminho sob
 * `_public/` com HMAC válido ({@see MadPublicUpload::isPublicPath()}), e só
 * imagem (png/jpg/gif/webp), PDF ou JSON — pela extensão E pelo conteúdo —, inline,
 * com o MIME do CONTEÚDO (nunca o do nome nem o do disco remoto). Qualquer outra coisa — arquivo privado, HMAC de outro
 * caminho, extensão ativa, arquivo apagado — responde 404, sem dizer por quê.
 *
 * Fora do grupo `web`: sem sessão nem cookie, a resposta pode ficar em cache
 * de navegador/CDN por um ano (o nome muda a cada envio). Todo IO via
 * MadUploadStorage (disco local ou S3).
 */
class MadPublicFileController
{
    public function __invoke(Request $request, string $path): Response
    {
        $path = (string) $path;
        if (MadPublicUpload::mimeFor($path) === null || !MadPublicUpload::isPublicPath($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        // O tipo servido vem do CONTEÚDO (e não do nome): arquivo que não é
        // de verdade imagem/PDF/JSON — ou que não existe — é 404.
        $mime = MadPublicUpload::servedMime($path, MadUploadStorage::head($path, MadPublicUpload::SNIFF_BYTES));
        if ($mime === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $headers = [
            'Content-Type'           => $mime,
            'X-Content-Type-Options' => 'nosniff',
        ];
        // JSON público (fórum #274) é só dado: nem aberto direto no navegador
        // ele roda script, carrega recurso ou vira página (CSP sem nada).
        if ($mime === MadPublicUpload::MIME['json']) {
            $headers['Content-Security-Policy'] = "default-src 'none'; sandbox";
        }

        $response = MadUploadStorage::response($path, basename($path), $headers, 'inline');
        $response->setPublic();
        $response->setMaxAge(31536000);
        $response->setImmutable();

        return $response;
    }
}
