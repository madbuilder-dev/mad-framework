<?php

namespace Mad\Service;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Mad\Form\ModelOptionsLoader;

/**
 * MadLocationResolver — resolução (e auto-criação opt-in) de cidade/estado a
 * partir da resposta dos lookups de CEP/CNPJ.
 *
 * Compartilhado por MadCepService e MadCnpjService: recebe o $dados da API +
 * o mapa `resolve` selado no token do componente (city/state/database) e injeta
 * os IDs resolvidos nos campos-alvo (default cidade_id / estado_id).
 *
 * Ordem: ESTADO primeiro (a cidade reaproveita o ID via city-state-fk); quando
 * só há city-model, o estado é back-fillado da FK da própria cidade.
 *
 * Escrita (auto-criação) — regras de segurança:
 *   - Só escreve com mapa de criação explícito no token OU com o fallback de
 *     projeto `config('mad.cep.auto_create')` ligado (e mesmo assim SÓ para
 *     lados com model declarado — flag sozinha não conjura model nenhum).
 *   - Transaction por conexão envolvida: falha na cidade não deixa estado órfão.
 *     Cidade e estado em conexões DIFERENTES não são 2PC: o commit da primeira
 *     pode vencer e o da segunda falhar — caso raro, documentado, não resolvido.
 *   - Dedupe em 3 camadas SEM vazar escopo: (1) lookup com todos os global
 *     scopes; (2) revival de soft-deleted (remove SÓ o scope de soft delete —
 *     tenant/unit continuam aplicados); (3) diagnóstico fora de escopo
 *     (withoutGlobalScopes) que NUNCA devolve a linha — só loga. Injetar o id
 *     de outro tenant renderia combo vazio e cross-linkaria dados.
 *   - Pré-flight NOT NULL por introspecção de schema: coluna obrigatória sem
 *     default e fora do mapa → NÃO insere e loga a lista de colunas (era o
 *     footgun que degradava em silêncio).
 *   - O preenchimento de endereço NUNCA quebra: apply() engole qualquer
 *     Throwable e devolve os erros estruturados; o service responde ok:true.
 *
 * Fallback por introspecção (project-level): com a flag ligada e mapa vazio, o
 * mapa de criação é montado do schema real (nome/sigla/uf reconhecíveis) — o
 * atalho cego ['nome' => 'cidade'] nunca é usado no fallback, justamente porque
 * ele morre em qualquer NOT NULL extra. A introspecção é memoizada por
 * conexão|tabela SÓ durante o request (sem Cache:: — schema cacheado é bug de
 * invalidação esperando a próxima migration).
 *
 * `database` (token): override da conexão declarada no model — aplicado no
 * lookup, na criação e na transaction.
 *
 * Erros estruturados (codes estáveis, prefixo de log `mad.location.`):
 *   model_unresolved, not_found_readonly, create_missing_required,
 *   create_unique_conflict, create_map_unresolvable, exists_out_of_scope,
 *   revived (info), introspection_failed, resolve_failed.
 * publicErrors() reduz cada entrada a {code, side} — contexto completo
 * (tabela/colunas/mensagem SQL) só com app.debug, para não vazar schema pro
 * browser do usuário final.
 */
final class MadLocationResolver
{
    /** @var array<int, array{code:string, side:string, ctx:array}> */
    private array $errors = [];

    /**
     * Escritas-alvo bufferizadas: só chegam ao $dados depois do commit.
     * Em rollback, entradas cuja linha foi CRIADA na transaction são descartadas
     * (id inválido); linhas pré-existentes continuam válidas e são aplicadas.
     *
     * @var array<int, array{field:string, value:mixed, created:bool, label:?string}>
     */
    private array $pending = [];

    /**
     * Rótulo humano da linha resolvida, por campo-alvo ('cidade_id' => 'Belo
     * Horizonte'). O combo do form recebe o ID, e uma cidade recém-criada (ou
     * qualquer id fora da lista já carregada) não tem <option> correspondente —
     * sem o rótulo o widget cai no próprio valor e a tela mostra "2" no lugar
     * do nome. Preenchido no flush, pelas mesmas regras do valor.
     *
     * @var array<string, string>
     */
    private array $labels = [];

    /**
     * Memo de introspecção por INSTÂNCIA (= por request; o service cria um
     * resolver por chamada). Nunca static: sob Octane o worker sobrevive entre
     * requests e um schema cacheado atravessaria migrations.
     * "conexão|tabela" => colunas|null (null = introspecção falhou).
     */
    private array $schemaMemo = [];

    /** Falhas de introspecção já notadas (evita nota dupla pré-pass/pré-flight). */
    private array $schemaNoted = [];

    public static function make(): self
    {
        return new self();
    }

    /**
     * Resolve/cria cidade e estado conforme $resolve e injeta os alvos em $dados.
     * Retorna os erros estruturados coletados (vazio em sucesso total).
     *
     * @param array $resolve ['city' => cfg, 'state' => cfg, 'database' => string]
     *   cfg: model, match_col, api_field, key, target, state_fk (city),
     *        scope_by_state (city), create (mapa modelColumn => apiField)
     */
    public function apply(\stdClass $dados, array $resolve): array
    {
        $this->errors  = [];
        $this->pending = [];
        $this->labels  = [];

        try {
            $this->run($dados, $resolve);
        } catch (\Throwable $e) {
            // Rede final: nada aqui pode derrubar o preenchimento de endereço.
            $this->note('resolve_failed', ['message' => $e->getMessage()], 'warning', '');
        }

        return $this->errors;
    }

    /**
     * Rótulos das linhas resolvidas, por campo-alvo da API ('cidade_id' =>
     * 'Belo Horizonte'). Válido depois de apply(); vazio quando nada resolveu
     * ou quando não deu pra deduzir a coluna de nome. O service traduz as
     * chaves para os campos do FORM (via fill-fields) antes de mandar ao JS.
     *
     * @return array<string, string>
     */
    public function targetLabels(): array
    {
        return $this->labels;
    }

    /**
     * Traduz os rótulos (chaveados pelo campo-alvo da API) para os campos do
     * FORM, via fill-fields — mesmo mapeamento do cascadeTargets. Um alvo
     * mapeado em dois campos rotula os dois.
     *
     * @param array $fillFields ['form_field' => 'api_field', ...]
     * @param array $targetLabels ['cidade_id' => 'Belo Horizonte', ...]
     * @return array<string, string>
     */
    public static function formLabels(array $fillFields, array $targetLabels): array
    {
        if ($targetLabels === []) {
            return [];
        }

        $out = [];
        foreach ($fillFields as $formField => $apiField) {
            $key = (string) $apiField;
            if (isset($targetLabels[$key])) {
                $out[(string) $formField] = (string) $targetLabels[$key];
            }
        }
        return $out;
    }

    /**
     * Campos do FORM que recebem o id da cidade (target) — o JS usa isso para
     * atrasar o set até o cascade estado→cidade recarregar o combo, em vez de
     * adivinhar por regex /cidade/i no name do campo.
     */
    public static function cascadeTargets(array $fillFields, array $resolve): array
    {
        $cityTarget = (string) ($resolve['city']['target'] ?? 'cidade_id');
        $out = [];
        foreach ($fillFields as $formField => $apiField) {
            if ((string) $apiField === $cityTarget) {
                $out[] = (string) $formField;
            }
        }
        return $out;
    }

    /**
     * Versão pública dos erros: sempre {code, side}; contexto completo só com
     * app.debug (schema/SQL não vazam pro browser em produção).
     */
    public static function publicErrors(array $errors): array
    {
        $debug = (bool) config('app.debug');
        return array_map(static function (array $e) use ($debug) {
            $out = ['code' => $e['code'], 'side' => $e['side']];
            return $debug ? array_merge($e['ctx'], $out) : $out;
        }, $errors);
    }

    /**
     * Navega um campo da resposta da API. Suporta "foo->bar->baz" e "foo.bar.baz".
     * (Era o _resolve privado duplicado em MadCepService/MadCnpjService.)
     */
    public static function path($obj, string $path)
    {
        if ($obj === null || $path === '') return '';
        $parts = preg_split('/->|\./', $path);
        $cur = $obj;
        foreach ($parts as $part) {
            if (is_object($cur) && isset($cur->{$part})) {
                $cur = $cur->{$part};
            } elseif (is_array($cur) && array_key_exists($part, $cur)) {
                $cur = $cur[$part];
            } else {
                return '';
            }
        }
        if (is_scalar($cur) || $cur === null) return $cur;
        return '';
    }

    // ------------------------------------------------------------------
    // pipeline
    // ------------------------------------------------------------------

    private function run(\stdClass $dados, array $resolve): void
    {
        $db       = trim((string) ($resolve['database'] ?? ''));
        $stateCfg = (!empty($resolve['state']) && is_array($resolve['state'])) ? $resolve['state'] : null;
        $cityCfg  = (!empty($resolve['city'])  && is_array($resolve['city']))  ? $resolve['city']  : null;

        if ($stateCfg === null && $cityCfg === null) {
            return; // flag de projeto sozinha não conjura model nenhum
        }

        $stateProto = $stateCfg ? $this->prototype($stateCfg, 'state', $db) : null;
        $cityProto  = $cityCfg  ? $this->prototype($cityCfg, 'city', $db)  : null;

        $mayWrite = ($stateProto && $this->createMapFor($stateCfg, $stateProto, 'state', false) !== [])
            || ($cityProto && $this->createMapFor($cityCfg, $cityProto, 'city', false) !== []);

        $connections = [];
        if ($mayWrite) {
            foreach ([$stateProto, $cityProto] as $proto) {
                if ($proto) {
                    $conn = $proto->getConnection();
                    $connections[$conn->getName()] = $conn;
                }
            }
        }

        $started = [];
        try {
            foreach ($connections as $conn) {
                $conn->beginTransaction();
                $started[] = $conn;
            }

            $this->resolveSides($dados, $stateCfg, $stateProto, $cityCfg, $cityProto);

            foreach ($started as $conn) {
                $conn->commit();
            }
            $this->flushPending($dados, false);
        } catch (\Throwable $e) {
            foreach (array_reverse($started) as $conn) {
                try {
                    $conn->rollBack();
                } catch (\Throwable) {
                    // conexão já fechada/rollback duplo — segue pras demais
                }
            }
            $this->flushPending($dados, true);
            $this->note('resolve_failed', ['message' => $e->getMessage()], 'warning', '');
        }
    }

    private function resolveSides(\stdClass $dados, ?array $stateCfg, $stateProto, ?array $cityCfg, $cityProto): void
    {
        // Estado primeiro — a cidade pode reaproveitar o ID resolvido.
        $stateRec     = null;
        $stateCreated = false;
        $stateId      = null;

        if ($stateCfg && $stateProto) {
            [$stateRec, $stateCreated] = $this->resolveSide($dados, $stateCfg, $stateProto, 'state', []);
            if ($stateRec) {
                $key     = (string) ($stateCfg['key'] ?? 'id');
                $stateId = $stateRec->{$key} ?? null;
                $this->buffer(
                    (string) ($stateCfg['target'] ?? 'estado_id'),
                    $stateId,
                    $stateCreated,
                    $this->labelFor($stateRec, $stateCfg),
                );
            }
        }

        if ($cityCfg && $cityProto) {
            $stateFk       = (string) ($cityCfg['state_fk'] ?? '');
            $extraOnCreate = ($stateFk !== '' && $stateId !== null) ? [$stateFk => $stateId] : [];

            $extraWhere = [];
            if (!empty($cityCfg['scope_by_state']) && $stateFk !== '' && $stateId !== null) {
                // city-scope-by-state: torna match por `nome` seguro (o Brasil
                // tem dezenas de cidades homônimas em estados diferentes).
                $extraWhere[$stateFk] = $stateId;
            }

            [$cityRec, $cityCreated] = $this->resolveSide($dados, $cityCfg, $cityProto, 'city', $extraWhere, $extraOnCreate);
            if ($cityRec) {
                $key = (string) ($cityCfg['key'] ?? 'id');
                $this->buffer(
                    (string) ($cityCfg['target'] ?? 'cidade_id'),
                    $cityRec->{$key} ?? null,
                    $cityCreated,
                    $this->labelFor($cityRec, $cityCfg),
                );

                // Back-fill do estado a partir da FK da cidade quando não houve
                // state-model próprio (comportamento histórico, load-bearing).
                // Sem rótulo: aqui só existe o ID da FK, não a linha do estado.
                if ($stateFk !== '' && $stateRec === null && isset($cityRec->{$stateFk})) {
                    $stateTarget = (string) ($stateCfg['target'] ?? 'estado_id');
                    $this->buffer($stateTarget, $cityRec->{$stateFk}, $cityCreated);
                }
            }
        }
    }

    /**
     * Resolve um lado (estado ou cidade): lookup scoped → revival → diagnóstico
     * → pré-flight → create. Retorna [registro|null, criadoNestaTransaction].
     *
     * @return array{0: ?object, 1: bool}
     */
    private function resolveSide(\stdClass $dados, array $cfg, $proto, string $side, array $extraWhere, array $extraOnCreate = []): array
    {
        $matchCol = (string) ($cfg['match_col'] ?? 'codigo_ibge');
        $apiField = (string) ($cfg['api_field'] ?? '');
        $value    = self::path($dados, $apiField);

        if ($matchCol === '' || $value === '' || $value === null) {
            return [null, false];
        }

        $rec = $this->findScoped($proto, $matchCol, $value, $extraWhere);
        if ($rec) {
            return [$rec, false];
        }

        $create = $this->createMapFor($cfg, $proto, $side, true);
        if ($create === []) {
            // Condição esperada no modo read-only (sem auto-criação) — info, não
            // warning, senão todo CEP de cidade não cadastrada polui o log.
            $this->note('not_found_readonly', [
                'model' => (string) ($cfg['model'] ?? ''), 'match_col' => $matchCol, 'value' => $value,
            ], 'info', $side);
            return [null, false];
        }

        $rec = $this->findRevivable($proto, $matchCol, $value, $extraWhere);
        if ($rec) {
            $this->revive($rec);
            $this->note('revived', [
                'model' => (string) ($cfg['model'] ?? ''), 'match_col' => $matchCol, 'value' => $value,
            ], 'info', $side);
            return [$rec, false];
        }

        // Diagnóstico: a linha existe fora do escopo atual (outro tenant/unit ou
        // scope custom do app)? NUNCA devolve — id de outro escopo cross-linka
        // dados e renderia combo vazio. Só loga; a criação scoped segue.
        $ghost = $this->findAnywhere($proto, $matchCol, $value, $extraWhere);
        if ($ghost) {
            $this->note('exists_out_of_scope', [
                'model' => (string) ($cfg['model'] ?? ''), 'match_col' => $matchCol, 'value' => $value,
                'scopes' => array_keys($proto->getGlobalScopes()),
            ], 'warning', $side);
        }

        $willSet = array_merge([$matchCol], array_keys($create), array_keys($extraOnCreate));
        $missing = $this->missingRequiredColumns($proto, $willSet, $side);
        if ($missing !== []) {
            $this->note('create_missing_required', [
                'model' => (string) ($cfg['model'] ?? ''), 'table' => $proto->getTable(), 'columns' => $missing,
            ], 'warning', $side);
            return [null, false];
        }

        $fqcn = get_class($proto);
        $obj  = new $fqcn();
        $obj->setConnection($proto->getConnectionName());
        // Garante a coluna de match no novo registro (idempotência em buscas futuras).
        $obj->{$matchCol} = $value;
        foreach ($create as $col => $af) {
            $col = (string) $col;
            if ($col === '' || $col === $matchCol) {
                continue;
            }
            $obj->{$col} = self::path($dados, (string) $af);
        }
        foreach ($extraOnCreate as $col => $val) {
            $col = (string) $col;
            if ($col !== '') {
                $obj->{$col} = $val;
            }
        }

        try {
            $obj->save();
        } catch (QueryException $e) {
            // SÓ violação de unicidade degrada este lado (UNIQUE global numa
            // tabela por-tenant, corrida entre requests) — o estado já resolvido
            // continua válido. Qualquer OUTRA falha de SQL (NOT NULL que o
            // pré-flight não viu, tabela ausente) propaga pra transaction fazer
            // rollback — é o que impede o estado órfão.
            if (!$this->isUniqueViolation($e)) {
                throw $e;
            }
            $this->note('create_unique_conflict', [
                'model' => (string) ($cfg['model'] ?? ''), 'table' => $proto->getTable(),
                'match_col' => $matchCol, 'value' => $value, 'message' => $e->getMessage(),
            ], 'warning', $side);
            return [null, false];
        }

        return [$obj, true];
    }

    /**
     * Violação de unicidade cross-driver, por mensagem (o SQLSTATE 23000 não
     * separa UNIQUE de NOT NULL): sqlite "UNIQUE constraint failed", mysql
     * "Duplicate entry", pgsql "duplicate key value violates unique
     * constraint", sqlsrv "Violation of UNIQUE KEY" / "Cannot insert duplicate".
     */
    private function isUniqueViolation(QueryException $e): bool
    {
        $msg = strtolower($e->getMessage());
        return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
    }

    // ------------------------------------------------------------------
    // lookups (3 camadas)
    // ------------------------------------------------------------------

    /** Lookup com TODOS os global scopes do model (tenant/unit/soft delete/custom). */
    private function findScoped($proto, string $col, $value, array $extraWhere)
    {
        $q = $proto->newQuery()->where($col, '=', $value);
        foreach ($extraWhere as $c => $v) {
            $q->where($c, '=', $v);
        }
        return $q->first();
    }

    /**
     * Lookup que enxerga soft-deleted para revival — remove SÓ o scope de soft
     * delete (MAD e nativo do Laravel). Tenant/unit/custom continuam aplicados:
     * linha soft-deleted de OUTRO tenant permanece invisível, que é o correto.
     */
    private function findRevivable($proto, string $col, $value, array $extraWhere)
    {
        $q = $proto->newQuery()
            ->withoutGlobalScope('madSoftDelete')
            ->withoutGlobalScope(\Illuminate\Database\Eloquent\SoftDeletingScope::class)
            ->where($col, '=', $value);
        foreach ($extraWhere as $c => $v) {
            $q->where($c, '=', $v);
        }
        return $q->first();
    }

    /** Diagnóstico puro (withoutGlobalScopes) — o retorno NUNCA vira resultado. */
    private function findAnywhere($proto, string $col, $value, array $extraWhere)
    {
        try {
            $q = $proto->newQuery()->withoutGlobalScopes()->where($col, '=', $value);
            foreach ($extraWhere as $c => $v) {
                $q->where($c, '=', $v);
            }
            return $q->first();
        } catch (\Throwable) {
            return null; // diagnóstico não pode derrubar o fluxo
        }
    }

    /**
     * Revive um registro soft-deleted: limpa deleted_at E as colunas de autoria
     * do delete (deleted_by / deleted_by_user_id) quando existirem — restore()
     * do trait só limpa o deleted_at e deixaria autoria órfã.
     */
    private function revive($rec): void
    {
        $set = [];
        foreach (['getDeletedAtColumn', 'getDeletedByColumn', 'getDeletedByUserIdColumn'] as $getter) {
            if (method_exists($rec, $getter)) {
                $col = $rec->{$getter}();
                if ($col) {
                    $set[$col] = null;
                }
            }
        }
        if ($set === []) {
            return; // sem soft delete configurado — nada a reviver
        }
        foreach ($set as $col => $v) {
            $rec->{$col} = $v;
        }
        $rec->save();
    }

    // ------------------------------------------------------------------
    // create map / pré-flight
    // ------------------------------------------------------------------

    /**
     * Mapa de criação efetivo do lado: o explícito do token SEMPRE vence; vazio
     * + flag de projeto ligada → monta por introspecção. $noting=false é usado
     * só pra decidir se a transaction é necessária (sem poluir os erros).
     */
    private function createMapFor(array $cfg, $proto, string $side, bool $noting): array
    {
        $explicit = (!empty($cfg['create']) && is_array($cfg['create'])) ? $cfg['create'] : [];
        if ($explicit !== []) {
            return $explicit;
        }
        if (!config('mad.cep.auto_create', false)) {
            return [];
        }
        return $this->introspectedCreateMap($cfg, $proto, $side, $noting);
    }

    /**
     * Fallback do project-level: monta o mapa só com colunas de semântica
     * conhecida que EXISTEM no schema real. Sem coluna de nome reconhecível,
     * não inventa insert — loga create_map_unresolvable e fica read-only.
     */
    private function introspectedCreateMap(array $cfg, $proto, string $side, bool $noting): array
    {
        $columns = $this->schemaColumns($proto, $side, $noting);
        if ($columns === null) {
            return [];
        }

        $names = [];
        foreach ($columns as $c) {
            $names[strtolower((string) ($c['name'] ?? ''))] = (string) ($c['name'] ?? '');
        }

        $matchCol = strtolower((string) ($cfg['match_col'] ?? 'codigo_ibge'));
        $pk       = strtolower((string) $proto->getKeyName());

        $groups = $side === 'state'
            ? [['cols' => ['nome', 'name', 'descricao', 'titulo'], 'api' => 'estado'],
               ['cols' => ['sigla', 'uf', 'abreviacao', 'abbr'],   'api' => 'uf']]
            : [['cols' => ['nome', 'name', 'municipio', 'descricao'], 'api' => 'cidade'],
               ['cols' => ['uf', 'sigla', 'estado_sigla'],            'api' => 'uf']];

        $map = [];
        foreach ($groups as $g) {
            foreach ($g['cols'] as $cand) {
                if (isset($names[$cand]) && $cand !== $matchCol && $cand !== $pk) {
                    $map[$names[$cand]] = $g['api'];
                    break;
                }
            }
        }

        // O primeiro grupo é sempre o NOME — sem ele o registro seria só um
        // código IBGE órfão. Melhor log acionável do que linha-lixo.
        $nameGroup = $side === 'state' ? ['nome', 'name', 'descricao', 'titulo'] : ['nome', 'name', 'municipio', 'descricao'];
        $hasName = false;
        foreach ($nameGroup as $cand) {
            if (isset($map[$names[$cand] ?? ''])) {
                $hasName = true;
                break;
            }
        }
        if (!$hasName) {
            if ($noting) {
                $this->note('create_map_unresolvable', [
                    'model' => (string) ($cfg['model'] ?? ''), 'table' => $proto->getTable(),
                    'looked_for' => $nameGroup,
                ], 'warning', $side);
            }
            return [];
        }

        return $map;
    }

    /**
     * Pré-flight NOT NULL: colunas obrigatórias (sem default, não auto-inc, não
     * geradas) que NÃO serão preenchidas pelo insert. Exclui PK, colunas
     * stampadas por trait (tenant/unit), timestamps e colunas de auditoria MAD.
     * Introspecção indisponível → lista vazia (tenta o insert como hoje).
     */
    private function missingRequiredColumns($proto, array $willSet, string $side): array
    {
        $columns = $this->schemaColumns($proto, $side, true);
        if ($columns === null) {
            return [];
        }

        $covered = [strtolower((string) $proto->getKeyName()) => true];
        foreach ($willSet as $col) {
            $covered[strtolower((string) $col)] = true;
        }

        $traits = class_uses_recursive($proto);
        if (in_array(\Mad\Database\Concerns\BelongsToTenant::class, $traits, true)) {
            $covered['tenant_id'] = true; // stampado no creating()
        }
        if (in_array(\Mad\Database\Concerns\BelongsToUnit::class, $traits, true)) {
            $covered['unit_id'] = true; // stampado no creating()
        }

        // Timestamps nativos + colunas de auditoria/soft delete MAD (stampadas
        // por evento). getCreatedAtColumn existe no Model base — cobre os dois.
        foreach (['getCreatedAtColumn', 'getUpdatedAtColumn', 'getCreatedByColumn',
                  'getCreatedByUserIdColumn', 'getCreatedByUnitIdColumn',
                  'getDeletedAtColumn', 'getDeletedByColumn', 'getDeletedByUserIdColumn'] as $getter) {
            if (method_exists($proto, $getter)) {
                try {
                    $col = $proto->{$getter}();
                } catch (\Throwable) {
                    $col = null;
                }
                if ($col) {
                    $covered[strtolower((string) $col)] = true;
                }
            }
        }

        $missing = [];
        foreach ($columns as $c) {
            $name = strtolower((string) ($c['name'] ?? ''));
            if ($name === '' || isset($covered[$name])) {
                continue;
            }
            $nullable = (bool) ($c['nullable'] ?? true);
            $default  = $c['default'] ?? null;
            $autoInc  = (bool) ($c['auto_increment'] ?? false);
            $generated = !empty($c['generation']);
            if (!$nullable && $default === null && !$autoInc && !$generated) {
                $missing[] = (string) $c['name'];
            }
        }

        return $missing;
    }

    /**
     * getColumns() do schema, memoizado por "conexão|tabela" no request.
     * null = introspecção falhou (driver sem suporte etc.) — quem chama decide
     * degradar pro comportamento antigo.
     */
    private function schemaColumns($proto, string $side, bool $noting): ?array
    {
        $key = $proto->getConnectionName() . '|' . $proto->getTable();

        if (!array_key_exists($key, $this->schemaMemo)) {
            try {
                $this->schemaMemo[$key] = $proto->getConnection()->getSchemaBuilder()->getColumns($proto->getTable());
            } catch (\Throwable $e) {
                $this->schemaMemo[$key] = null;
                $this->schemaNoted[$key] = $e->getMessage();
            }
        }

        // A falha pode ter sido memoizada num pré-pass sem noting — nota na
        // primeira chamada que PODE notar, uma única vez por tabela.
        if ($this->schemaMemo[$key] === null && $noting && isset($this->schemaNoted[$key])) {
            $this->note('introspection_failed', [
                'table' => $proto->getTable(), 'message' => $this->schemaNoted[$key],
            ], 'warning', $side);
            unset($this->schemaNoted[$key]);
        }

        return $this->schemaMemo[$key];
    }

    // ------------------------------------------------------------------
    // infra
    // ------------------------------------------------------------------

    /** Instancia o prototype do model do lado, aplicando o override de conexão. */
    private function prototype(array $cfg, string $side, string $db)
    {
        $model = trim((string) ($cfg['model'] ?? ''));
        if ($model === '') {
            return null;
        }

        try {
            $fqcn = ModelOptionsLoader::resolveModelClass($model);
        } catch (\Throwable $e) {
            $this->note('model_unresolved', ['model' => $model, 'message' => $e->getMessage()], 'warning', $side);
            return null;
        }

        $proto = new $fqcn();
        if ($db !== '') {
            $proto->setConnection($db);
        }
        return $proto;
    }

    private function buffer(string $field, $value, bool $created, ?string $label = null): void
    {
        if ($field === '') {
            return;
        }
        $this->pending[] = ['field' => $field, 'value' => $value, 'created' => $created, 'label' => $label];
    }

    /**
     * Aplica as escritas-alvo bufferizadas. Pós-rollback, ids de linhas criadas
     * na transaction são inválidos e caem; linhas pré-existentes permanecem.
     * O rótulo segue o valor: entrada descartada não deixa label órfão.
     */
    private function flushPending(\stdClass $dados, bool $rolledBack): void
    {
        foreach ($this->pending as $p) {
            if ($rolledBack && $p['created']) {
                continue;
            }
            $dados->{$p['field']} = $p['value'];

            $label = $p['label'] ?? null;
            if ($label !== null && $label !== '' && $p['value'] !== null && $p['value'] !== '') {
                $this->labels[$p['field']] = $label;
            }
        }
        $this->pending = [];
    }

    /**
     * Coluna que serve de rótulo humano da linha resolvida.
     *
     * Ordem: (1) a coluna que o mapa de criação liga ao campo TEXTUAL da API
     * (`['nome' => 'cidade']` → `nome`) — é o nome que o próprio usuário
     * declarou; (2) nomes convencionais; (3) a coluna de match, que ajuda
     * quando o match é por nome/sigla.
     *
     * Nunca devolve a chave nem código IBGE: "3550308" no combo é tão ruim
     * quanto o id. Sem candidata, devolve null e o JS mantém o que já fazia
     * (ficar sem rótulo é melhor que anunciar um número como se fosse nome).
     */
    private function labelFor($rec, array $cfg): ?string
    {
        if (!$rec) {
            return null;
        }

        $textApiFields = ['cidade', 'municipio', 'estado', 'nome'];
        $candidates    = [];

        foreach ((array) ($cfg['create'] ?? []) as $col => $apiField) {
            if (in_array((string) $apiField, $textApiFields, true)) {
                $candidates[] = (string) $col;
            }
        }
        foreach (['nome', 'name', 'descricao', 'description', 'municipio', 'titulo', 'title'] as $col) {
            $candidates[] = $col;
        }
        $candidates[] = (string) ($cfg['match_col'] ?? '');

        $key = (string) ($cfg['key'] ?? 'id');
        foreach ($candidates as $col) {
            if ($col === '' || $col === $key || preg_match('/^(id|.*cod(igo)?_ibge|ibge)$/i', $col)) {
                continue;
            }
            $val = $rec->{$col} ?? null;
            if (is_scalar($val) && trim((string) $val) !== '') {
                return (string) $val;
            }
        }
        return null;
    }

    private function note(string $code, array $ctx, string $level, string $side): void
    {
        $this->errors[] = ['code' => $code, 'side' => $side, 'ctx' => $ctx];

        $logCtx = array_merge($ctx, $side !== '' ? ['side' => $side] : []);
        if ($level === 'info') {
            Log::info('mad.location.' . $code, $logCtx);
        } else {
            Log::warning('mad.location.' . $code, $logCtx);
        }
    }
}
