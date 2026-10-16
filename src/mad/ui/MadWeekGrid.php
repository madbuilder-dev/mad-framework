<?php

declare(strict_types=1);

namespace Mad\Ui;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;

/**
 * MadWeekGrid — o `<mad-week-grid>`: linhas de registros (pessoas) × dias da
 * semana, com um estado por célula que muda a cada clique (P → R → F → vazio).
 *
 * Roda como componente próprio dentro da tela (como o `<mad-grid>`): a
 * configuração viaja no estado criptografado e não pode ser trocada pelo
 * navegador; cada clique grava só a célula, sem salvar a tela.
 *
 * Duas formas de gravar no model (`model=`):
 *   - uma linha por pessoa e semana: `week-field` (a segunda-feira) +
 *     `day-fields` (colunas `seg`…`sex`) + `note-field` opcional;
 *   - uma linha por pessoa e dia: `date-field` + `state-field` (célula vazia
 *     apaga a linha).
 * Ou `save-action="metodo"`: a tela recebe `(linha, data, valor)` e grava do
 * jeito dela; o quadro relê o model depois.
 *
 * Linhas: `rows-model` (+ `rows-label`, `rows-sub`, `:rows-filters`) ou `:rows`
 * (array); sem nenhum, as que têm registro na semana.
 *
 * Permissão: o dono é a TELA que contém o quadro (perfil → Editar). `readonly`
 * trava tudo; `:editable-rows` limita a quem a tela liberar (a lista é selada
 * no estado no primeiro desenho). Toda gravação reconfere a linha pela mesma
 * consulta que a desenhou (escopos globais incluídos).
 *
 * Guia: docs/mad-week-grid.md
 */
class MadWeekGrid extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    public const TONES = ['success', 'info', 'warning', 'orange', 'danger', 'neutral', 'primary'];

    private const DEFAULT_DAY_FIELDS = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];

    /** Config normalizada (selada no estado). */
    public array $wgConfig = [];

    /** Segunda-feira da semana exibida (Y-m-d). */
    public string $weekStart = '';

    protected function _lockedStateProps(): array
    {
        return ['wgConfig'];
    }

    // ── Desenho ────────────────────────────────────────────────────────────

    /** Chamado pelo Blade do `<mad-week-grid>`. @internal */
    public static function renderFor(array $config): string
    {
        $grid = new static();
        $grid->wgConfig = $config;
        $grid->boot();
        $grid->mount();

        return $grid->_renderWrapped();
    }

    public function mount(): void
    {
        $this->weekStart = self::monday((string) ($this->wgConfig['week'] ?? ''))->toDateString();
    }

    protected function view(): string|array
    {
        return ['components.partials.week-grid-body', ['wg' => $this->matrix()]];
    }

    /**
     * Tudo o que o Blade desenha: dias, linhas com os estados, feriados,
     * totais por dia. Público para teste.
     *
     * @return array<string,mixed>
     */
    public function matrix(): array
    {
        $c = $this->wgConfig;
        $start = self::monday($this->weekStart);
        $today = CarbonImmutable::today()->toDateString();
        $locale = str_replace('-', '_', (string) app()->getLocale());

        $days = [];
        for ($i = 0; $i < (int) $c['days']; $i++) {
            $d = $start->addDays($i);
            $days[] = [
                'i' => $i + 1,
                'date' => $d->toDateString(),
                'short' => mb_convert_case(rtrim($d->locale($locale)->isoFormat('ddd'), '.'), MB_CASE_TITLE),
                'dm' => $d->format('d/m'),
                'long' => $d->locale($locale)->isoFormat('dddd, LL'),
                'today' => $d->toDateString() === $today,
                'holiday' => null,
            ];
        }
        $end = $start->addDays(count($days) - 1);

        $error = null;
        $holidays = [];
        $values = [];
        $notes = [];
        $rows = [];
        try {
            $holidays = $this->holidays($start, $end);
            [$values, $notes] = $this->load($start, $end);
            $rows = $this->rows(array_keys($values));
        } catch (\Throwable $e) {
            $error = MadUserError::message($e, mad_t('mad.week_grid.load_failed'), static::class . '::matrix');
        }
        foreach ($days as &$d) {
            $d['holiday'] = $holidays[$d['date']] ?? null;
        }
        unset($d);

        $editable = $c['editableRows'];
        $locked = $c['readonly'] || ($c['model'] === '' && $c['action'] === '');
        $totals = [];
        foreach ($rows as &$r) {
            $r['cells'] = [];
            foreach ($days as $d) {
                $v = $values[$r['key']][$d['i']] ?? '';
                $r['cells'][$d['i']] = $v;
                if ($v !== '') {
                    $totals[$d['i']][$v] = ($totals[$d['i']][$v] ?? 0) + 1;
                }
            }
            $r['note'] = $notes[$r['key']] ?? '';
            $r['editable'] = !$locked && ($editable === null || in_array($r['key'], $editable, true));
        }
        unset($r);

        return [
            'config' => $c,
            'weekStart' => $start->toDateString(),
            'weekEnd' => $end->toDateString(),
            'range' => rtrim($start->locale($locale)->isoFormat('D MMM'), '.') . ' – ' . $end->locale($locale)->isoFormat('D MMM YYYY'),
            'isCurrent' => $start->toDateString() === self::monday('')->toDateString(),
            'days' => $days,
            'rows' => $rows,
            'states' => $c['states'],
            'totals' => $totals,
            'locked' => $locked,
            'noteOn' => $c['noteField'] !== '' || ($c['action'] !== '' && $c['noteLabel'] !== ''),
            'error' => $error,
        ];
    }

    // ── Ações (canal reativo) ──────────────────────────────────────────────

    /** Semana anterior / próxima / atual (navegação: não é permissão). */
    public function onWeekGo(string $to = 'today'): ?MadResponse
    {
        $cur = self::monday($this->weekStart);
        $this->weekStart = match ($to) {
            'prev' => $cur->subWeek()->toDateString(),
            'next' => $cur->addWeek()->toDateString(),
            'today' => self::monday('')->toDateString(),
            default => self::monday($to)->toDateString(),
        };
        $this->forceFullRender();

        return null;
    }

    /** Relê o model (depois de um `save-action` da tela). */
    public function onRefresh(): ?MadResponse
    {
        $this->forceFullRender();

        return null;
    }

    /** Grava o estado de UMA célula. `$value` vazio limpa. */
    public function onWeekCell(mixed $row = '', mixed $date = '', mixed $value = ''): MadResponse
    {
        $row = (string) $row;
        $value = trim((string) $value);
        try {
            $day = $this->guardCell($row, (string) $date, $value);
            $this->writeCell($row, $day, $value);
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return $this->saved('[data-wg-cell="' . self::cssKey($row) . ':' . $day->toDateString() . '"]');
    }

    /** Grava o texto livre da linha na semana exibida. */
    public function onWeekNote(mixed $row = '', mixed $text = ''): MadResponse
    {
        $row = (string) $row;
        $text = mb_substr(trim((string) $text), 0, (int) $this->wgConfig['noteMax']);
        try {
            $this->guardRow($row);
            if ($this->wgConfig['noteField'] === '' || $this->wgConfig['dateField'] !== '') {
                throw new \DomainException(mad_t('mad.week_grid.note_unavailable'));
            }
            $this->writeWeekRow($row, self::monday($this->weekStart), [$this->wgConfig['noteField'] => $text === '' ? null : $text]);
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return $this->saved('[data-wg-note="' . self::cssKey($row) . '"]');
    }

    /**
     * Copia a semana anterior para quem ainda não tem nada na semana exibida
     * (linhas que a pessoa pode editar). Não sobrescreve marcação existente.
     */
    public function onWeekCopy(): MadResponse
    {
        $c = $this->wgConfig;
        if ($c['readonly'] || $c['model'] === '' || !$c['copyWeek']) {
            return MadToast::warning(mad_t('mad.week_grid.readonly'));
        }
        $cur = self::monday($this->weekStart);
        $prev = $cur->subWeek();
        $nDays = (int) $c['days'];
        try {
            [$from, $fromNotes] = $this->load($prev, $prev->addDays($nDays - 1));
            [$now, $nowNotes] = $this->load($cur, $cur->addDays($nDays - 1));
            $allowed = array_column($this->rows(array_keys($from)), 'key');
            $copied = 0;
            DB::connection($this->newModel()->getConnectionName())->transaction(function () use ($from, $fromNotes, $now, $nowNotes, $allowed, $cur, $c, &$copied) {
                foreach ($from as $row => $days) {
                    $row = (string) $row;
                    if (!in_array($row, $allowed, true) || !$this->rowEditable($row)) {
                        continue;
                    }
                    if (array_filter($now[$row] ?? []) !== [] || ($nowNotes[$row] ?? '') !== '') {
                        continue;
                    }
                    if ($c['dateField'] !== '') {
                        foreach ($days as $i => $v) {
                            if ($v !== '') {
                                $this->writeCell($row, $cur->addDays($i - 1), $v);
                            }
                        }
                    } else {
                        $set = [];
                        foreach ($days as $i => $v) {
                            $set[$c['dayFields'][$i - 1]] = $v === '' ? null : $v;
                        }
                        if ($c['noteField'] !== '' && ($fromNotes[$row] ?? '') !== '') {
                            $set[$c['noteField']] = $fromNotes[$row];
                        }
                        $this->writeWeekRow($row, $cur, $set);
                    }
                    $copied++;
                }
            });
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        $this->forceFullRender();

        return $copied > 0
            ? MadToast::success(mad_t('mad.week_grid.copied', ['n' => $copied]))
            : MadToast::info(mad_t('mad.week_grid.copy_nothing'));
    }

    // ── Permissão ──────────────────────────────────────────────────────────

    /** Dono da permissão = a tela que contém o quadro (perfil → Editar). */
    public function _permOwner(): string
    {
        return $this->_permOwners()[0] ?? static::class;
    }

    /** @return list<string> */
    public function _permOwners(): array
    {
        $owner = trim((string) ($this->wgConfig['owner'] ?? ''));

        return $owner !== '' ? [$owner] : [];
    }

    // ── Config ─────────────────────────────────────────────────────────────

    /**
     * Normaliza os atributos do Blade. Colunas são validadas aqui (vão cruas
     * para o SQL); estados, dias e feriados ganham forma única.
     *
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    public static function config(array $a): array
    {
        $col = static fn (mixed $v, string $what): string => self::column(trim((string) ($v ?? '')), $what);
        $bool = static function (mixed $v, bool $default): bool {
            if ($v === null || $v === '') return $default;
            if (is_bool($v)) return $v;
            return !in_array(strtolower(trim((string) $v)), ['false', '0', 'no', 'off', 'nao', 'não'], true);
        };

        $days = (int) ($a['days'] ?? 5);
        $days = in_array($days, [5, 6, 7], true) ? $days : 5;
        $dayFields = $a['dayFields'] ?? null;
        if (is_string($dayFields) && trim($dayFields) !== '') {
            $dayFields = array_map('trim', explode(',', $dayFields));
        }
        $dayFields = is_array($dayFields) && $dayFields !== [] ? array_values($dayFields) : self::DEFAULT_DAY_FIELDS;
        $dayFields = array_map(static fn ($f) => self::column((string) $f, 'day-fields'), array_slice(array_pad($dayFields, $days, ''), 0, $days));

        $editable = $a['editableRows'] ?? null;
        $editable = is_array($editable) ? array_values(array_map('strval', $editable)) : null;

        $dateField = $col($a['dateField'] ?? '', 'date-field');

        return [
            'owner' => (string) ($a['owner'] ?? ''),
            'name' => (string) ($a['name'] ?? ''),
            'title' => (string) ($a['title'] ?? ''),
            'model' => trim((string) ($a['model'] ?? '')),
            'database' => trim((string) ($a['database'] ?? '')),
            'filters' => is_array($a['filters'] ?? null) ? $a['filters'] : [],
            'defaults' => is_array($a['defaults'] ?? null) ? $a['defaults'] : [],
            'rowField' => $col($a['rowField'] ?? '', 'row-field'),
            'weekField' => $dateField !== '' ? '' : $col($a['weekField'] ?? '', 'week-field'),
            'dayFields' => $dayFields,
            'dateField' => $dateField,
            'stateField' => $col($a['stateField'] ?? '', 'state-field'),
            'noteField' => $col($a['noteField'] ?? '', 'note-field'),
            'noteLabel' => (string) ($a['noteLabel'] ?? ''),
            'notePlaceholder' => (string) ($a['notePlaceholder'] ?? ''),
            'noteMax' => max(1, min(2000, (int) ($a['noteMax'] ?? 255))),
            'action' => self::method((string) ($a['saveAction'] ?? '')),
            'rows' => self::normalizeRows($a['rows'] ?? null),
            'rowsModel' => trim((string) ($a['rowsModel'] ?? '')),
            'rowsLabel' => $col($a['rowsLabel'] ?? '', 'rows-label'),
            'rowsSub' => $col($a['rowsSub'] ?? '', 'rows-sub'),
            'rowsFilters' => is_array($a['rowsFilters'] ?? null) ? $a['rowsFilters'] : [],
            'rowsTitle' => (string) ($a['rowsTitle'] ?? ''),
            'states' => self::normalizeStates($a['states'] ?? null),
            'allowEmpty' => $bool($a['allowEmpty'] ?? null, true),
            'days' => $days,
            'week' => (string) ($a['week'] ?? ''),
            'holidays' => self::normalizeHolidays($a['holidays'] ?? null),
            'holidayModel' => trim((string) ($a['holidayModel'] ?? '')),
            'holidayDateField' => $col($a['holidayDateField'] ?? '', 'holiday-date-field'),
            'holidayLabelField' => $col($a['holidayLabelField'] ?? '', 'holiday-label-field'),
            'holidayFilters' => is_array($a['holidayFilters'] ?? null) ? $a['holidayFilters'] : [],
            'holidayLock' => $bool($a['holidayLock'] ?? null, false),
            'readonly' => $bool($a['readonly'] ?? null, false),
            'editableRows' => $editable,
            'copyWeek' => $bool($a['copyWeek'] ?? null, false),
            'totals' => $bool($a['totals'] ?? null, true),
            'legend' => $bool($a['legend'] ?? null, true),
            'emptyText' => (string) ($a['emptyText'] ?? ''),
        ];
    }

    /** @return list<array{value:string,label:string,short:string,tone:string,color:string}> */
    public static function normalizeStates(mixed $states): array
    {
        if (is_string($states) && trim($states) !== '') {
            $decoded = json_decode($states, true);
            $states = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($states) || $states === []) {
            $states = [
                ['value' => 'P', 'label' => mad_t('mad.week_grid.state_onsite'), 'tone' => 'success'],
                ['value' => 'R', 'label' => mad_t('mad.week_grid.state_remote'), 'tone' => 'info'],
                ['value' => 'F', 'label' => mad_t('mad.week_grid.state_off'), 'tone' => 'neutral'],
            ];
        }
        $out = [];
        $seen = [];
        foreach ($states as $k => $s) {
            if (is_scalar($s)) {
                $s = is_string($k) ? ['value' => $k, 'label' => (string) $s] : ['value' => (string) $s];
            }
            if (!is_array($s)) continue;
            $value = trim((string) ($s['value'] ?? ''));
            if ($value === '' || isset($seen[$value])) continue;
            $seen[$value] = true;
            $tone = strtolower(trim((string) ($s['tone'] ?? '')));
            $color = trim((string) ($s['color'] ?? ''));
            if ($color !== '' && !preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch)\([0-9.,%\s\/a-z-]+\)|var\(--[a-z0-9-]+\))$/i', $color)) {
                $color = '';
            }
            $out[] = [
                'value' => $value,
                'label' => (string) ($s['label'] ?? $value),
                'short' => mb_substr((string) ($s['short'] ?? $value), 0, 3),
                'tone' => $color !== '' ? 'custom' : (in_array($tone, self::TONES, true) ? $tone : 'neutral'),
                'color' => $color,
            ];
        }

        return $out;
    }

    /** @return list<array{key:string,label:string,sub:string}>|null */
    public static function normalizeRows(mixed $rows): ?array
    {
        if (!is_array($rows)) return null;
        $out = [];
        foreach ($rows as $k => $r) {
            if (is_array($r) || is_object($r)) {
                $r = (array) $r;
                $key = $r['value'] ?? $r['id'] ?? $r['key'] ?? null;
                if ($key === null || !is_scalar($key)) continue;
                $out[] = ['key' => (string) $key, 'label' => (string) ($r['label'] ?? $r['name'] ?? $key), 'sub' => (string) ($r['sub'] ?? '')];
            } elseif (is_scalar($r)) {
                $out[] = array_is_list($rows)
                    ? ['key' => (string) $r, 'label' => (string) $r, 'sub' => '']
                    : ['key' => (string) $k, 'label' => (string) $r, 'sub' => ''];
            }
        }

        return $out;
    }

    /** @return array<string,string> data => rótulo */
    public static function normalizeHolidays(mixed $h): array
    {
        if (!is_array($h)) return [];
        $out = [];
        foreach ($h as $k => $v) {
            if (is_array($v)) {
                $date = (string) ($v['date'] ?? '');
                $label = (string) ($v['label'] ?? '');
            } elseif (is_string($k) && preg_match('/^\d{4}-\d{2}-\d{2}/', $k)) {
                $date = $k;
                $label = (string) $v;
            } else {
                $date = (string) $v;
                $label = '';
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $out[substr($date, 0, 10)] = $label !== '' ? $label : mad_t('mad.week_grid.holiday');
            }
        }

        return $out;
    }

    // ── Leitura ────────────────────────────────────────────────────────────

    /**
     * Estados e textos da semana, por linha.
     *
     * @return array{0: array<string,array<int,string>>, 1: array<string,string>}
     */
    private function load(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $c = $this->wgConfig;
        if ($c['model'] === '' || $c['rowField'] === '') {
            return [[], []];
        }
        $values = [];
        $notes = [];
        if ($c['dateField'] !== '') {
            if ($c['stateField'] === '') return [[], []];
            $q = $this->dataQuery()
                ->whereDate($c['dateField'], '>=', $start->toDateString())
                ->whereDate($c['dateField'], '<=', $end->toDateString());
            foreach ($q->get([$c['rowField'], $c['dateField'], $c['stateField']]) as $m) {
                $date = CarbonImmutable::parse((string) $m->getRawOriginal($c['dateField']));
                $i = (int) $start->diffInDays($date) + 1;
                $values[(string) $m->getRawOriginal($c['rowField'])][$i] = (string) ($m->getRawOriginal($c['stateField']) ?? '');
            }
        } else {
            if ($c['weekField'] === '') return [[], []];
            $cols = array_merge([$c['rowField']], $c['dayFields'], $c['noteField'] !== '' ? [$c['noteField']] : []);
            $q = $this->dataQuery()->whereDate($c['weekField'], $start->toDateString());
            foreach ($q->get($cols) as $m) {
                $row = (string) $m->getRawOriginal($c['rowField']);
                foreach ($c['dayFields'] as $i => $f) {
                    $values[$row][$i + 1] = (string) ($m->getRawOriginal($f) ?? '');
                }
                if ($c['noteField'] !== '') {
                    $notes[$row] = (string) ($m->getRawOriginal($c['noteField']) ?? '');
                }
            }
        }

        return [$values, $notes];
    }

    /**
     * Linhas exibidas. Sem fonte de linhas, as que têm registro na semana.
     *
     * @param list<int|string> $seen
     * @return list<array{key:string,label:string,sub:string}>
     */
    private function rows(array $seen): array
    {
        $c = $this->wgConfig;
        if (is_array($c['rows'])) {
            return $c['rows'];
        }
        if ($c['rowsModel'] !== '') {
            $q = $this->rowsQuery();
            $model = $q->getModel();
            $key = $model->getKeyName();
            $label = $c['rowsLabel'] !== '' ? $c['rowsLabel'] : $key;
            $cols = array_unique(array_filter([$key, $label, $c['rowsSub']]));
            if (empty($q->getQuery()->orders)) {
                $q->orderBy($label);
            }
            $out = [];
            foreach ($q->limit(500)->get($cols) as $m) {
                $out[] = [
                    'key' => (string) $m->getRawOriginal($key),
                    'label' => (string) ($m->getRawOriginal($label) ?? ''),
                    'sub' => $c['rowsSub'] !== '' ? (string) ($m->getRawOriginal($c['rowsSub']) ?? '') : '',
                ];
            }

            return $out;
        }
        $keys = array_map('strval', $seen);
        natcasesort($keys);

        return array_values(array_map(static fn ($k) => ['key' => $k, 'label' => $k, 'sub' => ''], $keys));
    }

    /** @return array<string,string> */
    private function holidays(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $c = $this->wgConfig;
        $out = $c['holidays'];
        if ($c['holidayModel'] !== '' && $c['holidayDateField'] !== '') {
            $cls = self::modelClass($c['holidayModel']);
            /** @var Builder $q */
            $q = $cls::query();
            if ($c['holidayFilters'] !== []) {
                QuerySource::applyArrayFilters($q->getQuery(), $c['holidayFilters']);
            }
            $q->whereDate($c['holidayDateField'], '>=', $start->toDateString())
                ->whereDate($c['holidayDateField'], '<=', $end->toDateString());
            $cols = array_filter([$c['holidayDateField'], $c['holidayLabelField']]);
            foreach ($q->get($cols) as $m) {
                $date = substr((string) $m->getRawOriginal($c['holidayDateField']), 0, 10);
                $label = $c['holidayLabelField'] !== '' ? (string) ($m->getRawOriginal($c['holidayLabelField']) ?? '') : '';
                $out[$date] = $label !== '' ? $label : mad_t('mad.week_grid.holiday');
            }
        }

        return $out;
    }

    // ── Gravação ───────────────────────────────────────────────────────────

    private function guardCell(string $row, string $date, string $value): CarbonImmutable
    {
        $c = $this->wgConfig;
        $this->guardRow($row);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \DomainException(mad_t('mad.week_grid.invalid'));
        }
        $day = CarbonImmutable::parse($date)->startOfDay();
        $index = (int) self::monday($date)->diffInDays($day);
        if ($index >= (int) $c['days']) {
            throw new \DomainException(mad_t('mad.week_grid.invalid'));
        }
        if ($value === '' ? !$c['allowEmpty'] : !in_array($value, array_column($c['states'], 'value'), true)) {
            throw new \DomainException(mad_t('mad.week_grid.invalid'));
        }
        if ($c['holidayLock'] && isset($this->holidays($day, $day)[$day->toDateString()])) {
            throw new \DomainException(mad_t('mad.week_grid.holiday_locked'));
        }

        return $day;
    }

    private function guardRow(string $row): void
    {
        $c = $this->wgConfig;
        if ($c['readonly'] || $c['model'] === '' || $c['rowField'] === '') {
            throw new \DomainException(mad_t('mad.week_grid.readonly'));
        }
        if (!$this->rowEditable($row)) {
            throw new \DomainException(mad_t('mad.week_grid.row_locked'));
        }
        if (is_array($c['rows'])) {
            $ok = in_array($row, array_column($c['rows'], 'key'), true);
        } elseif ($c['rowsModel'] !== '') {
            $ok = $this->rowsQuery()->whereKey($row)->exists();
        } else {
            $ok = $this->dataQuery()->where($c['rowField'], $row)->exists();
        }
        if (!$ok) {
            throw new \DomainException(mad_t('mad.week_grid.row_locked'));
        }
    }

    private function rowEditable(string $row): bool
    {
        $e = $this->wgConfig['editableRows'];

        return !$this->wgConfig['readonly'] && ($e === null || in_array($row, $e, true));
    }

    private function writeCell(string $row, CarbonImmutable $day, string $value): void
    {
        $c = $this->wgConfig;
        if ($c['dateField'] === '') {
            $field = $c['dayFields'][(int) self::monday($day->toDateString())->diffInDays($day)];
            $this->writeWeekRow($row, self::monday($day->toDateString()), [$field => $value === '' ? null : $value]);

            return;
        }
        $rec = $this->dataQuery()->where($c['rowField'], $row)->whereDate($c['dateField'], $day->toDateString())->first();
        if ($value === '') {
            $rec?->delete();

            return;
        }
        if ($rec === null) {
            $rec = $this->newModel();
            $rec->forceFill($c['defaults'] + [$c['rowField'] => $row, $c['dateField'] => $day->toDateString()]);
        }
        $rec->forceFill([$c['stateField'] => $value])->save();
    }

    /** @param array<string,mixed> $set */
    private function writeWeekRow(string $row, CarbonImmutable $monday, array $set): void
    {
        $c = $this->wgConfig;
        $rec = $this->dataQuery()->where($c['rowField'], $row)->whereDate($c['weekField'], $monday->toDateString())->first();
        if ($rec === null) {
            if (array_filter($set, static fn ($v) => $v !== null && $v !== '') === []) {
                return;
            }
            $rec = $this->newModel();
            $rec->forceFill($c['defaults'] + [$c['rowField'] => $row, $c['weekField'] => $monday->toDateString()]);
        }
        $rec->forceFill($set)->save();
    }

    private function saved(string $target): MadResponse
    {
        return (new MadResponse())->attr($target, 'data-wg-saved', (string) time());
    }

    /** Recusa: avisa e redesenha a partir do banco (desfaz o clique otimista). */
    private function refused(\Throwable $e): MadResponse
    {
        $this->forceFullRender();
        $msg = $e instanceof \DomainException
            ? $e->getMessage()
            : MadUserError::message($e, mad_t('mad.week_grid.save_failed'), static::class);

        return MadToast::danger($msg);
    }

    // ── Consultas ──────────────────────────────────────────────────────────

    private function newModel(): Model
    {
        $cls = self::modelClass($this->wgConfig['model']);
        /** @var Model $m */
        $m = new $cls();
        if ($this->wgConfig['database'] !== '') {
            $m->setConnection($this->wgConfig['database']);
        }

        return $m;
    }

    private function dataQuery(): Builder
    {
        $q = $this->newModel()->newQuery();
        if ($this->wgConfig['filters'] !== []) {
            QuerySource::applyArrayFilters($q->getQuery(), $this->wgConfig['filters']);
        }

        return $q;
    }

    private function rowsQuery(): Builder
    {
        $cls = self::modelClass($this->wgConfig['rowsModel']);
        /** @var Builder $q */
        $q = $cls::query();
        if ($this->wgConfig['rowsFilters'] !== []) {
            QuerySource::applyArrayFilters($q->getQuery(), $this->wgConfig['rowsFilters']);
        }

        return $q;
    }

    private static function modelClass(string $model): string
    {
        $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
        if (!is_subclass_of($cls, Model::class)) {
            throw new \InvalidArgumentException("<mad-week-grid>: model {$model} não encontrado.");
        }

        return $cls;
    }

    // ── Utilitários ────────────────────────────────────────────────────────

    public static function monday(string $date): CarbonImmutable
    {
        try {
            $d = $date !== '' ? CarbonImmutable::parse($date) : CarbonImmutable::today();
        } catch (\Throwable) {
            $d = CarbonImmutable::today();
        }

        return $d->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
    }

    private static function column(string $name, string $what): string
    {
        if ($name !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("<mad-week-grid>: {$what} inválido: {$name}");
        }

        return $name;
    }

    private static function method(string $name): string
    {
        $name = trim($name);

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) ? $name : '';
    }

    /** Chave de linha segura dentro de um seletor de atributo. */
    public static function cssKey(string $key): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $key);
    }
}
