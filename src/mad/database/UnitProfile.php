<?php

namespace Mad\Database;

use Illuminate\Database\Eloquent\Model;
use Mad\Component\MadComponent;
use Mad\Http\MadResponse;
use Mad\Registry\ControlRegistry;
use Mad\Routing\MadRoutes;

/**
 * UnitProfile — "Dados da unidade": a tabela do PRÓPRIO modelo do projeto com
 * o que a unidade (filial) do framework não tem — CNPJ, IE, endereço,
 * certificado digital. Uma linha por unidade, ligada por uma coluna dela
 * (padrão `unidade_id`; nunca a `unit_id` do escopo por unidade).
 *
 * Quem aponta a tabela é o dono do projeto, no Studio; a plataforma emite três
 * identificadores simples (nunca FQCN), lidos em mad.general.unit_profile:
 *   MAD_UNIT_PROFILE_MODEL  — token do ModelRegistry (o `model="…"` do Blade)
 *   MAD_UNIT_PROFILE_COLUMN — coluna que guarda o id da unidade
 *   MAD_UNIT_PROFILE_FORM   — tela de formulário da tabela (`LojaForm`)
 * Qualquer um vazio, ou Multi-unidade desligado, = recurso desligado: a tela
 * Unidades fica como sempre foi, sem ação nova e sem consulta.
 *
 *   UnitProfile::current()       // registro da unidade ativa (NF-e, cabeçalho de PDF)
 *   UnitProfile::for($unitId)    // registro de uma unidade qualquer
 *
 * A tela Unidades usa reachable()/open(); o formulário configurado, aberto com
 * `?mad_unit_profile=<id>`, nasce ligado à unidade (MadComponent::show →
 * {@see prefillFor()}).
 *
 * Nunca lança: tabela ainda não migrada, model ou tela apagados respondem
 * null/false — o recurso some em vez de derrubar a tela.
 */
final class UnitProfile
{
    /** Parâmetro do cadastro novo: a unidade que o formulário recebe. */
    public const PARAM = 'mad_unit_profile';

    private const MEMO = 'unit_profile';

    /** "Dados da unidade" configurado, e o model e a tela existem? */
    public static function enabled(): bool
    {
        return self::settings() !== null;
    }

    /** Coluna que guarda o id da unidade, ou null com o recurso desligado. */
    public static function column(): ?string
    {
        return self::settings()['column'] ?? null;
    }

    /** FQCN da tela de formulário configurada, ou null com o recurso desligado. */
    public static function formClass(): ?string
    {
        return self::settings()['form'] ?? null;
    }

    /**
     * Registro dos dados da unidade `$unitId`, ou null (recurso desligado,
     * unidade sem registro, tabela que ainda não existe). Memo por requisição,
     * separado por empresa/unidade (DataScope::memo).
     */
    public static function for(int $unitId): ?Model
    {
        $cfg = self::settings();
        if ($cfg === null || $unitId <= 0) {
            return null;
        }

        return DataScope::memo(self::MEMO, $cfg['model'] . '|' . $cfg['column'] . '|' . $unitId, static function () use ($cfg, $unitId): ?Model {
            try {
                return $cfg['model']::query()->where($cfg['column'], $unitId)->first();
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /** Registro dos dados da unidade ATIVA ({@see UnitContext::id()}), ou null. */
    public static function current(): ?Model
    {
        $unitId = UnitContext::id();

        return $unitId === null ? null : self::for($unitId);
    }

    /** Esquece os registros lidos nesta requisição (quem acabou de gravar um). */
    public static function flush(): void
    {
        DataScope::flushMemo(self::MEMO);
    }

    /**
     * A unidade pode ter os dados abertos por quem está logado?
     *
     * Só a unidade da empresa logada quando o registro dela é da empresa:
     *   - banco por empresa (Dedicado, `mad.general.multi_database`): o registro
     *     de outra empresa mora no banco DAQUELA empresa, não no aberto agora;
     *   - Pool (`mad.tenant.row_scope_enabled`) com a tabela dos dados separada
     *     por empresa (Model com BelongsToTenant): o registro de outra empresa
     *     não é lido, e o novo seria carimbado com a empresa logada.
     * Tabela sem separação por empresa (no Pool ou sem isolamento), toda
     * unidade — inclusive na visão de todas as empresas do administrador.
     *
     * @param  object|array<string,mixed>|int|null  $unit  a unidade (model), a linha da
     *                                                     listagem (`tenant_id`) ou o id
     */
    public static function reachable(object|array|int|null $unit): bool
    {
        if (is_int($unit)) {
            $unit = self::unit($unit);
        } elseif (is_array($unit) && ! array_key_exists('tenant_id', $unit)) {
            $unit = isset($unit['id']) && is_numeric($unit['id']) ? self::unit((int) $unit['id']) : null;
        }
        if ($unit === null) {
            return false;
        }
        if (! self::recordsBelongToTenant()) {
            return true;
        }

        $tenantId = is_array($unit) ? ($unit['tenant_id'] ?? null) : ($unit->tenant_id ?? null);

        return self::intOrNull($tenantId) === self::loggedTenantId();
    }

    /**
     * Endereço do formulário dos dados da unidade: a edição do registro que
     * existe, ou o cadastro novo com `?mad_unit_profile=<id>`. '' desligado.
     */
    public static function urlFor(int $unitId): string
    {
        $target = self::target($unitId);

        return $target === null ? '' : MadRoutes::urlFor($target[0], $target[1], $target[2]);
    }

    /**
     * Resposta que abre o formulário dos dados da unidade como a tela dele é
     * (`Mad.go`: cortina lateral ou janela por cima, tela inteira no lugar da
     * de trás). null quando o recurso está desligado ou a unidade não é
     * alcançável ({@see reachable()}) — quem chama avisa.
     */
    public static function open(int $unitId, ?MadResponse $response = null): ?MadResponse
    {
        if (! self::enabled() || ! self::reachable($unitId)) {
            return null;
        }
        $target = self::target($unitId);
        if ($target === null) {
            return null;
        }
        [$class, $method, $params] = $target;

        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $args  = [$class, $method !== '' ? $method : 'show', (object) $params, MadRoutes::urlFor($class, $method, $params)];

        return ($response ?? new MadResponse())
            ->script('Mad.go(' . implode(', ', array_map(static fn ($v) => json_encode($v, $flags), $args)) . ')');
    }

    /**
     * Unidade que o formulário `$class`, aberto com `?mad_unit_profile=`,
     * recebe na coluna configurada — ou null: não é a tela configurada,
     * unidade inexistente ou de outra empresa, ou a unidade já tem o registro
     * (o caminho dela é a edição).
     *
     * @internal chamado por MadComponent::show()
     */
    public static function prefillFor(string $class, mixed $unitId): ?int
    {
        if (! is_scalar($unitId) || ! ctype_digit((string) $unitId) || (int) $unitId <= 0) {
            return null;
        }
        $unitId = (int) $unitId;
        $form   = self::formClass();
        if ($form === null || ltrim($class, '\\') !== $form) {
            return null;
        }
        if (! self::reachable($unitId) || self::for($unitId) !== null) {
            return null;
        }

        return $unitId;
    }

    // ── internos ─────────────────────────────────────────────────────────────

    /**
     * As três chaves resolvidas, ou null (Multi-unidade desligado, chave
     * vazia, coluna que não é identificador, model/tela que não existem).
     *
     * @return array{model: class-string<Model>, column: string, form: class-string<MadComponent>}|null
     */
    private static function settings(): ?array
    {
        try {
            if (! DataScope::unitScopeActive()) {
                return null;
            }
            $cfg = (array) config('mad.general.unit_profile', []);

            $model  = trim((string) ($cfg['model'] ?? ''));
            $column = trim((string) ($cfg['column'] ?? ''));
            $form   = trim((string) ($cfg['form'] ?? ''));
            if ($model === '' || $form === '' || ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $column)) {
                return null;
            }

            $modelClass = ModelRegistry::resolve($model);
            $formClass  = ControlRegistry::resolve($form);
            if ($modelClass === null || ! is_subclass_of($modelClass, Model::class)
                || $formClass === null || ! is_subclass_of($formClass, MadComponent::class)) {
                return null;
            }

            // O nome curto da tela também existe como apelido global da classe
            // (`class_exists('LojaForm')`): o que se compara com o `static::class`
            // da tela aberta é o nome verdadeiro dela.
            return [
                'model'  => (new \ReflectionClass($modelClass))->getName(),
                'column' => $column,
                'form'   => (new \ReflectionClass($formClass))->getName(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Tela, método e parâmetros do formulário da unidade. A tela vai pelo
     * nome curto: é por ele que as rotas e as permissões a conhecem.
     *
     * @return array{0: string, 1: string, 2: array<string, int|string>}|null
     */
    private static function target(int $unitId): ?array
    {
        $form = self::formClass();
        if ($form === null || $unitId <= 0) {
            return null;
        }
        $class  = class_basename($form);
        $record = self::for($unitId);
        if ($record !== null) {
            return [$class, 'onEdit', ['id' => $record->getKey()]];
        }

        return [$class, '', [self::PARAM => $unitId]];
    }

    /**
     * O registro dos dados da unidade é de uma empresa? Banco por empresa
     * (Dedicado), ou Pool com o Model separado por empresa — o mesmo teste que
     * o verificador de unique/exists faz (BelongsToTenant::madTenantScoped()).
     */
    private static function recordsBelongToTenant(): bool
    {
        if ((string) config('mad.general.multi_database', '0') === '1') {
            return true;
        }
        if (! DataScope::tenantScopeActive()) {
            return false;
        }
        $model = self::settings()['model'] ?? null;

        return $model !== null && method_exists($model, 'madTenantScoped') && $model::madTenantScoped();
    }

    /**
     * Empresa de quem está logado: a da sessão ou, sem ela (login por token),
     * a da unidade ativa — mesma derivação do TenantContext::name().
     */
    private static function loggedTenantId(): ?int
    {
        $tenantId = TenantContext::id();
        if ($tenantId !== null || TenantContext::overridden()) {
            return $tenantId;
        }
        $unitId = UnitContext::id();

        return $unitId === null ? null : self::intOrNull(self::unit($unitId)?->tenant_id);
    }

    /** Unidade do esqueleto (App\Models\Iam\Unit), ou null — nunca lança. */
    private static function unit(int $id): ?Model
    {
        if ($id <= 0 || ! class_exists(\App\Models\Iam\Unit::class)) {
            return null;
        }
        try {
            return \App\Models\Iam\Unit::query()->find($id);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function intOrNull(mixed $v): ?int
    {
        return ($v === null || $v === '' || ! is_numeric($v)) ? null : (int) $v;
    }
}
