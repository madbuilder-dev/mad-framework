<?php

namespace Mad\Ai\Console\Handlers;

use App\Http\Controllers\AgentApplyController;
use App\Service\Builder\BuilderHttpClientService;
use Illuminate\Http\Request;

/**
 * Lógica compartilhada das tools que sincronizam do MadBuilder: (perna 1) buscam
 * a fatia canônica na nuvem (project-token) e (perna 2) aplicam via a API interna
 * do framework (AgentApplyController, in-process — sem sair na rede).
 */
trait SyncsFromCloud
{
    /**
     * GET {MAD_CODE_URL ?: MAD_BUILDER_URL}/api/app/agent/{slice} com o
     * project-token → array<int, array{path:string, content:string}>.
     * code_url = host dedicado do sync de código/menu/permissões; apps antigos
     * sem a key/env caem em builder.url (mesmo backend).
     */
    protected function fetchArtifacts(string $slice): array
    {
        $base  = rtrim((string) (config('mad.builder.code_url') ?: config('mad.builder.url')), '/');
        $token = (string) config('mad.builder.token');
        if ($base === '' || $token === '') {
            throw new \RuntimeException('App não pareado com o MadBuilder (MAD_BUILDER_URL / MAD_PROJECT_TOKEN ausentes).');
        }

        $resp = BuilderHttpClientService::get($base . '/api/app/agent/' . $slice, [], 'Bearer ' . $token);
        $raw  = is_object($resp) ? ($resp->files ?? []) : (is_array($resp) ? ($resp['files'] ?? []) : []);

        $files = [];
        foreach ($raw as $f) {
            $path    = is_object($f) ? ($f->path ?? '') : ($f['path'] ?? '');
            $content = is_object($f) ? ($f->content ?? '') : ($f['content'] ?? '');
            if ((string) $path !== '') {
                $files[] = ['path' => (string) $path, 'content' => (string) $content];
            }
        }

        return $files;
    }

    /**
     * Aplica os arquivos via o AgentApplyController::{$method} (code|menu|permissions),
     * in-process. Retorna o array decodificado do JSON da API interna.
     */
    protected function applyArtifacts(string $method, array $files): array
    {
        $req = Request::create(
            '/agent-console/v1/apply/' . $method,
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            (string) json_encode(['files' => $files])
        );

        $resp = (new AgentApplyController())->{$method}($req);

        return json_decode($resp->getContent(), true) ?: [];
    }
}
