<?php

namespace Mad\Ai\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mad\Ai\DashboardStore;
use Mad\Ai\WidgetBlockBuilder;
use Mad\Ai\WidgetDisplay;
use Mad\Ai\WidgetStore;
use Mad\Mcp\McpCurrentUser;
use Mad\Mcp\McpManifestLoader;

/**
 * EmbedWidgetController — widgets de BI salvos (micro-BI do embed).
 *
 *   GET    /embed/v1/widgets              lista (metadados) do usuário
 *   GET    /embed/v1/widgets/{id}/render  executa o spec → Block fresco (0 IA)
 *   PATCH  /embed/v1/widgets/{id}         título e/ou display (tipo/formato)
 *   DELETE /embed/v1/widgets/{id}         exclui
 *
 * Ownership por McpCurrentUser::id(). O render também aceita widget de OUTRO
 * dono quando ele aparece num dashboard compartilhado com o viewer.
 */
final class EmbedWidgetController
{
    public function list(Request $request): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return response()->json(['widgets' => self::widgets()->listByUser((int) $userId)]);
    }

    public function render(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $store  = self::widgets();
        $widget = $store->get($id, (int) $userId);

        if ($widget === null) {
            // não é meu — visível só se estiver num dashboard compartilhado comigo
            $shared = self::dashboards()->widgetVisibleViaShare($id, (int) $userId, McpCurrentUser::groupIds());
            $widget = $shared ? $store->getAny($id) : null;
        }
        if ($widget === null) {
            return response()->json(['error' => 'not_found'], 404);
        }

        // Filtros do dashboard: ?params={"busca":"jo","status":"Ativo"} — só
        // nomes declarados no spec entram (builder ignora o resto).
        $filterValues = [];
        $rawParams    = $request->query('params');
        if (is_string($rawParams) && $rawParams !== '') {
            $decoded = json_decode($rawParams, true);
            if (is_array($decoded)) {
                foreach ($decoded as $k => $v) {
                    if (is_string($k) && is_scalar($v)) {
                        $filterValues[$k] = (string) $v;
                    }
                }
            }
        }

        // Filtros GLOBAIS do dashboard: além dos params declarados no widget,
        // aceita os nomes definidos pelo DONO nos filters do dashboard (?dash=)
        // — ACL via getVisible; viewer segue sem poder injetar nome arbitrário.
        $dashId = (string) $request->query('dash', '');
        if ($dashId !== '') {
            $dash = self::dashboards()->getVisible($dashId, (int) $userId, McpCurrentUser::groupIds());
            if ($dash !== null) {
                $declared = array_column(WidgetBlockBuilder::params($widget['spec']), 'name');
                foreach (($dash['filters'] ?? []) as $f) {
                    $name = (string) ($f['name'] ?? '');
                    if ($name !== '' && ! in_array($name, $declared, true)) {
                        $widget['spec']['params'][] = [
                            'name'    => $name,
                            'label'   => (string) ($f['label'] ?? $name),
                            'kind'    => (string) ($f['kind'] ?? 'search'),
                            'options' => (array) ($f['options'] ?? []),
                            'default' => (string) ($f['default'] ?? ''),
                        ];
                        $declared[] = $name;
                    }
                }
            }
        }

        $built = WidgetBlockBuilder::build($widget['spec'], self::db(), $filterValues);
        if (! $built['ok']) {
            return response()->json(['error' => 'render_failed', 'message' => $built['error'] ?? ''], 422);
        }

        // Override de exibição do dono (tipo/formato) — determinístico, 0 IA.
        $display = is_array($widget['spec']['display'] ?? null) ? $widget['spec']['display'] : null;

        return response()->json([
            'id'        => $widget['id'],
            'title'     => $widget['title'],
            'type'      => (string) ($widget['spec']['type'] ?? ''),
            'block'     => WidgetDisplay::apply($built['block'], $display),
            'params'    => WidgetBlockBuilder::params($widget['spec']),
            // Candidatos a filtro: do SPEC (args da tool / :placeholders) + dos
            // DADOS (coluna textual com 2-30 distintos vira select com as
            // opções reais — as fatias do donut). Dedup por name, spec vence;
            // params já declarados ficam fora.
            'suggestedParams' => self::mergeSuggestions(
                WidgetBlockBuilder::suggestedParams($widget['spec']),
                WidgetBlockBuilder::suggestedParamsFromRows($built['rows'] ?? []),
                WidgetBlockBuilder::params($widget['spec']),
            ),
            'display'   => $display,
            // style do spec (título interno/unidade/cores…) — insumo do modal
            // de propriedades; inofensivo pro viewer de share (só exibição).
            'style'     => is_array($widget['spec']['style'] ?? null) ? $widget['spec']['style'] : new \stdClass(),
            'updatedAt' => $widget['updatedAt'],
        ]);
    }

    /**
     * Merge das sugestões (spec + dados) com dedup por name; declarados fora.
     *
     * @param  list<array<string, mixed>> $fromSpec
     * @param  list<array<string, mixed>> $fromRows
     * @param  list<array<string, mixed>> $declared
     * @return list<array<string, mixed>>
     */
    private static function mergeSuggestions(array $fromSpec, array $fromRows, array $declared): array
    {
        $skip = array_column($declared, 'name');
        $out  = [];
        foreach (array_merge($fromSpec, $fromRows) as $s) {
            $name = (string) ($s['name'] ?? '');
            if ($name === '' || in_array($name, $skip, true) || isset($out[$name])) {
                continue;
            }
            $out[$name] = $s;
        }

        return array_slice(array_values($out), 0, 12);
    }

    /**
     * PATCH — título, display, style e/ou params. (Nome "rename" preservado:
     * as rotas dos apps gerados apontam pra ele; o corpo aceita os 4 campos.)
     *
     * Body: {
     *   title?:   string,
     *   display?: {type?, format?: {kind, decimals?}} | null,   // null limpa
     *   style?:   {unit?, colors?, horizontal?, centerValue?, centerLabel?, note?},
     *   params?:  [{name, label?, kind?, options?, default?}] | []   // [] remove todos
     * }
     * `style` faz MERGE por chave no spec.style ('' / null remove a chave;
     * title interno do gráfico fica intacto). `params` SUBSTITUI as defs de
     * filtro do widget (sanitizadas por WidgetBlockBuilder::params).
     */
    public function rename(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $title      = trim((string) $request->input('title', ''));
        $hasDisplay = $request->has('display');
        $hasStyle   = $request->has('style') && is_array($request->input('style'));
        $hasParams  = $request->has('params') && is_array($request->input('params'));
        if ($title === '' && ! $hasDisplay && ! $hasStyle && ! $hasParams) {
            return response()->json(['error' => 'missing_title'], 422);
        }

        $store = self::widgets();

        if ($title !== '' && ! $store->rename($id, (int) $userId, $title)) {
            return response()->json(['error' => 'not_found'], 404);
        }

        if ($hasDisplay) {
            $widget = $store->get($id, (int) $userId);
            if ($widget === null) {
                return response()->json(['error' => 'not_found'], 404);
            }
            $display = WidgetDisplay::sanitize(
                $request->input('display'),
                (string) ($widget['spec']['type'] ?? ''),
            );
            if (! $store->setDisplay($id, (int) $userId, $display)) {
                return response()->json(['error' => 'not_found'], 404);
            }
        }

        if ($hasStyle || $hasParams) {
            $widget = $store->get($id, (int) $userId);
            if ($widget === null) {
                return response()->json(['error' => 'not_found'], 404);
            }

            $fields = [];
            if ($hasStyle) {
                $current = is_array($widget['spec']['style'] ?? null) ? $widget['spec']['style'] : [];
                $fields['style'] = self::mergeStyle($current, (array) $request->input('style'));
            }
            if ($hasParams) {
                $fields['params'] = WidgetBlockBuilder::params(['params' => $request->input('params')]);
            }
            if (! $store->setSpecFields($id, (int) $userId, $fields)) {
                return response()->json(['error' => 'not_found'], 404);
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Merge sanitizado do patch de style sobre o style atual do spec — só
     * chaves de APARÊNCIA editáveis pelo modal; o resto (title interno, max,
     * zones…) fica como o agente gerou. '' ou null no patch REMOVE a chave.
     *
     * @param  array<string, mixed> $current
     * @param  array<string, mixed> $patch
     * @return array<string, mixed>
     */
    private static function mergeStyle(array $current, array $patch): array
    {
        foreach (['unit', 'centerValue', 'centerLabel', 'note'] as $key) {
            if (! array_key_exists($key, $patch)) {
                continue;
            }
            $v = $patch[$key];
            if ($v === null || (is_string($v) && trim($v) === '')) {
                unset($current[$key]);
            } elseif (is_scalar($v)) {
                $current[$key] = mb_substr(trim((string) $v), 0, 80);
            }
        }

        if (array_key_exists('horizontal', $patch)) {
            if ($patch['horizontal'] === true) {
                $current['horizontal'] = true;
            } else {
                unset($current['horizontal']);
            }
        }

        if (array_key_exists('colors', $patch)) {
            $colors = [];
            foreach ((array) $patch['colors'] as $c) {
                if (is_string($c) && preg_match('/^#[0-9a-fA-F]{3,8}$/', trim($c))) {
                    $colors[] = strtolower(trim($c));
                }
            }
            if ($colors === []) {
                unset($current['colors']);
            } else {
                $current['colors'] = array_slice($colors, 0, 12);
            }
        }

        return $current;
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $userId = McpCurrentUser::id();
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        return self::widgets()->delete($id, (int) $userId)
            ? response()->json(['ok' => true])
            : response()->json(['error' => 'not_found'], 404);
    }

    private static function db(): string
    {
        try {
            return McpManifestLoader::load()->database();
        } catch (\Throwable) {
            return (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
        }
    }

    private static function widgets(): WidgetStore
    {
        return new WidgetStore(self::db());
    }

    private static function dashboards(): DashboardStore
    {
        return new DashboardStore(self::db());
    }
}
