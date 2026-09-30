<?php

namespace Mad\Registry;

/**
 * Último recurso de autoload para control endereçado com o NAMESPACE ERRADO.
 *
 * O identificador de um control neste framework é o BASENAME — é assim que ele
 * vive em `menu.xml`, nas rotas, no `PermissionGate` e no {@see ControlRegistry}
 * ("todo control é endereçado igual, pelo basename"). Quase todo call-site já
 * reduz FQCN a basename via {@see ControlRegistry::idFor()}, então um namespace
 * errado passa despercebido... EXCETO onde o framework instancia direto
 * (`new $gridClass()` do `manageRow`, `teleport`, `manageCard`): ali o PHP tenta
 * carregar o FQCN literal e mata a request.
 *
 * Incidente 25/jul/2026 (Mad Desk Oficial, produção): a tela `FormDefinitionsForm`
 * ganhou um `use App\Control\Forms\FormDefinitionsList;` — namespace inventado, a
 * página não tem módulo, então o control real é a classe GLOBAL
 * `FormDefinitionsList` (`app/control/FormDefinitionsList.php`, sem `namespace`).
 * Nada acusou: `php -l` passa, o deploy passa, a tela abre. Só ao clicar em
 * **Salvar** o `->manageRow($id, FormDefinitionsList::class)` estourava
 *
 *   Class "App\Control\Forms\FormDefinitionsList" not found
 *
 * O `app/madlib/global_controls.php` do app tinha o fallback por basename, mas
 * saía cedo justamente para `App\Control\**` ("PSR-4 cuida dos reais") — e o
 * PSR-4 não cuida de um namespace que não existe. Este autoloader fecha o furo
 * pelo lado do pacote (chega em app já publicado via update do framework).
 *
 * Contrato: registrado por ÚLTIMO (append). Só age quando todo mundo já falhou,
 * portanto não intercepta nada que resolva normalmente. Resolve o basename pelo
 * índice do registry e, se não achar, pelo control FLAT (`app/control/X.php`, sem
 * namespace — é o layout de página sem módulo). Achou: `class_alias` e a request
 * segue viva; registra um aviso no log de erro porque o código continua errado e
 * deve ser corrigido.
 */
final class ControlNamespaceFallback
{
    private static bool $registered = false;

    /** Nomes em resolução — impede recursão se o alvo também não carregar. */
    private static array $inFlight = [];

    /** Avisos já emitidos (1× por classe, senão polui o log a cada request). */
    private static array $avisado = [];

    /**
     * Registra o fallback no fim da pilha de autoload. Idempotente.
     */
    public static function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        // prepend=false: só depois do PSR-4 do composer e do global_controls do app.
        spl_autoload_register([self::class, 'load'], true, false);
    }

    /**
     * @internal handler do spl_autoload — público só por causa do callable.
     */
    public static function load(string $class): void
    {
        if (! str_starts_with($class, 'App\\Control\\')) {
            return;
        }
        if (isset(self::$inFlight[$class])) {
            return;
        }
        // Outro autoloader da pilha (o global_controls do app faz o mesmo) pode
        // ter criado o alias durante a nossa própria resolução — aliasar de novo
        // é "Cannot redeclare class".
        if (class_exists($class, false)) {
            return;
        }

        $pos = strrpos($class, '\\');
        $base = $pos === false ? $class : substr($class, $pos + 1);
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $base) !== 1) {
            return;
        }

        self::$inFlight[$class] = true;
        try {
            $real = self::resolveBasename($base);
            if ($real === null || $real === $class || class_exists($class, false)) {
                return;
            }
            class_alias($real, $class);
            self::avisa($class, $real);
        } finally {
            unset(self::$inFlight[$class]);
        }
    }

    /**
     * Classe real por trás do basename: primeiro o índice de `App\Control\**`,
     * depois o control flat (página sem módulo → classe no namespace global).
     */
    private static function resolveBasename(string $base): ?string
    {
        $fqcn = ControlRegistry::lookup($base);
        if ($fqcn !== null && class_exists($fqcn)) {
            return $fqcn;
        }

        // Flat: `app/control/X.php` declara a classe GLOBAL X (sem `namespace`),
        // que o discover() deixa fora do índice de propósito.
        if (class_exists($base, false)) {
            return $base;
        }
        $dir = ControlRegistry::layerDir();
        if ($dir !== null) {
            $file = $dir . '/' . $base . '.php';
            // require_once deduplica — nunca redeclara.
            if (is_file($file)) {
                require_once $file;
                if (class_exists($base, false)) {
                    return $base;
                }
            }
        }

        return null;
    }

    private static function avisa(string $pedido, string $real): void
    {
        if (isset(self::$avisado[$pedido])) {
            return;
        }
        self::$avisado[$pedido] = true;
        error_log(sprintf(
            '[mad] control "%s" nao existe — resolvido pelo basename para "%s". '
            .'O identificador de control e o BASENAME: remova o `use %s;` '
            .'(o gerador emite esse import comentado de proposito).',
            $pedido,
            $real,
            $pedido
        ));
    }

    /** Só para teste: esquece os avisos já emitidos. */
    public static function flushAvisos(): void
    {
        self::$avisado = [];
    }
}
