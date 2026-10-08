<?php

namespace Mad\Form;

/**
 * Regras dos campos de upload que valem no SERVIDOR.
 *
 * Três assuntos, todos lidos das definições do campo (as props que a tag
 * registra no MadFormRegistry):
 *
 *  - {@see writePrint()}: a impressão digital de ONDE o campo grava fora da
 *    coluna do registro (tabela, chave estrangeira, pasta, coluna de nome,
 *    tabela de ligação). O MadForm anota a de cada campo que a tela desenha e
 *    só grava quando o formulário recebido descreve o campo do mesmo jeito;
 *  - {@see check()}: tamanho, tipo e quantidade que o campo declara, e o
 *    arquivo que o PHP recusou (acima do `upload_max_filesize`, envio
 *    interrompido). Vira erro no campo, antes de qualquer gravação;
 *  - {@see oversizedPost()}: a requisição que passou do `post_max_size` — o
 *    PHP descarta o corpo inteiro, e sem isto a tela respondia com um erro que
 *    não dizia nada ao usuário.
 *
 * O navegador confere as mesmas coisas antes de enviar (mad-ui.js); aqui é
 * onde a regra vale de fato.
 */
final class MadUploadRules
{
    /**
     * Extensões que nenhum upload grava (executável do servidor, configuração
     * do Apache, conteúdo ATIVO que o navegador renderizaria na mesma origem —
     * svg com `<script>`, HTML, XML). A lista do navegador
     * (`_MAD_UPLOAD_BLOCKED`, mad-ui.js) espelha esta — travado em teste.
     */
    public const BLOCKED_EXTENSIONS = [
        // PHP variants
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps',
        'pht', 'phar', 'phpt', 'inc',
        // Apache config
        'htaccess', 'htpasswd',
        // Other server-side scripts
        'cgi', 'pl', 'py', 'sh', 'rb', 'asp', 'aspx', 'jsp', 'jspx',
        // Windows executables
        'exe', 'bat', 'cmd', 'msi', 'com', 'scr',
        // Conteudo ATIVO (renderiza/executa same-origin -> stored XSS): svg com
        // <script>, HTML, XML/XHTML, etc. Nunca aceitar no upload.
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'mathml', 'vtt',
    ];

    /** Tipos com que os campos de upload se registram. */
    private const UPLOAD_TYPES = ['file', 'image', 'multi-file'];

    /**
     * Props que decidem onde e o que o campo grava fora do `fill()` do
     * registro — e os limites dele. É o que entra na impressão digital.
     */
    private const WRITE_PROPS = [
        'type', 'storage', 'folder', 'mode', 'model', 'foreignKey', 'pathColumn', 'nameColumn', 'fileName',
        'originalNameColumn', 'sizeColumn', 'mimeColumn', 'diskColumn',
        'pivotModel', 'itemKey', 'database', 'separator',
        'accept', 'maxBytes', 'maxFiles',
    ];

    /** Tipo pelo NOME do arquivo (é o que decide como ele será servido depois). */
    private const EXTENSION_TYPES = [
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'jfif' => 'image/jpeg', 'pjpeg' => 'image/jpeg',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp', 'svg' => 'image/svg+xml', 'svgz' => 'image/svg+xml',
        'avif' => 'image/avif', 'heic' => 'image/heic', 'heif' => 'image/heif', 'tif' => 'image/tiff', 'tiff' => 'image/tiff',
        'ico' => 'image/x-icon',
        'pdf' => 'application/pdf', 'zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed',
        'json' => 'application/json', 'xml' => 'application/xml', 'rtf' => 'application/rtf',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'txt' => 'text/plain', 'csv' => 'text/csv', 'html' => 'text/html', 'htm' => 'text/html', 'md' => 'text/markdown',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'oga' => 'audio/ogg', 'm4a' => 'audio/mp4',
        'aac' => 'audio/aac', 'flac' => 'audio/flac', 'weba' => 'audio/webm', 'opus' => 'audio/opus',
        'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska', 'mpeg' => 'video/mpeg', 'mpg' => 'video/mpeg', 'ogv' => 'video/ogg', '3gp' => 'video/3gpp',
    ];

    /** Nomes alternativos do mesmo tipo, como aparecem em `accept` escrito à mão. */
    private const TYPE_ALIASES = [
        'image/jpg' => 'image/jpeg', 'image/pjpeg' => 'image/jpeg', 'image/x-png' => 'image/png',
        'audio/mp3' => 'audio/mpeg', 'audio/x-wav' => 'audio/wav', 'application/x-pdf' => 'application/pdf',
        'application/x-zip-compressed' => 'application/zip',
    ];

    /** @var array{0: int|null, 1: int|null}|null limites do PHP trocados num teste (arquivo, requisição) */
    private static ?array $serverLimits = null;

    // ── Onde o campo grava ────────────────────────────────────────────────────

    /**
     * Impressão digital de onde (e com quais limites) o campo grava fora do
     * `fill()` do registro — ou null quando ele não grava nada assim.
     *
     * Vale para o campo de arquivo com `storage` (Upload, Imagem, Avatar,
     * Assinatura, Upload Múltiplo) e para qualquer campo em `mode="table"` (a
     * seleção múltipla que sincroniza uma tabela de ligação). As props são as
     * que a tag registrou: o mesmo array que vai no formulário (`__mad_form`).
     *
     * @param array<string,mixed> $props
     */
    public static function writePrint(array $props): ?string
    {
        $upload = in_array((string) ($props['type'] ?? ''), self::UPLOAD_TYPES, true)
            && (string) ($props['storage'] ?? '') !== '';
        if (!$upload && ($props['mode'] ?? '') !== 'table') {
            return null;
        }

        $parts = [];
        foreach (self::WRITE_PROPS as $key) {
            $value = $props[$key] ?? '';
            // Prop vazia não vai no formulário (MadFormRegistry::register): vale ''.
            if ($value === null || $value === false) {
                $value = '';
            }
            $parts[] = is_scalar($value) ? (string) $value : (string) json_encode($value);
        }

        return substr(sha1(implode("\x1f", $parts)), 0, 16);
    }

    /**
     * Os limites de uma Imagem guardada na PRÓPRIA coluna (campo `image` sem
     * `storage`): `a` = Tipos aceitos, `b` = Tamanho máximo em bytes — ou null
     * quando o campo não é desses, ou não declara limite nenhum.
     *
     * Esse campo não grava fora da coluna (não tem impressão em writePrint()):
     * o conteúdo chega como texto, em base64, entre os valores do formulário, e
     * entra no registro pelo `fill()`. O MadForm anota os limites no estado
     * cifrado da tela e confere por eles — o formulário `__mad_form`, que
     * também os traz, é o navegador quem devolve, e dá para omiti-lo ou
     * trocá-lo pelo de uma tela sem limite.
     *
     * @param  array<string,mixed> $props as props registradas do campo
     * @return array{a?: string, b?: int}|null
     */
    public static function inlineImageLimits(array $props): ?array
    {
        if ((string) ($props['type'] ?? '') !== 'image' || (string) ($props['storage'] ?? '') !== '') {
            return null;
        }
        $limits = [];
        $accept = trim((string) ($props['accept'] ?? ''));
        if ($accept !== '') {
            $limits['a'] = $accept;
        }
        $max = max(0, (int) ($props['maxBytes'] ?? 0));
        if ($max > 0) {
            $limits['b'] = $max;
        }

        return $limits !== [] ? $limits : null;
    }

    /**
     * Impressão digital das colunas de ARQUIVO das linhas de um detalhe (Lista
     * de itens / Detail Form): de cada coluna `type="file"`, `"multifile"` ou
     * `"files"`, onde ela grava — armazenamento, pasta, nome do arquivo, coluna
     * do nome e, na coluna Arquivos, o Model, a chave estrangeira e a coluna do
     * caminho da tabela dos arquivos. Sem coluna de arquivo também tem
     * impressão: a tela que não tem nenhuma não aceita as de outra.
     *
     * As definições viajam no formulário `__mad_form`
     * (`__mad_detail_file_cols`), que o navegador devolve. O MadForm anota a
     * impressão do que a tela desenhou e só grava as linhas quando o
     * formulário recebido descreve as colunas do mesmo jeito.
     *
     * @param array<string, array<string,mixed>> $columns coluna => definições (MadFormRegistry::registerDetailFileColumns)
     */
    public static function detailFilesPrint(array $columns): string
    {
        $parts = [];
        foreach ($columns as $field => $meta) {
            $meta = is_array($meta) ? $meta : [];
            ksort($meta);
            // Como texto: o formulário vai e volta em JSON, e `false`, `0` e ''
            // têm de dar a mesma impressão nos dois lados.
            $parts[(string) $field] = array_map(
                static fn (mixed $v): string => is_bool($v) || $v === null ? ($v ? '1' : '') : (is_scalar($v) ? (string) $v : (string) json_encode($v)),
                $meta,
            );
        }
        ksort($parts);

        return substr(sha1((string) json_encode($parts)), 0, 16);
    }

    // ── Tamanhos ──────────────────────────────────────────────────────────────

    /**
     * "10MB", "2,5 MB", "500kb", "1G" → bytes. Devolve 0 quando o texto não é
     * um tamanho (vazio, número sem unidade): o campo fica sem esse limite.
     */
    public static function bytes(mixed $size): int
    {
        $text = strtoupper(trim(is_scalar($size) ? (string) $size : ''));
        if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*(B|KB?|MB?|GB?)$/', $text, $m)) {
            return 0;
        }
        $factor = ['B' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][$m[2][0]];

        return (int) round(((float) str_replace(',', '.', $m[1])) * $factor);
    }

    /**
     * Tamanho para a mensagem: "850 KB", "2,5 MB". Com `$ceil`, arredonda para
     * cima (o arquivo de 2,004 MB sai "2,01 MB", não "2 MB" igual ao limite).
     */
    public static function human(int $bytes, bool $ceil = false): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $value = $bytes / 1024;
        $unit  = 'KB';
        foreach (['MB', 'GB'] as $next) {
            if ($value < 1024) {
                break;
            }
            $value /= 1024;
            $unit   = $next;
        }
        $value = $ceil ? ceil($value * 100) / 100 : round($value, 2);

        $comma = true;
        try {
            $comma = !str_starts_with(strtolower((string) app()->getLocale()), 'en');
        } catch (\Throwable) {
            // Sem aplicação (teste de unidade): vírgula.
        }
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return ($comma ? str_replace('.', ',', $text) : $text) . ' ' . $unit;
    }

    /** Valor de tamanho do php.ini ("32M", "2G", "8388608") em bytes; 0 = sem limite. */
    public static function iniBytes(string $value): int
    {
        $value = trim($value);
        $n     = (float) $value;
        if ($value === '' || $n <= 0) {
            return 0;
        }
        $factor = ['K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3][strtoupper(substr($value, -1))] ?? 1;

        return (int) ($n * $factor);
    }

    /** Maior arquivo que o PHP recebe (`upload_max_filesize`, limitado pelo `post_max_size`); 0 = sem limite. */
    public static function serverFileBytes(): int
    {
        $file  = self::$serverLimits[0] ?? self::iniBytes((string) ini_get('upload_max_filesize'));
        $post  = self::serverPostBytes();
        $known = array_filter([$file, $post]);

        return $known ? (int) min($known) : 0;
    }

    /** Maior requisição que o PHP recebe (`post_max_size`); 0 = sem limite. */
    public static function serverPostBytes(): int
    {
        return self::$serverLimits[1] ?? self::iniBytes((string) ini_get('post_max_size'));
    }

    /**
     * Troca os limites do PHP (não dá para mudar `upload_max_filesize` em
     * tempo de execução). Sem argumentos, volta aos do php.ini.
     *
     * @internal só para testes
     */
    public static function fakeServerLimits(?int $file = null, ?int $post = null): void
    {
        self::$serverLimits = ($file === null && $post === null) ? null : [$file, $post];
    }

    // ── Tipos ─────────────────────────────────────────────────────────────────

    /**
     * O arquivo é de um tipo que `accept` aceita? Mesma leitura do atributo
     * `accept` do `<input type="file">`: extensões (`.pdf`), tipos
     * (`application/pdf`) e famílias (`image/*`), separados por vírgula.
     * Vazio, `*` e `* / *` aceitam tudo.
     *
     * O tipo do arquivo sai do NOME (a extensão é o que decide como ele será
     * servido depois); só quando a extensão não é conhecida entra o conteúdo
     * (`$tmpPath`) e, por último, o tipo que o navegador declarou — que quem
     * envia escolhe, e por isso não basta sozinho.
     */
    public static function accepts(string $accept, string $fileName, string $tmpPath = '', string $declaredType = ''): bool
    {
        $tokens = array_values(array_filter(array_map('trim', explode(',', strtolower($accept))), 'strlen'));
        if ($tokens === []) {
            return true;
        }

        $extension = strtolower((string) pathinfo($fileName, PATHINFO_EXTENSION));
        $type      = null;
        foreach ($tokens as $token) {
            if ($token === '*' || $token === '*/*') {
                return true;
            }
            if (!str_contains($token, '/')) {
                // `.pdf` — e `pdf`, que o navegador ignoraria: aqui vale como
                // extensão. Extensões do MESMO tipo se equivalem (`.jpg` aceita
                // `foto.jpeg`: a imagem que o campo Imagem reenvia sai `.jpeg`).
                $wanted = ltrim($token, '.');
                if ($extension !== '' && ($wanted === $extension
                    || (isset(self::EXTENSION_TYPES[$wanted], self::EXTENSION_TYPES[$extension])
                        && self::EXTENSION_TYPES[$wanted] === self::EXTENSION_TYPES[$extension]))) {
                    return true;
                }
                continue;
            }

            $type ??= self::fileType($extension, $tmpPath, $declaredType);
            $token  = self::TYPE_ALIASES[$token] ?? $token;
            if ($type === '') {
                continue;
            }
            if (str_ends_with($token, '/*') ? str_starts_with($type, substr($token, 0, -1)) : $type === $token) {
                return true;
            }
        }

        return false;
    }

    private static function fileType(string $extension, string $tmpPath, string $declaredType): string
    {
        if (isset(self::EXTENSION_TYPES[$extension])) {
            return self::EXTENSION_TYPES[$extension];
        }

        $type = '';
        if ($tmpPath !== '' && is_file($tmpPath) && function_exists('mime_content_type')) {
            $type = strtolower((string) @mime_content_type($tmpPath));
        }
        // Conteúdo que o servidor não reconhece: o que o navegador declarou.
        if ($type === '' || $type === 'application/octet-stream') {
            $type = strtolower(trim($declaredType)) ?: $type;
        }

        return self::TYPE_ALIASES[$type] ?? $type;
    }

    /** `accept` para a mensagem: "PDF, imagens, DOCX". */
    public static function acceptLabel(string $accept): string
    {
        $families = ['image/*' => self::text('form.upload_kind_image', [], 'imagens'), 'audio/*' => self::text('form.upload_kind_audio', [], 'áudio'), 'video/*' => self::text('form.upload_kind_video', [], 'vídeo')];
        $labels   = [];
        foreach (array_filter(array_map('trim', explode(',', strtolower($accept))), 'strlen') as $token) {
            if (isset($families[$token])) {
                $labels[] = $families[$token];
                continue;
            }
            $short = str_contains($token, '/') ? (string) (array_search(self::TYPE_ALIASES[$token] ?? $token, self::EXTENSION_TYPES, true) ?: substr($token, strpos($token, '/') + 1)) : ltrim($token, '.');
            $labels[] = strtoupper($short);
        }

        return implode(', ', array_values(array_unique($labels)));
    }

    // ── A conferência do Salvar ───────────────────────────────────────────────

    /**
     * Confere os arquivos desta requisição contra o que cada campo de upload
     * do formulário declara. Devolve o primeiro problema de cada campo
     * (`campo => mensagem`); vazio = pode gravar.
     *
     *  - arquivo que o PHP recusou (acima do limite do servidor, envio
     *    interrompido, pasta temporária): antes o campo era tratado como "sem
     *    arquivo novo" e o registro era gravado sem o anexo, calado;
     *  - extensão que nenhum upload grava;
     *  - `maxBytes` (Tamanho máximo), `accept` (Tipos aceitos) e `maxFiles`
     *    (Máximo de arquivos) do campo — só quando o campo os declara.
     *
     * @param array<string,mixed> $schema  campos do formulário (nome => props)
     * @param array<string,mixed> $fields  valores do formulário (a Imagem sem `storage` vem aqui, em base64)
     * @return array<string,string>
     */
    public static function check(array $schema, array $fields = []): array
    {
        $errors = [];

        foreach ($schema as $name => $props) {
            $name = (string) $name;
            if (!is_array($props) || !in_array((string) ($props['type'] ?? ''), self::UPLOAD_TYPES, true)) {
                continue;
            }
            $max     = max(0, (int) ($props['maxBytes'] ?? 0));
            $accept  = (string) ($props['accept'] ?? '');
            $storage = (string) ($props['storage'] ?? '');

            $sent = 0;
            foreach (self::entries($_FILES[$name] ?? null) as $file) {
                $problem = self::phpProblem((int) $file['error'], (string) $file['name']);
                if ($problem === null && (int) $file['error'] === UPLOAD_ERR_OK && (string) $file['tmp_name'] !== '') {
                    $sent++;
                    $problem = self::fileProblem($file, $max, $accept, $storage !== '');
                }
                if ($problem !== null) {
                    $errors[$name] = $problem;
                    continue 2;
                }
            }

            $maxFiles = max(0, (int) ($props['maxFiles'] ?? 0));
            if ($maxFiles > 0 && $sent > 0) {
                $kept  = $_POST['__mad_existing_files'][$name] ?? [];
                $total = $sent + count(array_filter((array) $kept, static fn ($v) => is_scalar($v) && (string) $v !== ''));
                if ($total > $maxFiles) {
                    $errors[$name] = self::text('form.upload_too_many', ['count' => $total, 'max' => $maxFiles], 'São :count arquivos; o campo aceita no máximo :max.');
                    continue;
                }
            }

            // Imagem guardada na própria coluna (sem `storage`): o conteúdo chega
            // como texto, em base64, entre os valores do formulário. Confere
            // sempre que o valor é um endereço `data:` — escrito de qualquer
            // jeito que o navegador aceite (`DATA:`, espaço antes), e mesmo que
            // a requisição traga também um arquivo com o nome do campo.
            $value = $fields[$name] ?? null;
            if ($storage === '' && is_string($value) && ($max > 0 || $accept !== '') && self::isDataUrl($value)) {
                $problem = self::dataUrlProblem($value, $max, $accept);
                if ($problem !== null) {
                    $errors[$name] = $problem;
                }
            }
        }

        // Arquivos das células de uma Lista de itens: só o que o PHP recusou
        // (o limite da coluna é conferido pela própria lista).
        foreach (self::entries($_FILES['mad_fl_files'] ?? null) as $file) {
            $problem = self::phpProblem((int) $file['error'], (string) $file['name']);
            if ($problem !== null) {
                $errors['mad_fl_files'] = $problem;
                break;
            }
        }

        return $errors;
    }

    /**
     * O que o PHP diz de UM arquivo de `$_FILES` que ele não recebeu inteiro —
     * ou null (recebido, ou campo sem arquivo).
     */
    public static function phpProblem(int $error, string $fileName): ?string
    {
        $file = self::shortName($fileName);

        return match ($error) {
            UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE => null,
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => self::text(
                'form.upload_server_limit',
                ['file' => $file, 'max' => self::human(self::serverFileBytes())],
                'O arquivo :file passa do limite de envio do servidor (:max por arquivo) e não foi salvo.',
            ),
            UPLOAD_ERR_PARTIAL => self::text('form.upload_partial', ['file' => $file], 'O envio do arquivo :file foi interrompido. Envie de novo.'),
            default => self::serverFailure($error, $file),
        };
    }

    private static function serverFailure(int $error, string $file): string
    {
        // 6 = sem pasta temporária, 7 = disco, 8 = extensão do PHP: é do servidor.
        error_log('[MadUploadRules] o PHP não recebeu o arquivo "' . $file . '" (UPLOAD_ERR ' . $error . ').');

        return self::text('form.upload_server_failed', ['file' => $file], 'O servidor não conseguiu receber o arquivo :file. Tente de novo; se continuar, avise o suporte.');
    }

    /** @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file */
    private static function fileProblem(array $file, int $max, string $accept, bool $stored): ?string
    {
        $name      = self::shortName((string) $file['name']);
        $extension = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if ($stored && $extension !== '' && in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            return self::text('form.upload_ext_blocked', ['file' => $name, 'ext' => $extension], 'O arquivo :file não foi salvo: arquivos .:ext não são aceitos por segurança.');
        }
        if ($accept !== '' && !self::accepts($accept, (string) $file['name'], (string) $file['tmp_name'], (string) $file['type'])) {
            return self::text('form.upload_type', ['file' => $name, 'accept' => self::acceptLabel($accept)], 'O arquivo :file não é de um tipo aceito neste campo (:accept).');
        }
        if ($max > 0) {
            $size = is_file((string) $file['tmp_name']) ? (int) filesize((string) $file['tmp_name']) : (int) $file['size'];
            if ($size > $max) {
                return self::text('form.upload_too_big', ['file' => $name, 'size' => self::human($size, true), 'max' => self::human($max)], 'O arquivo :file tem :size; o limite deste campo é :max.');
            }
        }

        return null;
    }

    /** O valor é um endereço `data:` (como o navegador o lê: sem distinguir maiúsculas, com espaço ou controle antes)? */
    public static function isDataUrl(string $value): bool
    {
        return preg_match('/^[\x00-\x20]*data:/i', $value) === 1;
    }

    private static function dataUrlProblem(string $value, int $max, string $accept): ?string
    {
        if (preg_match('#^[\x00-\x20]*data:([a-z0-9.+/-]+)?((?:;[^,]*)?),#i', $value, $m)) {
            $type    = strtolower($m[1] ?? '');
            $payload = substr($value, strlen($m[0]));
            $base64  = preg_match('/;\s*base64\s*$/i', $m[2] ?? '') === 1;
        } else {
            // `data:` sem a vírgula: não é uma imagem que o navegador mostre,
            // mas o texto inteiro iria para a coluna — o tamanho vale.
            [$type, $payload, $base64] = ['', $value, false];
        }

        if ($accept !== '' && $type !== '') {
            $extension = (string) (array_search(self::TYPE_ALIASES[$type] ?? $type, self::EXTENSION_TYPES, true) ?: '');
            if (!self::accepts($accept, 'imagem.' . ($extension !== '' ? $extension : 'bin'), '', $type)) {
                return self::text('form.upload_image_type', ['accept' => self::acceptLabel($accept)], 'A imagem não é de um tipo aceito neste campo (:accept).');
            }
        }
        if ($max > 0) {
            // Em base64 cada 4 letras são 3 bytes; fora dele o texto é o conteúdo.
            $size = $base64 ? (int) floor(strlen(rtrim($payload, '=')) * 3 / 4) : strlen($payload);
            if ($size > $max) {
                return self::text('form.upload_image_too_big', ['size' => self::human($size, true), 'max' => self::human($max)], 'A imagem tem :size; o limite deste campo é :max.');
            }
        }

        return null;
    }

    // ── Requisição acima do post_max_size ─────────────────────────────────────

    /**
     * A requisição passou do `post_max_size`: o PHP entrega `$_POST` e
     * `$_FILES` VAZIOS, sem erro. Devolve a mensagem para o usuário (com o
     * tamanho enviado e o limite) — ou null quando não é o caso.
     */
    public static function oversizedPost(): ?string
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST' || $_POST !== [] || $_FILES !== []) {
            return null;
        }
        $sent = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $max  = self::serverPostBytes();
        if ($sent <= 0 || $max <= 0 || $sent <= $max) {
            return null;
        }

        return self::text(
            'form.upload_post_limit',
            ['size' => self::human($sent, true), 'max' => self::human($max)],
            'O envio tem :size e o servidor aceita até :max de uma vez. Nada foi salvo: envie arquivos menores ou em menos arquivos por vez.',
        );
    }

    /** Título do aviso de envio recusado pelo servidor. */
    public static function oversizedTitle(): string
    {
        return self::text('form.upload_limit_title', [], 'Envio acima do limite');
    }

    // ── apoio ─────────────────────────────────────────────────────────────────

    /**
     * Uma entrada de `$_FILES` (um arquivo, vários, ou as células aninhadas de
     * `mad_fl_files`) como lista de arquivos.
     *
     * @return list<array{name:string,type:string,tmp_name:string,error:int,size:int}>
     */
    public static function entries(mixed $raw): array
    {
        if (!is_array($raw) || !array_key_exists('error', $raw)) {
            return [];
        }

        $files = [];
        $walk  = static function (mixed $error, array $path) use (&$walk, &$files, $raw): void {
            if (is_array($error)) {
                foreach ($error as $key => $inner) {
                    $walk($inner, [...$path, $key]);
                }

                return;
            }
            $part = static function (string $what) use ($raw, $path): mixed {
                $value = $raw[$what] ?? null;
                foreach ($path as $key) {
                    $value = is_array($value) ? ($value[$key] ?? null) : null;
                }

                return $value;
            };
            $files[] = [
                'name'     => (string) (is_scalar($part('name')) ? $part('name') : ''),
                'type'     => (string) (is_scalar($part('type')) ? $part('type') : ''),
                'tmp_name' => (string) (is_scalar($part('tmp_name')) ? $part('tmp_name') : ''),
                'error'    => (int) $error,
                'size'     => (int) (is_scalar($part('size')) ? $part('size') : 0),
            ];
        };
        $walk($raw['error'], []);

        return $files;
    }

    /** Nome do arquivo para a mensagem: sem caminho, sem marcação, curto. */
    private static function shortName(string $name): string
    {
        $name = trim(strip_tags(basename(str_replace(['\\', "\0"], ['/', ''], $name))));
        if ($name === '') {
            return self::text('form.upload_unnamed', [], 'enviado');
        }

        return mb_strlen($name) > 60 ? mb_substr($name, 0, 28) . '…' . mb_substr($name, -28) : $name;
    }

    /** Texto do catálogo do app, com o texto em português de reserva quando a chave não existe. */
    private static function text(string $key, array $replace, string $fallback): string
    {
        try {
            $text = function_exists('__') ? (string) __($key, $replace) : '';
        } catch (\Throwable) {
            $text = '';
        }
        if ($text !== '' && $text !== $key) {
            return $text;
        }
        foreach ($replace as $k => $v) {
            $fallback = str_replace(':' . $k, (string) $v, $fallback);
        }

        return $fallback;
    }
}
