<?php

namespace Mad\Ai;

/**
 * ChatDashboards — filtro e busca do dashboard que a IA monta no chat
 * (show_dashboard).
 *
 * Cada dashboard emitido vira uma entrada no registro da conversa
 * (mad_ai_conversation.dashboards_json): o bloco como a tela mostra + o spec
 * (SQL ou tool) dos widgets que vieram de preview/save_widget. Quando o usuário
 * mexe num filtro, o iframe manda `dashboardAction {dashboardId, values}`:
 *
 * - todo widget tem spec e todo filtro preenchido é consumido por algum deles
 *   (:placeholder da SQL / arg da tool) → reexecuta os widgets aqui, sem o
 *   modelo, e devolve o dashboard com o MESMO id (a tela troca no lugar);
 * - senão → turno sintético para o modelo refazer as consultas e reemitir o
 *   show_dashboard com o mesmo id.
 *
 * Os valores do filtro só passam pelas chaves declaradas no `filters` do
 * dashboard; select só com uma das opções. O spec vem do servidor (registro da
 * conversa), nunca do cliente.
 */
final class ChatDashboards
{
    /** Dashboards guardados por conversa (os mais recentes). */
    public const MAX = 10;

    /** Teto de um valor de busca/filtro. */
    private const MAX_VALUE = 200;

    /**
     * Entrada do registro para um dashboard recém-emitido.
     *
     * @param array<string, mixed> $dash bloco dashboard (já validado)
     * @return array{block: array<string, mixed>, specs: array<string, array<string, mixed>>}
     */
    public static function entry(array $dash, ?ProvenanceTracker $trace): array
    {
        $specs = [];
        foreach ((array) ($dash['widgets'] ?? []) as $w) {
            $id   = is_array($w) ? (string) ($w['block']['id'] ?? '') : '';
            $spec = $id !== '' ? $trace?->specFor($id) : null;
            if (is_array($spec)) {
                $specs[$id] = $spec;
            }
        }

        return ['block' => $dash, 'specs' => $specs];
    }

    /**
     * Registro da conversa + dashboards do turno (o do turno vence e vai para o
     * fim), cortado nos MAX mais recentes.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $turn
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $turn): array
    {
        foreach ($turn as $id => $entry) {
            unset($stored[$id]);
            $stored[$id] = $entry;
        }

        return array_slice($stored, -self::MAX, null, true);
    }

    /**
     * Valores aceitos do filtro: só as chaves do `filters` do dashboard (chave
     * ausente mantém o valor atual); select fora das opções vira vazio; texto
     * sem caractere de controle e até MAX_VALUE.
     *
     * @param array<string, mixed> $dash
     * @return array<string, string>
     */
    public static function cleanValues(array $dash, mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ((array) ($dash['filters'] ?? []) as $f) {
            $key = is_array($f) ? (string) ($f['key'] ?? '') : '';
            if ($key === '') {
                continue;
            }
            if (! array_key_exists($key, $raw)) {
                $out[$key] = (string) ($f['value'] ?? '');
                continue;
            }
            $v = is_scalar($raw[$key]) ? (string) $raw[$key] : '';
            $v = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $v));
            $v = mb_substr($v, 0, self::MAX_VALUE);
            $options = array_map('strval', (array) ($f['options'] ?? []));
            if (($f['kind'] ?? '') === 'select' && $v !== '' && $options !== [] && ! in_array($v, $options, true)) {
                $v = '';
            }
            $out[$key] = $v;
        }

        return $out;
    }

    /**
     * Dá para atualizar sem o modelo? Todo widget precisa de spec e todo filtro
     * preenchido tem de ser consumido por algum widget — senão o "filtro" não
     * mudaria nada e o usuário veria o mesmo painel.
     *
     * @param array<string, mixed>  $entry
     * @param array<string, string> $values
     */
    public static function canRefreshLocally(array $entry, array $values): bool
    {
        $widgets = (array) ($entry['block']['widgets'] ?? []);
        $specs   = (array) ($entry['specs'] ?? []);
        if ($widgets === []) {
            return false;
        }
        foreach ($widgets as $w) {
            $id = is_array($w) ? (string) ($w['block']['id'] ?? '') : '';
            if ($id === '' || ! is_array($specs[$id] ?? null)) {
                return false;
            }
        }
        foreach ($values as $key => $v) {
            if ($v === '') {
                continue;
            }
            $used = false;
            foreach ($specs as $spec) {
                if (in_array((string) $key, WidgetBlockBuilder::consumedNames($spec), true)) {
                    $used = true;
                    break;
                }
            }
            if (! $used) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reexecuta cada widget com os filtros (sem IA). Devolve o dashboard novo
     * (mesmo id, mesmos widgets e ids, filters com o valor aplicado) ou null se
     * algum widget falhou — aí o turno cai no modelo.
     *
     * @param array<string, mixed>  $entry
     * @param array<string, string> $values
     * @return array<string, mixed>|null
     */
    public static function refresh(array $entry, array $values, string $db): ?array
    {
        $dash    = (array) ($entry['block'] ?? []);
        $filters = (array) ($dash['filters'] ?? []);

        foreach ((array) ($dash['widgets'] ?? []) as $i => $w) {
            $id   = (string) ($w['block']['id'] ?? '');
            $spec = self::withFilterParams((array) ($entry['specs'][$id] ?? []), $filters);
            try {
                $built = WidgetBlockBuilder::build($spec, $db, $values);
            } catch (\Throwable $e) {
                error_log('[chat-dashboard] refresh ' . $id . ': ' . $e->getMessage());

                return null;
            }
            if (! ($built['ok'] ?? false) || ! is_array($built['block'] ?? null)) {
                return null;
            }
            $block       = $built['block'];
            $block['id'] = $id;
            if (isset($w['block']['source'])) {
                $block['source'] = $w['block']['source'];
            }
            $dash['widgets'][$i]['block'] = $block;
        }

        foreach ($filters as $fi => $f) {
            $v = $values[(string) ($f['key'] ?? '')] ?? '';
            if ($v === '') {
                unset($dash['filters'][$fi]['value']);
            } else {
                $dash['filters'][$fi]['value'] = $v;
            }
        }

        return $dash;
    }

    /**
     * Turno sintético para o modelo quando o refresh local não cobre: o
     * dashboard atual vai junto (o turno original pode ter saído do histórico).
     *
     * @param array<string, mixed>  $entry
     * @param array<string, string> $values
     */
    public static function refreshMessage(array $entry, array $values): string
    {
        $dash  = (array) ($entry['block'] ?? []);
        $id    = (string) ($dash['id'] ?? '');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        $lines = [];
        foreach ((array) ($dash['filters'] ?? []) as $f) {
            $key     = (string) ($f['key'] ?? '');
            $v       = $values[$key] ?? '';
            $lines[] = '- ' . (string) ($f['label'] ?? $key) . ' (' . $key . '): '
                . ($v === '' ? '(sem filtro)' : json_encode($v, $flags));
        }

        $widgets = [];
        foreach ((array) ($dash['widgets'] ?? []) as $w) {
            $b    = (array) ($w['block'] ?? []);
            $desc = array_filter([
                'type'  => $b['type'] ?? null,
                'title' => $b['title'] ?? null,
                'span'  => $w['span'] ?? null,
            ], static fn ($v) => $v !== null);
            $spec = $entry['specs'][(string) ($b['id'] ?? '')] ?? null;
            if (is_array($spec)) {
                $desc['preview_widget'] = array_filter([
                    'sql'    => $spec['sql'] ?? null,
                    'source' => $spec['source'] ?? null,
                    'map'    => $spec['map'] ?? null,
                    'params' => $spec['params'] ?? null,
                ], static fn ($v) => $v !== null && $v !== '' && $v !== []);
            } elseif (isset($b['source'])) {
                $desc['source'] = $b['source'];
            }
            $widgets[] = $desc;
        }

        return '[Filtro aplicado no painel ' . json_encode((string) ($dash['title'] ?? $id), $flags) . ' — id ' . $id . "]\n"
            . "Filtros:\n" . implode("\n", $lines) . "\n"
            . 'Widgets atuais: ' . json_encode($widgets, $flags) . "\n"
            . 'Refaça as consultas de cada widget aplicando os filtros, reemita os widgets e chame show_dashboard com o MESMO id "'
            . $id . '", o mesmo título e a mesma composição, com `filters` ecoando os valores em `value`. Responda no máximo 1 frase.';
    }

    /**
     * Grava o turno no transcript como a tela mostra: dashboard com id já
     * presente substitui o antigo no lugar, e blocos soltos absorvidos pela
     * moldura (widgets) saem do turno. Devolve os itens que ainda formam uma
     * resposta própria (texto, tools, outros blocos) — pode ficar vazio.
     *
     * @param list<array<string, mixed>> $transcript
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function fold(array &$transcript, array $items): array
    {
        foreach ($items as $k => $it) {
            $dash = ($it['k'] ?? '') === 'block' ? ($it['b'] ?? null) : null;
            if (! is_array($dash) || ($dash['type'] ?? '') !== 'dashboard') {
                continue;
            }
            $absorbed = [];
            foreach ((array) ($dash['widgets'] ?? []) as $w) {
                $wid = is_array($w) ? ($w['block']['id'] ?? null) : null;
                if (is_string($wid) && $wid !== '') {
                    $absorbed[] = $wid;
                }
            }
            foreach ($items as $j => $o) {
                $b = ($o['k'] ?? '') === 'block' ? ($o['b'] ?? null) : null;
                if (is_array($b) && ($b['type'] ?? '') !== 'dashboard' && in_array($b['id'] ?? null, $absorbed, true)) {
                    unset($items[$j]);
                }
            }
            if (self::replaceIn($transcript, $dash)) {
                unset($items[$k]);
            }
        }

        return array_values($items);
    }

    /**
     * Troca, no transcript, o dashboard de mesmo id pelo novo (o mais recente).
     *
     * @param list<array<string, mixed>> $transcript
     * @param array<string, mixed>       $dash
     */
    public static function replaceIn(array &$transcript, array $dash): bool
    {
        $id = (string) ($dash['id'] ?? '');
        for ($i = count($transcript) - 1; $i >= 0; $i--) {
            if (($transcript[$i]['role'] ?? '') !== 'agent') {
                continue;
            }
            foreach ((array) ($transcript[$i]['items'] ?? []) as $j => $it) {
                $b = ($it['k'] ?? '') === 'block' ? ($it['b'] ?? null) : null;
                if (is_array($b) && ($b['type'] ?? '') === 'dashboard' && ($b['id'] ?? null) === $id) {
                    $transcript[$i]['items'][$j]['b'] = $dash;

                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Os filtros do dashboard entram como params do widget (se ele não os
     * declarou): assim o build aceita o valor — no :placeholder da SQL, no arg
     * da tool ou recortando as linhas pela coluna homônima.
     *
     * @param array<string, mixed>       $spec
     * @param list<array<string, mixed>> $filters
     * @return array<string, mixed>
     */
    private static function withFilterParams(array $spec, array $filters): array
    {
        $declared = array_column(WidgetBlockBuilder::params($spec), 'name');
        $params   = is_array($spec['params'] ?? null) ? array_values($spec['params']) : [];
        foreach ($filters as $f) {
            $key = (string) ($f['key'] ?? '');
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key) !== 1 || in_array($key, $declared, true)) {
                continue;
            }
            $params[] = [
                'name'    => $key,
                'label'   => (string) ($f['label'] ?? $key),
                'kind'    => ($f['kind'] ?? '') === 'select' ? 'select' : 'search',
                'options' => array_values((array) ($f['options'] ?? [])),
                'default' => '',
            ];
        }
        $spec['params'] = $params;

        return $spec;
    }
}
