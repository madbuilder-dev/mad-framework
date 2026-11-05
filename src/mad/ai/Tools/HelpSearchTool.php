<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\SseSink;
use Mad\Help\ManualRepository;

/**
 * HelpSearchTool (`help_search`) — busca no manual do sistema (a Ajuda do app,
 * fórum #227) para o Copilot responder "como faço…?" com o texto que o
 * administrador publicou.
 *
 * Lê só o texto plano do `resources/manual/manual.json` (a mesma busca da tela
 * Ajuda, {@see ManualRepository::search()}): sem LLM, sem HTTP, sem banco. O
 * manual vale para todo usuário logado, então não há filtro por perfil.
 *
 * `{q, limit?}` → `{ok, results:[{path,title,snippet,url}]}`.
 */
final class HelpSearchTool implements Tool
{
    public const NAME = 'help_search';

    private const MAX_LIMIT = 10;

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
        return 'Search the system manual (the app\'s Help pages written by the administrator) for how to use the system: '
            . 'screens, steps, rules, who can do what. Returns matching pages {path, title, snippet, url}. '
            . 'Use it for "how do I…", "where is…", "what does this screen do" questions, then read the page with help_get.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'q'     => $schema->string()->description('Search terms (2+ characters), in the user\'s words.')->required(),
            'limit' => $schema->integer()->description('Max pages to return (1-' . self::MAX_LIMIT . ', default 5).'),
        ];
    }

    public function handle(Request $request): string
    {
        $all   = $request->all();
        $q     = mb_substr(trim((string) ($all['q'] ?? '')), 0, 120);
        $limit = max(1, min(self::MAX_LIMIT, (int) ($all['limit'] ?? 5) ?: 5));
        $t0    = microtime(true);
        $this->sink->toolUseStart(self::NAME, ['q' => $q]);

        $repo = $this->repo ?? new ManualRepository();
        $out  = ['ok' => true, 'results' => []];
        try {
            if (! $repo->exists()) {
                $out = ['ok' => false, 'error' => 'no_manual', 'message' => 'Este sistema ainda não tem manual publicado.'];
            } elseif (mb_strlen($q) < 2) {
                $out = ['ok' => false, 'error' => 'query_too_short', 'message' => 'Informe ao menos 2 caracteres.'];
            } else {
                foreach ($repo->search($q, $limit) as $hit) {
                    $out['results'][] = [
                        'path'    => $hit['path'],
                        'title'   => $hit['title'],
                        'snippet' => $hit['snippet'],
                        'url'     => ManualRepository::pageUrl($hit['path']),
                    ];
                }
            }
        } catch (\Throwable $e) {
            error_log('[help_search] ' . $e->getMessage());
            $out = ['ok' => false, 'error' => 'unavailable', 'message' => 'Não foi possível ler o manual agora.'];
        }

        $ms = (int) round((microtime(true) - $t0) * 1000);
        $this->sink->toolUseEnd(self::NAME, ['q' => $q], $ms, ['total' => count($out['results'] ?? [])], (bool) $out['ok']);

        return json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    }
}
