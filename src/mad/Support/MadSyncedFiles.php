<?php

namespace Mad\Support;

/**
 * Tema e logo que chegam pela aba Código Fonte da Central de Comando (fórum #271).
 *
 * O "Atualizar código" só grava em app/, resources/ e database/: o app recusa
 * public/ (webshell) e grava o conteúdo como veio (texto). Só que o navegador
 * carrega o CSS do tema e as imagens de public/, e imagem é binário. Sem SSH, um
 * app em cPanel só via o tema e o logo novos publicando o projeto inteiro.
 *
 * A plataforma manda esses arquivos como "portadores": `app/sync/<destino>.b64`,
 * o conteúdo em base64. Aqui, quando a página abre, cada portador vira o arquivo
 * de destino, com três travas:
 *
 *  1. LISTA FIXA: só as pastas do tema e das imagens, em app/ e public/, e só
 *     CSS e imagem (png, jpg, jpeg, gif, webp, ico). Nome com um ponto só, sem
 *     `..`, sem barra invertida, sem `%`: nada de `.php`, `.htaccess`, SVG (pode
 *     levar script) ou caminho manipulado. O conteúdo confere com o tipo.
 *  2. TROCA MANUAL VENCE: arquivo de public/ que mudou desde a última gravação
 *     daqui não é sobrescrito. Na primeira vez, sem histórico, vale o par que a
 *     publicação grava junto (a cópia de app/): se a de public/ é diferente e
 *     mais nova que ele, foi trocada à mão e fica. A cópia de app/ não vai ao
 *     navegador e segue sempre o Studio.
 *  3. NUNCA DERRUBA: falha vira aviso no log; a página abre do mesmo jeito.
 *
 * Custo com nada novo: listar app/sync e comparar uma assinatura (nomes, tamanhos
 * e datas). Só roda ao abrir a página inteira (não nas ações da tela).
 */
final class MadSyncedFiles
{
    /** Pasta dos portadores, relativa à raiz do app. */
    public const CARRIER_DIR = 'app/sync';

    public const SUFFIX = '.b64';

    /** Maior arquivo de destino aceito (decodificado). */
    private const MAX_BYTES = 8 * 1024 * 1024;

    private const IMAGES = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'ico'];

    /** Destinos aceitos: pasta (exata, relativa à raiz do app) => extensões. */
    private const TARGET_DIRS = [
        'app/templates/theme-notch/themes'        => ['css'],
        'public/app/templates/theme-notch/themes' => ['css'],
        'app/images'                              => self::IMAGES,
        'public/app/images'                       => self::IMAGES,
        'app/images/theme'                        => self::IMAGES,
        'public/app/images/theme'                 => self::IMAGES,
    ];

    /** Nome do arquivo: um ponto só, antes da extensão minúscula. */
    private const NAME = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,119}\.([a-z0-9]{2,4})$/';

    /**
     * Destino de um portador (`app/sync/<destino>.b64`), ou null se ele não
     * estiver na lista fixa.
     */
    public static function targetFor(string $carrierRel): ?string
    {
        $prefix = self::CARRIER_DIR . '/';
        if (!str_starts_with($carrierRel, $prefix) || !str_ends_with($carrierRel, self::SUFFIX)) {
            return null;
        }
        $target = substr($carrierRel, strlen($prefix), -strlen(self::SUFFIX));

        return self::allowedTarget($target) ? $target : null;
    }

    /** O destino está na lista fixa? Comparação exata da pasta: `..` não casa. */
    public static function allowedTarget(string $target): bool
    {
        if ($target === '' || strpbrk($target, "\0\\%:") !== false || str_starts_with($target, '/')) {
            return false;
        }
        $slash = strrpos($target, '/');
        if ($slash === false) {
            return false;
        }
        $dir  = substr($target, 0, $slash);
        $name = substr($target, $slash + 1);
        if (!isset(self::TARGET_DIRS[$dir]) || preg_match(self::NAME, $name, $m) !== 1) {
            return false;
        }

        return in_array($m[1], self::TARGET_DIRS[$dir], true);
    }

    /** O conteúdo é mesmo do tipo do nome? */
    public static function contentMatches(string $target, string $bytes): bool
    {
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES || stripos($bytes, '<?php') !== false) {
            return false;
        }

        return match (strtolower(pathinfo($target, PATHINFO_EXTENSION))) {
            'css'         => !str_contains($bytes, '<?'),
            'png'         => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'gif'         => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'webp'        => strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP',
            'ico'         => str_starts_with($bytes, "\x00\x00\x01\x00"),
            default       => false,
        };
    }

    /**
     * Grava os destinos dos portadores novos ou alterados.
     *
     * @return array{ran: bool, written: list<string>, kept: list<string>, rejected: list<string>, failed: list<string>}
     */
    public static function apply(string $basePath, string $stateFile): array
    {
        $out  = ['ran' => false, 'written' => [], 'kept' => [], 'rejected' => [], 'failed' => []];
        $base = rtrim($basePath, '/');

        $carriers = self::carriers($base);
        if ($carriers === []) {
            return $out;
        }

        $signature = self::signature($carriers);
        $state     = self::readState($stateFile);
        if (($state['signature'] ?? null) === $signature) {
            return $out;
        }

        $stateDir = dirname($stateFile);
        if (!is_dir($stateDir) && !@mkdir($stateDir, 0775, true) && !is_dir($stateDir)) {
            return $out;
        }
        // Um request por vez: os que chegam juntos seguem com o que já existe.
        $lock = @fopen($stateFile . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            if ($lock !== false) {
                fclose($lock);
            }

            return $out;
        }

        try {
            $out['ran'] = true;
            $known = is_array($state['files'] ?? null) ? $state['files'] : [];

            // 1) Lê e valida tudo; 2) decide tudo; 3) grava. Decidir antes de
            // gravar mantém o par raiz ↔ public/ como estava na publicação.
            $plan = [];
            foreach ($carriers as $rel => $abs) {
                $target = self::targetFor($rel);
                $bytes  = $target === null ? false : self::decode($abs);
                if ($target === null || $bytes === false || !self::contentMatches($target, $bytes)) {
                    $out['rejected'][] = $rel;
                    continue;
                }
                $plan[$target] = $bytes;
            }

            $decisions = [];
            foreach ($plan as $target => $bytes) {
                $decisions[$target] = self::decide($base, $target, $bytes, $known[$target] ?? null);
            }

            foreach ($decisions as $target => $decision) {
                $bytes = $plan[$target];
                if ($decision === 'same') {
                    $known[$target] = ['sha' => sha1($bytes)];
                } elseif ($decision === 'keep') {
                    $out['kept'][] = $target;
                    $known[$target] = ['sha' => (string) @sha1_file($base . '/' . $target), 'kept' => true];
                } elseif (self::write($base . '/' . $target, $bytes)) {
                    $out['written'][] = $target;
                    $known[$target] = ['sha' => sha1($bytes)];
                } else {
                    $out['failed'][] = $target;
                }
            }

            // Marca mesmo com falha (public/ sem permissão): sem isso todo request
            // voltaria a tentar. O próximo "Atualizar" muda a assinatura e tenta de novo.
            self::writeState($stateFile, ['signature' => $signature, 'files' => $known]);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        return $out;
    }

    /**
     * {@see apply()} para o request web: nunca lança e registra no log o que
     * ficou de fora.
     */
    public static function refresh(string $basePath, string $stateFile): void
    {
        try {
            $r = self::apply($basePath, $stateFile);
            if (!$r['ran'] || !function_exists('logger')) {
                return;
            }
            if ($r['written'] !== [] || $r['kept'] !== []) {
                logger()->info('[mad] tema e imagens da aba Código Fonte aplicados.', [
                    'gravados' => $r['written'],
                    'mantidos (trocados à mão)' => $r['kept'],
                ]);
            }
            if ($r['rejected'] !== [] || $r['failed'] !== []) {
                logger()->warning('[mad] tema e imagens da aba Código Fonte: arquivos não aplicados.', [
                    'recusados (fora da lista ou conteúdo inválido)' => $r['rejected'],
                    'sem permissão de escrita' => $r['failed'],
                ]);
            }
        } catch (\Throwable $e) {
            try {
                logger()->warning('[mad] falha ao aplicar tema e imagens da aba Código Fonte: ' . $e->getMessage());
            } catch (\Throwable) {
                // sem log, sem problema
            }
        }
    }

    /**
     * 'write', 'same' (já igual) ou 'keep' (trocado à mão).
     *
     * @param array{sha?: string, kept?: bool}|null $known o que esta classe gravou da última vez
     */
    private static function decide(string $base, string $target, string $bytes, ?array $known): string
    {
        $abs = $base . '/' . $target;
        if (is_link($abs)) {
            return 'keep';
        }
        if (!is_file($abs)) {
            return 'write';
        }
        $current = (string) sha1_file($abs);
        if ($current === sha1($bytes)) {
            return 'same';
        }
        // A cópia de app/ não vai ao navegador: segue sempre o Studio. A trava de
        // troca manual é de public/, o que o servidor entrega.
        if (!str_starts_with($target, 'public/')) {
            return 'write';
        }
        if ($known !== null) {
            if (!empty($known['kept'])) {
                return 'keep';
            }

            return $current === ($known['sha'] ?? null) ? 'write' : 'keep';
        }

        // Primeira vez: o par que a publicação grava junto (a cópia de app/).
        $twinAbs = $base . '/' . substr($target, strlen('public/'));
        if (is_file($twinAbs) && !is_link($twinAbs)) {
            if (sha1_file($twinAbs) === $current) {
                return 'write';
            }

            return (int) filemtime($abs) > (int) filemtime($twinAbs) ? 'keep' : 'write';
        }

        return 'write';
    }

    /**
     * Portadores em app/sync (sem seguir link), relativo => absoluto.
     *
     * @return array<string, string>
     */
    private static function carriers(string $base): array
    {
        $root = $base . '/' . self::CARRIER_DIR;
        if (!is_dir($root) || is_link($root)) {
            return [];
        }

        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isLink() || !$file->isFile() || !str_ends_with($file->getFilename(), self::SUFFIX)) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
            $out[$rel] = $file->getPathname();
        }
        ksort($out);

        return $out;
    }

    /** @param array<string, string> $carriers */
    private static function signature(array $carriers): string
    {
        $parts = [];
        foreach ($carriers as $rel => $abs) {
            $parts[] = $rel . '|' . (int) @filesize($abs) . '|' . (int) @filemtime($abs);
        }

        return sha1(implode("\n", $parts));
    }

    private static function decode(string $abs): string|false
    {
        $size = @filesize($abs);
        if ($size === false || $size === 0 || $size > (int) ceil(self::MAX_BYTES / 3) * 4 + 1024) {
            return false;
        }
        $raw = @file_get_contents($abs);

        return $raw === false ? false : base64_decode(trim($raw), true);
    }

    /** Escrita atômica (o servidor pode estar entregando o arquivo agora). */
    private static function write(string $abs, string $bytes): bool
    {
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }
        $tmp = $dir . '/.madsync-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $bytes) === strlen($bytes) && @rename($tmp, $abs)) {
            @chmod($abs, 0644);

            return true;
        }
        @unlink($tmp);

        return false;
    }

    /** @return array<string, mixed> */
    private static function readState(string $stateFile): array
    {
        $raw = is_file($stateFile) ? @file_get_contents($stateFile) : false;
        $data = $raw === false ? null : json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /** @param array<string, mixed> $state */
    private static function writeState(string $stateFile, array $state): void
    {
        $tmp = $stateFile . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) !== false) {
            @rename($tmp, $stateFile);
        }
        @unlink($tmp);
    }
}
