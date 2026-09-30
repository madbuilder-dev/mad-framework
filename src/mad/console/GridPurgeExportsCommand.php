<?php

namespace Mad\Console;

use Illuminate\Console\Command;
use Mad\Service\MadScratchStorage;

/**
 * mad:grid:purge-exports — apaga os arquivos de exportação da grade
 * (CSV/XLSX/PDF) antigos do scratch (disco mad_tmp, prefixo output/).
 *
 * O MadGridExporter grava cada export como output/<uniqid>.<ext> e nunca
 * limpa — sem isto o diretório cresce sem limite em produção. Agendado diário
 * em routes/console.php. Todo IO via Storage.
 */
class GridPurgeExportsCommand extends Command
{
    protected $signature = 'mad:grid:purge-exports
        {--hours=24 : Idade mínima em horas para apagar (default 24)}
        {--dry-run : Lista o que seria apagado sem remover}';

    protected $description = 'Apaga exports antigos (CSV/XLSX/PDF) do scratch output/';

    private const EXTENSIONS = ['csv', 'xlsx', 'pdf'];

    public function handle(): int
    {
        $hours = max(0, (int) $this->option('hours'));
        $dry   = (bool) $this->option('dry-run');
        $disk  = MadScratchStorage::disk();

        $cutoff  = time() - $hours * 3600;
        $deleted = 0;
        $kept    = 0;
        $freed   = 0;

        foreach ($disk->files('output') as $key) {
            $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));
            if (!in_array($ext, self::EXTENSIONS, true)) {
                continue;
            }
            if ($disk->lastModified($key) <= $cutoff) {
                $size = (int) $disk->size($key);
                if ($dry || $disk->delete($key)) {
                    $deleted++;
                    $freed += $size;
                }
            } else {
                $kept++;
            }
        }

        $this->info(sprintf(
            '%s%d apagado(s) (%s liberado), %d preservado(s) [corte: > %dh].',
            $dry ? '[dry-run] ' : '',
            $deleted,
            self::human($freed),
            $kept,
            $hours
        ));

        return self::SUCCESS;
    }

    private static function human(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $units = ['KB', 'MB', 'GB'];
        $n = $bytes / 1024;
        foreach ($units as $u) {
            if ($n < 1024 || $u === 'GB') {
                return round($n, 1) . ' ' . $u;
            }
            $n /= 1024;
        }
        return $bytes . ' B';
    }
}
