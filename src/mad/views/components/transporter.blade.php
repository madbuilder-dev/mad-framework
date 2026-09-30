@php
    $class  = $class  ?? '';
    $method = $method ?? 'show';
    $params = $params ?? [];
    $height = \Mad\Support\CssUnits::length((string) ($height ?? ''), '200px');
    $name   = $name   ?? '';
    $forwardParamsAttr = $forwardParams ?? $forward_params ?? '';
    // lazy: só busca a tela quando o transporter fica VISÍVEL — numa aba ou
    // seção fechada nada é carregado até o usuário abri-la.
    $lazy   = filter_var($lazy ?? false, FILTER_VALIDATE_BOOL);
    // header: como a tela embutida desenha o próprio cabeçalho
    // (compact | title | full — ver MadTransporter::embedHeader()).
    $embedHeader = strtolower(trim((string) ($header ?? 'compact')));
    if (!in_array($embedHeader, \Mad\Ui\MadTransporter::EMBED_MODES, true)) {
        $embedHeader = 'compact';
    }
    $uid    = 'mad-tp-' . substr(md5(uniqid()), 0, 8);

    // Suporta method="onViewThread(42)" — extrai params inline e mapeia por nome.
    // `(.*)` para também aceitar `onShow()` (parênteses vazios): com `.+` o
    // método ficava com os parênteses colados e o Mad.get chamava 'Class@onShow()'.
    $inlineParams = [];
    if (preg_match('/^(\w+)\s*\((.*)\)$/', $method, $m)) {
        $method = $m[1];
        $rawArgs = trim($m[2]) === '' ? [] : array_map('trim', str_getcsv($m[2]));

        // Resolve nomes dos parametros via reflection para mapear posicional → named
        $paramNames = [];
        if ($class && class_exists($class) && method_exists($class, $method)) {
            $ref = new \ReflectionMethod($class, $method);
            foreach ($ref->getParameters() as $p) {
                $paramNames[] = $p->getName();
            }
        }

        foreach ($rawArgs as $i => $arg) {
            $arg = trim($arg, " \t\n\r\0\x0B\"'");
            $key = $paramNames[$i] ?? $i;
            $inlineParams[$key] = $arg;
        }
    }

    $call = $class . '@' . $method;

    // `:params` como array PHP OU string JSON (MadTransporter::decodeParams).
    // Ilegível → aviso no log e no console, em vez de sumir calado.
    $decoded = \Mad\Ui\MadTransporter::decodeParams($params);
    $paramsInvalid = $decoded === null;
    if ($paramsInvalid) {
        $decoded = [];
        if (function_exists('logger')) {
            try {
                logger()->warning('[mad-transporter] :params ilegível — use um array PHP ou json_encode([...])', [
                    'transporter' => $name, 'class' => $class, 'params' => is_scalar($params) ? (string) $params : get_debug_type($params),
                ]);
            } catch (\Throwable) {}
        }
    }

    // Merge inline params (method args) into decoded params
    foreach ($inlineParams as $i => $v) {
        $decoded[$i] = $v;
    }

    // forwardParams="negociacao_id, id" → adiciona _forward_param_* ao params
    if ($forwardParamsAttr) {
        $fwdKeys = array_map('trim', explode(',', $forwardParamsAttr));
        foreach ($fwdKeys as $key) {
            if (isset($decoded[$key])) {
                $decoded['_forward_param_' . $key] = $decoded[$key];
            }
        }
    }

    // Objeto JS dos params. JSON (e não um literal montado à mão): valor com
    // aspas ou array aninhado — agora possível com `:params` array — sai
    // válido. O `{{ }}` abaixo escapa para o atributo; o navegador desfaz.
    $paramsJs = json_encode((object) $decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '{}';

    // URL amigável ASSADA no servidor. O 3º arg do Mad.get é o TARGET, não a
    // URL — sem o 4º arg o client montava `/app/<Classe>/<metodo>`, forma que
    // só existe para classe com exposeClass(); tela de resource() dava 404.
    // Todos os params já são conhecidos aqui, então a URL sai completa (os
    // extras do mad:energize entram por cima via Mad._urlWithParams).
    $callUrl = $class !== '' ? \Mad\Ui\MadAction::to($class, $method, $decoded)->url() : '';
@endphp
{{-- A carga (imediata ou lazy) e o energize moram em Mad.transporter (mad.js).
     O fallback cobre um mad.js em cache mais velho que este blade. --}}
<div id="{{ $uid }}"
     class="mad-transporter"
     @if($name) data-transporter="{{ $name }}" @endif
     data-mad-embed-header="{{ $embedHeader }}"
     @if($lazy) data-mad-lazy @endif
     @if($paramsInvalid) data-mad-params-invalid @endif
     x-data
     x-init="
        @if($paramsInvalid)
        console.warn('[mad-transporter] :params ilegível — a tela embutida abriu sem parâmetros. Use um array PHP ou json_encode([...]).', {{ json_encode(['transporter' => $name, 'class' => $class]) }});
        @endif
        (Mad.transporter || function (el, o) { o.load(); })($el, {
            name: {{ json_encode((string) $name) }},
            lazy: {{ $lazy ? 'true' : 'false' }},
            load: function () { Mad.get('{{ $call }}', {{ $paramsJs }}, '#{{ $uid }}', '{{ $callUrl }}'); },
            energize: function (p) { Mad.get('{{ $call }}', Object.assign({}, {{ $paramsJs }}, p || {}), '#{{ $uid }}', Mad._urlWithParams('{{ $callUrl }}', p || {})); }
        });
     ">
    <div class="mad-transporter-loading" style="min-height:{{ $height }}">
        <div class="mad-spinner"></div>
        <span class="mad-transporter-loading-text">Carregando...</span>
    </div>
</div>
