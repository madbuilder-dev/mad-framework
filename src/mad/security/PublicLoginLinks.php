<?php

namespace Mad\Security;

use Mad\Registry\ControlRegistry;
use Mad\Routing\MadRoutes;

/**
 * Links das telas públicas na tela de LOGIN do app — o "Mostrar na tela de
 * login" da página pública do MadBuilder 4.0 (é por ele que o cliente acha a
 * "Área do Cliente").
 *
 * A tela pede o link na própria classe, ao lado da marca de tela pública:
 *
 *   protected static bool $public = true;
 *   protected static string $loginLink = 'Área do Cliente';
 *
 *   // com ícone (Lucide) e posição entre os links:
 *   protected static array $loginLink = ['label' => 'Área do Cliente', 'icon' => 'user-round', 'order' => 1];
 *
 * Sem `$public = true` não há link — o login não anuncia tela que pede login.
 * Página do site público (`extends MadSitePage`) é pública pelo tipo e também
 * pode pedir o link. Ordem: `order` (quem não declara vai depois), depois o texto.
 *
 * A descoberta LÊ o código das telas (tokenizer) e não carrega classe nenhuma
 * — o mesmo cuidado do {@see \Mad\Registry\AbstractClassRegistry::discover()}:
 * incluir uma tela com erro de compilação é fatal (não há catch), e o login do
 * app inteiro não pode cair por causa de uma página quebrada. Por isso o valor
 * precisa ser literal (texto, ou array de texto/número), que é o que o PHP
 * aceita como valor padrão de propriedade de qualquer forma.
 *
 * O link mora na classe: sobrevive à regeneração do app e ao cache de rotas
 * (nada em config/, no menu ou em routes/). O texto passa por `__()`, então uma
 * tradução JSON do app (`lang/en.json`) troca o rótulo por idioma.
 */
final class PublicLoginLinks
{
    /** Arquivo maior que isto não é tela gerada — nem é lido. */
    private const MAX_BYTES = 1048576;

    /** @var array<string, array{mtime:int, size:int, found:list<array{class:string, label:string, icon:string, order:?int}>}> */
    private static array $memo = [];

    /** Pasta das telas no lugar de app/control (testes). */
    private static ?string $directory = null;

    /**
     * Links a mostrar no login, já ordenados. Nunca lança: na dúvida, lista vazia.
     *
     * @return list<array{class:string, label:string, icon:string, url:string}>
     */
    public static function all(): array
    {
        try {
            $found = self::discover(self::$directory ?? ControlRegistry::layerDir());
        } catch (\Throwable $e) {
            if (function_exists('report')) {
                report($e);
            }

            return [];
        }

        usort($found, function (array $a, array $b): int {
            $byOrder = ($a['order'] ?? PHP_INT_MAX) <=> ($b['order'] ?? PHP_INT_MAX);

            return $byOrder !== 0 ? $byOrder : strnatcasecmp(self::sortKey($a['label']), self::sortKey($b['label']));
        });

        $links = [];
        foreach ($found as $link) {
            try {
                $url = MadRoutes::urlFor($link['class']);
            } catch (\Throwable) {
                continue;
            }
            $label = function_exists('__') ? __($link['label']) : $link['label'];
            $links[] = [
                'class' => $link['class'],
                'label' => is_string($label) && $label !== '' ? $label : $link['label'],
                'icon'  => $link['icon'],
                'url'   => $url,
            ];
        }

        return $links;
    }

    /**
     * Telas (arquivos `.php` da pasta, recursivo) que pedem o link e são públicas.
     *
     * @return list<array{class:string, label:string, icon:string, order:?int}>
     */
    public static function discover(?string $directory): array
    {
        if ($directory === null || !is_dir($directory)) {
            return [];
        }

        $found = [];
        $seen = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::fromFile($file->getPathname()) as $link) {
                // O login é a porta do app — não se anuncia; e cada tela uma vez.
                if ($link['class'] === 'LoginForm' || isset($seen[$link['class']])) {
                    continue;
                }
                $seen[$link['class']] = true;
                $found[] = $link;
            }
        }

        return $found;
    }

    /** Pasta das telas no lugar de app/control (null volta ao normal). Só para testes. */
    public static function useDirectory(?string $directory): void
    {
        self::$directory = $directory;
        self::$memo = [];
    }

    /**
     * Links declarados num arquivo, memoizados por data/tamanho (o login não
     * relê as telas que não mudaram; no Octane o memo vive o worker inteiro).
     *
     * @return list<array{class:string, label:string, icon:string, order:?int}>
     */
    private static function fromFile(string $path): array
    {
        $mtime = @filemtime($path);
        $size = @filesize($path);
        if ($mtime === false || $size === false || $size > self::MAX_BYTES) {
            return [];
        }
        $memo = self::$memo[$path] ?? null;
        if ($memo !== null && $memo['mtime'] === $mtime && $memo['size'] === $size) {
            return $memo['found'];
        }

        $source = @file_get_contents($path);
        // Filtro barato: só tokeniza quem cita a propriedade.
        $found = is_string($source) && str_contains($source, '$loginLink') ? self::parse($source) : [];
        self::$memo[$path] = ['mtime' => $mtime, 'size' => $size, 'found' => $found];

        return $found;
    }

    /**
     * Classes do código-fonte com `$loginLink` literal E públicas (`static bool
     * $public = true` na mesma classe, ou `extends MadSitePage`). Só lê tokens
     * ({@see ClassSource::parse()} — o mesmo leitor da marca de tela pública).
     *
     * @return list<array{class:string, label:string, icon:string, order:?int}>
     */
    public static function parse(string $source): array
    {
        $found = [];
        foreach (ClassSource::parse($source) as $class) {
            if ($class['kind'] !== 'class') {
                continue;
            }
            $public = ($class['props']['public'] ?? null) === true
                || basename(str_replace('\\', '/', $class['extends'])) === 'MadSitePage';
            $link = self::normalize($class['props']['loginLink'] ?? null);
            if ($public && $link !== null) {
                $found[] = ['class' => $class['class']] + $link;
            }
        }

        return $found;
    }

    /** @return array{label:string, icon:string, order:?int}|null */
    private static function normalize(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = ['label' => $value];
        }
        if (!is_array($value) || !is_string($value['label'] ?? null)) {
            return null;
        }
        $label = trim($value['label']);
        if ($label === '') {
            return null;
        }
        $icon = is_string($value['icon'] ?? null) ? trim((string) preg_replace('/^lucide:/i', '', trim($value['icon']))) : '';

        return [
            'label' => $label,
            // Nome de ícone Lucide (`user-round`); outra coisa não vai para o HTML.
            'icon'  => preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/i', $icon) ? strtolower($icon) : '',
            'order' => is_int($value['order'] ?? null) ? $value['order'] : null,
        ];
    }

    /** "Área" ordena junto de "Area". */
    private static function sortKey(string $label): string
    {
        return class_exists(\Illuminate\Support\Str::class) ? \Illuminate\Support\Str::ascii($label) : $label;
    }
}
