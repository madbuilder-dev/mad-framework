<?php

namespace Mad\Database;

/**
 * Revisões da migration incremental que o MadBuilder gera (fórum #336).
 *
 * A migration `…_update_<banco>_vX_to_vY` muda de conteúdo enquanto o
 * checkpoint não é congelado; cada conteúdo novo ganha uma revisão no nome:
 * `…_v10_to_v11_r001`, `_r002`, `_r003`… Cada revisão leva o delta INTEIRO
 * desde a baseline e é reexecutável (guards de existência), então só a mais
 * nova importa. O app grava cada uma num arquivo; uma revisão pendente que
 * falha (uma view com erro) travava o `migrate` antes de chegar à que corrige.
 *
 * Aqui só a regra, pura: dada a lista de migrations do app e as já aplicadas,
 * quais pendentes estão SUPERADAS (há revisão maior do mesmo nome). Uma
 * aplicada nunca é superada — o que já rodou fica como está.
 */
final class MigrationRevisions
{
    /**
     * `2026_11_16_000008_update_max_sistema_v10_to_v11` + revisão. O nome sem
     * `_rNNN` (de antes das revisões) conta como revisão 0 do mesmo nome.
     *
     * A família é o passo (`update_<banco>_vX_to_vY`), SEM a data e a
     * sequência do começo do nome (fórum #338): a revisão gerada em outro dia
     * (`2026_11_17_…_r001`) é do mesmo passo que a `2026_11_16_…_r010` que
     * ficou pendente, e é ela a mais nova.
     */
    private const FAMILY = '/^(\d{4}_\d{2}_\d{2}_\d{6})_(update_[a-z0-9_]+?_v\d+_to_v\d+)(?:_r(\d{3,}))?$/i';

    /**
     * @return array{0: string, 1: int}|null  [passo, revisão]
     */
    public static function family(string $migration): ?array
    {
        if (! preg_match(self::FAMILY, $migration, $m)) {
            return null;
        }

        return [strtolower($m[2]), isset($m[3]) ? (int) $m[3] : 0];
    }

    /**
     * Ordem dentro do passo: data e sequência do nome, depois a revisão (a
     * revisão é número: `r1000` vem depois de `r999`).
     *
     * @return array{0: string, 1: int}|null
     */
    private static function rank(string $migration): ?array
    {
        if (! preg_match(self::FAMILY, $migration, $m)) {
            return null;
        }

        return [$m[1], isset($m[3]) ? (int) $m[3] : 0];
    }

    /**
     * Pendentes superadas: migration → a revisão mais nova do mesmo nome.
     *
     * @param  iterable<string>  $migrations  nomes (sem .php) de todos os arquivos do app
     * @param  iterable<string>  $ran         nomes já gravados na tabela `migrations`
     * @return array<string, string>
     */
    public static function superseded(iterable $migrations, iterable $ran): array
    {
        $ranSet = [];
        foreach ($ran as $name) {
            $ranSet[(string) $name] = true;
        }

        $newest = [];   // passo → [ordem, migration]
        $members = [];  // passo → migrations da família
        foreach ($migrations as $name) {
            $name = (string) $name;
            $fam = self::family($name);
            if ($fam === null) {
                continue;
            }
            $base = $fam[0];
            $rank = self::rank($name);
            $members[$base][] = $name;
            if (! isset($newest[$base]) || ($rank <=> $newest[$base][0]) > 0) {
                $newest[$base] = [$rank, $name];
            }
        }

        $out = [];
        foreach ($members as $base => $names) {
            $top = $newest[$base][1];
            foreach ($names as $name) {
                if ($name !== $top && ! isset($ranSet[$name])) {
                    $out[$name] = $top;
                }
            }
        }
        ksort($out);

        return $out;
    }
}
