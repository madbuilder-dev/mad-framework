<?php
namespace Mad\Grid;

/**
 * MadGridSelection — seleção de linhas de um `<mad-grid selectable>`, lida de
 * qualquer tela.
 *
 * ┌─ Onde a seleção mora ───────────────────────────────────────────────────┐
 * │                                                                        │
 * │  1. No navegador (Alpine): marcar/desmarcar não vai ao servidor.       │
 * │  2. Em TODA requisição do grid (paginar, buscar, ordenar, filtrar,     │
 * │     ação de linha, botão do cabeçalho, ação em lote) a seleção viaja   │
 * │     no campo oculto `__mad_grid_sel` (JSON). O MadDataGrid a guarda no │
 * │     estado criptografado e a espelha aqui — na sessão, por classe do   │
 * │     grid. É por isso que ela sobrevive à troca de página e à reabertura │
 * │     da tela, e que OUTRA tela consegue lê-la.                          │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ Uso ──────────────────────────────────────────────────────────────────┐
 * │                                                                        │
 * │  // No próprio grid (ação de linha, botão do cabeçalho, ação em lote): │
 * │  $ids = $this->selectedIds();                                          │
 * │                                                                        │
 * │  // Em outra tela (ex.: a aberta por <mad-bulk-action target>):        │
 * │  $ids = MadGridSelection::get(ContaPagarList::class);                  │
 * │  $ids = MadGridSelection::fromParams($params);   // o `ids` recebido   │
 * │                                                                        │
 * │  // Depois de processar:                                               │
 * │  MadGridSelection::clear(ContaPagarList::class);                       │
 * │                                                                        │
 * │  Conta::whereIn('id', $ids)->update([...]);                            │
 * │                                                                        │
 * └────────────────────────────────────────────────────────────────────────┘
 *
 * Formato: `array<id, id>` — o id é a CHAVE e o VALOR (como o TCheckGroup e a
 * sessão de seleção do 4.0). Percorra com `foreach`, passe direto a
 * `whereIn()`/`count()`/`implode()`, e teste uma linha com `isset($ids[$id])`.
 * `array_values()` quando precisar de índice numérico.
 *
 * A seleção é ENTRADA DO USUÁRIO (vem do navegador): o método que a processa
 * autoriza e filtra como faria com qualquer parâmetro — um id marcado pode ter
 * sido apagado por outro usuário, ou ter saído do filtro da listagem.
 */
final class MadGridSelection
{
    /** Parâmetro que a tela aberta por `<mad-bulk-action target>` recebe. */
    public const PARAM = 'ids';

    /** Campo oculto (JSON) com a seleção, postado em toda requisição do grid. */
    public const REQUEST_FIELD = '__mad_grid_sel';

    /** Teto de ids guardados (a seleção vai e volta em toda requisição). */
    public const MAX = 5000;

    private const SESSION_KEY = 'mad_grid_selection';

    /**
     * Seleção vista NESTA requisição, por grid. Vence a sessão: sem sessão
     * (teste, CLI) ou antes de ela ser gravada, quem lê em seguida enxerga o
     * valor recém-chegado.
     *
     * @var array<string, array<int|string, string>>
     */
    private static array $current = [];

    /** Seleção do grid (classe, ex.: `ContaPagarList::class` ou `'ContaPagarList'`). */
    public static function get(string $grid): array
    {
        $key = self::key($grid);
        if ($key === '') {
            return [];
        }
        if (array_key_exists($key, self::$current)) {
            return self::$current[$key];
        }
        $all = self::sessionAll();

        return self::normalize($all[$key] ?? []);
    }

    /** Substitui a seleção do grid (lista de ids, `[id => id]`, "1,2,3" ou JSON). */
    public static function put(string $grid, mixed $ids): void
    {
        $key = self::key($grid);
        if ($key === '') {
            return;
        }
        $ids = self::normalize($ids);
        self::$current[$key] = $ids;

        $all = self::sessionAll();
        if ($ids === []) {
            unset($all[$key]);
        } else {
            // Na sessão só a lista: o formato [id => id] é remontado na leitura.
            $all[$key] = array_values($ids);
        }
        self::sessionPut($all);
    }

    /** Esvazia a seleção do grid (ex.: depois de processar o lote). */
    public static function clear(string $grid): void
    {
        self::put($grid, []);
    }

    /**
     * Ids recebidos pela tela de destino de um `<mad-bulk-action target>`
     * (`$params['ids']`, "1,2,3"). Aceita também lista/array e JSON.
     */
    public static function fromParams(array $params, string $key = self::PARAM): array
    {
        return self::normalize($params[$key] ?? []);
    }

    /**
     * Normaliza qualquer forma de seleção para `[id => id]` (strings), sem
     * vazios, sem repetidos, na ordem em que chegaram, até MAX.
     *
     * Aceita: lista (`[3, 5]`), mapa do 4.0 (`[3 => 3]`), "3,5", JSON
     * (`'["3","5"]'`), um id solto, null.
     *
     * @return array<int|string, string>
     */
    public static function normalize(mixed $ids): array
    {
        if ($ids === null || $ids === '' || $ids === false) {
            return [];
        }
        if (is_string($ids)) {
            $trim = trim($ids);
            if ($trim !== '' && ($trim[0] === '[' || $trim[0] === '{')) {
                $decoded = json_decode($trim, true);
                $ids = is_array($decoded) ? $decoded : [];
            } else {
                $ids = explode(',', $trim);
            }
        } elseif (is_object($ids)) {
            $ids = $ids instanceof \Traversable ? iterator_to_array($ids) : get_object_vars($ids);
        } elseif (!is_array($ids)) {
            $ids = [$ids];
        }

        $out = [];
        foreach ($ids as $id) {
            if (!is_scalar($id) || is_bool($id)) {
                continue;
            }
            $id = trim((string) $id);
            if ($id === '' || isset($out[$id])) {
                continue;
            }
            $out[$id] = $id;
            if (count($out) >= self::MAX) {
                break;
            }
        }

        return $out;
    }

    /** Chave do grid: nome da classe sem a barra inicial. */
    public static function key(string $grid): string
    {
        return ltrim(trim($grid), '\\');
    }

    /** @internal testes: esquece a seleção vista nesta requisição. */
    public static function _resetForTests(): void
    {
        self::$current = [];
    }

    /** @return array<string, list<string>> */
    private static function sessionAll(): array
    {
        try {
            if (function_exists('session') && function_exists('app') && app()->bound('session')) {
                $all = session(self::SESSION_KEY);

                return is_array($all) ? $all : [];
            }
        } catch (\Throwable $e) {
            // sem sessão (CLI/teste): só o valor da requisição
        }

        return [];
    }

    private static function sessionPut(array $all): void
    {
        try {
            if (function_exists('session') && function_exists('app') && app()->bound('session')) {
                session([self::SESSION_KEY => $all]);
            }
        } catch (\Throwable $e) {
            // sem sessão (CLI/teste): só o valor da requisição
        }
    }
}
