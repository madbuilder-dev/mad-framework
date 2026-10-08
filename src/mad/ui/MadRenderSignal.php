<?php

namespace Mad\Ui;

use Symfony\Component\HttpFoundation\Response;

/**
 * MadRenderSignal — "esta tela montou" / "esta tela quebrou", dito FORA do
 * corpo da resposta, para o teste de tela do agente de IA no Teste Online.
 *
 * Por que existe: a tela que falha ao montar (o `mount()` ou a view lança) não
 * responde 5xx. O `MadComponent::show()` captura a exceção e devolve **200**
 * com o cartão de erro no lugar do conteúdo. Para o usuário está certo — ele vê
 * o erro dentro do app, com menu e caminho de volta. Para quem TESTA a tela por
 * fora, status 200 sem nenhum sinal é "funcionou": o agente lia a tela quebrada
 * como boa. O cabeçalho que já existia (`X-Mad-Render-Error`, no
 * `bootstrap/app.php`) só acompanha resposta 5xx.
 *
 * Dois cabeçalhos, na resposta do CONTEÚDO da tela (o pedido com
 * `X-Mad-Partial`, que é o que monta a tela):
 *
 *  • `X-Mad-Render: ok`    — a tela montou e desenhou sem exceção;
 *  • `X-Mad-Render: error` + `X-Mad-Render-Error: <classe>: <mensagem>` — caiu
 *    no cartão de erro.
 *
 * A navegação direta (o casco) NÃO leva nenhum dos dois: ela não monta a tela,
 * e montá-la só para sinalizar custaria uma montagem a mais por abertura (e
 * rodaria duas vezes o que o `mount()` grava). Quem quer o sinal pede o
 * conteúdo, que é o mesmo pedido que o navegador faz logo em seguida.
 *
 * Quem recebe: SÓ o app que roda dentro do Teste Online (`MAD_FRAMEABLE`, a
 * mesma variável que libera o `<iframe>` e que o cabeçalho das respostas 5xx já
 * usa). Num app publicado nenhum dos dois cabeçalhos existe — o corpo da
 * resposta é o mesmo nos dois casos.
 *
 * O que vai no texto: classe e mensagem, sem o que não pode sair do servidor —
 * valor nenhum de SQL (nem os que não são segredo: nome, CPF e e-mail de
 * cliente também são dado), hash de senha, credencial, nem caminho absoluto.
 * Parte do `MadErrorRedactor` (o texto de log da #134) e aperta mais, porque
 * cabeçalho viaja até o navegador.
 */
final class MadRenderSignal
{
    /** Estado da montagem da tela: {@see self::OK} ou {@see self::FAILED}. */
    public const STATE = 'X-Mad-Render';

    /** Classe e mensagem da exceção, sem segredo. O mesmo nome usado nas respostas 5xx. */
    public const ERROR = 'X-Mad-Render-Error';

    public const OK = 'ok';

    public const FAILED = 'error';

    /**
     * Teto do texto. Cabeçalho de vários KB (o SQL inteiro de um erro de banco)
     * derruba a resposta no proxy.
     */
    private const MAX = 300;

    /** O app roda dentro do Teste Online? (mesmo gate do `<iframe>` e do cabeçalho das 5xx) */
    public static function enabled(): bool
    {
        try {
            return function_exists('env') && (bool) env('MAD_FRAMEABLE', false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Carimba a resposta do conteúdo de uma tela.
     *
     * @param  \Throwable|null  $failure  a exceção que virou cartão de erro, ou
     *                                    null quando a tela montou
     */
    public static function stamp(Response $response, ?\Throwable $failure = null): Response
    {
        if (! self::enabled()) {
            return $response;
        }

        if ($failure === null) {
            $response->headers->set(self::STATE, self::OK);

            return $response;
        }

        $response->headers->set(self::STATE, self::FAILED);
        $response->headers->set(self::ERROR, self::line($failure));

        return $response;
    }

    /**
     * Classe e mensagem da exceção num texto que cabe num cabeçalho: uma linha,
     * ASCII, curto, sem segredo, sem valor de SQL e sem caminho absoluto.
     *
     * Exceção sem mensagem (`abort(503)`) fica só com a classe.
     */
    public static function line(\Throwable $e): string
    {
        $class = $e::class;
        if (str_contains($class, '@anonymous')) {
            // O nome de uma classe anônima traz o caminho do arquivo que a declara.
            $class = 'class@anonymous';
        }

        $text = rtrim($class . ': ' . self::message($e), ': ');
        $text = self::withoutAbsolutePaths($text);
        $text = str_replace(MadErrorRedactor::MASK, '***', $text);

        try {
            $text = \Illuminate\Support\Str::ascii($text);
        } catch (\Throwable) {
            // segue com o texto como está: o filtro abaixo tira o que não é ASCII
        }

        // Quebra de linha faz o PHP descartar o cabeçalho; byte fora do ASCII
        // chega trocado no navegador.
        $text = trim((string) preg_replace('/[^\x21-\x7E]+/', ' ', $text));

        return substr($text, 0, self::MAX);
    }

    // ── Internos ────────────────────────────────────────────────────────

    /** Mensagem sem segredo e sem valor de SQL. */
    private static function message(\Throwable $e): string
    {
        if ($e instanceof \Illuminate\Database\QueryException) {
            $driver = $e->getPrevious() instanceof \Throwable
                ? $e->getPrevious()->getMessage()
                : self::beforeConnectionDetails($e->getMessage());

            // Sem conexão, servidor, porta nem arquivo do banco: do SQL fica só
            // o começo da instrução (o que ela é e, quando escreve, em qual tabela).
            return self::withoutRowValues(MadErrorRedactor::text($driver))
                . ' (SQL: ' . self::statementHead((string) $e->getSql()) . ')';
        }

        // A ViewException copia a mensagem da exceção original e acrescenta
        // " (View: caminho)". O caminho é a pista que diz QUAL view corrigir:
        // sai antes da limpeza (que corta um SQL embutido até o último
        // parêntese e o levaria junto) e volta no fim.
        $message = $e->getMessage();
        $view    = '';
        if (preg_match('/^(.*) (\(View: [^()]+\))$/s', $message, $m)) {
            [$message, $view] = [$m[1], ' ' . $m[2]];
        }

        // `MadErrorRedactor::text()` já reduz ao começo da instrução o SQL de
        // uma QueryException copiada para dentro de outra exceção.
        return self::withoutRowValues(MadErrorRedactor::text($message)) . $view;
    }

    /**
     * Valores de linha que o banco anexa ao erro, de QUALQUER coluna:
     * `Key (email)=(ana@exemplo.com) already exists` (PostgreSQL) e
     * `Duplicate entry 'ana@exemplo.com' for key …` (MySQL/MariaDB). O texto de
     * log só esconde os de coluna sensível.
     */
    private static function withoutRowValues(string $text): string
    {
        $text = preg_replace('/Key \(([^)]*)\)=\(.*?\)(?= (?:already exists|is not present|is still referenced|conflicts))/s', 'Key ($1)=(***)', $text) ?? $text;
        $text = preg_replace("/Duplicate entry '.*?' for key/s", "Duplicate entry '***' for key", $text) ?? $text;

        // "(Connection: mysql, Host: 10.0.0.5, Port: 3306, Database: loja, SQL: select …)"
        // de uma QueryException embutida: fica só o SQL já reduzido.
        return preg_replace('/\(Connection: [^()]*?, SQL: /', '(SQL: ', $text) ?? $text;
    }

    /** `insert into "t" …`, `update "t" …`, `delete from "t" …`, `select …` — sem coluna e sem valor. */
    private static function statementHead(string $sql): string
    {
        $sql = trim($sql);
        if (preg_match('/^\s*((?:insert|replace)\b.*?\binto\s+[^\s(]+|update\s+[^\s(]+|delete\s+from\s+[^\s(]+)/is', $sql, $m)) {
            return (string) preg_replace('/\s+/', ' ', $m[1]) . ' …';
        }

        return (preg_match('/^\s*([a-z]+)/i', $sql, $m) ? $m[1] : '') . ' …';
    }

    /** O que vem antes do " (Connection: …" que o Laravel anexa. */
    private static function beforeConnectionDetails(string $message): string
    {
        $pos = strpos($message, ' (Connection: ');

        return $pos === false ? $message : substr($message, 0, $pos);
    }

    /**
     * Caminho de arquivo sem a árvore do servidor: o que é do projeto fica
     * relativo à raiz dele (`resources/views/pedido.blade.php`); o que é de
     * fora (PHP, sistema) fica só com o nome do arquivo.
     */
    private static function withoutAbsolutePaths(string $text): string
    {
        try {
            $base = function_exists('base_path') ? rtrim((string) base_path(), '/\\') : '';
        } catch (\Throwable) {
            $base = '';
        }
        if ($base !== '') {
            $text = str_replace([$base . '/', $base . '\\', $base], '', $text);
        }

        // Não casa com URL (`https://host/a/b.json`) nem com caminho relativo
        // (`app/control/Tela.php`): só o que começa na raiz do disco.
        return preg_replace('~(?<![\w:/.\-])(?:/[\w.\-]+){2,}/([\w.\-]+\.[A-Za-z0-9]{1,8})\b~', '$1', $text) ?? $text;
    }
}
