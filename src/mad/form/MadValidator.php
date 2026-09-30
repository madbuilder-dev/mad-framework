<?php
namespace Mad\Form;

use Illuminate\Validation\Factory;
use Illuminate\Translation\Translator;
use Illuminate\Translation\ArrayLoader;

/**
 * MadValidator — wrapper singleton em torno do illuminate/validation.
 *
 * Configura o Factory com mensagens em pt-BR e provê um ponto central
 * para validação em qualquer parte da aplicação MAD.
 *
 * Uso direto:
 *   $errors = MadValidator::make($data, ['nome' => 'required|string'])->errors();
 *
 * Via MadForm (uso recomendado):
 *   $errors = $this->form->validate(['nome' => 'required|string|max:255']);
 */
class MadValidator
{
    private static ?Factory $factory = null;

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function factory(): Factory
    {
        if (self::$factory === null) {
            $loader     = new ArrayLoader();
            $loader->addMessages('pt_BR', 'validation', self::ptBrMessages());
            $translator = new Translator($loader, 'pt_BR');
            self::$factory = new Factory($translator);
        }

        // Regras de banco (unique/exists) exigem o presence verifier — sem ele o
        // validator lança "Presence verifier has not been set.". É o ESCOPADO:
        // com Multi-unidade/tenant em pool ligado, unique e exists contam só as
        // linhas da unidade corrente, como a leitura do model (ver
        // ScopedPresenceVerifier). Refeito a cada chamada com o `db` do
        // container ATUAL: o Factory é estático e sobrevive ao app — o verifier
        // da 1ª chamada ficava preso a um container já descartado (Octane
        // reiniciado, suíte de testes: "Target class [config] does not exist").
        // Em uso standalone (sem container/db) segue sem: regras de DB
        // indisponíveis, como antes.
        $verifier = self::presenceVerifier();
        if ($verifier !== null) {
            self::$factory->setPresenceVerifier($verifier);
        }

        return self::$factory;
    }

    /**
     * Verificador de presença das regras `unique`/`exists` do framework — o
     * mesmo para formulários (MadForm::validate) e para a API REST
     * (ApiResourceController). Null fora de um app com banco.
     */
    public static function presenceVerifier(): ?ScopedPresenceVerifier
    {
        try {
            if (function_exists('app') && app()->bound('db')) {
                return new ScopedPresenceVerifier(app('db'));
            }
        } catch (\Throwable $e) {
            // sem container/db — validação segue só com regras não-DB
        }

        return null;
    }

    /**
     * Cria um validator illuminate com os dados e regras fornecidos.
     * Retorna array ['campo' => 'primeira mensagem de erro'] ou [] se válido.
     *
     * @param array $data     Dados a validar (ex: $form->fields)
     * @param array $rules    Regras illuminate (ex: ['nome' => 'required|string'])
     * @param array $messages Mensagens customizadas (opcional)
     * @param array $attrs    Nomes amigáveis dos atributos (opcional)
     * @return array          Erros: ['campo' => 'mensagem'] ou [] se válido
     */
    public static function validate(
        array $data,
        array $rules,
        array $messages = [],
        array $attrs = []
    ): array {
        $validator = self::factory()->make($data, $rules, $messages, $attrs);

        if ($validator->passes()) {
            return [];
        }

        // Retorna a primeira mensagem de erro por campo (compatível com fieldError())
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            $errors[$field] = $messages[0];
        }
        return $errors;
    }

    /**
     * Mensagem de uma regra simples com o rótulo do campo — a MESMA linha que o
     * validator usaria. Para erro detectado fora do validator (ex.: índice
     * único do banco em MadForm::save()) sair idêntico ao da rule.
     *
     *   MadValidator::ruleMessage('unique', 'CPF') → 'O campo CPF já está sendo utilizado.'
     */
    public static function ruleMessage(string $rule, string $attribute): string
    {
        $line = self::factory()->getTranslator()->get('validation.' . $rule);
        if (!is_string($line)) {
            $line = 'O campo :attribute é inválido.';
        }

        return str_replace(':attribute', $attribute, $line);
    }

    // ── Mensagens pt-BR ───────────────────────────────────────────────────────

    private static function ptBrMessages(): array
    {
        return [
            'accepted'             => 'O campo :attribute deve ser aceito.',
            'accepted_if'          => 'O campo :attribute deve ser aceito quando :other é :value.',
            'active_url'           => 'O campo :attribute não contém uma URL válida.',
            'after'                => 'O campo :attribute deve ser uma data posterior a :date.',
            'after_or_equal'       => 'O campo :attribute deve ser uma data posterior ou igual a :date.',
            'alpha'                => 'O campo :attribute deve conter apenas letras.',
            'alpha_dash'           => 'O campo :attribute deve conter apenas letras, números, traços e underscores.',
            'alpha_num'            => 'O campo :attribute deve conter apenas letras e números.',
            'array'                => 'O campo :attribute deve ser um array.',
            'before'               => 'O campo :attribute deve ser uma data anterior a :date.',
            'before_or_equal'      => 'O campo :attribute deve ser uma data anterior ou igual a :date.',
            'between'              => [
                'numeric' => 'O campo :attribute deve estar entre :min e :max.',
                'file'    => 'O campo :attribute deve estar entre :min e :max kilobytes.',
                'string'  => 'O campo :attribute deve ter entre :min e :max caracteres.',
                'array'   => 'O campo :attribute deve ter entre :min e :max itens.',
            ],
            'boolean'              => 'O campo :attribute deve ser verdadeiro ou falso.',
            'confirmed'            => 'A confirmação do campo :attribute não confere.',
            'date'                 => 'O campo :attribute não contém uma data válida.',
            'date_equals'          => 'O campo :attribute deve ser uma data igual a :date.',
            'date_format'          => 'O campo :attribute não corresponde ao formato :format.',
            'different'            => 'Os campos :attribute e :other devem ser diferentes.',
            'digits'               => 'O campo :attribute deve ter :digits dígitos.',
            'digits_between'       => 'O campo :attribute deve ter entre :min e :max dígitos.',
            'dimensions'           => 'O campo :attribute tem dimensões de imagem inválidas.',
            'distinct'             => 'O campo :attribute possui um valor duplicado.',
            'email'                => 'O campo :attribute deve ser um endereço de e-mail válido.',
            'ends_with'            => 'O campo :attribute deve terminar com um dos seguintes valores: :values.',
            'exists'               => 'O campo :attribute selecionado é inválido.',
            'file'                 => 'O campo :attribute deve ser um arquivo.',
            'filled'               => 'O campo :attribute deve ter um valor.',
            'gt'                   => [
                'numeric' => 'O campo :attribute deve ser maior que :value.',
                'file'    => 'O campo :attribute deve ser maior que :value kilobytes.',
                'string'  => 'O campo :attribute deve ter mais que :value caracteres.',
                'array'   => 'O campo :attribute deve ter mais que :value itens.',
            ],
            'gte'                  => [
                'numeric' => 'O campo :attribute deve ser maior ou igual a :value.',
                'file'    => 'O campo :attribute deve ser maior ou igual a :value kilobytes.',
                'string'  => 'O campo :attribute deve ter :value caracteres ou mais.',
                'array'   => 'O campo :attribute deve ter :value itens ou mais.',
            ],
            'image'                => 'O campo :attribute deve ser uma imagem.',
            'in'                   => 'O campo :attribute selecionado é inválido.',
            'in_array'             => 'O campo :attribute não existe em :other.',
            'integer'              => 'O campo :attribute deve ser um número inteiro.',
            'ip'                   => 'O campo :attribute deve ser um endereço IP válido.',
            'ipv4'                 => 'O campo :attribute deve ser um endereço IPv4 válido.',
            'ipv6'                 => 'O campo :attribute deve ser um endereço IPv6 válido.',
            'json'                 => 'O campo :attribute deve ser uma string JSON válida.',
            'lt'                   => [
                'numeric' => 'O campo :attribute deve ser menor que :value.',
                'file'    => 'O campo :attribute deve ser menor que :value kilobytes.',
                'string'  => 'O campo :attribute deve ter menos que :value caracteres.',
                'array'   => 'O campo :attribute deve ter menos que :value itens.',
            ],
            'lte'                  => [
                'numeric' => 'O campo :attribute deve ser menor ou igual a :value.',
                'file'    => 'O campo :attribute deve ser menor ou igual a :value kilobytes.',
                'string'  => 'O campo :attribute deve ter :value caracteres ou menos.',
                'array'   => 'O campo :attribute deve ter :value itens ou menos.',
            ],
            'max'                  => [
                'numeric' => 'O campo :attribute não pode ser maior que :max.',
                'file'    => 'O campo :attribute não pode ser maior que :max kilobytes.',
                'string'  => 'O campo :attribute não pode ter mais que :max caracteres.',
                'array'   => 'O campo :attribute não pode ter mais que :max itens.',
            ],
            'mimes'                => 'O campo :attribute deve ser um arquivo do tipo: :values.',
            'mimetypes'            => 'O campo :attribute deve ser um arquivo do tipo: :values.',
            'min'                  => [
                'numeric' => 'O campo :attribute deve ser no mínimo :min.',
                'file'    => 'O campo :attribute deve ter no mínimo :min kilobytes.',
                'string'  => 'O campo :attribute deve ter no mínimo :min caracteres.',
                'array'   => 'O campo :attribute deve ter no mínimo :min itens.',
            ],
            'multiple_of'          => 'O campo :attribute deve ser um múltiplo de :value.',
            'not_in'               => 'O campo :attribute selecionado é inválido.',
            'not_regex'            => 'O formato do campo :attribute é inválido.',
            'numeric'              => 'O campo :attribute deve ser um número.',
            'password'             => 'A senha está incorreta.',
            'present'              => 'O campo :attribute deve estar presente.',
            'regex'                => 'O formato do campo :attribute é inválido.',
            'required'             => 'O campo :attribute é obrigatório.',
            'required_if'          => 'O campo :attribute é obrigatório quando :other é :value.',
            'required_unless'      => 'O campo :attribute é obrigatório a menos que :other esteja em :values.',
            'required_with'        => 'O campo :attribute é obrigatório quando :values está presente.',
            'required_with_all'    => 'O campo :attribute é obrigatório quando :values estão presentes.',
            'required_without'     => 'O campo :attribute é obrigatório quando :values não está presente.',
            'required_without_all' => 'O campo :attribute é obrigatório quando nenhum de :values estão presentes.',
            'same'                 => 'Os campos :attribute e :other devem ser iguais.',
            'size'                 => [
                'numeric' => 'O campo :attribute deve ser :size.',
                'file'    => 'O campo :attribute deve ter :size kilobytes.',
                'string'  => 'O campo :attribute deve ter :size caracteres.',
                'array'   => 'O campo :attribute deve ter :size itens.',
            ],
            'starts_with'          => 'O campo :attribute deve começar com um dos seguintes valores: :values.',
            'string'               => 'O campo :attribute deve ser uma string.',
            'timezone'             => 'O campo :attribute deve ser um fuso horário válido.',
            'unique'               => 'O campo :attribute já está sendo utilizado.',
            'uploaded'             => 'O upload do campo :attribute falhou.',
            'url'                  => 'O campo :attribute deve ser uma URL válida.',
            'uuid'                 => 'O campo :attribute deve ser um UUID válido.',
        ];
    }
}