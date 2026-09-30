<?php

namespace Mad\Database\Concerns;

use Mad\Util\IdGenerator;

/**
 * HasIdPolicy
 *
 * Geração de chave primária por política, aplicada no evento `creating`:
 *  - 'serial' (default): banco gera (identity / auto-increment) — nada é gerado na app
 *  - 'uuid'|'uuid4'|'uuid7'|'ulid'|'tsid'|'cuid2'|'nanoid'|'snowflake': IdGenerator
 *  - 'none': caller define a PK (ex.: chave semântica string) — nada é gerado
 *
 * 'max' (MAX(pk)+1) foi REMOVIDO — tinha race condition (dois inserts concorrentes
 * calculam o mesmo MAX+1). Declarar 'max' agora lança RuntimeException no save();
 * migre a tabela para identity/auto-increment e use 'serial'.
 *
 * Configuração no model (default = 'serial'):
 *   protected string $idPolicy = 'uuid7';
 *
 * A constante IDPOLICY, se definida, tem precedência (compat legado).
 */
trait HasIdPolicy
{
    public static function bootHasIdPolicy(): void
    {
        static::creating(function ($model) {
            $model->madApplyIdPolicy();
        });
    }

    public function initializeHasIdPolicy(): void
    {
        $policy = $this->madIdPolicy();

        $this->incrementing = ($policy === 'serial');

        if (in_array($policy, $this->madStringIdPolicies(), true)) {
            $this->keyType = 'string';
        }
    }

    public function madIdPolicy(): string
    {
        $class = static::class;
        if (defined("{$class}::IDPOLICY")) {
            return constant("{$class}::IDPOLICY");
        }
        if (property_exists($this, 'idPolicy') && isset($this->idPolicy)) {
            return $this->idPolicy;
        }
        return 'serial';
    }

    protected function madStringIdPolicies(): array
    {
        return ['uuid', 'uuid4', 'uuid7', 'ulid', 'tsid', 'cuid2', 'nanoid', 'snowflake'];
    }

    protected function madApplyIdPolicy(): void
    {
        $pk = $this->getKeyName();
        if (!empty($this->getAttribute($pk))) {
            return;
        }

        $policy = $this->madIdPolicy();

        if ($policy === 'serial' || $policy === 'none') {
            return; // serial: banco gera; none: caller define
        }

        if (in_array($policy, $this->madStringIdPolicies(), true)) {
            $this->setAttribute($pk, IdGenerator::generate($policy));
            return;
        }

        if ($policy === 'max') {
            throw new \RuntimeException(
                "idPolicy 'max' foi removido (race em MAX(pk)+1). Migre a tabela para "
                . "identity/auto-increment e use 'serial' em " . static::class . '.'
            );
        }

        throw new \InvalidArgumentException(
            "idPolicy desconhecida '{$policy}' em " . static::class . '.'
        );
    }
}
