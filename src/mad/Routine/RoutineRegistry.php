<?php

namespace Mad\Routine;

use Illuminate\Support\Facades\Log;

/**
 * Rotinas do app — o conteúdo de `app/config/routines.json`, gerado pela
 * plataforma (`config('mad.routine.definitions_path')` troca o caminho).
 *
 * Lido DIRETO do disco, memoizado por caminho + mtime + tamanho: um worker de
 * vida longa (Octane, `queue:work`) enxerga a versão nova do arquivo na
 * requisição seguinte, sem reinício.
 *
 * Arquivo ausente, ilegível ou com JSON quebrado = nenhuma rotina (salvar e
 * excluir seguem como sempre). Nunca lança.
 */
final class RoutineRegistry
{
    /** @var array{path: string, stamp: string, entries: array<string, RoutineEntry>, connections: list<string>}|null */
    private static ?array $memo = null;

    public static function path(): string
    {
        $path = '';
        try {
            $path = (string) (config('mad.routine.definitions_path') ?: '');
        } catch (\Throwable) {
            $path = '';
        }

        return $path !== '' ? $path : app_path('config/routines.json');
    }

    /** @return array<string, RoutineEntry> slug => rotina */
    public static function all(): array
    {
        return self::load()['entries'];
    }

    public static function find(string $slug): ?RoutineEntry
    {
        return self::all()[$slug] ?? null;
    }

    /** A rotina declarada por esta classe (a classe gerada sabe o próprio slug, mas o registro diz a conexão). */
    public static function forClass(string $class): ?RoutineEntry
    {
        $class = ltrim($class, '\\');
        foreach (self::all() as $entry) {
            if (strcasecmp($entry->class, $class) === 0) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<RoutineEntry> rotinas que disparam ao salvar este registro por esta tela */
    public static function onSaveFor(object $record, ?string $controller): array
    {
        return array_values(array_filter(self::all(), static fn (RoutineEntry $e) => $e->firesOnSave($record, $controller)));
    }

    /** @return list<RoutineEntry> rotinas que se desfazem quando este registro é excluído */
    public static function onDeleteFor(object $record): array
    {
        return array_values(array_filter(self::all(), static fn (RoutineEntry $e) => $e->firesOnDelete($record)));
    }

    /** @return list<string> conexões que precisam do livro das rotinas */
    public static function connections(): array
    {
        return self::load()['connections'];
    }

    /** Esquece o que foi lido (testes). */
    public static function flush(): void
    {
        self::$memo = null;
    }

    /** @return array{path: string, stamp: string, entries: array<string, RoutineEntry>, connections: list<string>} */
    private static function load(): array
    {
        $empty = ['path' => '', 'stamp' => '', 'entries' => [], 'connections' => []];
        try {
            $path = self::path();
        } catch (\Throwable) {
            return $empty;
        }

        clearstatcache(true, $path);
        $mtime = @filemtime($path);
        if ($mtime === false) {
            return $empty;
        }
        $stamp = $mtime . ':' . (int) @filesize($path);
        if (self::$memo !== null && self::$memo['path'] === $path && self::$memo['stamp'] === $stamp) {
            return self::$memo;
        }

        return self::$memo = ['path' => $path, 'stamp' => $stamp] + self::parse($path);
    }

    /** @return array{entries: array<string, RoutineEntry>, connections: list<string>} */
    private static function parse(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return ['entries' => [], 'connections' => []];
        }

        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['routines'] ?? null)) {
            self::warn('o arquivo de rotinas não é um JSON válido', $path);

            return ['entries' => [], 'connections' => []];
        }

        $entries = [];
        foreach ($data['routines'] as $raw) {
            $entry = RoutineEntry::fromArray($raw);
            if ($entry === null) {
                self::warn('rotina ignorada (slug ou classe inválidos)', $path);

                continue;
            }
            // Slug repetido: vale a primeira; a plataforma garante unicidade por projeto.
            $entries[$entry->slug] ??= $entry;
        }

        $connections = [];
        foreach (is_array($data['connections'] ?? null) ? $data['connections'] : [] as $c) {
            if (is_string($c) && trim($c) !== '') {
                $connections[trim($c)] = true;
            }
        }

        return ['entries' => $entries, 'connections' => array_keys($connections)];
    }

    private static function warn(string $message, string $path): void
    {
        try {
            Log::warning('[routine] ' . $message, ['file' => $path]);
        } catch (\Throwable) {
            // log indisponível não derruba a leitura
        }
    }
}
