<?php
namespace Mad\Grid;

/**
 * GridActionGroup — Builder fluent de grupo dropdown de ações para MadDataGrid.
 */
class GridActionGroup
{
    public string $label = '';
    public string $icon  = 'more-horizontal';
    /** @var GridAction[] */
    public array  $actions = [];

    public static function make(string $label): self
    {
        $g = new self();
        $g->label = $label;
        return $g;
    }

    public function icon(string $lucideIcon): self
    {
        $this->icon = $lucideIcon;
        return $this;
    }

    public function add(GridAction $action): self
    {
        $this->actions[] = $action;
        return $this;
    }
}