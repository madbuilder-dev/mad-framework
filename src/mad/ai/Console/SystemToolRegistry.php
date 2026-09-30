<?php

namespace Mad\Ai\Console;

use Mad\Ai\ConfirmCoordinator;
use Mad\Ai\SseSink;

/**
 * SystemToolRegistry — fonte única das system tools do Command Center (espelho do
 * RenderToolRegistry). Adicionar uma tool = uma linha em map(). Resolve handlers
 * preguiçosamente (1 instância por processo) e os expõe ao loop (systemTools)
 * e ao prompt (descriptors).
 */
final class SystemToolRegistry
{
    /** @var array<string, SystemToolHandler> */
    private array $handlers = [];

    /** @return array<string, class-string<SystemToolHandler>> */
    private static function map(): array
    {
        return [
            'update_source_code' => Handlers\SourceCodeTool::class,
            'update_menu'        => Handlers\MenuTool::class,
            'run_migration'      => Handlers\MigrationTool::class,
            'update_permissions' => Handlers\PermissionsTool::class,
            'backup_database'    => Handlers\DatabaseBackupTool::class,
            'backup_source_code' => Handlers\SourceBackupTool::class,
        ];
    }

    public function handler(string $name): ?SystemToolHandler
    {
        $map = self::map();
        if (! isset($map[$name])) {
            return null;
        }
        if (! isset($this->handlers[$name])) {
            $cls                    = $map[$name];
            $this->handlers[$name] = new $cls();
        }

        return $this->handlers[$name];
    }

    /** @return array<string, SystemToolHandler> */
    public function all(): array
    {
        $out = [];
        foreach (array_keys(self::map()) as $name) {
            $h = $this->handler($name);
            if ($h !== null) {
                $out[$name] = $h;
            }
        }

        return $out;
    }

    /**
     * Instâncias SystemTool ligadas ao sink/confirms/audit p/ o loop laravel/ai.
     *
     * @return list<SystemTool>
     */
    public function systemTools(
        SseSink $sink,
        ConfirmCoordinator $confirms,
        ?ToolAuditStore $audit,
        string $conversationId,
        int $userId,
        string $login,
    ): array {
        $out = [];
        foreach ($this->all() as $handler) {
            $out[] = new SystemTool($handler, $sink, $confirms, $audit, $conversationId, $userId, $login);
        }

        return $out;
    }

    /**
     * Descritores (nome → descrição + risco) p/ o system prompt.
     *
     * @return array<string, array{description:string, risk:array<string,mixed>}>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->all() as $name => $handler) {
            $out[$name] = ['description' => $handler->description(), 'risk' => $handler->risk()->toArray()];
        }

        return $out;
    }
}
