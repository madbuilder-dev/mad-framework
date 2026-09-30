<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\DashboardStore;
use Mad\Ai\SseSink;
use Mad\Ai\WidgetStore;
use Mad\Mcp\McpCurrentUser;

/**
 * SaveDashboardTool — o agente MONTA um dashboard persistente na tela
 * "Meus Dashboards" a partir de widgets SALVOS (save_widget), pelo usuário.
 *
 * Fecha o ciclo de alto nível: preview no chat (show_*) → aprovação →
 * save_widget (fonte SQL ou TOOL) → save_dashboard com os ids. O layout
 * inicial é uma grade 2 colunas (grid 12: w=6, h=6); a tela é um editor
 * visual (react-grid-layout) — o usuário arrasta/redimensiona/renomeia/
 * adiciona depois, sem IA. dashboardId re-monta um dashboard existente.
 */
final class SaveDashboardTool implements Tool
{
    private const MAX_WIDGETS = 12;

    public function __construct(
        private SseSink $sink,
        private WidgetStore $widgets,
        private DashboardStore $dashboards,
    ) {
    }

    public function name(): string
    {
        return 'save_dashboard';
    }

    public function description(): string
    {
        return 'Crie (ou re-monte, com dashboardId) um DASHBOARD PERSISTENTE na tela "Meus Dashboards" a partir de '
            . 'widgets já salvos com save_widget. Passe os widgetIds (wid-…) na ordem desejada — layout inicial em '
            . '2 colunas; o usuário ajusta visualmente depois (arrastar/redimensionar/adicionar). Use quando o '
            . 'usuário pedir para "salvar/montar um dashboard/painel" persistente — NÃO para o painel efêmero do '
            . 'chat (esse é show_dashboard).';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title'       => $schema->string()->description('Nome do dashboard.')->required(),
            'widgetIds'   => $schema->array()->description('2-12 ids de widgets salvos (wid-…), na ordem de exibição.')->required(),
            'dashboardId' => $schema->string()->description('Id de dashboard existente (dash-…) para SUBSTITUIR o layout em vez de criar.'),
        ];
    }

    public function handle(Request $request): string
    {
        $input = $request->all();
        $title = trim((string) ($input['title'] ?? ''));
        $ids   = $input['widgetIds'] ?? [];
        if (is_string($ids)) {
            $ids = json_decode($ids, true);
        }
        $ids = is_array($ids) ? array_values(array_filter(array_map('strval', $ids))) : [];
        $ids = array_slice(array_unique($ids), 0, self::MAX_WIDGETS);

        $params = ['title' => $title, 'widgets' => count($ids)];
        $this->sink->toolUseStart($this->name(), $params);
        $fail = function (string $msg) use ($params): string {
            $this->sink->toolUseEnd($this->name(), $params, 0, ['error' => $msg], false);

            return 'erro: ' . $msg;
        };

        $userId = (int) (McpCurrentUser::id() ?? 0);
        if ($userId <= 0) {
            return $fail('usuário não identificado.');
        }
        if ($title === '') {
            return $fail('title é obrigatório.');
        }
        if (count($ids) < 2) {
            return $fail('informe 2+ widgetIds salvos (use save_widget antes; list_widgets lista os existentes).');
        }

        // Todos os widgets têm que existir E ser do usuário (não vaza id alheio).
        $faltando = [];
        foreach ($ids as $wid) {
            if ($this->widgets->get($wid, $userId) === null) {
                $faltando[] = $wid;
            }
        }
        if ($faltando !== []) {
            return $fail('widgets inexistentes ou de outro usuário: ' . implode(', ', $faltando));
        }

        // Grade inicial 2 colunas (grid de 12): o editor visual assume depois.
        $layout = [];
        foreach ($ids as $i => $wid) {
            $layout[] = [
                'widgetId' => $wid,
                'x'        => ($i % 2) * 6,
                'y'        => intdiv($i, 2) * 6,
                'w'        => 6,
                'h'        => 6,
            ];
        }

        $dashId = trim((string) ($input['dashboardId'] ?? ''));
        if ($dashId !== '') {
            $ok = $this->dashboards->update($dashId, $userId, $title, $layout);
            if (! $ok) {
                return $fail("não foi possível atualizar o dashboard {$dashId} (existe? é seu?).");
            }
        } else {
            $dashId = $this->dashboards->create($userId, $title, $layout);
            if ($dashId === null) {
                return $fail('não foi possível criar o dashboard.');
            }
        }

        $this->sink->toolUseEnd($this->name(), $params, 0, ['id' => $dashId, 'widgets' => count($ids)]);
        $this->sink->block([
            'type'   => 'callout',
            'intent' => 'pos',
            'title'  => 'Dashboard salvo',
            'text'   => "\"{$title}\" com " . count($ids) . ' widgets. Abra a tela "Meus Dashboards" para ver, arrastar, redimensionar e compartilhar.',
        ]);

        return "dashboard salvo (id: {$dashId}, " . count($ids) . ' widgets). Diga ao usuário que ele já aparece na '
            . 'tela "Meus Dashboards", onde dá pra ajustar o layout visualmente.';
    }
}
