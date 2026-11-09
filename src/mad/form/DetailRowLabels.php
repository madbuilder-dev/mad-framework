<?php

namespace Mad\Form;

/**
 * Texto do valor que cada linha de um `<mad-detail-form>` já tem nos campos de
 * busca no banco do editor (`<mad-dbunique-search-field>`,
 * `<mad-dbmulti-search-field>`).
 *
 * Esses campos não trazem a lista inteira de opções: a do valor salvo vem do
 * registro quando a tela abre (o "pré-carregar" de cada campo). No editor do
 * detalhe o campo é desenhado vazio e o valor chega depois, da linha, ao clicar
 * em editar: sem opção para ele, o campo mostrava só a chave ("1619" no lugar de
 * "1619 - SUPERMERCADO BELLA VILLA LTDA", fórum #273).
 *
 * Aqui o servidor resolve, numa consulta por campo, o texto de todos os valores
 * das linhas carregadas, com o mesmo Model, chave e display do campo — o mesmo
 * caminho do pré-carregar do formulário principal. O navegador recebe
 * `{campo: {valor: texto}}` e põe a opção antes de selecionar o valor.
 *
 * Nunca derruba o formulário: falha na consulta deixa o campo como antes (a
 * chave), com o motivo no log.
 */
final class DetailRowLabels
{
    /** Valores por consulta (whereIn). */
    private const CHUNK = 500;

    /**
     * @param list<string>                     $fields campos do editor do detalhe
     * @param list<array<string, mixed>>       $rows   linhas carregadas
     * @return array<string, array<string, string>> campo => [valor => texto]
     */
    public static function resolve(array $fields, array $rows): array
    {
        $out = [];
        foreach ($fields as $field) {
            $field  = (string) $field;
            $source = MadFormRegistry::labelSourceOf($field);
            if ($source === null) {
                continue;
            }

            $values = self::valuesOf($rows, $field);
            if ($values === []) {
                continue;
            }

            try {
                $class  = class_exists($source['model'])
                    ? $source['model']
                    : ModelOptionsLoader::resolveModelClass($source['model']);
                $labels = [];
                foreach (array_chunk($values, self::CHUNK) as $chunk) {
                    $items = ModelOptionsLoader::itemsFromQuery(
                        $class::query()->whereIn($source['key'], $chunk),
                        $source['key'],
                        $source['display']
                    );
                    foreach ($items as $value => $text) {
                        $labels[(string) $value] = is_array($text) ? (string) ($text['text'] ?? $value) : (string) $text;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[mad-detail-form] texto do campo "' . $field . '" nas linhas: ' . $e->getMessage()
                    . ' — o campo mostra a chave ao editar.');
                continue;
            }

            if ($labels !== []) {
                $out[$field] = $labels;
            }
        }

        return $out;
    }

    /**
     * Valores distintos do campo nas linhas. Seleção múltipla vem como array,
     * JSON ou vírgula (MadForm::selectionKeys).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private static function valuesOf(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($field, $row)) {
                continue;
            }
            foreach (MadForm::selectionKeys($row[$field], ',') as $v) {
                $v = trim((string) $v);
                if ($v !== '') {
                    $values[$v] = true;
                }
            }
        }

        return array_map('strval', array_keys($values));
    }
}
