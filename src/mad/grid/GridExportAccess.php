<?php

namespace Mad\Grid;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Mad\Security\PermissionGate;
use Mad\Service\MadScratchStorage;

/**
 * GridExportAccess — quem pode baixar um export da grade (CSV/XLSX/PDF).
 *
 * O arquivo em `output/` era servido a QUALQUER usuário logado que tivesse o
 * nome, e o nome era `uniqid()` (relógio em microssegundos, deduzível): um
 * perfil sem acesso à tela baixava a exportação de outro (mad-framework#342).
 *
 * Agora:
 *  - o nome é aleatório de verdade ({@see newKey()});
 *  - ao lado do arquivo fica `output/<arquivo>.meta` com quem gerou, a unidade
 *    e a tela de origem ({@see grant()});
 *  - o link do botão Baixar é assinado e curto ({@see url()});
 *  - no download, a assinatura ({@see signatureValid()}), o prazo e o dono
 *    ({@see ownedByCurrentUser()}) — com, de novo, o acesso à tela de origem —
 *    são conferidos. Dono divergente responde 404: não revela o arquivo.
 */
final class GridExportAccess
{
    /** Validade do link de download, em minutos (padrão; `mad.grid_export_link_minutes`). */
    public const DEFAULT_MINUTES = 30;

    /** Ação da tela de origem conferida no download, por extensão. */
    private const METHOD_BY_EXT = [
        'csv'  => 'onExportCSV',
        'xlsx' => 'onExportXLSX',
        'pdf'  => 'onExportPDF',
    ];

    /** Chave nova no scratch: `output/<32 hex>.<ext>` (128 bits de random_bytes). */
    public static function newKey(string $ext): string
    {
        return 'output/' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Chave do metadado de um export (`output/<arquivo>.meta`). */
    public static function metaKey(string $file): string
    {
        return 'output/' . basename($file) . '.meta';
    }

    /**
     * Amarra o export a quem o gerou: usuário e unidade da sessão e a tela
     * (programa) de origem.
     */
    public static function grant(string $file, string $ownerClass): void
    {
        MadScratchStorage::putContent(self::metaKey($file), (string) json_encode([
            'user'  => self::sessionValue('userid'),
            'unit'  => self::sessionValue('userunitid'),
            'owner' => $ownerClass,
            'at'    => time(),
        ], JSON_UNESCAPED_SLASHES));
    }

    /** Link relativo, assinado e com validade curta para baixar o export. */
    public static function url(string $file, string $basename): string
    {
        $minutes = (int) (function_exists('config') ? config('mad.grid_export_link_minutes', self::DEFAULT_MINUTES) : self::DEFAULT_MINUTES);

        return URL::temporarySignedRoute(
            'mad.grid.export',
            now()->addMinutes(max(1, $minutes)),
            ['file' => basename($file), 'basename' => $basename],
            false
        );
    }

    /** O link foi emitido por este app para este arquivo (assinatura confere)? */
    public static function signatureValid(Request $request): bool
    {
        return URL::hasCorrectSignature($request, false);
    }

    /** O link ainda está no prazo? (vencido = exporte de novo, como no purge) */
    public static function signatureFresh(Request $request): bool
    {
        return URL::signatureHasNotExpired($request);
    }

    /**
     * O export é do usuário e da unidade da sessão, e ele ainda abre a tela de
     * origem com a ação de exportar? Sem metadado (arquivo de antes desta
     * versão ou criado por fora) = não.
     */
    public static function ownedByCurrentUser(string $file): bool
    {
        $disk = MadScratchStorage::disk();
        $raw  = $disk->exists(self::metaKey($file)) ? $disk->get(self::metaKey($file)) : null;
        $meta = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($meta)) {
            return false;
        }

        $user = self::sessionValue('userid');
        if ($user === null || (string) ($meta['user'] ?? '') !== $user) {
            return false;
        }
        if ((string) ($meta['unit'] ?? '') !== (string) self::sessionValue('userunitid')) {
            return false;
        }

        $owner = trim((string) ($meta['owner'] ?? ''));
        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return $owner !== '' && PermissionGate::canAccess($owner, self::METHOD_BY_EXT[$ext] ?? null);
    }

    private static function sessionValue(string $key): ?string
    {
        $value = function_exists('session') ? session($key) : null;

        return ($value === null || $value === '') ? null : (string) $value;
    }
}
