<?php
namespace Mad\Http;
use Mad\Component\MadComponent;
use Mad\Component\MadComponentHandler;
use Mad\Grid\MadDataGrid;
use Mad\Ui\MadAction;
use Mad\Ui\MadTransporter;
use Mad\Calendar\MadFullCalendar;
use Mad\Calendar\MadKanban;
use Mad\Calendar\MadGantt;


/**
 * MadResponse — Resposta estruturada para atualizações parciais de tela.
 *
 * Uso em métodos estáticos chamados pelo mad.js (field actions, lookups):
 *
 *   public static function onExitCep()
 *   {
 *       $cep = preg_replace('/\D/', '', $_POST['cep'] ?? '');
 *       $data = json_decode(file_get_contents("https://viacep.com.br/ws/{$cep}/json/"), true);
 *
 *       (new MadResponse)
 *           ->val('#logradouro', $data['logradouro'])
 *           ->val('#bairro',     $data['bairro'])
 *           ->val('#cidade',     $data['localidade'])
 *           ->val('#uf',         $data['uf'])
 *           ->toast('CEP preenchido!', 'success')
 *           ->send();
 *   }
 */
class MadResponse
{
    public array $ops = [];

    // ── Manipulação de DOM ────────────────────────────────────────────────────

    /**
     * Substitui o innerHTML de um elemento.
     * @param string $target Seletor CSS (ex: '#cidade', '.resultado')
     * @param string $html   HTML a injetar
     */
    public function html(string $target, string $html): static
    {
        $this->ops[] = ['op' => 'html', 'target' => $target, 'content' => $html];
        return $this;
    }

    /**
     * Define o value de um input/select/textarea.
     */
    public function val(string $target, string $value, bool $onlyEmpty = false): static
    {
        $op = ['op' => 'val', 'target' => $target, 'content' => $value];
        if ($onlyEmpty) $op['onlyEmpty'] = true;
        $this->ops[] = $op;
        return $this;
    }

    /**
     * Remove uma row do MadDataGrid pelo ID.
     * O $gridClass é usado para escopar quando há múltiplas grids na tela.
     *
     * Uso:
     *   $response->removeRow($id);                       // sem escopo
     *   $response->removeRow($id, static::class);        // com escopo
     */
    public function removeRow(int|string $id, string $gridClass = ''): static
    {
        $prefix = $gridClass ? $gridClass . '_' : '';
        $op = ['op' => 'remove_row', 'rowId' => $prefix . $id];
        if ($gridClass !== '') {
            $op['gridKey'] = str_replace('\\', '_', $gridClass); // ver manageRow()
        }
        $this->ops[] = $op;
        return $this;
    }

    /**
     * Patcha visualmente uma barra do MadGantt sem full re-render.
     *
     * Atualiza apenas a barra com `data-task-id="$id"` no SVG do gantt:
     * recalcula x/w pela nova date range, redesenha label/handles.
     *
     * Uso comum: action onTaskUpdate (drag commit) — o client ja patcheou
     * localmente durante o drag; esta op so confirma server-side e mantem
     * o resto da tela intacto.
     *
     *   $response->ganttPatchTask('analise', '2026-06-01', '2026-06-05');
     *   $response->ganttPatchTask($id, $start, $end, $ganttSelector);
     *
     * @param string $id            ID da tarefa
     * @param string $start         Nova data inicio (Y-m-d)
     * @param string $end           Nova data fim (Y-m-d)
     * @param string $ganttSelector Seletor CSS opcional pra escopar quando
     *                              ha varios gantts na tela (default: '.mad-gantt')
     */
    public function ganttPatchTask(string $id, string $start, string $end, string $ganttSelector = '.mad-gantt'): static
    {
        $this->ops[] = [
            'op'       => 'gantt_patch_task',
            'taskId'   => $id,
            'start'    => $start,
            'end'      => $end,
            'selector' => $ganttSelector,
        ];
        return $this;
    }

    /**
     * Patcha varias barras de uma vez. Util quando uma action move um pai
     * + filhos (cascade).
     *
     *   $response->ganttPatchTasks([
     *       ['id' => 'a', 'start' => '2026-06-01', 'end' => '2026-06-05'],
     *       ['id' => 'b', 'start' => '2026-06-03', 'end' => '2026-06-08'],
     *   ]);
     */
    public function ganttPatchTasks(array $tasks, string $ganttSelector = '.mad-gantt'): static
    {
        $this->ops[] = [
            'op'       => 'gantt_patch_tasks',
            'tasks'    => array_values($tasks),
            'selector' => $ganttSelector,
        ];
        return $this;
    }

    /**
     * Insere ou atualiza UMA tarefa no gantt sem reload do componente.
     * O gantt mescla no array `tasks` (por id), recomputa geometria e
     * re-desenha internamente (barra + dependencias ligadas re-roteiam
     * sozinhas). Preserva scroll, zoom, filtro e estado de expand.
     *
     * $task deve estar no shape do JS:
     *   ['id'=>'x','name'=>'..','start'=>'Y-m-d','end'=>'Y-m-d','progress'=>0.6,
     *    'owner'=>'anna','parentId'=>'pai'|null,'phase_id'=>'design',
     *    'code'=>'DSG-01','milestone'=>false]
     *
     *   return (new MadResponse())->closeDrawer()->ganttUpsertTask($jsTask);
     */
    public function ganttUpsertTask(array $task, string $ganttSelector = '.mad-gantt'): static
    {
        $this->ops[] = [
            'op'       => 'gantt_upsert_task',
            'task'     => $task,
            'selector' => $ganttSelector,
        ];
        return $this;
    }

    /**
     * Remove UMA tarefa (e suas dependencias) do gantt sem reload.
     * Filhos opcionalmente removidos passando-os em $alsoRemove.
     *
     *   return (new MadResponse())->closeDrawer()->ganttRemoveTask('x', ['filho1','filho2']);
     */
    public function ganttRemoveTask(string $id, array $alsoRemove = [], string $ganttSelector = '.mad-gantt'): static
    {
        $this->ops[] = [
            'op'       => 'gantt_remove_task',
            'taskId'   => $id,
            'alsoRemove' => array_values($alsoRemove),
            'selector' => $ganttSelector,
        ];
        return $this;
    }

    /**
     * Insere/atualiza uma barra a partir de um model — sem hand-escrever o
     * shape JS. Mapeia o record via MadGantt::recordToTask() (mesmo mapper do
     * render) e delega pra ganttUpsertTask().
     *
     * $map (role => coluna) e OPCIONAL: vazio usa a convencao (defaultTaskMap:
     * id/name/start/end/progress/parent_id/owner_id/code/milestone/phase_id).
     * Passe um map inline so quando as colunas do model fugirem da convencao.
     *
     *   return (new MadResponse())
     *       ->toast('Salvo', 'success')->closeDrawer()
     *       ->ganttUpsertRecord($task);                  // colunas convencionais
     *   // ou: ->ganttUpsertRecord($t, ['name'=>'nome','start'=>'dt_inicio',...]);
     *
     * @param object $record        model salvo
     * @param array  $map           role => coluna (ver MadGantt::recordToTask)
     * @param string $ganttSelector escopo quando ha varios gantts na tela
     */
    public function ganttUpsertRecord(object $record, array $map = [], string $ganttSelector = '.mad-gantt'): static
    {
        return $this->ganttUpsertTask(self::resolveTaskMap($record, $map), $ganttSelector);
    }

    /**
     * Patcha datas de uma barra a partir de um model (so id/start/end).
     * Util no onTaskUpdate apos persistir o drag/resize.
     *
     *   return (new MadResponse())->ganttPatchRecord($task);
     */
    public function ganttPatchRecord(object $record, array $map = [], string $ganttSelector = '.mad-gantt'): static
    {
        $t = self::resolveTaskMap($record, $map);
        return $this->ganttPatchTask(
            (string) ($t['id'] ?? ''),
            (string) ($t['start'] ?? ''),
            (string) ($t['end'] ?? ''),
            $ganttSelector
        );
    }

    /** Converte record->shape JS (map vazio → convencao defaultTaskMap). */
    private static function resolveTaskMap(object $record, array $map): array
    {
        return MadGantt::recordToTask($record, $map);
    }

    /**
     * Recarrega os itens de autocomplete de um campo.
     * O $var deve corresponder ao data-mad-autocomplete="var" do input.
     *
     * Uso:
     *   $response->reloadCompletion('methods', ['onSave', 'onEdit', 'onDelete']);
     */
    public function reloadCompletion(string $var, array $items): static
    {
        $this->ops[] = [
            'op'    => 'reload_completion',
            'var'   => $var,
            'items' => array_values($items),
        ];
        return $this;
    }

    /**
     * Recarrega as options de um combo (mad-select-field / mad-dbcombo-field /
     * select nativo) sem precisar renderizar HTML server-side. Basta passar o
     * `name` do campo — o JS localiza o <select> pelo atributo name.
     *
     * Uso:
     *   $response->reloadCombo('unit_id', ['1' => 'Matriz', '2' => 'Filial']);
     *   $response->reloadCombo('cidade_id', $cidades, selected: '42', placeholder: 'Selecione...');
     *
     * @param string      $name        Nome do campo (ex: 'unit_id')
     * @param array       $items       Mapa associativo [value => label]
     * @param string|null $selected    Valor a pré-selecionar (opcional)
     * @param string|null $placeholder Texto da option vazia (opcional)
     */
    public function reloadCombo(string $name, array $items, ?string $selected = null, ?string $placeholder = null): static
    {
        $normalized = [];
        foreach ($items as $key => $label) {
            $normalized[] = ['value' => (string) $key, 'label' => (string) $label];
        }
        $this->ops[] = [
            'op'          => 'reload_combo',
            'name'        => $name,
            'items'       => $normalized,
            'selected'    => $selected,
            'placeholder' => $placeholder,
        ];
        return $this;
    }

    /**
     * ACRESCENTA uma option a um combo e (por padrão) a seleciona — sem recarregar
     * a lista inteira como o reloadCombo(). É o retorno natural de "cadastrei um
     * registro novo, coloca ele aqui selecionado":
     *
     *   $response->addComboOption('pais_id', (string) $pais->id, $pais->nome);
     *
     * Em combo de seleção múltipla a option é SOMADA à seleção atual (nada é
     * desmarcado).
     *
     * @param string $name   Nome do campo (o JS também casa com `name="X[]"`).
     * @param string $value  Valor da option.
     * @param string $label  Texto exibido.
     * @param bool   $select Selecionar depois de inserir.
     * @param string $scope  `mad-id` do componente onde procurar o campo antes de
     *                       cair na busca global — evita acertar um `<select>`
     *                       homônimo de outra tela aberta.
     */
    public function addComboOption(string $name, string $value, string $label, bool $select = true, string $scope = ''): static
    {
        $this->ops[] = [
            'op'     => 'combo_add_option',
            'name'   => $name,
            'value'  => $value,
            'label'  => $label,
            'select' => $select,
            'scope'  => $scope,
        ];
        return $this;
    }

    /**
     * Recarrega as options de um `<mad-radio-field>` / `<mad-dbradio-field>`.
     *
     *   $response->reloadRadio('tipo', ['A' => 'Ativo', 'I' => 'Inativo'], selected: 'A');
     */
    public function reloadRadio(string $name, array $items, ?string $selected = null): static
    {
        $normalized = [];
        foreach ($items as $key => $label) {
            $normalized[] = ['value' => (string) $key, 'label' => (string) $label];
        }
        $this->ops[] = [
            'op'       => 'reload_radio',
            'name'     => $name,
            'items'    => $normalized,
            'selected' => $selected,
        ];
        return $this;
    }

    /**
     * Recarrega as options de um `<mad-checkbox-group-field>` / `<mad-dbcheckbox-group-field>`.
     *
     *   $response->reloadCheckboxGroup('perms', ['read'=>'Ler','write'=>'Escrever'], selected: ['read']);
     */
    public function reloadCheckboxGroup(string $name, array $items, array $selected = []): static
    {
        $normalized = [];
        foreach ($items as $key => $label) {
            $normalized[] = ['value' => (string) $key, 'label' => (string) $label];
        }
        $this->ops[] = [
            'op'       => 'reload_checkbox_group',
            'name'     => $name,
            'items'    => $normalized,
            'selected' => array_map('strval', $selected),
        ];
        return $this;
    }

    /**
     * Recarrega as options de um `<mad-multi-entry-field>`.
     *
     *   $response->reloadMultiEntry('tags', ['pro'=>'Pro','free'=>'Free'], selected: ['pro']);
     */
    public function reloadMultiEntry(string $name, array $items, array $selected = []): static
    {
        $normalized = [];
        foreach ($items as $key => $label) {
            $normalized[] = ['value' => (string) $key, 'label' => (string) $label];
        }
        $this->ops[] = [
            'op'       => 'reload_multi_entry',
            'name'     => $name,
            'items'    => $normalized,
            'selected' => array_map('strval', $selected),
        ];
        return $this;
    }

    /**
     * Recarrega os items de um `<mad-sort-list-field>`. A ordem do array `selected`
     * define a ordem inicial; itens não listados entram no fim.
     *
     *   $response->reloadSortList('ordem', ['id'=>'Codigo','nome'=>'Nome'], selected: ['nome','id']);
     */
    public function reloadSortList(string $name, array $items, array $selected = []): static
    {
        $normalized = [];
        foreach ($items as $key => $label) {
            $normalized[] = ['value' => (string) $key, 'label' => (string) $label];
        }
        $this->ops[] = [
            'op'       => 'reload_sort_list',
            'name'     => $name,
            'items'    => $normalized,
            'selected' => array_map('strval', $selected),
        ];
        return $this;
    }

    /**
     * Recarrega os items de um `<mad-checklist-field>` / `<mad-dbchecklist-field>`.
     *
     * Os items devem ser arrays associativos compatíveis com as colunas declaradas
     * no render (mesmo formato que o model->toArray() geraria). Transforms
     * server-side NÃO são reaplicados — se o checklist usa `transform` em colunas,
     * prefira um full re-render via energize/navigate.
     *
     *   $response->reloadChecklist('grupos', $rows, selected: ['1','3']);
     */
    public function reloadChecklist(string $name, array $items, array $selected = []): static
    {
        $this->ops[] = [
            'op'       => 'reload_checklist',
            'name'     => $name,
            'items'    => array_values($items),
            'selected' => array_map('strval', $selected),
        ];
        return $this;
    }

    /**
     * Define um atributo HTML num elemento.
     */
    public function attr(string $target, string $attribute, string $value): static
    {
        $this->ops[] = ['op' => 'attr', 'target' => $target, 'attr' => $attribute, 'content' => $value];
        return $this;
    }

    /**
     * Adiciona ou remove uma classe CSS.
     */
    public function addClass(string $target, string $class): static
    {
        $this->ops[] = ['op' => 'addClass', 'target' => $target, 'content' => $class];
        return $this;
    }

    public function removeClass(string $target, string $class): static
    {
        $this->ops[] = ['op' => 'removeClass', 'target' => $target, 'content' => $class];
        return $this;
    }

    /**
     * Mostra ou esconde um elemento (display).
     */
    public function show(string $target): static
    {
        $this->ops[] = ['op' => 'show', 'target' => $target];
        return $this;
    }

    public function hide(string $target): static
    {
        $this->ops[] = ['op' => 'hide', 'target' => $target];
        return $this;
    }

    /**
     * Põe o cursor num campo do formulário, pelo `name`.
     *
     *   return (new MadResponse())->focus('cpf');
     *
     * O campo é procurado dentro da tela que respondeu — numa cortina ou
     * janela, não acerta o campo homônimo da listagem por trás — e o cliente
     * espera ele existir e estar visível (tela ainda abrindo). Na ABERTURA da
     * tela (mount()/onEdit(), que não devolvem resposta) use
     * `$this->form->focus('cpf')`.
     */
    public function focus(string $field): static
    {
        $this->ops[] = ['op' => 'focus', 'name' => $field];
        return $this;
    }

    // ── Erros de campo ────────────────────────────────────────────────────────

    /**
     * Exibe uma mensagem de erro num campo do formulário.
     * Requer que o campo tenha <p data-field-error="campo"> no DOM (gerado por mad-input-field etc.).
     */
    public function fieldError(string $field, string $message): static
    {
        $slot = self::errorSlotSelector($field);
        $this->ops[] = ['op' => 'html',        'target' => $slot,                       'content' => htmlspecialchars($message, ENT_QUOTES)];
        $this->ops[] = ['op' => 'addClass',    'target' => $slot,                       'content' => 'mad-error'];
        $this->ops[] = ['op' => 'addClass',    'target' => self::inputSelector($field), 'content' => 'mad-input-error'];
        return $this;
    }

    /**
     * Exibe erro num campo DENTRO de um detail-form (scoped ao container).
     * Evita conflito com campos de mesmo nome no formulário master.
     */
    public function dfFieldError(string $dfName, string $field, string $message): static
    {
        $this->ops[] = [
            'op'      => 'df_field_error',
            'target'  => $dfName,
            'field'   => $field,
            'message' => htmlspecialchars($message, ENT_QUOTES),
        ];
        return $this;
    }

    /**
     * Limpa o erro de um campo.
     */
    public function clearFieldError(string $field): static
    {
        $slot = self::errorSlotSelector($field);
        $this->ops[] = ['op' => 'html',        'target' => $slot,                       'content' => ''];
        $this->ops[] = ['op' => 'removeClass', 'target' => $slot,                       'content' => 'mad-error'];
        $this->ops[] = ['op' => 'removeClass', 'target' => self::inputSelector($field), 'content' => 'mad-input-error'];
        return $this;
    }

    /**
     * Seletor do slot de erro de um campo MASTER.
     *
     * :not([data-df-fields] *) exclui os campos do sub-form de detail-form:
     * querySelector pega o PRIMEIRO match do documento, então um detail-form
     * com campo de mesmo name antes do campo master no DOM roubaria o erro.
     * Erro dentro do detail-form é responsabilidade de dfFieldError().
     */
    private static function errorSlotSelector(string $field): string
    {
        return '[data-field-error="'.$field.'"]:not([data-df-fields] *)';
    }

    /**
     * Seletor do input de um campo.
     *
     * O id renderizado pelos componentes é "mad_<campo>_<rand>", NÃO "<campo>" —
     * então "#campo" só acerta quando o id foi passado à mão. O fallback por
     * [name] pega o input real em qualquer componente. Campos do sub-form de
     * detail-form são excluídos (ver errorSlotSelector).
     */
    private static function inputSelector(string $field): string
    {
        return '#'.$field.':not([data-df-fields] *), [name="'.$field.'"]:not([data-df-fields] *)';
    }

    // ── Recarregamento de seções ──────────────────────────────────────────────

    /**
     * Recarrega um trecho da tela chamando um método estático PHP.
     * O HTML retornado substitui o conteúdo de $target.
     *
     * @param string $target      Seletor CSS do elemento a atualizar
     * @param string $classMethod Ex: 'PedidoForm@renderItens'
     * @param array  $params      Parâmetros extras a enviar
     */
    public function reload(string $target, string $classMethod, array $params = []): static
    {
        [$class, $method] = explode('@', $classMethod, 2);
        $this->ops[] = [
            'op'     => 'reload',
            'target' => $target,
            'class'  => $class,
            'method' => $method,
            'params' => $params,
        ];
        return $this;
    }

    // ── Feedback ao usuário ───────────────────────────────────────────────────

    /**
     * Exibe um toast no cliente.
     * @param string $type  info | success | warning | danger
     */
    public function toast(string $message, string $type = 'info', string $title = '', string $position = 'top-right'): static
    {
        $this->ops[] = [
            'op'       => 'toast',
            'message'  => $message,
            'type'     => $type,
            'title'    => $title,
            'position' => $position,
        ];
        return $this;
    }

    /**
     * Exibe um diálogo de alerta (info/warning/error).
     */
    public function alert(string $message, string $type = 'info', string $title = ''): static
    {
        $this->ops[] = [
            'op'      => 'alert',
            'message' => $message,
            'type'    => $type,
            'title'   => $title,
        ];
        return $this;
    }

    // ── Navegação ─────────────────────────────────────────────────────────────

    /**
     * Navega (full page) para uma CLASSE do sistema. Nativo: emite o op
     * 'redirect' que o Mad.applyOps aplica via window.location, reescrevendo
     * pra rota /app/* no modo web (MadWebRoute). 100% MAD — nunca legado.
     *
     *   return (new MadResponse())->redirect('WelcomeView');
     *   return (new MadResponse())->redirect('PedidoForm@onEdit', ['id' => 5]);
     *
     * Aceita também uma URL pronta (`redirect(url('/app/docs/x/' . $id))`,
     * `redirect('/app/x')`): nome de classe nunca tem `/` nem `?`, então
     * qualquer um dos dois delega para redirectUrl(), com `$params` na query.
     * Antes a URL ia para o urlFor() como se fosse a classe e saía
     * `/app/` + a URL inteira — 404 calado.
     *
     * @param string $classMethod  'Classe', 'Classe@metodo' ou uma URL
     * @param array  $params       querystring extra
     * @param int    $delayMs      atraso antes de navegar (ms) — 0 = imediato
     */
    public function redirect(string $classMethod, array $params = [], int $delayMs = 0): static
    {
        if (strpbrk($classMethod, '/?') !== false) {
            return $this->redirectUrl(self::appendQuery($classMethod, $params), $delayMs);
        }

        $parts  = explode('@', $classMethod, 2);
        $class  = $parts[0];
        $method = $parts[1] ?? '';

        // Assa a rota amigável no servidor — o op 'redirect' vai com a URL
        // /app/slug pronta (sem depender do mapa de rotas no client, removido
        // por segurança).
        $url = \Mad\Routing\MadRoutes::urlFor($class, $method, $params);

        return $this->redirectUrl($url, $delayMs);
    }

    /** Acrescenta `$params` à query de `$url`, antes do `#fragmento`. */
    private static function appendQuery(string $url, array $params): string
    {
        $qs = http_build_query($params);
        if ($qs === '') {
            return $url;
        }
        $frag = '';
        if (($h = strpos($url, '#')) !== false) {
            $frag = substr($url, $h);
            $url  = substr($url, 0, $h);
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . $qs . $frag;
    }

    /**
     * Navega (full page) para uma URL já montada. Nativo e MAD-only: o op
     * 'redirect' é aplicado pelo Mad.applyOps, que reescreve pra /app/* no modo
     * web (via MadWebRoute) e faz window.location. Use quando já se tem a URL
     * (ex.: destino pós-login). Nunca chama nada do legado.
     */
    public function redirectUrl(string $url, int $delayMs = 0): static
    {
        // Assa a rota amigável no servidor (index.php?class=X → /app/slug).
        // O mapa de rotas não é exportado pro client, então o MadWebRoute só
        // faz reescrita GENÉRICA (/app/Classe) — que daria 404 para a rota
        // localizada (ex.: WelcomeView → /app/inicio). toFriendlyUrl é
        // idempotente: URL sem `class=` (externa ou já amigável) passa intacta.
        $url = \Mad\Routing\MadRoutes::toFriendlyUrl($url);

        $op = ['op' => 'redirect', 'url' => $url];
        if ($delayMs > 0) {
            $op['delay'] = $delayMs;
        }
        $this->ops[] = $op;
        return $this;
    }

    /**
     * Emite (echo) os ops como um <script> inline que chama Mad.applyOps —
     * para contextos de dispatch CLÁSSICO (páginas/janelas/ações estáticas que
     * NÃO passam pelo wire e cujo retorno não é serializado). 100% MAD: nunca
     * usa a navegação do runtime legado. Espera o Mad estar pronto.
     *
     *   (new MadResponse())->redirect('LoginForm')->emit();  // pós-logout
     */
    public function emit(): void
    {
        // JSON_HEX_TAG: um `</script>` dentro de um html() não fecha o script.
        $json = json_encode($this->ops, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        // Mad.emitOps recebe o PRÓPRIO <script> (document.currentScript): na
        // abertura de uma tela em gaveta/modal os ops miram o componente que
        // vem depois dele no fragmento — não a página de fundo. Mad antigo
        // (sem emitOps) cai no applyOps de sempre.
        echo '<script>(function(s){var o=' . $json . ';function go(){'
           . 'if(window.Mad&&Mad.emitOps){Mad.emitOps(o,s);}'
           . 'else if(window.Mad&&Mad.applyOps){Mad.applyOps(o);}'
           . 'else{setTimeout(go,30);}}go();})(document.currentScript);</script>';
    }

    /**
     * Recarrega os eventos de um MadFullCalendar pelo ID do container.
     */
    /**
     * Recarrega os eventos de um MadFullCalendar pelo ID do container.
     */
    public function refetchCalendar(string $calendarId): static
    {
        return $this->script(
            "(function(){var el=document.getElementById('{$calendarId}');if(el&&window.Alpine){var d=Alpine.\$data(el);if(d&&d.refetchEvents)d.refetchEvents();}})()"
        );
    }

    /**
     * Executa JavaScript arbitrário no cliente. Use com cuidado.
     */
    public function script(string $js): static
    {
        $this->ops[] = ['op' => 'script', 'content' => $js];
        return $this;
    }

    /**
     * Abre um drawer por nome.
     */
    public function openDrawer(string $name): static
    {
        return $this->script("window.dispatchEvent(new CustomEvent('maddrawer',{detail:{name:'{$name}',action:'open'}}))");
    }

    /**
     * Abre um modal por nome.
     */
    public function openModal(string $name): static
    {
        return $this->script("window.dispatchEvent(new CustomEvent('madmodal',{detail:{name:'{$name}',action:'open'}}))");
    }

    /**
     * Fecha o drawer/modal do componente atual.
     * Se $name for informado, fecha por nome; senão, fecha pelo mad_id do componente.
     */
    public function closeDrawer(string $name = ''): static
    {
        if ($name !== '') {
            return $this->script("window.dispatchEvent(new CustomEvent('maddrawer',{detail:{name:'{$name}',action:'close'}}))");
        }
        $this->ops[] = ['op' => 'close_overlay', 'type' => 'drawer'];
        return $this;
    }

    public function closeModal(string $name = ''): static
    {
        if ($name !== '') {
            return $this->script("window.dispatchEvent(new CustomEvent('madmodal',{detail:{name:'{$name}',action:'close'}}))");
        }
        $this->ops[] = ['op' => 'close_overlay', 'type' => 'modal'];
        return $this;
    }

    /**
     * Recarrega a TELA-MÃE de uma gaveta/modal — ou as telas nomeadas — que
     * estiverem abertas na página.
     *
     *   // o item salvo na gaveta muda o total mostrado na tela da negociação
     *   return (new MadResponse())->toast('Salvo!', 'success')->closeDrawer()
     *       ->refreshScreen('NegociacaoFormView');
     *   // sem nome: as telas por trás da gaveta/modal desta ação
     *   return (new MadResponse())->closeDrawer()->refreshScreen();
     *
     * Cada tela encontrada (a principal, uma sub-tela embutida com
     * <mad-transporter> ou outra camada aberta por baixo) é redesenhada pelo
     * servidor com o estado que tem — relê o banco, como o `$refresh`; a que
     * embute outra redesenha as embutidas junto. Tela que não está aberta não é
     * tocada: quando abrir, já vem atualizada. A própria tela que responde nunca
     * é recarregada por aqui (use o retorno da ação para ela).
     *
     * @param string ...$screens Classes das telas (ex.: 'NegociacaoFormView'); vazio = as de trás
     */
    public function refreshScreen(string ...$screens): static
    {
        $this->ops[] = [
            'op'      => 'refresh_screen',
            'screens' => array_values(array_filter(array_map(
                fn (string $s) => ltrim(trim($s), '\\'),
                $screens,
            ), fn (string $s) => $s !== '')),
        ];
        return $this;
    }

    /**
     * Recarrega um MadTransporter por nome, opcionalmente com parâmetros extras.
     * Inspirado no Star Trek: "Energize!" — comando do Scotty ao ativar o transporter.
     */
    public function energize(string $name, array $params = []): static
    {
        $paramsJs = !empty($params) ? json_encode($params) : '{}';
        return $this->script("Mad.energize('{$name}', {$paramsJs})");
    }

    /**
     * Teletransporta (renderiza server-side) um MadComponent para dentro de um
     * seletor no DOM — sem round-trip client. Versao "sincrona" do energize().
     *
     *   $resp->teleport('#painel', DocumentDetail::class, 'onShow', ['id' => 42]);
     *   $resp->teleport('#painel', DocumentDetail::class);   // so mount
     *
     * Fluxo: new Class() → mount($params) → _resolveAndCall($method, $params) se method
     * nao for vazio → _renderWrapped() → html($selector, $html).
     *
     * O $method recebe args resolvidos por reflection a partir de $params (mesma
     * logica do MadWire — parametros do metodo sao buscados por nome no array).
     *
     * @param string $selector Seletor CSS do container de destino
     * @param string $class    Classe do MadComponent
     * @param string $method   Metodo opcional a chamar apos mount (ex: 'onShow', 'onEdit')
     * @param array  $params   Params passados para mount() e resolvidos no method
     */
    public function teleport(string $selector, string $class, string $method = '', array $params = []): static
    {
        /** @var \Mad\Component\MadComponent $comp */
        $comp = new $class();
        $comp->mount($params);
        if ($method !== '' && method_exists($comp, $method)) {
            $comp->_resolveAndCall($method, $params);
        }
        return $this->html($selector, $comp->_renderWrapped());
    }

    // ── Abertura inteligente de tela ────────────────────────────────────

    /**
     * Abre um MadComponent detectando automaticamente o wrapper.
     *
     * - DRAWER/MODAL → Mad.overlay() (abre por cima, sem substituir a tela atual)
     * - INTERNAL     → Mad.load()   (navega para a tela)
     *
     *   return MadResponse::open('DocProdutoForm', ['id' => $id]);
     *   return MadResponse::open('DocProdutoForm');                // sem params
     *   return MadResponse::open('DocProdutoForm', ['id' => 5], 'onEdit'); // método custom
     *
     * @param string $class   Nome da classe MadComponent
     * @param array  $params  Parâmetros a passar para mount/method
     * @param string $method  Método a chamar (padrão: 'show')
     */
    public static function open(string $class, array $params = [], string $method = 'show'): static
    {
        $response = new static();
        $action   = MadAction::to($class, $method, $params);

        return $response->script($action->jsAuto());
    }

    // ── Envio ─────────────────────────────────────────────────────────────────

    /**
     * Atualiza um span gerado por @madBind('prop') no componente.
     * Scoped ao wrapper do componente — não afeta outras instâncias na página.
     *
     * Uso no método do componente:
     *   public function incrementar(): MadResponse
     *   {
     *       $this->contador++;
     *       return (new MadResponse)->bind('contador', $this->contador);
     *   }
     */
    public function bind(string $prop, mixed $value): static
    {
        $this->ops[] = [
            'op'      => 'bind',
            'prop'    => $prop,
            'content' => htmlspecialchars((string) $value, ENT_QUOTES),
        ];
        return $this;
    }

    /**
     * Instrui o MadDetailForm a inserir/atualizar uma row na listagem.
     *
     * @param string $detailName  Nome do detail-form (atributo name="" do <mad-detail-form>)
     * @param array  $row         Dados da row (já validados/enriquecidos pelo backend)
     * @param int    $editIndex   -1 = nova row, >= 0 = atualizar row no índice
     */
    public function dfAdd(string $detailName, array $row, int $editIndex = -1): static
    {
        $this->ops[] = [
            'op'        => 'df_add',
            'target'    => $detailName,
            'row'       => $row,
            'editIndex' => $editIndex,
        ];
        return $this;
    }

    /**
     * Instrui o MadDetailForm a remover uma row da listagem.
     *
     * @param string $detailName  Nome do detail-form
     * @param int    $index       Índice da row a remover
     */
    public function dfDelete(string $detailName, int $index): static
    {
        $this->ops[] = [
            'op'     => 'df_delete',
            'target' => $detailName,
            'index'  => $index,
        ];
        return $this;
    }

    /**
     * Preenche as colunas de APRESENTAÇÃO de uma linha já existente no
     * detail-form, identificada pelo `__id` (não pelo índice: o usuário pode
     * ter adicionado/removido linhas no meio do round-trip).
     *
     * São as colunas que só o servidor resolve — caminho de relacionamento
     * (`{produto->nome}`) e template composto (`{a} - {b}`). A linha criada no
     * navegador não tem como montá-las: o cliente não percorre relação.
     *
     * @param string               $detailName Nome do detail-form
     * @param string               $rowId      `__id` da linha a corrigir
     * @param array<string,string> $values     [template => valor resolvido]
     */
    public function dfDisplay(string $detailName, string $rowId, array $values): static
    {
        $this->ops[] = [
            'op'     => 'df_display',
            'target' => $detailName,
            'rowId'  => $rowId,
            'values' => $values,
        ];
        return $this;
    }

    /**
     * Atualiza ou insere uma linha no MadDataGrid sem full reload.
     *
     * - Se a row já existe no DOM (data-row-id), substitui o HTML + highlight.
     * - Se não existe, insere no topo do tbody + highlight.
     *
     * Uso:
     *   return (new MadResponse())
     *       ->toast('Salvo!', 'success')
     *       ->closeDrawer()
     *       ->manageRow($registro->id, MinhaListagem::class);
     */
    public function manageRow(int|string $id, string $gridClass): static
    {
        $rawHtml = MadDataGrid::renderSingleRow($gridClass, $id);
        $prefix  = $gridClass ? $gridClass . '_' : '';

        // renderSingleRow pode retornar rowHtml<!-- CARD_HTML -->cardHtml
        $cardHtml = '';
        if (str_contains($rawHtml, '<!-- CARD_HTML -->')) {
            [$rowHtml, $cardHtml] = explode('<!-- CARD_HTML -->', $rawHtml, 2);
        } else {
            $rowHtml = $rawHtml;
        }

        $op = [
            'op'      => 'manage_row',
            'rowId'   => $prefix . $id,
            'html'    => $rowHtml,
            // Chave do grid alvo (= storageKey do data-grid). Sem ela o mad.js
            // inseria/limpava no PRIMEIRO .mad-dg-body da página — errado em
            // tela com mais de um grid (mestre/detalhe, abas).
            'gridKey' => str_replace('\\', '_', $gridClass),
        ];
        if ($cardHtml !== '') {
            $op['cardHtml'] = $cardHtml;
        }

        $this->ops[] = $op;
        return $this;
    }

    // ── Kanban ops ──────────────────────────────────────────────────────────

    /**
     * Atualiza ou insere um card no MadKanban sem full reload.
     *
     * - Se o card já existe no DOM (data-card-id), substitui o HTML + highlight.
     * - Se não existe, insere no topo do stage correspondente + highlight.
     *
     * Uso:
     *   return (new MadResponse())
     *       ->toast('Salvo!', 'success')
     *       ->closeDrawer()
     *       ->manageCard($registro->id, MeuKanban::class);
     */
    public function manageCard(int|string $cardId, string $kanbanClass, ?MadKanban $instance = null): static
    {
        // Sem cast: PK de texto (UUID/código) viraria 0 e o card não seria achado.
        $html = MadKanban::renderSingleCardStatic($kanbanClass, $cardId, $instance);

        $this->ops[] = [
            'op'     => 'manage_card',
            'cardId' => (string) $cardId,
            'html'   => $html,
        ];
        return $this;
    }

    /**
     * Remove um card do MadKanban pelo ID.
     */
    public function removeCard(int|string $cardId): static
    {
        $this->ops[] = [
            'op'     => 'remove_card',
            'cardId' => (string) $cardId,
        ];
        return $this;
    }

    // ── Tree View ops ────────────────────────────────────────────────────────

    /**
     * Adiciona um nó na tree-view sem reload.
     *
     * $nodeData deve conter: id, parent_id (ou null), name/label, icon (opcional), count (opcional).
     *
     * Uso:
     *   return (new MadResponse())
     *       ->treeAddNode('folders', ['id' => $f->id, 'parent_id' => $f->parent_id, 'name' => $f->name])
     *       ->toast('Pasta criada!', 'success');
     */
    public function treeAddNode(string $treeName, array $nodeData): static
    {
        $this->ops[] = ['op' => 'tree_add', 'tree' => $treeName, 'node' => $nodeData];
        return $this;
    }

    /**
     * Remove um nó da tree-view pelo ID, com animação.
     */
    public function treeRemoveNode(string $treeName, int|string $nodeId): static
    {
        $this->ops[] = ['op' => 'tree_remove', 'tree' => $treeName, 'id' => (string) $nodeId];
        return $this;
    }

    /**
     * Atualiza label, icon e/ou count de um nó existente na tree-view.
     *
     * $data pode conter: name/label, icon, count.
     */
    public function treeUpdateNode(string $treeName, int|string $nodeId, array $data): static
    {
        $this->ops[] = ['op' => 'tree_update', 'tree' => $treeName, 'id' => (string) $nodeId, 'data' => $data];
        return $this;
    }

    /**
     * Muda o nó ativo (selecionado) na tree-view, expandindo ancestrais se necessário.
     */
    public function treeSetActive(string $treeName, int|string $nodeId): static
    {
        $this->ops[] = ['op' => 'tree_active', 'tree' => $treeName, 'id' => (string) $nodeId];
        return $this;
    }

    /**
     * Mescla as ops de outro MadResponse neste.
     */
    public function merge(MadResponse $other): static
    {
        $this->ops = array_merge($this->ops, $other->ops);
        return $this;
    }

    /**
     * Retorna o array de operações sem enviar nem encerrar.
     * Usado pelo MadComponentHandler quando o método do componente retorna MadResponse.
     */
    public function getOps(): array
    {
        // Anexa dumps pendentes de mad_dump_modal() / mdm() (se houver) antes de retornar
        \Mad\Util\MadDumpModal::inject($this);
        return $this->ops;
    }

    /**
     * Serializa as operações como JSON e encerra a execução.
     * DEVE ser chamado ao final do método estático.
     */
    /**
     * Op `dump_modal` — debug overlay client-side.
     * Normalmente injetada automaticamente em send() via MadDumpModal::inject().
     * Cada item do array $dumps representa uma chamada de mad_dump_modal() / mdm().
     */
    public function dumpModal(array $dumps, array $meta = []): static
    {
        $this->ops[] = ['op' => 'dump_modal', 'dumps' => $dumps, 'meta' => $meta];
        return $this;
    }

    public function send(): never
    {
        header('Content-Type: application/json; charset=utf-8');

        // Anexa dumps pendentes de mad_dump_modal() / mdm() antes de serializar
        \Mad\Util\MadDumpModal::inject($this);

        $data = $this->ops;

        $debugPayload = \Mad\Service\MadLogService::getDebugPayload();
        if ($debugPayload) {
            $data = [
                'ops'    => $this->ops,
                '_debug' => $debugPayload,
            ];
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Atalho estático para criar e enviar resposta vazia (só toast).
     */
    public static function ok(string $message = 'Operação realizada com sucesso!'): never
    {
        (new static)->toast($message, 'success')->send();
    }

    public static function err(string $message): never
    {
        (new static)->toast($message, 'danger')->send();
    }
}