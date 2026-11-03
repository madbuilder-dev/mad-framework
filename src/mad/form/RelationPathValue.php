<?php

namespace Mad\Form;

use Illuminate\Database\Eloquent\Model;
use Mad\Component\MadRenderContext;

/**
 * RelationPathValue — o valor de um campo cujo `name` é um CAMINHO DE RELAÇÃO
 * (`divisao->subgrupo->grupo_id`), lido do registro que o formulário abriu.
 *
 * O cadastro de produto grava só `divisao_id`; Grupo e Subgrupo são combos de
 * filtro da cascata (`depends-on`), e o valor deles na edição vem do caminho a
 * partir do produto. O caminho não é coluna do registro editado: não está no
 * contexto do formulário e não é gravado pelo Salvar (MadForm::fillRecord).
 *
 * ## A regra do combo
 *
 * O valor do combo é a CHAVE do registro do Model das opções. O caminho que
 * passa por esse registro vale a chave dele, seja qual for a coluna no fim:
 * `divisao->subgrupo->grupo->nome` num combo de Grupo vale o id do grupo (o
 * Studio deixa escolher a coluna de exibição no fim do caminho). Antes o
 * combo recebia o nome, que não é chave de opção nenhuma, e abria em
 * "Registro atual (fora da sua lista)" (fórum #217).
 *
 * ## A cascata
 *
 * O `depends-on` do combo filho é o `name` do pai. O valor que o pai escolheu
 * ao ser desenhado fica anotado (remember/chosen) e é por ele que o filho
 * filtra no primeiro desenho — e não pela coluna do fim do caminho do pai.
 * A anotação é do formulário em desenho (WeakMap): some com ele, não passa de
 * uma requisição para outra.
 */
final class RelationPathValue
{
    /** @var \WeakMap<object, array<string,string>>|null valor escolhido por formulário => name */
    private static ?\WeakMap $chosen = null;

    /** `{a->b}` ou `a->b` → `a->b`. */
    public static function normalize(string $name): string
    {
        $name = trim($name);
        if ($name !== '' && $name[0] === '{' && substr($name, -1) === '}') {
            $name = trim(substr($name, 1, -1));
        }

        return $name;
    }

    /** O nome é um caminho de relação (`a->b`)? */
    public static function isPath(string $name): bool
    {
        return str_contains(self::normalize($name), '->');
    }

    /**
     * O valor do caminho no registro que o formulário em desenho abriu
     * (`fill($registro)`). Null quando não é caminho, não há registro ou algum
     * elo está vazio.
     *
     * Com `$modelClass` (o Model das opções de um combo): o caminho que passa
     * por um registro desse Model vale a CHAVE dele (`$key`, ou a chave do
     * Model), e o caminho que termina num registro de outro Model vale null.
     * Sem ele, o valor é o do fim do caminho, como está no banco.
     */
    public static function fromRecord(string $path, ?string $modelClass = null, string $key = ''): mixed
    {
        $path = self::normalize($path);
        if (!str_contains($path, '->')) {
            return null;
        }
        $form   = MadRenderContext::getForm();
        $record = $form ? $form->getSourceRecord() : null;
        if (!$record) {
            return null;
        }
        if ($modelClass !== null && ($modelClass === '' || !class_exists($modelClass))) {
            $modelClass = null;
        }

        $parts = array_map('trim', explode('->', $path));
        $last  = count($parts) - 1;
        $cur   = $record;
        foreach ($parts as $i => $part) {
            // Chegou ao registro do Model do combo antes da coluna final: o
            // valor do combo é a chave dele, não `nome`/`descricao`.
            if ($i === $last && $modelClass !== null && $cur instanceof $modelClass) {
                return self::keyOf($cur, $key);
            }
            $cur = self::step($cur, $part);
            if ($cur === null) {
                return null;
            }
        }

        // O caminho termina num registro (`divisao->subgrupo`).
        if ($cur instanceof Model) {
            return $modelClass !== null && $cur instanceof $modelClass ? self::keyOf($cur, $key) : null;
        }

        return $cur;
    }

    /**
     * Anota o valor com que o campo `$name` foi desenhado no formulário em
     * desenho — é o que o combo filho (`depends-on="$name"`) usa como pai.
     */
    public static function remember(string $name, string $value): void
    {
        $form = MadRenderContext::getForm();
        if (!$form) {
            return;
        }
        self::$chosen ??= new \WeakMap();
        $values = self::$chosen[$form] ?? [];
        $values[self::normalize($name)] = $value;
        self::$chosen[$form] = $values;
    }

    /** O valor anotado para `$name` no formulário em desenho, ou null. */
    public static function chosen(string $name): ?string
    {
        $form = MadRenderContext::getForm();
        if (!$form || self::$chosen === null || !isset(self::$chosen[$form])) {
            return null;
        }

        return self::$chosen[$form][self::normalize($name)] ?? null;
    }

    private static function step(mixed $cur, string $part): mixed
    {
        if ($part === '') {
            return null;
        }
        if (is_object($cur)) {
            try {
                return $cur->{$part} ?? null;
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_array($cur)) {
            return $cur[$part] ?? null;
        }

        return null;
    }

    private static function keyOf(object $record, string $key): mixed
    {
        if ($record instanceof Model) {
            return $key !== '' ? $record->getAttribute($key) : $record->getKey();
        }

        return $record->{$key !== '' ? $key : 'id'} ?? null;
    }
}
