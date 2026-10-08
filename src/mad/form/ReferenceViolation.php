<?php

namespace Mad\Form;

use Mad\Http\MadResponse;
use Mad\Ui\MadToast;

/**
 * ReferenceViolation — o Salvar foi recusado porque uma linha filha (Lista de
 * itens, Detail Form) ou uma ligação N:N (Checklist, seleção múltipla em outra
 * tabela) traz a chave de um cadastro que as regras de referência do Model não
 * aceitam: o registro é de outra unidade, quem salva não o enxerga, ou ele não
 * existe mais. Ver {@see ReferenceGuard}.
 *
 * É uma MadValidationException: o `catch (MadValidationException $e) { return
 * $e->asInline(); }` que todo `onSave` gerado já tem mostra a mensagem, e o
 * `DB::transaction()` em volta desfaz o que o Salvar tinha gravado antes.
 *
 * A diferença está em onde a mensagem aparece. O erro de um campo do
 * formulário (a seleção múltipla) vai no próprio campo; o de uma linha não tem
 * campo onde ser pintado — a mensagem já diz a lista, a linha e a coluna, e
 * vai no aviso.
 */
class ReferenceViolation extends MadValidationException
{
    /** @var list<string> */
    private array $inline;

    /**
     * @param array<string,string> $errors chave => mensagem pronta para o usuário
     * @param list<string>         $inline chaves de `$errors` que são campos do formulário (a mensagem vai no campo)
     */
    public function __construct(array $errors, array $inline = [])
    {
        $this->inline = array_values(array_map('strval', $inline));

        parent::__construct($errors, $this->inline);
    }

    public function asInline(string $toast = 'Corrija os erros antes de continuar.'): MadResponse
    {
        $response = new MadResponse();

        $loose = [];
        foreach ($this->getErrors() as $field => $message) {
            if (in_array((string) $field, $this->inline, true)) {
                $response->fieldError((string) $field, (string) $message);
            } else {
                $loose[] = (string) $message;
            }
        }

        // Toast é uma linha, não uma lista: as três primeiras e quantas faltam.
        if ($loose !== []) {
            $head  = array_slice($loose, 0, 3);
            $rest  = count($loose) - count($head);
            $toast = trim($toast . ' ' . implode(' ', $head) . ($rest > 0 ? " (+{$rest})" : ''));
        }
        if ($toast !== '') {
            $response->merge(MadToast::warning($toast));
        }

        return $response;
    }
}
