<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mad\Mcp\McpManifest;
use Mad\Mcp\McpManifestLoader;

/**
 * ChatStorage — onde mora o estado do chat (conversas, pendências de
 * confirmação, favoritos, widgets e dashboards do Micro-BI, auditoria do
 * Command Center): a conexão `ai` do framework, data-plane (o tenant repointa).
 *
 * Até a 5.227 tudo isso ia para o banco de NEGÓCIO (o do manifest MCP): o chat
 * criava 7 tabelas `mad_ai_*` no banco do cliente e dependia de CREATE/ALTER
 * nele. Com o framework em SQLite e o negócio num PostgreSQL próprio, o DDL
 * falhava, nada gravava e a confirmação de escrita voltava sempre "expirada"
 * (fw#564). As SQL dos widgets e as tools do MCP continuam no banco de
 * negócio ({@see business()}).
 *
 * O que já estava gravado no banco de negócio é copiado para a `ai` uma vez,
 * na primeira vez que o chat abre depois da atualização (ids preservados — os
 * dashboards continuam apontando para os mesmos widgets). O banco de negócio
 * só é LIDO: nada é criado, alterado ou apagado lá.
 */
final class ChatStorage
{
    public const CONNECTION = 'ai';

    /**
     * Tabelas copiadas do banco de negócio. `id` = id texto: a cópia mantém o
     * id e pula o que já existe. `natural` = id inteiro: a linha ganha id novo
     * e a repetição é reconhecida por essas colunas.
     */
    private const LEGACY_TABLES = [
        'mad_ai_conversation'    => ['id' => 'id'],
        'mad_ai_favorite'        => ['id' => 'id'],
        'mad_ai_widget'          => ['id' => 'id'],
        'mad_ai_dashboard'       => ['id' => 'id'],
        'mad_ai_dashboard_share' => ['natural' => ['dashboard_id', 'kind', 'ref_id']],
        'mad_ai_tool_audit'      => ['natural' => ['conversation_id', 'confirm_id', 'tool', 'created_at']],
    ];

    private const PAGE = 200;

    /** Cópia "running" mais velha que isto foi interrompida: outra requisição retoma. */
    private const STALE_SECONDS = 600;

    /** @var array<string, bool> cópias já tentadas neste processo */
    private static array $imported = [];

    /**
     * Banco de NEGÓCIO: o do manifest MCP, senão [mcp] database / main_database.
     * É onde rodam as tools do MCP e as SQL dos widgets.
     */
    public static function business(?McpManifest $manifest = null): string
    {
        if ($manifest !== null) {
            return $manifest->database();
        }

        try {
            return McpManifestLoader::load()->database();
        } catch (\Throwable) {
            return (string) (config('mad.mcp.database') ?: config('mad.general.main_database', 'business'));
        }
    }

    /**
     * Conexão do estado do chat: `ai`; app que não declara a conexão `ai`
     * continua no banco de negócio, como antes.
     */
    public static function connection(?string $business = null): string
    {
        $business ??= self::business();

        if (! is_array(config('database.connections.' . self::CONNECTION))) {
            return $business;
        }

        self::importLegacy(self::CONNECTION, $business);

        return self::CONNECTION;
    }

    /**
     * Copia para $target o estado do chat que ficou em $legacy. Uma vez por
     * banco de negócio: a linha em mad_ai_legacy_import é reservada ANTES da
     * cópia (chave primária = quem chegar primeiro copia; as outras requisições
     * seguem sem esperar) e liberada se a cópia falhar, para tentar de novo.
     * Mesmo banco físico atrás das duas conexões (padrão: tudo no mad.sqlite;
     * tenant bridge) = nada a copiar.
     */
    private static function importLegacy(string $target, string $legacy): void
    {
        if ($legacy === '' || $legacy === $target || ! is_array(config('database.connections.' . $legacy))) {
            return;
        }

        $source = self::sourceKey($legacy);
        $memo   = self::sourceKey($target) . '|' . $source;
        if (self::$imported[$memo] ?? false) {
            return;
        }
        self::$imported[$memo] = true;

        $nonce = 'running:' . bin2hex(random_bytes(8));
        try {
            EmbedSchema::ensure($target);
            $dst = DB::connection($target)->getPdo();
            if (! self::reserve($dst, $source, $nonce)) {
                return;
            }
        } catch (\Throwable $e) {
            error_log('[chat-storage] conexão ' . $target . ' indisponível para receber o histórico de ' . $legacy . ': ' . $e->getMessage());

            return;
        }

        try {
            $src = DB::connection($legacy)->getPdo();

            if (self::sameDatabase($src, $source, $nonce)) {
                self::mark($dst, $source, 'same', $legacy);

                return;
            }

            $copied = [];
            foreach (self::LEGACY_TABLES as $table => $how) {
                $n = self::copyTable($target, $legacy, $table, $how);
                if ($n > 0) {
                    $copied[$table] = $n;
                }
            }

            self::mark($dst, $source, 'done', json_encode(['from' => $legacy, 'copied' => (object) $copied]) ?: $legacy);
            if ($copied !== []) {
                error_log('[chat-storage] histórico do chat copiado de ' . $legacy . ' para ' . $target . ': ' . json_encode($copied));
            }
        } catch (\Throwable $e) {
            error_log('[chat-storage] cópia do histórico do chat de ' . $legacy . ' para ' . $target . ' falhou (tenta de novo depois): ' . $e->getMessage());
            try {
                $dst->prepare('DELETE FROM mad_ai_legacy_import WHERE source = ? AND detail = ?')->execute([$source, $nonce]);
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Reserva a cópia deste banco de negócio. false = já copiado, ou outra
     * requisição está copiando agora. Reserva "running" parada há mais de
     * STALE_SECONDS (processo morto no meio) é retomada — a cópia é idempotente.
     */
    private static function reserve(\PDO $dst, string $source, string $nonce): bool
    {
        $now  = date('Y-m-d H:i:s');
        $seen = $dst->prepare('SELECT status, updated_at FROM mad_ai_legacy_import WHERE source = ?');
        $seen->execute([$source]);
        $row = $seen->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            try {
                $dst->prepare('INSERT INTO mad_ai_legacy_import (source, status, detail, created_at, updated_at) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$source, 'running', $nonce, $now, $now]);

                return true;
            } catch (\Throwable) {
                return false; // outra requisição reservou primeiro
            }
        }

        $since = strtotime((string) ($row['updated_at'] ?? '')) ?: 0;
        if (($row['status'] ?? '') !== 'running' || time() - $since < self::STALE_SECONDS) {
            return false;
        }

        $take = $dst->prepare('UPDATE mad_ai_legacy_import SET detail = ?, updated_at = ? WHERE source = ? AND status = ? AND updated_at = ?');
        $take->execute([$nonce, $now, $source, 'running', $row['updated_at']]);

        return $take->rowCount() === 1;
    }

    /**
     * As duas conexões são o mesmo banco? A reserva recém-gravada no destino
     * (com o nonce desta requisição) aparece na origem — vale para qualquer
     * configuração (mesmo arquivo, mesmo servidor por outro nome, tenant).
     */
    private static function sameDatabase(\PDO $src, string $source, string $nonce): bool
    {
        try {
            $st = $src->prepare('SELECT 1 FROM mad_ai_legacy_import WHERE source = ? AND detail = ?');
            $st->execute([$source, $nonce]);

            return $st->fetchColumn() !== false;
        } catch (\Throwable) {
            return false; // a tabela não existe lá: outro banco
        }
    }

    /**
     * Copia uma tabela em páginas; devolve quantas linhas entraram.
     *
     * @param array{id?: string, natural?: list<string>} $how
     */
    private static function copyTable(string $target, string $legacy, string $table, array $how): int
    {
        $src = DB::connection($legacy)->getPdo();
        try {
            $src->query('SELECT 1 FROM ' . $table . ' WHERE 1 = 0');
        } catch (\Throwable) {
            return 0; // o banco de negócio nunca teve esta tabela
        }

        $idColumn = $how['id'] ?? null;
        $columns  = array_values(array_intersect(
            Schema::connection($legacy)->getColumnListing($table),
            Schema::connection($target)->getColumnListing($table),
        ));
        if ($idColumn === null) {
            $columns = array_values(array_diff($columns, ['id']));
        }
        if ($columns === [] || ($idColumn !== null && ! in_array($idColumn, $columns, true))) {
            return 0;
        }

        $dst    = DB::connection($target)->getPdo();
        $insert = $dst->prepare(
            'INSERT INTO ' . $table . ' (' . ($idColumn === null ? 'id, ' : '') . implode(', ', $columns) . ')'
            . ' VALUES (' . ($idColumn === null ? '?, ' : '') . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $exists = $idColumn !== null ? $dst->prepare('SELECT 1 FROM ' . $table . ' WHERE ' . $idColumn . ' = ?') : null;

        // id inteiro: o que já está no destino, pelas colunas naturais.
        $natural = array_values(array_intersect($how['natural'] ?? [], $columns));
        $known   = [];
        $nextId  = 0;
        if ($idColumn === null) {
            $nextId = (int) ($dst->query('SELECT COALESCE(MAX(id), 0) FROM ' . $table)->fetchColumn() ?: 0);
            if ($natural !== []) {
                foreach ($dst->query('SELECT ' . implode(', ', $natural) . ' FROM ' . $table)->fetchAll(\PDO::FETCH_NUM) as $r) {
                    $known[self::naturalKey($r)] = true;
                }
            }
        }

        // Página por chave (id > último): a tabela pode ser grande (transcript
        // de cada conversa) e nada fica todo em memória. Uma transação por
        // página no destino (no SQLite, um fsync por página, não por linha).
        $key    = $idColumn ?? 'id';
        $select = $src->prepare(
            'SELECT ' . $key . ' AS mad_import_key, ' . implode(', ', $columns) . ' FROM ' . $table
            . ' WHERE ' . $key . ' > ? ORDER BY ' . $key . ' LIMIT ' . self::PAGE
        );

        $copied = 0;
        $last   = $idColumn !== null ? '' : 0;
        do {
            $select->execute([$last]);
            $rows = $select->fetchAll(\PDO::FETCH_ASSOC);

            $dst->beginTransaction();
            try {
                foreach ($rows as $row) {
                    $last = $row['mad_import_key'];
                    $values = array_map(static fn (string $c) => $row[$c] ?? null, $columns);

                    if ($exists !== null) {
                        $exists->execute([$row[$idColumn]]);
                        $hit = $exists->fetchColumn() !== false;
                        $exists->closeCursor();
                        if ($hit) {
                            continue; // já está na `ai`: o de lá vence
                        }
                        $insert->execute($values);
                    } else {
                        $nk = self::naturalKey(array_map(static fn (string $c) => $row[$c] ?? null, $natural));
                        if ($natural !== [] && isset($known[$nk])) {
                            continue;
                        }
                        $known[$nk] = true;
                        $insert->execute([++$nextId, ...$values]);
                    }
                    $copied++;
                }
                $dst->commit();
            } catch (\Throwable $e) {
                $dst->rollBack();
                throw $e;
            }
        } while (count($rows) === self::PAGE);

        return $copied;
    }

    /** @param list<mixed> $values */
    private static function naturalKey(array $values): string
    {
        return json_encode(array_map(static fn ($v) => $v === null ? null : (string) $v, $values)) ?: '';
    }

    private static function mark(\PDO $dst, string $source, string $status, string $detail): void
    {
        $dst->prepare('UPDATE mad_ai_legacy_import SET status = ?, detail = ?, updated_at = ? WHERE source = ?')
            ->execute([$status, $detail, date('Y-m-d H:i:s'), $source]);
    }

    /** Identidade do banco físico atrás de uma conexão (nome + destino). */
    private static function sourceKey(string $connection): string
    {
        $c = (array) config('database.connections.' . $connection, []);

        return md5(json_encode([
            $connection, $c['driver'] ?? '', $c['host'] ?? '', $c['port'] ?? '', $c['database'] ?? '', $c['search_path'] ?? '',
        ]) ?: $connection);
    }
}
