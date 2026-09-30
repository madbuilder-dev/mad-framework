<?php

namespace Mad\Web;

use Mad\Rest\ResponseInterface;
use Mad\View\MadBlade;

/**
 * Mad\Web\ViewResponse
 *
 * Response HTML que renderiza um template Blade na hora do parse().
 * Implementa o mesmo contrato do Mad\Rest\JSONResponse/HtmlResponse,
 * entao o normalizeResponse do Router (com a migracao illuminate/routing)
 * converte automaticamente pra Illuminate\Http\Response.
 *
 * Expoe `with()` chainable pra adicionar dados extras ao template e
 * injeta automaticamente `$errors` (ErrorBag), `$flash` (array), e
 * dados da session nas variaveis disponiveis no template.
 */
class ViewResponse implements ResponseInterface
{
    private string $template;
    private array $data = [];
    private int $code;

    /**
     * Construtor compat com ResponseInterface (primeiro arg = template/resultado,
     * segundo arg = code). Dados extras sao adicionados via with().
     */
    public function __construct($result, $code = 200)
    {
        $this->template = (string) $result;
        $this->code     = (int) $code;
    }

    /**
     * Adiciona dados ao template (chainable).
     */
    public function with(array $data): self
    {
        $this->data = array_merge($this->data, $data);
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
     * Renderiza o template Blade e retorna a string HTML.
     * Injeta $errors (ErrorBag), $flash (array) e old_input nos dados.
     */
    public function parse()
    {
        $errorsArray = Session::getErrors();
        $errorsBag   = new ErrorBag($errorsArray);

        $flash = $_SESSION['_mad_web_flash_old'] ?? [];

        $extra = [
            'errors' => $errorsBag,
            'flash'  => $flash,
        ];

        if (!headers_sent()) {
            http_response_code($this->code);
            header('Content-Type: ' . $this->getContentType());
        }

        return MadBlade::render($this->template, array_merge($extra, $this->data));
    }
}
