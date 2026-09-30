<?php
namespace Mad\Form;

/**
 * MadComboOrigin — quem abriu esta tela.
 *
 * Quando um combo (`<mad-dbcombo-field>` e irmãos) não encontra resultados e o
 * usuário clica em "Cadastrar novo", o MAD Select abre o formulário alvo com um
 * token assinado (`_mad_origin`) descrevendo o combo de ORIGEM. O
 * `MadComponent` decripta esse token e expõe este value object via
 * `$this->comboOrigin()`.
 *
 * Com ele o formulário alvo sabe:
 *   - em qual `<select>` devolver a nova option (`field()` + `component()`);
 *   - o que o usuário já tinha digitado, para pré-preencher (`term()`);
 *   - como montar o rótulo da option (`display()`, `model()`, `database()`).
 *
 * Na prática o desenvolvedor raramente toca aqui: o
 * `MadComponent::returnToCombo()` faz tudo. Este objeto existe para os casos em
 * que o formulário quer decidir algo por conta própria — por exemplo travar um
 * campo quando veio de um combo específico:
 *
 *   if (($o = $this->comboOrigin()) && $o->field() === 'pais_id') { … }
 *
 * @see \Mad\Form\MadNoResultsHelper::buildPayload()  monta e assina o token
 * @see \Mad\Component\MadComponent::comboOrigin()    entrega esta instância
 */
final readonly class MadComboOrigin
{
    public function __construct(
        private string $field,
        private string $term = '',
        private string $display = '',
        private string $model = '',
        private string $database = '',
        private string $key = 'id',
        private string $component = '',
    ) {}

    /**
     * Reconstrói a partir do payload decriptado do token. Retorna null quando o
     * payload não identifica um campo — sem `field` não há para onde devolver a
     * option, então não é uma origem válida.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $field = trim((string)($payload['field_name'] ?? ''));
        if ($field === '') {
            return null;
        }

        return new self(
            field:     $field,
            term:      (string)($payload['term']      ?? ''),
            display:   (string)($payload['display']   ?? ''),
            model:     (string)($payload['model']     ?? ''),
            database:  (string)($payload['database']  ?? ''),
            key:       (string)($payload['key']       ?? 'id') ?: 'id',
            component: (string)($payload['component'] ?? ''),
        );
    }

    /** @return array<string, string> Formato aceito de volta por fromArray(). */
    public function toArray(): array
    {
        return [
            'field_name' => $this->field,
            'term'       => $this->term,
            'display'    => $this->display,
            'model'      => $this->model,
            'database'   => $this->database,
            'key'        => $this->key,
            'component'  => $this->component,
        ];
    }

    /** `name` do <select> de origem (ex.: 'pais_id'). */
    public function field(): string { return $this->field; }

    /** O que o usuário tinha digitado na busca antes de não achar nada. */
    public function term(): string { return $this->term; }

    /** `display` do combo — nome de coluna OU máscara (ver isMask()). */
    public function display(): string { return $this->display; }

    /** Model Eloquent que alimenta o combo (nome curto ou FQCN). */
    public function model(): string { return $this->model; }

    /** Conexão do model. */
    public function database(): string { return $this->database; }

    /** Coluna-chave do combo (default 'id'). */
    public function key(): string { return $this->key; }

    /** `mad-id` do componente que hospeda o combo ('' quando desconhecido). */
    public function component(): string { return $this->component; }

    /**
     * O `display` é uma MÁSCARA (`{nome} — {sigla}`), não um nome de coluna.
     * Máscara não serve para `$form->set()` nem para `$form->get()`: renderiza
     * com ModelOptionsLoader::mask() a partir do registro salvo.
     */
    public function isMask(): bool
    {
        return str_contains($this->display, '{');
    }
}
