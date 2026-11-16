<?php

/**
 * Defaults do pacote mesclados em config('mad') via mergeConfigFrom — rede para
 * apps que atualizam SÓ o vendor (Central de Comando → card Mad Framework) e
 * ainda não têm as chaves novas no config/mad.php próprio.
 *
 * mergeConfigFrom mescla APENAS o nível raiz: um app cujo config/mad.php já
 * declara a chave ('cep') vence por inteiro. Caveat Laravel: o merge é PULADO
 * quando a config está cacheada (config:cache) — nesses apps a chave nova só
 * existe depois de atualizar o config/mad.php e recachear.
 *
 * Mantenha este arquivo MÍNIMO: só chaves que o runtime lê com fallback seguro
 * (a ausência delas não pode quebrar nada).
 */
return [
    // Ver a doc completa no config/mad.php do template (seção 'cep').
    'cep' => [
        'auto_create' => filter_var(env('MAD_CEP_AUTO_CREATE', false), FILTER_VALIDATE_BOOL),
    ],
    // Ver a doc completa no config/mad.php do template (seção 'sql_log').
    'sql_log' => [
        'enabled' => filter_var(env('MAD_SQL_LOG', false), FILTER_VALIDATE_BOOL),
    ],
    // Ver a doc completa no config/mad.php do template (seção 'toast_user_name').
    'toast_user_name' => [
        'enabled' => filter_var(env('MAD_TOAST_USER_NAME', false), FILTER_VALIDATE_BOOL),
    ],
    // Ver a doc completa no config/mad.php do template (seção 'migrations').
    'migrations' => [
        'skip_superseded_revisions' => filter_var(env('MAD_MIGRATIONS_SKIP_SUPERSEDED', true), FILTER_VALIDATE_BOOL),
        'superseded_archive' => env('MAD_MIGRATIONS_SUPERSEDED_ARCHIVE'),
    ],
];
