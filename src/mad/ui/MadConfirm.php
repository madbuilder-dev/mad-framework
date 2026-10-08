<?php
namespace Mad\Ui;
use Mad\Http\MadResponse;


/**
 * MadConfirm — builder fluente para diálogos de confirmação.
 *
 * Criado via MadMessage::confirm(). Não instanciar diretamente.
 *
 * Uso básico:
 *   return MadMessage::confirm('Excluir', 'Confirmar exclusão?', $this)
 *       ->onConfirm('deletar', [42])
 *       ->onCancel()
 *       ->build();
 *
 * Múltiplos botões / outro componente:
 *   return MadMessage::confirm('Ação', 'Escolha:', $this)
 *       ->button('Aprovar',  'aprovar',  [$id], 'primary')
 *       ->button('Rejeitar', 'rejeitar', [$id], 'destructive')
 *       ->onCancel('Fechar')
 *       ->build();
 *
 * Diálogo com campos (o TInputDialog do Adianti) — o valor digitado chega ao
 * método do botão no MadForm, junto com os campos da tela:
 *   return MadMessage::prompt('Baixar em lote', 'Informe a data do pagamento.', $this)
 *       ->field('data_pagto', 'Data do pagamento', 'date', required: true)
 *       ->field('conta_id', 'Conta', 'select', Conta::pluck('nome', 'id')->all())
 *       ->onConfirm('onBaixarLote', [], 'Baixar')
 *       ->onCancel()
 *       ->build();
 *
 *   public function onBaixarLote(): MadResponse
 *   {
 *       $data = $this->form->get('data_pagto');   // '2026-09-23'
 *       ...
 */
class MadConfirm
{
    /** Tipos de campo do diálogo (field()). */
    public const FIELD_TYPES = ['text', 'number', 'date', 'datetime', 'time', 'textarea', 'select', 'password', 'hidden'];

    /** @var array{ label: string, variant: string, callback_js: string, action?: string, params?: array, mad_id?: string }[] */
    private array $buttons = [];

    /** @var list<array{name: string, label: string, type: string, options?: list<array{value: string, label: string}>, value?: string, required?: bool, required_msg?: string}> */
    private array $fields = [];

    public function __construct(
        private readonly string $title,
        private readonly string $content,
        private readonly string $defaultMadId = '',
        private readonly string $icon = 'alert-triangle',
        private readonly string $type = 'warning',
    ) {}

    // ── Campos ────────────────────────────────────────────────────────────────

    /**
     * Campo digitável no diálogo — o equivalente ao formulário do
     * TInputDialog do Adianti. Ao clicar num botão de ação, o valor de cada
     * campo vai junto na chamada (como os campos da tela, via `mad_model`): o
     * método lê com `$this->form->get('nome')` (ou `$this->nome`, quando o
     * componente tem uma prop pública com esse nome). Campo `required` vazio
     * segura o diálogo aberto com a mensagem de obrigatório.
     *
     * @param string $name     Nome do valor (identificador: letras, números e _)
     * @param string $label    Rótulo (padrão: o nome)
     * @param string $type     text | number | date | datetime | time | textarea | select | password | hidden
     * @param array  $options  select: [valor => rótulo] (ex.: Model::pluck('nome', 'id')->all())
     * @param mixed  $value    Valor inicial (date: 'Y-m-d'; datetime: 'Y-m-d H:i')
     * @param bool   $required Exige preenchimento antes de chamar a ação
     */
    public function field(
        string $name,
        string $label = '',
        string $type = 'text',
        array  $options = [],
        mixed  $value = null,
        bool   $required = false,
    ): static {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("MadConfirm::field(): nome de campo inválido '{$name}'.");
        }
        $type = in_array($type, self::FIELD_TYPES, true) ? $type : 'text';
        $label = $label !== '' ? $label : $name;
        $field = ['name' => $name, 'label' => $label, 'type' => $type];
        if ($type === 'select') {
            $field['options'] = [];
            foreach ($options as $optValue => $optLabel) {
                $field['options'][] = ['value' => (string) $optValue, 'label' => (string) $optLabel];
            }
        }
        if ($value !== null && $value !== '') {
            $field['value'] = (string) $value;
        }
        if ($required) {
            $field['required'] = true;
            $msg = '';
            try {
                $msg = function_exists('__') ? __('validation.required', ['attribute' => $label]) : '';
            } catch (\Throwable) {
                // sem tradutor (uso fora do app): cai no texto padrão
            }
            $field['required_msg'] = is_string($msg) && $msg !== '' && $msg !== 'validation.required' ? $msg : "{$label}: obrigatório.";
        }
        $this->fields[] = $field;

        // O valor volta em `mad_model`: o formulário da tela precisa saber que
        // foi o CÓDIGO que ofereceu este campo, senão o recusa como chave que
        // a tela não tem (MadForm::takesFromBrowser).
        \Mad\Form\MadFormRegistry::declareScreenField($name);

        return $this;
    }

    // ── Botões ────────────────────────────────────────────────────────────────

    /**
     * Adiciona um botão que chama um método do componente.
     *
     * @param string $label   Texto do botão
     * @param string $action  Método do componente (ex: 'deletar')
     * @param array  $params  Parâmetros para o método
     * @param string $variant Variante CSS: primary | secondary | destructive | ghost | outline
     * @param string $madId   mad-id do componente (sobrescreve o padrão definido em confirm())
     */
    public function button(
        string $label,
        string $action,
        array  $params  = [],
        string $variant = 'secondary',
        string $madId   = '',
    ): static {
        $id = $madId ?: $this->defaultMadId;

        $callbackJs = $id
            ? sprintf('()=>MadWire.call(%s,%s,%s)',
                json_encode($id),
                json_encode($action),
                json_encode($params)
              )
            : 'null';

        $this->buttons[] = [
            'label'       => $label,
            'variant'     => $variant,
            'callback_js' => $callbackJs,
            'action'      => $action,
            'params'      => $params,
            'mad_id'      => $id,
        ];

        return $this;
    }

    /**
     * Adiciona o botão de confirmação principal (lado direito, destaque).
     *
     * @param string $action  Método do componente
     * @param array  $params  Parâmetros para o método
     * @param string $label   Texto do botão (padrão: 'Confirmar')
     * @param string $variant Variante CSS (padrão: 'primary')
     * @param string $madId   mad-id (sobrescreve o padrão se necessário)
     */
    public function onConfirm(
        string $action,
        array  $params  = [],
        string $label   = 'Confirmar',
        string $variant = 'primary',
        string $madId   = '',
    ): static {
        return $this->button($label, $action, $params, $variant, $madId);
    }

    /**
     * Adiciona o botão de cancelamento (apenas fecha o dialog).
     *
     * @param string $label Texto do botão (padrão: 'Cancelar')
     */
    public function onCancel(string $label = 'Cancelar'): static
    {
        $this->buttons[] = [
            'label'       => $label,
            'variant'     => 'secondary',
            'callback_js' => 'null',
        ];

        return $this;
    }

    // ── Build ─────────────────────────────────────────────────────────────────

    /**
     * Finaliza o builder e retorna o MadResponse com o script do dialog.
     */
    public function build(): MadResponse
    {
        $btns = $this->buttons ?: [
            ['label' => 'OK', 'variant' => 'secondary', 'callback_js' => 'null'],
        ];

        $withFields = $this->fields !== [];
        $btnsJs = array_map(function (array $btn) use ($withFields): string {
            $label   = json_encode($btn['label'], JSON_UNESCAPED_UNICODE);
            $variant = json_encode($btn['variant']);
            $cb      = $btn['callback_js'];
            $submit  = '';
            // Com campos, o botão de ação leva os valores digitados (4º
            // argumento do MadWire.call = mad_model) e valida os obrigatórios.
            if ($withFields && ($btn['mad_id'] ?? '') !== '' && isset($btn['action'])) {
                $cb = sprintf('(v)=>MadWire.call(%s,%s,%s,v||{})',
                    json_encode($btn['mad_id']),
                    json_encode($btn['action']),
                    json_encode($btn['params'] ?? [])
                );
                $submit = ',submit:true';
            }
            return "{label:{$label},type:{$variant},callback:{$cb}{$submit}}";
        }, $btns);

        // json_encode para as opções escalares; injectar buttons manualmente
        // pois callbacks JS não são JSON-serializáveis. JSON_HEX_TAG só com
        // campos: rótulo/opção vindos do banco não fecham o <script> quando a
        // resposta sai por emit(). Sem campos, a saída de sempre.
        $opts = json_encode(array_filter([
            'type'    => $this->type,
            'title'   => $this->title,
            'message' => $this->content,
            'icon'    => $this->icon,
            'fields'  => $withFields ? $this->fields : null,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | ($withFields ? JSON_HEX_TAG : 0));

        $optsWithBtns = substr($opts, 0, -1) . ',"buttons":[' . implode(',', $btnsJs) . ']}';

        return (new MadResponse())->script("MadDialog.show({$optsWithBtns})");
    }
}