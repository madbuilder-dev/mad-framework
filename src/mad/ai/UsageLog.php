<?php

namespace Mad\Ai;

use Laravel\Ai\Responses\Data\TextUsage;
use Illuminate\Support\Facades\DB;

/**
 * UsageLog — registro best-effort do consumo de tokens por turno em
 * mad_ai_token_usage. Versão-slice do TokenUsageRecorder do framework antigo:
 * grava o que o provider reportou + custo do OpenRouter (AiCallCost::take).
 * Quotas (Mad\Usage\QuotaService) ficam pro F3.2.
 *
 * Nunca lança — falha de medição não pode quebrar o chat.
 */
final class UsageLog
{
    /** Conexão do subsistema de usage — a MESMA da tela "Consumo de IA". */
    private const DB = 'log';

    /** @param array<string, mixed> $ctx userId/tokenId/tokenPrefix/systemSlug/unitId/context/provider/model/requestId/status/metadata */
    public static function record(?TextUsage $usage, array $ctx, string $db = self::DB): void
    {
        try {
            EmbedSchema::ensure(self::DB);
            $pdo = DB::connection(self::DB)->getPdo();

            // laravel/ai 1.0: `inputTokens` inclui o cache em todo provider (no
            // 0.8 o Anthropic direto descontava; OpenRouter e Coding Plan ja
            // incluiam). Os campos de cache sao subconjuntos dele.
            $prompt     = $usage?->inputTokens;
            $completion = $usage?->outputTokens;

            // Schema = o da tela "Consumo de IA" já portada (AiTokenUsage):
            // token_id / cache_read_tokens / cache_write_tokens / reasoning_tokens.
            $pdo->prepare(
                'INSERT INTO mad_ai_token_usage (id, user_id, token_id, token_prefix, app_slug,'
                . ' unit_id, context, provider, model, prompt_tokens, completion_tokens,'
                . ' cache_write_tokens, cache_read_tokens, reasoning_tokens, total_tokens,'
                . ' request_id, status, metadata_json, created_at, cost_usd)'
                . ' VALUES ((SELECT COALESCE(MAX(id),0)+1 FROM mad_ai_token_usage), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $ctx['userId']      ?? null,
                $ctx['tokenId']     ?? null,
                $ctx['tokenPrefix'] ?? null,
                $ctx['systemSlug']  ?? null,
                $ctx['unitId']      ?? null,
                (string) ($ctx['context'] ?? 'embed_chat'),
                (string) ($ctx['provider'] ?? ''),
                (string) ($ctx['model'] ?? ''),
                (int) ($prompt ?? 0),
                (int) ($completion ?? 0),
                (int) ($usage?->cacheWriteInputTokens ?? 0),
                (int) ($usage?->cacheReadInputTokens ?? 0),
                (int) ($usage?->reasoningTokens ?? 0),
                (int) (($prompt ?? 0) + ($completion ?? 0)),
                (string) ($ctx['requestId'] ?? ''),
                (string) ($ctx['status'] ?? ''),
                json_encode($ctx['metadata'] ?? [], JSON_UNESCAPED_UNICODE),
                date('Y-m-d H:i:s'),
                AiCallCost::take(),
            ]);
        } catch (\Throwable $e) {
            error_log('[mad-usage] record failed (best-effort): ' . $e->getMessage());
        }
    }
}
