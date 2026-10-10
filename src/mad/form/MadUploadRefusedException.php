<?php

namespace Mad\Form;

use Mad\Http\MadResponse;

/**
 * O PHP não recebeu um arquivo que a ação pediu (`$request->file('campo')`):
 * passou do limite de envio do servidor, o envio foi interrompido, ou o
 * servidor falhou. Antes o arquivo simplesmente não aparecia e a ação seguia
 * como se o usuário não tivesse enviado nada — gravando o registro sem o
 * anexo, calada.
 *
 * É uma {@see MadValidationException}: escapando da ação, o MadComponentHandler
 * a mostra no campo (`[data-field-error="campo"]`). Como a ação lê o arquivo
 * por fora do formulário, o campo pode nem ter onde mostrar o erro — então a
 * própria mensagem vai também no aviso, e não o "Corrija os erros…" genérico.
 */
final class MadUploadRefusedException extends MadValidationException
{
    public function __construct(string $field, string $message)
    {
        parent::__construct([$field => $message], [$field]);
    }

    public function asInline(string $toast = ''): MadResponse
    {
        return parent::asInline($toast !== '' ? $toast : implode(' ', $this->getErrors()));
    }
}
