<?php

namespace Mad\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Mad\Ai\WidgetStore;
use Mad\Mcp\McpCurrentUser;

/**
 * ListWidgetsTool — lista os widgets salvos do usuário (id, título, tipo),
 * para o modelo referenciar/atualizar via save_widget{widgetId}.
 */
final class ListWidgetsTool implements Tool
{
    public function __construct(private WidgetStore $store)
    {
    }

    public function name(): string
    {
        return 'list_widgets';
    }

    public function description(): string
    {
        return 'List the user\'s saved BI widgets (id, title, type). Use the id with save_widget.widgetId to update one.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): string
    {
        $userId = (int) (McpCurrentUser::id() ?? 0);
        if ($userId <= 0) {
            return 'erro: usuário não identificado.';
        }

        $rows = $this->store->listByUser($userId);
        if ($rows === []) {
            return 'nenhum widget salvo ainda.';
        }

        return json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}
