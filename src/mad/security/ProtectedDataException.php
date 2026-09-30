<?php

namespace Mad\Security;

/**
 * Recusa de acesso a dado protegido (credencial, token, catálogo do banco) por
 * uma tool da IA — a mensagem é a que o modelo recebe. Ver {@see ProtectedData}.
 */
final class ProtectedDataException extends \RuntimeException
{
}
