<?php

namespace Mad\Registry;

/**
 * AbstractClassRegistry — base comum dos registries que resolvem um identificador
 * (token curto, basename ou nome legado) para o FQCN real de uma classe que vive
 * sob um namespace-raiz por domínio: `App\Control\**`, `App\Service\**`,
 * `App\Models\**`.
 *
 * Extrai o esqueleto que {@see ControlRegistry}, {@see ServiceRegistry} e
 * {@see \Mad\Database\ModelRegistry} repetiam quase byte-a-byte: `discover()`,
 * `lookup()`, `map()`, `flush()`, o resolver de path (`base_path` quando bootado,
 * senão sobe de `__DIR__`) e o `appRoot()` de 8 níveis. Um fix cross-cutting (já
 * aconteceu com o "não-memoiza-vazio" pré-boot) passa a viver num só lugar.
 *
 * Template methods que a subclasse implementa / sobrescreve:
 *   - baseNamespace(): prefixo FQCN da camada, ex. `App\Control\` (com a barra).
 *   - relativeDir():   dir relativo à raiz da app, ex. `app/control`.
 *   - buildMap():      índice token|basename|legacy => FQCN específico da camada.
 *   - accepts():       (opcional) filtro de descoberta — default `class_exists`.
 *                      Service amplia p/ interface|trait; Model restringe a
 *                      subclasses de Model (ignora Concerns\*, enums, ...).
 *   - classExists():   (opcional) predicado "o input já é classe válida" usado no
 *                      curto-circuito de resolve() — default `class_exists`.
 *   - emptyMap():      (opcional) mapa devolvido quando a descoberta vem vazia
 *                      (pré-boot) — default `[]`; camadas com LEGACY devolvem-no.
 *
 * ⚠️ IMPORTANTE: cada subclasse DEVE redeclarar `protected static ?array $map`.
 * Sem isso o PHP COMPARTILHA a propriedade estática herdada entre todas as
 * subclasses — `flush()` de uma zeraria o cache das outras, e os mapas colidiriam.
 * É o gotcha clássico de static property herdada.
 */
abstract class AbstractClassRegistry
{
    /** @var array<string,string>|null índice memoizado (REDECLARAR na subclasse!). */
    protected static ?array $map = null;

    /** Camadas com discover() em andamento (keyed por classe — storage único). */
    private static array $building = [];

    /** Prefixo FQCN da camada, ex. `App\Control\` (com a barra final). */
    abstract protected static function baseNamespace(): string;

    /** Dir da camada relativo à raiz da app, ex. `app/control`. */
    abstract protected static function relativeDir(): string;

    /**
     * Constrói o índice token|basename|legacy => FQCN a partir do descoberto.
     *
     * @param  list<array{fqcn:string,domain:string,class:string}>  $discovered
     * @return array<string,string>
     */
    abstract protected static function buildMap(array $discovered): array;

    /**
     * LEGADO — não é mais chamado pelo discover() (ver acceptsLoaded()): o
     * class_exists com autoload daqui incluía os arquivos via composer
     * (include cru, sem dedup) e explodia em reentrância. Mantido só por
     * compat com subclasses antigas que o sobrescrevem.
     */
    protected static function accepts(string $fqcn): bool
    {
        return class_exists($fqcn);
    }

    /**
     * Filtro de descoberta pós-require_once: o FQCN derivado do path declara
     * mesmo uma classe carregável? SEM autoload (o arquivo já foi incluído
     * pelo discover) — class_exists com autoload aqui re-dispararia o include
     * cru do composer para flats. Substitui accepts() no fluxo do discover.
     */
    protected static function acceptsLoaded(string $fqcn): bool
    {
        return class_exists($fqcn, false);
    }

    /**
     * Predicado "o input já é uma classe válida", usado em resolve() para
     * curto-circuitar antes do índice. Default `class_exists`.
     */
    protected static function classExists(string $name): bool
    {
        return class_exists($name);
    }

    /** Mapa devolvido quando a descoberta vem vazia (pré-boot). Default vazio. */
    protected static function emptyMap(): array
    {
        return [];
    }

    /** Resolve para FQCN, ou null. Aceita FQCN válido, token, basename ou legado. */
    public static function resolve(string $name): ?string
    {
        $name = ltrim(trim($name), '\\');
        if ($name === '') {
            return null;
        }
        if (static::classExists($name)) {
            return $name;
        }

        return static::lookup($name);
    }

    /** Resolve SÓ pelo índice (sem class_exists no input). Use no autoloader. */
    public static function lookup(string $name): ?string
    {
        $name = ltrim(trim($name), '\\');
        if ($name === '') {
            return null;
        }

        $map = static::map();
        if (isset($map[$name])) {
            return $map[$name];
        }

        // FQCN legado cujo basename é conhecido (ex "App\Foo\Bar" -> "Bar").
        $pos = strrpos($name, '\\');
        if ($pos !== false) {
            $base = substr($name, $pos + 1);
            if (isset($map[$base])) {
                return $map[$base];
            }
        }

        return null;
    }

    /** @return array<string,string> token|basename|legacy => FQCN (memoizado) */
    public static function map(): array
    {
        if (static::$map !== null) {
            return static::$map;
        }

        // Guard de reentrância: o discover() inclui os arquivos da camada e um
        // deles pode disparar autoload (ex.: control flat `X extends Y` da
        // mesma camada) que volta em lookup()->map() ANTES do índice existir.
        // Sem o guard, o discover roda de novo no meio do primeiro e re-inclui
        // arquivo já carregado ("Cannot redeclare class"). Reentrada devolve o
        // emptyMap SEM memoizar; o autoloader da camada tem fallback por path.
        if (!empty(self::$building[static::class])) {
            return static::emptyMap();
        }
        self::$building[static::class] = true;
        try {
            $discovered = static::discover();
        } finally {
            unset(self::$building[static::class]);
        }
        if ($discovered === []) {
            // Não memoiza vazio: um lookup PRÉ-BOOT (ex.: PHPUnit coletando a
            // suíte, `base_path` ainda indisponível) envenenaria o cache e
            // quebraria toda resolução em runtime. Re-tenta quando a app subir.
            return static::emptyMap();
        }

        return static::$map = static::buildMap($discovered);
    }

    /** Limpa o cache memoizado (uso em testes que mexem na estrutura). */
    public static function flush(): void
    {
        static::$map = null;
    }

    /**
     * Varre relativeDir() recursivamente e devolve os FQCNs aceitos, já fatiados
     * em domínio (concatenado, sem barras) + classe.
     *
     * @return list<array{fqcn:string,domain:string,class:string}>
     */
    protected static function discover(): array
    {
        $base = static::layerPath();
        if ($base === null || ! is_dir($base)) {
            return [];
        }

        $ns  = static::baseNamespace();
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $rel  = substr($file->getPathname(), strlen($base) + 1, -4); // "Iam/UserForm"
            $rel  = str_replace(['/', '\\'], '\\', $rel);
            $fqcn = $ns.$rel;
            // ⚠️ NÃO carregar o arquivo aqui.
            //
            // Isto fazia `require_once` em TODO .php da camada só para montar o
            // índice. O `try/catch` abaixo dava a impressão de proteger, mas
            // erro de COMPILAÇÃO (covariância, redeclaração, sintaxe) é fatal —
            // não é Throwable, não há catch possível. Uma única página com o
            // retorno trocado derrubava o app INTEIRO: incidente 25/jul/2026,
            //
            //   Declaration of RelatorioChamadosMes::show(...): Response|MadResponse
            //   must be compatible with MadComponent::show(array $params = []): void
            //
            // e o Teste Online respondeu 500 em toda página, com o migrate
            // falhando junto.
            //
            // O índice precisa só do NOME declarado — isso o tokenizer entrega
            // sem executar uma linha. O arquivo é carregado quando (e se) a
            // classe for de fato usada, então um arquivo quebrado tira do ar
            // apenas a própria tela.
            $simbolo = static::declaredSymbol($file->getPathname());
            // Mesma regra do antigo `acceptsLoaded()`: entra no índice só o
            // arquivo cujo FQCN DECLARADO bate com o caminho, e só o TIPO que a
            // camada aceita (o ServiceRegistry aceita interface/trait — services
            // podem ser contratos; o resto só classe). Flat (classe global)
            // continua fora — o autoloader da camada tem fallback por path.
            // A diferença é que agora isso é decidido LENDO, não executando.
            if ($simbolo === null || $simbolo['fqcn'] !== $fqcn || !static::acceptsKind($simbolo['kind'])) {
                continue;
            }
            $segments = explode('\\', $rel);
            $class    = array_pop($segments);
            $domain   = implode('', $segments); // "Iam" | "IamSub" | "" (flat)

            $out[] = ['fqcn' => $fqcn, 'domain' => $domain, 'class' => $class];
        }

        return $out;
    }

    /**
     * Tipos de símbolo que ESTA camada indexa. Default: só classe — era o que o
     * antigo `acceptsLoaded()` fazia (`class_exists`). O ServiceRegistry
     * sobrescreve para aceitar interface e trait.
     *
     * @param 'class'|'interface'|'trait'|'enum' $kind
     */
    protected static function acceptsKind(string $kind): bool
    {
        return $kind === 'class';
    }

    /**
     * Símbolo declarado no arquivo — `['fqcn' => ..., 'kind' => ...]` — lido por
     * tokenização, sem incluir, sem executar, sem risco de fatal.
     *
     * Devolve null quando o arquivo não declara nada indexável (helper solto,
     * retorno de array, etc.).
     *
     * @return array{fqcn:string,kind:string}|null
     */
    protected static function declaredSymbol(string $path): ?array
    {
        $src = @file_get_contents($path);
        if ($src === false || $src === '') {
            return null;
        }

        $tokens = @token_get_all($src);
        if (!is_array($tokens)) {
            return null;
        }

        $ns = '';
        $n  = count($tokens);

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (!is_array($t)) {
                continue;
            }

            if ($t[0] === T_NAMESPACE) {
                $buf = '';
                for ($j = $i + 1; $j < $n; $j++) {
                    $x = $tokens[$j];
                    if (is_string($x) && ($x === ';' || $x === '{')) {
                        break;
                    }
                    if (is_array($x) && in_array($x[0], [T_STRING, T_NS_SEPARATOR], true)) {
                        $buf .= $x[1];
                    } elseif (is_array($x) && defined('T_NAME_QUALIFIED') && $x[0] === T_NAME_QUALIFIED) {
                        $buf .= $x[1];
                    }
                }
                $ns = trim($buf, '\\');
                continue;
            }

            $kind = match (true) {
                $t[0] === T_CLASS                                  => 'class',
                $t[0] === T_INTERFACE                              => 'interface',
                $t[0] === T_TRAIT                                  => 'trait',
                defined('T_ENUM') && $t[0] === constant('T_ENUM')  => 'enum',
                default                                            => null,
            };
            if ($kind === null) {
                continue;
            }

            // `Foo::class` e classe anônima (`new class`) não declaram nome.
            $anterior = $tokens[$i - 1] ?? null;
            if (is_array($anterior) && in_array($anterior[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            for ($j = $i + 1; $j < $n; $j++) {
                $x = $tokens[$j];
                if (is_array($x) && $x[0] === T_STRING) {
                    return ['fqcn' => $ns === '' ? $x[1] : $ns . '\\' . $x[1], 'kind' => $kind];
                }
                if (is_string($x) && $x === '{') {
                    break;
                }
            }
        }

        return null;
    }

    /** Caminho absoluto da camada (base_path quando bootado; senão sobe de __DIR__). */
    protected static function layerPath(): ?string
    {
        $rel = static::relativeDir();

        // App bootada: base_path é autoritativo (qualquer layout de install).
        if (function_exists('base_path')) {
            try {
                $p = base_path($rel);
                if (is_dir($p)) {
                    return $p;
                }
            } catch (\Throwable) {
                // app ainda não bootada (coleta de suíte PHPUnit) — fallback abaixo
            }
        }
        // Pré-boot: sobe de __DIR__ até a raiz da app (tem artisan + app/).
        $root = self::appRoot();

        return $root !== null ? $root.'/'.$rel : null;
    }

    /** Raiz da aplicação Laravel (ancestral de __DIR__ com artisan + app/). */
    private static function appRoot(): ?string
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d.'/app') && is_file($d.'/artisan')) {
                return $d;
            }
            $parent = \dirname($d);
            if ($parent === $d) {
                break;
            }
            $d = $parent;
        }

        return null;
    }
}
