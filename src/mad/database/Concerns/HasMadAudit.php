<?php

namespace Mad\Database\Concerns;

/**
 * HasMadAudit
 *
 * Auditoria MAD via eventos Eloquent (substitui os timestamps nativos —
 * use com `public $timestamps = false`):
 *   creating → created_at / created_by / created_by_user_id / created_by_unit_id
 *   updating → updated_at / updated_by / updated_by_user_id
 *
 * Remapeamento de colunas — precedência: const legada → $madAudit → prop → null.
 * Forma recomendada (1 mapa; chave lógica => coluna real, false = desliga a coluna):
 *   protected array $madAudit = [
 *       'created_at'         => 'criado_em',
 *       'created_by'         => 'criado_por',
 *       'created_by_user_id' => 'criado_por_user_id',
 *       'created_by_unit_id' => false,          // não audita unidade
 *       'updated_at'         => 'alterado_em',
 *       'updated_by'         => 'alterado_por',
 *       'updated_by_user_id' => 'alterado_por_user_id',
 *   ];
 *
 * Forma antiga (ainda funciona como fallback; null = não audita aquela coluna):
 *   protected ?string $createdAtColumn = 'created_at'; // ...createdByColumn, etc.
 *
 * Constantes legadas (CREATEDAT, CREATED_BY, ...) têm precedência sobre tudo (compat).
 * Usuário/unidade vêm da session MAD (session('login'/'userid'/'userunitid')).
 */
trait HasMadAudit
{
    public static function bootHasMadAudit(): void
    {
        static::creating(fn ($model) => $model->madStampAudit(true));
        static::updating(fn ($model) => $model->madStampAudit(false));
    }

    /**
     * Auditoria MAD substitui os timestamps nativos do Eloquent — desliga-os
     * aqui para que `created_at`/`updated_at` sejam gerenciados só pelos eventos
     * deste trait. Roda no construtor (initializeTraits), então o model não
     * precisa declarar `public $timestamps = false`.
     */
    public function initializeHasMadAudit(): void
    {
        $this->timestamps = false;
    }

    public function getCreatedAtColumn()       { return $this->madAuditColumn('CREATEDAT', 'created_at', 'createdAtColumn'); }
    public function getUpdatedAtColumn()       { return $this->madAuditColumn('UPDATEDAT', 'updated_at', 'updatedAtColumn'); }
    public function getCreatedByColumn()       { return $this->madAuditColumn('CREATED_BY', 'created_by', 'createdByColumn'); }
    public function getUpdatedByColumn()       { return $this->madAuditColumn('UPDATED_BY', 'updated_by', 'updatedByColumn'); }
    public function getCreatedByUserIdColumn() { return $this->madAuditColumn('CREATED_BY_USER_ID', 'created_by_user_id', 'createdByUserIdColumn'); }
    public function getUpdatedByUserIdColumn() { return $this->madAuditColumn('UPDATED_BY_USER_ID', 'updated_by_user_id', 'updatedByUserIdColumn'); }
    public function getCreatedByUnitIdColumn() { return $this->madAuditColumn('CREATED_BY_UNIT_ID', 'created_by_unit_id', 'createdByUnitIdColumn'); }

    /**
     * Resolve o nome real de uma coluna de auditoria.
     * Precedência: const legada → $madAudit[$mapKey] → propriedade legada → null.
     * Em $madAudit, valor `false` (ou `null`) desliga explicitamente a coluna.
     */
    protected function madAuditColumn(string $const, string $mapKey, string $property): ?string
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

    protected function madStampAudit(bool $creating): void
    {
        $now = date($this->madAuditDateMask());

        if ($creating) {
            if ($c = $this->getCreatedAtColumn())       { $this->setAttribute($c, $now); }
            if ($c = $this->getCreatedByColumn())       { $this->setAttribute($c, $this->madAuditSessionValue('login')); }
            if ($c = $this->getCreatedByUserIdColumn()) { $this->setAttribute($c, $this->madAuditSessionValue('userid')); }
            if ($c = $this->getCreatedByUnitIdColumn()) { $this->setAttribute($c, $this->madAuditSessionValue('userunitid')); }
        } else {
            if ($c = $this->getUpdatedAtColumn())       { $this->setAttribute($c, $now); }
            if ($c = $this->getUpdatedByColumn())       { $this->setAttribute($c, $this->madAuditSessionValue('login')); }
            if ($c = $this->getUpdatedByUserIdColumn()) { $this->setAttribute($c, $this->madAuditSessionValue('userid')); }
        }
    }

    /** Mesma chave usada no login (session nativa do Laravel, sem prefixo). */
    protected function madAuditSessionValue(string $key)
    {
        try {
            return app()->bound('session') ? session($key) : null;
        } catch (\Throwable) {
            return null; // CLI/queue sem session — auditoria de usuário fica null
        }
    }

    protected function madAuditDateMask(): string
    {
        try {
            $driver = $this->getConnection()->getDriverName();
        } catch (\Throwable) {
            $driver = '';
        }
        return $driver === 'sqlsrv' ? 'Ymd H:i:s' : 'Y-m-d H:i:s';
    }
}
