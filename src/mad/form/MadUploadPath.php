<?php

namespace Mad\Form;

/**
 * MadUploadPath — validação/normalização do diretório de upload declarado pelo
 * dev no atributo `folder` dos componentes de arquivo.
 *
 * Regras (um diretório "indevido" é rejeitado):
 *  - caminho absoluto (Unix `/...` ou Windows `C:\...`) — escaparia da raiz;
 *  - path traversal (`..`) — subiria de diretório;
 *  - null byte (`\0`) — truncamento de path.
 *
 * Onde é chamado:
 *  - RENDER: cada blade de componente de arquivo chama assertValidFolder() —
 *    se inválido, lança e o MadComponent mostra erro RICO em tela (o dev vê na
 *    hora, sem precisar salvar).
 *  - SAVE: MadForm::_normalizeUploadFolder() chama normalize() — mesma regra,
 *    defesa em profundidade; a exceção vira modal de erro no onSave.
 */
class MadUploadPath
{
    /**
     * Valida o folder declarado num componente (chamado no render).
     * Só valida quando storage='disk' (db/blob não usa pasta). Folder vazio é
     * permitido (cai no default 'uploads' no save).
     *
     * @throws MadUploadPathException
     */
    public static function assertValidFolder(string $folder, string $storage = 'disk', string $context = ''): void
    {
        if ($storage !== 'disk') {
            return;
        }
        $folder = trim($folder);
        if ($folder === '') {
            return;
        }
        self::assertSafe($folder, $context);
    }

    /**
     * Normaliza o folder para uso no filesystem (chamado no save).
     * Retorna o path relativo limpo; lança se inválido.
     *
     * @throws MadUploadPathException
     */
    public static function normalize(string $folder): string
    {
        $folder = trim($folder);
        if ($folder === '') {
            return 'uploads';
        }
        self::assertSafe($folder, '');
        return rtrim(ltrim($folder, './'), '/');
    }

    /** Núcleo das regras; lança MadUploadPathException com mensagem dev-friendly. */
    private static function assertSafe(string $folder, string $context): void
    {
        $ctx = $context !== '' ? " ({$context})" : '';

        if ($folder[0] === '/' || $folder[0] === '\\' || preg_match('/^[a-zA-Z]:[\/\\\\]/', $folder)) {
            throw new MadUploadPathException(
                "Diretório de upload inválido{$ctx}: \"{$folder}\" — caminho absoluto não é permitido. "
                . "Use um caminho relativo à raiz do projeto, ex.: folder=\"uploads/minha-pasta\"."
            );
        }

        if (strpos($folder, '..') !== false) {
            throw new MadUploadPathException(
                "Diretório de upload inválido{$ctx}: \"{$folder}\" — contém \"..\" (path traversal), não permitido. "
                . "Use um caminho relativo simples, ex.: folder=\"uploads/minha-pasta\"."
            );
        }

        if (strpos($folder, "\0") !== false) {
            throw new MadUploadPathException(
                "Diretório de upload inválido{$ctx}: null byte não é permitido no caminho."
            );
        }
    }
}
