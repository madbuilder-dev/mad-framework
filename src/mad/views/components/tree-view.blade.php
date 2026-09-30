@php
    $name        = $name        ?? '';
    $model       = $model       ?? '';
    $database    = $database    ?? (defined('MAIN_DATABASE') ? MAIN_DATABASE : 'business');
    $display     = $display     ?? 'nome';
    // Atributo em kebab-case chega ao template em camelCase (o compiler MAD
    // converte `-` -> camel). A leitura aceita as duas formas; sem isto o valor
    // escrito na tag era descartado em silencio e valia sempre o default.
    $key_field   = $keyField   ?? $key_field   ?? 'id';
    $parent_field = $parentField ?? $parent_field ?? 'parent_id';
    $icon        = $icon        ?? 'file';
    $active_icon  = $activeIcon  ?? $active_icon  ?? '';
    $expanded_icon = $expandedIcon ?? $expanded_icon ?? '';
    $icon_field  = $iconField  ?? $icon_field  ?? '';
    $order_by    = $orderBy    ?? $order_by    ?? $display;
    $count_field = $countField ?? $count_field ?? '';
    $filters     = $filters     ?? [];
    $items       = $items       ?? null;
    $active      = $active      ?? '';
    $click       = $click       ?? '';
    $expanded    = $expanded    ?? 'all';
    $persist     = !empty($persist);
    $class       = $class       ?? '';
    $actions_top    = $actionsTop    ?? $actions_top    ?? '';
    $actions_bottom = $actionsBottom ?? $actions_bottom ?? '';
    $context_menu   = $contextMenu   ?? $context_menu   ?? '';

    // ── Build tree data — via model Eloquent ───────────────────────────────
    if ($items === null && $model) {
        try {
            $__m  = \Mad\Form\ModelOptionsLoader::resolveModelClass($model);
            $__qb = $__m::query();
            if (!empty($filters)) {
                \Mad\Database\QuerySource::applyArrayFilters($__qb, $filters);
            }
            $records = \Mad\Database\QuerySource::recordsFromQuery($__qb, $order_by . ' ASC');

            // Build flat map
            $map = [];
            foreach ($records as $r) {
                $id = $r->$key_field;
                $parentId = !empty($r->$parent_field) ? $r->$parent_field : null;

                // Resolve display template
                $label = $display;
                if (strpos($display, '{') !== false) {
                    $label = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($r) {
                        return $r->{$m[1]} ?? '';
                    }, $display);
                } else {
                    $label = $r->$display ?? '';
                }

                $node = [
                    'id'       => $id,
                    'parent_id' => $parentId,
                    'label'    => (string) $label,
                    'icon'     => $icon_field ? ($r->$icon_field ?? $icon) : $icon,
                    'children' => [],
                ];

                if ($count_field && isset($r->$count_field)) {
                    $node['count'] = (int) $r->$count_field;
                }

                $map[$id] = $node;
            }

            // Nest
            $items = [];
            foreach ($map as $id => &$node) {
                if ($node['parent_id'] !== null && isset($map[$node['parent_id']])) {
                    $map[$node['parent_id']]['children'][] = &$node;
                } else {
                    $items[] = &$node;
                }
            }
            unset($node);
        } catch (Throwable $e) {
            $items = [];
        }
    }

    $items = $items ?? [];

    // ── Compute initial expanded IDs ───────────────────────────────────────
    $allIds = [];
    $parentIds = [];
    $collectIds = function ($nodes, $depth = 0) use (&$collectIds, &$allIds, &$parentIds) {
        foreach ($nodes as $n) {
            $allIds[] = (string) $n['id'];
            if (!empty($n['children'])) {
                $parentIds[] = (string) $n['id'];
                $collectIds($n['children'], $depth + 1);
            }
        }
    };
    $collectIds($items);

    $initialExpanded = [];
    if ($expanded === 'all') {
        $initialExpanded = $parentIds;
    } elseif ($expanded === 'first' && !empty($items)) {
        $initialExpanded = [(string) $items[0]['id']];
    }
    // 'none' → empty array

    // ── Config for Alpine ──────────────────────────────────────────────────
    $config = json_encode([
        'name'        => $name,
        'active'      => (string) $active,
        'expanded'    => $initialExpanded,
        'persist'     => $persist,
        'click'       => $click,
        'icon'        => $icon,
        'activeIcon'  => $active_icon,
        'expandedIcon' => $expanded_icon,
    ], JSON_UNESCAPED_UNICODE);

    // ── Recursive render function ──────────────────────────────────────────
    $renderNode = null;
    $renderNode = function ($node, $depth) use (&$renderNode, $icon, $active_icon, $expanded_icon, $count_field, $context_menu, $name, $display) {
        $nid    = htmlspecialchars($node['id']);
        $nlabel = htmlspecialchars($node['label'] ?? $node[$display] ?? $node['name'] ?? '');
        $nicon  = htmlspecialchars($node['icon'] ?? $icon);
        $hasChildren = !empty($node['children']);
        $pad    = 4 + ($depth * 16);
        $count  = isset($node['count']) ? (int) $node['count'] : null;

        // Replace context menu placeholders
        $nodeMenu = '';
        if ($context_menu) {
            $nodeMenu = str_replace(
                ['{id}', '{name}', '{label}'],
                [$nid, $nlabel, $nlabel],
                $context_menu
            );
            // Replace any other {field} from node data
            foreach ($node as $k => $v) {
                if (is_scalar($v)) {
                    $nodeMenu = str_replace('{' . $k . '}', htmlspecialchars((string) $v), $nodeMenu);
                }
            }
        }

        $iconExpr = $nicon;
        if ($active_icon) {
            $iconExpr = "' + (isActive('{$nid}') ? '{$active_icon}' : " . ($expanded_icon && $hasChildren ? "(isExpanded('{$nid}') ? '{$expanded_icon}' : '{$nicon}')" : "'{$nicon}'") . ") + '";
        } elseif ($expanded_icon && $hasChildren) {
            $iconExpr = "' + (isExpanded('{$nid}') ? '{$expanded_icon}' : '{$nicon}') + '";
        }

        $html = '';

        // Node wrapper
        $html .= '<div class="mad-tree-node" data-tree-id="' . $nid . '" data-tree-parent="' . htmlspecialchars($node['parent_id'] ?? '') . '">';

        // Context menu wrapper — output final HTML directly (not Blade component tag,
        // because BladeOne would try to compile it even inside a PHP string).
        if ($nodeMenu) {
            $html .= '<div class="mad-context" x-data="madContextMenu()" @contextmenu.prevent="openAt($event)" @click.outside="close()" @keydown.escape.window="close()">';
        }

        // Node content line
        $html .= '<div class="mad-tree-node-content" style="padding-left:' . $pad . 'px;"';
        $html .= ' @click="select(\'' . $nid . '\')"';
        $html .= ' :class="isActive(\'' . $nid . '\') ? \'active\' : \'\'">';

        // Toggle chevron
        if ($hasChildren) {
            $html .= '<button type="button" class="mad-tree-toggle" @click.stop="toggle(\'' . $nid . '\')">';
            $html .= '<i data-lucide="chevron-right" style="width:12px;height:12px;transition:transform 0.15s ease;"';
            // typeof-guard: no boot/observer o :style pode ser avaliado 1x antes do
            // x-data madTreeView ancestral montar (estrutura aninhada tab-bar>tabs>
            // tree) — sem o guard isso jogava "isExpanded is not defined" no console.
            $html .= ' :style="(typeof isExpanded===\'function\' && isExpanded(\'' . $nid . '\')) ? \'transform:rotate(90deg)\' : \'\'"></i>';
            $html .= '</button>';
        } else {
            $html .= '<span class="mad-tree-toggle-spacer"></span>';
        }

        // Icon — static per initial render, dynamic icons handled by JS setActive/toggle
        $html .= '<i data-lucide="' . $nicon . '" class="mad-tree-icon" style="width:14px;height:14px;flex-shrink:0;"></i>';

        // Label
        $html .= '<span class="mad-tree-label">' . $nlabel . '</span>';

        // Badge count
        if ($count !== null && $count > 0) {
            $html .= '<span class="mad-tree-badge">' . $count . '</span>';
        }

        $html .= '</div>'; // end node-content

        if ($nodeMenu) {
            $html .= '<div class="mad-context-menu" x-show="open" x-cloak x-transition:enter="mad-context-enter" x-transition:enter-start="mad-context-enter-start" x-transition:enter-end="mad-context-enter-end" x-transition:leave="mad-context-leave" x-transition:leave-start="mad-context-leave-start" x-transition:leave-end="mad-context-leave-end" :style="menuStyle()" @click="close()" role="menu">';
            $html .= $nodeMenu;
            $html .= '</div></div>';
        }

        // Children
        if ($hasChildren) {
            $html .= '<div class="mad-tree-children" x-show="typeof isExpanded===\'function\' && isExpanded(\'' . $nid . '\')">';
            foreach ($node['children'] as $child) {
                $html .= $renderNode($child, $depth + 1);
            }
            $html .= '</div>';
        }

        $html .= '</div>'; // end tree-node

        return $html;
    };
@endphp

<div class="mad-tree {{ $class }}" data-mad-tree="{{ $name }}"
     x-data="madTreeView({{ $config }})">

    @if($actions_top)
    <div class="mad-tree-actions mad-tree-actions-top">
        {!! $actions_top !!}
    </div>
    @endif

    <div class="mad-tree-nodes">
        @foreach($items as $item)
            {!! $renderNode($item, 0) !!}
        @endforeach
    </div>

    @if($actions_bottom)
    <div class="mad-tree-actions mad-tree-actions-bottom">
        {!! $actions_bottom !!}
    </div>
    @endif

    {{-- Store context menu template for JS ops --}}
    @if($context_menu)
    <script type="text/mad-tree-menu" data-tree-menu="{{ $name }}" style="display:none;">{!! $context_menu !!}</script>
    @endif
</div>
