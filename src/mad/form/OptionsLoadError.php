<?php

namespace Mad\Form;

use Mad\Support\MadDebug;

/**
 * Falha ao carregar as opções de um campo que lê do banco
 * (`<mad-dbcombo-field>`, `<mad-dbradio-field>`, `<mad-dbcheckbox-group-field>`,
 * `<mad-dbselect-check-field>`, `<mad-dbsort-list-field>`, `<mad-dbchecklist-field>`,
 * as buscas `<mad-dbunique-search-field>`/`<mad-dbmulti-search-field>`/
 * `<mad-dbentry-field>` e os endpoints AJAX que recarregam essas listas).
 *
 * Por que existe: todos esses pontos carregavam as opções dentro de um
 * `try { … } catch (\Throwable $e) { $options = []; }`. Model que não resolve,
 * coluna de `display`/`order-by` que não existe, SQL inválido, conexão errada —
 * qualquer um virava um campo VAZIO, sem log e sem aviso, nem com `APP_DEBUG`.
 * Nem quem montou a tela nem o agente de IA tinham por onde achar a causa
 * (fórum #41: "o DB Combo não lista mais os dados").
 *
 * O contrato agora:
 *   - o campo continua vazio para o usuário final (nada de SQL ou caminho na tela);
 *   - a falha vai para o log do app com campo, model, display e a mensagem
 *     ({@see report()}) — no máximo UMA linha por campo por request, para uma
 *     grade de detalhe com 50 linhas não escrever 50 vezes o mesmo erro;
 *   - com `APP_DEBUG` ligado (o mesmo gate dos detalhes técnicos de erro do
 *     app: {@see MadDebug::appDebug()}), {@see notice()} devolve um texto curto
 *     que o componente mostra no próprio campo.
 *
 * Sem erro nada muda: tabela vazia continua dando campo vazio, sem mensagem.
 */
final class OptionsLoadError
{
    /** Binding `scoped` do container: o Octane e o queue worker descartam a cada request/job. */
    private const SEEN_BINDING = 'mad.options_load_error.seen';

    /** Prefixo do aviso no campo (só com APP_DEBUG). */
    public const NOTICE_PREFIX = 'Erro ao carregar opções: ';

    /** Tamanho máximo da mensagem curta exibida no campo. */
    private const NOTICE_MAX = 240;

    /** @var array<string, true> reserva quando não há container (CLI isolado) */
    private static array $seenFallback = [];

    /**
     * Registra a falha e devolve o aviso para o campo (null sem APP_DEBUG).
     *
     * @param array{field?:string, model?:string, database?:string, display?:string, order_by?:string} $context
     */
    public static function handle(\Throwable $e, string $component, array $context = []): ?string
    {
        self::report($e, $component, $context);

        return self::notice($e);
    }

    /**
     * Mesmo que {@see handle()}, para um aviso montado aqui (sem exceção de
     * verdade por trás): o log não leva um stack trace que só apontaria para
     * esta classe.
     */
    private static function handleNotice(string $message, string $component, array $context): ?string
    {
        $e = new \RuntimeException($message);
        self::report($e, $component, $context, false);

        return self::notice($e);
    }

    /**
     * Grava a falha no log do app (`Log::warning`), uma vez por campo por request.
     *
     * @param array{field?:string, model?:string, database?:string, display?:string, order_by?:string} $context
     */
    public static function report(\Throwable $e, string $component, array $context = [], bool $withTrace = true): void
    {
        $field = (string) ($context['field'] ?? '');
        $model = (string) ($context['model'] ?? '');

        if (! self::firstTime($component . '|' . $field . '|' . $model)) {
            return;
        }

        // Campo do formulário: identifica pela tag + name. Endpoint AJAX
        // (cascata, busca) não conhece o name — o token só traz model/display.
        if ($field !== '' && preg_match('/^mad-[a-z0-9-]+$/', $component)) {
            $who = sprintf('<%s name="%s">', $component, $field);
        } elseif ($field !== '') {
            $who = sprintf('%s, campo "%s"', $component, $field);
        } else {
            $who = $component . ($model !== '' ? sprintf(' (model %s)', $model) : '');
        }
        $line = '[mad] ' . $who . ': erro ao carregar as opções — ' . self::shortMessage($e);

        $ctx = ['component' => $component];
        foreach (['field', 'model', 'database', 'display', 'order_by'] as $k) {
            if (isset($context[$k]) && (string) $context[$k] !== '') {
                $ctx[$k] = (string) $context[$k];
            }
        }
        $ctx['error']   = get_class($e);
        $ctx['message'] = $e->getMessage();
        if ($withTrace) {
            $ctx['exception'] = $e; // o Monolog grava classe, arquivo:linha e o trace
        }

        try {
            \Illuminate\Support\Facades\Log::warning($line, $ctx);
        } catch (\Throwable $logFailure) {
            // Sem logger (CLI isolado): ainda deixa rastro.
            @error_log($line);
        }
    }

    /**
     * Texto curto para mostrar no próprio campo — só com APP_DEBUG ligado.
     * Fora do debug devolve null e o campo fica exatamente como antes (vazio).
     */
    public static function notice(\Throwable $e): ?string
    {
        if (! MadDebug::appDebug()) {
            return null;
        }

        return self::NOTICE_PREFIX . self::shortMessage($e);
    }

    /**
     * Mensagem de uma linha, sem o SQL e os dados de conexão que a
     * QueryException do Laravel anexa (`… (Connection: x, Host: …, SQL: …)`).
     * O texto completo vai só para o log.
     */
    public static function shortMessage(\Throwable $e): string
    {
        $msg = $e->getMessage();

        if ($e instanceof \Illuminate\Database\QueryException && $e->getPrevious() instanceof \Throwable) {
            $msg = $e->getPrevious()->getMessage();
        } elseif (($cut = strpos($msg, ' (Connection: ')) !== false) {
            $msg = substr($msg, 0, $cut);
        }

        $msg = trim((string) preg_replace('/\s+/u', ' ', $msg));
        if ($msg === '') {
            $msg = get_class($e);
        }

        return mb_strimwidth($msg, 0, self::NOTICE_MAX, '…', 'UTF-8');
    }

    /**
     * Colunas do `display` que a consulta não trouxe (saída `$missing` do
     * {@see ModelOptionsLoader::itemsFromQuery()}). Não é exceção — as opções
     * saem com o rótulo em branco —, mas é o mesmo erro de configuração (coluna
     * renomeada, id-ref cru `{entity_column_id:…}` no display) e merece o mesmo
     * rastro. Lista vazia = nada a avisar (null).
     *
     * @param array<int, string> $columns
     * @param array{field?:string, model?:string, database?:string, display?:string, order_by?:string} $context
     */
    public static function handleMissing(array $columns, string $component, array $context = []): ?string
    {
        if ($columns === []) {
            return null;
        }

        $list    = implode('", "', $columns);
        $display = (string) ($context['display'] ?? '');

        return self::handleNotice(
            (count($columns) === 1
                ? "a coluna \"{$list}\" do display não existe na tabela"
                : "as colunas \"{$list}\" do display não existem na tabela")
            . ($display !== '' ? " (display=\"{$display}\")" : '')
            . '; as opções ficam sem texto.',
            $component,
            $context
        );
    }

    /**
     * Nome do model para o log quando a fonte é um `:query` (o `model=` vem vazio).
     *
     * @param mixed $query
     */
    public static function sourceOf($query, string $model = ''): string
    {
        if ($model !== '') {
            return $model;
        }
        try {
            if ($query instanceof \Illuminate\Database\Eloquent\Builder) {
                return get_class($query->getModel());
            }
            if ($query instanceof \Illuminate\Database\Query\Builder && is_string($query->from)) {
                return $query->from;
            }
        } catch (\Throwable) {
            // sem nome — o log sai sem model
        }

        return '';
    }

    private static function firstTime(string $key): bool
    {
        $store = self::store();
        if ($store === null) {
            if (isset(self::$seenFallback[$key])) {
                return false;
            }
            self::$seenFallback[$key] = true;

            return true;
        }

        if (isset($store->items[$key])) {
            return false;
        }
        $store->items[$key] = true;

        return true;
    }

    private static function store(): ?object
    {
        try {
            if (! function_exists('app')) {
                return null;
            }
            $app = app();
            if (! $app->bound(self::SEEN_BINDING)) {
                $app->scoped(self::SEEN_BINDING, static fn () => new class {
                    /** @var array<string, true> */
                    public array $items = [];
                });
            }

            return $app->make(self::SEEN_BINDING);
        } catch (\Throwable) {
            return null;
        }
    }
}
