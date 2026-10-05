<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;

/**
 * WidgetSqlGuard — validação SELECT-only + execução da SQL de um widget salvo.
 *
 * A SQL vem do MODELO (via save_widget/preview_widget) e fica persistida em
 * mad_ai_widget.spec_json; a exibição do dashboard re-executa aqui SEM IA.
 * Superfície de risco = escrita/DDL — por isso o guard é fail-closed:
 *
 *   - UMA instrução, começando com SELECT ou WITH;
 *   - comentários e literais são removidos ANTES do scan de keywords (não dá
 *     pra esconder um UPDATE dentro de string/comentário, nem um label
 *     "última atualização" gera falso-positivo);
 *   - denylist de palavras de escrita/DDL/execução;
 *   - resultado sempre envelopado em `SELECT * FROM ( … ) __mad_w LIMIT N`
 *     (portável sqlite/mysql8/pgsql — todos aceitam CTE em derived table).
 *
 * ACESSO (manifest MCP): a SQL crua obedece às mesmas regras das tools —
 * só tabelas concedidas ao perfil do usuário, colunas expostas (PII
 * mascarada) e linhas do escopo dele (row-scope do MCP, unidade/empresa do
 * app, soft delete) — {@see WidgetSqlScope}. Credenciais, dados privados de
 * cada usuário, catálogos do banco e funções que executam SQL em texto são
 * recusados sempre, antes de tudo — {@see \Mad\Security\ProtectedData}.
 *
 * Até o 5.96.21 a Multi-unidade/tenant em pool RECUSAVA toda SQL sobre tabela
 * com unit_id/tenant_id (o tenancyViolation do 5.96.6); agora ela roda
 * filtrada pela unidade/empresa do token, como as tools.
 */
final class WidgetSqlGuard
{
    public const MAX_ROWS = 1000;

    private const DENY = [
        'insert', 'update', 'delete', 'drop', 'alter', 'create', 'truncate',
        'replace', 'grant', 'revoke', 'attach', 'detach', 'vacuum', 'pragma',
        'exec', 'execute', 'call', 'copy', 'merge', 'into', 'outfile',
        'dumpfile', 'load_file', 'sleep', 'benchmark', 'pg_sleep', 'dblink',
        'lock', 'handler', 'shutdown',
    ];

    /** Valida a SQL; retorna mensagem de erro ou null quando ok. */
    public static function validate(string $sql): ?string
    {
        $sql = trim($sql);
        if ($sql === '') {
            return 'SQL vazia.';
        }

        // tolera UM ';' terminal (LLM adora); qualquer outro = multi-statement
        $sql = rtrim($sql);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }

        $scan = self::stripLiteralsAndComments($sql);

        if (str_contains($scan, ';')) {
            return 'Apenas uma instrução SQL é permitida (encontrado ";").';
        }
        if (! preg_match('/^\s*(select|with)\b/i', $scan)) {
            return 'Apenas consultas SELECT (ou WITH … SELECT) são permitidas.';
        }
        // Widget SEM FROM = dado fabricado em literal (SELECT 'x' AS a, 3 AS b…)
        // — o modelo já tentou "preencher" um widget assim quando a tabela
        // pedida não existia. Widget de BI SEMPRE lê de tabela.
        if (! preg_match('/\bfrom\b/i', $scan)) {
            return 'A SQL do widget precisa ler de uma tabela (FROM). Não fabrique dados com SELECT de literais — se o dado pedido não existe no schema, informe o usuário.';
        }
        foreach (self::DENY as $kw) {
            if (preg_match('/\b' . preg_quote($kw, '/') . '\b/i', $scan)) {
                return "Palavra-chave não permitida em widget: \"{$kw}\". Use apenas SELECT de leitura.";
            }
        }

        return null;
    }

    /**
     * Substitui placeholders :nome pelos valores QUOTED via PDO (filtros de
     * dashboard). Só troca fora de literais/comentários; placeholder sem valor
     * vira '' (o SQL do widget usa o padrão ":x = '' OR …"). Quote do driver =
     * sem injection; a validate() roda DEPOIS da interpolação (defesa dupla).
     *
     * @param array<string, string> $values name => valor cru do filtro
     */
    public static function interpolate(string $db, string $sql, array $values): string
    {
        if (! str_contains($sql, ':')) {
            return $sql;
        }

        try {
            $pdo = DB::connection($db)->getPdo();
        } catch (\Throwable) {
            return $sql;
        }

        // Anda pelo SQL pulando literais (mesma máquina do strip) e troca
        // :nome fora deles. (?<![:\w]) evita casts pgsql `::tipo` e meio-de-palavra.
        $out = '';
        $len = strlen($sql);
        $i   = 0;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === "'" || $c === '"') {
                $quote = $c;
                $out  .= $c;
                $i++;
                while ($i < $len) {
                    $out .= $sql[$i];
                    if ($sql[$i] === $quote) {
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $out .= $sql[$i + 1];
                            $i   += 2;
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                $i++;
                continue;
            }
            if ($c === ':' && $i + 1 < $len && preg_match('/[a-zA-Z_]/', $sql[$i + 1]) && ($i === 0 || ! preg_match('/[:\w]/', $sql[$i - 1]))) {
                preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*/', substr($sql, $i + 1), $m);
                $name = $m[0];
                $out .= $pdo->quote((string) ($values[$name] ?? ''));
                $i   += 1 + strlen($name);
                continue;
            }
            $out .= $c;
            $i++;
        }

        return $out;
    }

    /**
     * Valida + executa; retorna rows associativas (máx MAX_ROWS).
     *
     * @return array{ok: bool, rows?: list<array<string, mixed>>, error?: string}
     */
    public static function run(string $db, string $sql, int $maxRows = self::MAX_ROWS): array
    {
        if (($err = self::validate($sql)) !== null) {
            return ['ok' => false, 'error' => $err];
        }
        // Credenciais (senha dos usuários, tokens, sessões, chaves do provedor
        // de pagamento), catálogos do banco e funções que executam SQL em texto:
        // recusados SEMPRE, com ou sem Multi-unidade (ver ProtectedData).
        if (($err = \Mad\Security\ProtectedData::sqlViolation($db, $sql, 'widget_sql')) !== null) {
            return ['ok' => false, 'error' => $err];
        }
        // Concessões do perfil no manifest MCP: tabela, colunas e linhas.
        $scope = WidgetSqlScope::plan($db, $sql);
        if (! $scope['ok']) {
            return ['ok' => false, 'error' => (string) ($scope['error'] ?? 'Consulta fora do seu acesso.')];
        }

        $inner = rtrim(trim($sql));
        if (str_ends_with($inner, ';')) {
            $inner = rtrim(substr($inner, 0, -1));
        }
        $maxRows = max(1, min(self::MAX_ROWS, $maxRows));
        $wrapped = ($scope['with'] ?? '') . 'SELECT * FROM (' . $inner . ') __mad_w LIMIT ' . $maxRows;

        try {
            $rows = DB::connection($db)->select($wrapped);
        } catch (\Throwable $e) {
            // A mensagem do Laravel anexa conexão, arquivo do banco e a SQL
            // executada — que inclui as CTEs de escopo (e a do diretório de
            // usuários, com todos os nomes). O modelo só precisa do erro do banco.
            $msg = $e instanceof \Illuminate\Database\QueryException && $e->getPrevious() !== null
                ? $e->getPrevious()->getMessage()
                : $e->getMessage();

            return ['ok' => false, 'error' => 'Erro ao executar a SQL: ' . $msg];
        }

        // stdClass → assoc, valores escalares (objetos/binários viram string)
        $out = [];
        foreach ($rows as $r) {
            $out[] = json_decode(json_encode($r, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}', true) ?: [];
        }

        return ['ok' => true, 'rows' => $out];
    }

    /** Remove literais ('…', "…") e comentários (--, /* *​/) para o scan. */
    private static function stripLiteralsAndComments(string $sql): string
    {
        $out = '';
        $len = strlen($sql);
        $i   = 0;
        while ($i < $len) {
            $c = $sql[$i];

            if ($c === "'" || $c === '"') {
                $quote = $c;
                $i++;
                while ($i < $len) {
                    if ($sql[$i] === $quote) {
                        // '' escapado dentro do literal
                        if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                            $i += 2;
                            continue;
                        }
                        break;
                    }
                    if ($sql[$i] === '\\') {
                        $i++; // pula o escapado
                    }
                    $i++;
                }
                $i++;
                $out .= ' ';
                continue;
            }

            if ($c === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($c === '/' && $i + 1 < $len && $sql[$i + 1] === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i   = $end === false ? $len : $end + 2;
                $out .= ' ';
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }
}
