<?php
namespace Mad\Form;
use Mad\Http\MadResponse;
use Mad\Ui\MadToast;


/**
 * MadValidationException — exceção lançada por MadForm::validate().
 *
 * Carrega os erros de validação e provê métodos de exibição para uso no catch:
 *
 *   public function salvar(): MadResponse
 *   {
 *       try {
 *           $this->form->validate($this->rules());
 *       } catch (MadValidationException $e) {
 *           return $e->asInline();                    // erros inline nos campos
 *           return $e->asModal('Dados inválidos');       // dialog com lista de erros
 *           return $e->asToast();                        // só o primeiro erro como toast
 *       }
 *
 *       $data = $this->form->getData();
 *       // ...
 *       return MadToast::success('Salvo!');
 *   }
 */
class MadValidationException extends \RuntimeException
{
    /**
     * @param array  $errors          ['campo' => 'mensagem'] — campos que falharam
     * @param array  $validatedFields Todos os campos que foram validados (para limpar erros anteriores)
     * @param string $dfName          Nome do detail-form (se veio de um detail-form, para scoping)
     * @param array  $formFields      Campos que a TELA tem (schema ∪ POST). Vazio = detecção de órfão desligada.
     */
    public function __construct(
        private readonly array  $errors,
        private readonly array  $validatedFields = [],
        private readonly string $dfName = '',
        private readonly array  $formFields = [],
    ) {
        parent::__construct('Validation failed: ' . implode('; ', $errors));
    }

    /** Retorna o array de erros ['campo' => 'mensagem']. */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Erros ÓRFÃOS: campo com erro que a tela não tem.
     *
     * `fieldError()` mira `[data-field-error="campo"]`; se o campo não está no
     * formulário o seletor não casa em elemento nenhum e a mensagem some — o
     * usuário via só "Corrija os erros antes de continuar.", sem NENHUM campo
     * marcado em vermelho, e não tinha como descobrir o motivo.
     *
     * Caso clássico: `Model::rules()` exige coluna NOT NULL que o form não
     * expõe (`unit_id`/`tenant_id` — a plataforma preenche DEPOIS do
     * `validate()`; ou coluna criada depois do CRUD gerado). O form fica
     * impossível de salvar em silêncio.
     *
     * @return array<string,string> ['campo' => 'mensagem']
     */
    public function orphanErrors(): array
    {
        if ($this->formFields === []) {
            return []; // caller antigo não informou o schema — sem detecção
        }

        return array_filter(
            $this->errors,
            fn (string $field) => ! in_array($field, $this->formFields, true),
            ARRAY_FILTER_USE_KEY,
        );
    }

    // ── Métodos de exibição ───────────────────────────────────────────────────

    /**
     * Exibe os erros inline nos campos do formulário.
     *
     * - Limpa os erros anteriores de todos os campos validados
     * - Marca cada campo com erro via data-field-error
     * - Exibe toast de aviso (passável como "" para omitir)
     *
     * @param string $toast Mensagem do toast de aviso ('' para omitir)
     */
    public function asInline(string $toast = 'Corrija os erros antes de continuar.'): MadResponse
    {
        $response = new MadResponse();

        // Limpa erros anteriores de todos os campos que passaram pela validação
        foreach ($this->validatedFields as $field) {
            $response->clearFieldError($field);
        }

        foreach ($this->errors as $field => $msg) {
            $response->fieldError($field, $msg);
        }

        // Erro em campo que a tela não tem não pinta nada: a mensagem vai pro
        // toast, senão o form fica "não salva e não diz por quê".
        $orphans = $this->orphanErrors();
        if ($orphans !== []) {
            $this->logOrphans($orphans);
            $toast = trim($toast . ' ' . self::summarize($orphans));
        }

        if ($toast !== '') {
            $response->merge(MadToast::warning($toast));
        }

        return $response;
    }

    /**
     * Junta as mensagens órfãs numa linha só (3 primeiras + "(+N)") — toast é
     * uma linha, não uma lista.
     *
     * @param array<string,string> $orphans
     */
    private static function summarize(array $orphans): string
    {
        $msgs = array_values($orphans);
        $head = array_slice($msgs, 0, 3);
        $rest = count($msgs) - count($head);

        return implode(' ', $head) . ($rest > 0 ? " (+{$rest})" : '');
    }

    /**
     * Log pro dev: o usuário vê a mensagem, mas quem conserta precisa do NOME
     * do campo e do porquê (a tela não tem esse campo).
     *
     * @param array<string,string> $orphans
     */
    private function logOrphans(array $orphans): void
    {
        if (! class_exists(\Illuminate\Support\Facades\Log::class)) {
            return;
        }

        try {
            \Illuminate\Support\Facades\Log::warning(
                '[MadForm] Validação reprovou campo que o formulário não tem: '
                . implode(', ', array_keys($orphans))
                . '. A regra existe (Model::rules()?) mas a tela não renderiza o campo — '
                . 'o erro não tinha onde aparecer. Inclua o campo no form, ou tire a regra.',
                ['fields' => array_keys($orphans), 'errors' => $orphans],
            );
        } catch (\Throwable) {
            // logger indisponível (uso fora do Laravel) — não é motivo pra derrubar o save
        }
    }

    /**
     * Exibe os erros em um dialog estilizado (mad-dialog).
     *
     * Ideal para erros que não correspondem a um campo específico
     * ou quando o contexto do formulário não está visível.
     *
     * @param string $title Título do dialog
     */
    public function asModal(?string $title = null): MadResponse
    {
        // O default era a STRING 'Erros encontrados' na assinatura — título em
        // português cravado no pacote, que aparecia assim num app em `en` ou
        // `es`. Resolver em runtime mantém a chamada sem argumento funcionando
        // (é o caso de todos os controls) e passa a respeitar o idioma; quem
        // passa título explícito continua mandando.
        $title = $title ?? self::defaultModalTitle();

        $items = implode('', array_map(
            fn(string $msg) => '<li style="margin-bottom:.35rem">' . htmlspecialchars($msg, ENT_QUOTES) . '</li>',
            array_values($this->errors)
        ));

        $html = '<ul style="margin:.25rem 0 0;padding-left:1.3rem;line-height:1.6">'
              . $items
              . '</ul>';

        return (new MadResponse())->alert($html, 'error', $title);
    }

    /**
     * Título do modal de erros no idioma do usuário.
     *
     * Reserva embutida: app publicado antes desta versão tem um `mad.php` de
     * `lang` sem a chave, e aí o `__()` devolveria "mad.errors_found" — chave
     * crua na tela é pior do que português fixo.
     */
    private static function defaultModalTitle(): string
    {
        try {
            if (function_exists('__')) {
                $value = __('mad.errors_found');
                if (is_string($value) && $value !== 'mad.errors_found' && $value !== '') {
                    return $value;
                }
            }
        } catch (\Throwable $e) {
            // cai na reserva
        }

        return 'Erros encontrados';
    }

    /**
     * Exibe apenas o primeiro erro como toast de aviso.
     * Útil para validações simples ou fora do contexto de formulário.
     *
     * @param string $prefix Prefixo opcional antes da mensagem (ex: 'Atenção: ')
     */
    public function asToast(string $prefix = ''): MadResponse
    {
        $first = array_values($this->errors)[0] ?? '';
        return MadToast::warning($prefix . $first);
    }

    /**
     * Exibe os erros scoped a um detail-form (não afeta campos master com mesmo nome).
     *
     * @param string $dfName Nome do detail-form (se não informado, usa o do construtor)
     */
    public function asDetailForm(string $dfName = ''): MadResponse
    {
        $name = $dfName ?: $this->dfName;
        $response = new MadResponse();

        foreach ($this->errors as $field => $msg) {
            $response->dfFieldError($name, $field, $msg);
        }

        return $response;
    }
}