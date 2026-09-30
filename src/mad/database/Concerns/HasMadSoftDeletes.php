<?php

namespace Mad\Database\Concerns;

/**
 * HasMadSoftDeletes
 *
 * Soft delete condicional: ativo apenas quando o model define a coluna.
 * Global scope 'madSoftDelete' filtra registros marcados; delete() marca a
 * coluna (e autoria) em vez de remover a linha.
 *
 * Remapeamento — precedência: const legada → $madAudit → prop → null (= hard delete).
 * Recomendado (mesmo mapa de HasMadAudit; chave lógica => coluna real, false = desliga):
 *   protected array $madAudit = [
 *       'deleted_at'         => 'removido_em',
 *       'deleted_by'         => 'removido_por',
 *       'deleted_by_user_id' => 'removido_por_user_id',
 *   ];
 *
 * Forma antiga (fallback; null = hard delete, comportamento padrão):
 *   protected ?string $deletedAtColumn = 'deleted_at'; // ...deletedByColumn, etc.
 *
 * Constantes legadas (DELETEDAT, DELETED_BY, DELETED_BY_USER_ID) têm
 * precedência (compat). Nome do scope idêntico ao legado ('madSoftDelete')
 * para withoutGlobalScope() funcionar igual nos dois mundos.
 */
trait HasMadSoftDeletes
{
    public static function bootHasMadSoftDeletes(): void
    {
        static::addGlobalScope('madSoftDelete', function ($builder) {
            $col = $builder->getModel()->getDeletedAtColumn();
            if ($col) {
                $builder->getQuery()->whereNull($col);
            }
        });
    }

    public function getDeletedAtColumn()       { return $this->madSoftDeleteColumn('DELETEDAT', 'deleted_at', 'deletedAtColumn'); }
    public function getDeletedByColumn()       { return $this->madSoftDeleteColumn('DELETED_BY', 'deleted_by', 'deletedByColumn'); }
    public function getDeletedByUserIdColumn() { return $this->madSoftDeleteColumn('DELETED_BY_USER_ID', 'deleted_by_user_id', 'deletedByUserIdColumn'); }

    /**
     * Resolve o nome real de uma coluna de soft delete.
     * Precedência: const legada → $madAudit[$mapKey] → propriedade legada → null.
     * Em $madAudit, valor `false` (ou `null`) desliga explicitamente a coluna.
     */
    protected function madSoftDeleteColumn(string $const, string $mapKey, string $property): ?string
    {
        $class = static::class;
        if (defined("{$class}::{$const}")) {
            return constant("{$class}::{$const}");
        }
        if (property_exists($this, 'madAudit') && array_key_exists($mapKey, $this->madAudit)) {
            $mapped = $this->madAudit[$mapKey];
            return ($mapped === false || $mapped === null) ? null : $mapped;
        }
        if (property_exists($this, $property) && isset($this->{$property})) {
            return $this->{$property};
        }
        return null;
    }

    /** Soft delete quando a coluna está configurada; senão delega ao Eloquent. */
    protected function performDeleteOnModel()
    {
        $col = $this->getDeletedAtColumn();
        if (!$col) {
            return parent::performDeleteOnModel();
        }

        $set = [$col => date($this->madSoftDeleteDateMask())];
        if ($c = $this->getDeletedByColumn())       { $set[$c] = $this->madSoftDeleteSessionValue('login'); }
        if ($c = $this->getDeletedByUserIdColumn()) { $set[$c] = $this->madSoftDeleteSessionValue('userid'); }

        $this->setKeysForSaveQuery($this->newModelQuery())->update($set);

        foreach ($set as $key => $value) {
            $this->setAttribute($key, $value);
        }
        $this->syncOriginal();
    }

    public function restore(): static
    {
        $col = $this->getDeletedAtColumn();
        if (!$col) {
            throw new \RuntimeException('Soft delete não está ativo em ' . static::class);
        }

        $this->setKeysForSaveQuery($this->newModelQuery())->update([$col => null]);
        $this->setAttribute($col, null);
        $this->syncOriginal();

        return $this;
    }

    public function trashed(): bool
    {
        $col = $this->getDeletedAtColumn();
        return $col !== null && $this->getAttribute($col) !== null;
    }

    public static function withTrashed()
    {
        return static::query()->withoutGlobalScope('madSoftDelete');
    }

    public static function onlyTrashed()
    {
        $query = static::query()->withoutGlobalScope('madSoftDelete');
        $col = (new static)->getDeletedAtColumn();
        if ($col) {
            $query->whereNotNull($col);
        }
        return $query;
    }

    protected function madSoftDeleteSessionValue(string $key)
    {
        try {
            return app()->bound('session') ? session($key) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function madSoftDeleteDateMask(): string
    {
        try {
            $driver = $this->getConnection()->getDriverName();
        } catch (\Throwable) {
            $driver = '';
        }
        return $driver === 'sqlsrv' ? 'Ymd H:i:s' : 'Y-m-d H:i:s';
    }
}
