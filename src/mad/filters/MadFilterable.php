<?php
namespace Mad\Filters;


/**
 * Contract surface consumed by the <mad-dash-filters> renderer blades
 * (`components.dash-filters-{toolbar,chips,drawer,modal,sidebar}` and
 * `components.period-monthyear`).
 *
 * Implemented by `MadDashboard` and `MadDataGrid` via `MadFiltersTrait`.
 * Renderers should typecast to `Mad\Filters\MadFilterable` instead of
 * `Mad\Dashboard\MadDashboard` so the wrappers work in any host.
 */
interface MadFilterable
{
    public function activeFiltersCount(array $fields): int;
    public function isFilterActive(array $field): bool;
    public function resolveFilterLabel(array $field): string;
    public function activeFiltersSummary(array $fields): array;
    public function totalActiveFilters(): int;
    public function currentFilters(): array;

    public function clearFilter(string $name): void;
    public function setProp(string $prop, string $value = ''): void;
    public function setMesAno(string $mes = '', string $ano = ''): void;

    public function onShow(): void;
    public function onFiltrar(): void;
    public function onAtualizar(): void;
    public function onRefresh(): void;
    public function onLimpar(): void;

    public function getOpcoesMes(): array;
    public function getOpcoesAno(): array;
    public function getOpcoesPreset(): array;
    public function getUsePresets(): bool;
    public function getPeriodType(): string;
    public function getDateField(): string;
    public function getRememberFilters(): bool;
}
