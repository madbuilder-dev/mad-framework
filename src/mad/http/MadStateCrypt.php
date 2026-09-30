<?php
namespace Mad\Http;
use Mad\Component\MadComponent;
use Mad\Form\MadFormRegistry;


/**
 * MadStateCrypt — criptografia autenticada para tokens do MadComponent / MadFormRegistry.
 *
 * Formato v2 (atual): AES-256-GCM com tag de autenticacao + payload com _iat e _nonce.
 *   Garante:
 *     - Confidencialidade (AES-256)
 *     - Integridade/autenticidade (tag GCM — bit-flip e detectado)
 *     - Frescor opcional via _iat (timestamp); chamadores podem validar idade max
 *
 * Formato v1 (legado): AES-256-CBC sem MAC. NAO E MAIS ACEITO — removido por ser
 *   superficie de padding-oracle / bit-flip. Tokens v1 antigos sao invalidos
 *   (o MadComponentHandler re-renderiza o estado quando o decrypt retorna null).
 *
 * Tokens de SERVIÇO (busca, cascata, auto-fill, CEP/CNPJ, cadastro rápido…):
 *   {@see encryptFor()} / {@see decryptFor()} atrelam o token a uma FINALIDADE
 *   (`_p`) e ao USUÁRIO que recebeu a página (`_u`). Todos os tokens usam a
 *   mesma chave, então sem a finalidade qualquer serviço aceitava qualquer
 *   token: o de cascata de um combo (`data-mad-dep-token`, sem o filtro do
 *   combo) colado em /app/services/db-search listava o model inteiro da
 *   unidade, ignorando a regra de carregamento do combo.
 *   Compatível por construção: token SEM `_p` (cunhado por versão anterior,
 *   HTML em cache, código do app que cunha com encrypt()) segue aceito — só o
 *   token de OUTRA finalidade é recusado. `decrypt()` continua sem checar nada
 *   (estado de componente, schema de formulário) e remove `_p`/`_u` do retorno.
 */
class MadStateCrypt
{
    /** Chave do payload: finalidade do token de serviço. */
    private const PURPOSE_KEY = '_p';

    /** Chave do payload: usuário (session('userid')) que recebeu a página. */
    private const USER_KEY = '_u';

    /** Prefixo do formato autenticado v2. */
    private const V2_PREFIX = 'v2:';

    /** Tamanho do IV/nonce do GCM (12 bytes — recomendado pelo NIST). */
    private const GCM_IV_LEN = 12;

    /** Tamanho da tag de autenticacao GCM (16 bytes). */
    private const GCM_TAG_LEN = 16;

    /**
     * Serializa e criptografa um payload array.
     * Adiciona automaticamente _iat (issued-at, segundos epoch) e _nonce
     * (8 bytes random hex) caso o chamador nao tenha definido.
     *
     * Retorna string com prefixo "v2:" + base64(iv || tag || cipher).
     */
    public static function encrypt(array $payload): string
    {
        if (!isset($payload['_iat'])) {
            $payload['_iat'] = time();
        }
        if (!isset($payload['_nonce'])) {
            $payload['_nonce'] = bin2hex(random_bytes(8));
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new \RuntimeException('MadStateCrypt: failed to encode payload as JSON');
        }

        $key  = self::deriveKey();
        $iv   = random_bytes(self::GCM_IV_LEN);
        $tag  = '';

        $cipher = openssl_encrypt(
            $json,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',                       // additional authenticated data (vazio)
            self::GCM_TAG_LEN
        );

        if ($cipher === false) {
            throw new \RuntimeException('MadStateCrypt: openssl_encrypt failed');
        }

        return self::V2_PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Descriptografa e decodifica um token gerado por encrypt().
     *
     * @param string   $token   Token criado por encrypt().
     * @param int|null $maxAge  Se nao-nulo, rejeita tokens com _iat mais antigo
     *                          que $maxAge segundos (anti-replay/freshness opt-in).
     * @param bool     $keepBinding  uso interno de decryptFor(): mantém `_p`/`_u`.
     *
     * @return array|null  Payload original (sem _iat/_nonce/_p/_u) ou null se invalido.
     */
    public static function decrypt(string $token, ?int $maxAge = null, bool $keepBinding = false): ?array
    {
        if ($token === '') {
            return null;
        }

        $key = self::deriveKey();

        // APENAS v2 (AES-256-GCM autenticado). O formato v1 (AES-256-CBC SEM MAC)
        // NAO e mais aceito — era superficie de padding-oracle / bit-flip. Tokens
        // v1 antigos sao tratados como invalidos (o handler re-renderiza o estado).
        if (!str_starts_with($token, self::V2_PREFIX)) {
            return null;
        }

        $data = self::decryptV2(substr($token, strlen(self::V2_PREFIX)), $key);

        if (!is_array($data)) {
            return null;
        }

        // Freshness check opt-in (so executa quando o chamador especifica maxAge).
        if ($maxAge !== null && isset($data['_iat'])) {
            $age = time() - (int) $data['_iat'];
            if ($age > $maxAge || $age < -60) {
                // Rejeita tokens muito antigos OU com timestamp futuro implausivel.
                return null;
            }
        }

        // Remove metadados internos antes de devolver.
        unset($data['_iat'], $data['_nonce']);
        if (!$keepBinding) {
            unset($data[self::PURPOSE_KEY], $data[self::USER_KEY]);
        }
        return $data;
    }

    /**
     * Token de SERVIÇO: `encrypt()` + finalidade + usuário que recebe a página.
     *
     * A finalidade é o nome do consumidor ('db-search', 'db-combo', 'auto-fill',
     * 'cep'…) — {@see decryptFor()} do serviço recusa token cunhado para outro.
     * Usuário anônimo (página pública) não carimba `_u`: o token vale para quem
     * estiver na sessão.
     */
    public static function encryptFor(string $purpose, array $payload): string
    {
        $payload[self::PURPOSE_KEY] = $purpose;
        $user = self::currentUserId();
        if ($user !== null) {
            $payload[self::USER_KEY] = $user;
        }

        return self::encrypt($payload);
    }

    /**
     * Abre um token de SERVIÇO. Null (= token inválido para o chamador) quando:
     *  - foi cunhado para OUTRA finalidade (`_p` fora de $purpose);
     *  - foi cunhado para OUTRO usuário (`_u` ≠ session('userid'), inclusive
     *    sessão que terminou) — o token está no HTML de uma página que era de
     *    outra pessoa;
     *  - passou da idade máxima: $maxAge, ou `mad.security.service_token_max_age`
     *    (segundos; vazio = sem limite — o padrão, porque uma página pode ficar
     *    aberta o dia inteiro, e não há como renovar o token sem recarregar).
     *
     * Token SEM finalidade (versão anterior, HTML em cache, `encrypt()` de
     * código do app) é aceito como antes: só a mistura de finalidades é barrada.
     *
     * @param string|list<string> $purpose finalidade(s) que este consumidor aceita
     */
    public static function decryptFor(string|array $purpose, string $token, ?int $maxAge = null): ?array
    {
        $data = self::decrypt($token, $maxAge ?? self::serviceTokenMaxAge(), true);
        if ($data === null) {
            return null;
        }

        $tokenPurpose = $data[self::PURPOSE_KEY] ?? null;
        if ($tokenPurpose !== null && !in_array((string) $tokenPurpose, (array) $purpose, true)) {
            return null;
        }
        if (array_key_exists(self::USER_KEY, $data)) {
            $user = self::currentUserId();
            if ($user === null || (int) $data[self::USER_KEY] !== $user) {
                return null;
            }
        }

        unset($data[self::PURPOSE_KEY], $data[self::USER_KEY]);
        return $data;
    }

    /** Idade máxima opcional dos tokens de serviço (segundos); null = sem limite. */
    private static function serviceTokenMaxAge(): ?int
    {
        try {
            $v = function_exists('config') ? config('mad.security.service_token_max_age') : null;
        } catch (\Throwable) {
            $v = null;
        }

        return (is_numeric($v) && (int) $v > 0) ? (int) $v : null;
    }

    /** Usuário do request (o app gerado identifica por session('userid')). */
    private static function currentUserId(): ?int
    {
        return \Mad\Database\DataScope::userId();
    }

    /** AES-256-GCM (autenticado). */
    private static function decryptV2(string $b64, string $key): ?array
    {
        try {
            $raw = base64_decode($b64, true);
            $minLen = self::GCM_IV_LEN + self::GCM_TAG_LEN + 1;
            if (!$raw || strlen($raw) < $minLen) {
                return null;
            }

            $iv     = substr($raw, 0, self::GCM_IV_LEN);
            $tag    = substr($raw, self::GCM_IV_LEN, self::GCM_TAG_LEN);
            $cipher = substr($raw, self::GCM_IV_LEN + self::GCM_TAG_LEN);

            $payload = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($payload === false) {
                // Tag invalida = corrupcao/forge detectado.
                return null;
            }

            $data = json_decode($payload, true);
            return is_array($data) ? $data : null;

        } catch (\Throwable) {
            return null;
        }
    }

    /** Deriva chave AES-256 (32 bytes) determiniticamente da secret do registry. */
    private static function deriveKey(): string
    {
        return hash('sha256', MadFormRegistry::getSecret(), true);
    }
}
