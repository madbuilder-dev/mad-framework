<?php

namespace Mad\Http;

/**
 * HtmlErrorRenderer
 *
 * Politica de erro HTML dos entries de pagina (Public/Web).
 *
 * Paridade com os entries originais:
 *   - Public: leakDetails=true, bladeErrorPrefix='public.errors', homeUrl='/public/'
 *             → 404/405 via template Blade (com fallback inline), 500 com message + file:line
 *   - Web:    leakDetails=false, logPrefix='[MadWebServer]'
 *             → 404/405 inline generico, 500 generico + error_log
 */
class HtmlErrorRenderer implements ErrorRenderer
{
    private const TITLES = [
        404 => 'Página não encontrada',
        405 => 'Método não permitido',
    ];

    public function __construct(
        private bool $leakDetails = false,
        private ?string $logPrefix = null,
        private ?string $bladeErrorPrefix = null,
        private ?string $homeUrl = null
    ) {
    }

    public function render(int $code, \Throwable $e): \Illuminate\Http\Response
    {
        $title   = self::TITLES[$code] ?? 'Erro';
        $message = $this->leakDetails ? $e->getMessage() : '';

        // Template Blade customizado (paridade com MadPublicServer)
        if ($this->bladeErrorPrefix !== null) {
            $root = defined('PATH') ? PATH : dirname(__DIR__, 3);
            $dir  = str_replace('.', '/', $this->bladeErrorPrefix);

            if (file_exists("{$root}/app/resources/views/{$dir}/{$code}.blade.php")) {
                try {
                    $html = \Mad\View\MadBlade::render("{$this->bladeErrorPrefix}.{$code}", [
                        'title'   => $title,
                        'message' => $message,
                    ]);

                    return $this->htmlResponse($html, $code);
                } catch (\Throwable $renderError) {
                    // cai pro fallback inline
                }
            }
        }

        return $this->htmlResponse($this->fallbackPage($code, $title, $message), $code);
    }

    public function renderFatal(\Throwable $e): string
    {
        if ($this->logPrefix !== null) {
            \error_log($this->logPrefix . ' ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        }

        http_response_code(500);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        if (!$this->leakDetails) {
            return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>500</title></head>'
                . '<body style="font-family:system-ui;max-width:640px;margin:80px auto;">'
                . '<h1 style="color:#ef4444">500</h1><h2>Erro interno</h2></body></html>';
        }

        // Paridade com MadPublicServer::render500 — message + file:line
        $msg  = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>500 - Erro interno</title></head>
<body style="font-family:system-ui;max-width:800px;margin:60px auto;padding:0 20px;">
    <h1 style="color:#ef4444;">500 - Erro interno</h1>
    <pre style="background:#f4f4f5;padding:16px;border-radius:6px;overflow-x:auto;">{$msg}</pre>
    <p style="color:#666;font-size:13px;">{$file}</p>
</body></html>
HTML;
    }

    private function htmlResponse(string $html, int $code): \Illuminate\Http\Response
    {
        return new \Illuminate\Http\Response($html, $code, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    private function fallbackPage(int $code, string $title, string $message): string
    {
        $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $homeLink = $this->homeUrl !== null
            ? '<p><a href="' . htmlspecialchars($this->homeUrl, ENT_QUOTES, 'UTF-8') . '">← Voltar ao início</a></p>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{$code} - {$t}</title>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; max-width:640px; margin:80px auto; padding:0 20px; color:#333; }
        h1 { font-size:72px; margin:0; color:#ef4444; }
        h2 { font-size:24px; margin:10px 0; }
        p { color:#666; }
        a { color:#3b82f6; text-decoration:none; }
    </style>
</head>
<body>
    <h1>{$code}</h1>
    <h2>{$t}</h2>
    <p>{$m}</p>
    {$homeLink}
</body>
</html>
HTML;
    }
}
