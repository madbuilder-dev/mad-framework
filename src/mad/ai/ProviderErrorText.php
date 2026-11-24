<?php

namespace Mad\Ai;

/**
 * ProviderErrorText — o texto cru de um erro do provedor de IA nunca vai para
 * o navegador de quem usa o app.
 *
 * O caso real: no modo proxy, o 402 do OpenRouter chegava inteiro no frame
 * `error` do /embed/v1/chat — "OpenRouter API error 402 (out of credits…):
 * {"error":…,"user_id":"user_…"}" —, com o id da conta da plataforma. A tela
 * mostrava a frase genérica, mas o SSE levava o detalhe.
 *
 * Mensagem já escrita para o usuário (cota, chat desligado, sem permissão…)
 * passa como está. Texto de provedor/exceção vira uma de duas frases neutras —
 * IA indisponível no momento × erro do próprio pedido — e o detalhe vai para
 * o log do servidor com ids, chaves e tokens mascarados.
 */
final class ProviderErrorText
{
    /** Provedor fora do ar, sem crédito, limitando ou demorando: passa sozinho. */
    public const UNAVAILABLE = 'A IA está temporariamente indisponível. Tente de novo em alguns minutos.';

    /** O provedor recusou o pedido em si (grande demais, formato inválido). */
    public const REQUEST = 'Não consegui processar este pedido. Tente reformular a pergunta ou dividi-la em partes menores.';

    public const CODE_UNAVAILABLE = 'AI_UNAVAILABLE';

    public const CODE_REQUEST = 'AI_REQUEST_ERROR';

    /** Mensagem pronta para o usuário passa; texto cru vira neutro (e vai mascarado para o log). */
    public static function forUser(string $message, string $context = 'mad-ai'): string
    {
        if (! self::isRaw($message)) {
            return $message;
        }
        self::report($context, $message);

        return self::kind($message) === 'request' ? self::REQUEST : self::UNAVAILABLE;
    }

    /** Código do frame quando a mensagem foi trocada (null quando passou como estava). */
    public static function codeFor(string $message): ?string
    {
        if (! self::isRaw($message)) {
            return null;
        }

        return self::kind($message) === 'request' ? self::CODE_REQUEST : self::CODE_UNAVAILABLE;
    }

    /** É texto de provedor/exceção, não uma frase escrita para o usuário? */
    public static function isRaw(string $message): bool
    {
        $m = trim($message);
        if ($m === '') {
            return false;
        }

        return mb_strlen($m) > 400
            || (bool) preg_match('/\{\s*"|"(error|message|code|type)"\s*:/', $m)
            || (bool) preg_match('/\b(user_id|request_id|org_id|api[_-]?key|x-api-key|bearer)\b/i', $m)
            || (bool) preg_match('/\b(api error|openrouter|anthropic|openai|googleapis|generativelanguage|gemini|x\.ai|xai|grok|mistral|deepseek|groq|bedrock|vertex)\b/i', $m)
            || (bool) preg_match('/\b(stack trace|exception|sqlstate|curl error|guzzle|stream_socket|status code \d{3})\b/i', $m)
            || (bool) preg_match('#https?://#i', $m);
    }

    /** 'unavailable' (passa sozinho) ou 'request' (o pedido em si foi recusado). */
    public static function kind(string $message): string
    {
        if (preg_match('/\b(402|408|429|5\d\d)\b|credit|quota|rate.?limit|too many requests|overload|capacity|timeout|timed out|unavailable|insufficient|billing|connection|could not resolve|curl error/i', $message)) {
            return 'unavailable';
        }
        if (preg_match('/\b(400|413|422)\b|invalid|context.?length|maximum context|too long|too large|bad request|schema|malformed|exceed|unsupported|not supported|max_output_tokens/i', $message)) {
            return 'request';
        }

        return 'unavailable';
    }

    /** Ids, chaves e tokens mascarados — para o log do servidor. */
    public static function mask(string $text): string
    {
        $text = (string) preg_replace(
            '/("(?:user_id|userId|request_id|requestId|org_id|organization|account_id|api_key|apiKey|key|token|access_token|secret|authorization)"\s*:\s*)"[^"]*"/i',
            '$1"***"',
            $text,
        );
        $text = (string) preg_replace('/\b(user|org|acct|req)_[A-Za-z0-9]{6,}/', '$1_***', $text);
        $text = (string) preg_replace('/\bsk-[A-Za-z0-9_\-]{6,}/', 'sk-***', $text);
        $text = (string) preg_replace('/\b(Bearer)\s+[A-Za-z0-9._\-~+\/=]+/i', '$1 ***', $text);
        $text = (string) preg_replace('/([?&](?:key|token|api_key|access_token)=)[^&\s"\']+/i', '$1***', $text);
        $text = (string) preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '***@***', $text);

        return $text;
    }

    /** Detalhe do erro (mascarado) no log do servidor. */
    public static function report(string $context, string $message): void
    {
        \error_log('[' . $context . '] erro do provedor de IA (' . self::kind($message) . '): ' . self::mask(mb_substr($message, 0, 2000)));
    }
}
