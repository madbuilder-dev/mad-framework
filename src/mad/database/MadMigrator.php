<?php

namespace Mad\Database;

use Illuminate\Console\View\Components\Info;
use Illuminate\Console\View\Components\Warn;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Log;

/**
 * Migrator do app: uma revisão pendente superada não trava as outras (fórum #336).
 *
 * O MadBuilder gera `…_v10_to_v11_r001`, `_r002`, `_r003`… para o mesmo passo;
 * cada revisão leva o delta inteiro e é reexecutável, então só a mais nova
 * precisa rodar. Antes, uma `_r001` com erro (uma view com SQL inválido)
 * parava o `migrate` e a `_r003`, que corrigia, nunca chegava ao banco.
 *
 *   - Pendente superada (há revisão maior do mesmo nome) não roda: o
 *     `migrate` avisa no console qual pulou e qual vale.
 *   - Revisão já aplicada nunca é tocada.
 *   - Depois que a mais nova é aplicada, o arquivo das pendentes superadas
 *     sai de database/migrations para storage/mad/migrations-superseded/<data>/
 *     (nada é apagado; nunca rodaram) — a lista da Central de Comando fica limpa.
 *
 * Vale para toda chamada do migrate (Central de Comando, deploy, Teste Online).
 * Desliga com `mad.migrations.skip_superseded_revisions = false`.
 */
class MadMigrator extends Migrator
{
    /** @var array<string, string> pendente superada → a revisão que vale */
    protected array $madSuperseded = [];

    /** @var array<int, string> arquivos das pendentes superadas (caminho completo) */
    protected array $madSupersededFiles = [];

    public static function enabled(): bool
    {
        return filter_var(config('mad.migrations.skip_superseded_revisions', true), FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  string[]  $files
     * @param  string[]  $ran
     * @return string[]
     */
    protected function pendingMigrations($files, $ran)
    {
        $pending = parent::pendingMigrations($files, $ran);
        $this->madSuperseded = [];
        $this->madSupersededFiles = [];
        if (! self::enabled()) {
            return $pending;
        }

        $names = [];
        foreach ($files as $file) {
            $names[] = $this->getMigrationName($file);
        }
        $superseded = MigrationRevisions::superseded($names, $ran);
        if ($superseded === []) {
            return $pending;
        }

        $keep = [];
        foreach ($pending as $file) {
            $name = $this->getMigrationName($file);
            if (isset($superseded[$name])) {
                $this->madSuperseded[$name] = $superseded[$name];
                $this->madSupersededFiles[] = $file;

                continue;
            }
            $keep[] = $file;
        }
        foreach ($this->madSuperseded as $name => $newest) {
            $this->write(Warn::class, self::say('migration_superseded', "Superada, não roda: {$name} — a revisão mais nova ({$newest}) leva todas as mudanças.", ['name' => $name, 'newest' => $newest]));
        }

        return array_values($keep);
    }

    /**
     * @param  string[]|string  $paths
     * @param  array<string, mixed>  $options
     * @return string[]
     */
    public function run($paths = [], array $options = [])
    {
        $migrations = parent::run($paths, $options);

        if (empty($options['pretend']) && $this->madSupersededFiles !== []) {
            $this->archiveSuperseded();
        }

        return $migrations;
    }

    /** Frase do catálogo do framework (pt/en/es), com o texto em pt de reserva. */
    private static function say(string $key, string $fallback, array $params): string
    {
        if (! function_exists('mad_t')) {
            return $fallback;
        }
        $out = (string) mad_t('mad.ui.' . $key, $params);

        return ($out === '' || $out === 'mad.ui.' . $key) ? $fallback : $out;
    }

    /** @return array<string, string> pendente superada → a revisão que vale (última execução) */
    public function supersededRevisions(): array
    {
        return $this->madSuperseded;
    }

    /**
     * Tira de database/migrations as pendentes superadas cuja revisão mais
     * nova já está aplicada. Só move (nunca apaga) e só arquivo que não está
     * na tabela `migrations`. Falha aqui não derruba o migrate.
     */
    protected function archiveSuperseded(): void
    {
        try {
            $ran = array_flip($this->repository->getRan());
            $dir = rtrim((string) (config('mad.migrations.superseded_archive') ?: storage_path('mad/migrations-superseded')), '/')
                . '/' . date('Ymd_His');
            $moved = [];
            foreach ($this->madSupersededFiles as $file) {
                $name = $this->getMigrationName($file);
                $newest = $this->madSuperseded[$name] ?? null;
                if ($newest === null || isset($ran[$name]) || ! isset($ran[$newest]) || ! is_file($file)) {
                    continue;
                }
                if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                    return;
                }
                if (@rename($file, $dir . '/' . basename($file))) {
                    $moved[] = $name;
                }
            }
            foreach ($moved as $name) {
                $this->write(Info::class, self::say('migration_superseded_moved', "Revisão superada guardada fora da lista de migrations: {$name}.", ['name' => $name]));
            }
        } catch (\Throwable $e) {
            Log::warning('[mad-migrator] não arquivou as revisões superadas', ['error' => $e->getMessage()]);
        }
    }
}
