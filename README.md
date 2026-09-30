<p align="center">
  <a href="https://madbuilder.dev">
    <img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/banner.png" alt="Mad Framework: the open-source Laravel runtime behind every app built with MadBuilder" width="100%">
  </a>
</p>

<p align="center">
  <a href="https://packagist.org/packages/madbuilder/framework"><img alt="Latest version" src="https://img.shields.io/packagist/v/madbuilder/framework?style=flat-square&color=1E55E8&label=packagist"></a>
  <a href="https://packagist.org/packages/madbuilder/framework"><img alt="PHP version" src="https://img.shields.io/packagist/dependency-v/madbuilder/framework/php?style=flat-square&color=0E1B3D&label=php"></a>
  <a href="https://laravel.com"><img alt="Laravel 13" src="https://img.shields.io/badge/laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white"></a>
  <a href="LICENSE"><img alt="MIT License" src="https://img.shields.io/badge/license-MIT-3FB8E8?style=flat-square"></a>
  <a href="https://madbuilder.dev"><img alt="Powered by MadBuilder" src="https://img.shields.io/badge/powered%20by-MadBuilder-F26B1F?style=flat-square"></a>
</p>

<p align="center">
  <b>English</b> · <a href="README.pt-BR.md">Português (Brasil)</a>
</p>

---

**Mad Framework** is the runtime of every application built with [**MadBuilder**](https://madbuilder.dev), the agentic low-code platform that turns a data model, a visual editor and an AI agent into a **real Laravel project you own**.

It gives Blade more than **250 `<mad-*>` tags**, from a single currency field to a full kanban, gantt chart, spreadsheet or point of sale. It adds a **reactive layer that needs no JavaScript build**, and the plumbing every business app ends up needing: permissions per action, audit trail, multi-tenancy, i18n, PDF documents, exports, a REST layer, an **MCP server** and an **embedded AI copilot**.

All of it runs on plain **Laravel 13**: Eloquent models, controllers, Blade views, routes and migrations. No proprietary engine behind your app, and the runtime itself is MIT.

## Why Mad Framework

- **It is just Laravel.** Generated apps are standard Laravel projects that any PHP developer can pick up. Open the files, change them, deploy them wherever you want.
- **Screens in a few lines of Blade.** Components talk to Eloquent directly. A searchable, sortable, filterable, exportable grid is one tag, and a master-detail form fits on a screen.
- **Reactive, server-driven UI.** `mad:click`, `mad:model` and `@madBind` connect the page to public properties of a PHP component. State changes are diffed and pushed back to the browser; Alpine.js does the rest.
- **Business-ready from day one.** IAM with permissions per action, audit log, multi-tenancy and business units, four languages (English, Spanish, Portuguese, European Portuguese), PDF documents, Excel/CSV/PDF export, REST resources and error tracking.
- **Built for agents.** Every app can expose its own MCP server, with CRUD and query tools that respect user permissions, mask personal data and write an audit trail. An AI copilot and AI-built dashboards run inside the app.
- **Ready for Brazil, open to the world.** CEP and CNPJ lookups that fill in the form, money and document masks, and six databases: MySQL/MariaDB, PostgreSQL, SQLite, SQL Server, Oracle and Firebird.

## A quick look

A listing screen. Sorting, per-column filters, search, pagination and export come with the tag:

```blade
<mad-page-container>
    <mad-page-header title="Customers" icon="users" breadcrumb="Customers">
        <actions>
            <mad-btn navigate="CustomerForm" variant="primary" icon="plus">New customer</mad-btn>
        </actions>
    </mad-page-header>

    <mad-page-content>
        <mad-grid self per-page="15" searchable :search-columns="['name', 'email']">
            <mad-columns>
                <mad-col field="id" label="#" width="70" sort />
                <mad-col field="name" label="Name" sort filter />
                <mad-col field="{city->name}" label="City" sort />
                <mad-col field="created_at" label="Customer since" date="d/m/Y" sort />
            </mad-columns>
            <mad-actions>
                <mad-nav icon="pencil" label="Edit" target="CustomerForm::onEdit({id})" />
                <mad-act method="onDelete" icon="trash-2" label="Delete" danger confirm="Delete this customer?" />
            </mad-actions>
        </mad-grid>
    </mad-page-content>
</mad-page-container>
```

The form that opens from it, and the component behind the form:

```blade
<mad-form submit="onSave">
    <mad-form-section title="Customer" icon="user">
        <mad-form-grid :cols="2">
            <mad-input-field name="name" label="Name" required />
            <mad-input-field name="email" label="E-mail" type="email" />
            <mad-dbcombo-field name="city_id" label="City" model="City"
                display="{name} - {state}" order-by="name" />
            <mad-money-field name="credit_limit" label="Credit limit" prefix="$" />
        </mad-form-grid>
    </mad-form-section>

    <mad-form-actions>
        <mad-btn type="submit" variant="primary" icon="save">Save</mad-btn>
    </mad-form-actions>
</mad-form>
```

```php
<?php

use App\Models\Crm\Customer;
use Mad\Component\MadComponent;
use Mad\Form\MadForm;
use Mad\Form\MadValidationException;
use Mad\Http\MadResponse;

class CustomerForm extends MadComponent
{
    protected static string $wrapper = self::DRAWER; // opens as a side drawer
    protected static string $title   = 'Customer';

    public MadForm $form;
    public ?int $customerId = null;

    public function mount(array $params = []): void
    {
        $this->form = new MadForm('form');

        if (! empty($params['id'])) {
            $this->onEdit((int) $params['id']);
        }
    }

    public function onEdit(int $id): void
    {
        $customer = Customer::findOrFail($id);
        $this->customerId = (int) $customer->id;
        $this->form->fill($customer);
    }

    public function onSave(): MadResponse
    {
        try {
            // rules() comes from the model MadBuilder generates from your schema
            $this->form->validate(Customer::rules($this->customerId));

            $customer = Customer::findOrNew($this->customerId);
            $this->form->save($customer);

            return (new MadResponse())
                ->toast('Customer saved', 'success')
                ->closeDrawer()
                ->manageRow($customer->id, CustomerList::class); // refreshes that row in the open grid
        } catch (MadValidationException $e) {
            return $e->asInline(); // each message goes under its field
        }
    }

    protected function view(): string|array
    {
        return 'customers.customer-form';
    }
}
```

A dashboard with KPIs and charts that write their own queries:

```blade
<mad-form-grid :cols="3">
    <mad-db-metric-card name="orders" model="Order" total="count"
        label="Orders" icon="shopping-cart" format="integer" />
    <mad-db-metric-card name="revenue" model="Order" field="total" total="sum"
        label="Revenue" icon="circle-dollar-sign" format="money:$" variant="success" />
    <mad-db-metric-card name="customers" model="Customer" total="count"
        label="Customers" icon="users" format="integer" />
</mad-form-grid>

<mad-db-chart type="bar" name="revenue_by_seller" model="Order"
    group-by="seller" field="total" total="sum"
    title="Revenue by seller" format="currency:$" />
```

Reactivity without writing JavaScript. The method changes a PHP property and the page follows:

```php
use Mad\Component\MadComponent;

class Counter extends MadComponent
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    protected function view(): string|array
    {
        return 'demo.counter';
    }
}
```

```blade
<mad-card>
    <h2>@madBind('count')</h2>
    <mad-btn variant="primary" icon="plus" mad:click="increment">Add one</mad-btn>
</mad-card>
```

## What it looks like

Screens from a sample app generated by MadBuilder. Every one of them is rendered by this package.

<table>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/dashboard.jpg" alt="Sales dashboard with KPIs, bar, donut and line charts"><br><sub><b>Dashboard</b>: KPIs, charts and filters with <code>&lt;mad-db-metric-card&gt;</code> and <code>&lt;mad-db-chart&gt;</code></sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/form.jpg" alt="Master-detail sales form opened in a drawer over the listing"><br><sub><b>Master-detail form</b> in a drawer, with line items and live totals</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/listing.jpg" alt="Sales listing with filters, status badges and money columns"><br><sub><b>Listing</b>: search, column filters, badges, export</sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/kanban.jpg" alt="Kanban board of sales grouped by status"><br><sub><b>Kanban</b> with drag and drop between stages</sub></td>
  </tr>
  <tr>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/calendar.jpg" alt="Monthly sales calendar with colored events"><br><sub><b>Calendar</b>: month, week, day and list views</sub></td>
    <td width="50%"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/gantt.jpg" alt="Project gantt chart with phases, progress and critical path"><br><sub><b>Gantt</b> with phases, progress and critical path</sub></td>
  </tr>
</table>

## What is inside

| Area | Highlights |
|---|---|
| **Layout** | `mad-page-container`, `mad-page-header`, `mad-tabs`, `mad-drawer`, `mad-modal`, `mad-card`, `mad-accordion`, `mad-steps`, `mad-wizard`, `mad-sidebar-nav` |
| **Forms** | 40+ fields: text, number, money, date, date range, time, select, database combo, unique search, multi search, checklists, switch, color, icon, OTP, password, rich text, signature, file and image upload with cropping, **CEP** and **CNPJ** with auto-fill, line-item lists (`mad-field-list`) and master-detail (`mad-detail-form`) |
| **Lists** | `mad-grid` with sorting, column filters, advanced filters, inline editing, grouping, totals, bulk actions and Excel/CSV/PDF export; `mad-data-table`, `mad-tree-view`, `mad-pivot-table`, and `mad-sheet` for spreadsheet-style batch entry |
| **Dashboards** | `mad-db-metric-card`, `mad-kpi-card`, `mad-db-chart` (bar, line, pie, donut, rose, funnel, treemap), `mad-dash-filters`, `mad-goal-ladder`, `mad-stat-grid` |
| **Planning** | `mad-kanban`, `mad-calendar` (with resources), `mad-gantt` (phases, dependencies, baseline), `mad-timeline`, `mad-org-chart`, `mad-wf-map` for approval workflows |
| **Documents** | `mad-doc-*` bands, tables, QR codes, barcodes, signatures and page numbers, rendered to PDF |
| **Operations** | `mad-pdv` point of sale, `mad-reconcile` for bank reconciliation, comments, attachments and change history |
| **Public website** | `mad-site-*` blocks: hero, features, pricing, FAQ, testimonials, blog and self-service sign-up |
| **Platform** | IAM and permissions per action, audit log, multi-tenancy and business units, i18n, REST resources, installer, MadTrace error tracking, MCP server, AI copilot |

The full reference of tags, props and recipes lives in the [component docs](https://app.madbuilder.dev/help/docs.html).

## MadBuilder: the agentic low-code platform

<p align="center">
  <a href="https://madbuilder.dev"><img src="https://raw.githubusercontent.com/madbuilder-dev/mad-framework/main/.github/art/studio.jpg" alt="MadBuilder Studio: visual editor side by side with the Blade code it writes" width="100%"></a>
</p>

This package is the runtime; [**MadBuilder**](https://madbuilder.dev) is where the apps are made. Draw the tables, build the screens visually or ask the AI, and get a Laravel project that runs on your server and opens in your editor.

- **Data modeling.** Tables and relationships in a diagram your team edits together. Every change becomes an incremental migration.
- **An AI agent that builds.** Ask in plain language. It reads the database, creates the tables that are missing and builds the screens from these ready-made components instead of writing everything from scratch, which is why it spends **65% to 75% fewer tokens**.
- **13 screen types** ready to start from: listing, form, dashboard, kanban, calendar, report, spreadsheet, timeline, org chart, wizard, approval queue, document and gantt.
- **Your code survives.** `@mad-block` markers keep what you wrote by hand when a screen is regenerated.
- **Test online.** One click runs the system in an isolated environment, with the PHP version and database you choose.
- **Deploy anywhere.** SSH to your server, push to your Git repository or download a ZIP. Or publish on **MadCloud**: your own domain, HTTPS, managed PostgreSQL or MySQL, staging and production environments and 30 days of backups, hosted in São Paulo.
- **Bring your own agent.** Claude Code, Cursor or any MCP client can build screens, model data and publish, with one token per project and every call logged:

  ```bash
  claude mcp add --transport http madbuilder https://api.madbuilder.dev/api/mcp-gateway \
      --header "Authorization: Bearer <your-token>"
  ```

**[Start building at madbuilder.dev →](https://madbuilder.dev)**

## Installation

### With MadBuilder (recommended)

Every app MadBuilder generates already requires this package and ships with the version the platform tested. There is nothing to install.

### In a Laravel application

```bash
composer require madbuilder/framework
```

The service provider is registered through package discovery. Keep in mind that the framework is built as the runtime of MadBuilder applications: several features rely on the application scaffold MadBuilder generates (IAM tables, menus, configuration and the installer). Using it in a Laravel app that MadBuilder did not create is not supported yet.

### Requirements

- PHP 8.4 or newer
- Laravel 13
- MySQL/MariaDB, PostgreSQL, SQLite or SQL Server. Oracle and Firebird work with the optional drivers listed under `suggest` in `composer.json`.

## Versioning

The framework follows [semantic versioning](https://semver.org). Every release has an entry in the [changelog](CHANGELOG.md), written for the people who build screens with it; the same notes appear inside MadBuilder, on the *What's new* screen.

## Community

- **Questions and ideas:** [MadBuilder forum](https://manager.madbuilder.dev/ajuda/forum)
- **Bugs in the framework:** [GitHub issues](https://github.com/madbuilder-dev/mad-framework/issues)
- **Contributing:** see [CONTRIBUTING.md](CONTRIBUTING.md)
- **Security:** please report vulnerabilities privately, as described in [SECURITY.md](SECURITY.md)

## Credits

Mad Framework is created by [Matheus Agnes Dias](https://github.com/matheusagnes) and the [Mad Solutions](https://madbuilder.dev) team, and stands on the shoulders of great open-source work: [Laravel](https://laravel.com), [Alpine.js](https://alpinejs.dev), [Apache ECharts](https://echarts.apache.org), [Lucide](https://lucide.dev), [FullCalendar](https://fullcalendar.io), [TinyMCE](https://www.tiny.cloud), [Cropper.js](https://github.com/fengyuanchen/cropperjs), [SortableJS](https://sortablejs.github.io/Sortable/), [Dompdf](https://github.com/dompdf/dompdf), [OpenSpout](https://github.com/openspout/openspout), [league/commonmark](https://commonmark.thephpleague.com), [BaconQrCode](https://github.com/Bacon/BaconQrCode) and [php-barcode-generator](https://github.com/picqer/php-barcode-generator).

## License

Mad Framework is open-source software released under the [MIT license](LICENSE).
Copyright © 2025-2026 Mad Solutions LTDA.

The license covers the code. The MadBuilder and Mad Framework names and logos belong to Mad Solutions LTDA.

<p align="center"><sub>Made in Brazil 🇧🇷 with Laravel.</sub></p>
