@php
    // View default de uma tela que É a agenda (extends MadAgenda) sem blade
    // próprio: a config vem do corpo da classe.
    if (!isset($__component) || !$__component instanceof \Mad\Agenda\MadAgenda) {
        return;
    }
@endphp
<div class="mad-agenda-host">{!! $__component->_renderInlineAgenda(['key' => $__component->_classKey()]) !!}</div>
