<?php

namespace Mad\Mcp;

/**
 * McpManifest
 *
 * Value-object tipado sobre o `mcp.config.json` (manifest canonico emitido pelo
 * configurador madbuilder v5). Apenas leitura — o runtime consome, nao edita.
 *
 * Shape: ver "Contrato de Runtime" secao 2.
 */
final class McpManifest
{
    /** @param array<string,mixed> $data */
    public function __construct(private array $data)
    {
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    public function version(): string
    {
        return (string) ($this->data['version'] ?? '0.0.0');
    }

    /** @return array<string,mixed> */
    public function system(): array
    {
        return (array) ($this->data['system'] ?? []);
    }

    public function systemValue(string $key, string $default = ''): string
    {
        return (string) ($this->system()[$key] ?? $default);
    }

    public function endpoint(): string
    {
        return (string) ($this->data['endpoint'] ?? '');
    }

    /**
     * Conexao de banco que o motor abre p/ executar as tools.
     * Top-level "database" do manifest; fallback config [mcp] database;
     * fallback conexao principal do app (mad.general.main_database — a chave
     * mora em `general`; `mad.main_database` nao existe e o fallback caia na
     * conexao default do Laravel, que nao e a do negocio).
     */
    public function database(): string
    {
        if (! empty($this->data['database'])) {
            return (string) $this->data['database'];
        }

        $ini = \Mad\Core\AppConfig::get();
        if (! empty($ini['mcp']['database'])) {
            return (string) $ini['mcp']['database'];
        }

        return (string) (config('mad.main_database')
            ?: ($ini['general']['main_database'] ?? null)
            ?: config('database.default', 'default'));
    }

    /** @return array<string,mixed> */
    public function auth(): array
    {
        return (array) ($this->data['auth'] ?? []);
    }

    public function tokenPrefix(): string
    {
        return (string) ($this->auth()['token_prefix'] ?? 'mcp_');
    }

    public function authStrategy(): string
    {
        return (string) ($this->auth()['strategy'] ?? 'mapped_to_system_user');
    }

    /** @return array<int,array<string,mixed>> */
    public function namespaces(): array
    {
        return (array) ($this->data['namespaces'] ?? []);
    }

    /**
     * Todas as tools achatadas, com o namespace anexado em cada uma.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tools(): array
    {
        $out = [];
        foreach ($this->namespaces() as $ns) {
            $nsId    = (string) ($ns['ns'] ?? '');
            $nsLabel = (string) ($ns['label'] ?? $nsId);
            foreach ((array) ($ns['tools'] ?? []) as $tool) {
                $tool['ns']       = $nsId;
                $tool['ns_label'] = $nsLabel;
                $out[] = $tool;
            }
        }

        return $out;
    }

    /** @return array<string,mixed>|null */
    public function tool(string $id): ?array
    {
        foreach ($this->tools() as $tool) {
            if (($tool['id'] ?? null) === $id) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Campos (colunas) declarados para uma tabela.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fieldsFor(string $table): array
    {
        return (array) (($this->data['fields'] ?? [])[$table] ?? []);
    }

    /**
     * Campos expostos (expose=true) de uma tabela.
     *
     * @return array<int,array<string,mixed>>
     */
    public function exposedFieldsFor(string $table): array
    {
        return array_values(array_filter(
            $this->fieldsFor($table),
            static fn ($f): bool => (bool) ($f['expose'] ?? false)
        ));
    }

    /** @return array<int,string> nomes das colunas PII de uma tabela */
    public function piiColumnsFor(string $table): array
    {
        $cols = [];
        foreach ($this->fieldsFor($table) as $f) {
            if (! empty($f['pii'])) {
                $cols[] = (string) $f['name'];
            }
        }

        return $cols;
    }

    /** @return array<int,string> colunas que podem aparecer no retorno (expose=true) */
    public function exposedColumnNamesFor(string $table): array
    {
        return array_map(
            static fn ($f): string => (string) $f['name'],
            $this->exposedFieldsFor($table)
        );
    }

    public function primaryKeyFor(string $table): string
    {
        foreach ($this->fieldsFor($table) as $f) {
            if (! empty($f['pk'])) {
                return (string) $f['name'];
            }
        }

        return 'id';
    }

    /** @return array<string,mixed> matriz de permissoes por profile_id (mad_iam_group) */
    public function permissions(): array
    {
        return (array) ($this->data['permissions'] ?? []);
    }

    /**
     * Perfis da matriz (`profiles` do manifest): id do perfil no Studio →
     * {name, code}. A plataforma emite desde o Copilot com permissões: o id é
     * o do `PermissionGroup` do projeto, que NÃO é o id do grupo no app (o
     * seeder cria o grupo pelo NOME). Sem o bloco (manifest escrito à mão ou
     * antigo) as chaves de `permissions` são comparadas com os ids crus dos
     * grupos do app, como sempre foram. Ver {@see McpProfiles}.
     *
     * @return array<string, array{name: string, code: string}>
     */
    public function profiles(): array
    {
        $out = [];
        foreach ((array) ($this->data['profiles'] ?? []) as $id => $p) {
            if (! is_array($p)) {
                continue;
            }
            $out[(string) $id] = [
                'name' => trim((string) ($p['name'] ?? '')),
                'code' => trim((string) ($p['code'] ?? '')),
            ];
        }

        return $out;
    }

    public function hasProfiles(): bool
    {
        return is_array($this->data['profiles'] ?? null);
    }

    /**
     * Tools de uma entidade (tabela), achatadas — crud de todos os verbos e as
     * queries salvas cuja base é ela.
     *
     * @return array<int,array<string,mixed>>
     */
    public function toolsForEntity(string $table): array
    {
        $table = strtolower($table);

        return array_values(array_filter(
            $this->tools(),
            static fn (array $t): bool => strtolower((string) ($t['entity'] ?? '')) === $table
        ));
    }

    /**
     * Decisao da matriz para (profile_id, tool_id): 'allow' | 'deny' | null (inherit).
     */
    public function matrixDecision(string $profileId, string $toolId): ?string
    {
        $perm = $this->permissions()[$profileId][$toolId] ?? null;

        return $perm !== null ? (string) $perm : null;
    }

    /**
     * Bloco de row-scope de uma entidade (do `scope` do manifest, emitido pelo
     * gerador a partir do schema REAL do app gerado). Framework-generico: cada
     * tabela declara as colunas de escopo que POSSUI.
     * shape: scope[<table>] = {
     *   owner_column?: 'created_by_user_id',   // existe coluna de dono(usuario)?
     *   unit_column?:  'created_by_unit_id',   // existe coluna de unidade?
     *   exempt?: bool,                          // tabela de lookup/referencia (sem dono)
     *   allow_coarsen?: bool,                   // nivel 'own' pode cair p/ unidade se so houver unit_column
     *   soft_delete?: 'deleted_at'
     * }
     *
     * @return array<string,mixed>|null null = sem bloco declarado
     */
    public function scopeBlock(string $table): ?array
    {
        $block = (($this->data['scope'] ?? [])[$table] ?? null);

        return is_array($block) ? $block : null;
    }

    public function hasScopeBlock(string $table): bool
    {
        return $this->scopeBlock($table) !== null;
    }

    /** Coluna de DONO (usuario) da entidade, ou null se a tabela nao tem. */
    public function ownerColumnFor(string $table): ?string
    {
        $c = $this->scopeBlock($table)['owner_column'] ?? null;

        return is_string($c) && $c !== '' ? $c : null;
    }

    /** Coluna de UNIDADE da entidade, ou null se a tabela nao tem. */
    public function unitColumnFor(string $table): ?string
    {
        $c = $this->scopeBlock($table)['unit_column'] ?? null;

        return is_string($c) && $c !== '' ? $c : null;
    }

    /** Entidade isenta de row-scope (lookup/referencia). So via exempt===true explicito. */
    public function isScopeExempt(string $table): bool
    {
        return (bool) (($this->scopeBlock($table) ?? [])['exempt'] ?? false);
    }

    /** Nivel 'own' pode degradar p/ unidade quando a tabela so tem unit_column. */
    public function allowsCoarsen(string $table): bool
    {
        return (bool) (($this->scopeBlock($table) ?? [])['allow_coarsen'] ?? false);
    }

    /** Coluna de soft-delete (p/ reaplicar no path raw do agente). null se nao houver. */
    public function softDeleteColumnFor(string $table): ?string
    {
        $c = ($this->scopeBlock($table) ?? [])['soft_delete'] ?? null;

        return is_string($c) && $c !== '' ? $c : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function glossary(): array
    {
        return (array) ($this->data['glossary'] ?? []);
    }

    /** @return array<int,array<string,mixed>> */
    public function fewshot(): array
    {
        return (array) ($this->data['fewshot'] ?? []);
    }

    public function instructions(): string
    {
        return $this->systemValue('instructions');
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
