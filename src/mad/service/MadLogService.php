<?php

namespace Mad\Service;

/**
 * STUB DO SPIKE — o MadLogService real (debug console SQL) é legado-pesado:
 * usa a sessão legada, o log global de SQL legado, o dispatcher legado e a
 * emissão de script legada.
 *
 * BACKLOG F1-08: portar debug console para Laravel (DB::listen + session()),
 * ou extrair para pacote opcional. Chamado por:
 *   - MadComponentHandler::handle():57  → getDebugPayload()
 *   - MadResponse:919                   → getDebugPayload()
 *   - util/GlobalFunctions.php:84,105   → addDebugData(), finalizeDebugLogging()
 */
class MadLogService
{
    public static function getDebugPayload(): array
    {
        return [];
    }

    public static function addDebugData(array $data): void
    {
    }

    public static function finalizeDebugLogging(): void
    {
    }

    /** Qualquer outro método do serviço real vira no-op no spike. */
    public static function __callStatic(string $name, array $args)
    {
        return null;
    }
}
