<?php

namespace Mad\Mcp;

/**
 * McpChangeAudit
 *
 * Auditoria de escrita/destrutivas (Contrato secao 6) gravando em
 * mad_log_change (conexao 'log', resolvida pelo proprio model Eloquent).
 * Best-effort: nunca lanca — falha de auditoria nao pode quebrar a operacao
 * ja efetivada.
 *
 * Deve ser chamado APOS a transacao principal fechar.
 */
final class McpChangeAudit
{
    /**
     * @param array<string,mixed> $old valores anteriores (update/delete)
     * @param array<string,mixed> $new valores novos (insert/update)
     */
    public static function record(
        string $table,
        string $pk,
        mixed $pkValue,
        string $operation,
        array $old = [],
        array $new = []
    ): void {
        try {
            $columns = self::diffColumns($operation, $old, $new);

            $now   = date('Y-m-d H:i:s');
            $login = (string) ((function_exists('session') ? session('login') : null) ?: McpCurrentUser::login());
            $ip    = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $sid   = (function_exists('session') ? session()->getId() : null) ?: '';
            $trace = (new \Exception)->getTraceAsString();

            if ($columns === []) {
                self::row($table, $pk, $pkValue, $operation, null, null, null, $now, $login, $ip, $sid, $trace);
            } else {
                foreach ($columns as $col) {
                    self::row(
                        $table, $pk, $pkValue, $operation, $col,
                        isset($old[$col]) ? (string) $old[$col] : null,
                        isset($new[$col]) ? (string) $new[$col] : null,
                        $now, $login, $ip, $sid, $trace
                    );
                }
            }
        } catch (\Throwable $e) {
            error_log('[MCP audit] ' . $e->getMessage());
        }
    }

    /** @return array<int,string> */
    private static function diffColumns(string $operation, array $old, array $new): array
    {
        if ($operation === 'delete') {
            return [];
        }
        if ($operation === 'insert') {
            return array_keys($new);
        }

        // update: apenas colunas que mudaram
        $cols = [];
        foreach ($new as $col => $val) {
            $before = $old[$col] ?? null;
            if ((string) $before !== (string) $val) {
                $cols[] = (string) $col;
            }
        }

        return $cols;
    }

    private static function row(
        string $table, string $pk, mixed $pkValue, string $operation,
        ?string $col, ?string $oldVal, ?string $newVal,
        string $now, string $login, string $ip, string $sid, string $trace = ''
    ): void {
        \App\Models\Log\Change::create([
            'logdate'    => $now,
            'log_year'   => (int) date('Y'),
            'log_month'  => (int) date('m'),
            'log_day'    => (int) date('d'),
            'login'      => $login,
            'tablename'  => $table,
            'primarykey' => $pk,
            'pkvalue'    => (string) $pkValue,
            'operation'  => $operation,
            'columnname' => $col,
            'oldvalue'   => $oldVal,
            'newvalue'   => $newVal,
            'access_ip'  => $ip,
            'session_id' => $sid,
            'log_trace'  => $trace,
            'class_name' => 'MCP',
            'php_sapi'   => PHP_SAPI,
        ]);
    }
}
