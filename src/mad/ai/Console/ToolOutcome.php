<?php

namespace Mad\Ai\Console;

/**
 * ToolOutcome — resultado da execução de uma system tool.
 *
 * O handler OWNS a ordem backup→mutate→verify e devolve isto. O Executor lê:
 *  - ok=false                      → falha (status 'failed'); se backupRef vazio,
 *                                    nada foi mutado (ex.: pg_dump falhou ANTES de mutar).
 *  - ok=true & verifyOk=false      → mutou mas a verificação falhou → Executor
 *                                    aciona rollback(backupRef) (status 'rolled_back').
 *  - ok=true & verifyOk=true       → sucesso (status 'ok').
 */
final class ToolOutcome
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message = '',
        public readonly string $backupRef = '',
        public readonly bool $verifyOk = true,
        public readonly array $data = [],
    ) {
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }

    /** @param array<string,mixed> $data */
    public static function success(string $message, string $backupRef = '', bool $verifyOk = true, array $data = []): self
    {
        return new self(true, $message, $backupRef, $verifyOk, $data);
    }
}
