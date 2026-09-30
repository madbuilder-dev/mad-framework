<?php
namespace Mad\Chart;

/**
 * MadChartConfig — Objeto de valor imutável produzido por BEChart::toConfig()
 * e consumido pelo componente Blade <mad-chart>.
 *
 * Contém as opções ECharts já serializadas como JSON + metadados de exibição.
 */
class MadChartConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $optionsJson,        // opções ECharts como string JS-safe
        public readonly string $displayValuesJson,  // variável __dv_{name} em JS
        public readonly string $title,
        public readonly string $subtitle,
        public readonly string $width,
        public readonly int    $height,
        public readonly bool   $hasData,
        public readonly bool   $showPanel,
        public readonly ?string $sql = null,         // SQL prepared (bind placeholders)
        public readonly array   $sqlBinds = [],      // bind values
        public readonly ?string $sqlInlined = null,  // SQL with binds inlined (debug only)
        public readonly ?string $sqlError = null,    // execution error captured (if any)
    ) {}
}