<?php

namespace Mad\Service;

/**
 * No-op — o MadLogService legado alimentava o console de depuração no rodapé
 * das telas, que foi DESCONTINUADO na v5 (não volta; o backlog F1-08 que
 * previa portá-lo foi encerrado na 5.119.3, fórum #8). O diagnóstico de hoje é
 * o painel de SQL dos dashboards (Mad\Support\MadDebug::sqlPanel), o menu Logs
 * do app e o MadTrace.
 *
 * A classe continua porque ainda é chamada:
 *   - MadComponentHandler::handle() e MadResponse::send() → getDebugPayload()
 *   - util/GlobalFunctions.php (md/mdd)                   → addDebugData(), finalizeDebugLogging()
 *
 * ⚠️ NÃO remova o __callStatic: o ShellViewModel dos apps gerados antes da
 * 5.119.3 (app/lib/builder, fora do pacote — não se atualiza junto com ele)
 * chama `MadLogService::isDebugConsoleEnabled()` em toda tela. Sem o
 * catch-all, atualizar só o pacote derrubaria o layout desses apps com
 * "Call to undefined method".
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

    /** Qualquer outro método do serviço legado vira no-op (compat — ver o docblock da classe). */
    public static function __callStatic(string $name, array $args)
    {
        return null;
    }
}
