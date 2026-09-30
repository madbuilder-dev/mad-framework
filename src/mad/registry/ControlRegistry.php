<?php

namespace Mad\Registry;

/**
 * ControlRegistry — resolve o IDENTIFICADOR de um control (tela) para o FQCN real,
 * varrendo `App\Control\**` recursivamente. Estende {@see AbstractClassRegistry}
 * (esqueleto comum com Service/ModelRegistry).
 *
 * IDENTIFICADOR = BASENAME da classe (o último segmento do FQCN), NÃO um token
 * concatenado por domínio. Controls vivem em `App\Control\<Dominio>\<Nome>` e são
 * despachados/permissionados pelo próprio <Nome>:
 *
 *   App\Control\Iam\UserForm        -> "UserForm"
 *   App\Control\Ged\DocumentList    -> "DocumentList"
 *   App\Control\Comm\MessageList    -> "MessageList"
 *
 * O basename é o que vive em `mad_iam_program.controller`, `menu.xml`, nas rotas
 * (`->defaults('class')`), em `config/mad.php` e nas chaves do {@see \Mad\Security\PermissionGate}.
 * Exige-se que o basename seja GLOBALMENTE ÚNICO entre todos os controls — o
 * {@see buildMap()} lança se houver colisão, e o guard-rail
 * {@see \Tests\Feature\BasenameUniquenessTest} trava isso na CI.
 *
 * Índice: basename -> FQCN (memoizado). Consumido por global_controls.php (faz
 * `class_exists('UserForm')` resolver via {@see lookup()} + class_alias).
 *
 * Home em Mad\Registry (prefixo PSR-4 já existente). Sem ponte legada, sem token de
 * domínio, sem caso "bare" especial — todo control é endereçado igual, pelo basename.
 */
class ControlRegistry extends AbstractClassRegistry
{
    /** @var array<string,string>|null basename => FQCN (cache PRÓPRIO — redeclarar!). */
    protected static ?array $map = null;

    protected static function baseNamespace(): string
    {
        return 'App\\Control\\';
    }

    protected static function relativeDir(): string
    {
        return 'app/control';
    }

    /**
     * Dir físico de `app/control` (mesma resolução do discover: `base_path()`
     * quando bootado, senão sobe de `__DIR__`). Público porque o
     * {@see ControlNamespaceFallback} precisa alcançar o control FLAT — que fica
     * fora do índice de propósito (classe global, sem namespace).
     */
    public static function layerDir(): ?string
    {
        return static::layerPath();
    }

    /**
     * IDENTIFICADOR de runtime de um control: o basename da classe. Aceita o FQCN
     * (`App\Control\<Dominio>\<Nome>`) e devolve `<Nome>`. Qualquer outra string
     * (basename já pronto, nome de serviço, etc.) volta inalterada. Puro (sem
     * discovery) — usado no PermissionGate para normalizar o get_class() do
     * componente (wire) ao identificador.
     */
    public static function idFor(string $name): string
    {
        $name = ltrim(trim($name), '\\');
        if (str_starts_with($name, 'App\\Control\\')) {
            $pos = strrpos($name, '\\');

            return $pos === false ? $name : substr($name, $pos + 1);
        }

        return $name; // já é basename / serviço / outro
    }

    /**
     * Constrói o índice basename => FQCN. O basename é o contrato de endereçamento,
     * portanto DEVE ser único; colisão é erro de programação (dois controls com o
     * mesmo nome de classe em domínios diferentes) e aborta o boot.
     *
     * @param  list<array{fqcn:string,domain:string,class:string}>  $discovered
     * @return array<string,string>
     */
    protected static function buildMap(array $discovered): array
    {
        $map = [];
        foreach ($discovered as $d) {
            $base = $d['class'];
            if (isset($map[$base]) && $map[$base] !== $d['fqcn']) {
                throw new \LogicException(sprintf(
                    'Colisão de basename de control "%s": %s vs %s. O identificador de '
                    .'control é o basename — deve ser globalmente único entre App\\Control\\**.',
                    $base,
                    $map[$base],
                    $d['fqcn']
                ));
            }
            $map[$base] = $d['fqcn'];
        }

        return $map;
    }
}
