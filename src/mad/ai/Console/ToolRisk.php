<?php

namespace Mad\Ai\Console;

/**
 * ToolRisk — metadados de risco de uma system tool do Command Center.
 *
 * Serializa no bloco `confirm` (o front mostra badge de risco + exige
 * typed-confirm em `critical`) e governa o portão no SystemTool/Executor:
 *  - requiresConfirmation → a tool NUNCA executa direto; vira pendência.
 *  - requiresBackup       → o handler garante snapshot ANTES de mutar.
 *  - reversible           → há caminho de rollback (false = aviso forte).
 *
 * level: low | medium | high | critical.
 */
final class ToolRisk
{
    public function __construct(
        public readonly string $level,
        public readonly bool $reversible,
        public readonly bool $requiresConfirmation,
        public readonly bool $requiresBackup,
    ) {
    }

    /** Leitura/proteção (backups): roda direto, sem confirmação. */
    public static function safe(): self
    {
        return new self('low', true, false, false);
    }

    /** Escrita reversível de médio risco (ex.: menu/config) — confirma, faz backup. */
    public static function medium(bool $reversible = true): self
    {
        return new self('medium', $reversible, true, true);
    }

    /** Escrita de alto risco (ex.: código/permissões) — confirma, faz backup. */
    public static function high(bool $reversible = true): self
    {
        return new self('high', $reversible, true, true);
    }

    /** Destrutiva (ex.: migration) — confirma + typed-confirm, backup obrigatório. */
    public static function critical(bool $reversible = true): self
    {
        return new self('critical', $reversible, true, true);
    }

    /** @return array{risk_level:string,reversible:bool,requires_confirmation:bool,requires_backup:bool} */
    public function toArray(): array
    {
        return [
            'risk_level'            => $this->level,
            'reversible'            => $this->reversible,
            'requires_confirmation' => $this->requiresConfirmation,
            'requires_backup'       => $this->requiresBackup,
        ];
    }

    /** Cartão "perigoso" (vermelho) p/ high/critical. */
    public function isDanger(): bool
    {
        return $this->level === 'high' || $this->level === 'critical';
    }
}
