<?php

use Mad\Service\MadLogService;
use Mad\Rest\Response;

/**
 * @package    util
 * @author     Matheus Agnes Dias
 * @copyright  Copyright (c) 2025-2026 Mad Solutions LTDA (https://madbuilder.dev)
 */

/**
 * mad_app_config — config da aplicacao como array. Fonte ÚNICA: config/mad.php
 * (via AppConfig::get() = config('mad'), com fallback de require fora do Laravel).
 *
 * O legado app/config/application.php e application.ini foi REMOVIDO — config/mad.php
 * é a fonte canônica (mesma API estática que todos os call sites já usam).
 * $fresh força reload (usado por quem ESCREVE a config).
 *
 * @return array<string,mixed>
 */
function mad_app_config(bool $fresh = false): array
{
    if ($fresh) {
        \Mad\Core\AppConfig::reset();
    }

    return \Mad\Core\AppConfig::get();
}

/**
 * Função mad_dump - Realiza var_dump de variáveis e retorna em formato JSON
 * para integração com o painel de debug
 * 
 * @param mixed ...$args Variáveis a serem examinadas
 * @return string JSON representando os dados do var_dump
 */
function mad_dump(...$args) {
    // Obtém informações do contexto de chamada
    $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
    $file = isset($backtrace[0]['file']) ? $backtrace[0]['file'] : 'unknown';
    $file = explode('/', $file);
    $file = end($file);
    $line = isset($backtrace[0]['line']) ? $backtrace[0]['line'] : 0;

    if($file == 'GlobalFunctions.php')
    {
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $file = isset($backtrace[1]['file']) ? $backtrace[1]['file'] : 'unknown';
        $file = explode('/', $file);
        $file = end($file);
        $line = isset($backtrace[1]['line']) ? $backtrace[1]['line'] : 0;
    }
    
    
    // Prepara o resultado
    $dumps = [];
    
    foreach ($args as $index => $arg) {
        // Captura a saída do var_dump
        
        $dump = $arg;
        
        
        $dump = print_r($arg, true);
        
        // Adiciona ao array de dumps
        MadLogService::addDebugData([
            'requestId' => REQUEST_ID,
            'name' => '',
            'type' => gettype($arg),
            'value' => base64_encode($dump),
            'file' => $file,
            'line' => $line,
            'time' => date('Y-m-d H:i:s')
        ]);
    }
}

function md(...$args)
{
    mad_dump(...$args);
}

function mdd(...$args)
{
    mad_dump(...$args);

    MadLogService::finalizeDebugLogging();
    die;
}

function response($content = null, $status = 200, array $headers = [])
{
    // Sem argumentos: comportamento legado MAD (retorna Mad\Rest\Response).
    if (func_num_args() === 0) {
        return new Response;
    }

    // Com argumentos: compativel com o helper response() do Laravel. O
    // laravel/mcp chama response($body, $status, $headers) no HttpTransport e
    // response('', 405)->header(...) no Registrar, esperando
    // Illuminate\Http\Response. (Nenhum codigo MAD passa argumentos.)
    return new \Illuminate\Http\Response($content ?? '', $status, $headers);
}

/**
 * Empurra um dump pra modal de debug client-side.
 *
 * Pode ser chamado de qualquer ponto dentro de uma action AJAX/MadComponent —
 * os dumps sao acumulados num buffer e injetados automaticamente como op
 * `dump_modal` quando MadResponse->send() ou getOps() for executado.
 *
 *   mad_dump_modal($this->form->getData(), $cliente, $_POST);
 *   mad_dump_modal('marcador', $valor1);
 *   mad_dump_modal($outro);  // pode chamar N vezes — tudo aparece no mesmo overlay
 *
 * @param mixed ...$args qualquer numero de variaveis
 */
function mad_dump_modal(...$args): void
{
    \Mad\Util\MadDumpModal::push($args);
}

/**
 * Atalho curto para mad_dump_modal().
 *
 *   mdm($var1, $var2);
 */
function mdm(...$args): void
{
    \Mad\Util\MadDumpModal::push($args);
}

// ── i18n helpers (padrão Laravel) ────────────────────────────────────────────

/**
 * Traduz a chave fornecida via MadTranslator.
 *
 * @param  string      $key     'grupo.chave' (ex: 'mad.save', 'doc.recipe')
 * @param  array       $replace Substituições (:name → valor)
 * @param  string|null $locale  Locale override (null = usa o default)
 */
function __(string $key, array $replace = [], ?string $locale = null): string
{
    return \Mad\View\MadTranslator::get($key, $replace, $locale);
}

function trans(string $key, array $replace = [], ?string $locale = null): string
{
    return __($key, $replace, $locale);
}