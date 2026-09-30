<?php

namespace Mad\Security;

use Mad\Registry\ControlRegistry;

/**
 * O que o CÓDIGO de uma classe declara — lido por tokens, sem carregar a classe.
 *
 * Existe pela mesma razão do {@see \Mad\Registry\AbstractClassRegistry::discover()}:
 * carregar uma tela com erro de compilação (assinatura incompatível com a
 * classe-mãe, sintaxe) é FATAL — não é Throwable, não há catch. Quem precisa
 * saber algo de MUITAS telas de uma vez (o menu, a busca, a tela de login) não
 * pode carregá-las: uma única tela quebrada derrubaria todas as páginas do app.
 * Aqui o arquivo é só lido, e a tela quebrada fica fora do ar sozinha, quando
 * (e se) alguém a abrir.
 *
 * Consumidores: {@see PermissionGate::isPublicPage()} (a marca
 * `protected static bool $public = true` e a porta `publicGuard()`),
 * {@see PublicLoginLinks} (o `$loginLink`) e {@see inherits()} (a página do
 * site público, na porta de login e no canal reativo do site).
 *
 * O arquivo da classe é achado sem autoload: a classe já carregada (reflection),
 * o índice do {@see ControlRegistry} (também montado por tokens), a tela plana
 * `app/control/<Nome>.php` e o mapa do Composer (`findFile()` não inclui nada).
 *
 * Só conta o que está DECLARADO: propriedade estática com valor literal no corpo
 * da própria classe. Comentário, texto, variável estática de método, parâmetro,
 * valor calculado — nada disso é declaração.
 */
final class ClassSource
{
    /** Arquivo maior que isto não é tela gerada — nem é lido. */
    private const MAX_BYTES = 1048576;

    /** Nome de classe (basename ou qualificado). Barra, ponto, byte nulo: fora. */
    private const NAME_RE = '/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/';

    /**
     * @var array<string, array{mtime:int, size:int, skip:array<string,true>, decls:?list<array<string,mixed>>}>
     *      memo por arquivo, invalidado por data/tamanho (no Octane vive o worker inteiro)
     */
    private static array $memo = [];

    /**
     * Declaração da classe (ou trait) lida do arquivo dela; null quando não dá
     * para achar o arquivo sem carregar a classe, ou quando ele não a declara.
     *
     * `$mustContain`: filtro barato — arquivo que não cita o texto nem é
     * tokenizado (o menu pergunta de toda tela; quase nenhuma tem a marca).
     *
     * @return array{fqcn:string, class:string, kind:string, namespace:string, extends:string,
     *               parent:string, abstract:bool, traits:list<string>, props:array<string,mixed>,
     *               methods:array<string, array{static:bool, public:bool}>}|null
     */
    public static function of(string $class, string $mustContain = ''): ?array
    {
        $where = self::locate($class);
        if ($where === null) {
            return null;
        }

        foreach (self::declarationsIn($where['path'], $mustContain) as $decl) {
            if (strcasecmp($decl['fqcn'], $where['fqcn']) === 0) {
                return $decl;
            }
        }

        return null;
    }

    /**
     * A classe chega em `$ancestor` pela herança DECLARADA? Sem carregar nada.
     *
     * Sobe pelo `extends` escrito no código (resolvido pelo namespace e pelos
     * `use` de cada arquivo), classe a classe, até achar `$ancestor`. É a
     * pergunta que a porta de login faz sobre a classe da requisição ("é página
     * do site?") ANTES de saber se ela abre: com `is_subclass_of` a tela era
     * carregada ali, e uma tela com erro de compilação (fatal, sem catch)
     * devolvia 500 ao visitante anônimo em vez do desvio para o login.
     *
     * Só `true` quando a herança é PROVADA. Qualquer dúvida é `false`: arquivo
     * não achado, trait/interface no caminho, linhagem longa demais — e classe
     * do FRAMEWORK (`Mad\…`) que não é `$ancestor`: ela encerra a subida, como
     * em {@see PermissionGate}, sem ter o arquivo lido nem ser carregada. Hoje
     * nenhuma classe do framework estende `MadSitePage` (há teste travando
     * isso); se um dia estender, ela só conta aqui depois de carregada.
     *
     * Classe já carregada (em qualquer ponto da subida) responde exato, por
     * `is_a` — que não dispara autoload para uma classe que já está na memória.
     */
    public static function inherits(string $class, string $ancestor): bool
    {
        $ancestor = ltrim(trim($ancestor), '\\');
        $where = $ancestor === '' ? null : self::locate($class);
        if ($where === null) {
            return false;
        }

        $current = $where['fqcn'];
        for ($depth = 0; $depth <= 12; $depth++) {
            if (class_exists($current, false)) {
                // A própria classe pedida não é "descendente de si mesma".
                return $depth === 0
                    ? is_subclass_of($current, $ancestor)
                    : is_a($current, $ancestor, true);
            }

            $decl = self::of($current);
            if ($decl === null || $decl['kind'] !== 'class' || $decl['parent'] === '') {
                return false;
            }

            $parent = $decl['parent'];
            if (strcasecmp($parent, $ancestor) === 0) {
                return true;
            }
            if (str_starts_with($parent, 'Mad\\') && !class_exists($parent, false)) {
                return false;
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Arquivo e FQCN da classe — sem autoload, sem incluir nada.
     *
     * @return array{fqcn:string, path:string}|null
     */
    public static function locate(string $class): ?array
    {
        // Só espaço sai: byte nulo e afins reprovam no padrão abaixo.
        $class = ltrim(trim($class, " \t\r\n"), '\\');
        if ($class === '' || strlen($class) > 512 || !preg_match(self::NAME_RE, $class)) {
            return null;
        }

        // Já carregada: o arquivo de onde veio. Um alias (UserForm → App\Control\Iam\UserForm)
        // devolve a classe real.
        if (class_exists($class, false) || trait_exists($class, false)) {
            $ref = new \ReflectionClass($class);
            $file = $ref->getFileName();

            return is_string($file) && $file !== '' ? ['fqcn' => $ref->getName(), 'path' => $file] : null;
        }

        $qualified = str_contains($class, '\\');

        // Tela do app: pelo índice de controls (basename, ou FQCN com o módulo
        // certo ou errado — o fallback do framework resolve pelo basename) e pela
        // tela plana (página sem módulo = classe global em app/control/<Nome>.php).
        if (!$qualified || str_starts_with($class, 'App\\Control\\')) {
            $found = self::locateControl($class);
            if ($found !== null) {
                return $found;
            }
        }

        // Resto (framework, bibliotecas, testes): o mapa do Composer.
        if (class_exists(\Composer\Autoload\ClassLoader::class, false)
            && method_exists(\Composer\Autoload\ClassLoader::class, 'getRegisteredLoaders')) {
            foreach (\Composer\Autoload\ClassLoader::getRegisteredLoaders() as $loader) {
                $file = $loader->findFile($class);
                if (is_string($file) && $file !== '') {
                    return ['fqcn' => $class, 'path' => $file];
                }
            }
        }

        return null;
    }

    /** @return array{fqcn:string, path:string}|null */
    private static function locateControl(string $class): ?array
    {
        if (!class_exists(ControlRegistry::class)) {
            return null;
        }
        $dir = ControlRegistry::layerDir();
        if ($dir === null || !is_dir($dir)) {
            return null;
        }

        $fqcn = ControlRegistry::lookup($class);
        if ($fqcn !== null && str_starts_with($fqcn, 'App\\Control\\')) {
            $path = $dir.'/'.str_replace('\\', '/', substr($fqcn, strlen('App\\Control\\'))).'.php';
            if (is_file($path)) {
                return ['fqcn' => $fqcn, 'path' => $path];
            }
        }

        // FQCN que o índice não guarda (trait, por exemplo): o caminho PSR-4 da camada.
        if (str_starts_with($class, 'App\\Control\\')) {
            $path = $dir.'/'.str_replace('\\', '/', substr($class, strlen('App\\Control\\'))).'.php';
            if (is_file($path)) {
                return ['fqcn' => $class, 'path' => $path];
            }
        }

        $pos = strrpos($class, '\\');
        $short = $pos === false ? $class : substr($class, $pos + 1);
        $flat = $dir.'/'.$short.'.php';
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $short) && is_file($flat)) {
            return ['fqcn' => $short, 'path' => $flat];
        }

        return null;
    }

    /**
     * Declarações de um arquivo, memoizadas por data/tamanho.
     *
     * @return list<array<string,mixed>>
     */
    public static function declarationsIn(string $path, string $mustContain = ''): array
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false || $size > self::MAX_BYTES) {
            return [];
        }

        $memo = self::$memo[$path] ?? null;
        if ($memo === null || $memo['mtime'] !== $mtime || $memo['size'] !== $size) {
            $memo = ['mtime' => $mtime, 'size' => $size, 'skip' => [], 'decls' => null];
        }
        if ($memo['decls'] !== null) {
            return $memo['decls'];
        }
        if ($mustContain !== '' && isset($memo['skip'][$mustContain])) {
            return [];
        }

        $source = @file_get_contents($path);
        if (!is_string($source)) {
            return [];
        }
        if ($mustContain !== '' && !str_contains($source, $mustContain)) {
            $memo['skip'][$mustContain] = true;
            self::$memo[$path] = $memo;

            return [];
        }

        $memo['decls'] = self::parse($source);
        self::$memo[$path] = $memo;

        return $memo['decls'];
    }

    /** Esquece o que foi lido (testes que reescrevem arquivos no mesmo segundo). */
    public static function flush(): void
    {
        self::$memo = [];
    }

    /**
     * Classes e traits declarados no código-fonte. Só lê tokens.
     *
     * Por declaração: `fqcn`, `kind` (class|trait), `extends` (como escrito),
     * `parent` (FQCN resolvido pelo namespace e pelos `use`), `abstract`,
     * `traits` (FQCNs usados no corpo), `props` (propriedades ESTÁTICAS com
     * valor literal) e `methods` (nome em minúsculas → static/public).
     *
     * @return list<array<string,mixed>>
     */
    public static function parse(string $source): array
    {
        $tokens = @token_get_all($source);
        if (!is_array($tokens)) {
            return [];
        }
        // Sem espaço e comentário: o resto do parser olha só o que conta.
        $tokens = array_values(array_filter($tokens, fn ($t) => !is_array($t)
            || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));

        $decls = [];
        $namespace = '';
        $nsDepth = 0;
        $uses = [];
        $current = null;   // índice em $decls da classe aberta
        $classDepth = null;
        $depth = 0;
        $inString = false;
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $id = is_array($t) ? $t[0] : null;

            // Texto com interpolação ("…$x…", heredoc, crase): nada ali dentro
            // é declaração — nem o `$public` que aparecer no meio do texto.
            if ($t === '"' || $t === '`') {
                $inString = !$inString;
                continue;
            }
            if ($id === T_START_HEREDOC) {
                $inString = true;
                continue;
            }
            if ($id === T_END_HEREDOC) {
                $inString = false;
                continue;
            }
            if ($inString) {
                continue;
            }

            if ($t === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;
                continue;
            }
            if ($t === '}') {
                $depth--;
                if ($current !== null && $depth < $classDepth) {
                    $current = null;
                    $classDepth = null;
                }
                if ($depth < $nsDepth) {
                    // fim do `namespace X { … }`
                    $namespace = '';
                    $nsDepth = 0;
                    $uses = [];
                }
                continue;
            }

            if ($current === null && $id === T_NAMESPACE) {
                $name = '';
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j])) {
                        $name .= $tokens[$j][1];
                    }
                }
                $namespace = trim($name, '\\');
                $uses = [];
                if (($tokens[$j] ?? null) === '{') {
                    $nsDepth = $depth + 1;
                    $i = $j - 1; // o `{` entra no laço e abre a profundidade
                } else {
                    $nsDepth = $depth;
                    $i = $j;
                }
                continue;
            }

            // `use` de arquivo (import). Closure (`function () use ($x)`) fica de fora.
            if ($current === null && $id === T_USE && $depth === $nsDepth) {
                if (($tokens[$i + 1] ?? null) !== '(') {
                    $i = self::readImports($tokens, $i + 1, $uses);
                }
                continue;
            }

            if ($current === null && ($id === T_CLASS || $id === T_TRAIT)) {
                $before = $tokens[$i - 1] ?? null;
                if (is_array($before) && in_array($before[0], [T_DOUBLE_COLON, T_NEW], true)) {
                    continue; // Foo::class / classe anônima
                }
                $name = $tokens[$i + 1] ?? null;
                if (!is_array($name) || $name[0] !== T_STRING) {
                    continue;
                }
                $abstract = false;
                for ($k = $i - 1; $k >= 0 && is_array($tokens[$k])
                    && in_array($tokens[$k][0], [T_ABSTRACT, T_FINAL, T_READONLY], true); $k--) {
                    $abstract = $abstract || $tokens[$k][0] === T_ABSTRACT;
                }
                $extends = '';
                for ($j = $i + 2; $j < $count && $tokens[$j] !== '{'; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_EXTENDS && is_array($tokens[$j + 1] ?? null)) {
                        $extends = (string) $tokens[$j + 1][1];
                    }
                }
                $decls[] = [
                    'fqcn'      => $namespace === '' ? $name[1] : $namespace.'\\'.$name[1],
                    'class'     => $name[1],
                    'kind'      => $id === T_TRAIT ? 'trait' : 'class',
                    'namespace' => $namespace,
                    'extends'   => ltrim($extends, '\\'),
                    'parent'    => $extends === '' ? '' : self::resolveName($extends, $namespace, $uses),
                    'abstract'  => $abstract,
                    'traits'    => [],
                    'props'     => [],
                    'methods'   => [],
                ];
                $current = count($decls) - 1;
                $classDepth = $depth + 1;
                $i = $j - 1; // o `{` da classe entra no laço e abre a profundidade
                continue;
            }

            if ($current === null || $depth !== $classDepth) {
                continue;
            }

            // ── corpo da classe ──────────────────────────────────────────────
            if ($id === T_USE) {
                // `use TraitA, TraitB;` — e o bloco `{ … }` de conflito, onde
                // `A::x as publicGuard;` cria um método com outro nome.
                for ($j = $i + 1; $j < $count && $tokens[$j] !== ';' && $tokens[$j] !== '{'; $j++) {
                    $x = $tokens[$j];
                    if (is_array($x) && in_array($x[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
                        $decls[$current]['traits'][] = self::resolveName($x[1], $namespace, $uses);
                    }
                }
                if (($tokens[$j] ?? null) === '{') {
                    for ($j++; $j < $count && $tokens[$j] !== '}'; $j++) {
                        if (!is_array($tokens[$j]) || $tokens[$j][0] !== T_AS) {
                            continue;
                        }
                        $k = $j + 1;
                        $public = true;
                        if (is_array($tokens[$k] ?? null) && in_array($tokens[$k][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                            $public = $tokens[$k][0] === T_PUBLIC;
                            $k++;
                        }
                        if (is_array($tokens[$k] ?? null) && $tokens[$k][0] === T_STRING) {
                            // Estático ou não, só o trait sabe: conta como "tem o método".
                            $decls[$current]['methods'][strtolower($tokens[$k][1])] = ['static' => true, 'public' => $public];
                        }
                    }
                }
                $i = $j; // o `;` ou o `}` do bloco (o `{` foi consumido junto: profundidade intacta)
                continue;
            }

            if ($id === T_FUNCTION) {
                $k = $i + 1;
                if (($tokens[$k] ?? null) === '&') {
                    $k++;
                }
                $fn = $tokens[$k] ?? null;
                if (!is_array($fn) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $fn[1])) {
                    continue; // closure em valor padrão (PHP 8.5)
                }
                $static = false;
                $public = true;
                for ($m = $i - 1; $m >= 0 && is_array($tokens[$m]); $m--) {
                    $mod = $tokens[$m][0];
                    if ($mod === T_STATIC) {
                        $static = true;
                    } elseif ($mod === T_PRIVATE || $mod === T_PROTECTED) {
                        $public = false;
                    } elseif (!in_array($mod, [T_PUBLIC, T_ABSTRACT, T_FINAL], true)) {
                        break;
                    }
                }
                $decls[$current]['methods'][strtolower($fn[1])] = ['static' => $static, 'public' => $public];
                continue;
            }

            if ($id !== T_VARIABLE || ($tokens[$i + 1] ?? null) !== '=' || !self::isStaticProperty($tokens, $i)) {
                continue;
            }
            $end = $i + 2;
            $value = self::literal($tokens, $end);
            // Só o literal inteiro: `'a' . X`, constante, heredoc… ficam de fora.
            if ($value['ok'] && in_array($tokens[$end] ?? null, [';', ','], true)) {
                $decls[$current]['props'][substr($t[1], 1)] = $value['value'];
            }
        }

        return $decls;
    }

    /**
     * Lê um `use` de arquivo a partir de `$i` (depois do `use`) e registra os
     * apelidos em `$uses` (minúsculas → FQCN). Devolve o índice do `;`.
     *
     * @param  list<mixed>  $tokens
     * @param  array<string,string>  $uses
     */
    private static function readImports(array $tokens, int $i, array &$uses): int
    {
        $prefix = '';
        $name = '';
        $alias = null;
        $expectAlias = false;
        $skipItem = false;

        $flush = function () use (&$prefix, &$name, &$alias, &$skipItem, &$uses): void {
            $full = trim(($prefix !== '' ? $prefix.'\\' : '').trim($name, '\\'), '\\');
            if ($full !== '' && !$skipItem) {
                $pos = strrpos($full, '\\');
                $key = $alias ?? ($pos === false ? $full : substr($full, $pos + 1));
                $uses[strtolower($key)] = $full;
            }
            $name = '';
            $alias = null;
            $skipItem = false;
        };

        for ($count = count($tokens); $i < $count; $i++) {
            $x = $tokens[$i];
            if ($x === ';') {
                $flush();

                return $i;
            }
            if ($x === '{') {
                $prefix = trim($name, '\\');
                $name = '';
                continue;
            }
            if ($x === '}' || $x === ',') {
                $flush();
                continue;
            }
            if (!is_array($x)) {
                continue;
            }
            if ($x[0] === T_FUNCTION || $x[0] === T_CONST) {
                $skipItem = true; // `use function` / `use const`: não é classe
            } elseif ($x[0] === T_AS) {
                $expectAlias = true;
            } elseif (in_array($x[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NS_SEPARATOR], true)) {
                if ($expectAlias) {
                    $alias = $x[1];
                    $expectAlias = false;
                } else {
                    $name .= $x[1];
                }
            }
        }

        return $i;
    }

    /** Nome de classe como escrito no código → FQCN (regras do PHP para classe). */
    private static function resolveName(string $name, string $namespace, array $uses): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        if (strncasecmp($name, 'namespace\\', 10) === 0) {
            $rest = substr($name, 10);

            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
        }
        $parts = explode('\\', $name);
        $first = strtolower($parts[0]);
        if (isset($uses[$first])) {
            $parts[0] = $uses[$first];

            return implode('\\', $parts);
        }

        // Nome de classe não qualificado NÃO cai no namespace global.
        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }

    /**
     * O `$variavel` do índice é uma propriedade ESTÁTICA da classe? Volta pelos
     * modificadores e pelo tipo até o começo da declaração.
     *
     * @param  list<mixed>  $tokens
     */
    private static function isStaticProperty(array $tokens, int $i): bool
    {
        $modifiers = [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_READONLY, T_FINAL, T_ABSTRACT, T_STRING, T_ARRAY,
            T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];
        foreach (['T_PUBLIC_SET', 'T_PROTECTED_SET', 'T_PRIVATE_SET'] as $asymmetric) {
            if (defined($asymmetric)) {
                $modifiers[] = constant($asymmetric);
            }
        }

        $static = false;
        for ($j = $i - 1; $j >= 0; $j--) {
            $t = $tokens[$j];
            if (is_array($t) && $t[0] === T_STATIC) {
                $static = true;
                continue;
            }
            if ((is_array($t) && in_array($t[0], $modifiers, true))
                || in_array($t, ['?', '|', '&'], true)
                || (is_array($t) && in_array($t[1], ['?', '|', '&'], true))) {
                continue;
            }

            return $static; // `;`, `{`, `}`, `,` ou `(` (parâmetro): fim da declaração
        }

        return $static;
    }

    /**
     * Valor literal a partir de `$i` (avança `$i` até depois dele): texto,
     * número, true/false/null ou array desses. Qualquer outra coisa → ok=false.
     *
     * @param  list<mixed>  $tokens
     * @return array{ok:bool, value:mixed}
     */
    private static function literal(array $tokens, int &$i): array
    {
        $t = $tokens[$i] ?? null;
        $fail = ['ok' => false, 'value' => null];

        if (is_array($t) && $t[0] === T_CONSTANT_ENCAPSED_STRING) {
            $i++;
            $raw = substr($t[1], 1, -1);

            return ['ok' => true, 'value' => $t[1][0] === "'"
                ? str_replace(['\\\\', "\\'"], ['\\', "'"], $raw)
                : stripcslashes($raw)];
        }
        if (is_array($t) && in_array($t[0], [T_LNUMBER, T_DNUMBER], true)) {
            $i++;

            return ['ok' => true, 'value' => (int) $t[1]];
        }
        if ($t === '-' && is_array($tokens[$i + 1] ?? null) && in_array($tokens[$i + 1][0], [T_LNUMBER, T_DNUMBER], true)) {
            $i += 2;

            return ['ok' => true, 'value' => -(int) $tokens[$i - 1][1]];
        }
        if (is_array($t) && $t[0] === T_STRING && in_array(strtolower($t[1]), ['true', 'false', 'null'], true)) {
            $i++;

            return ['ok' => true, 'value' => ['true' => true, 'false' => false, 'null' => null][strtolower($t[1])]];
        }

        $close = null;
        if ($t === '[') {
            $close = ']';
            $i++;
        } elseif (is_array($t) && $t[0] === T_ARRAY && ($tokens[$i + 1] ?? null) === '(') {
            $close = ')';
            $i += 2;
        }
        if ($close === null) {
            return $fail;
        }

        $array = [];
        while (($tokens[$i] ?? $close) !== $close) {
            $first = self::literal($tokens, $i);
            if (!$first['ok']) {
                return $fail;
            }
            if (is_array($tokens[$i] ?? null) && $tokens[$i][0] === T_DOUBLE_ARROW) {
                $i++;
                $value = self::literal($tokens, $i);
                if (!$value['ok'] || !(is_string($first['value']) || is_int($first['value']))) {
                    return $fail;
                }
                $array[$first['value']] = $value['value'];
            } else {
                $array[] = $first['value'];
            }
            if (($tokens[$i] ?? null) === ',') {
                $i++;
            } elseif (($tokens[$i] ?? null) !== $close) {
                return $fail;
            }
        }
        $i++; // o fechamento

        return ['ok' => true, 'value' => $array];
    }
}
