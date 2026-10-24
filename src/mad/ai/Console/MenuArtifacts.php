<?php

namespace Mad\Ai\Console;

use App\Service\Builder\BuilderCodeSyncService;
use DOMDocument;
use Mad\Util\MenuPaths;

/**
 * Apply dos menus que a nuvem publica (GET /api/app/agent/menu): a família
 * menu*.xml + `page-modules.json` (classe da tela → módulo, framework #357).
 * Usado pelo card Menu da Central de Comando e pela MenuTool do agente do app
 * (trait SyncsFromCloud) e pela rota /agent-console/v1/apply/menu
 * (AgentApplyController).
 *
 * Mora no PACOTE de propósito. Até a 5.218.0 a validação ficava só no
 * AgentApplyController, em app/ do esqueleto, que muda apenas quando o app é
 * republicado. A nuvem passou a mandar o JSON (14/10), o controller recusava o
 * lote inteiro ("XML inválido em …page-modules.json") e o conserto da 5.212.1
 * não chegava a quem só atualizava o Mad Framework pelo card (fórum #163 e
 * #173). Aqui, atualizar o framework basta.
 */
final class MenuArtifacts
{
    /** Arquivos de menu conhecidos — remapeados p/ o diretório dos menus (issue #62). */
    public const FILES = [
        'menu.xml', 'top_menu.xml', 'menu-navbar-dropdown.xml',
        'menu-public.xml', 'menu-mobile.xml', 'menu-public-mobile.xml',
        'page-modules.json',
    ];

    /**
     * Valida CADA arquivo antes de gravar: .xml como XML, .json como objeto
     * JSON; outra extensão é recusada. Devolve a mensagem do primeiro inválido
     * (o lote inteiro é recusado) ou null.
     *
     * @param  array<string,string>  $map  path => conteúdo
     */
    public static function validate(array $map): ?string
    {
        foreach ($map as $path => $content) {
            $ext = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
            if ($ext === 'json') {
                try {
                    $okJson = is_array(json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR));
                } catch (\JsonException) {
                    $okJson = false;
                }
                if (! $okJson) {
                    return "JSON inválido em {$path} — nenhum menu foi alterado.";
                }
                continue;
            }
            if ($ext !== 'xml') {
                return "{$path} não é arquivo de menu (.xml ou .json) — nenhum menu foi alterado.";
            }
            $doc  = new DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $okXml = $doc->loadXML((string) $content);
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            if (! $okXml) {
                return "XML inválido em {$path} — nenhum menu foi alterado.";
            }
        }

        return null;
    }

    /**
     * Os menus vivem em resources/menus/ (issue #62). Remapeia pelo basename p/
     * o diretório canônico — robusto seja qual for o path que a nuvem mandou
     * (raiz legada `menu.xml` ou já `resources/menus/menu.xml`). Path que não é
     * menu fica intacto (defensivo — só menu deveria chegar aqui).
     *
     * @param  array<string,string>  $map
     * @return array<string,string>
     */
    public static function remap(array $map): array
    {
        $dir = MenuPaths::relativeDir();
        $out = [];
        foreach ($map as $path => $content) {
            $base = basename(str_replace('\\', '/', (string) $path));
            $out[in_array($base, self::FILES, true) ? "{$dir}/{$base}" : $path] = $content;
        }

        return $out;
    }

    /**
     * Valida e grava pelo pipeline do BuilderCodeSyncService::applyFiles (stage
     * → backup → rename atômico → audit; reversível por batchId).
     *
     * @param  array<string,string>  $map  path => conteúdo
     * @return array{ok:bool,batchId:string,applied:int,new:int,changed:int,message:string}
     */
    public static function apply(array $map): array
    {
        if ($map === []) {
            return self::result(false, message: 'Nenhum arquivo de menu recebido.');
        }
        if (($error = self::validate($map)) !== null) {
            return self::result(false, message: $error);
        }

        $res = BuilderCodeSyncService::applyFiles(self::remap($map), true);
        $ok  = ! empty($res['ok']);

        return self::result(
            $ok,
            (string) ($res['batchId'] ?? ''),
            (int) ($res['applied'] ?? 0),
            (int) ($res['new'] ?? 0),
            (int) ($res['changed'] ?? 0),
            $ok ? '' : trim(implode(' ', array_map(
                static fn ($l) => is_array($l) ? (string) ($l['msg'] ?? $l[1] ?? json_encode($l)) : (string) $l,
                $res['lines'] ?? []
            ))),
        );
    }

    /** @return array{ok:bool,batchId:string,applied:int,new:int,changed:int,message:string} */
    private static function result(bool $ok, string $batchId = '', int $applied = 0, int $new = 0, int $changed = 0, string $message = ''): array
    {
        return compact('ok', 'batchId', 'applied', 'new', 'changed', 'message');
    }
}
