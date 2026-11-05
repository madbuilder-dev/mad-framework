<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\SseSink;
use Mad\Help\ManualRepository;
use Mad\Site\MadDocMarkdown;

/**
 * HelpGetTool (`help_get`) — devolve uma página do manual do sistema (a Ajuda
 * do app, fórum #227) para o Copilot ler e responder com ela.
 *
 * Lê o `.md` publicado em `resources/manual/<path>.md` (path validado pela
 * allowlist do {@see ManualRepository}; só página que está no índice): sem LLM,
 * sem HTTP, sem banco. O front-matter sai; o Markdown vai como está.
 *
 * `{path}` → `{ok, title, markdown, screens, url}` | `{ok:false, error:"not_found"}`.
 */
final class HelpGetTool implements Tool
{
    public const NAME = 'help_get';

    /** Teto do Markdown devolvido ao modelo (página enorme não estoura o contexto). */
    private const MAX_CHARS = 40000;

    public function __construct(
        private SseSink $sink,
        private ?ManualRepository $repo = null,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function description(): string
    {
        return 'Read one page of the system manual by its path (from help_search). Returns {title, markdown, screens, url}. '
            . 'Answer the user with what the page says and give the url so they can open it. '
            . 'Image references (prints/…) are screenshots the user sees on the page; you cannot see them.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'path' => $schema->string()->description('Page path exactly as help_search returned it (e.g. "cadastros/clientes").')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $path = trim(mb_substr((string) ($request->all()['path'] ?? ''), 0, 200), " /\t\n");
        // Aceita a URL inteira que o help_search devolveu (…/ajuda/<path>).
        $base = ManualRepository::baseUrl() . '/';
        if ($base !== '/' && str_starts_with($path, ltrim($base, '/'))) {
            $path = substr($path, strlen(ltrim($base, '/')));
        }
        $t0 = microtime(true);
        $this->sink->toolUseStart(self::NAME, ['path' => $path]);

        $repo = $this->repo ?? new ManualRepository();
        $out  = ['ok' => false, 'error' => 'not_found', 'message' => 'Página não encontrada no manual. Use help_search.'];
        try {
            $page = $repo->find($path);
            $file = $page !== null ? $repo->markdownFile($page['path']) : null;
            if ($page !== null && $file !== null && is_file($file)) {
                [, $body] = MadDocMarkdown::splitFrontMatter((string) file_get_contents($file, false, null, 0, self::MAX_CHARS * 4));
                $body = trim($body);
                if (mb_strlen($body) > self::MAX_CHARS) {
                    $body = mb_substr($body, 0, self::MAX_CHARS) . "\n\n[…]";
                }
                $out = [
                    'ok'       => true,
                    'path'     => $page['path'],
                    'title'    => $page['title'],
                    'section'  => $page['section'],
                    'markdown' => $body,
                    'screens'  => array_values($page['screens']),
                    'url'      => ManualRepository::pageUrl($page['path']),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[help_get] ' . $e->getMessage());
            $out = ['ok' => false, 'error' => 'unavailable', 'message' => 'Não foi possível ler o manual agora.'];
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->sink->toolUseEnd(self::NAME, ['path' => $path], $ms, ['found' => $out['ok']], (bool) $out['ok']);

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    }
}
