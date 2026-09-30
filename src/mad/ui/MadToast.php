<?php
namespace Mad\Ui;
use Mad\Http\MadResponse;


/**
 * MadToast — Factory de operações de toast para MadResponse.
 *
 * Retorna um MadResponse com o op de toast já adicionado,
 * permitindo encadeamento de outras operações:
 *
 *   return MadToast::success('Registro salvo!');
 *
 *   return MadToast::danger('Falha ao salvar.', 'Erro', 'top-center');
 *
 *   return MadToast::warning('Corrija os erros.')
 *       ->fieldError('nome', 'Nome é obrigatório.')
 *       ->fieldError('email', 'E-mail inválido.');
 *
 * Posições: top-left | top-center | top-right (padrão)
 *           bottom-left | bottom-center | bottom-right
 */
class MadToast
{
    public static function success(string $message, string $title = '', string $position = 'top-right'): MadResponse
    {
        return (new MadResponse)->toast($message, 'success', $title, $position);
    }

    public static function danger(string $message, string $title = '', string $position = 'top-right'): MadResponse
    {
        return (new MadResponse)->toast($message, 'danger', $title, $position);
    }

    public static function warning(string $message, string $title = '', string $position = 'top-right'): MadResponse
    {
        return (new MadResponse)->toast($message, 'warning', $title, $position);
    }

    public static function info(string $message, string $title = '', string $position = 'top-right'): MadResponse
    {
        return (new MadResponse)->toast($message, 'info', $title, $position);
    }
}