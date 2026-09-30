<?php

declare(strict_types=1);

/**
 * Mapa ÚNICO de assets do MAD: fonte (editável) => cópia servida (sob public/).
 *
 * Dois grupos:
 *
 *  - 'package'        — assets cuja FONTE vive em packages/mad-framework/assets/**.
 *                       Consumidos por `composer mad:sync` E por
 *                       MadServiceProvider::bootPublishing() (vendor:publish
 *                       --tag=mad-assets).
 *
 *  - 'served_copies'  — assets cuja FONTE é a própria cópia-raiz em app/lib/**
 *                       (lida pelo PHP em MadBlade::_resolveCssPath p/ inline de
 *                       CSS) e que TAMBÉM precisam de uma cópia servida via HTTP
 *                       sob public/. Não têm fonte no package, então NÃO são
 *                       publicados via vendor:publish — só o `mad:sync` mantém
 *                       root↔public em sincronia (e o CI falha se divergir).
 *
 * Chave  = caminho da FONTE relativo à raiz do projeto.
 * Valor  = caminho SERVIDO relativo à raiz do projeto (sempre sob public/).
 *
 * Os destinos casam EXATAMENTE com as URLs que o tema ativo (theme-notch,
 * app/templates/theme-notch/libraries.html) referencia — docroot é public/.
 *
 * Para adicionar/garantir um asset, edite SÓ este arquivo.
 */

$builderUi = 'app/lib/include/builder/ui';
$pubBuilderUi = 'public/app/lib/include/builder/ui';

// Arquivos de app/lib/include/builder/ui/** que existem em raiz E em public/
// (CSS/JS de UI do framework sem build step). Fonte = a cópia-raiz.
// Só os REALMENTE carregados por libraries.html (GED, tab-bar) ficam aqui —
// mad-ui-docs era scaffolding sem wiring e foi removido. mad-docs e mad-site
// têm fonte própria (Mad\Site\MadSiteAssets) — entram no grupo 'package' abaixo.
$builderUiFiles = [
    'ged.css',
    'mad-tab-bar.css',
];

$servedCopies = [];
foreach ($builderUiFiles as $f) {
    $servedCopies["{$builderUi}/{$f}"] = "{$pubBuilderUi}/{$f}";
}
// Scripts app-level (legado) + TinyMCE self-hosted (vendor) — root↔public.
$servedCopies['app/lib/include/application.js']                 = 'public/app/lib/include/application.js';
$servedCopies['app/lib/include/system.js']                      = 'public/app/lib/include/system.js';
$servedCopies['app/lib/include/builder/tinymce/tinymce.min.js'] = 'public/app/lib/include/builder/tinymce/tinymce.min.js';

// MadTemplate.js do tema (theme-notch): comportamento do casco autenticado
// (initNotchShell etc.). Fonte = cópia-raiz em app/lib/** (sem build step);
// servida sob public/ e referenciada por libraries-builder.blade.php. Antes era
// mantida na mão (`cp`) — agora o mad:sync cobre e o CI falha se divergir.
$servedCopies['app/lib/include/builder/theme-notch/MadTemplate.js'] = 'public/app/lib/include/builder/theme-notch/MadTemplate.js';

// CSS/JS do tema theme-notch (root↔public, sem build step) referenciados por
// libraries-theme/libraries-builder.blade.php. Fonte = cópia-raiz em
// app/templates/theme-notch/**; servida sob public/. (sweetalert.* NÃO entra:
// só existe em public/, sem cópia-raiz.)
$themeNotch    = 'app/templates/theme-notch';
$pubThemeNotch = 'public/app/templates/theme-notch';
$themeNotchFiles = [
    'css/notch-base.css',
    'css/notch-dark.css',
    'css/notch-erp.css',
    'css/notch-login-variants.css',
    'css/notch-menu.css',
    'css/notch-pages.css',
    'css/notch-responsive.css',
    'css/style.css',
    'css/mad-chat.css',
    'css/mad-copilot.css',
    'css/mad-command-center.css',
    'js/custom.js',
    'js/mad-copilot.js',
    'js/mad-chat/engine.js',
    'js/mad-chat/variations.js',
    'js/mad-chat/mad-chat.js',
];
foreach ($themeNotchFiles as $f) {
    $servedCopies["{$themeNotch}/{$f}"] = "{$pubThemeNotch}/{$f}";
}

// Paleta do tema NOMEADO "erp" (themes/erp.css). Entrada NOMINAL, nunca glob:
// themes/**.css é território do Builder (ThemeFileGenerator baixa/gera os temas
// do projeto lá) — varrer o diretório publicaria arquivo gerado e faria o
// mad:sync:check quebrar em toda máquina com tema diferente. Só o built-in
// versionado no repo entra aqui.
$servedCopies["{$themeNotch}/themes/erp.css"] = "{$pubThemeNotch}/themes/erp.css";

// Charts (C3/D3/ECharts) + admin.min.css + builder.css do tema — app/lib/include/**
// (root↔public, sem build step). Referenciados por libraries-theme/builder.blade.php.
$includeFiles = [
    'admin.min.css',
    'c3/c3.min.css',
    'c3/c3.min.js',
    'c3/d3.min.js',
    'echarts/echarts.min.js',
    'echarts/echarts-dark.js',
    'builder/theme-notch/builder.css',
];
foreach ($includeFiles as $f) {
    $servedCopies["app/lib/include/{$f}"] = "public/app/lib/include/{$f}";
}

return [
    'package' => [
        'packages/mad-framework/assets/builder-ui/mad-ui.js'  => 'public/app/lib/include/builder/ui/mad-ui.js',
        'packages/mad-framework/assets/builder-ui/mad-ui.css' => 'public/app/lib/include/builder/ui/mad-ui.css',
        'packages/mad-framework/assets/builder-ui/install-wizard.css' => 'public/app/lib/include/builder/ui/install-wizard.css',
        'packages/mad-framework/assets/builder-ui/mad-docs.css' => 'public/app/lib/include/builder/ui/mad-docs.css',
        'packages/mad-framework/assets/builder-ui/mad-docs.js'  => 'public/app/lib/include/builder/ui/mad-docs.js',
        // Site público do produto (Mad\Site\MadSiteAssets::enableSite()).
        'packages/mad-framework/assets/builder-ui/mad-site.css' => 'public/app/lib/include/builder/ui/mad-site.css',
        'packages/mad-framework/assets/builder-ui/site.js'      => 'public/app/lib/include/builder/ui/site.js',
        'packages/mad-framework/assets/mad.js'                => 'public/lib/mad/mad.js',
        'packages/mad-framework/assets/mad-livewire.js'       => 'public/lib/mad/mad-livewire.js',
        'packages/mad-framework/assets/mad-web-routing.js'    => 'public/lib/mad/mad-web-routing.js',
        'packages/mad-framework/assets/mad-mail.js'           => 'public/lib/mad/mad-mail.js',
        'packages/mad-framework/assets/mad-mail.css'          => 'public/lib/mad/mad-mail.css',
        'packages/mad-framework/assets/mad-notify.js'         => 'public/lib/mad/mad-notify.js',
        'packages/mad-framework/assets/mad-gantt.js'          => 'public/lib/mad/mad-gantt.js',
        'packages/mad-framework/assets/mad-gantt.css'         => 'public/lib/mad/mad-gantt.css',
        'packages/mad-framework/assets/mad-sheet.js'          => 'public/lib/mad/mad-sheet.js',
        'packages/mad-framework/assets/mad-sheet.css'         => 'public/lib/mad/mad-sheet.css',
        'packages/mad-framework/assets/mad-reconcile.js'      => 'public/lib/mad/mad-reconcile.js',
        'packages/mad-framework/assets/mad-reconcile.css'     => 'public/lib/mad/mad-reconcile.css',
        'packages/mad-framework/assets/mad-orgchart.js'       => 'public/lib/mad/mad-orgchart.js',
        'packages/mad-framework/assets/mad-orgchart.css'      => 'public/lib/mad/mad-orgchart.css',
        'packages/mad-framework/assets/mad-pdv.js'            => 'public/lib/mad/mad-pdv.js',
        'packages/mad-framework/assets/mad-pdv.css'           => 'public/lib/mad/mad-pdv.css',
    ],
    'served_copies' => $servedCopies,
];
