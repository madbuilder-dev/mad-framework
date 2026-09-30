<?php

namespace Mad\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * MadBlobDownloadController — serve um BLOB base64 guardado no banco
 * (rota mad.blob: /app/_mad-blob?model=...&id=...&col=...&name=...).
 *
 * Usado pelos campos de arquivo storage="db" cujo conteúdo vive numa coluna
 * (ex.: tabela neto do field-list type="files"). Lê o base64, decodifica e
 * faz stream como attachment.
 *
 * Segurança:
 *  - rota protegida por web + mad.auth (só usuário logado);
 *  - model + coluna precisam estar na allowlist config('mad.blob_downloads')
 *    — nada de model/coluna arbitrários via querystring;
 *  - coluna casa /^[A-Za-z0-9_]+$/ (defesa extra contra injeção de identificador);
 *  - linha lida via Eloquent find() (parametrizado), nunca SQL cru;
 *  - sempre attachment + nome sanitizado (basename).
 */
class MadBlobDownloadController
{
    public function __invoke(Request $request): Response
    {
        $model = (string) $request->query('model', '');
        $col   = (string) $request->query('col', '');
        $id    = (string) $request->query('id', '');

        if ($model === '' || $col === '' || $id === '') {
            abort(400, 'Missing parameters.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $col)) {
            abort(400, 'Invalid column.');
        }

        // ── Allowlist (model curto → colunas permitidas + programa) ─────────
        $allow = (array) config('mad.blob_downloads', []);
        $short = ltrim(strrchr($model, '\\') ?: $model, '\\');
        $entry = $allow[$short] ?? $allow[$model] ?? null;
        if ($entry === null) {
            abort(403, 'Blob download not allowed.');
        }
        // Formato novo: ['cols' => [...], 'program' => 'XxxList']. Antigo: ['col1', ...].
        if (array_is_list((array) $entry)) {
            $allowedCols = (array) $entry;
            $program     = null;
        } else {
            $allowedCols = (array) ($entry['cols'] ?? []);
            $program     = isset($entry['program']) ? (string) $entry['program'] : null;
        }
        if (!in_array($col, $allowedCols, true)) {
            abort(403, 'Blob download not allowed.');
        }

        // ── Autorização por programa: usuário logado precisa ter acesso à tela
        //    dona do blob (mesma fonte do menu/rotas: session('programs')). Sem
        //    isto, qualquer logado baixaria o blob de qualquer tela. ──────────
        if ($program !== null && $program !== ''
            && !\Mad\Security\PermissionGate::canAccess($program)) {
            abort(403, 'Sem permissão para este recurso.');
        }

        // ── Resolve model + carrega linha ───────────────────────────────────
        try {
            $class = \Mad\Form\ModelOptionsLoader::resolveModelClass($short);
        } catch (\Throwable) {
            abort(404);
        }

        $row = $class::find($id);
        if (!$row) {
            abort(404);
        }

        $b64 = $row->getAttribute($col);
        if (!is_string($b64) || $b64 === '') {
            abort(404);
        }
        $binary = base64_decode($b64, true);
        if ($binary === false) {
            abort(404, 'Corrupt blob.');
        }

        // ── Nome de exibição + resposta ─────────────────────────────────────
        $name = (string) $request->query('name', '');
        $name = $name !== '' ? basename(str_replace('\\', '/', $name)) : ('arquivo_' . $id);

        $response = new Response($binary, 200, [
            'Content-Type'   => $this->guessMime($name),
            'Content-Length' => (string) strlen($binary),
        ]);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(
                'attachment',
                $name,
                preg_replace('/[^\x20-\x7e]/', '_', $name) ?: 'download'
            )
        );
        $response->setPrivate();

        return $response;
    }

    private function guessMime(string $name): string
    {
        return match (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'pdf'           => 'application/pdf',
            'png'           => 'image/png',
            'jpg', 'jpeg'   => 'image/jpeg',
            'gif'           => 'image/gif',
            'webp'          => 'image/webp',
            'txt'           => 'text/plain',
            'csv'           => 'text/csv',
            'json'          => 'application/json',
            default         => 'application/octet-stream',
        };
    }
}
