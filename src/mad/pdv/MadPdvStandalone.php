<?php
namespace Mad\Pdv;

/**
 * MadPdvStandalone — instância temporária usada pelo MadPdvCompiler quando o
 * host da view não é um MadPdvComponent. Os callbacks/ações custom roteiam
 * pro host externo via _setExternalHost (injetado pelo compiler).
 */
class MadPdvStandalone extends MadPdvComponent
{
}
