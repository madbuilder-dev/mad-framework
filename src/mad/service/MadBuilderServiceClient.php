<?php

namespace Mad\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Mad\Support\MadCnpj;

/**
 * Cliente dos lookups de CEP/CNPJ da plataforma MadBuilder.
 *
 * Substitui o acesso direto ao host legado services.madbuilder.com.br, que
 * autenticava com o `general.token` (MAD_TOKEN) na URL. Agora fala com o
 * backend da plataforma usando o MESMO Bearer que o app já usa pro sync de
 * código (`MAD_PROJECT_TOKEN` → config('mad.builder.token')):
 *
 *   GET {builder.code_url|url}/api/app/services/cep/{cep}
 *   GET {builder.code_url|url}/api/app/services/cnpj/{cnpj}[?full=1]
 *
 * Três razões pra não reusar o BuilderHttpClientService:
 *  1. Ele vive em `app/` — usá-lo daqui inverte a dependência pacote → app,
 *     que é justamente o acoplamento que esta mudança corta.
 *  2. CURLOPT_TIMEOUT = 120s. O <mad-cep-field> não tem timeout no JS: o
 *     spinner giraria DOIS MINUTOS num campo de formulário.
 *  3. curl cru é intestável — Http::fake() não intercepta, e o caminho de rede
 *     do CEP tinha cobertura zero por causa disso.
 *
 * O token viaja no HEADER, nunca na URL: o formato antigo
 * (.../cep/api/v1/{cep}/{token}) vazava o segredo em access log de proxy, em
 * Referer e em qualquer exception que carregasse a URL.
 *
 * RECURSO PAGO: sem plano ativo o backend devolve 403 e a mensagem DELE vai
 * literal pro toast do campo. App não vinculado (sem MAD_PROJECT_TOKEN) recebe
 * erro claro — não há fallback público, por design.
 */
final class MadBuilderServiceClient
{
    /** Handshake curto: o usuário está parado olhando o spinner do campo. */
    private const CONNECT_TIMEOUT = 5;

    private const TIMEOUT = 12;

    /** @throws \RuntimeException mensagem já pronta pro toast */
    public static function cep(string $cep): ?\stdClass
    {
        $digits = (string) preg_replace('/\D/', '', $cep);

        return self::get('/api/app/services/cep/' . $digits);
    }

    /** @throws \RuntimeException */
    public static function cnpj(string $cnpj, bool $full = false): ?\stdClass
    {
        // sanitize, NÃO "tirar não-dígitos": o CNPJ alfanumérico da Receita
        // (julho/2026) tem letras nas 12 primeiras posições. Ver Mad\Support\MadCnpj.
        $clean = MadCnpj::sanitize($cnpj);

        return self::get('/api/app/services/cnpj/' . $clean, $full ? ['full' => 1] : []);
    }

    /** App pareado com a plataforma? (base + token presentes) */
    public static function isConfigured(): bool
    {
        return self::baseUrl() !== '' && (string) config('mad.builder.token') !== '';
    }

    /**
     * null = 404 (documento não encontrado) — o caller decide a mensagem.
     *
     * @param array<string, mixed> $query
     * @throws \RuntimeException
     */
    private static function get(string $path, array $query = []): ?\stdClass
    {
        $base  = self::baseUrl();
        $token = (string) config('mad.builder.token');

        if ($base === '' || $token === '') {
            // Problema de CONFIGURAÇÃO do app. Quem está na tela é o cliente
            // final digitando um CNPJ: dizer a ele "configure MAD_BUILDER_URL e
            // MAD_PROJECT_TOKEN no .env" é instrução de deploy na cara de quem
            // paga a conta, e ele não tem nem como executá-la.
            throw new \RuntimeException(self::configProblem(
                'app sem pareamento com a plataforma'
                . ' (mad.builder.url/code_url ou mad.builder.token ausente)'
            ));
        }

        try {
            $resp = Http::withToken($token)
                ->acceptJson()
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->get($base . $path, $query);
        } catch (ConnectionException $e) {
            // Sem retry de propósito: retry dobra a espera de quem está parado
            // olhando o campo.
            throw new \RuntimeException(self::msg(
                'builder_unavailable',
                'Serviço de consulta indisponível no momento. Tente novamente.'
            ));
        }

        if ($resp->status() === 404) {
            return null;
        }

        if ($resp->status() === 401) {
            // Token inválido/revogado também é configuração — o usuário do app
            // não "reconfigura o app no MadBuilder".
            throw new \RuntimeException(self::configProblem('token do projeto inválido ou revogado (HTTP 401)'));
        }

        if ($resp->status() === 429) {
            throw new \RuntimeException(self::msg(
                'builder_rate_limited',
                'Muitas consultas seguidas. Aguarde alguns segundos e tente de novo.'
            ));
        }

        // json_decode direto (e não ->json()): o _resolve dos services navega
        // `foo->bar->baz`, então precisa de stdClass ANINHADO.
        $body = json_decode((string) $resp->body());

        if ($resp->status() === 403) {
            // Gate de PLANO: é assunto do dono do app, não de quem preenche o
            // cadastro. O texto do backend (quando vem) é mais informativo e
            // fica no log; na tela, a orientação que serve para o usuário é
            // "preencha à mão".
            throw new \RuntimeException(self::configProblem(
                'consulta bloqueada pelo plano (HTTP 403): ' . (self::backendMessage($body) ?: 'sem detalhe')
            ));
        }

        if (! $resp->successful()) {
            throw new \RuntimeException(self::backendMessage($body) ?: self::msg(
                'builder_unavailable',
                'Serviço de consulta indisponível no momento. Tente novamente.'
            ));
        }

        if (! ($body instanceof \stdClass) || ($body->status ?? null) !== 'ok') {
            throw new \RuntimeException(self::backendMessage($body) ?: self::msg(
                'builder_unavailable',
                'Serviço de consulta indisponível no momento. Tente novamente.'
            ));
        }

        return ($body->data ?? null) instanceof \stdClass ? $body->data : null;
    }

    private static function baseUrl(): string
    {
        $base = (string) (config('mad.builder.code_url') ?: config('mad.builder.url') ?: '');

        return rtrim($base, '/');
    }

    private static function backendMessage(mixed $body): string
    {
        return $body instanceof \stdClass ? trim((string) ($body->message ?? '')) : '';
    }

    /**
     * As chaves `service.*` vivem no lang da APP, não do pacote — um app com o
     * pacote atualizado mas as traduções antigas mostraria a chave crua no
     * toast. Daí o literal de fallback.
     *
     * @param array<string, string> $repl
     */
    private static function msg(string $key, string $fallback, array $repl = []): string
    {
        $full = 'service.' . $key;

        // `__()` devolve ARRAY quando a chave aponta para um nó com filhos
        // (`service.cep` com `service.cep.invalid` embaixo, por exemplo). O
        // cast `(string)` nesse array estoura "Array to string conversion" —
        // erro de renderização no meio de um toast de campo. Sem cast: o que
        // não for string cai no literal de reserva.
        $raw        = __($full, $repl);
        $translated = is_string($raw) ? $raw : $full;

        return $translated === $full ? $fallback : $translated;
    }

    /**
     * Falha de CONFIGURAÇÃO/PLANO: o usuário recebe a orientação que ele pode
     * executar ("preencha à mão"), e o detalhe vai para o log, onde quem
     * administra o app vai procurar.
     */
    private static function configProblem(string $detail): string
    {
        try {
            if (function_exists('logger')) {
                logger()->warning('[mad-lookup] consulta CEP/CNPJ indisponível: ' . $detail);
            }
        } catch (\Throwable $ignored) {
            // silêncio deliberado: já estamos no caminho de erro
        }

        return self::msg(
            'builder_manual_fallback',
            'Consulta automática indisponível. Preencha os dados manualmente.'
        );
    }
}
