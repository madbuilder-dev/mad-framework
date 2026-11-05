<?php

namespace Mad\Help;

use Illuminate\Http\Request;
use Mad\Security\PermissionGate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Imagem do manual: `GET /app/ajuda/prints/{arquivo}`.
 *
 * Só para quem está logado (mesma regra da tela da Ajuda, `HelpCenter` em
 * PermissionGate::defaultPermissions) — os prints podem mostrar dados do
 * sistema. Só serve `resources/manual/prints/*.webp`: nome fora do padrão,
 * `..`, link simbólico ou arquivo inexistente → 404. O tipo vai fixo em
 * `image/webp` com `nosniff` (o navegador não reinterpreta o conteúdo).
 */
final class ManualPrintController
{
    public function __invoke(Request $request, string $file): Response
    {
        if (!session('logged')) {
            return redirect(\Mad\Routing\MadRoutes::absoluteUrl(\Mad\Routing\MadRoutes::loginUrl()));
        }
        if (!PermissionGate::canAccess('HelpCenter')) {
            abort(403);
        }

        $path = (new ManualRepository())->printFile($file);
        if ($path === null) {
            abort(404);
        }

        $response = new BinaryFileResponse($path, 200, [
            'Content-Type'           => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, max-age=3600',
            'Content-Security-Policy' => "default-src 'none'",
        ], false, null, true, true);
        $response->setContentDisposition('inline', $file);

        return $response;
    }
}
