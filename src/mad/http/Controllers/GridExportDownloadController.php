<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\Request;
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
 * Segurança: o param `file` precisa ser um basename simples (o uniqid+ext que
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

        // Sumiu? Quase sempre foi o purge (mad:grid:purge-exports). 410 Gone.
        $key = 'output/' . $file;
        if (!MadScratchStorage::exists($key)) {
            abort(Response::HTTP_GONE, __('grid.export_expired'));
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
