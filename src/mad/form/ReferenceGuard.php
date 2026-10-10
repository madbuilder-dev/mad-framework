<?php

namespace Mad\Form;

use Illuminate\Validation\ConditionalRules;
use Illuminate\Validation\Rules\Exists;

/**
 * ReferenceGuard — as regras de REFERÊNCIA (`exists`) que o `rules()` de um
 * Model declara, aplicadas ao que o Salvar vai gravar numa linha filha (Lista
 * de itens, Detail Form) ou numa ligação N:N (Checklist, seleção múltipla em
 * outra tabela).
 *
 * Por que existe: num app com Multi-unidade o campo de tabela só lista o que a
 * pessoa logada enxerga (os cadastros da unidade, os usuários das unidades
 * dela), mas quem confere o número que chega na requisição é a regra `exists`
 * do Model — e só o formulário principal chamava `rules()`. Nas linhas filhas
 * e nas ligações a regra existia no Model e nunca rodava: uma requisição
 * alterada gravava a chave de um cadastro de outra unidade.
 *
 * O que roda, de propósito, é SÓ a regra de referência da coluna (com o tipo
 * que a acompanha — `integer`, `numeric`, `uuid`, `ulid` —, que evita mandar
 * ao banco um valor que nem número é). Obrigatório, único, tamanho e as demais
 * regras do Model continuam sendo do formulário principal: uma linha costuma
 * ter colunas que o código da tela preenche depois (no gancho de gravação) e
 * que o `required` do Model recusaria antes da hora.
 *
 * Quem chama passa só o que MUDA: a chave que a linha já tem gravada não é
 * conferida de novo — o registro pode apontar, de antes, para um cadastro que
 * quem edita não enxerga, e salvar sem mexer nele não pode ser recusado.
 *
 * Model sem `rules()` (ou cujo `rules()` não pôde ser lido): nada a conferir,
 * como no formulário principal de uma tela que não valida.
 *
 * A coluna que guarda uma LISTA de chaves (seleção múltipla por vírgula) não
 * tem regra `exists` possível: para ela, e como segunda barreira das ligações
 * N:N, as marcas novas são conferidas na consulta do Model das opções do
 * campo — ver {@see outsideSource()}.
 */
final class ReferenceGuard
{
    /** Regras de tipo que acompanham o `exists` (o valor que não passa nelas nem chega à consulta). */
    private const TYPE_RULES = ['integer', 'numeric', 'uuid', 'ulid'];

    /**
     * As colunas de `$model` que têm regra de referência no `rules()` dele:
     * coluna => o rótulo que a regra traz (`'coluna|Rótulo'`; '' = nenhum).
     * Vazio = nada a conferir neste Model.
     *
     * @return array<string,string>
     */
    public static function columns(string $model): array
    {
        $columns = [];
        foreach (self::rulesOf($model, null) as $column => $entry) {
            try {
                $has = self::references($entry['rule'], null) !== [];
            } catch (\Throwable) {
                $has = true;   // regra que só se resolve com os dados: confere na hora
            }
            if ($has) {
                $columns[$column] = $entry['label'];
            }
        }

        return $columns;
    }

    /**
     * Confere as chaves de `$values` — o que vai ser gravado na linha `$key` de
     * `$model` (`null` = linha nova) — contra as regras de referência dele.
     *
     * @param  array<string,mixed>  $values  coluna => valor que MUDA na linha
     * @param  array<string,string> $labels  coluna => rótulo da tela (vale sobre o `'coluna|Rótulo'` da regra)
     * @param  array<string,mixed>  $row     o resto da linha, para a regra que olha outra coluna
     * @return array<string,string> coluna => mensagem ("O campo Cliente selecionado é inválido.")
     */
    public static function check(string $model, int|string|null $key, array $values, array $labels = [], array $row = []): array
    {
        $rules = $values !== [] ? self::rulesOf($model, $key) : [];
        if ($rules === []) {
            return [];
        }

        $data  = $values + $row;
        $check = [];
        $attrs = [];
        foreach ($values as $column => $value) {
            $column = (string) $column;
            if (!isset($rules[$column]) || !is_scalar($value) || is_bool($value) || trim((string) $value) === ''
                || str_contains($column, '.') || str_contains($column, '*')) {
                continue;
            }
            $reference = self::references($rules[$column]['rule'], $data);
            if ($reference === []) {
                continue;
            }
            $check[$column] = $reference;
            $label = trim((string) ($labels[$column] ?? ''));
            $attrs[$column] = $label !== '' ? $label : ($rules[$column]['label'] !== '' ? $rules[$column]['label'] : $column);
        }

        return $check === [] ? [] : MadValidator::validate($data, $check, [], $attrs);
    }

    /** Chaves por consulta ao conferir a fonte de uma seleção (limite de parâmetros do banco). */
    private const SOURCE_CHUNK = 500;

    /**
     * Das chaves `$keys`, as que a consulta de `$model` NÃO devolve para quem
     * está logado — itens de outra unidade ou empresa, usuários que a pessoa
     * não enxerga, registros excluídos ou que não existem. É a mesma consulta
     * com que o campo de seleção múltipla monta as opções (`Model::query()`,
     * com os escopos do Model); o filtro da tag não entra.
     *
     * Vale para a coluna que guarda uma LISTA de chaves (seleção múltipla por
     * vírgula): ali não há chave estrangeira, e o `rules()` do Model não tem
     * regra `exists` a aplicar.
     *
     * A comparação é pelo texto exato da chave que o servidor entregou: `04`,
     * `4 ` e `4|9` não são o item nº 4 (o banco, comparando com uma coluna
     * numérica, pode dizer que são). Model que não resolve e consulta que
     * falha (o texto que não é número numa coluna numérica, no PostgreSQL)
     * recusam tudo — quem não conseguiu listar as opções não tem marca nova a
     * gravar —, e o motivo vai para o log.
     *
     * @param  list<int|string> $keys   valores de `$key` a conferir (as marcas NOVAS)
     * @return list<string> as chaves recusadas (vazio = todas são de quem salva)
     */
    public static function outsideSource(string $model, string $key, array $keys): array
    {
        $asked = [];
        foreach ($keys as $value) {
            if (is_scalar($value) && !is_bool($value) && (string) $value !== '') {
                $asked[(string) $value] = true;
            }
        }
        $asked = array_map('strval', array_keys($asked));
        if ($asked === []) {
            return [];
        }

        try {
            $class = self::resolve($model);
            if ($class === null || !is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                throw new \RuntimeException('o Model das opções ("' . $model . '") não foi encontrado');
            }
            $key = $key !== '' ? $key : (string) (new $class())->getKeyName();

            $found = [];
            foreach (array_chunk($asked, self::SOURCE_CHUNK) as $chunk) {
                foreach ($class::query()->whereIn($key, $chunk)->pluck($key) as $value) {
                    if (is_scalar($value)) {
                        $found[(string) $value] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            self::log('a fonte das opções de uma seleção (' . $model . ') não pôde ser consultada ao conferir as marcas novas: ' . \Mad\Ui\MadErrorRedactor::describe($e));

            return $asked;
        }

        return array_values(array_filter($asked, static fn (string $v): bool => !isset($found[$v])));
    }

    /**
     * Das chaves `$keys`, as que a consulta PRÓPRIA do campo (`:query` da tag)
     * não devolve — o mesmo papel do outsideSource(), para o campo cuja lista
     * de opções é a consulta do código da tela e não a do Model.
     *
     * `$sql` e `$bindings` são a consulta como a tela a montou ao desenhar o
     * campo (QuerySource::compileSql, guardada no estado cifrado da tela —
     * nunca vinda do navegador): ela vira tabela derivada e a conferência é um
     * `whereIn` na coluna-chave das opções. Os escopos que a consulta tinha
     * (unidade, empresa, exclusão lógica) ficam como eram quando a tela abriu.
     *
     * Mesmas regras do outsideSource(): texto exato da chave, e consulta que
     * falha recusa tudo (o motivo vai para o log).
     *
     * @param  list<mixed>      $bindings
     * @param  list<int|string> $keys     as marcas NOVAS
     * @return list<string> as chaves recusadas (vazio = todas estão na consulta)
     */
    public static function outsideQuery(string $connection, string $sql, array $bindings, string $key, array $keys): array
    {
        $asked = [];
        foreach ($keys as $value) {
            if (is_scalar($value) && !is_bool($value) && (string) $value !== '') {
                $asked[(string) $value] = true;
            }
        }
        $asked = array_map('strval', array_keys($asked));
        if ($asked === []) {
            return [];
        }

        try {
            if (trim($sql) === '') {
                throw new \RuntimeException('a consulta das opções está vazia');
            }
            $key = $key !== '' ? $key : 'id';

            $found = [];
            foreach (array_chunk($asked, self::SOURCE_CHUNK) as $chunk) {
                $rows = \Illuminate\Support\Facades\DB::connection($connection !== '' ? $connection : null)->query()
                    ->fromRaw('(' . $sql . ') as mad_q', $bindings)
                    ->whereIn('mad_q.' . $key, $chunk)
                    ->pluck('mad_q.' . $key);
                foreach ($rows as $value) {
                    if (is_scalar($value)) {
                        $found[(string) $value] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            self::log('a consulta própria das opções de uma seleção não pôde ser refeita ao conferir as marcas novas: ' . \Mad\Ui\MadErrorRedactor::describe($e));

            return $asked;
        }

        return array_values(array_filter($asked, static fn (string $v): bool => !isset($found[$v])));
    }

    /**
     * Das chaves `$keys`, as que não estão entre as opções que a tela OFERECEU
     * — a lista fixa do campo (`:options`, `:items`), o que a consulta própria
     * dele (`:query`) carregou ao desenhar, ou o que o código trocou com
     * `setItems()`. Texto exato da chave, como no outsideSource().
     *
     * A lista vem do estado cifrado da tela: `$plain` com as chaves como
     * texto, ou `$prints` com a impressão de cada uma (offeredPrint) quando a
     * lista é grande demais para viajar inteira.
     *
     * @param  list<string>     $plain
     * @param  list<string>     $prints
     * @param  list<int|string> $keys   as marcas NOVAS
     * @return list<string> as chaves recusadas (vazio = todas foram oferecidas)
     */
    public static function outsideOffered(array $plain, array $prints, array $keys): array
    {
        $offered = array_fill_keys(array_map('strval', $plain), true);
        $printed = array_fill_keys(array_map('strval', $prints), true);

        $outside = [];
        foreach ($keys as $value) {
            if (!is_scalar($value) || is_bool($value) || (string) $value === '') {
                continue;
            }
            $value = (string) $value;
            if (!isset($offered[$value]) && !isset($printed[self::offeredPrint($value)])) {
                $outside[$value] = true;
            }
        }

        return array_map('strval', array_keys($outside));
    }

    /** Impressão curta de uma chave oferecida (lista grande no estado da tela). */
    public static function offeredPrint(string $key): string
    {
        return substr(hash('sha256', 'mad-offered|' . $key), 0, 12);
    }

    /**
     * `rules($key)` do Model, por coluna: a regra e o rótulo que a chave traz
     * (`'coluna|Rótulo'`, a convenção do MadForm::validate). Vazio quando o
     * Model não tem `rules()` na forma da convenção, ou quando ele falha — o
     * motivo vai para o log e nada é conferido (uma tela que gravava não passa
     * a quebrar por um `rules()` que nunca tinha sido chamado para a linha).
     *
     * @return array<string, array{rule: mixed, label: string}>
     */
    private static function rulesOf(string $model, int|string|null $key): array
    {
        try {
            $model = self::resolve($model);
            if ($model === null || !method_exists($model, 'rules')) {
                return [];
            }
            $method = new \ReflectionMethod($model, 'rules');
            if (!$method->isPublic() || $method->getNumberOfRequiredParameters() > 1) {
                return [];
            }
            $rules = $method->isStatic() ? $model::rules($key) : (new $model())->rules($key);
        } catch (\Throwable $e) {
            self::log('rules() de ' . $model . ' falhou ao conferir as chaves de uma linha: ' . $e->getMessage());

            return [];
        }
        if (!is_array($rules)) {
            return [];
        }

        $out = [];
        foreach ($rules as $name => $rule) {
            [$column, $label] = array_pad(explode('|', (string) $name, 2), 2, '');
            if ($column !== '') {
                $out[$column] = ['rule' => $rule, 'label' => trim($label)];
            }
        }

        return $out;
    }

    /** A classe do Model: o nome como veio (FQCN ou apelido carregado) ou o que o registro de Models resolve. */
    private static function resolve(string $model): ?string
    {
        if ($model === '') {
            return null;
        }
        if (class_exists($model)) {
            return $model;
        }
        $resolved = ModelOptionsLoader::resolveModelClass($model);

        return is_string($resolved) && class_exists($resolved) ? $resolved : null;
    }

    /**
     * De uma regra de coluna, o que confere a referência: os `exists` (objeto
     * ou texto) e, quando há algum, as regras de tipo que vêm antes dele — na
     * ordem em que foram escritas. `Rule::when()` é resolvido com `$data`
     * (`null` = sem dados: olha os dois ramos, só para saber se há referência).
     *
     * @return list<mixed>
     */
    private static function references(mixed $rule, ?array $data): array
    {
        $kept   = [];
        $exists = false;
        foreach (self::flatten($rule, $data) as $part) {
            if ($part instanceof Exists) {
                $kept[] = $part;
                $exists = true;
                continue;
            }
            if (is_array($part)) {
                // Forma de lista: ['exists', 'tabela', 'coluna'].
                if (is_string($part[0] ?? null) && strtolower($part[0]) === 'exists') {
                    $kept[] = $part;
                    $exists = true;
                }
                continue;
            }
            if (!is_string($part)) {
                continue;
            }
            $name = strtolower(trim(explode(':', $part, 2)[0]));
            if ($name === 'exists') {
                $kept[] = trim($part);
                $exists = true;
            } elseif (in_array($name, self::TYPE_RULES, true)) {
                $kept[] = trim($part);
            }
        }

        return $exists ? $kept : [];
    }

    /**
     * A regra aberta numa lista plana de partes (texto `a|b` dividido, listas
     * e `Rule::when()` resolvidos).
     *
     * @return list<mixed>
     */
    private static function flatten(mixed $rule, ?array $data): array
    {
        if ($rule instanceof ConditionalRules) {
            if ($data === null) {
                return array_merge(self::flatten($rule->rules([]), null), self::flatten($rule->defaultRules([]), null));
            }

            return self::flatten($rule->passes($data) ? $rule->rules($data) : $rule->defaultRules($data), $data);
        }
        if (is_string($rule)) {
            return array_values(array_filter(array_map('trim', explode('|', $rule)), static fn (string $p): bool => $p !== ''));
        }
        if (!is_array($rule)) {
            return $rule === null ? [] : [$rule];
        }
        // ['exists', 'tabela', 'coluna']: uma regra só, na forma de lista (um
        // `exists` sem parâmetros não existe, então não é uma lista de regras).
        if (is_string($rule[0] ?? null) && strtolower($rule[0]) === 'exists' && count($rule) > 1
            && array_filter($rule, static fn (mixed $p): bool => !is_scalar($p)) === []) {
            return [$rule];
        }

        $out = [];
        foreach ($rule as $part) {
            array_push($out, ...self::flatten($part, $data));
        }

        return $out;
    }

    /**
     * Registra, para quem cuida do sistema, o Salvar recusado por uma chave:
     * a tela, quem estava logado, onde a chave veio. Nunca o valor.
     */
    public static function logRefused(string $where): void
    {
        $screen = MadFormRegistry::actingScreen();
        $tela   = is_object($screen) ? (string) preg_replace('/@anonymous.*$/s', '@anonymous', $screen::class) : '-';
        $user   = '-';
        try {
            if (function_exists('session')) {
                $user = (string) (session('userid') ?? '-');
            }
        } catch (\Throwable) {
            // sem sessão (comando, teste): fica sem o usuário
        }

        self::log(sprintf(
            'Salvar recusado em %s (usuário %s): %s aponta para um registro que quem salva não enxerga, ou que não existe mais.'
            . ' A lista do campo não oferece esse registro: a requisição foi alterada, ou a opção saiu da lista depois de a tela abrir.',
            $tela,
            $user,
            $where,
        ));
    }

    private static function log(string $message): void
    {
        $message = '[MadForm] ' . $message;
        try {
            function_exists('logger') ? logger()->warning($message) : error_log($message);
        } catch (\Throwable) {
            error_log($message);
        }
    }
}
