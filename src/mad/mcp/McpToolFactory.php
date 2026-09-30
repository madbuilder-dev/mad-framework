<?php

namespace Mad\Mcp;

/**
 * McpToolFactory
 *
 * Constroi as instancias de tool a partir do manifest. Um `kind` por classe:
 *   crud -> McpCrudTool | query -> McpQueryTool | custom -> McpCustomTool.
 */
final class McpToolFactory
{
    /** @return array<int,McpManifestTool> */
    public static function build(McpManifest $manifest): array
    {
        $tools = [];
        foreach ($manifest->tools() as $spec) {
            $kind = (string) ($spec['kind'] ?? 'crud');
            $tool = match ($kind) {
                'query'  => new McpQueryTool($manifest, $spec),
                'custom' => new McpCustomTool($manifest, $spec),
                default  => new McpCrudTool($manifest, $spec),
            };
            $tools[] = $tool;
        }

        return $tools;
    }
}
