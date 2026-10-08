<?php

namespace Mad\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * StoredSecret — a FORMA em que um segredo (senha, chave de API, token, segredo
 * de webhook) fica guardado em banco ou arquivo: cifrado com a chave do app
 * (`Crypt::encryptString`, `APP_KEY`).
 *
 * É o ponto único da cifragem. Quem guarda um segredo não chama `Crypt` direto:
 *
 *     $gravar = StoredSecret::seal($digitado);          // para gravar
 *     $claro  = StoredSecret::open($gravado, 'Tela › Campo'); // para usar
 *
 * `open()` é o que mantém um app antigo de pé:
 *
 *  - valor em TEXTO PURO (gravado antes da cifragem) volta como está — o app
 *    segue funcionando até a migration ou o próximo Salvar cifrarem;
 *  - valor cifrado com OUTRA chave (a `APP_KEY` foi trocada sem
 *    `APP_PREVIOUS_KEYS`) não tem como ser lido: volta '' — o segredo passa a
 *    valer como NÃO CONFIGURADO — e o motivo vai para o log, com o nome do
 *    lugar onde informar de novo. Devolver o texto cifrado como se fosse o
 *    segredo só trocaria um erro claro por um obscuro no serviço de fora;
 *  - nunca lança: tela e envio não caem por causa de um segredo ilegível.
 *
 * NUNCA logue o retorno de `open()`, e não o coloque em mensagem de erro.
 *
 * Os serviços do app que guardam segredos nas preferências
 * (`App\Service\Sys\PreferenceSecrets`, `MailSettings`, `BillingSettings`)
 * passam todos por aqui.
 */
final class StoredSecret
{
    /** Nada gravado. */
    public const EMPTY = 'empty';

    /** Cifrado com a chave deste app (ou com uma das `APP_PREVIOUS_KEYS`). */
    public const SEALED = 'sealed';

    /** Texto puro, de antes da cifragem: funciona, e deve ser cifrado. */
    public const PLAIN = 'plain';

    /** Cifrado com outra chave: não dá para ler. Vale como não configurado. */
    public const UNREADABLE = 'unreadable';

    /** Minutos entre dois avisos do mesmo segredo ilegível no log. */
    private const WARN_EVERY_MINUTES = 60;

    /** @var array<string, true> avisos já dados neste processo */
    private static array $warned = [];

    /** Cifra para gravar. '' continua '' (nada a guardar). */
    public static function seal(string $plain): string
    {
        return $plain === '' ? '' : Crypt::encryptString($plain);
    }

    /**
     * Segredo em claro a partir do valor gravado ('' quando não há ou quando
     * não dá para ler). `$label` diz ONDE o segredo é informado ("Preferências
     * › E-mail › Senha"): é o que o log mostra quando o valor está ilegível.
     */
    public static function open(?string $stored, string $label = ''): string
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return '';
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable $e) {
            if (! self::looksSealed($stored)) {
                return $stored; // texto puro, de antes da cifragem
            }
        }

        self::warnUnreadable($label);

        return '';
    }

    /** Em que pé está o valor gravado: EMPTY, SEALED, PLAIN ou UNREADABLE. */
    public static function state(?string $stored): string
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return self::EMPTY;
        }

        try {
            Crypt::decryptString($stored);

            return self::SEALED;
        } catch (\Throwable $e) {
            return self::looksSealed($stored) ? self::UNREADABLE : self::PLAIN;
        }
    }

    /** Há um segredo que dá para usar? (é o "configurado" das telas) */
    public static function usable(?string $stored): bool
    {
        return in_array(self::state($stored), [self::SEALED, self::PLAIN], true);
    }

    /** Está em texto puro e precisa ser cifrado? */
    public static function needsSealing(?string $stored): bool
    {
        return self::state($stored) === self::PLAIN;
    }

    /** Tem a forma de um valor cifrado pelo Laravel (com qualquer chave)? */
    public static function looksSealed(string $stored): bool
    {
        $json = base64_decode($stored, true);
        if ($json === false) {
            return false;
        }

        $payload = json_decode($json, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /** Zera os avisos já dados (testes). */
    public static function flush(): void
    {
        self::$warned = [];
    }

    /**
     * Diz no log POR QUE o segredo deixou de valer — sem o valor. Uma vez por
     * processo e, entre processos, uma vez por hora: o segredo é lido a cada
     * tela de login, a cada e-mail, a cada chamada de IA.
     */
    private static function warnUnreadable(string $label): void
    {
        $label = $label !== '' ? $label : 'segredo';

        if (isset(self::$warned[$label])) {
            return;
        }
        self::$warned[$label] = true;

        try {
            if (! Cache::add('mad:stored-secret:unreadable:' . sha1($label), 1, now()->addMinutes(self::WARN_EVERY_MINUTES))) {
                return;
            }
        } catch (\Throwable $e) {
            // Sem cache o aviso sai uma vez por processo, o que já basta.
        }

        try {
            Log::warning(
                '[segredo] ' . $label . ': o valor gravado foi cifrado com outra chave do app (APP_KEY) '
                . 'e não pode ser lido. Ele passa a valer como NÃO CONFIGURADO. Informe-o de novo, ou '
                . 'devolva a chave anterior em APP_PREVIOUS_KEYS.',
                ['secret' => $label],
            );
        } catch (\Throwable $e) {
            // Log indisponível nunca derruba quem só queria ler um segredo.
        }
    }
}
