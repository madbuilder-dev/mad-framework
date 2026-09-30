<?php

namespace Mad\Database\Concerns;

use Mad\Database\ChangeLog;

/**
 * HasMadChangeLog
 *
 * Liga o "log de mudança de objeto" no model (equivalente ao TRACKCHANGES do
 * Adianti): toda criação, alteração e exclusão via Eloquent grava em
 * mad_log_change 1 linha por coluna afetada — valor antigo → novo, quem, quando,
 * de onde (tela Logs → Alterações). Escrita best-effort e adiada para o commit
 * da transação (ver Mad\Database\ChangeLog).
 *
 *   class Produto extends Model
 *   {
 *       use HasMadChangeLog;
 *
 *       protected $hidden = ['cartao'];           // entra no log como '***'
 *       // password/senha/token/secret… já entram como '***' pelo nome
 *       protected array $madChangeLog = [
 *           'except' => ['hash_busca'],           // não entra no log
 *           'mask'   => ['cpf'],                  // entra como '***'
 *       ];
 *   }
 *
 * Não entram: colunas de carimbo (HasMadAudit / soft delete / timestamps).
 * Não dispara em operações em massa que pulam eventos Eloquent
 * (Model::where()->update(), insert(), upsert(), DB::table()).
 * Desligar: env MAD_CHANGE_LOG=false (global) ou ChangeLog::withoutLogging(fn).
 */
trait HasMadChangeLog
{
    public static function bootHasMadChangeLog(): void
    {
        static::created(fn ($model) => ChangeLog::record($model, [], $model->getAttributes()));

        // Em `updated` o original ainda não foi sincronizado (syncOriginal roda
        // depois de `saved`), então getRawOriginal() é o estado ANTES do update.
        static::updated(fn ($model) => ChangeLog::record($model, $model->getRawOriginal(), $model->getAttributes()));

        // Hard ou soft delete: o estado anterior é o original; com soft delete
        // os atributos já carregam deleted_at, por isso não servem de "antes".
        static::deleted(fn ($model) => ChangeLog::record($model, $model->getRawOriginal() ?: $model->getAttributes(), []));
    }

    /**
     * Opções do model lidas pelo escritor: `except` (colunas fora do log) e
     * `mask` (colunas gravadas como '***', além de $hidden).
     *
     * @return array{except: string[], mask: string[]}
     */
    public function madChangeLogOptions(): array
    {
        $opt = property_exists($this, 'madChangeLog') && is_array($this->madChangeLog) ? $this->madChangeLog : [];

        return [
            'except' => (array) ($opt['except'] ?? []),
            'mask'   => (array) ($opt['mask'] ?? []),
        ];
    }
}
