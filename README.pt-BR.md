<p align="center">
  <a href="https://madbuilder.dev">
    <img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/banner.png" alt="Mad Framework: o runtime Laravel de código aberto por trás de todo app feito no MadBuilder" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/madbuilder/framework"><img alt="Versão" src="https://img.shields.io/packagist/v/madbuilder/framework?style=flat-square&color=1E55E8&label=packagist"></a>
  <a href="https://packagist.org/packages/madbuilder/framework"><img alt="Versão do PHP" src="https://img.shields.io/packagist/dependency-v/madbuilder/framework/php?style=flat-square&color=0E1B3D&label=php"></a>
  <a href="https://laravel.com"><img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white"></a>
  <a href="LICENSE"><img alt="Licença MIT" src="https://img.shields.io/badge/licen%C3%A7a-MIT-3FB8E8?style=flat-square"></a>
  <a href="https://madbuilder.dev"><img alt="Feito para o MadBuilder" src="https://img.shields.io/badge/feito%20para-MadBuilder-F26B1F?style=flat-square"></a>
</p>

<p align="center">
  <a href="README.md">English</a> · <b>Português (Brasil)</b>
</p>

---

O **Mad Framework** é o runtime de todo aplicativo feito no [**MadBuilder**](https://madbuilder.dev), a plataforma low-code com agentes de IA que transforma um modelo de dados, um editor visual e um agente num **projeto Laravel de verdade, que é seu**.

Ele dá ao Blade mais de **250 tags `<mad-*>`**: de um campo de moeda a um kanban completo, gráfico de Gantt, planilha ou frente de caixa. Traz uma **camada reativa que não precisa de build de JavaScript** e a infraestrutura que todo sistema de negócio acaba precisando: permissões por ação, trilha de auditoria, multiempresa, tradução, documentos em PDF, exportação, camada REST, **servidor MCP** e **copiloto de IA embutido**.

Tudo isso roda em **Laravel 13** puro: models Eloquent, controllers, views Blade, rotas e migrations. Não existe um motor proprietário por trás do seu app, e o próprio runtime é MIT.

## Por que o Mad Framework

- **É só Laravel.** Os apps gerados são projetos Laravel padrão, que qualquer dev PHP assume. Abra os arquivos, mude o que quiser, publique onde preferir.
- **Telas em poucas linhas de Blade.** Os componentes conversam direto com o Eloquent. Uma grid com busca, ordenação, filtro por coluna e exportação é uma tag; um formulário mestre-detalhe cabe numa tela.
- **Interface reativa dirigida pelo servidor.** `mad:click`, `mad:model` e `@madBind` ligam a página às propriedades públicas de um componente PHP. O que muda no estado volta sozinho para o navegador; o Alpine.js cuida do resto.
- **Pronto para o negócio desde o primeiro dia.** Controle de acesso com permissões por ação, log de auditoria, multiempresa e unidades, quatro idiomas (português, português europeu, inglês e espanhol), documentos em PDF, exportação para Excel/CSV/PDF, recursos REST e rastreamento de erros.
- **Feito para agentes.** Cada app pode expor o próprio servidor MCP, com ferramentas de cadastro e consulta que respeitam as permissões do usuário, mascaram dados pessoais e registram auditoria. Copiloto de IA e dashboards montados por IA rodam dentro do app.
- **Brasileiro de nascença, aberto para o mundo.** CEP e CNPJ que preenchem o formulário, máscaras de moeda e de documento, e seis bancos: MySQL/MariaDB, PostgreSQL, SQLite, SQL Server, Oracle e Firebird.

## Um gostinho

Uma listagem. Ordenação, filtro por coluna, busca, paginação e exportação vêm com a tag:

```blade
<mad-page-container>
    <mad-page-header title="Clientes" icon="users" breadcrumb="Clientes">
        <actions>
            <mad-btn navigate="ClienteForm" variant="primary" icon="plus">Novo cliente</mad-btn>
        </actions>
    </mad-page-header>

    <mad-page-content>
        <mad-grid self per-page="15" searchable :search-columns="['nome', 'email']">
            <mad-columns>
                <mad-col field="id" label="Cód." width="70" sort />
                <mad-col field="nome" label="Nome" sort filter />
                <mad-col field="{cidade->nome}" label="Cidade" sort />
                <mad-col field="created_at" label="Cliente desde" date="d/m/Y" sort />
            </mad-columns>
            <mad-actions>
                <mad-nav icon="pencil" label="Editar" target="ClienteForm::onEdit({id})" />
                <mad-act method="onExcluir" icon="trash-2" label="Excluir" danger confirm="Excluir este cliente?" />
            </mad-actions>
        </mad-grid>
    </mad-page-content>
</mad-page-container>
```

O formulário que ela abre, e o componente por trás dele:

```blade
<mad-form submit="onSave">
    <mad-form-section title="Cliente" icon="user">
        <mad-form-grid :cols="2">
            <mad-input-field name="nome" label="Nome" required />
            <mad-input-field name="email" label="E-mail" type="email" />
            <mad-dbcombo-field name="cidade_id" label="Cidade" model="Cidade"
                display="{nome} - {uf}" order-by="nome" />
            <mad-money-field name="limite_credito" label="Limite de crédito" />
        </mad-form-grid>
    </mad-form-section>

    <mad-form-actions>
        <mad-btn type="submit" variant="primary" icon="save">Salvar</mad-btn>
    </mad-form-actions>
</mad-form>
```

```php
<?php

use App\Models\Crm\Cliente;
use Mad\Component\MadComponent;
use Mad\Form\MadForm;
use Mad\Form\MadValidationException;
use Mad\Http\MadResponse;

class ClienteForm extends MadComponent
{
    protected static string $wrapper = self::DRAWER; // abre numa gaveta lateral
    protected static string $title   = 'Cliente';

    public MadForm $form;
    public ?int $clienteId = null;

    public function mount(array $params = []): void
    {
        $this->form = new MadForm('form');

        if (! empty($params['id'])) {
            $this->onEdit((int) $params['id']);
        }
    }

    public function onEdit(int $id): void
    {
        $cliente = Cliente::findOrFail($id);
        $this->clienteId = (int) $cliente->id;
        $this->form->fill($cliente);
    }

    public function onSave(): MadResponse
    {
        try {
            // rules() vem do model que o MadBuilder gera a partir do seu banco
            $this->form->validate(Cliente::rules($this->clienteId));

            $cliente = Cliente::findOrNew($this->clienteId);
            $this->form->save($cliente);

            return (new MadResponse())
                ->toast('Cliente salvo', 'success')
                ->closeDrawer()
                ->manageRow($cliente->id, ClienteList::class); // atualiza a linha na grid aberta
        } catch (MadValidationException $e) {
            return $e->asInline(); // cada mensagem aparece embaixo do seu campo
        }
    }

    protected function view(): string|array
    {
        return 'clientes.cliente-form';
    }
}
```

Um dashboard com indicadores e gráficos que montam a própria consulta:

```blade
<mad-form-grid :cols="3">
    <mad-db-metric-card name="pedidos" model="Pedido" total="count"
        label="Pedidos" icon="shopping-cart" format="integer" />
    <mad-db-metric-card name="receita" model="Pedido" field="valor_total" total="sum"
        label="Receita" icon="circle-dollar-sign" format="money:R$" variant="success" />
    <mad-db-metric-card name="clientes" model="Cliente" total="count"
        label="Clientes" icon="users" format="integer" />
</mad-form-grid>

<mad-db-chart type="bar" name="receita_vendedor" model="Pedido"
    group-by="vendedor" field="valor_total" total="sum"
    title="Receita por vendedor" format="currency:R$" />
```

Reatividade sem escrever JavaScript. O método muda uma propriedade PHP e a página acompanha:

```php
use Mad\Component\MadComponent;

class Contador extends MadComponent
{
    public int $contador = 0;

    public function incrementar(): void
    {
        $this->contador++;
    }

    protected function view(): string|array
    {
        return 'demo.contador';
    }
}
```

```blade
<mad-card>
    <h2>@madBind('contador')</h2>
    <mad-btn variant="primary" icon="plus" mad:click="incrementar">Somar um</mad-btn>
</mad-card>
```

## Como fica na tela

Telas de um app de exemplo gerado pelo MadBuilder. Todas são desenhadas por este pacote.

<table>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/dashboard.jpg" alt="Dashboard de vendas com indicadores e gráficos de barra, rosca e linha"><br><sub><b>Dashboard</b>: indicadores, gráficos e filtros com <code>&lt;mad-db-metric-card&gt;</code> e <code>&lt;mad-db-chart&gt;</code></sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/form.jpg" alt="Cadastro de venda mestre-detalhe aberto numa gaveta sobre a listagem"><br><sub><b>Formulário mestre-detalhe</b> numa gaveta, com itens e totais ao vivo</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/listing.jpg" alt="Listagem de vendas com filtros, selos de status e colunas de valor"><br><sub><b>Listagem</b>: busca, filtro por coluna, selos, exportação</sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/kanban.jpg" alt="Kanban de vendas agrupado por status"><br><sub><b>Kanban</b> com arrastar e soltar entre etapas</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/calendar.jpg" alt="Calendário mensal de vendas com eventos coloridos"><br><sub><b>Calendário</b>: mês, semana, dia e lista</sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/gantt.jpg" alt="Cronograma de projeto em Gantt com fases, progresso e caminho crítico"><br><sub><b>Gantt</b> com fases, progresso e caminho crítico</sub></td>
  </tr>
</table>

## O que vem dentro

| Área | Destaques |
|---|---|
| **Layout** | `mad-page-container`, `mad-page-header`, `mad-tabs`, `mad-drawer`, `mad-modal`, `mad-card`, `mad-accordion`, `mad-steps`, `mad-wizard`, `mad-sidebar-nav` |
| **Formulários** | Mais de 40 campos: texto, número, moeda, data, período, hora, seleção, combo do banco, busca única e múltipla, checklists, switch, cor, ícone, OTP, senha, texto rico, assinatura, upload de arquivo e de imagem com recorte, **CEP** e **CNPJ** que preenchem o formulário, listas de itens (`mad-field-list`) e mestre-detalhe (`mad-detail-form`) |
| **Listagens** | `mad-grid` com ordenação, filtro por coluna, filtro avançado, edição na linha, agrupamento, totais, ações em lote e exportação para Excel/CSV/PDF; `mad-data-table`, `mad-tree-view`, `mad-pivot-table` e `mad-sheet` para lançamento em lote estilo planilha |
| **Dashboards** | `mad-db-metric-card`, `mad-kpi-card`, `mad-db-chart` (barra, linha, pizza, rosca, rosa, funil, treemap), `mad-dash-filters`, `mad-goal-ladder`, `mad-stat-grid` |
| **Planejamento** | `mad-kanban`, `mad-calendar` (com recursos), `mad-gantt` (fases, dependências, linha de base), `mad-timeline`, `mad-org-chart` e `mad-wf-map` para fluxos de aprovação |
| **Documentos** | Bandas `mad-doc-*`, tabelas, QR code, código de barras, assinatura e numeração de página, gerando PDF |
| **Operação** | `mad-pdv` (frente de caixa), `mad-reconcile` (conciliação bancária), comentários, anexos e histórico de alterações |
| **Site público** | Blocos `mad-site-*`: hero, recursos, planos, FAQ, depoimentos, blog e cadastro self-service |
| **Plataforma** | Controle de acesso com permissões por ação, log de auditoria, multiempresa e unidades, tradução, recursos REST, instalador, rastreamento de erros com MadTrace, servidor MCP, copiloto de IA |

A referência completa de tags, propriedades e receitas está na [documentação dos componentes](https://app.madbuilder.dev/help/docs.html).

## MadBuilder: a plataforma low-code com agentes

<p align="center">
  <a href="https://madbuilder.dev"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/studio.jpg" alt="MadBuilder Studio: editor visual lado a lado com o código Blade que ele escreve" width="100%"></a>
</p>

Este pacote é o runtime; o [**MadBuilder**](https://madbuilder.dev) é onde os apps nascem. Desenhe as tabelas, monte as telas ou peça à IA, e receba um projeto Laravel que roda no seu servidor e abre no seu editor.

- **Modelagem de dados.** Tabelas e relacionamentos num diagrama que a equipe edita junta. Cada mudança vira uma migration incremental.
- **Um agente de IA que monta.** Peça em português. Ele lê o banco, cria as tabelas que faltam e gera as telas com estes componentes prontos, em vez de escrever tudo do zero. Por isso gasta **de 65% a 75% menos tokens**.
- **13 tipos de tela** prontos para partir: listagem, formulário, dashboard, kanban, calendário, relatório, planilha, timeline, organograma, wizard, fila de aprovação, documento e Gantt.
- **Seu código sobrevive.** As marcações `@mad-block` guardam o que você escreveu à mão quando a tela é gerada de novo.
- **Teste online.** Um clique sobe o sistema num ambiente isolado, com o PHP e o banco que você escolher.
- **Publique onde quiser.** Por SSH no seu servidor, push no seu repositório Git ou ZIP. Ou no **MadCloud**: domínio próprio, HTTPS, PostgreSQL ou MySQL gerenciado, ambientes de homologação e produção e 30 dias de backup, com servidores em São Paulo.
- **Traga o seu agente.** Claude Code, Cursor ou qualquer cliente MCP cria telas, modela dados e publica, com um token por projeto e cada chamada registrada:

  ```bash
  claude mcp add --transport http madbuilder https://api.madbuilder.dev/api/mcp-gateway \
      --header "Authorization: Bearer <seu-token>"
  ```

**[Comece a construir em madbuilder.dev →](https://madbuilder.dev)**

## Instalação

### Com o MadBuilder (recomendado)

Todo app que o MadBuilder gera já depende deste pacote e sai com a versão que a plataforma testou. Não há nada para instalar.

### Num aplicativo Laravel

```bash
composer require madbuilder/framework
```

O service provider é registrado pelo package discovery. Lembre que o framework foi feito como runtime dos apps do MadBuilder: vários recursos dependem da estrutura que o MadBuilder gera (tabelas de acesso, menus, configuração e o instalador). Ainda não há suporte para usá-lo num app Laravel que não foi criado pelo MadBuilder.

### Requisitos

- PHP 8.4 ou mais novo
- Laravel 13
- MySQL/MariaDB, PostgreSQL, SQLite ou SQL Server. Oracle e Firebird funcionam com os drivers opcionais listados em `suggest` no `composer.json`.

## Versões

O framework segue o [versionamento semântico](https://semver.org). Toda versão tem uma entrada no [changelog](CHANGELOG.md), escrita para quem monta telas com ele; as mesmas notas aparecem dentro do MadBuilder, na tela *Novidades*.

## Comunidade

- **Dúvidas e ideias:** [fórum do MadBuilder](https://manager.madbuilder.dev/ajuda/forum)
- **Bugs no framework:** [issues no GitHub](https://github.com/madbuilder-dev/mad-framework/issues)
- **Como contribuir:** veja o [CONTRIBUTING.md](CONTRIBUTING.md)
- **Segurança:** reporte vulnerabilidades em particular, como explica o [SECURITY.md](SECURITY.md)

## Créditos

O Mad Framework foi criado por [Matheus Agnes Dias](https://github.com/matheusagnes) e pela equipe da [Mad Solutions](https://madbuilder.dev), e se apoia em ótimos projetos de código aberto: [Laravel](https://laravel.com), [Alpine.js](https://alpinejs.dev), [Apache ECharts](https://echarts.apache.org), [Lucide](https://lucide.dev), [FullCalendar](https://fullcalendar.io), [TinyMCE](https://www.tiny.cloud), [Cropper.js](https://github.com/fengyuanchen/cropperjs), [SortableJS](https://sortablejs.github.io/Sortable/), [Dompdf](https://github.com/dompdf/dompdf), [OpenSpout](https://github.com/openspout/openspout), [league/commonmark](https://commonmark.thephpleague.com), [BaconQrCode](https://github.com/Bacon/BaconQrCode) e [php-barcode-generator](https://github.com/picqer/php-barcode-generator).

## Licença

O Mad Framework é software de código aberto sob a [licença MIT](LICENSE).
Copyright © 2025-2026 Mad Solutions LTDA.

A licença vale para o código. Os nomes e logotipos MadBuilder e Mad Framework pertencem à Mad Solutions LTDA.

<p align="center"><sub>Feito no Brasil 🇧🇷 com Laravel.</sub></p>
