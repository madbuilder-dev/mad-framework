<?php

declare(strict_types=1);

namespace Mad\Support;

/**
 * Remetente padrão dos e-mails do app (builder#659).
 *
 * O `.env` gerado pela plataforma traz um remetente de exemplo no domínio
 * `localhost` (`nao-responda@localhost`, `no-reply@localhost`): o domínio do
 * app só é conhecido no servidor. Servidor de e-mail de verdade recusa (ou manda
 * para spam) um remetente `@localhost`, e o `nao-responda` saía em português
 * num app em inglês.
 *
 * Enquanto o remetente for esse exemplo (ou estiver vazio), vale
 * `<no-reply no idioma do app>@<domínio do APP_URL>`. Endereço que o dono
 * configurou fica como está. Sem domínio de verdade no APP_URL (localhost, IP),
 * o exemplo também fica.
 */
final class MailFrom
{
    /** Partes locais que a plataforma grava no `.env` de exemplo. */
    private const PLACEHOLDERS = ['nao-responda', 'no-reply', 'no-responder', 'noreply'];

    public static function address(?string $configured, ?string $appUrl, ?string $locale): string
    {
        $configured = trim((string) $configured, " \t\"'");
        $local = strtolower((string) strstr($configured, '@', true));
        $domain = strtolower((string) substr((string) strrchr($configured, '@'), 1));

        $placeholder = $configured === ''
            || ($domain === 'localhost' && in_array($local, self::PLACEHOLDERS, true));
        if (! $placeholder) {
            return $configured;
        }

        $host = self::host($appUrl);
        if ($host === null) {
            return $configured !== '' ? $configured : self::localPart($locale) . '@localhost';
        }

        return self::localPart($locale) . '@' . $host;
    }

    /** `no-reply` (en e outros), `no-responder` (es), `nao-responda` (pt). */
    public static function localPart(?string $locale): string
    {
        return match (strtolower(substr(trim((string) $locale), 0, 2))) {
            'pt'    => 'nao-responda',
            'es'    => 'no-responder',
            default => 'no-reply',
        };
    }

    /** Domínio do APP_URL sem `www.`; null para localhost, IP ou URL sem host. */
    private static function host(?string $appUrl): ?string
    {
        $host = strtolower((string) parse_url(trim((string) $appUrl), PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? '';

        if ($host === '' || $host === 'localhost' || ! str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false || str_ends_with($host, '.localhost')) {
            return null;
        }

        return $host;
    }
}
