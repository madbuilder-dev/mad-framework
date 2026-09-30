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
     */
    public static function loadPivotSelected(string $pivotModel, string $foreignKey, string $itemKey, string $database = ''): array
    {
        $comp = self::getComponent();
        if (!$comp) return [];

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
        if (!$recordId) return [];

        // FK do pai omitida no componente → deriva por convenção da tabela do
        // sourceRecord (pessoa → pessoa_id). Espelha o save em
        // MadForm::derivePivotForeignKey — os dois lados têm que concordar.
        if ($foreignKey === '') {
            $foreignKey = MadForm::derivePivotForeignKey(self::getForm()?->getSourceRecord());
            if ($foreignKey === '') return [];
        }

        try {
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
        } catch (\Throwable $e) {
            return [];
        }
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