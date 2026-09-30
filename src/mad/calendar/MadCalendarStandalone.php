<?php
namespace Mad\Calendar;

/**
 * MadCalendarStandalone — concretização instanciável do MadCalendarComponent.
 *
 * Usado pelo MadCalendarCompiler quando o `<mad-calendar>` aparece numa view
 * cujo host (`$that`) não é subclass de MadCalendarComponent. Espelha
 * MadGanttStandalone: helper de render inline (config via
 * `_renderInlineCalendar()`); callbacks (on-event-click etc.) são despachados
 * pelo JS para o host da página, não para esta instância.
 *
 * MadCalendarComponent já implementa view(); nada a sobrescrever.
 *
 * @internal Não instanciar diretamente — use o compiler.
 */
class MadCalendarStandalone extends MadCalendarComponent
{
}
