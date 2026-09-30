<?php
namespace Mad\Form;

/**
 * Objeto devolvido por {@see MadForm::getData()} — um `stdClass` em que o
 * campo DECLARADO no formulário e não enviado lê `null` em vez de estourar
 * "Undefined property".
 *
 * Por que existe: o POST só traz o que o navegador envia. Rádio sem opção
 * marcada, campo desabilitado, o primeiro render da página (GET, sem POST
 * nenhum) e a paginação de um grid antes do primeiro "Aplicar" chegam sem a
 * chave, e o `getData()` montava o objeto só com o que chegou. Código que lê
 * `$data->campo` — o padrão do Adianti 4.0, onde `TForm::getData()` devolvia
 * TODOS os campos, e o que qualquer um escreve à mão — derrubava a tela:
 *
 *     if ($data->filtros_rapidos == 'atrasadas')   // ViewException no GET
 *
 * Por que a propriedade é VIRTUAL (via __get) e não um `null` de verdade: o
 * mesmo objeto alimenta a gravação — `fillRecord()`/`save()`, o
 * `<mad-db-blocks>` e o código de usuário `$model->fill((array) $data)`.
 * Uma chave `null` real faria a gravação escrever NULL em coluna que o
 * usuário não tocou: o campo desabilitado apagaria o valor do registro, e o
 * rádio vazio num cadastro novo passaria por cima do DEFAULT da coluna
 * (NOT NULL → INSERT falha). Virtual, o campo ausente só existe para LEITURA:
 * `(array)`, `foreach`, `json_encode` e `get_object_vars` continuam vendo
 * exatamente o que veio no POST, como antes.
 *
 * Contrato de leitura do campo declarado e ausente:
 *   $data->campo            → null (sem warning)
 *   isset($data->campo)     → false  ·  empty() → true  ·  `?? 'x'` → 'x'
 *   property_exists(...)    → false (não é propriedade real)
 *
 * Nome que NÃO é campo do formulário continua avisando ("Undefined property"),
 * como um stdClass: erro de digitação não vira null calado.
 *
 * "Declarado" = está no schema do `<mad-form-token>` que veio no request
 * (ações do MadWire) ou foi registrado no render corrente (GET inicial: o
 * `<mad-grid-filters>` renderiza antes do grid consultar).
 */
final class MadFormData extends \stdClass
{
    /**
     * Campos declarados por instância. Fora do objeto de propósito: uma
     * propriedade privada apareceria no cast `(array) $data` (chave
     * "\0Mad\Form\MadFormData\0…") e iria parar no `fill()` do model.
     *
     * @var \WeakMap<self, array<string, true>>|null
     */
    private static ?\WeakMap $declared = null;

    /**
     * @param array<string|int, mixed> $values   o que o getData() montou (vai como propriedade real)
     * @param array<int, string>       $declared nomes de campo do formulário (lêem null quando ausentes)
     */
    public static function make(array $values, array $declared = []): self
    {
        $data = new self();
        foreach ($values as $key => $value) {
            $key = (string) $key;
            // Nome que não pode ser propriedade (vazio / "\0…") — o antigo
            // `(object) $array` também não o expunha como `$data->x` legível.
            if ($key === '' || $key[0] === "\0") {
                continue;
            }
            $data->{$key} = $value;
        }

        self::map()[$data] = array_fill_keys(array_map('strval', $declared), true);

        return $data;
    }

    public function __get(string $name): mixed
    {
        if ($this->isDeclared($name)) {
            return null;
        }

        // Mesmo aviso que um stdClass daria — o handler do Laravel o converte
        // em ErrorException, como antes.
        trigger_error(sprintf('Undefined property: %s::$%s', self::class, $name), E_USER_WARNING);

        return null;
    }

    /** Propriedade virtual vale null → isset/empty/?? tratam como ausente. */
    public function __isset(string $name): bool
    {
        return false;
    }

    /**
     * O campo é do formulário (schema do request ou render corrente)?
     *
     * Um `clone` ou um objeto desserializado não está no mapa e fica só com o
     * registry do render — o caso de uso é ler o que o getData() devolveu.
     */
    private function isDeclared(string $name): bool
    {
        $map = self::map();
        if (isset($map[$this]) && isset($map[$this][$name])) {
            return true;
        }

        // Registrado no render corrente depois do getData() (cache): o
        // registry é consultado na hora da leitura, não só na montagem.
        return array_key_exists($name, MadFormRegistry::getFields());
    }

    /** @return \WeakMap<self, array<string, true>> */
    private static function map(): \WeakMap
    {
        return self::$declared ??= new \WeakMap();
    }
}
