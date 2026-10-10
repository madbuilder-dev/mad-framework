<?php
namespace Mad\Form;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Http\MadStateCrypt;
use Mad\View\MadBlade;

/**
 * MadDbBlocks — generic helper for the <mad-db-blocks> Blade component.
 *
 * Provides state encryption + server-side rendering of pivot rows via a
 * user-supplied Blade row-view partial. Action dispatch (add/remove/update)
 * lives in MadDbBlocksTrait, which the host MadComponent must `use`.
 */
class MadDbBlocks
{
    /**
     * Descobre o ID do registro-pai quando a tag não recebe `record-id`.
     *
     * A view do componente é ANÔNIMA: ela não enxerga as variáveis da tela, só
     * os atributos passados. Sem isso, `<mad-comments model=… foreign-key=…/>`
     * numa tela de detalhe gravava o filho com a FK **nula** (o insert estourava
     * NOT NULL). O id é resolvido no SERVIDOR — do componente que está
     * renderizando (ou executando a ação) — e só então entra no state cifrado;
     * o cliente nunca escolhe o pai.
     *
     * Ordem: valor explícito → `registroId` → `recordId` → `id` do componente.
     *
     * @param object|null $component Componente; null = o do contexto de render.
     */
    public static function resolveRecordId(mixed $explicit = null, ?object $component = null): mixed
    {
        if ($explicit !== null && $explicit !== '' && $explicit !== 0 && $explicit !== '0') {
            return $explicit;
        }

        $comp = $component ?: \Mad\Component\MadRenderContext::getComponent();
        if (!$comp) {
            return $explicit;
        }

        foreach (['registroId', 'recordId', 'id'] as $prop) {
            if (!property_exists($comp, $prop)) {
                continue;
            }
            // Prop tipada não-inicializada explode ao ser lida.
            try {
                $v = $comp->$prop;
            } catch (\Throwable) {
                continue;
            }
            if ($v !== null && $v !== '' && $v !== 0 && $v !== '0') {
                return $v;
            }
        }

        return $explicit;
    }

    /**
     * Normaliza a prop `confirm-remove` dos blocos numa MENSAGEM.
     *
     * O atributo aceita três formas, e todas passam por aqui para o template do
     * item receber sempre string:
     *
     *   confirm-remove                    → $padrao ("Remover este item?")
     *   confirm-remove="Excluir a nota?"  → a mensagem escrita
     *   (ausente / false / "")            → '' (sem confirmação — o padrão)
     *
     * Opt-in de propósito: ligar a confirmação para todo mundo num update do
     * framework mudaria o comportamento de telas que já existem sem ninguém
     * pedir. Quem quer, declara.
     */
    public static function confirmMessage(mixed $valor, string $padrao): string
    {
        if ($valor === true || $valor === 1 || $valor === '1' || $valor === 'true') {
            return $padrao;
        }
        if (! is_string($valor)) {
            return '';
        }
        $valor = trim($valor);

        return ($valor === '' || $valor === '0' || strtolower($valor) === 'false') ? '' : $valor;
    }

    public static function encodeState(array $config): string
    {
        return MadStateCrypt::encrypt($config);
    }

    public static function decodeState(string $token): ?array
    {
        return MadStateCrypt::decrypt($token);
    }

    /**
     * As condições que definem QUAIS linhas o bloco mostra: a chave do pai
     * (modo pivot) e os filtros da tag (`:filters`). `null` = o bloco não
     * mostra linha nenhuma (pivot sem registro aberto).
     *
     * É o escopo da lista e também o das ações de item: editar ou remover só
     * alcança uma linha que esta consulta devolve — o número do item vem da
     * requisição.
     *
     * @return list<array{0:string,1:string,2:mixed}>|null
     */
    public static function scopeFilters(array $cfg): ?array
    {
        $flatMode = (($cfg['mode'] ?? 'pivot') === 'flat');
        if (!$flatMode && empty($cfg['recordId'])) {
            return null;
        }

        $filters = [];
        if (!$flatMode && !empty($cfg['foreignKey'])) {
            $filters[] = [$cfg['foreignKey'], '=', $cfg['recordId']];
        }

        // Extra filters [['col','op','val'], ...]
        if (!empty($cfg['filters']) && is_array($cfg['filters'])) {
            foreach ($cfg['filters'] as $f) {
                if (is_array($f) && count($f) >= 3) {
                    $filters[] = [$f[0], $f[1], $f[2]];
                }
            }
        }

        return $filters;
    }

    /**
     * As colunas que o escopo do bloco fixa (a chave do pai e as colunas dos
     * filtros): gravar outro valor nelas tiraria o item da lista — ou o
     * levaria para a lista de outro registro.
     *
     * @return list<string>
     */
    public static function scopeColumns(array $cfg): array
    {
        $columns = [];
        foreach (self::scopeFilters($cfg) ?? [] as $f) {
            $column = (string) $f[0];
            if ($column !== '') {
                // `tabela.coluna` num filtro: o que se grava é a coluna
                $columns[] = str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column;
            }
        }
        if (($cfg['mode'] ?? 'pivot') !== 'flat' && !empty($cfg['foreignKey'])) {
            $columns[] = (string) $cfg['foreignKey'];
        }

        return array_values(array_unique($columns));
    }

    /** Carrega as linhas do pivot — via QuerySource (Eloquent/Query Builder). */
    public static function loadItems(array $cfg): array
    {
        if (empty($cfg['pivotModel'])) {
            return [];
        }
        $filters = self::scopeFilters($cfg);
        if ($filters === null) {
            return [];
        }

        $orderBy  = $cfg['orderBy']  ?? 'id';
        $orderDir = $cfg['orderDir'] ?? 'asc';

        // Builder-native: :filters DSL → wheres no Query Builder.
        $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($cfg['pivotModel']);
        $__qb = $__m::query();
        QuerySource::applyArrayFilters($__qb, $filters);

        return QuerySource::recordsFromQuery($__qb, $orderBy . ' ' . $orderDir);
    }

    /** Loads via QuerySource (Eloquent), which manages its own connection. */
    public static function renderRow(array $cfg, $item): string
    {
        $state = self::encodeState($cfg);
        return MadBlade::render($cfg['rowView'], [
            'item'  => $item,
            'state' => $state,
            'name'  => $cfg['name'],
            // `vars`: valores livres declarados pelo preset (`:preset-vars`),
            // ex.: qual coluna é o corpo do comentário. Sem isso o template do
            // item não teria como saber os nomes de campo do caso concreto.
            'vars'  => $cfg['presetVars'] ?? [],
        ]);
    }

    /** Loads via QuerySource (Eloquent), which manages its own connection. */
    public static function renderList(array $cfg): string
    {
        $items = self::loadItems($cfg);
        if (empty($items)) {
            $empty = $cfg['emptyText'] ?? 'Nenhum item';
            return '<div class="mad-db-blocks-empty">' . htmlspecialchars($empty) . '</div>';
        }
        $html = '';
        foreach ($items as $item) {
            $html .= self::renderRow($cfg, $item);
        }
        return $html;
    }

    /**
     * Render a single row using an inline Blade template string (from <mad-db-blocks-row> slot).
     * Loads via QuerySource (Eloquent), which manages its own connection.
     */
    public static function renderRowInline(array $cfg, string $bladeTemplate, $item): string
    {
        $state = self::encodeState($cfg);
        return MadBlade::renderString($bladeTemplate, [
            'item'  => $item,
            'state' => $state,
            'name'  => $cfg['name'],
            'vars'  => $cfg['presetVars'] ?? [],
        ]);
    }

    /**
     * Render the full list using inline row template.
     * Loads via QuerySource (Eloquent), which manages its own connection.
     */
    public static function renderListInline(array $cfg, string $bladeTemplate): string
    {
        $items = self::loadItems($cfg);
        if (empty($items)) {
            $empty = $cfg['emptyText'] ?? 'Nenhum item';
            return '<div class="mad-db-blocks-empty">' . htmlspecialchars($empty) . '</div>';
        }
        $html = '';
        foreach ($items as $item) {
            $html .= self::renderRowInline($cfg, $bladeTemplate, $item);
        }
        return $html;
    }
}