<?php

namespace Mad\Usage;

use Illuminate\Support\Facades\DB;

/**
 * QuotaService — resolve e enforce limites de consumo de token.
 *
 * MODELO: o VALOR de uma regra é um teto POR CHAMADOR (por usuário; por token
 * nas regras de escopo 'token'). O escopo determina QUAIS chamadores a regra
 * atinge e sua PRECEDÊNCIA:
 *   token > user > group > unit > global
 * Em cada PERÍODO (day/month/total) vale a regra ativa mais específica;
 * contexto-específico (ex.: 'embed_chat') vence contexto-nulo no mesmo escopo.
 * Bloqueia se QUALQUER regra `block` de QUALQUER período já estourou.
 *
 * SEMÂNTICA PRE-CALL: o custo da chamada atual é desconhecido → bloqueia
 * somente quando o consumo JÁ está no/acima do teto (used >= max). A chamada
 * que cruza a linha roda e é gravada; a próxima é barrada.
 *
 * Tabela de regras vazia (ou sem regra que case) = ilimitado (degrada seguro).
 * Erros de infra SOBEM — o chamador decide o fail-open (o EmbedChatController
 * só bloqueia com QuotaExceededException).
 *
 * Port do antigo: regras via Eloquent (AiTokenQuota, conexão permission —
 * a MESMA tabela da tela /app/ia/cotas); somas em mad_ai_token_usage (conexão
 * log, a MESMA da tela /app/ia/consumo).
 */
final class QuotaService
{
    /** Precedência de escopo (maior vence). */
    private const RANK = ['global' => 0, 'unit' => 1, 'group' => 2, 'user' => 3, 'token' => 4];

    private const PERIODS = ['day', 'month', 'total'];

    /**
     * Regra efetiva por período, considerando precedência de escopo e contexto.
     *
     * @param int[] $groupIds
     * @return array<string, array{max:int,action:string,resetDay:?int,scope:string}|null>
     */
    public function resolveEffectiveRules(?int $userId, array $groupIds, ?int $unitId, ?int $tokenId, ?string $context): array
    {
        $rules = \App\Models\Ai\TokenQuota::query()->get();

        $groupIds = array_map('intval', $groupIds);

        $best     = ['day' => null, 'month' => null, 'total' => null];
        $bestRank = ['day' => -1, 'month' => -1, 'total' => -1];
        $bestCtx  = ['day' => -1, 'month' => -1, 'total' => -1]; // contexto-específico (1) vence nulo (0)

        foreach ($rules as $r) {
            if (! self::isActive($r->active)) {
                continue;
            }
            if (! $this->matchesScope($r, $userId, $groupIds, $unitId, $tokenId)) {
                continue;
            }

            $rc          = $r->context;
            $ctxSpecific = ($rc !== null && $rc !== '') ? 1 : 0;
            if ($ctxSpecific === 1 && (string) $rc !== (string) $context) {
                continue; // regra de contexto específico que não casa
            }

            $period = (string) $r->period;
            if (! in_array($period, self::PERIODS, true)) {
                continue;
            }

            $rank = self::RANK[(string) $r->scope_type] ?? -1;
            if ($rank < 0) {
                continue;
            }

            if ($ctxSpecific > $bestCtx[$period]
                || ($ctxSpecific === $bestCtx[$period] && $rank > $bestRank[$period])) {
                $best[$period] = [
                    'max'      => (int) $r->max_tokens,
                    'action'   => ((string) $r->action) === 'warn' ? 'warn' : 'block',
                    'resetDay' => ($r->reset_day !== null && $r->reset_day !== '') ? (int) $r->reset_day : null,
                    'scope'    => (string) $r->scope_type,
                ];
                $bestRank[$period] = $rank;
                $bestCtx[$period]  = $ctxSpecific;
            }
        }

        return $best;
    }

    /**
     * Estado de cota do chamador.
     *
     * @param int[] $groupIds
     * @return array{blocked:bool, warn:bool, periods: array<string, array{used:int,max:int,remaining:int,over:bool,action:string,scope:string}>}
     */
    public function check(?int $userId, array $groupIds, ?int $unitId, ?int $tokenId, ?string $context): array
    {
        $rules = $this->resolveEffectiveRules($userId, $groupIds, $unitId, $tokenId, $context);

        $out = ['blocked' => false, 'warn' => false, 'periods' => []];

        foreach ($rules as $period => $rule) {
            if ($rule === null || $rule['max'] <= 0) {
                continue;
            }

            // token-scope mede por token_id; os demais medem por usuário.
            $used = $rule['scope'] === 'token'
                ? $this->sumUsage('token_id', $tokenId, $period, $rule['resetDay'], $context)
                : $this->sumUsage('user_id', $userId, $period, $rule['resetDay'], $context);

            $over = $used >= $rule['max'];

            $out['periods'][$period] = [
                'used'      => $used,
                'max'       => $rule['max'],
                'remaining' => max(0, $rule['max'] - $used),
                'over'      => $over,
                'action'    => $rule['action'],
                'scope'     => $rule['scope'],
            ];

            if ($over && $rule['action'] === 'block') {
                $out['blocked'] = true;
            }
            if ($over && $rule['action'] === 'warn') {
                $out['warn'] = true;
            }
        }

        return $out;
    }

    /**
     * Lança QuotaExceededException se algum período com regra `block` já estourou.
     *
     * @param int[] $groupIds
     * @return array<string,mixed> resultado de check() (quando não bloqueado)
     */
    public function assertWithinQuota(?int $userId, array $groupIds, ?int $unitId, ?int $tokenId, ?string $context): array
    {
        $res = $this->check($userId, $groupIds, $unitId, $tokenId, $context);
        if ($res['blocked'] === true) {
            throw new QuotaExceededException($res);
        }

        return $res;
    }

    /** Soma total_tokens da janela do período p/ um usuário (atalho público). */
    public function currentUsage(?int $userId, string $period, ?int $resetDay, ?string $context): int
    {
        return $this->sumUsage('user_id', $userId, $period, $resetDay, $context);
    }

    /**
     * SUM(total_tokens) em mad_ai_token_usage (conexão `log`) filtrando por
     * $column = $value na janela do período. $column é literal interno
     * ('user_id' | 'token_id') — nunca input de usuário.
     */
    private function sumUsage(string $column, $value, string $period, ?int $resetDay, ?string $context): int
    {
        if ($value === null) {
            return 0;
        }

        $start = Period::windowStart($period, $resetDay);

        $sql    = "SELECT COALESCE(SUM(total_tokens), 0) FROM mad_ai_token_usage WHERE {$column} = ?";
        $params = [$value];

        if ($start !== null) {
            $sql     .= ' AND created_at >= ?';
            $params[] = $start;
        }
        if ($context !== null && $context !== '') {
            $sql     .= ' AND context = ?';
            $params[] = $context;
        }

        $st = DB::connection('log')->getPdo()->prepare($sql);
        $st->execute($params);

        return (int) $st->fetchColumn();
    }

    /** @param int[] $groupIds */
    private function matchesScope($r, ?int $userId, array $groupIds, ?int $unitId, ?int $tokenId): bool
    {
        $sid = ($r->scope_id !== null && $r->scope_id !== '') ? (int) $r->scope_id : null;

        switch ((string) $r->scope_type) {
            case 'global':
                return true;
            case 'unit':
                return $unitId !== null && $sid === $unitId;
            case 'group':
                return $sid !== null && in_array($sid, $groupIds, true);
            case 'user':
                return $userId !== null && $sid === $userId;
            case 'token':
                return $tokenId !== null && $sid === $tokenId;
            default:
                return false;
        }
    }

    /** Normaliza o flag active (tinyint/bool/string) p/ bool. */
    private static function isActive($v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
