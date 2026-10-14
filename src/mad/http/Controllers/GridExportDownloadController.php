<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\Request;
use Mad\Grid\GridExportAccess;
use Mad\Service\MadScratchStorage;
use Symfony\Component\HttpFoundation\Response;

/**
 * GridExportDownloadController — serve os arquivos de exportação da grade
 * (CSV/XLSX/PDF) gerados por MadGridExporter, sob a rota mad.grid.export.
 *
 * Por quê uma rota dedicada (e não href direto pro arquivo):
 *  - AUTH: exports podem conter dados sensíveis; servidos só por usuário logado
 *    (web + mad.auth). O link nunca expõe o arquivo publicamente.
 *  - EXPIRAÇÃO: o mad:grid:purge-exports apaga exports antigos. Se o usuário
 *    clicar "Baixar" depois do purge (ex.: dialog aberto > 24h), devolvemos
 *    410 Gone com mensagem clara — em vez de um 404 cru ou download corrompido.
 *
 *  - DONO (mad-framework#342): o link é assinado e curto, e o arquivo só
 *    serve a quem o gerou, na mesma unidade, enquanto ainda abrir a tela de
 *    origem — ver GridExportAccess. Divergência = 404 (não revela o arquivo).
 *
 * Segurança: o param `file` precisa ser um basename simples (o nome+ext que
 * o exporter gerou) — sem path, sem traversal, sem null byte; extensão na
 * allowlist. Todo IO via Storage (disco de scratch MadScratchStorage, chave
 * output/<basename>) — sempre attachment + Content-Type canônico da extensão.
 */
class GridExportDownloadController
{
    private const ALLOWED = [
        'csv'  => 'text/csv',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf'  => 'application/pdf',
    ];

    public function __invoke(Request $request): Response
    {
        $file = (string) $request->query('file', '');

        // Só basename simples — qualquer path/traversal/null é recusado.
        if ($file === '' || str_contains($file, "\0") || $file !== basename($file)) {
            abort(400, 'Invalid file parameter.');
        }

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!array_key_exists($ext, self::ALLOWED)) {
            abort(403, 'File type not allowed.');
        }

        // Link sem assinatura válida = 404; vencido = 410 (mesmo aviso do purge).
        if (!GridExportAccess::signatureValid($request)) {
            abort(Response::HTTP_NOT_FOUND);
        }
        if (!GridExportAccess::signatureFresh($request)) {
            abort(Response::HTTP_GONE, __('grid.export_expired'));
        }

        // Sumiu? Quase sempre foi o purge (mad:grid:purge-exports). 410 Gone.
        // Antes do dono: o purge leva o metadado junto.
        $key = 'output/' . $file;
        if (!MadScratchStorage::exists($key)) {
            abort(Response::HTTP_GONE, __('grid.export_expired'));
        }

        // Outro usuário/unidade, ou tela que ele não abre mais: 404.
        if (!GridExportAccess::ownedByCurrentUser($file)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $basename = (string) $request->query('basename', '');
        $basename = $basename !== '' ? basename(str_replace('\\', '/', $basename)) : $file;

        $response = MadScratchStorage::disk()->response($key, $basename, [
            'Content-Type'           => self::ALLOWED[$ext],
            'X-Content-Type-Options' => 'nosniff',
        ], 'attachment');
        $response->setPrivate();

        return $response;
    }
}
