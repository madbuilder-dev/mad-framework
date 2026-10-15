<?php

namespace Mad\Routine;

/**
 * Uma rotina parou de propósito: passo "parar", trava (guard) que impediu
 * refazer/desfazer, registro gerado que já está em uso, limite estourado.
 *
 * A mensagem é escrita PARA O USUÁRIO e vai inteira para a tela (diálogo de
 * erro do Salvar, aviso do botão). Tudo o que a rotina gravou é desfeito: ela
 * roda numa transação, e a exceção atravessa a transação.
 */
class RoutineStopped extends \DomainException
{
}
