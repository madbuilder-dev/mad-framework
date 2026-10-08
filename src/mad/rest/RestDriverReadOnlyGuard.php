<?php

namespace Mad\Rest;

use RuntimeException;

/**
 * Filtro de FORMA do modo somente leitura do Driver REST: lê o texto do SQL e
 * recusa cedo, com mensagem clara, o que não é uma única instrução de leitura.
 *
 * NÃO é a defesa principal. Lista de palavras sempre deixa um caminho (função
 * que escreve, extensão, identificador escapado); quem garante a leitura é o
 * próprio banco, na sessão aberta por {@see RestDriverReadOnlySession}. Este
 * filtro existe para:
 *   1. dar o erro antes de abrir a conexão e com texto que o usuário entende;
 *   2. cobrir o que a transação somente leitura do engine NÃO recusa (arquivo no
 *      servidor: `INTO OUTFILE`, `COPY ... TO`; outra conexão: `dblink`; função
 *      que executa um SQL recebido em texto: `query_to_xml`; procedure que
 *      reabre a sessão: `CALL`; `PRAGMA` que desliga a trava no SQLite).
 *
 * A regra é a MESMA das conexões Direta e por Túnel SSH do Banco de dados do
 * Studio (lá, `ReadOnlySqlGuard`): quem marca somente leitura não pode ter
 * comportamento diferente conforme o tipo de conexão. Mexeu numa, mexa na outra.
 *
 * O que é recusado é a CATEGORIA, não um nome por vez: comando que não é de
 * leitura; função que abre outra conexão, carrega código ou toca arquivo; função
 * que executa SQL montado em texto. O nome é comparado DEPOIS de tirar as aspas
 * e o schema (`"dblink_exec"`, `public.dblink_exec`, comentário entre o nome e o
 * `(`), e a forma com escape Unicode do PostgreSQL (`U&"dblink\005fexec"`) — que
 * a máscara não decodifica — é recusada inteira. O que só o filtro segura (item
 * 2) é procurado também na OUTRA leitura possível de `\'`, que depende de
 * configuração do servidor: ali, errar para o lado de recusar é o certo.
 */
final class RestDriverReadOnlyGuard
{
    public const WRITE_BLOCKED = 'Conexão em modo somente-leitura: comando de escrita bloqueado.';

    public const MULTI_BLOCKED = 'Conexão em modo somente-leitura: envie um comando por vez (instruções separadas por ";" não são aceitas).';

    public const EXPLAIN_ANALYZE_BLOCKED = 'Conexão em modo somente-leitura: EXPLAIN ANALYZE executa o comando, então só é aceito com uma consulta (SELECT).';

    public const INTO_BLOCKED = 'Conexão em modo somente-leitura: consulta com INTO grava em tabela ou em arquivo e não é aceita.';

    public const EXECUTABLE_COMMENT_BLOCKED = 'Conexão em modo somente-leitura: comentário executável (/*! ... */) não é aceito.';

    public const PRAGMA_BLOCKED = 'Conexão em modo somente-leitura: este PRAGMA altera o banco ou a conexão. Só os de consulta são aceitos (ex.: PRAGMA table_info(tabela)).';

    public const SIDE_CHANNEL_BLOCKED = 'Conexão em modo somente-leitura: função que abre outra conexão ou carrega código no servidor não é aceita.';

    public const UNICODE_IDENT_BLOCKED = 'Conexão em modo somente-leitura: identificador ou texto com escape Unicode (U&"…") não é aceito.';

    public const DYNAMIC_SQL_BLOCKED = 'Conexão em modo somente-leitura: função que executa um comando SQL montado em texto não é aceita.';

    public const DB_REFUSED = 'Conexão em modo somente-leitura: o banco recusou essa chamada (função indisponível ou sem permissão).';

    /** Primeira palavra de uma instrução de leitura. */
    private const READ_KEYWORDS = ['select', 'with', 'table', 'values', 'explain', 'describe', 'desc', 'show', 'pragma'];

    /** Instruções que devolvem linhas (as únicas que `EXPLAIN ANALYZE` pode executar aqui). */
    private const QUERY_KEYWORDS = ['select', 'with', 'table', 'values'];

    /**
     * Palavras que só aparecem numa consulta quando ela escreve: CTE de escrita
     * (`WITH x AS (DELETE ... RETURNING ...)`), `SELECT ... INTO`, `FOR UPDATE`.
     * Coluna com um desses nomes precisa vir entre aspas (identificador entre
     * aspas não é lido como palavra).
     */
    private const WRITE_WORDS = 'insert|update|delete|merge|truncate|drop|alter|create|grant|revoke';

    /** Onde começa a instrução explicada por um EXPLAIN (para separar as opções dele). */
    private const STATEMENT_WORDS = 'select|with|table|values|insert|update|delete|replace|merge|truncate|create|alter|drop|grant|revoke|call|do|execute|declare|copy|refresh|set';

    /** PRAGMAs de consulta que recebem argumento entre parênteses. */
    private const PRAGMA_WITH_ARGUMENT = [
        'table_info', 'table_xinfo', 'table_list', 'index_list', 'index_info', 'index_xinfo',
        'foreign_key_list', 'foreign_key_check', 'integrity_check', 'quick_check',
    ];

    /** PRAGMAs que AGEM mesmo sem valor. */
    private const PRAGMA_ACTIONS = ['optimize', 'wal_checkpoint', 'incremental_vacuum', 'shrink_memory'];

    /**
     * PostgreSQL: funções que RECEBEM um comando SQL em TEXTO e o executam. Como
     * o comando só existe depois que o servidor monta a string (concatenação,
     * `format()`, `quote_literal()`…), não dá para reconhecer no texto o que está
     * lá dentro — mas a função EXTERNA aparece literal na consulta. Recusar a
     * CLASSE pelo nome dela fecha o caminho inteiro (uma chamada que embrulha
     * `dblink`/escrita por dentro), em vez de caçar o alvo caso a caso.
     *
     * Lista tirada da doc do PostgreSQL (seção "XML export"): as
     * `*_to_xml`/`*_to_xmlschema` que levam um `query` ou um `cursor`. As que
     * levam só um nome de tabela não executam texto arbitrário, mas entram junto
     * por segurança. Manutenção: ao subir a versão do PostgreSQL, conferir
     * `\df *_to_xml*` e `\df query_*`.
     */
    private const PG_DYNAMIC_SQL_FUNCTIONS = [
        'query_to_xml', 'query_to_xmlschema', 'query_to_xml_and_xmlschema',
        'cursor_to_xml', 'cursor_to_xmlschema',
        'table_to_xml', 'table_to_xmlschema', 'table_to_xml_and_xmlschema',
        'schema_to_xml', 'schema_to_xmlschema', 'schema_to_xml_and_xmlschema',
        'database_to_xml', 'database_to_xmlschema', 'database_to_xml_and_xmlschema',
    ];

    /**
     * Recusa (RuntimeException) o que não for UMA instrução de leitura.
     *
     * @param  string  $driver  pgsql | mysql | sqlite (nome do driver PDO)
     */
    public static function assertReadable(string $sql, string $driver): void
    {
        $driver = strtolower($driver);

        // Comentário executável do MySQL/MariaDB: `/*! ... */` é CÓDIGO para o
        // servidor e comentário para qualquer leitor de SQL. Nada de bom vem daí.
        if ($driver !== 'pgsql' && $driver !== 'sqlite' && self::found('~/\*M?!~', $sql)) {
            throw new RuntimeException(self::EXECUTABLE_COMMENT_BLOCKED);
        }

        // `\'` dentro de string: escapa no MySQL (padrão) e, no PostgreSQL, só em
        // E'...'. No SQLite nunca.
        $backslash = $driver !== 'pgsql' && $driver !== 'sqlite';

        [$code, $named] = self::masks($sql, $driver, $backslash);
        self::check($code, $named);

        // A leitura contrária (MySQL com NO_BACKSLASH_ESCAPES; PostgreSQL com
        // standard_conforming_strings desligado) muda onde uma string termina.
        // Para o que o banco recusa sozinho tanto faz; para o que só este filtro
        // segura, não: procura de novo.
        if ($driver !== 'sqlite') {
            self::assertNoEngineBlindSpot(...self::masks($sql, $driver, ! $backslash));
        }
    }

    /**
     * As duas leituras do texto (sem e com o nome dos identificadores entre
     * aspas) numa das interpretações de `\'`. A forma `U&"…"` do PostgreSQL só
     * serve para DISFARÇAR um nome — ninguém a escreve para ler uma tabela — e
     * é recusada em qualquer das leituras em que apareça como código.
     *
     * @return array{0:string,1:string}
     */
    private static function masks(string $sql, string $driver, bool $backslash): array
    {
        $unicode = false;
        $code = RestDriverSqlScanner::mask($sql, $driver, $backslash, false, $unicode);
        $named = RestDriverSqlScanner::mask($sql, $driver, $backslash, true, $unicode);

        if ($unicode) {
            throw new RuntimeException(self::UNICODE_IDENT_BLOCKED);
        }

        return [$code, $named];
    }

    /** Texto que o driver executa para o plano de execução de `$sql`. */
    public static function explainStatement(string $sql, string $driver): string
    {
        return (strtolower($driver) === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$sql;
    }

    /**
     * @param  string  $code  SQL sem literais, comentários e identificadores entre aspas
     * @param  string  $named  o mesmo, mas com o NOME dos identificadores entre aspas mantido
     */
    private static function check(string $code, string $named): void
    {
        if (! preg_match('/^[\s(]*([A-Za-z_]+)(.*)$/s', $code, $m)) {
            throw new RuntimeException(self::WRITE_BLOCKED);
        }
        $first = strtolower($m[1]);
        $rest = $m[2];

        // Primeiro o comando: `CREATE TRIGGER ... BEGIN a; b; END` é escrita, e
        // dizer "envie um comando por vez" mandaria o usuário para o lado errado.
        if (! in_array($first, self::READ_KEYWORDS, true)) {
            throw new RuntimeException(self::WRITE_BLOCKED);
        }

        // Uma instrução só: depois do primeiro `;` de verdade não pode vir código.
        // (Aqui não há corpo de rotina a entender: criar rotina é escrita.)
        $semicolon = strpos($code, ';');
        if ($semicolon !== false && trim(str_replace(';', '', substr($code, $semicolon))) !== '') {
            throw new RuntimeException(self::MULTI_BLOCKED);
        }

        self::assertNoEngineBlindSpot($code, $named);

        if ($first === 'pragma') {
            self::assertReadPragma($rest);

            return;
        }

        // DESCRIBE/DESC são sinônimos de EXPLAIN no MySQL (aceitam ANALYZE).
        if (in_array($first, ['explain', 'describe', 'desc'], true)) {
            self::assertExplainDoesNotWrite($rest);

            return;
        }

        if (in_array($first, self::QUERY_KEYWORDS, true)) {
            self::assertQueryDoesNotWrite($rest);
        }
        // SHOW: não escreve em engine nenhum (e `SHOW CREATE TABLE` é leitura).
    }

    /**
     * O que a transação somente leitura do engine NÃO recusa: dblink abre OUTRA
     * conexão (que não herda a transação), load_extension carrega código no
     * servidor e `INTO OUTFILE|DUMPFILE` do MySQL grava arquivo no servidor.
     */
    private static function assertNoEngineBlindSpot(string $code, string $named): void
    {
        // O nome é comparado em $named, de onde a máscara já tirou as aspas:
        // `"dblink_exec"`, `public.dblink_exec`, `"public"."dblink_exec"` e um
        // comentário/quebra entre o nome e o `(` chegam aqui normalizados.
        if (self::found('/\b(dblink\w*|load_extension|lo_import|lo_export)\s*\(/i', $named)) {
            throw new RuntimeException(self::SIDE_CHANNEL_BLOCKED);
        }
        // Classe de execução dinâmica: a função que roda um SQL em texto aparece
        // literal (o alvo embrulhado não). Recusa a família inteira.
        if (self::found('/\b('.implode('|', self::PG_DYNAMIC_SQL_FUNCTIONS).')\s*\(/i', $named)) {
            throw new RuntimeException(self::DYNAMIC_SQL_BLOCKED);
        }
        if (self::found('/\binto\s+(outfile|dumpfile)\b/i', $code)) {
            throw new RuntimeException(self::INTO_BLOCKED);
        }
    }

    private static function assertQueryDoesNotWrite(string $code): void
    {
        if (self::found('/\b('.self::WRITE_WORDS.')\b/i', $code)) {
            throw new RuntimeException(self::WRITE_BLOCKED);
        }
        // SELECT ... INTO tabela cria tabela (INTO OUTFILE já caiu no ponto cego).
        if (self::found('/\binto\b/i', $code)) {
            throw new RuntimeException(self::INTO_BLOCKED);
        }
    }

    /**
     * EXPLAIN sem ANALYZE só planeja: quem decide é o banco. Com ANALYZE o
     * comando explicado EXECUTA — então precisa ser uma consulta que não escreve.
     */
    private static function assertExplainDoesNotWrite(string $rest): void
    {
        $hasStatement = self::found('/\b('.self::STATEMENT_WORDS.')\b/i', $rest, $m, PREG_OFFSET_CAPTURE);
        $options = $hasStatement ? substr($rest, 0, $m[1][1]) : $rest;

        if (! self::found('/\banaly[sz]e\b/i', $options)) {
            return;
        }

        if (! $hasStatement || ! in_array(strtolower($m[1][0]), self::QUERY_KEYWORDS, true)) {
            throw new RuntimeException(self::EXPLAIN_ANALYZE_BLOCKED);
        }

        self::assertQueryDoesNotWrite(substr($rest, $m[1][1] + strlen($m[1][0])));
    }

    /**
     * SQLite: `PRAGMA nome = valor` e `PRAGMA nome(valor)` ALTERAM (inclusive
     * `query_only`, que é uma das travas). Passa só a forma de consulta.
     */
    private static function assertReadPragma(string $rest): void
    {
        if (! preg_match('/^\s*(?:[A-Za-z_][\w$]*\s*\.\s*)?([A-Za-z_]\w*)\s*(\(.*\))?[\s;]*$/s', $rest, $m)) {
            throw new RuntimeException(self::PRAGMA_BLOCKED); // tem `=`, ou não é um PRAGMA simples
        }
        $name = strtolower($m[1]);
        $hasArgument = ($m[2] ?? '') !== '';

        if (in_array($name, self::PRAGMA_ACTIONS, true)
            || ($hasArgument && ! in_array($name, self::PRAGMA_WITH_ARGUMENT, true))) {
            throw new RuntimeException(self::PRAGMA_BLOCKED);
        }
    }

    /**
     * preg_match que FALHA FECHADO: erro do PCRE (limite de backtrack/JIT num
     * texto enorme) devolveria `false`, que um `if` leria como "não achei".
     *
     * @param  array<int|string,mixed>|null  $m
     */
    private static function found(string $pattern, string $subject, ?array &$m = null, int $flags = 0): bool
    {
        $result = preg_match($pattern, $subject, $m, $flags);
        if ($result === false) {
            throw new RuntimeException(self::WRITE_BLOCKED);
        }

        return $result === 1;
    }
}
