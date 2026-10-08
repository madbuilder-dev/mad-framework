<?php

namespace Mad\Ui;

/**
 * MadUserError — o texto que VAI PARA A TELA quando uma ação do usuário falha
 * com exceção capturada pelo próprio componente.
 *
 * Por que existe: componentes que capturavam a exceção e montavam o diálogo
 * ou o toast com `$e->getMessage()` mostravam ao usuário final o erro cru do
 * banco — SQL, nome da conexão e o CAMINHO do arquivo do banco ("SQLSTATE[HY000]:
 * General error: 1 no such column: ordem (Connection: assistec, Database:
 * /var/www/html/app/database/mad.sqlite, SQL: update …)"). Numa tela que grava
 * usuário o SQL traz também o HASH DA SENHA. O handler do wire já esconde o
 * detalhe fora do APP_DEBUG; o catch local furava isso.
 *
 * Regra:
 *  • erro TÉCNICO — banco (QueryException/PDOException), erro do PHP (\Error:
 *    TypeError, ArgumentCountError…), warning convertido (ErrorException),
 *    exceção de biblioteca de terceiros (e-mail, HTTP, arquivo, Eloquent) ou
 *    mensagem com SQLSTATE — nunca vai cru para a tela. O usuário lê uma frase
 *    que diz o que fazer quando dá para saber (`MadDbErrorMessage`: "O campo
 *    E-mail já está sendo utilizado.") e o aviso genérico quando não dá. O
 *    detalhe vai SÓ para o log, sem segredo (`MadErrorRedactor`).
 *  • exceção de DOMÍNIO, lançada de propósito com texto para o usuário
 *    ("Pedido faturado não pode ser excluído"), passa como está.
 *
 *     } catch (\Throwable $e) {
 *         return MadMessage::error('Erro ao salvar', MadUserError::message($e, null, static::class . '::onSave'));
 *     }
 *
 * `APP_DEBUG` não muda o que este texto mostra: a tela do usuário é a mesma no
 * Teste Online e no app publicado.
 */
final class MadUserError
{
    /**
     * Texto seguro para mostrar ao usuário.
     *
     * @param string|null          $friendly texto para erro técnico. `null` = a frase específica
     *                                       da recusa do banco quando ela é conhecida, senão o genérico.
     *                                       Informado, vale para TODO erro técnico (a tela não quer dizer
     *                                       qual foi a recusa).
     * @param string               $context  de onde veio (classe::método) — só no log
     * @param array<string,string> $labels   coluna => rótulo do campo, para a frase citar o campo.
     *                                       Sem isto, usa os campos do formulário da requisição.
     */
    public static function message(\Throwable $e, ?string $friendly = null, string $context = '', array $labels = []): string
    {
        if (! self::isTechnical($e)) {
            return $e->getMessage();
        }

        // Registro que não existe (ou que o escopo do usuário esconde) não é
        // falha do sistema: é resposta. Não vai para o log como erro.
        if (self::isNotFound($e)) {
            return $friendly ?? MadDbErrorMessage::notFound();
        }

        self::log($e, $context);

        if ($friendly !== null) {
            return $friendly;
        }

        try {
            $specific = MadDbErrorMessage::for($e, $labels !== [] ? $labels : self::requestLabels());
        } catch (\Throwable) {
            $specific = null;
        }

        return $specific ?? self::friendly();
    }

    /**
     * Texto para TELA PÚBLICA (login, cadastro, redefinição de senha): erro
     * técnico vira sempre o aviso genérico, nunca a frase específica.
     *
     * "Já existe um registro com este e-mail" numa tela sem login é um oráculo:
     * diz a quem não entrou quais e-mails têm conta. Exceção de domínio passa
     * como está — o que ela pode revelar é decisão de quem a lançou.
     */
    public static function publicMessage(\Throwable $e, string $context = ''): string
    {
        return self::message($e, self::friendly(), $context);
    }

    /**
     * Só registra (sem segredo) uma falha que a tela decidiu NÃO mostrar —
     * envio de e-mail que não desfaz o cadastro, resposta idêntica de uma tela
     * que não pode revelar se a conta existe.
     */
    public static function report(\Throwable $e, string $context = ''): void
    {
        self::log($e, $context);
    }

    /** "Não foi possível concluir a operação. Tente de novo ou avise o administrador." */
    public static function friendly(): string
    {
        try {
            return mad_t('mad.error.friendly');
        } catch (\Throwable) {
            return 'Não foi possível concluir a operação. Tente de novo ou avise o administrador.';
        }
    }

    /** Erro técnico (banco, PHP, warning, biblioteca) — nunca vai cru para a tela. */
    public static function isTechnical(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException                       // QueryException estende PDOException
                || $x instanceof \Illuminate\Database\QueryException
                || $x instanceof \Error                           // TypeError, ArgumentCountError…
                || $x instanceof \ErrorException                  // warning/notice convertidos
                || self::isLibraryException($x)) {
                return true;
            }
        }

        // Exceção de domínio que embrulhou a mensagem do banco no próprio texto.
        return str_contains($e->getMessage(), 'SQLSTATE[');
    }

    /**
     * Exceção cuja CLASSE é de uma biblioteca de terceiros (`vendor/`, fora do
     * próprio framework): "Connection could not be established with host
     * smtp.x:587", "No query results for model [App\Models\Iam\User] 5", "Add
     * [x] to fillable property…". O texto foi escrito para quem programa.
     *
     * Vale a classe, não o lugar do `throw`: `throw_if($x, \RuntimeException::class,
     * 'Pedido faturado…')` nasce dentro do `vendor/` e é de domínio.
     *
     * Ficam de fora as exceções de biblioteca cujo texto É para o usuário:
     * validação, `abort(403, '…')`, autorização.
     */
    private static function isLibraryException(\Throwable $x): bool
    {
        if ($x instanceof \Illuminate\Validation\ValidationException
            || $x instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
            || $x instanceof \Illuminate\Auth\Access\AuthorizationException
            || $x instanceof \Illuminate\Auth\AuthenticationException) {
            return false;
        }

        try {
            $file = (new \ReflectionClass($x))->getFileName();
        } catch (\Throwable) {
            return false;
        }
        if (! is_string($file) || $file === '') {
            return false; // classe interna do PHP (\Exception, \RuntimeException…)
        }

        $file = str_replace('\\', '/', $file);

        // Relativo à raiz do app: um app instalado DENTRO de uma pasta chamada
        // `vendor` não vira "biblioteca" por causa do caminho do servidor.
        try {
            $base = function_exists('base_path') ? rtrim(str_replace('\\', '/', (string) base_path()), '/') : '';
        } catch (\Throwable) {
            $base = '';
        }
        if ($base !== '' && str_starts_with($file, $base . '/')) {
            $file = substr($file, strlen($base));
        }

        return str_contains($file, '/vendor/') && ! str_contains($file, '/vendor/madbuilder/');
    }

    /** `findOrFail()` que não achou — em qualquer ponto da cadeia. */
    private static function isNotFound(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \Illuminate\Database\RecordsNotFoundException) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rótulos dos campos do formulário desta requisição (coluna => rótulo). É o
     * que a própria tela desenhou; serve só para a frase citar o campo.
     *
     * @return array<string,string>
     */
    private static function requestLabels(): array
    {
        $labels = [];
        try {
            $schema = \Mad\Form\MadFormRegistry::fromRequest();
            foreach (is_array($schema) ? $schema : [] as $name => $props) {
                $label = is_array($props) ? trim((string) ($props['label'] ?? '')) : '';
                if ($label !== '' && is_string($name)) {
                    $labels[$name] = $label;
                }
            }
        } catch (\Throwable) {
            // sem formulário na requisição: a frase sai sem o nome do campo
        }

        return $labels;
    }

    private static function log(\Throwable $e, string $context): void
    {
        // Mensagem REMONTADA sem os valores sensíveis e trace SEM argumentos: a
        // exceção em si não vai no contexto do log — o formatador escreveria a
        // mensagem crua (SQL com o hash da senha) e os argumentos de cada
        // chamada (a senha digitada).
        $line = '[MadUserError]' . ($context !== '' ? ' ' . $context : '')
            . ' ' . MadErrorRedactor::describe($e)
            . ' @ ' . MadErrorRedactor::relativePath($e->getFile()) . ':' . $e->getLine();

        for ($prev = $e->getPrevious(), $depth = 0; $prev !== null && $depth < 5; $prev = $prev->getPrevious(), $depth++) {
            // A causa de uma QueryException é o erro do driver, que já está na linha.
            if ($depth === 0 && $e instanceof \Illuminate\Database\QueryException) {
                continue;
            }
            $line .= ' | causa: ' . MadErrorRedactor::describe($prev);
        }

        try {
            if (function_exists('app') && app()->bound('log')) {
                \Illuminate\Support\Facades\Log::error($line, ['trace' => MadErrorRedactor::trace($e)]);

                return;
            }
        } catch (\Throwable) {
            // log indisponível: cai no error_log
        }
        @error_log($line);
    }
}
