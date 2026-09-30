<?php

namespace Mad\Web;

use Mad\Rest\ResponseInterface;

/**
 * Mad\Web\RedirectResponse
 *
 * Response de redirect (302 por padrao) que grava flash messages,
 * old input e errors na sessao ANTES de emitir o Location header.
 *
 * API similar ao Laravel:
 *   return redirect('/login')
 *          ->with('error', 'Algo deu errado')
 *          ->withInput()
 *          ->withErrors($errors);
 */
class RedirectResponse implements ResponseInterface
{
    private string $url;
    private int $code;
    private array $withFlash = [];
    private ?array $oldInput = null;
    private ?ErrorBag $errors = null;

    /**
     * Construtor compat com ResponseInterface.
     *
     * @param string $result URL destino
     * @param int $code Status HTTP (302 por padrao)
     */
    public function __construct($result, $code = 302)
    {
        $this->url  = (string) $result;
        $this->code = (int) $code;
    }

    /**
     * Grava um flash message na sessao.
     */
    public function with(string $key, $value): self
    {
        $this->withFlash[$key] = $value;
        return $this;
    }

    /**
     * Grava varios flash messages de uma vez.
     * @param array<string, mixed> $values
     */
    public function withMany(array $values): self
    {
        $this->withFlash = array_merge($this->withFlash, $values);
        return $this;
    }

    /**
     * Preserva o input da request atual pra ser usado via old() na proxima.
     * Se null, usa $_POST + $_GET atuais.
     */
    public function withInput(?array $input = null): self
    {
        if ($input === null) {
            $input = array_merge($_GET ?? [], $_POST ?? []);
            // Remove campos sensiveis do old input
            unset($input['_token'], $input['_method'], $input['senha'], $input['password'], $input['password_confirmation']);
        }
        $this->oldInput = $input;
        return $this;
    }

    /**
     * Anexa erros ao redirect. Aceita ErrorBag, array ou string.
     */
    public function withErrors($errors): self
    {
        if ($errors instanceof ErrorBag) {
            $this->errors = $errors;
        } elseif (is_array($errors)) {
            $this->errors = new ErrorBag($errors);
        } else {
            $this->errors = new ErrorBag(['_general' => (string) $errors]);
        }
        return $this;
    }

    public function getStatusCode(): int
    {
        return $this->code;
    }

    public function getContentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    /**
     * Persiste flash/old/errors na sessao e retorna string vazia.
     * Usado como fallback quando alguem chama ->parse() diretamente.
     *
     * Em producao via Router, o caminho normal e via toIlluminateResponse()
     * abaixo, que produz um Illuminate\Http\Response com o Location header
     * correto — evitando dependencia de header() global.
     */
    public function parse()
    {
        $this->persistSessionData();

        if (!headers_sent()) {
            http_response_code($this->code);
            header('Location: ' . $this->url);
        }

        return '';
    }

    /**
     * Converte para um Illuminate\Http\Response com status 302 + Location header.
     * Usado pelo Mad\Rest\Router::normalizeResponse() via detection de metodo.
     * Persiste flash/old/errors na session ANTES de retornar.
     */
    public function toIlluminateResponse(): \Illuminate\Http\Response
    {
        $this->persistSessionData();

        return new \Illuminate\Http\Response('', $this->code, [
            'Location'     => $this->url,
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    /**
     * Persiste flash messages, old input e errors na sessao.
     * Chamado tanto por parse() quanto por toIlluminateResponse().
     */
    private function persistSessionData(): void
    {
        foreach ($this->withFlash as $k => $v) {
            Session::flash($k, $v);
        }

        if ($this->oldInput !== null) {
            Session::putOldInput($this->oldInput);
        }

        if ($this->errors !== null) {
            Session::putErrors($this->errors->all());
        }
    }

    public function getTargetUrl(): string
    {
        return $this->url;
    }
}
