<?php
namespace Mad\Component;
use Mad\Form\MadForm;
use Mad\Form\MadFieldListTrait;


/**
 * MadRenderContext — gerencia o stack de contexto de render para componentes aninhados.
 *
 * Durante o render de um MadComponent, o contexto achatado (props escalares +
 * chaves de arrays) é empilhado aqui para que componentes Blade filhos (input-field,
 * date-field, etc.) possam ler o valor atual via MadRenderContext::current().
 *
 * Substitui os métodos estáticos _setContext/_getContext/_clearContext de MadComponent.
 */
class MadRenderContext
{
    /** @var array[] Stack de contextos (suporte a componentes aninhados) */
    private static array $stack = [];

    /** @var object[] Stack de componentes (referência ao MadComponent que está renderizando) */
    private static array $components = [];

    /**
     * Achata as props e empilha o contexto resultante.
     * Retorna o contexto criado para uso imediato no render.
     *
     * @param array       $props     Props do componente (escalares + arrays)
     * @param object|null $component Referência ao MadComponent (para injectIntoForm)
     *
     * Lógica de flatten:
     *   - Props escalares têm prioridade
     *   - Chaves de arrays públicos são incluídas apenas se não colidirem com escalares
     */
    public static function push(array $props, ?object $component = null): array
    {
        $context = [];

        // 1. Escalares com prioridade
        foreach ($props as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                $context[$key] = $value;
            }
        }

        // Todos os nomes de public props — mesmo que a prop seja array, seu nome
        // deve ser considerado "reservado" pelo top-level e nao sobrescrito pelo
        // flatten de outro array. Ex: $this->itens (array, 2 rows) vs.
        // $this->form->fields['itens'] (3 rows cached no mount) — a prop atual
        // ganha, senao o render ve o snapshot antigo do form.
        $topLevelNames = array_keys($props);

        // 2. Arrays — achata sem sobrescrever escalares NEM colidir com outras props
        foreach ($props as $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (array_key_exists($k, $context)) continue;       // ja ha escalar
                    if (in_array($k, $topLevelNames, true)) continue;   // colide com outra prop
                    $context[$k] = $v;
                }
            }
        }

        self::$stack[] = $context;
        self::$components[] = $component;
        return $context;
    }

    /**
     * Remove o contexto do topo do stack (chamado após o render do componente).
     */
    public static function pop(): void
    {
        array_pop(self::$stack);
        array_pop(self::$components);
    }

    /**
     * Retorna o MadComponent atual (topo do stack de componentes).
     * Usado por injectIntoForm() para encontrar o MadForm via reflection.
     */
    public static function getComponent(): ?object
    {
        $comp = end(self::$components);
        return $comp !== false ? $comp : null;
    }

    /**
     * Retorna o contexto atual (topo do stack), ou [] se não há nenhum.
     * Usado pelos blade components de campo para auto-popular valores.
     */
    public static function current(): array
    {
        $top = end(self::$stack);
        return $top !== false ? $top : [];
    }

    /**
     * Consulta se um elemento está marcado como hidden no MadForm atual.
     * Usado pelos Blade templates para aplicar `display:none` no primeiro render
     * quando `onEdit()` já chamou `$form->hide()`.
     *
     * @param string $name  Nome do campo, tab ou ID da row
     * @param string $scope 'field' | 'tab' | 'row'
     */
    public static function isHidden(string $name, string $scope = 'field'): bool
    {
        $form = self::getForm();
        return $form && isset($form->hidden[$name]) && $form->hidden[$name] === $scope;
    }

    /**
     * Consulta se um campo está marcado como read-only no MadForm atual.
     */
    public static function isReadonly(string $name): bool
    {
        $form = self::getForm();
        return $form && !empty($form->readonly[$name]);
    }

    /**
     * Consulta se um botão está marcado como disabled no MadForm atual.
     */
    public static function isDisabled(string $name): bool
    {
        $form = self::getForm();
        return $form && !empty($form->disabled[$name]);
    }

    /**
     * Campo marcado com `autofocus` no Blade: pede o cursor ao MadForm atual.
     * Chamado pelo código compilado da tag (MadBlade::autofocusExpr). Devolve
     * true para a prop continuar valendo como booleana.
     */
    public static function autofocus(string $name): bool
    {
        self::getForm()?->autofocus($name);

        return true;
    }

    /**
     * Retorna o primeiro MadForm público do componente atual.
     * Usado pelos Blade templates para acessar hooks e sourceRecord.
     */
    public static function getForm(): ?MadForm
    {
        $comp = self::getComponent();
        if (!$comp) {
            return null;
        }
        $ref = new \ReflectionObject($comp);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($comp)) continue;
            $val = $prop->getValue($comp);
            if ($val instanceof MadForm) {
                return $val;
            }
        }
        return null;
    }

    /**
     * Injeta um valor diretamente no primeiro MadForm público do componente atual.
     *
     * Usa a mesma técnica de reflection que MadFieldListTrait::_injectIntoForm():
     * encontra o MadForm via reflection no componente e seta o campo diretamente.
     *
     * Usado por componentes Blade que precisam transformar valores durante o render.
     * Ex: file-field com storage="db" detecta base64 bruto, extrai para tmp/,
     * e injeta o path no MadForm para que o estado serializado fique limpo.
     *
     *   \Mad\Component\MadRenderContext::injectIntoForm('conteudo_arquivo', $tmpPath);
     */
    public static function injectIntoForm(string $field, mixed $value): void
    {
        $comp = self::getComponent();
        if (!$comp) {
            return;
        }
        $ref = new \ReflectionObject($comp);
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if (!$prop->isInitialized($comp)) continue;
            $val = $prop->getValue($comp);
            if ($val instanceof MadForm) {
                $val->fields[$field] = $value;
                return;
            }
        }
    }

    /**
     * Carrega IDs selecionados de uma tabela pivot para campos multi-select mode=table.
     * Chamado automaticamente pelos componentes Blade durante o render.
     *
     * Usa o registroId do MadComponent atual (via getComponent()) para saber o ID do registro pai.
     * Retorna array de IDs selecionados, ou vazio se não houver registro ou dados.
     *
     * Com `$field` (o `name` do campo), o MadForm da tela fica sabendo o que a
     * leitura devolveu — é a base do Salvar, que só desmarca o que o campo
     * entregou marcado (ver MadForm::pivotLoaded). Uma leitura que FALHA não é
     * "nada selecionado": o campo abre sem marcas, o motivo vai para o log (e,
     * com APP_DEBUG, para o próprio campo — pivotLoadNotice) e o formulário
     * anota que não entregou marca nenhuma.
     */
    public static function loadPivotSelected(string $pivotModel, string $foreignKey, string $itemKey, string $database = '', string $field = ''): array
    {
        if ($field !== '') {
            unset(self::$pivotNotices[$field]);
        }

        [$recordId, $foreignKey] = self::pivotParent($foreignKey);
        if ($recordId === null) {
            return [];
        }

        $form = $field !== '' ? self::getForm() : null;

        // Registro aberto, mas sem como saber a coluna do pai (foreign-key
        // omitida e o formulário sem o registro): nada é lido — e nada foi
        // entregue como marcado.
        if ($foreignKey === '') {
            $form?->pivotLoaded($field, $recordId, null);

            return [];
        }

        try {
            $selected = self::pivotLinks($pivotModel, $foreignKey, $itemKey, $recordId);
        } catch (\Throwable $e) {
            // ⚠️ Este [] NÃO é inofensivo: o campo abre sem nenhuma marca, igual
            // a "o usuário desmarcou tudo", e o Salvar seguinte apagava todas as
            // ligações do registro. Devolver vazio segue sendo o comportamento
            // (lançar aqui derrubaria o render do formulário inteiro); o que
            // protege é o formulário saber que não entregou marca nenhuma.
            $notice = self::pivotFailure($e, $field, $pivotModel, $foreignKey, $recordId);
            if ($field !== '') {
                self::$pivotNotices[$field] = $notice;
            }
            $form?->pivotLoaded($field, $recordId, null);

            return [];
        }

        $form?->pivotLoaded($field, $recordId, $selected);

        return $selected;
    }

    /**
     * O campo de seleção múltipla em mode=table terminou de montar a tela:
     * `$selected` é a seleção com que ele ficou e `$optionKeys` as chaves das
     * opções que ele desenhou. O que vai para o navegador MARCADO é a
     * interseção — e é isso que o MadForm guarda como entregue (ver
     * MadForm::pivotShown).
     *
     * Chamado pelas views dos campos, depois de carregarem as opções.
     *
     * @param mixed            $selected   array, JSON do MadWire ou lista separada
     * @param iterable<mixed>  $optionKeys chaves das opções desenhadas
     */
    public static function pivotRendered(
        string $field,
        mixed $selected,
        iterable $optionKeys,
        string $pivotModel,
        string $foreignKey,
        string $itemKey,
        string $separator = ','
    ): void {
        $form = $field !== '' ? self::getForm() : null;
        if (!$form) {
            return;
        }

        [$recordId, $foreignKey] = self::pivotParent($foreignKey);
        if ($recordId === null) {
            return;
        }

        $options = [];
        foreach ($optionKeys as $key) {
            if (is_scalar($key)) {
                $options[(string) $key] = true;
            }
        }
        $shown = array_values(array_filter(
            MadForm::selectionKeys($selected, $separator),
            static fn (string $id): bool => isset($options[$id])
        ));

        $form->pivotShown(
            $field,
            $recordId,
            $shown,
            static function () use ($pivotModel, $foreignKey, $itemKey, $recordId): array {
                if ($foreignKey === '') {
                    throw new \RuntimeException('foreign-key da tabela de ligação não identificada');
                }

                return self::pivotLinks($pivotModel, $foreignKey, $itemKey, $recordId);
            }
        );
    }

    /**
     * O registro que a tela tem aberto (null = cadastro novo). A mesma escada
     * do auto-load das pivots (pivotParent).
     */
    public static function recordId(): int|string|null
    {
        return self::pivotParent('-')[0];
    }

    /**
     * Um campo de seleção múltipla gravado na PRÓPRIA coluna (por vírgula)
     * terminou de montar a tela: `$selected` é a seleção com que ele ficou e
     * `$optionKeys` as chaves das opções que ele desenhou. O que vai para o
     * navegador MARCADO é a interseção — e é isso que o MadForm guarda como
     * entregue (ver MadForm::selectionShown). O item da coluna que a lista não
     * mostra não está aí, e o Salvar não o tira da coluna.
     *
     * Chamado pelas views dos campos, depois de carregarem as opções.
     *
     * @param mixed            $selected   array, JSON do MadWire ou lista separada
     * @param iterable<mixed>  $optionKeys chaves das opções desenhadas
     */
    public static function selectionRendered(string $field, mixed $selected, iterable $optionKeys, string $separator = ','): void
    {
        $form = $field !== '' ? self::getForm() : null;
        if (!$form) {
            return;
        }

        $options = [];
        foreach ($optionKeys as $key) {
            if (is_scalar($key)) {
                $options[(string) $key] = true;
            }
        }
        $shown = array_values(array_filter(
            MadForm::selectionKeys($selected, $separator),
            static fn (string $id): bool => isset($options[$id])
        ));

        $form->selectionShown($field, self::recordId(), $shown);
    }

    /**
     * Um checklist que o código da tela grava (`saveChecklist()`; fora do
     * `mode="table"`) terminou de montar a tela: `$selected` é o que ele
     * mostra marcado, `$drawn` as chaves dos itens que desenhou, `$label` o
     * rótulo e `$source` o Model das opções. Ver MadForm::checklistDrawn.
     *
     * @param iterable<mixed> $drawn
     */
    public static function checklistRendered(string $field, mixed $selected, iterable $drawn, string $label = '', mixed $source = null): void
    {
        if ($field !== '') {
            self::getForm()?->checklistDrawn($field, $selected, $drawn, $label, $source);
        }
    }

    /** Uma aba (`<mad-tab>`) foi desenhada com o texto `$label` (ver MadForm::tabRendered). */
    public static function tabRendered(string $tab, string $label): void
    {
        if ($tab !== '') {
            self::getForm()?->tabRendered($tab, $label);
        }
    }

    /** O painel de uma aba foi desenhado com `$html` (ver MadForm::tabPanelRendered). */
    public static function tabPanelRendered(string $tab, string $html): void
    {
        if ($tab !== '' && str_contains($html, 'data-mad-checklist=')) {
            self::getForm()?->tabPanelRendered($tab, $html);
        }
    }

    /**
     * Um campo de arquivo ÚNICO gravado no disco terminou de montar a tela:
     * `$path` é o valor da coluna que ele mostra — o caminho do arquivo, ou ''
     * quando não há arquivo. Ver MadForm::fileShown.
     */
    public static function fileRendered(string $field, mixed $path): void
    {
        $form = $field !== '' ? self::getForm() : null;
        $form?->fileShown($field, self::recordId(), is_scalar($path) ? (string) $path : '');
    }

    /**
     * Os arquivos que um Upload Múltiplo gravado na PRÓPRIA coluna (por
     * vírgula) está mostrando. Ver MadForm::columnFilesShown.
     *
     * @param list<string> $paths
     */
    public static function columnFilesRendered(string $field, array $paths): void
    {
        $form = $field !== '' ? self::getForm() : null;
        $form?->columnFilesShown($field, self::recordId(), $paths);
    }

    /**
     * Aviso de "as marcas não carregaram" do loadPivotSelected() que este campo
     * acabou de chamar — null sem erro ou com APP_DEBUG desligado (mesmo
     * contrato do \Mad\Form\OptionsLoadError). As views o mostram pelo
     * parcial options-error. Lido uma vez: não sobra para o próximo render.
     */
    public static function pivotLoadNotice(string $field): ?string
    {
        $notice = self::$pivotNotices[$field] ?? null;
        unset(self::$pivotNotices[$field]);

        return $notice;
    }

    /** @var array<string, string|null> aviso da última leitura do pivô, por campo */
    private static array $pivotNotices = [];

    /** Registra a falha de leitura do pivô no log e devolve o aviso para o campo (só com APP_DEBUG). */
    private static function pivotFailure(\Throwable $e, string $field, string $pivotModel, string $foreignKey, mixed $recordId): ?string
    {
        $short = \Mad\Form\OptionsLoadError::shortMessage($e);
        $line  = '[mad] campo "' . $field . '" (mode=table): falha ao ler as ligações em ' . $pivotModel
            . ' (fk=' . $foreignKey . ', registro=' . $recordId . ') — o campo abre SEM marcas e o '
            . 'Salvar NAO vai apagar ligações: ' . $short;

        try {
            \Illuminate\Support\Facades\Log::warning($line, ['exception' => $e]);
        } catch (\Throwable $logFailure) {
            @error_log($line);   // sem logger (CLI isolado): ainda deixa rastro
        }

        return \Mad\Support\MadDebug::appDebug() ? 'Erro ao carregar as marcas: ' . $short : null;
    }

    /**
     * O registro aberto e a coluna do pai na tabela de ligação.
     *
     * @return array{0: int|string|null, 1: string} [chave do registro (null = cadastro novo), foreign-key ('' = não identificada)]
     */
    private static function pivotParent(string $foreignKey): array
    {
        $comp = self::getComponent();
        if (!$comp) {
            return [null, $foreignKey];
        }

        // ID do registro pai: a escada mora em MadComponent::_recordId()
        // (registroId legado → recordId dos forms gerados → id → sourceRecord
        // do MadForm). Ela ficou lá porque a permissão de "Salvar" precisa da
        // MESMA resposta para saber se é inclusão ou edição — duas escadas
        // significariam o auto-load e a permissão discordando sobre o que é um
        // "registro aberto".
        $recordId = null;
        if (method_exists($comp, '_recordId')) {
            $recordId = $comp->_recordId();
        } else {
            foreach (['registroId', 'recordId', 'id'] as $prop) {
                if (property_exists($comp, $prop) && !empty($comp->$prop)) {
                    $recordId = $comp->$prop;
                    break;
                }
            }
            if (!$recordId) {
                $src = self::getForm()?->getSourceRecord();
                if ($src && method_exists($src, 'getKey')) {
                    $recordId = $src->getKey();
                }
            }
        }
        if (!$recordId) {
            return [null, $foreignKey];
        }

        // FK do pai omitida no componente → deriva por convenção da tabela do
        // sourceRecord (pessoa → pessoa_id). Espelha o save em
        // MadForm::derivePivotForeignKey — os dois lados têm que concordar.
        if ($foreignKey === '') {
            $foreignKey = MadForm::derivePivotForeignKey(self::getForm()?->getSourceRecord());
        }

        return [$recordId, $foreignKey];
    }

    /**
     * Itens ligados ao registro na tabela de ligação (lança se a leitura falhar).
     *
     * @return list<string>
     */
    private static function pivotLinks(string $pivotModel, string $foreignKey, string $itemKey, int|string $recordId): array
    {
        // Builder-native (100% Query Builder): filtra a pivot pela FK do registro pai.
        $__m   = \Mad\Form\ModelOptionsLoader::resolveModelClass($pivotModel);
        $items = \Mad\Database\QuerySource::recordsFromQuery(
            $__m::query()->where($foreignKey, '=', $recordId)
        );

        $selected = [];
        foreach ($items as $item) {
            $selected[] = (string) $item->$itemKey;
        }

        return $selected;
    }

    /**
     * Retorna o helper $mad() usado nas views Blade para gerar atributos de binding.
     *
     * $mad('campo')           → value="..." mad:model="campo"
     * $mad('campo', 'live')   → value="..." mad:model.live="campo"
     * $mad('campo', 'select') → mad:model="campo"
     * $mad('campo', 'check')  → mad:model="campo" checked?
     */
    public static function madHelper(array $context): \Closure
    {
        return function (string $field, string $mode = '') use ($context): string {
            $value = $context[$field] ?? null;
            return match ($mode) {
                'live'   => 'value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '" mad:model.live="' . $field . '"',
                'check'  => 'mad:model="' . $field . '"' . ($value ? ' checked' : ''),
                'select' => 'mad:model="' . $field . '"',
                default  => 'value="' . htmlspecialchars((string) $value, ENT_QUOTES) . '" mad:model="' . $field . '"',
            };
        };
    }
}