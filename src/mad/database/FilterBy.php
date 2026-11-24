<?php

namespace Mad\Database;

/**
 * Colunas reais em que um campo CALCULADO do Model (accessor) filtra e busca
 * (fórum #393).
 *
 *     #[FilterBy('cpf', 'cnpj')]
 *     public function getDoccnpjcpfAttribute()
 *     {
 *         return $this->cpf ?: $this->cnpj;
 *     }
 *
 * O accessor continua só exibindo; com a declaração, o filtro da coluna, o
 * campo do painel de filtros, o filtro avançado e a busca da listagem que
 * citam `doccnpjcpf` procuram em `cpf` OU `cnpj`, com o operador escolhido.
 * Sem a declaração, nada muda: o campo é só exibição.
 *
 * Só valem colunas da tabela do PRÓPRIO Model, conferidas no schema
 * ({@see ModelAccessors::filterColumns()}); nome que não é coluna, coluna
 * escondida (`$hidden`) e caminho de relação ficam de fora. Ordenar pelo
 * campo calculado continua desligado.
 *
 * O nome é lido pela reflexão sem instanciar a classe: `#[FilterBy(…)]` sem o
 * `use` também vale (só o nome curto conta), e um framework antigo apenas
 * ignora o atributo.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class FilterBy
{
    /** @var list<string> */
    public array $columns;

    public function __construct(string|array ...$columns)
    {
        $flat = [];
        array_walk_recursive($columns, static function ($c) use (&$flat): void {
            if (is_string($c)) {
                $flat[] = $c;
            }
        });
        $this->columns = $flat;
    }
}
