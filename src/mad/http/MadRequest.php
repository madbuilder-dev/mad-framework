<?php

namespace Mad\Http;
use Mad\Component\MadComponent;
use Mad\Component\MadRenderContext;


/**
 * Encapsula os dados do request HTTP com accessors tipados.
 *
 * Inspirado no Request do Laravel — simplifica o acesso a parametros
 * em mount(), actions e handlers de MadComponent.
 *
 * ── USO COMO INSTANCIA (injetada em mount/action) ─────────────────────
 *   public function mount(MadRequest $request): void
 *   {
 *       $this->form->set('parent_id', $request->int('parent_id'));
 *       $nome = $request->string('nome', 'Sem nome');
 *   }
 *
 * ── USO ESTATICO (qualquer lugar: Blade, service, helper) ─────────────
 *   MadRequest::int('negociacao_id')
 *   MadRequest::string('nome', 'default')
 *   MadRequest::has('id')
 *
 * O acessor estatico procura em duas fontes (nesta ordem):
 *   1. Prop publica do MadComponent ativo (via MadRenderContext)
 *      — sobrevive ao ciclo reativo (MadWire) desde que o mount()
 *        tenha promovido o param a uma public prop.
 *   2. $_REQUEST capturado (funciona apenas no primeiro GET/POST).
 */
class MadRequest
{
    private array $data;

    /** Cache de $_REQUEST capturado na primeira chamada estatica. */
    private static ?array $captured = null;

    public function __construct(array $data = [])
    {
        $this->data = $data;
    }

    // ═════════════════════════════════════════════════════════════════════
    // ACESSORES ESTATICOS — lookup em prop do componente + $_REQUEST
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Retorna o valor cru de um parametro (ou default).
     *
     * Ordem de lookup:
     *   1. Public prop do MadComponent ativo (se dentro de render/action)
     *   2. $_REQUEST capturado
     */
    public static function param(string $key, mixed $default = null): mixed
    {
        // 1) Tenta ler da prop publica do componente atual.
        //    So usa se for "truthy" — senao cai no $_REQUEST porque
        //    pode ser valor default da prop (nao foi promovida em mount).
        if (class_exists(MadRenderContext::class, false)) {
            $component = MadRenderContext::getComponent();
            if ($component && property_exists($component, $key)) {
                $val = $component->{$key};
                if ($val !== null && $val !== '' && $val !== 0 && $val !== '0') {
                    return $val;
                }
            }
        }

        // 2) Fallback para $_REQUEST capturado
        return self::captured()[$key] ?? $default;
    }

    /** Estatico: retorna como int. */
    public static function getInt(string $key, int $default = 0): int
    {
        $val = self::param($key, null);
        return $val === null ? $default : (int) $val;
    }

    /** Estatico: retorna como string. */
    public static function getString(string $key, string $default = ''): string
    {
        $val = self::param($key, null);
        return $val === null ? $default : (string) $val;
    }

    /** Estatico: retorna como float. */
    public static function getFloat(string $key, float $default = 0.0): float
    {
        $val = self::param($key, null);
        return $val === null ? $default : (float) $val;
    }

    /** Estatico: retorna como bool (aceita '1', 'true', 'on', 'yes'). */
    public static function getBool(string $key, bool $default = false): bool
    {
        $val = self::param($key, null);
        if ($val === null) {
            return $default;
        }
        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /** Estatico: verifica se o parametro existe e nao e vazio. */
    public static function hasParam(string $key): bool
    {
        $val = self::param($key, null);
        return $val !== null && $val !== '';
    }

    /** Estatico: retorna todos os parametros disponiveis (merged). */
    public static function allParams(): array
    {
        $out = self::captured();
        if (class_exists(MadRenderContext::class, false)) {
            $component = MadRenderContext::getComponent();
            if ($component) {
                $ref = new \ReflectionObject($component);
                foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $p) {
                    $name = $p->getName();
                    $val = $component->{$name};
                    if (!is_object($val) && !is_array($val)) {
                        $out[$name] = $val;
                    }
                }
            }
        }
        return $out;
    }

    /** Permite sobrescrever/injetar dados capturados (testes, boot manual). */
    public static function capture(array $data): void
    {
        self::$captured = $data;
    }

    /** Limpa o cache capturado (usar em testes). */
    public static function reset(): void
    {
        self::$captured = null;
    }

    /** Retorna $_REQUEST capturado (lazy). */
    private static function captured(): array
    {
        if (self::$captured === null) {
            self::$captured = $_REQUEST ?? [];
        }
        return self::$captured;
    }

    // ── Accessors tipados ────────────────────────────────────────────────

    /** Retorna o valor cru ou default. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** Retorna como int. */
    public function int(string $key, int $default = 0): int
    {
        return array_key_exists($key, $this->data) ? (int) $this->data[$key] : $default;
    }

    /** Retorna como string. */
    public function string(string $key, string $default = ''): string
    {
        return array_key_exists($key, $this->data) ? (string) $this->data[$key] : $default;
    }

    /** Retorna como float. */
    public function float(string $key, float $default = 0.0): float
    {
        return array_key_exists($key, $this->data) ? (float) $this->data[$key] : $default;
    }

    /** Retorna como bool (aceita '1', 'true', 'on', 'yes'). */
    public function bool(string $key, bool $default = false): bool
    {
        return array_key_exists($key, $this->data)
            ? filter_var($this->data[$key], FILTER_VALIDATE_BOOLEAN)
            : $default;
    }

    // ── Verificacao ─────────────────────────────────────────────────────

    /** Verifica se a key existe e nao e vazia. */
    public function has(string $key): bool
    {
        return !empty($this->data[$key]);
    }

    /** Verifica se a key existe (mesmo se vazia/null). */
    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    // ── Colecao ─────────────────────────────────────────────────────────

    /** Retorna todos os dados. */
    public function all(): array
    {
        return $this->data;
    }

    /** Retorna apenas as keys especificadas. */
    public function only(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    /** Retorna tudo exceto as keys especificadas. */
    public function except(array $keys): array
    {
        return array_diff_key($this->data, array_flip($keys));
    }

    // ── Arquivos enviados ───────────────────────────────────────────────
    //
    // O wire (mad-livewire.js) coleta TODO `input[type=file]` do wrapper no
    // FormData, então `$_FILES` chega na action mesmo num `mad:click`. Sem
    // estes helpers o dev caía em `$req->file()` (não existia → fatal "Call
    // to undefined method") ou mexia em `$_FILES` cru.
    //
    // ⚠️ Na MAIORIA dos casos você NÃO precisa disto: declare o
    // `<mad-file-field>`/`<mad-multi-file-field>` com storage/model e chame
    // `$this->form->save($registro)` — o framework grava disco/banco, trata
    // remoção na edição e sanitiza nome. Use estes helpers só quando o
    // destino do arquivo é fora do padrão (API externa, parser em memória).

    /**
     * Arquivos enviados no campo, SEMPRE como lista (0..N) de
     * UploadedFile do Symfony/Laravel. Campo ausente → [].
     *
     *   foreach ($request->files('anexos') as $file) { $file->getClientOriginalName(); }
     *
     * @return array<int,\Symfony\Component\HttpFoundation\File\UploadedFile>
     */
    public function files(string $key): array
    {
        $req = function_exists('request') ? request() : null;
        if ($req !== null) {
            $found = $req->file($key);
            if ($found === null) {
                return [];
            }
            return is_array($found) ? array_values(array_filter($found)) : [$found];
        }

        // Fallback sem container HTTP (CLI/testes): monta a partir de $_FILES.
        $raw = $_FILES[$key] ?? null;
        if (!is_array($raw) || empty($raw['tmp_name'])) {
            return [];
        }
        $class = '\\Symfony\\Component\\HttpFoundation\\File\\UploadedFile';
        if (!class_exists($class)) {
            return [];
        }
        $out = [];
        if (is_array($raw['tmp_name'])) {
            foreach ($raw['tmp_name'] as $i => $tmp) {
                if ($tmp === '' || ($raw['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                $out[] = new $class($tmp, $raw['name'][$i], $raw['type'][$i] ?? null, $raw['error'][$i], true);
            }
            return $out;
        }
        if (($raw['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return [];
        }
        return [new $class($raw['tmp_name'], $raw['name'], $raw['type'] ?? null, $raw['error'], true)];
    }

    /**
     * Primeiro arquivo do campo (ou null). Espelha `Request::file()` do
     * Laravel — é o que todo dev tenta primeiro.
     */
    public function file(string $key): mixed
    {
        return $this->files($key)[0] ?? null;
    }

    /** true quando o campo trouxe ao menos um arquivo válido. */
    public function hasFile(string $key): bool
    {
        return $this->files($key) !== [];
    }

    // ── Array access ────────────────────────────────────────────────────

    /** Permite acesso como propriedade: $request->parent_id */
    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }
}