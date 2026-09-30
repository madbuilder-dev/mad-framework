<?php
namespace Mad\Util;

use Mad\Http\MadResponse;

/**
 * MadDumpModal — Buffer estatico de dumps que serao exibidos numa modal
 * de debug client-side ao final da request AJAX.
 *
 * Nao usar diretamente — chamar `mad_dump_modal($var, ...)` ou o atalho `mdm($var, ...)`.
 *
 * Funciona como o `dd()` do Laravel: o dev chama de qualquer ponto da
 * action, e quando o MadResponse->send() for executado, todos os dumps
 * pendentes viram uma op `dump_modal` injetada na resposta automaticamente.
 */
class MadDumpModal
{
    /** @var array<int,array> */
    private static array $pending = [];

    private const MAX_DEPTH = 6;

    /**
     * Empilha argumentos no buffer (chamado pelos helpers globais mad_dump_modal / mdm).
     */
    public static function push(array $args): void
    {
        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        // bt[0] = MadDumpModal::push, bt[1] = mad_dump_modal/mdm, bt[2] = caller
        $callerFrame = $bt[2] ?? $bt[1] ?? $bt[0] ?? [];
        $file = $callerFrame['file'] ?? 'unknown';
        $line = $callerFrame['line'] ?? 0;

        $items = [];
        foreach ($args as $idx => $arg) {
            $items[] = [
                'index' => $idx,
                'type'  => self::typeOf($arg),
                'html'  => self::format($arg),
                'json'  => self::toJsonSafe($arg),
            ];
        }

        self::$pending[] = [
            'file' => self::shortPath($file),
            'line' => $line,
            'time' => date('H:i:s'),
            'items' => $items,
        ];
    }

    /**
     * Anexa op `dump_modal` ao MadResponse se houver dumps pendentes.
     * Chamado automaticamente em MadResponse::send().
     */
    public static function inject(MadResponse $response): void
    {
        if (empty(self::$pending)) return;

        $dumps = self::$pending;
        self::$pending = [];

        $response->dumpModal($dumps, self::collectRequestMeta());
    }

    /**
     * Coleta informacoes do request atual para o header da modal de debug.
     */
    public static function collectRequestMeta(): array
    {
        $startedAt = defined('MAD_REQUEST_START')
            ? MAD_REQUEST_START
            : ($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));

        $sessUser = null;
        if (class_exists('TSession')) {
            try { $sessUser = session('login') ?: session('userlogin') ?: session('userid'); } catch (\Throwable) {}
        }

        // Sanitiza POST removendo campos sensiveis
        $post = $_POST ?? [];
        foreach (['password', 'senha', 'mad_state', '_token'] as $k) {
            if (isset($post[$k])) $post[$k] = '***';
        }

        return [
            'method'       => $_SERVER['REQUEST_METHOD']  ?? 'CLI',
            'uri'          => $_SERVER['REQUEST_URI']     ?? '',
            'class'        => $_REQUEST['class']          ?? null,
            'action'       => $_REQUEST['method']         ?? null,
            'ajax'         => !empty($_SERVER['HTTP_X_REQUESTED_WITH']),
            'ip'           => $_SERVER['REMOTE_ADDR']     ?? '',
            'user_agent'   => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 120),
            'referer'      => $_SERVER['HTTP_REFERER']    ?? '',
            'php'          => PHP_VERSION,
            'memory'       => self::formatBytes(memory_get_usage(true)),
            'memory_peak'  => self::formatBytes(memory_get_peak_usage(true)),
            'duration_ms'  => round((microtime(true) - $startedAt) * 1000, 1),
            'session_id'   => (function_exists('session') ? session()->getId() : null) ?: null,
            'session_user' => $sessUser,
            'get'          => $_GET ?? [],
            'post'         => $post,
            'request_id'   => defined('REQUEST_ID') ? REQUEST_ID : null,
            'sql_count'    => MadSqlCollector::count(),
            'sql_total_ms' => MadSqlCollector::totalDuration(),
            'sql_queries'  => MadSqlCollector::all(),
        ];
    }

    private static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /**
     * Permite checar/limpar manualmente (testes).
     */
    public static function flush(): array
    {
        $d = self::$pending;
        self::$pending = [];
        return $d;
    }

    public static function hasPending(): bool
    {
        return !empty(self::$pending);
    }

    // ── Formatadores ──────────────────────────────────────────────────────────

    private static function typeOf(mixed $v): string
    {
        if (is_object($v)) return 'object<' . get_class($v) . '>';
        if (is_array($v))  return 'array(' . count($v) . ')';
        if (is_string($v)) return 'string(' . strlen($v) . ')';
        return gettype($v);
    }

    private static function shortPath(string $path): string
    {
        $parts = explode('/', $path);
        $n = count($parts);
        return $n >= 2 ? $parts[$n - 2] . '/' . $parts[$n - 1] : $path;
    }

    /**
     * Converte para JSON best-effort (objetos/closures viram string).
     */
    private static function toJsonSafe(mixed $v, int $depth = 0): mixed
    {
        if ($depth > self::MAX_DEPTH) return '...';
        if ($v === null || is_scalar($v)) return $v;
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $vv) $out[$k] = self::toJsonSafe($vv, $depth + 1);
            return $out;
        }
        if ($v instanceof \Closure) return '[Closure]';
        if (is_object($v)) {
            if (method_exists($v, 'toArray')) {
                try { return self::toJsonSafe($v->toArray(), $depth + 1); } catch (\Throwable) {}
            }
            $out = ['__class' => get_class($v)];
            foreach (get_object_vars($v) as $k => $vv) $out[$k] = self::toJsonSafe($vv, $depth + 1);
            return $out;
        }
        if (is_resource($v)) return '[resource ' . get_resource_type($v) . ']';
        return '[' . gettype($v) . ']';
    }

    /**
     * Renderiza HTML formatado com syntax highlight inline.
     */
    public static function format(mixed $v, int $depth = 0): string
    {
        if ($depth > self::MAX_DEPTH) {
            return '<span style="color:#888;">…</span>';
        }

        if ($v === null) {
            return '<span style="color:#9ca3af;font-style:italic;">null</span>';
        }
        if (is_bool($v)) {
            return '<span style="color:#a78bfa;">' . ($v ? 'true' : 'false') . '</span>';
        }
        if (is_int($v) || is_float($v)) {
            return '<span style="color:#60a5fa;">' . $v . '</span>';
        }
        if (is_string($v)) {
            $len = strlen($v);
            $esc = htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
            $tag = $len > 80 ? 'pre' : 'span';
            $style = $tag === 'pre'
                ? 'color:#86efac;background:#0a0f1a;padding:6px 8px;border-radius:4px;white-space:pre-wrap;word-break:break-all;margin:2px 0;'
                : 'color:#86efac;';
            return '<' . $tag . ' style="' . $style . '">"' . $esc . '"</' . $tag . '> '
                 . '<span style="color:#64748b;font-size:10px;">(' . $len . ')</span>';
        }
        if (is_array($v)) {
            return self::renderArray($v, $depth);
        }
        if ($v instanceof \Closure) {
            return '<span style="color:#fbbf24;">[Closure]</span>';
        }
        if (is_object($v)) {
            return self::renderObject($v, $depth);
        }
        if (is_resource($v)) {
            return '<span style="color:#fbbf24;">[resource ' . get_resource_type($v) . ']</span>';
        }
        return '<span style="color:#888;">' . gettype($v) . '</span>';
    }

    private static function renderArray(array $a, int $depth): string
    {
        if (empty($a)) {
            return '<span style="color:#64748b;">[]</span>';
        }
        $count = count($a);
        $isList = array_is_list($a);
        $label = $isList ? 'array(' . $count . ')' : 'assoc(' . $count . ')';

        // arrays curtos numericos inline
        if ($isList && $count <= 4 && $depth > 0) {
            $parts = array_map(fn($x) => self::format($x, $depth + 1), $a);
            return '<span style="color:#64748b;">[</span>' . implode('<span style="color:#64748b;">, </span>', $parts) . '<span style="color:#64748b;">]</span>';
        }

        $rows = '';
        foreach ($a as $k => $v) {
            $key = is_int($k) ? '<span style="color:#60a5fa;">' . $k . '</span>' : '<span style="color:#fbbf24;">"' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '"</span>';
            $rows .= '<div style="padding:1px 0 1px 16px;border-left:1px solid #1e293b;margin-left:4px;">'
                   . $key . ' <span style="color:#64748b;">=&gt;</span> ' . self::format($v, $depth + 1)
                   . '</div>';
        }
        $open = $depth < 1 ? ' open' : '';
        return '<details' . $open . ' style="display:inline-block;vertical-align:top;">'
             . '<summary style="cursor:pointer;color:#a78bfa;outline:none;">' . $label . '</summary>'
             . $rows
             . '</details>';
    }

    private static function renderObject(object $o, int $depth): string
    {
        $class = get_class($o);
        $vars = [];
        try {
            if (method_exists($o, 'toArray')) {
                $vars = $o->toArray();
            } else {
                $vars = get_object_vars($o);
            }
        } catch (\Throwable $e) {
            $vars = ['__error' => $e->getMessage()];
        }

        $rows = '';
        foreach ($vars as $k => $v) {
            $rows .= '<div style="padding:1px 0 1px 16px;border-left:1px solid #1e293b;margin-left:4px;">'
                   . '<span style="color:#fbbf24;">' . htmlspecialchars((string)$k, ENT_QUOTES, 'UTF-8') . '</span> '
                   . '<span style="color:#64748b;">=&gt;</span> ' . self::format($v, $depth + 1)
                   . '</div>';
        }
        $open = $depth < 1 ? ' open' : '';
        return '<details' . $open . ' style="display:inline-block;vertical-align:top;">'
             . '<summary style="cursor:pointer;color:#f472b6;outline:none;">'
             . htmlspecialchars($class, ENT_QUOTES, 'UTF-8')
             . ' <span style="color:#64748b;font-size:10px;">{' . count($vars) . '}</span>'
             . '</summary>'
             . $rows
             . '</details>';
    }
}
