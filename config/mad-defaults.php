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
];
