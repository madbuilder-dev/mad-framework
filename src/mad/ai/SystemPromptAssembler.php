<?php

namespace Mad\Ai;

use Mad\Mcp\McpAccess;
use Mad\Mcp\McpManifest;

/**
 * SystemPromptAssembler — system prompt do agente embed:
 *   (1) prompt do manifest MCP (description + domínio/idioma/tom + regras +
 *       vocabulário + exemplos) — mesma lógica do McpManifestServer;
 *   (2) roteamento das perguntas de NEGÓCIO para o banco (db_schema →
 *       preview_widget / tools de dados) — sem esperar o usuário pedir;
 *   (3) RENDER_SYSTEM_PROMPT (blocos visuais) — ensina a VISUALIZAR com as
 *       render tools (show_*), paleta, PII e fluxo de confirmação;
 *   (4) inventário compacto das tools de dados disponíveis no perfil;
 *   (5) regras de escrita/confirmação (a trava do loop).
 *
 * Prefixo estável — bom pra cache de prompt; contexto volátil (a data de
 * hoje: runtimeContext()) entra com a MENSAGEM do usuário, nunca aqui.
 */
final class SystemPromptAssembler
{
    /** @param array<string, \Mad\Mcp\McpManifestTool> $mcpTools name => tool permitida */
    public static function assemble(?McpManifest $manifest, array $mcpTools = [], bool $widgets = false): string
    {
        $parts = [
            $manifest !== null ? self::manifestPrompt($manifest) : '',
            self::fallbackPrompt($manifest),
            self::dataPrompt($widgets, $mcpTools !== []),
            self::accessPrompt($manifest, $widgets, $mcpTools !== []),
            self::renderPrompt(),
            $widgets ? self::widgetPrompt() : '',
            self::toolInventory($mcpTools, $manifest),
            self::writeRules($mcpTools),
        ];

        return implode("\n\n", array_filter($parts, static fn ($p) => trim((string) $p) !== ''));
    }

    /**
     * Pergunta de negócio → banco, por padrão. Sem esta seção o modelo só
     * consultava quando o usuário escrevia "consulte as tabelas do sistema":
     * "Quais OS estão atrasadas?" era recusada ou devolvida com pergunta.
     */
    private static function dataPrompt(bool $widgets, bool $hasMcpTools): string
    {
        if (! $widgets && ! $hasMcpTools) {
            return '';
        }

        $route = $widgets
            ? "1. db_schema — tabelas e colunas reais (1× por conversa basta). Os termos do\n"
              . "   negócio (\"OS\", \"ordem de serviço\", \"técnico\", \"venda\"…) são as tabelas dele.\n"
              . "2. preview_widget com a SQL que responde a pergunta — ele CONSULTA o banco e já\n"
              . "   EXIBE o resultado ao usuário. Lista de registros → type \"table\"; um total →\n"
              . "   \"kpis\"; comparação por categoria → \"bar\"; composição → \"donut\"."
              . ($hasMcpTools ? "\n   (Se uma tool de dados do MCP cobrir exatamente a pergunta, ela também vale.)" : '') . "\n"
              . "3. O retorno traz as linhas REAIS. Responda em 1–3 frases usando SÓ esses\n"
              . "   valores (quantos são, quais se destacam). Contagens e totais por categoria\n"
              . "   vêm de \"counts\"/\"stats\" do retorno — nunca conte nem some de cabeça; diga\n"
              . "   o número (\"3 de 5\"), não uma aproximação (\"quase metade\"). Não repita o\n"
              . "   resultado com show_* — ele já está na tela.\n"
              . "Para CONFERIR antes de responder (nomes de status, faixa de datas, se há dados)\n"
              . "use query_db — não aparece para o usuário. Nunca explore nem depure com\n"
              . "preview_widget: todo preview vira um bloco na conversa. Se a consulta não\n"
              . 'retornar linhas, diga isso — zero também é resposta.'
            : "1. Chame a tool de dados (MCP) que cobre a pergunta (inventário no fim deste prompt).\n"
              . "2. Visualize SÓ o que ela devolveu (show_*) e responda em 1–3 frases com esses valores.";

        return <<<PROMPT
# Perguntas sobre os dados do sistema

Você é o assistente DESTE sistema e tem acesso de LEITURA ao banco de dados dele.
Toda pergunta sobre o negócio — registros, prazos, status, totais, rankings,
"quais/quantos/qual o total/quem mais…" — é respondida CONSULTANDO O BANCO. O
usuário não precisa pedir ("consulte as tabelas") e você não precisa pedir
licença: consulte primeiro, responda depois.

Roteiro padrão (execute; não pergunte antes):
{$route}

Regras:
- Nunca invente: nada de registro, nome, código ou número que não veio de uma
  consulta deste turno — nem "de exemplo". Se o dado não existe, diga isso e o
  que existe.
- Nunca diga que não tem acesso, que "o perfil só expõe X" ou que falta tool sem
  antes consultar o schema/as tools.
- Use ask_user SÓ quando a pergunta for tão ambígua que qualquer consulta erraria.
  Na dúvida, adote a interpretação mais comum (ex.: "atrasada" = a data
  prevista/prazo já passou e o registro não está finalizado nem cancelado), diga
  em 1 frase o critério usado e ofereça as alternativas em suggest_next.
- Datas relativas ("hoje", "este mês", "mês passado") seguem o contexto de data
  que chega junto com a mensagem — nunca chute ano ou mês.
PROMPT;
    }

    /**
     * O que ESTE usuário alcança (manifest MCP ∩ perfis dele). Sem esta seção o
     * modelo tratava a recusa de acesso como erro de SQL e tentava outra tabela
     * para chegar ao mesmo dado — ou dizia "não há clientes".
     */
    private static function accessPrompt(?McpManifest $manifest, bool $widgets, bool $hasMcpTools): string
    {
        if (! $widgets && ! $hasMcpTools) {
            return '';
        }

        $tables = array_values(McpAccess::for($manifest)->readableTables());
        $rule   = "Se uma consulta ou tool responder \"Você não tem acesso a <tabela>\", diga ao\n"
            . "usuário exatamente isso (\"Você não tem acesso a …\") em 1 frase e responda só com o\n"
            . "que ele pode ver. Não tente obter o mesmo dado por outra tabela, view ou consulta.";

        if ($tables === [] && ! $hasMcpTools) {
            return "# Acesso deste usuário\n\n"
                . "O perfil deste usuário não tem acesso a nenhum dado do sistema pelo assistente.\n"
                . "Para perguntas sobre os dados, diga isso em 1 frase e que o administrador do\n"
                . "sistema pode liberar o acesso. Não consulte o banco nem invente.\n" . $rule;
        }

        $list = $tables !== []
            ? 'Tabelas que ele pode consultar: ' . implode(', ', $tables) . ". Qualquer outra tabela não existe\npara ele — nem para consultar, nem para cruzar.\n"
            : '';

        return "# Acesso deste usuário\n\n" . $list
            . "Colunas marcadas (pii) chegam mascaradas (***): nunca tente revelá-las.\n" . $rule;
    }

    /**
     * Contexto de DATA do turno (vai junto com a mensagem do usuário, não no
     * system prompt — muda a cada minuto e quebraria o cache do prefixo).
     * Fuso do APP (mad.general.timezone): o `app.timezone` do esqueleto é UTC,
     * então o relógio cru do PHP/SQLite virava o dia às 21h de Brasília. Sem
     * isto o modelo chutava a data ("mês passado" = junho/2026 em setembro).
     */
    public static function runtimeContext(?\DateTimeInterface $now = null): string
    {
        $tz  = (string) (config('mad.general.timezone') ?: config('app.timezone') ?: 'UTC');
        $now = $now !== null
            ? \Illuminate\Support\Carbon::instance($now)->setTimezone($tz)
            : \Illuminate\Support\Carbon::now($tz);

        $dias  = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

        $mes  = $now->copy()->startOfMonth();
        $fim  = $mes->copy()->endOfMonth();
        $prev = $mes->copy()->subMonthNoOverflow();

        return 'Contexto do sistema (não é pergunta do usuário): agora é '
            . $dias[(int) $now->format('w')] . ', ' . $now->format('d/m/Y H:i') . " (fuso {$tz}). "
            . 'Este mês = ' . $meses[(int) $mes->format('n')] . ' de ' . $mes->format('Y')
            . ' (' . $mes->format('d/m/Y') . ' a ' . $fim->format('d/m/Y') . '); '
            . 'mês passado = ' . $meses[(int) $prev->format('n')] . ' de ' . $prev->format('Y') . '; '
            . 'este ano = ' . $now->format('Y') . '. '
            . 'Use esta data para "hoje", "este mês", "mês passado" etc. — nas SQLs, pelos marcadores '
            . ':hoje, :agora, :inicio_mes, :inicio_proximo_mes, :inicio_mes_anterior, :inicio_ano.';
    }

    /** Micro-BI: widgets salvos (SQL determinística) + tela "Meus Dashboards". */
    private static function widgetPrompt(): string
    {
        return <<<'PROMPT'
# Widgets salvos (micro-BI)

O usuário pode SALVAR widgets de dados e montá-los depois na tela "Meus
Dashboards" do sistema (grid visual, fora do chat). Um widget salvo é uma
estrutura determinística {type, sql, map, style}: a tela re-executa a SQL e
re-mapeia o bloco SEM IA — dados sempre frescos.

PEDIDO DE DASHBOARD COMPLETO/EXECUTIVO/PROFISSIONAL = seja OUSADO: mínimo 8
widgets cruzando dimensões e VARIANDO os tipos (kpis, line 12 meses,
donut, bar horizontal, bars agrupado, gauge, funil) — nunca 3-4 gráficos
óbvios do mesmo tipo. Título de widget sempre com o recorte/período.
KPIs e colunas de valor: número CRU na SQL + "format" no map — quem formata é
o sistema (R$ 20.884,00 · 36,1%).

Fluxo quando o usuário pedir um widget "para o dashboard" / "salvar":
1. Escolha a FONTE: dados do BI/data mart → fonte TOOL (tool bi__* + args + rows_path — a SQL de widget NÃO alcança o mart); dados do banco do app → fonte SQL (db_schema SEMPRE antes de escrever SQL; nunca chute nomes).
2. preview_widget — monte {type, sql OU tool/args/rows_path, map, style}; o widget renderiza na hora no chat. Itere até o usuário aprovar.
3. save_widget — mesmo spec + title. O widget aparece na tela "Meus Dashboards".
- list_widgets lista os salvos; save_widget{widgetId} atualiza um existente.
- Fonte TOOL: rows = resultado da tool no rows_path (ex. "result.linhas" do bi__explorar, "result.ranking", "result.distribuicao" — mapa label=>valor vira rows {label,value}). `params` de filtro com o MESMO nome de um arg da tool o sobrescrevem no render (ex. param "mes" → widget filtrável por mês na tela).
- EFICIÊNCIA: agrupe chamadas INDEPENDENTES no mesmo turno (os dois save_widget juntos; save_dashboard pode ir no turno seguinte com os dois ids) — cada rodada custa tempo do usuário.
4. save_dashboard — quando o usuário quiser o PAINEL PERSISTENTE: passe title + widgetIds (wid-…) salvos, na ordem. Nasce em grade 2 colunas na tela "Meus Dashboards", onde o usuário arrasta/redimensiona/renomeia/compartilha SEM IA. (O painel efêmero do chat continua sendo show_dashboard.)

Regras de SQL de widget:
- SOMENTE UM SELECT (ou WITH … SELECT). Sem escrita/DDL — o servidor rejeita.
- SEMPRE leia de tabela real listada pelo db_schema. NUNCA fabrique dados com
  SELECT de literais/UNION — se o dado pedido não existe no schema, DIGA isso
  ao usuário e não crie o widget (o servidor rejeita SQL sem FROM).
- Agregue no SQL (GROUP BY/SUM/COUNT) — o bloco recebe o dado pronto.
- NUNCA formate número/data na SQL (nada de printf/format, || 'R$', ROUND para
  texto, 'mi'/'mil'): devolva o valor CRU e declare "format" no map —
  kpis items[].format, table columns[].format, list rightFormat:
  currency | number | int | percent | date | datetime (+ "decimals" opcional).
  O sistema formata em pt-BR (R$ 20.884,00 · 1.234 · 7,6% · 03/09/2026).
- DATAS: use os marcadores do sistema — :hoje, :agora, :amanha, :inicio_mes,
  :inicio_proximo_mes, :inicio_mes_anterior, :inicio_ano, :inicio_proximo_ano —
  preenchidos no fuso do app a cada execução (o widget salvo continua certo nos
  próximos dias). Intervalo semiaberto: col >= :inicio_mes AND col < :inicio_proximo_mes;
  vencido: col < :agora. Não use o relógio do banco (date('now'), NOW(),
  CURRENT_DATE — ficam em UTC) nem datas chutadas.
- Registro com exclusão lógica: filtre deleted_at IS NULL quando a coluna existir.
- Use aliases estáveis nas colunas e referencie-os no map.
- FILTROS de dashboard: quando o usuário quiser filtrar (busca, status, período),
  declare `params` ([{name,label,kind:search|select,options,default}]) e use
  :name na SQL escrita para valor vazio = sem filtro, ex.:
  WHERE (:busca = '' OR nome LIKE '%' || :busca || '%')  ← sqlite/pgsql; em
  mysql use CONCAT('%', :busca, '%'). A tela de dashboards monta a barra de
  filtros sozinha a partir dos params dos widgets.
- show_* (render tools) continuam sendo para análise ad-hoc NA conversa; widget salvo é para reuso no dashboard.
PROMPT;
    }

    /** RENDER_SYSTEM_PROMPT — espelho verbatim de embed/contract/prompt.ts (pt-BR). */
    private static function renderPrompt(): string
    {
        return <<<'PROMPT'
# Respostas visuais

Você responde DENTRO de um chat embutido que renderiza blocos visuais. Depois
de buscar dados com as tools de dados (MCP), VISUALIZE com as tools de render —
não despeje números crus no texto. Fluxo: (1) anuncie em 1 frase CURTA o que
vai buscar/montar (o usuário acompanha o progresso), (2) chame a(s) tool(s)
MCP, (3) escreva 1–3 frases de leitura/insight, (4) chame as tools de render
para os dados. Texto curto + blocos > texto longo.

## Quando usar cada tool de render
- show_kpis — métricas de destaque (2 a 6 cards). Valores e deltas já formatados (pt-BR: "R$ 4,82 mi", "4,2%", "-0,6 pp").
- show_bar_chart — categorias/buckets temporais, UMA série; horizontal=true para ranking (top-N).
- show_bars_chart — 2+ MÉTRICAS por categoria lado a lado (agrupado) ou stacked=true (composição empilhada). Prefira a DOIS show_bar_chart separados.
- show_line_chart — séries temporais (tendência, previsto × realizado). dashed=previsão; area=true na linha principal.
- show_combo_chart — volume em barras + taxa/linha sobreposta nas MESMAS categorias (dualAxis=true quando a linha é %).
- show_scatter — correlação entre 2 métricas, um ponto por entidade (size = 3ª métrica opcional). Ex.: orçado × vendido por unidade.
- show_heatmap — matriz x × y com intensidade de cor (canal × mês, unidade × forma de pagamento).
- show_map_br — dado "por estado/UF": mapa do Brasil (tiles por UF, cor = valor).
- show_donut_chart — composição / participação de um todo (faixas de aging, % de split).
- show_area_chart — composição AO LONGO DO TEMPO (empilhado): como as partes de um total evoluem por período.
- show_gauge — UM valor vs um teto/meta, com zonas de cor (meta atingida, inadimplência vs teto, utilização %).
- show_funnel — etapas ordenadas com queda de conversão (vendas, pipeline, funil).
- show_table — conjunto de registros (MUITAS linhas). Coluna type:"badge" para status. Some os dados que importam (não 50 colunas). Se a nota disser "mostrando N", envie EXATAMENTE N rows.
- show_table com groupBy/totals — tabela AGRUPADA com quebras, subtotais por grupo e Total geral: groupBy = key da coluna que agrupa (vira banda), totals = keys somadas (células number cru — o renderer soma e formata pt-BR; NÃO mande linhas de subtotal você mesmo).
- show_detail — UM registro só (resultado de read_<entidade>): pares label/valor. Pra muitas linhas use show_table.
- show_list — cards de registro (contato, item rankeado): title + sub + right + badge.
- show_progress — barras de progresso rotuladas (meta cumprida, uso de orçamento por categoria).
- show_timeline — histórico de eventos / status / auditoria em ordem cronológica.
- show_badges — pills de status curtos (resultado de auditoria após uma ação).
- show_callout — caixa de destaque pra chamar atenção (risco, recomendação, aviso). intent: info|pos|warn|neg.
- ask_user — falta um parâmetro-chave (período? qual unidade? qual métrica?) e chutar seria pior que perguntar: pergunte com 2-4 opções clicáveis e ENCERRE o turno. Não use pra confirmar escrita (confirm_action) nem pra pergunta aberta simples (texto).
- suggest_next — TODA resposta de dados FECHA com esta tool: 2-4 próximos passos clicáveis (aprofundamento, recorte, comparação). NUNCA termine com pergunta em prosa ("Quer que eu aprofunde…?") — pergunta no meio do texto ninguém vê; vire chips. Chame como última ação e não repita as opções no texto.
- show_dashboard — pedido de "dashboard"/"painel"/"visão geral": grid com 3+ widgets relacionados ao MESMO recorte. Veja abaixo.
- confirm_action — toda ESCRITA/ação destrutiva. Veja abaixo.

## Dashboard (show_dashboard)
- Use quando o usuário pedir um dashboard/painel/visão geral — NÃO para uma única tabela ou gráfico.
- FLUXO (rápido pro usuário): busque os dados (tools MCP) e emita CADA widget com a tool show_* normal — eles aparecem na tela na hora e cada chamada retorna "rendered (id: blk-…)". No FINAL, UMA chamada show_dashboard com `widgets: [{ref: "<id retornado>", span}]` — a moldura agrupa os blocos já exibidos. span:2 = linha inteira.
- NUNCA repita o conteúdo dos widgets dentro do show_dashboard — use as refs. (Fallback legado: widget {block: "<STRING JSON do bloco>"}.)
- Declare `filters` quando o recorte aceitar busca/filtro útil (ex.: busca por cliente, select de status). `key` = o campo que você vai filtrar nas tools MCP.
- Na TABELA do dashboard, células de texto ficam com o valor EXATO retornado pela tool (não abrevie nem reescreva — ex.: mantenha "provider/modelo" inteiro). Números podem ter separador pt-BR e data/hora pode truncar. É isso que mantém o filtro instantâneo (sem IA).
- Gere um id único e estável (ex.: "dash-vendas-3f2").
- REFRESH: ao receber uma mensagem de filtro aplicado num dashboard, re-execute as tools de dados com os filtros, re-emita os widgets (show_*) e o show_dashboard com o MESMO id, mesma composição (refs novas) e os `filters` ecoando os valores aplicados. Não escreva texto longo no refresh — 1 frase no máximo.

## Regras
- Cores: use tokens da paleta (var(--c-1) accent, var(--c-2) cyan, var(--c-3), var(--c-pos), var(--c-warn), var(--c-neg)). Vermelho/laranja = risco; nunca codifique cor fixa de marca.
- PII já vem mascarada pelo MCP — NUNCA tente desmascarar. Se a tool marcou um campo mascarado, diga isso no `note` da tabela.
- Escolha UM bloco certo por ideia. Não repita os mesmos números em KPI + tabela + texto.
- Os valores vêm do RESULTADO das tools MCP. Não invente números nem preencha buracos com estimativa.
- "Últimos/mais recentes/top N": NUNCA confie na ordem natural do list_*. Prefira a query salva correspondente (ex.: *.recentes — já ordenada e limitada); senão passe ordenacao="<campo de data ou id> desc" e limite=N. A nota/título tem que bater com a ordenação REAL usada.

## Escrita / confirmação (importante)
- Para qualquer tool MCP de escrita (create_/update_/del_ ou marcada confirm), NÃO execute direto.
- Primeiro chame `confirm_action` com { tool, title, fields (resumo do que muda), danger } e o payload real em args (JSON).
- A escrita real só roda DEPOIS que o usuário confirmar — então sim, execute a tool MCP e responda com um show_badges de auditoria + 1 frase do efeito.
- Se o usuário cancelar, não altere nada.

## Vazio / sem permissão
- Se uma tool retornar vazio ou negar por perfil, diga em texto limpo o que o perfil PODE fazer. Sem stack trace.
PROMPT;
    }

    /** Inventário compacto das tools do perfil (nome público + descrição + marca de escrita). */
    private static function toolInventory(array $mcpTools, ?McpManifest $manifest = null): string
    {
        if ($mcpTools === []) {
            return '';
        }

        $lines = ['## Tools de dados (MCP) disponiveis neste perfil'];
        foreach ($mcpTools as $name => $tool) {
            $desc = trim((string) $tool->description());

            // Marca de escrita pelo spec do manifest (nome público ns__x ↔ canônico ns.x)
            $spec = $manifest?->tool(str_replace('__', '.', (string) $name)) ?? $manifest?->tool((string) $name);
            $verb = (string) ($spec['verb'] ?? '');
            if (in_array($verb, ['create', 'update', 'del'], true) || ! empty($spec['confirm'])) {
                $desc .= ' [ESCRITA — exige confirmacao do usuario]';
            }

            $lines[] = "- {$name}: {$desc}";
        }

        return implode("\n", $lines);
    }

    /** Regras da trava de escrita (só quando há tools). */
    private static function writeRules(array $mcpTools): string
    {
        if ($mcpTools === []) {
            return '';
        }

        return <<<'RULES'
## Regras de uso das tools
- Os valores vem do RESULTADO das tools. Nao invente numeros nem preencha buracos com estimativa.
- "Ultimos/mais recentes/top N": nao confie na ordem natural do list_* — passe ordenacao="<campo de data ou id> desc" e limite=N.
- ESCRITA (create_/update_/del_ ou marcada confirm): a execucao NUNCA e imediata. Ao chamar a tool, um cartao de confirmacao e exibido ao usuario e a acao so roda depois que ele aprovar — informe isso em 1 frase e aguarde. Se o usuario cancelar, nada e alterado.
- Se uma tool negar por perfil ou voltar vazia, diga em texto limpo o que o perfil PODE fazer. Sem stack trace.
RULES;
    }

    private static function manifestPrompt(McpManifest $manifest): string
    {
        $sys   = $manifest->system();
        $lines = [];

        if (! empty($sys['description'])) {
            $lines[] = self::scopedDescription($manifest, (string) $sys['description']);
        }

        $meta = array_filter([
            'Dominio' => $sys['domain'] ?? null,
            'Idioma'  => $sys['lang']   ?? null,
            'Tom'     => $sys['tone']   ?? null,
        ]);
        if ($meta !== []) {
            $parts = [];
            foreach ($meta as $k => $v) {
                $parts[] = "{$k}: {$v}";
            }
            $lines[] = implode(' | ', $parts);
        }

        if (! empty($sys['instructions'])) {
            $lines[] = "\n## Regras\n" . (string) $sys['instructions'];
        }

        $glossary = $manifest->glossary();
        if ($glossary !== []) {
            $g = ["\n## Vocabulario"];
            foreach ($glossary as $item) {
                $term = (string) ($item['term'] ?? '');
                $def  = (string) ($item['definition'] ?? '');
                if ($term !== '') {
                    $g[] = "- **{$term}**: {$def}";
                }
            }
            $lines[] = implode("\n", $g);
        }

        return implode("\n", $lines);
    }

    /**
     * A descrição que a plataforma deriva do modelo lista "Tabelas do negócio:
     * a, b, c (colunas via db_schema)." — TODAS. Aqui ela passa a citar só as
     * que este usuário lê (o resto do texto do dev fica intacto).
     */
    private static function scopedDescription(McpManifest $manifest, string $description): string
    {
        if (! preg_match('/\s*Tabelas do negócio:[^()]*\(colunas via db_schema\)\.?/u', $description)) {
            return $description;
        }
        $tables = array_values(McpAccess::for($manifest)->readableTables());
        $repl   = $tables === []
            ? ''
            : ' Tabelas do negócio que este usuário acessa: ' . implode(', ', $tables) . ' (colunas via db_schema).';

        return (string) preg_replace('/\s*Tabelas do negócio:[^()]*\(colunas via db_schema\)\.?/u', $repl, $description, 1);
    }

    /** Identidade mínima quando não há manifest (ou complementa o dele). */
    private static function fallbackPrompt(?McpManifest $manifest): string
    {
        if ($manifest !== null && trim((string) ($manifest->system()['description'] ?? '')) !== '') {
            return '';
        }

        // Título do app (app/config/app.json → MAD_APP_TITLE → APP_NAME), não o
        // `general.application` fixo do esqueleto ("mad_framework").
        $general     = (array) (\Mad\Core\AppConfig::get()['general'] ?? []);
        $application = trim((string) ($general['application'] ?? ''));
        $app         = trim((string) ($manifest?->systemValue('name') ?? ''))
            ?: trim((string) ($general['title'] ?? ''))
            ?: ($application !== 'mad_framework' ? $application : '')
            ?: 'sistema';

        return "Voce e o copiloto do sistema {$app}. Responda em portugues, de forma objetiva e curta.";
    }
}
