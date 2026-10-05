<?php
namespace Mad\Ui;

/**
 * MadErrorRenderer — Tela de erro rica para MadComponents (estilo Ignition).
 *
 * Exibe mensagem do erro, código fonte com highlight, stack trace colapsável.
 * CSS inline — não depende de mad-ui.css.
 */
class MadErrorRenderer
{
    /**
     * Renderiza uma tela de erro HTML completa para um componente MAD.
     */
    public static function render(\Throwable $e, string $componentClass = ''): string
    {
        $type    = (new \ReflectionClass($e))->getShortName();
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES);
        $file    = $e->getFile();
        $line    = $e->getLine();

        // Tenta encontrar o template Blade original no stack trace
        $bladeInfo = self::findBladeTemplate($e);
        $codeFile  = $bladeInfo['file'] ?? $file;
        $codeLine  = $bladeInfo['line'] ?? $line;

        $codeHtml  = self::renderCodePreview($codeFile, $codeLine);
        $traceHtml = self::renderStackTrace($e);

        $shortFile  = self::shortPath($codeFile);
        $component  = $componentClass ? htmlspecialchars($componentClass, ENT_QUOTES) : '';
        $compLabel  = $component ? "<span style=\"color:#a78bfa;\">{$component}</span> &mdash; " : '';

        // data-mad-error-page: o front (mad.js / mad-livewire.js) reconhece o
        // fragmento como ERRO mesmo com status 200 e o mostra num diálogo, em vez
        // de despejá-lo num contêiner invisível (ex.: #mad_partial do Mad.get).
        return <<<HTML
        <div data-mad-error-page="debug" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;max-width:960px;margin:24px auto;background:#1e1e2e;color:#cdd6f4;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.3);font-size:14px;line-height:1.6;">
            <!-- Header -->
            <div style="background:#f38ba8;color:#1e1e2e;padding:20px 24px;">
                <div style="font-size:12px;text-transform:uppercase;letter-spacing:1px;opacity:.7;margin-bottom:4px;">{$compLabel}{$type}</div>
                <div style="font-size:18px;font-weight:600;word-break:break-word;">{$message}</div>
                <div style="font-size:12px;margin-top:8px;opacity:.8;">{$shortFile}:{$codeLine}</div>
            </div>

            <!-- Code Preview -->
            <div style="padding:0;">
                <div style="padding:12px 24px 8px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6c7086;">Código fonte</div>
                {$codeHtml}
            </div>

            <!-- Stack Trace -->
            <details style="border-top:1px solid #313244;">
                <summary style="padding:12px 24px;cursor:pointer;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#6c7086;user-select:none;">Stack trace</summary>
                <div style="padding:0 24px 16px;overflow-x:auto;">
                    {$traceHtml}
                </div>
            </details>
        </div>
        HTML;
    }

    /**
     * Renderiza o código fonte com highlight da linha do erro.
     */
    private static function renderCodePreview(string $file, int $errorLine, int $context = 8): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '<div style="padding:12px 24px;color:#6c7086;">Arquivo não encontrado: ' . htmlspecialchars($file, ENT_QUOTES) . '</div>';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $start = max(0, $errorLine - $context - 1);
        $end   = min(count($lines), $errorLine + $context);

        $html = '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-family:\'SF Mono\',\'Fira Code\',\'JetBrains Mono\',Consolas,monospace;font-size:13px;line-height:1.5;">';

        for ($i = $start; $i < $end; $i++) {
            $num     = $i + 1;
            $code    = htmlspecialchars($lines[$i] ?? '', ENT_QUOTES);
            $isError = ($num === $errorLine);

            $bgColor   = $isError ? 'rgba(243,139,168,.15)' : 'transparent';
            $numColor  = $isError ? '#f38ba8' : '#6c7086';
            $codeColor = $isError ? '#f38ba8' : '#cdd6f4';
            $border    = $isError ? '3px solid #f38ba8' : '3px solid transparent';

            $html .= "<tr style=\"background:{$bgColor};\">"
                   . "<td style=\"padding:2px 12px 2px 21px;text-align:right;color:{$numColor};user-select:none;white-space:nowrap;border-left:{$border};\">{$num}</td>"
                   . "<td style=\"padding:2px 16px 2px 8px;color:{$codeColor};white-space:pre;\">{$code}</td>"
                   . '</tr>';
        }

        $html .= '</table></div>';
        return $html;
    }

    /**
     * Renderiza o stack trace como lista.
     */
    private static function renderStackTrace(\Throwable $e): string
    {
        $trace = $e->getTrace();
        $html  = '<table style="width:100%;border-collapse:collapse;font-size:12px;">';

        foreach ($trace as $i => $frame) {
            $file     = $frame['file'] ?? '';
            $line     = $frame['line'] ?? '';
            $class    = $frame['class'] ?? '';
            $type     = $frame['type'] ?? '';
            $function = $frame['function'] ?? '';

            $shortFile = self::shortPath($file);
            $call      = $class
                ? htmlspecialchars("{$class}{$type}{$function}()", ENT_QUOTES)
                : htmlspecialchars("{$function}()", ENT_QUOTES);

            $fileHtml  = $file
                ? '<span style="color:#89b4fa;">' . htmlspecialchars($shortFile, ENT_QUOTES) . '</span>'
                  . '<span style="color:#6c7086;">:' . $line . '</span>'
                : '<span style="color:#6c7086;">[internal]</span>';

            $html .= "<tr style=\"border-bottom:1px solid #313244;\">"
                   . "<td style=\"padding:6px 8px 6px 0;color:#6c7086;width:20px;text-align:right;\">{$i}</td>"
                   . "<td style=\"padding:6px 12px;\">{$fileHtml}</td>"
                   . "<td style=\"padding:6px 0;color:#a6adc8;font-family:monospace;\">{$call}</td>"
                   . '</tr>';
        }

        $html .= '</table>';
        return $html;
    }

    /**
     * Tenta encontrar o template Blade original a partir do stack trace.
     * Procura frames em tmp/blade-cache/ e mapeia para o .blade.php.
     *
     * @return array{file: string, line: int}|null
     */
    private static function findBladeTemplate(\Throwable $e): ?array
    {
        $file = $e->getFile();

        // Se o erro já é num .blade.php, retorna direto
        if (str_contains($file, '.blade.php')) {
            return ['file' => $file, 'line' => $e->getLine()];
        }

        // Se o erro é num arquivo de cache do BladeOne
        if (str_contains($file, 'blade-cache') || str_contains($file, '.bladec')) {
            $original = self::resolveBladeOriginal($file, $e->getLine());
            if ($original) return $original;
        }

        // Procura no stack trace
        foreach ($e->getTrace() as $frame) {
            $frameFile = $frame['file'] ?? '';
            if (str_contains($frameFile, '.blade.php')) {
                return ['file' => $frameFile, 'line' => $frame['line'] ?? 0];
            }
            if (str_contains($frameFile, 'blade-cache') || str_contains($frameFile, '.bladec')) {
                $original = self::resolveBladeOriginal($frameFile, $frame['line'] ?? 0);
                if ($original) return $original;
            }
        }

        return null;
    }

    /**
     * Tenta resolver o template original a partir do arquivo compilado.
     * BladeOne gera o nome do cache como md5 do path do template.
     */
    private static function resolveBladeOriginal(string $cacheFile, int $cacheLine): ?array
    {
        if (!is_file($cacheFile)) return null;

        // BladeOne pode incluir um comentário com o path original no topo
        $firstLines = file($cacheFile, FILE_IGNORE_NEW_LINES);
        if (!$firstLines) return null;

        // Tenta encontrar referência ao arquivo original nos comentários
        foreach (array_slice($firstLines, 0, 5) as $l) {
            if (preg_match('#/\*\*?\s*(.*?\.blade\.php)\s*\*/#', $l, $m)) {
                $original = $m[1];
                if (is_file($original)) {
                    return ['file' => $original, 'line' => $cacheLine];
                }
            }
        }

        // Fallback: retorna o arquivo compilado (melhor que nada)
        return ['file' => $cacheFile, 'line' => $cacheLine];
    }

    /**
     * Gera um markdown completo do erro para copiar e enviar a uma LLM.
     * Inclui: exceção, código fonte, stack trace, request completo, headers, curl, ambiente.
     */
    public static function toMarkdown(\Throwable $e, string $componentClass = ''): string
    {
        $shortFile = self::shortPath($e->getFile());
        $comp      = $componentClass ?: 'desconhecido';

        $md  = "## Erro no componente `{$comp}`\n\n";
        $md .= "**Tipo:** `" . get_class($e) . "`\n\n";
        $md .= "**Mensagem:**\n```\n{$e->getMessage()}\n```\n\n";
        $md .= "**Arquivo:** `{$shortFile}:{$e->getLine()}`\n\n";

        // ── Código fonte ──────────────────────────────────────────
        if (is_file($e->getFile())) {
            $lines = file($e->getFile(), FILE_IGNORE_NEW_LINES);
            $start = max(0, $e->getLine() - 6);
            $end   = min(count($lines), $e->getLine() + 4);
            $md .= "### Código fonte\n\n```php\n";
            for ($i = $start; $i < $end; $i++) {
                $num    = $i + 1;
                $marker = ($num === $e->getLine()) ? ' ➤ ' : '   ';
                $md .= sprintf("%s%4d | %s\n", $marker, $num, $lines[$i] ?? '');
            }
            $md .= "```\n\n";
        }

        // ── Stack trace ───────────────────────────────────────────
        $md .= "### Stack Trace\n\n";
        foreach (array_slice($e->getTrace(), 0, 20) as $f) {
            $tf = isset($f['file']) ? self::shortPath($f['file']) : '[internal]';
            $tl = $f['line'] ?? '';
            $tc = ($f['class'] ?? '') . ($f['type'] ?? '') . ($f['function'] ?? '') . '()';
            $md .= "- `{$tf}:{$tl}` — `{$tc}`\n";
        }
        $md .= "\n";

        // ── Request ───────────────────────────────────────────────
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
        $uri    = \Mad\Security\SecretMasker::maskUri((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $fullUrl = "{$scheme}://{$host}{$uri}";
        // Este markdown é copiado para a IA/chamados: nada de senha, token,
        // cookie ou Authorization (SecretMasker) — nem na query, nem no corpo.
        $get  = \Mad\Security\SecretMasker::maskArray($_GET ?? []);
        $post = \Mad\Security\SecretMasker::maskArray($_POST ?? []);

        $md .= "### Request\n\n";
        $md .= "```\n{$method} {$fullUrl}\n```\n\n";

        // Query string
        if (!empty($get)) {
            $md .= "**Query String:**\n\n";
            $md .= "| Param | Value |\n|-------|-------|\n";
            foreach ($get as $k => $v) {
                if (is_string($v)) {
                    $md .= "| `{$k}` | `{$v}` |\n";
                }
            }
            $md .= "\n";
        }

        // Body (POST)
        if (!empty($post)) {
            $md .= "**Body (POST):**\n\n```json\n";
            $postClean = [];
            foreach ($post as $k => $v) {
                if (is_string($v) && strlen($v) < 500) {
                    $postClean[$k] = $v;
                } elseif (is_array($v)) {
                    $postClean[$k] = '[array]';
                }
            }
            $md .= json_encode($postClean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n```\n\n";
        }

        // Headers
        $md .= "**Headers:**\n\n";
        $md .= "| Header | Value |\n|--------|-------|\n";
        $skipHeaders = ['cookie']; // cookie é muito longo, poluiria
        $headers = \Mad\Security\SecretMasker::maskHeaders(self::getRequestHeaders());
        foreach ($headers as $name => $value) {
            if (in_array(strtolower($name), $skipHeaders)) continue;
            $md .= "| `{$name}` | `{$value}` |\n";
        }
        $md .= "\n";

        // cURL
        $md .= "**cURL:**\n\n```bash\ncurl \"{$fullUrl}\"";
        if ($method !== 'GET') {
            $md .= " \\\n  -X {$method}";
        }
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'cookie') continue;
            $md .= " \\\n  -H '{$name}: {$value}'";
        }
        if (!empty($post)) {
            foreach ($post as $k => $v) {
                if (is_string($v) && strlen($v) < 500) {
                    $md .= " \\\n  -F '{$k}={$v}'";
                }
            }
        }
        $md .= "\n```\n\n";

        // ── Ambiente ──────────────────────────────────────────────
        $md .= "### Ambiente\n\n";
        $md .= "| | |\n|---|---|\n";
        $md .= "| PHP | `" . PHP_VERSION . "` |\n";
        $md .= "| Componente | `{$comp}` |\n";
        $md .= "| Servidor | `{$host}` |\n";
        $md .= "| OS | `" . PHP_OS . "` |\n";
        $md .= "| Data | `" . date('Y-m-d H:i:s') . "` |\n";
        $md .= "| User-Agent | `" . ($_SERVER['HTTP_USER_AGENT'] ?? '') . "` |\n";
        $md .= "\n---\nAnalise o erro acima e sugira a correção.\n";

        return $md;
    }

    /**
     * Retorna os headers HTTP da request atual.
     */
    private static function getRequestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }
        // Fallback para quando getallheaders() não existe (CGI/FastCGI)
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', substr($key, 5));
                $name = ucwords(strtolower($name), '-');
                $headers[$name] = $value;
            }
        }
        return $headers;
    }

    /**
     * Encurta um path absoluto para exibição.
     */
    private static function shortPath(string $path): string
    {
        // Remove o prefixo do projeto
        $root = (defined('PATH') ? PATH : dirname(__DIR__, 3)) . '/';
        if (str_starts_with($path, $root)) {
            return substr($path, strlen($root));
        }
        return $path;
    }
}