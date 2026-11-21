<?php

namespace Mad\Ai;

use Illuminate\Support\Facades\Http;
use Mad\Ai\Gateways\SecretScrubber;

/**
 * Lista de modelos de texto de um provedor com chave direta (Google, OpenAI,
 * xAI), lida com a CHAVE DO PRÓPRIO USUÁRIO — é a lista que aquela chave
 * enxerga, não uma lista fixa (fórum #376).
 *
 * A chave vai só no cabeçalho da chamada ao provedor (nunca na URL, no cache,
 * no log ou na resposta para o navegador). O cache (storage/app, 1 h) guarda
 * os modelos e uma impressão digital curta da chave — trocar a chave invalida
 * a lista.
 */
final class ProviderModels
{
    private const TTL = 3600;

    /** Modelos que não conversam (embedding, imagem, áudio, vídeo, busca). */
    private const NOT_CHAT = '/embed|aqa|tts|audio|image|imagen|imagine|veo|video|live|native|transcribe|realtime|'
        . 'moderation|search|whisper|dall-e|davinci|babbage|instruct|robotics|computer-use/i';

    private const ENDPOINTS = [
        'google' => 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1000',
        'openai' => 'https://api.openai.com/v1/models',
        'xai'    => 'https://api.x.ai/v1/models',
    ];

    /**
     * @return array{ok: bool, models: array<string, string>, error: string|null}
     *   models = id => rótulo; error = motivo em pt-BR (sem a chave)
     */
    public static function fetch(string $provider, string $key, bool $force = false): array
    {
        $key = trim($key);
        if (! isset(self::ENDPOINTS[$provider])) {
            return ['ok' => false, 'models' => [], 'error' => 'Provedor sem lista de modelos.'];
        }
        if ($key === '') {
            return ['ok' => false, 'models' => [], 'error' => 'Informe a chave do provedor para carregar os modelos.'];
        }

        $fp = substr(hash('sha256', $key), 0, 16);
        if (! $force && ($cached = self::cached($provider, $fp)) !== null) {
            return ['ok' => true, 'models' => $cached, 'error' => null];
        }

        try {
            $request = Http::acceptJson()->timeout(10)->connectTimeout(4);
            $request = $provider === 'google'
                ? $request->withHeaders(['x-goog-api-key' => $key])
                : $request->withToken($key);
            $resp = $request->get(self::url($provider));
        } catch (\Throwable $e) {
            return ['ok' => false, 'models' => [], 'error' => 'Não foi possível falar com o provedor agora. Tente de novo em instantes.'];
        }

        if (! $resp->successful()) {
            $detail = SecretScrubber::clean(trim((string) ($resp->json('error.message') ?? '')));
            $why    = match (true) {
                in_array($resp->status(), [400, 401, 403], true) => 'O provedor recusou a chave',
                $resp->status() === 429                          => 'O provedor está limitando as chamadas desta chave',
                default                                          => 'O provedor não devolveu a lista de modelos',
            };

            return ['ok' => false, 'models' => [], 'error' => $why . ' (HTTP ' . $resp->status() . ')' . ($detail !== '' ? ': ' . $detail : '.')];
        }

        $models = self::parse($provider, (array) $resp->json());
        if ($models === []) {
            return ['ok' => false, 'models' => [], 'error' => 'A chave não tem acesso a nenhum modelo de texto.'];
        }
        self::store($provider, $fp, $models);

        return ['ok' => true, 'models' => $models, 'error' => null];
    }

    /**
     * Só a lista guardada (sem rede) — o que a tela de Preferências usa ao abrir.
     *
     * @return array<string, string>
     */
    public static function cachedFor(string $provider, string $key): array
    {
        $key = trim($key);

        return $key === '' ? [] : (self::cached($provider, substr(hash('sha256', $key), 0, 16)) ?? []);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    public static function parse(string $provider, array $body): array
    {
        $out = [];
        if ($provider === 'google') {
            foreach ((array) ($body['models'] ?? []) as $m) {
                $id      = preg_replace('#^models/#', '', (string) ($m['name'] ?? ''));
                $methods = (array) ($m['supportedGenerationMethods'] ?? []);
                if ($id === '' || ! str_starts_with($id, 'gemini') || ! in_array('generateContent', $methods, true) || preg_match(self::NOT_CHAT, $id)) {
                    continue;
                }
                $name     = trim((string) ($m['displayName'] ?? ''));
                $out[$id] = $name !== '' && $name !== $id ? "{$id} — {$name}" : $id;
            }
        } else {
            $prefix = $provider === 'xai' ? '/^grok/i' : '/^(gpt-|o\d|chatgpt-)/i';
            foreach ((array) ($body['data'] ?? []) as $m) {
                $id = (string) ($m['id'] ?? '');
                if ($id === '' || ! preg_match($prefix, $id) || preg_match(self::NOT_CHAT, $id)) {
                    continue;
                }
                $out[$id] = $id;
            }
        }
        ksort($out);

        return $out;
    }

    private static function url(string $provider): string
    {
        // Mesmo endereço base que a conversa usa (config [ai] / .env), para
        // proxies corporativos e testes. O boot monta config('ai') mesmo quando
        // a chave veio digitada na tela (ainda não salva).
        MadAi::boot();
        $base = rtrim((string) (config("ai.providers.{$provider}.url") ?? ''), '/');
        if ($base === '') {
            return self::ENDPOINTS[$provider];
        }

        return $provider === 'google' ? $base . '/models?pageSize=1000' : $base . '/models';
    }

    private static function file(string $provider): string
    {
        return storage_path("app/ai-models-{$provider}.json");
    }

    /** @return array<string, string>|null */
    private static function cached(string $provider, string $fp): ?array
    {
        $f = self::file($provider);
        if (! is_file($f) || (time() - (int) filemtime($f)) >= self::TTL) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($f), true);

        return is_array($data) && ($data['fp'] ?? '') === $fp && is_array($data['models'] ?? null) && $data['models'] !== []
            ? array_map('strval', $data['models'])
            : null;
    }

    /** @param array<string, string> $models */
    private static function store(string $provider, string $fp, array $models): void
    {
        $f = self::file($provider);
        if (! is_dir(dirname($f))) {
            @mkdir(dirname($f), 0775, true);
        }
        @file_put_contents($f, json_encode(['fp' => $fp, 'models' => $models], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
