<?php
namespace Mad\Component;
use Mad\Form\MadForm;
use Mad\Form\MadFormRegistry;
use Mad\Form\MadValidationException;
use Mad\Form\FieldListColumn;
use Mad\Http\MadResponse;
use Mad\Http\CurrentControl;
use Mad\Http\MadStateCrypt;
use Mad\Security\PermissionGate;
use Mad\Ui\MadAction;
use Mad\Ui\MadForbidden;
use Mad\Registry\MadVarRegistry;


/**
 * MadComponentHandler — processa requisições AJAX dos componentes reativos.
 *
 * O cliente envia:
 *   mad_state   → string base64 criptografada (classe + estado atual)
 *   mad_id      → id do componente no DOM
 *   mad_action  → nome do método público a chamar (opcional)
 *   mad_params  → JSON com os parâmetros do método (opcional)
 *   mad_model[] → array de propriedades mad:model vindas do formulário
 *
 * Responde com JSON:
 *   { "id": "mc_xxx", "html": "<div>...</div>" }
 *   ou em caso de erro:
 *   { "error": "mensagem" }
 *
 * Como registrar num endpoint dedicado:
 *   if ($_POST['__mad_component'] ?? false) {
 *       \Mad\Component\MadComponentHandler::handle();
 *   }
 */
class MadComponentHandler
{
    /**
     * Ponto de entrada HTTP.
     * Deve ser chamado antes de qualquer saída HTML.
     */
    public static function handle(): void
    {
        // Descarta qualquer output acumulado antes desta chamada
        // (erros PHP com display_errors, buffers do framework legado, BOM, etc.)
        // Isso é o mesmo que o Livewire/Laravel fazem internamente.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');

        // Captura qualquer saida acidental durante process() (warnings/notices
        // com display_errors=on, echos/prints, etc) pra nao corromper o JSON.
        ob_start();

        try {
            $result = self::process($_POST);

            // Recusa por permissão carimba o status (403). É de transporte:
            // sai no cabeçalho, não no corpo — quem monitora precisa ver a
            // negativa, e o front continua lendo só `error`.
            if (isset($result['status'])) {
                http_response_code((int) $result['status']);
                unset($result['status']);
            }

            $stray = ob_get_clean();

            $debugPayload = \Mad\Service\MadLogService::getDebugPayload();
            if ($debugPayload) {
                $result['_debug'] = $debugPayload;
            }

            // Se houve warning/notice/echo solto, injeta modal de erro no
            // comeco dos ops pra o dev enxergar (em vez de corromper o JSON).
            if ($stray !== '' && $stray !== false) {
                $result = self::_prependStrayOutputModal($result, $stray);
            }

            $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            echo $json;
        } catch (\Throwable $e) {
            $stray = ob_get_clean();
            http_response_code(500);
            $payload = self::exceptionPayload($e, $_POST);
            if ($stray !== '' && $stray !== false) {
                $payload['error'] .= "\n\nOutput capturado:\n" . strip_tags($stray);
                if (isset($payload['_exception'])) {
                    $payload['_exception']['stray_output'] = strip_tags($stray);
                }
            }
            echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        exit;
    }

    /**
     * Payload JSON de uma exception não tratada no ciclo wire.
     *
     * O front (MadErrorModal, em mad-livewire.js) usa `_exception` para abrir o
     * modal 90×90 com trace + snippet e gerar o markdown pro agente de IA.
     *
     * Detalhes (arquivo, linha, trace, snippet) SÓ vão com APP_DEBUG ligado —
     * em produção volta apenas a mensagem genérica.
     *
     * @param array<string,mixed> $post $_POST do request wire (contexto)
     * @return array{error:string,_exception?:array<string,mixed>}
     */
    public static function exceptionPayload(\Throwable $e, array $post = []): array
    {
        if (!self::_debugEnabled()) {
            return ['error' => 'Erro interno no servidor. Consulte o log da aplicação.'];
        }

        $exception = [
            'type'      => get_class($e),
            'message'   => $e->getMessage(),
            'code'      => $e->getCode(),
            'file'      => self::_relPath($e->getFile()),
            'line'      => $e->getLine(),
            'php'       => PHP_VERSION,
            'laravel'   => function_exists('app') ? app()->version() : '',
            'framework' => class_exists(\Mad\Support\MadFramework::class)
                ? \Mad\Support\MadFramework::version()
                : '',
            'action'    => isset($post['mad_action']) ? (string) $post['mad_action'] : '',
            'component' => isset($post['mad_id']) ? (string) $post['mad_id'] : '',
            'snippet'   => self::_sourceSnippet($e->getFile(), $e->getLine()),
            'trace'     => self::_traceLines($e),
        ];

        $prev = $e->getPrevious();
        if ($prev instanceof \Throwable) {
            $exception['previous'] = get_class($prev) . ': ' . $prev->getMessage()
                . ' (' . self::_relPath($prev->getFile()) . ':' . $prev->getLine() . ')';
        }

        return [
            'error'      => get_class($e) . ': ' . $e->getMessage(),
            '_exception' => $exception,
        ];
    }

    /** APP_DEBUG ligado? (config quando há Laravel; env como fallback) */
    private static function _debugEnabled(): bool
    {
        if (function_exists('config')) {
            return (bool) config('app.debug', false);
        }
        $env = getenv('APP_DEBUG');
        return $env !== false && filter_var($env, FILTER_VALIDATE_BOOLEAN);
    }

    /** Caminho relativo à raiz do projeto (não vaza a árvore do servidor). */
    private static function _relPath(string $path): string
    {
        $base = function_exists('base_path') ? base_path() : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/\\');
        }
        return $path;
    }

    /** Linhas ao redor da origem do erro, numeradas (contexto pro agente). */
    private static function _sourceSnippet(string $file, int $line, int $radius = 8): string
    {
        if ($file === '' || !is_file($file) || !is_readable($file) || filesize($file) > 2_000_000) {
            return '';
        }

        $lines = @file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) return '';

        $start = max(0, $line - $radius - 1);
        $end   = min(count($lines) - 1, $line + $radius - 1);
        $out   = [];

        for ($i = $start; $i <= $end; $i++) {
            $marker = ($i + 1 === $line) ? '>' : ' ';
            $out[]  = sprintf('%s %5d | %s', $marker, $i + 1, $lines[$i]);
        }

        return implode("\n", $out);
    }

    /**
     * Stack trace em linhas, com caminhos relativos e limite de frames.
     *
     * @return string[]
     */
    private static function _traceLines(\Throwable $e, int $max = 40): array
    {
        $base  = function_exists('base_path') ? base_path() : '';
        $lines = explode("\n", $e->getTraceAsString());

        if ($base !== '') {
            $lines = array_map(static fn ($l) => str_replace($base . DIRECTORY_SEPARATOR, '', $l), $lines);
        }

        if (count($lines) > $max) {
            $omitted = count($lines) - $max;
            $lines   = array_slice($lines, 0, $max);
            $lines[] = '… (' . $omitted . ' frames omitidos)';
        }

        return $lines;
    }

    /**
     * {@see self::process()} com a saída solta capturada — para as portas
     * Laravel do wire (`MadAppController::wire`, `MadSiteWireController`), que
     * devolvem um JsonResponse em vez de ecoar como o {@see self::handle()}.
     *
     * Sem o buffer, um `MadResponse::emit()` (ou echo/print) dentro da ação
     * saía ANTES do JSON: o PHP acusava "headers already sent", a resposta da
     * ação se perdia e o front recebia só o `<script>`, que rodava fora do
     * componente — um `closeDrawer()` ali não achava a gaveta. Agora a saída
     * vira ops pela mesma regra do handle(): `<script>` → op `script` (roda com
     * o escopo do componente), texto solto → diálogo só com `app.debug`.
     *
     * Exception: a saída parcial é descartada e a exception segue para quem
     * chamou (que monta o JSON de erro).
     *
     * @param  array<string,mixed>  $data  $_POST do wire
     * @return array<string,mixed>
     */
    public static function processCapturingOutput(array $data): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $result = self::process($data);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        // A ação pode ter aberto buffers próprios sem fechar: junta tudo, na
        // ordem em que foi escrito (o buffer de fora veio antes).
        $stray = '';
        while (ob_get_level() > $level) {
            $stray = (string) ob_get_clean() . $stray;
        }

        return $stray !== '' ? self::_prependStrayOutputModal($result, $stray) : $result;
    }

    /**
     * Ponte de teste para {@see self::_prependStrayOutputModal()}.
     *
     * O método é privado (e continua) porque ninguém de fora deve montar o
     * diálogo; o que o teste precisa provar é a REGRA — warning do PHP, que
     * carrega caminho de arquivo, não chega à tela com `app.debug` desligado.
     *
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    public static function _prependStrayOutputModalForTest(array $result, string $stray): array
    {
        return self::_prependStrayOutputModal($result, $stray);
    }

    /**
     * Adiciona um op de modal de erro com o output capturado no topo da lista
     * de ops, convertendo o handler em partial response quando necessario.
     */
    private static function _prependStrayOutputModal(array $result, string $stray): array
    {
        // Separa <script>...</script> (JS legitimo — download legado, abertura de pagina legada,
        // etc) do resto (warnings/notices/echos). Scripts sao executados como
        // op 'script'; o resto vira dialog de erro pro dev enxergar.
        $scriptJs = '';
        $clean = preg_replace_callback(
            '#<script\b[^>]*>(.*?)</script>#is',
            function ($m) use (&$scriptJs) {
                $scriptJs .= trim($m[1]) . "\n";
                return '';
            },
            $stray
        );

        $ops = (isset($result['ops']) && is_array($result['ops'])) ? $result['ops'] : [];

        // Texto solto sobrando (fora de <script>) = warning/notice/echo indevido.
        $remaining = trim(strip_tags((string) $clean));
        // Warning/notice do PHP carrega CAMINHO DE ARQUIVO e linha
        // ("Undefined array key ... in /var/www/app/control/Foo.php on line 12").
        // É diagnóstico de desenvolvedor: com `app.debug` desligado vai só para
        // o log, nunca para o diálogo na tela do usuário. Os <script> legítimos
        // (download, abertura de tela legada) continuam executando.
        if ($remaining !== '' && ! self::_debugEnabled()) {
            try {
                if (function_exists('logger')) {
                    logger()->warning('[mad-wire] saída PHP durante a requisição: ' . $remaining);
                }
            } catch (\Throwable $ignored) {
                // silêncio deliberado
            }
            $remaining = '';
        }
        if ($remaining !== '') {
            $js = 'MadDialog.show(' . json_encode([
                'type'    => 'error',
                'title'   => 'PHP output durante requisição',
                'message' => $remaining,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ')';
            array_unshift($ops, ['op' => 'script', 'content' => $js]);
        }

        // Scripts legitimos — executam apos o morph/aplicacao do html.
        $scriptJs = trim($scriptJs);
        if ($scriptJs !== '') {
            // Detecta __mad_error(...) / __mad_message(...) / __mad_warning(...)
            // emitidos por TExceptionView / TMessage em modo debug. Essas funcoes dependem
            // de bootbox+jquery (nao garantidos em telas MadComponent) e "somem" no eval
            // silencioso do op 'script'. Convertemos em op 'alert' (MadDialog.show),
            // que e sempre visivel no contexto MAD.
            $alertOps = self::_extractMadDialogCalls($scriptJs, $scriptJs);

            foreach ($alertOps as $alertOp) {
                array_unshift($ops, $alertOp);
            }

            if ($scriptJs !== '') {
                $ops[] = ['op' => 'script', 'content' => $scriptJs];
            }
        }

        if (!empty($ops)) {
            $result['ops'] = $ops;
        }
        return $result;
    }

    /**
     * Extrai chamadas __mad_error/__mad_message/__mad_warning do script
     * e as transforma em ops 'alert' (MadDialog.show), que sao sempre visiveis.
     * O script $js e modificado por referencia — as chamadas extraidas sao removidas.
     *
     * @return array<int, array{op:string,type:string,title:string,message:string}>
     */
    private static function _extractMadDialogCalls(string $jsIn, string &$jsOut): array
    {
        $out = [];
        $typeMap = [
            '__mad_error'   => 'error',
            '__mad_warning' => 'warning',
            '__mad_message' => 'info',
        ];

        // Casa chamadas tipo:  __mad_error('titulo', 'mensagem' [, callback]) ;
        // (aspas simples, com escapes \' ou \\)  — padrao emitido pelo gerador de scripts legado.
        $pattern = '/\b(__mad_(?:error|warning|message))\s*\(\s*\'((?:\\\\.|[^\'\\\\])*)\'\s*,\s*\'((?:\\\\.|[^\'\\\\])*)\'\s*(?:,\s*[^)]*)?\)\s*;?/s';

        $jsOut = preg_replace_callback(
            $pattern,
            function ($m) use (&$out, $typeMap) {
                $fn      = $m[1];
                $title   = stripslashes($m[2]);
                $message = stripslashes($m[3]);
                $type    = $typeMap[$fn] ?? 'info';

                $out[] = [
                    'op'      => 'alert',
                    'type'    => $type,
                    'title'   => $title,
                    'message' => $message,
                ];
                return '';
            },
            $jsIn
        );

        $jsOut = trim((string) $jsOut);
        return $out;
    }

    /**
     * Processa os dados recebidos e retorna o array de resposta.
     * Separado de handle() para facilitar testes.
     *
     * @param  array $data Normalmente $_POST
     * @return array { id, html } ou { error }
     */
    public static function process(array $data): array
    {
        // ── 1. Descriptografar estado ──────────────────────────────────────────
        $token = $data['mad_state'] ?? '';
        if (!$token) {
            return ['error' => 'mad_state ausente'];
        }

        $decoded = MadStateCrypt::decrypt($token);
        if (!$decoded) {
            return ['error' => 'mad_state inválido ou expirado'];
        }

        $class = $decoded['class'] ?? '';
        $state = $decoded['state'] ?? [];
        CurrentControl::set($class ?: null); // p/ class_name dos logs de auditoria
        $id    = $data['mad_id'] ?? '';

        // ── 2. Validar classe ──────────────────────────────────────────────────
        if (!self::_isValidComponent($class)) {
            return ['error' => "Classe inválida: {$class}"];
        }

        // ── 3. Hidratar componente ─────────────────────────────────────────────
        /** @var MadComponent $component */
        $component = new $class();
        $component->boot();          // toda requisição AJAX
        $component->_setState($state);
        $component->hydrate();       // após restaurar o estado
        if ($id) {
            $component->_setId($id);
        }

        // Restaura forward params persistidos no state do componente
        $fwd = $component->_getForwardParams();
        if (!empty($fwd)) {
            MadAction::setForwardParams($fwd);
        }

        // ── 4. Aplicar valores mad:model (dispara updating/updated hooks) ──────
        $modelValues = [];
        if (!empty($data['mad_model']) && is_array($data['mad_model'])) {
            $modelValues = $data['mad_model'];
        }
        if ($modelValues) {
            $component->_applyModelValues($modelValues);
        }

        // ── 4b. Aplicar dados de field-lists (rows Alpine) ─────────────────────
        if (!empty($data['mad_field_lists'])) {
            $flData = json_decode($data['mad_field_lists'], true);
            if (is_array($flData)) {
                $component->_setFieldListData($flData);
            }
        }

        // ── 4c. Aplicar dados de detail-forms (rows Alpine) ──────────────────
        if (!empty($data['mad_detail_forms'])) {
            $dfData = json_decode($data['mad_detail_forms'], true);
            if (is_array($dfData)) {
                $component->_setFieldListData($dfData);
            }
        }

        // ── 5. Chamar action se fornecida ──────────────────────────────────────
        $result     = null;
        $action     = $data['mad_action'] ?? '';
        $stateAntes = $component->_getState(); // snapshot antes da action

        // APM (MadTrace) — route real da acao reativa: Classe::acao
        if (class_exists('MadTrace')) {
            \MadTrace::setTransactionName($action ? "{$class}::{$action}" : $class);
            \MadTrace::setTransactionType('web');
        }

        if ($action) {
            // ── Handler interno: auto-load dependent options (Camada 3) ──────
            if ($action === '_madFlDependentOptions') {
                $params   = json_decode($data['mad_params'] ?? '{}', true);
                $response = FieldListColumn::_autoLoadDependentOptions($params ?: []);
                return [
                    'partial'   => true,
                    'id'        => $component->_getId(),
                    'mad_state' => $component->_encryptState(),
                    'ops'       => $response->getOps(),
                ];
            }

            // ── Handler interno: resolve colunas de apresentação do detail ───
            // A linha criada no navegador ("Adicionar") não tem a coluna de
            // caminho de relacionamento — o cliente não percorre relação. Aqui
            // o servidor resolve e devolve um patch por `__id`.
            if ($action === '_madDfResolveDisplay') {
                $decoded = json_decode($data['mad_params'] ?? '{}', true);
                $params  = is_array($decoded) ? $decoded : [];
                // MadWire.call manda os params numa lista; aceita os dois formatos.
                if (isset($params[0]) && is_array($params[0])) {
                    $params = $params[0];
                }

                $detail = $params['detail'] ?? '';
                $rowId  = $params['row_id'] ?? '';
                $row    = $params['row']    ?? [];
                if (!is_string($detail) || !is_string($rowId) || !is_array($row)) {
                    return ['error' => 'Parâmetros inválidos em _madDfResolveDisplay'];
                }

                // O registry do form NÃO sobrevive entre requests: quem o carrega
                // é o token `__mad_form` (MadWire.call já o posta junto). Sem isto
                // `$detailForms` está vazio no AJAX e a resolução devolve [] —
                // célula continua vazia, sem erro nenhum.
                MadFormRegistry::fromRequest();

                $values   = MadFormRegistry::resolveDetailDisplay($detail, $row);
                $response = (new MadResponse())->dfDisplay($detail, $rowId, $values);

                return [
                    'partial'   => true,
                    'id'        => $component->_getId(),
                    'mad_state' => $component->_encryptState(),
                    'ops'       => $response->getOps(),
                ];
            }

            if (!self::isInvokableAction($class, $action)) {
                return ['error' => "Método não permitido: {$action}"];
            }

            // ── Permissão POR AÇÃO (fase contextual) ─────────────────────────
            // Só aqui, com o componente já hidratado, dá para saber se um
            // "Salvar" é inclusão ou edição — e é por isso que esta checagem
            // não cabe no gate de entrada (que só vê a classe e o nome do
            // método). Vale também para o <mad-grid> declarativo, que roda numa
            // classe do framework e informa a tela dona por _permOwner().
            // A recusa diz O QUE foi recusado ("Sem permissão para excluir"),
            // não "você não tem acesso a esta tela" — a tela ABRIU, contradizer
            // isso parece defeito do sistema em vez de regra do perfil.
            $negada = PermissionGate::deniedActionKey($component, $action);
            if ($negada !== null) {
                return MadForbidden::wirePayload($negada) + ['status' => 403];
            }

            $params = [];
            if (!empty($data['mad_params'])) {
                $decoded_params = json_decode($data['mad_params'], true);
                if (is_array($decoded_params)) {
                    $params = $decoded_params;
                }
            }

            // ── 5a. File change callback: staging do arquivo no scratch ──────
            // Detecta quando o primeiro param é o nome de um campo em $_FILES
            // (enviado pelo _fireMadChange do JS: MadWire.call(wrapper, action, [fieldName])).
            // O arquivo vai pro disco de scratch (MadScratchStorage, chave
            // "mad_uploads/<nome>") e a AÇÃO recebe a CHAVE — quem consome lê
            // via Storage (ex.: DataImportForm::onUpload).
            $triggerField = $params[0] ?? '';
            if ($triggerField && !empty($_FILES) && isset($_FILES[$triggerField])) {
                $fileData = $_FILES[$triggerField];
                $scratch  = \Mad\Service\MadScratchStorage::disk();
                // Limpeza probabilística: remove staged > 1h (1 em 50 requests)
                if (rand(1, 50) === 1) {
                    foreach ($scratch->files('mad_uploads') as $_old) {
                        if ($scratch->lastModified($_old) < time() - 3600) {
                            $scratch->delete($_old);
                        }
                    }
                }
                if (is_array($fileData['tmp_name'])) {
                    $paths = [];
                    foreach ($fileData['tmp_name'] as $i => $tmpName) {
                        if (empty($tmpName) || $fileData['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $safeName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileData['name'][$i]);
                        $key = "mad_uploads/{$safeName}";
                        if (\Mad\Service\MadScratchStorage::putUploaded($tmpName, $key)) {
                            $paths[] = $key;
                        }
                    }
                    if ($paths) $params[0] = $paths;
                } else {
                    if (!empty($fileData['tmp_name']) && $fileData['error'] === UPLOAD_ERR_OK) {
                        $safeName = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileData['name']);
                        $key = "mad_uploads/{$safeName}";
                        if (\Mad\Service\MadScratchStorage::putUploaded($fileData['tmp_name'], $key)) {
                            $params[0] = $key;
                        }
                    }
                }
            }

            // ── 5b. Escopo do editor de detail-form (mad:change no sub-form) ──
            // Os campos do sub-form não viajam em mad_model (clobber de homônimo
            // do master) — vêm no bucket `mad_df_edit` e ficam visíveis em
            // $this->form SÓ durante a action. Ver MadForm::beginDetailScope().
            $dfForms = self::_openDetailScope($component, $data);

            try {
                $result = $component->_resolveAndCall($action, $params);
            } catch (\Throwable $e) {
                // Dá ao componente a chance de tratar o erro via exception()
                if ($component->_callExceptionHook($e)) {
                    // Tratado: re-renderiza o componente com o estado atual (ex: $this->erro)
                    $result = null;
                } elseif ($e instanceof MadValidationException) {
                    // Validação que escapou da ação vira erro NO CAMPO, como o
                    // `catch … return $e->asInline()` dos formulários. Ação
                    // `void` não tem como devolver MadResponse — é o caso do
                    // onShow() do bloco de filtros com campo obrigatório vazio
                    // (fórum #77); antes caía no modal de erro 500.
                    $result = $e->detailFormName() !== '' ? $e->asDetailForm() : $e->asInline();
                } else {
                    foreach ($dfForms as $_df) { $_df->endDetailScope(); }
                    throw $e; // não tratado → propaga para handle() → JSON de erro
                }
            }

            // Fecha ANTES do snapshot de estado/render: o diff do auto-bind tem
            // que ver os campos do master, não os do editor do detail.
            foreach ($dfForms as $_df) {
                $_df->endDetailScope();
            }
        }

        // ── 6a/6b. Gerar ops (MadResponse explícito + auto-bind) ─────────────
        $explicitOps = ($result instanceof MadResponse) ? $result->getOps() : [];

        // Render antecipado para popular o VarRegistry
        // (templates registram vars de autocomplete e inputs durante o render)
        // IMPORTANTE: render() chama rendering() que pode popular props (ex: threads,
        // contadores). O snapshot do state deve ser DEPOIS do render para capturar
        // essas mudanças no diff do auto-bind.
        MadVarRegistry::reset();
        $html = $component->render();

        $stateDepois = $component->_getState();

        $bindOps       = [];
        $precisaRender = $component->_needsFullRender();

        foreach ($stateDepois as $prop => $valor) {
            if ($prop === '_forwardParams' || $prop === '_rowAttach' || $prop === '_comboOrigin') continue; // interno, não é prop renderizável
            if ($valor === ($stateAntes[$prop] ?? null)) {
                continue; // não mudou
            }

            // Objeto → full re-render
            if (is_object($valor)) {
                $precisaRender = true;
                break;
            }

            // MadForm serializado → diff dos fields e gerar val ops
            if (is_array($valor) && isset($valor['__mad_form_name'])) {
                // 1) Diff dos VALORES dos campos → val ops (inputs, selects, etc)
                $antes  = ($stateAntes[$prop] ?? [])['fields'] ?? [];
                $depois = $valor['fields'] ?? [];
                foreach ($depois as $field => $fieldVal) {
                    if (($antes[$field] ?? null) === $fieldVal) continue;
                    if (is_scalar($fieldVal) || $fieldVal === null) {
                        $bindOps[] = [
                            'op'      => 'val',
                            'target'  => "[name=\"{$field}\"]",
                            'content' => (string) $fieldVal,
                        ];
                    }
                }

                // 1b) Diff do bucket HIDDEN → mad_hide / mad_show ops
                $antesHidden  = ($stateAntes[$prop] ?? [])['hidden'] ?? [];
                $depoisHidden = $valor['hidden'] ?? [];
                foreach ($depoisHidden as $hName => $hScope) {
                    if (($antesHidden[$hName] ?? null) === $hScope) continue;
                    $bindOps[] = ['op' => 'mad_hide', 'scope' => $hScope, 'name' => $hName];
                }
                foreach ($antesHidden as $hName => $hScope) {
                    if (!array_key_exists($hName, $depoisHidden)) {
                        $bindOps[] = ['op' => 'mad_show', 'scope' => $hScope, 'name' => $hName];
                    }
                }

                // 1c) Diff do bucket READONLY → mad_readonly ops
                $antesRo  = ($stateAntes[$prop] ?? [])['readonly'] ?? [];
                $depoisRo = $valor['readonly'] ?? [];
                foreach ($depoisRo as $roName => $_) {
                    if (!empty($antesRo[$roName])) continue;
                    $bindOps[] = ['op' => 'mad_readonly', 'name' => $roName, 'readonly' => true];
                }
                foreach ($antesRo as $roName => $_) {
                    if (empty($depoisRo[$roName])) {
                        $bindOps[] = ['op' => 'mad_readonly', 'name' => $roName, 'readonly' => false];
                    }
                }

                // 1d) Diff do bucket DISABLED (botões) → mad_disabled ops
                $antesDis  = ($stateAntes[$prop] ?? [])['disabled'] ?? [];
                $depoisDis = $valor['disabled'] ?? [];
                foreach ($depoisDis as $dName => $_) {
                    if (!empty($antesDis[$dName])) continue;
                    $bindOps[] = ['op' => 'mad_disabled', 'name' => $dName, 'disabled' => true];
                }
                foreach ($antesDis as $dName => $_) {
                    if (empty($depoisDis[$dName])) {
                        $bindOps[] = ['op' => 'mad_disabled', 'name' => $dName, 'disabled' => false];
                    }
                }

                // 2) Diff do bucket ITEMS → reload_* ops (combos, radios, grupos, etc)
                $antesItems  = ($stateAntes[$prop] ?? [])['items'] ?? [];
                $depoisItems = $valor['items'] ?? [];
                $placeholders = $valor['placeholders'] ?? [];
                $schema       = \Mad\Form\MadFormRegistry::getFields();

                foreach ($depoisItems as $field => $fieldItems) {
                    if (($antesItems[$field] ?? null) === $fieldItems) continue;

                    $type = $schema[$field]['type'] ?? '';
                    $selected = $depois[$field] ?? null;

                    // setItems() com lista de objetos [['value' => …, 'label' => …]]
                    // vira mapa antes (\Mad\Support\MadItems) — senão cada item saía
                    // como "Array" na op. Checklist fica de fora: lá o item É registro.
                    $mapItems = $type === 'checklist'
                        ? (array) $fieldItems
                        : \Mad\Support\MadItems::normalize((array) $fieldItems);

                    $normalized = [];
                    foreach ($mapItems as $k => $lbl) {
                        $normalized[] = ['value' => (string) $k, 'label' => (string) $lbl];
                    }

                    switch ($type) {
                        case 'select':
                            $bindOps[] = [
                                'op'          => 'reload_combo',
                                'name'        => $field,
                                'items'       => $normalized,
                                'selected'    => is_scalar($selected) ? (string) $selected : null,
                                'placeholder' => $placeholders[$field] ?? null,
                            ];
                            break;

                        case 'radio':
                            $bindOps[] = [
                                'op'       => 'reload_radio',
                                'name'     => $field,
                                'items'    => $normalized,
                                'selected' => is_scalar($selected) ? (string) $selected : null,
                            ];
                            break;

                        case 'checkbox-group':
                        case 'db-checkbox-group':
                            $sel = is_array($selected) ? array_map('strval', $selected)
                                 : (is_string($selected) && $selected !== '' ? array_map('strval', explode(',', $selected)) : []);
                            $bindOps[] = [
                                'op'       => 'reload_checkbox_group',
                                'name'     => $field,
                                'items'    => $normalized,
                                'selected' => $sel,
                            ];
                            break;

                        case 'multi-entry':
                            $sel = is_array($selected) ? array_map('strval', $selected)
                                 : (is_string($selected) && $selected !== '' ? array_map('strval', explode(',', $selected)) : []);
                            $bindOps[] = [
                                'op'       => 'reload_multi_entry',
                                'name'     => $field,
                                'items'    => $normalized,
                                'selected' => $sel,
                            ];
                            break;

                        case 'sort-list':
                            $sel = is_array($selected) ? array_map('strval', $selected)
                                 : (is_string($selected) && $selected !== '' ? array_map('strval', explode(',', $selected)) : []);
                            $bindOps[] = [
                                'op'       => 'reload_sort_list',
                                'name'     => $field,
                                'items'    => $normalized,
                                'selected' => $sel,
                            ];
                            break;

                        case 'checklist':
                            $sel = is_array($selected) ? array_map('strval', $selected)
                                 : (is_string($selected) && $selected !== '' ? array_map('strval', explode(',', $selected)) : []);
                            $bindOps[] = [
                                'op'       => 'reload_checklist',
                                'name'     => $field,
                                'items'    => array_values((array) $fieldItems),
                                'selected' => $sel,
                            ];
                            break;

                        default:
                            // Tipo desconhecido/não-registrado → full re-render
                            $precisaRender = true;
                            break 2;
                    }
                }

                continue;
            }

            // Consulta o registry para saber o tipo da variável
            $varType = MadVarRegistry::getType($prop);

            if (is_array($valor)) {
                if ($varType === 'completion') {
                    // Array é source de autocomplete → op granular
                    $bindOps[] = [
                        'op'    => 'reload_completion',
                        'var'   => $prop,
                        'items' => array_values($valor),
                    ];
                } else {
                    // Array sem registro → full re-render
                    $precisaRender = true;
                    break;
                }
                continue;
            }

            // Escalar → bind op (para spans @madBind)
            $bindOps[] = [
                'op'      => 'bind',
                'prop'    => $prop,
                'content' => htmlspecialchars((string) $valor, ENT_QUOTES),
            ];

            // Se tem input registrado com esse name → val op também
            if ($varType === 'val') {
                $bindOps[] = [
                    'op'      => 'val',
                    'target'  => "[name=\"{$prop}\"]",
                    'content' => (string) $valor,
                ];
            }
        }

        // Mescla ops explícitos (MadResponse) + auto-bind + dump_modal pendente
        $dumpOps = [];
        if (\Mad\Util\MadDumpModal::hasPending()) {
            $dumps = \Mad\Util\MadDumpModal::flush();
            $dumpOps[] = [
                'op'    => 'dump_modal',
                'dumps' => $dumps,
                'meta'  => \Mad\Util\MadDumpModal::collectRequestMeta(),
            ];
        }
        // Pending fl_combo ops geradas por setItems('campo[]')
        $pendingFlOps = [];
        foreach (get_object_vars($component) as $_propVal) {
            if ($_propVal instanceof \Mad\Form\MadForm) {
                $pendingFlOps = array_merge($pendingFlOps, $_propVal->getPendingFlOps());
            }
        }

        $allOps = array_merge($explicitOps, $bindOps, $dumpOps, $pendingFlOps);

        if (!$precisaRender && !empty($allOps)) {
            return [
                'partial'   => true,
                'id'        => $component->_getId(),
                'mad_state' => $component->_encryptState(),
                'ops'       => $allOps,
            ];
        }

        // ── 6c. Re-render completo — reusa o HTML já renderizado acima ────────
        // Inclui ops (toast, openModal, etc) junto com o HTML: o client aplica
        // ambos em sequência (morph + Mad.applyOps) após full render.
        $wrappedHtml = $component->_wrapRenderedHtml($html);

        $fullResponse = [
            'id'   => $component->_getId(),
            'html' => $wrappedHtml,
        ];

        if (!empty($allOps)) {
            $fullResponse['ops'] = $allOps;
        }

        return $fullResponse;
    }

    // ── Helpers de segurança ───────────────────────────────────────────────────

    /**
     * Verifica se a classe existe e é subclasse de MadComponent.
     */
    private static function _isValidComponent(string $class): bool
    {
        if (!$class || !class_exists($class)) {
            return false;
        }
        return is_subclass_of($class, MadComponent::class);
    }

    /**
     * Verifica se o método pode ser chamado remotamente:
     *   - deve existir na classe
     *   - deve ser público (nao estatico, nao abstrato)
     *   - não pode começar com underscore (métodos internos)
     *   - não pode ser um lifecycle/render method da base MadComponent
     *
     * Convencao do projeto: actions reativas seguem o prefixo "on*", mas existem
     * excecoes legitimas (ex: blockAdd/blockRemove/blockUpdate do MadDbBlocksTrait,
     * verbos em portugues em controllers legados como "gerarParcelas"). Por isso
     * usamos blacklist em vez de whitelist por prefixo.
     */
    /**
     * Abre o escopo do editor de detail-form em todos os MadForm do componente
     * quando o POST traz `mad_df_scope` (mad:change disparado dentro de
     * `<mad-detail-fields>`). Devolve os forms abertos, pra fechar depois.
     *
     * @return array<int,MadForm>
     */
    private static function _openDetailScope(MadComponent $component, array $data): array
    {
        $detail = trim((string) ($data['mad_df_scope'] ?? ''));
        if ($detail === '') {
            return [];
        }

        $decoded = json_decode((string) ($data['mad_df_edit'] ?? '{}'), true);
        $values  = is_array($decoded) ? $decoded : [];

        // Só escalares: o editor posta inputs, e array aqui seria payload forjado
        // caindo em fields (de onde sai getData()/fillRecord()).
        $values = array_filter($values, fn ($v) => is_scalar($v) || $v === null);

        $forms = [];
        foreach (get_object_vars($component) as $prop) {
            if ($prop instanceof MadForm) {
                $prop->beginDetailScope($detail, $values);
                $forms[] = $prop;
            }
        }

        return $forms;
    }

    /**
     * O método pode ser invocado por HTTP nesta classe?
     *
     * É o portão de EXPOSIÇÃO (o que existe como ação), não o de permissão (o
     * que este usuário pode fazer) — esse é o {@see PermissionGate}. Público
     * porque a descoberta automática das ações de um programa pergunta
     * exatamente isto para montar a lista de caixinhas da tela de Perfis: o que
     * o canal reativo aceita chamar é o que faz sentido oferecer ali.
     */
    public static function isInvokableAction(string $class, string $method): bool
    {
        return self::_isAllowedMethod($class, $method);
    }

    private static function _isAllowedMethod(string $class, string $method): bool
    {
        // Bloqueia tudo que comeca com underscore (metodos internos + magic methods)
        if (str_starts_with($method, '_')) {
            return false;
        }

        // Lifecycle e render de MadComponent: nunca devem ser invocados por HTTP.
        // Inclui metodos que aceitam arrays/state arbitrarios (fill, show)
        // ou que mudam o pipeline de render (forceFullRender).
        $blocked = [
            // render pipeline
            'render', 'rendering', 'rendered', 'forceFullRender',
            // lifecycle hooks
            'boot', 'mount', 'hydrate', 'dehydrate',
            'updating', 'updated', 'exception',
            // entry-point/state
            'show', 'fill',
        ];
        if (in_array($method, $blocked, true)) {
            return false;
        }

        if (!method_exists($class, $method)) {
            return false;
        }

        $ref = new \ReflectionMethod($class, $method);
        if (!$ref->isPublic() || $ref->isStatic() || $ref->isAbstract()) {
            return false;
        }

        // Bloqueia metodos declarados diretamente na base \Mad\Component\MadComponent.
        // Isso protege contra novas exposicoes acidentais quando a base ganhar
        // novos metodos publicos no futuro — exige que o dev sobrescreva
        // explicitamente na subclasse para tornar invocavel via HTTP.
        $declaring = $ref->getDeclaringClass()->getName();
        if ($declaring === \Mad\Component\MadComponent::class) {
            // ⚠️ EXCEÇÃO: as ações do MadDbBlocksTrait.
            //
            // O trait foi içado para a BASE justamente para o dev não precisar
            // lembrar do `use MadDbBlocksTrait;` no controller — mas método de
            // trait tem `getDeclaringClass()` = a classe que USA o trait, ou
            // seja MadComponent. O guard acima passou a barrar exatamente o
            // caso que o içamento veio resolver: `<mad-comments>` e
            // `<mad-attachments>` numa tela sem o `use` explícito devolviam
            // "Método não permitido: blockAdd" ao adicionar um item.
            //
            // Não afrouxa a segurança: as 4 ações exigem o state CRIPTOGRAFADO
            // que o componente emitiu (model/FK/colunas viajam assinados) e
            // passam pelo PermissionGate como qualquer outra action.
            return self::_isDbBlocksAction($method);
        }

        return true;
    }

    /**
     * O método é uma das ações públicas do {@see \Mad\Form\MadDbBlocksTrait}
     * (blockAdd/blockEdit/blockRemove/blockUpdate)?
     *
     * Lido do próprio trait por reflection em vez de lista fixa: ação nova no
     * trait passa a valer sozinha, e ação removida deixa de valer — sem
     * allowlist paralela pra esquecer de atualizar.
     */
    private static function _isDbBlocksAction(string $method): bool
    {
        static $acoes = null;

        if ($acoes === null) {
            $acoes = [];
            $ref = new \ReflectionClass(\Mad\Form\MadDbBlocksTrait::class);
            foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
                if (! str_starts_with($m->getName(), '_')) {
                    $acoes[$m->getName()] = true;
                }
            }
        }

        return isset($acoes[$method]);
    }
}