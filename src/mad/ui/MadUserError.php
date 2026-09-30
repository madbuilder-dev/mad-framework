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
 * /var/www/html/app/database/mad.sqlite, SQL: update …)"). O handler do wire já
 * esconde o detalhe fora do APP_DEBUG; o catch local furava isso.
 *
 * Regra: erro TÉCNICO — banco (QueryException/PDOException), erro do PHP
 * (\Error: TypeError, ArgumentCountError…), warning convertido (ErrorException)
 * ou mensagem com SQLSTATE — vira o texto amigável e o detalhe vai SÓ para o
 * log. Exceção de domínio, lançada de propósito com texto para o usuário
 * ("Pedido faturado não pode ser excluído"), passa como está.
 *
 *     } catch (\Throwable $e) {
 *         return MadToast::danger(MadUserError::message($e, mad_t('mad.error.delete_failed'), static::class));
 *     }
 */
final class MadUserError
{
    /**
     * Texto seguro para mostrar ao usuário.
     *
     * @param string|null $friendly texto amigável para erro técnico (null = o genérico)
     * @param string      $context  de onde veio (classe::método) — só no log
     */
    public static function message(\Throwable $e, ?string $friendly = null, string $context = ''): string
    {
        if (! self::isTechnical($e)) {
            return $e->getMessage();
        }
        self::log($e, $context);

        return $friendly ?? self::friendly();
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

    /** Erro técnico (banco, PHP, warning) — nunca vai cru para a tela. */
    public static function isTechnical(\Throwable $e): bool
    {
        for ($x = $e; $x !== null; $x = $x->getPrevious()) {
            if ($x instanceof \PDOException                       // QueryException estende PDOException
                || $x instanceof \Illuminate\Database\QueryException
                || $x instanceof \Error                           // TypeError, ArgumentCountError…
                || $x instanceof \ErrorException) {               // warning/notice convertidos
                return true;
            }
        }

        // Exceção de domínio que embrulhou a mensagem do banco no próprio texto.
        return str_contains($e->getMessage(), 'SQLSTATE[');
    }

    private static function log(\Throwable $e, string $context): void
    {
        $line = '[MadUserError]' . ($context !== '' ? ' ' . $context : '')
            . ' ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();

        try {
            if (function_exists('app') && app()->bound('log')) {
                \Illuminate\Support\Facades\Log::error($line, ['exception' => $e]);

                return;
            }
        } catch (\Throwable) {
            // log indisponível: cai no error_log
        }
        @error_log($line);
    }
}
