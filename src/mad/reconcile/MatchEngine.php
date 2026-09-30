<?php
namespace Mad\Reconcile;

/**
 * MatchEngine — motor de auto-match da conciliação (<mad-reconcile>).
 *
 * Puro (sem I/O): recebe dois lados normalizados e devolve grupos sugeridos.
 * Cada item de entrada: ['id' => int, 'amount' => float, 'date' => 'Y-m-d'|null,
 * 'doc' => string|null]. Cada item é usado no máximo uma vez (greedy, passes
 * em ordem de confiança):
 *
 *   Pass 1 — exato 1:1: mesmo valor + mesmo documento (se a regra document
 *            estiver ativa e ambos tiverem doc). Score 100.
 *   Pass 2 — 1:1 por valor com data dentro da tolerância (date:N). Score
 *            95 - 5×diff_dias (mín 70).
 *   Pass 3 — N:1 por soma: até COMBO_MAX_ITEMS itens de um lado somando o
 *            valor de 1 item do outro (data média dentro da tolerância, se
 *            houver regra de data). Score 70. Capado em COMBO_MAX_CHECKS
 *            combinações por alvo — conciliações grandes não explodem
 *            (subset-sum é exponencial; o cap é anunciado via $truncated).
 *
 * Regras (string do attr match-on): "amount,date:3,document"
 *   amount    — sempre implícita (é a base de tudo)
 *   date:N    — tolerância de N dias entre as datas
 *   document  — pass 1 exige igualdade de documento
 */
class MatchEngine
{
    public const COMBO_MAX_ITEMS  = 3;
    public const COMBO_MAX_CHECKS = 20000;

    /** Tolerância de centavos na comparação de somas (float-safe). */
    private const EPSILON = 0.005;

    private int  $dateTolerance = 0;
    private bool $useDocument   = false;
    private bool $useDate       = false;

    /** True quando o pass 3 estourou o cap de combinações (cobertura parcial). */
    public bool $truncated = false;

    public function __construct(string $rules = 'amount')
    {
        foreach (explode(',', $rules) as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === 'document') {
                $this->useDocument = true;
            } elseif (preg_match('/^date(?::(\d+))?$/', $rule, $m)) {
                $this->useDate       = true;
                $this->dateTolerance = isset($m[1]) ? (int) $m[1] : 0;
            }
            // 'amount' é implícita; tokens desconhecidos são ignorados
        }
    }

    public function dateTolerance(): int  { return $this->dateTolerance; }
    public function usesDocument(): bool  { return $this->useDocument; }
    public function usesDate(): bool      { return $this->useDate; }

    /**
     * @param array<int,array> $left
     * @param array<int,array> $right
     * @return array<int,array{left: int[], right: int[], score: int}>
     */
    public function match(array $left, array $right): array
    {
        $this->truncated = false;

        $left  = array_values($left);
        $right = array_values($right);
        $usedL = [];
        $usedR = [];
        $groups = [];

        // ── Pass 1: exato valor (+ documento) ─────────────────────────────
        foreach ($left as $li => $l) {
            if (isset($usedL[$li])) continue;
            foreach ($right as $ri => $r) {
                if (isset($usedR[$ri])) continue;
                if (!$this->sameAmount($l['amount'], $r['amount'])) continue;
                if ($this->useDocument) {
                    $ld = trim((string) ($l['doc'] ?? ''));
                    $rd = trim((string) ($r['doc'] ?? ''));
                    if ($ld === '' || $rd === '' || strcasecmp($ld, $rd) !== 0) continue;
                } elseif ($this->useDate && $this->dateDiff($l, $r) !== 0) {
                    continue; // sem document: pass 1 = valor + mesma data
                }
                $groups[] = ['left' => [$l['id']], 'right' => [$r['id']], 'score' => 100];
                $usedL[$li] = true;
                $usedR[$ri] = true;
                break;
            }
        }

        // ── Pass 2: 1:1 valor + data na tolerância ────────────────────────
        if ($this->useDate) {
            foreach ($left as $li => $l) {
                if (isset($usedL[$li])) continue;
                $best = null;
                $bestDiff = PHP_INT_MAX;
                foreach ($right as $ri => $r) {
                    if (isset($usedR[$ri])) continue;
                    if (!$this->sameAmount($l['amount'], $r['amount'])) continue;
                    $diff = $this->dateDiff($l, $r);
                    if ($diff === null || $diff > $this->dateTolerance) continue;
                    if ($diff < $bestDiff) {
                        $bestDiff = $diff;
                        $best     = $ri;
                    }
                }
                if ($best !== null) {
                    $groups[] = [
                        'left'  => [$l['id']],
                        'right' => [$right[$best]['id']],
                        'score' => max(70, 95 - 5 * $bestDiff),
                    ];
                    $usedL[$li]   = true;
                    $usedR[$best] = true;
                }
            }
        }

        // ── Pass 3: N:1 por soma (dois sentidos) ──────────────────────────
        $checks = 0;
        $this->comboPass($left, $right, $usedL, $usedR, $groups, false, $checks);
        $this->comboPass($right, $left, $usedR, $usedL, $groups, true, $checks);

        return $groups;
    }

    /**
     * Para cada alvo não usado de $targets, procura até COMBO_MAX_ITEMS itens
     * de $pool somando o valor. $swap=true → pool é o lado esquerdo do grupo.
     */
    private function comboPass(array $pool, array $targets, array &$usedPool, array &$usedTargets, array &$groups, bool $swap, int &$checks): void
    {
        foreach ($targets as $ti => $target) {
            if (isset($usedTargets[$ti])) continue;
            if ($checks >= self::COMBO_MAX_CHECKS) {
                $this->truncated = true;
                return;
            }

            $candidates = [];
            foreach ($pool as $pi => $p) {
                if (isset($usedPool[$pi])) continue;
                // candidato precisa ter o mesmo sinal e não exceder o alvo
                if ($p['amount'] * $target['amount'] <= 0) continue;
                if (abs($p['amount']) > abs($target['amount']) + self::EPSILON) continue;
                $candidates[] = $pi;
            }
            if (count($candidates) < 2) continue;

            $combo = $this->findSum($pool, $candidates, (float) $target['amount'], $checks);
            if ($combo === null) continue;

            $poolIds = array_map(fn ($pi) => $pool[$pi]['id'], $combo);
            if ($this->useDate && !$this->comboDatesOk($pool, $combo, $target)) continue;

            $groups[] = [
                'left'  => $swap ? [$target['id']] : $poolIds,
                'right' => $swap ? $poolIds : [$target['id']],
                'score' => 70,
            ];
            foreach ($combo as $pi) $usedPool[$pi] = true;
            $usedTargets[$ti] = true;
        }
    }

    /** DFS de subconjunto (2..COMBO_MAX_ITEMS itens) somando $target. */
    private function findSum(array $pool, array $candidates, float $target, int &$checks): ?array
    {
        $n = count($candidates);
        $stack = [[0, 0.0, []]]; // [próximo índice, soma, escolhidos]

        while ($stack) {
            [$i, $sum, $chosen] = array_pop($stack);
            $checks++;
            if ($checks >= self::COMBO_MAX_CHECKS) {
                $this->truncated = true;
                return null;
            }
            if (count($chosen) >= 2 && $this->sameAmount($sum, $target)) {
                return $chosen;
            }
            if ($i >= $n || count($chosen) >= self::COMBO_MAX_ITEMS) {
                continue;
            }
            $pi = $candidates[$i];
            // incluir pool[$pi]
            $stack[] = [$i + 1, $sum + (float) $pool[$pi]['amount'], array_merge($chosen, [$pi])];
            // pular
            $stack[] = [$i + 1, $sum, $chosen];
        }

        return null;
    }

    private function comboDatesOk(array $pool, array $combo, array $target): bool
    {
        foreach ($combo as $pi) {
            $diff = $this->dateDiff($pool[$pi], $target);
            if ($diff === null || $diff > $this->dateTolerance) {
                return false;
            }
        }
        return true;
    }

    private function sameAmount(float $a, float $b): bool
    {
        return abs($a - $b) < self::EPSILON;
    }

    /** Diferença em dias absoluta; null se algum lado não tem data. */
    private function dateDiff(array $a, array $b): ?int
    {
        $da = $a['date'] ?? null;
        $db = $b['date'] ?? null;
        if (!$da || !$db) {
            return null;
        }
        $ta = strtotime((string) $da);
        $tb = strtotime((string) $db);
        if ($ta === false || $tb === false) {
            return null;
        }
        return (int) round(abs($ta - $tb) / 86400);
    }
}
