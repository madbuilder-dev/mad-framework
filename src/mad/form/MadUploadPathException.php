<?php

namespace Mad\Form;

/**
 * Lançada quando o `folder` declarado num componente de arquivo (file-field,
 * multi-file-field, image-field, avatar-field, signature-field) é inválido —
 * caminho absoluto, path traversal (..) ou null byte.
 *
 * No render de um MadComponent, vira erro rico em tela (Ignition/MadErrorRenderer);
 * no save (`$form->save()`), é capturada pelo onSave e vira modal de erro.
 */
class MadUploadPathException extends \RuntimeException
{
}
