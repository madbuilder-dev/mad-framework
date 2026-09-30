<?php

namespace Mad\Mcp;

use Laravel\Mcp\Server\Tool;

/**
 * McpManifestTool
 *
 * Base das tools geradas dinamicamente a partir do manifest. UMA instancia por
 * tool do manifest (o laravel/mcp aceita instancias no $tools, nao so
 * class-strings — ver ServerContext::resolvePrimitives).
 *
 * Cada instancia carrega seu `spec` (do manifest) e implementa name/description/
 * annotations/shouldRegister aqui; as subclasses (crud/query/custom) implementam
 * schema()/handle().
 */
abstract class McpManifestTool extends Tool
{
    /** @param array<string,mixed> $spec */
    public function __construct(
        protected McpManifest $manifest,
        protected array $spec
    ) {
    }

    public function name(): string
    {
        return (string) ($this->spec['id'] ?? parent::name());
    }

    public function description(): string
    {
        return (string) ($this->spec['description'] ?? '');
    }

    /**
     * Filtro por requisicao: piso de permissao legado + matriz do manifest (Contrato 6).
     * Chamado por Primitive::eligibleForRegistration() em tools/list e tools/call.
     */
    public function shouldRegister(): bool
    {
        return (new McpPermissionResolver())->canUseTool($this->manifest, $this->spec);
    }

    /** Sem atributos PHP — annotations vem do spec. Override nas subclasses. */
    public function annotations(): array
    {
        return [];
    }

    protected function entity(): string
    {
        return (string) ($this->spec['entity'] ?? '');
    }

    protected function db(): string
    {
        return $this->manifest->database();
    }

    /**
     * FURO #1: UNICO ponto de construcao de gateway. Quando row-scope esta ligado,
     * SEMPRE devolve um McpScopedGateway ja carregado com o WHERE forcado da
     * entidade — assim TODO tool (crud/query) e escopado por construcao, sem
     * depender de cada handler lembrar de aplicar o filtro. Nenhum outro arquivo
     * pode instanciar McpTableGateway (ver McpGatewayArchTest).
     *
     * A TENANCY do app (Multi-unidade / tenant em pool) e o soft delete
     * declarado no manifest valem nos DOIS modos: com o row-scope desligado (o
     * default) o gateway cru lia a tabela inteira — o chat de um usuario da
     * unidade A listava, lia por id e somava as linhas da unidade B.
     */
    protected function gateway(): McpTableGateway
    {
        $entity = $this->entity();
        [$tenancy, $stamp] = McpScopeContext::tenancyFor($this->db(), $entity);
        $softDelete = $this->manifest->softDeleteColumnFor($entity);

        if (! (bool) (\Mad\Core\AppConfig::get()['mcp']['row_scope_enabled'] ?? false)) {
            if ($tenancy === [] && $softDelete === null) {
                return (new McpTableGateway())->on($this->db()); // flag off, sem tenancy => comportamento legado explicito
            }

            return (new McpScopedGateway())
                ->on($this->db())
                ->withTenancy($tenancy, $stamp)
                ->withSoftDeleteColumn($softDelete);
        }

        $plan = (new McpGrantResolver())->rowScopePlan($this->manifest, $entity);

        return (new McpScopedGateway())
            ->on($this->db())
            ->withPlan($plan)
            ->withTenancy($tenancy, $stamp)
            ->withSoftDeleteColumn($softDelete);
    }

    protected function masker(): McpPiiMasker
    {
        return new McpPiiMasker($this->manifest->fieldsFor($this->entity()));
    }

    protected function schemaBuilder(): McpSchemaBuilder
    {
        return new McpSchemaBuilder();
    }
}
