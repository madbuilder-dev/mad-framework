<?php

declare(strict_types=1);

namespace Mad\Ui;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mad\Component\MadComponent;
use Mad\Database\QuerySource;
use Mad\Form\ModelOptionsLoader;
use Mad\Http\MadResponse;

/**
 * MadWhiteboard — o `<mad-whiteboard>`: quadro branco com notas (post-its)
 * gravadas num model, cada uma com posição, largura, cor e texto.
 *
 * Roda como componente próprio dentro da tela (como o `<mad-grid>`): a
 * configuração viaja no estado criptografado e o navegador não a troca. Cada
 * gesto grava só a nota — arrastar e redimensionar gravam ao soltar; o texto
 * grava ao sair da edição; a cor, ao escolher.
 *
 * Permissão: o dono é a TELA que contém o quadro (perfil → Incluir, Editar,
 * Excluir). `locked` trava o quadro inteiro (só leitura); `:editable-notes`
 * limita as notas que a tela liberar (selado no primeiro desenho). Toda
 * gravação reencontra a nota pela consulta do quadro (`:filters` + escopos
 * globais do model): nota de outro quadro ou de outra empresa não é alcançável.
 *
 * Guia: docs/mad-whiteboard.md
 */
class MadWhiteboard extends MadComponent
{
    protected static string $wrapper = self::INTERNAL;

    public const MAX_COORD = 20000;

    /** Paleta padrão (a mesma do quadro da POC). Chave = o que vai para o banco. */
    public const DEFAULT_COLORS = [
        'amarelo' => '#FFF3A3',
        'verde'   => '#CDEFD3',
        'azul'    => '#CFE3FA',
        'rosa'    => '#FAD2E1',
        'laranja' => '#FFD9B3',
        'cinza'   => '#E6E8EE',
    ];

    /** Config normalizada (selada no estado). */
    public array $wbConfig = [];

    /** Nota recém-criada neste pedido: o corpo a abre em edição. */
    protected string $fresh = '';

    protected function _lockedStateProps(): array
    {
        return ['wbConfig'];
    }

    // ── Desenho ────────────────────────────────────────────────────────────

    /** Chamado pelo Blade do `<mad-whiteboard>`. @internal */
    public static function renderFor(array $config): string
    {
        $board = new static();
        $board->wbConfig = $config;
        $board->boot();
        $board->mount();

        return $board->_renderWrapped();
    }

    protected function view(): string|array
    {
        return ['components.partials.whiteboard-body', ['wb' => $this->board()]];
    }

    /**
     * Notas e o tamanho do quadro. Público para teste.
     *
     * @return array<string,mixed>
     */
    public function board(): array
    {
        $c = $this->wbConfig;
        $notes = [];
        $error = null;
        try {
            $notes = $this->notes();
        } catch (\Throwable $e) {
            $error = MadUserError::message($e, mad_t('mad.whiteboard.load_failed'), static::class . '::board');
        }

        $width = (int) $c['minWidth'];
        $height = (int) $c['minHeight'];
        foreach ($notes as $n) {
            $width = max($width, $n['x'] + $n['w'] + 40);
            $height = max($height, $n['y'] + ($n['h'] ?: 160) + 60);
        }

        return [
            'config' => $c,
            'notes' => $notes,
            'width' => $width,
            'height' => $height,
            'locked' => $this->locked(),
            'canAdd' => !$this->locked() && $c['canAdd'],
            'canDelete' => !$this->locked() && $c['canDelete'],
            'fresh' => $this->fresh,
            'error' => $error,
        ];
    }

    // ── Ações (canal reativo) ──────────────────────────────────────────────

    /** Arrastou / redimensionou: grava posição e tamanho ao soltar. */
    public function onNoteMove(mixed $id = '', mixed $x = 0, mixed $y = 0, mixed $w = null, mixed $h = null): MadResponse
    {
        $c = $this->wbConfig;
        try {
            $note = $this->guardNote((string) $id);
            $set = [
                $c['xField'] => $this->coord($x),
                $c['yField'] => $this->coord($y),
            ];
            if ($w !== null && $w !== '' && $c['widthField'] !== '') {
                $set[$c['widthField']] = $this->size($w, (int) $c['minNoteWidth'], (int) $c['maxNoteWidth']);
            }
            if ($h !== null && $h !== '' && $c['heightField'] !== '') {
                $set[$c['heightField']] = $this->size($h, 60, 1200);
            }
            $note->forceFill($set)->save();
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return $this->saved((string) $id);
    }

    /** Texto editado no lugar. */
    public function onNoteText(mixed $id = '', mixed $text = ''): MadResponse
    {
        $c = $this->wbConfig;
        $text = mb_substr(rtrim(str_replace("\r\n", "\n", (string) $text)), 0, (int) $c['textMax']);
        try {
            $note = $this->guardNote((string) $id);
            $note->forceFill([$c['textField'] => $text])->save();
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return $this->saved((string) $id);
    }

    /** Cor escolhida na paleta da nota. */
    public function onNoteColor(mixed $id = '', mixed $color = ''): MadResponse
    {
        $c = $this->wbConfig;
        $color = (string) $color;
        try {
            if ($c['colorField'] === '' || !isset($c['colors'][$color])) {
                throw new \DomainException(mad_t('mad.whiteboard.invalid'));
            }
            $note = $this->guardNote((string) $id);
            $note->forceFill([$c['colorField'] => $color])->save();
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return $this->saved((string) $id);
    }

    /** Nova nota na posição pedida (ou num canto livre). Abre em edição. */
    public function onNoteAdd(mixed $x = null, mixed $y = null): ?MadResponse
    {
        $c = $this->wbConfig;
        $this->forceFullRender();
        try {
            if ($this->locked() || !$c['canAdd']) {
                throw new \DomainException(mad_t('mad.whiteboard.locked'));
            }
            $note = $this->newModel();
            $set = $c['defaults'] + [
                $c['xField'] => $x === null || $x === '' ? 30 + 24 * ($this->dataQuery()->count() % 12) : $this->coord($x),
                $c['yField'] => $y === null || $y === '' ? 30 + 24 * ($this->dataQuery()->count() % 8) : $this->coord($y),
                $c['textField'] => '',
            ];
            if ($c['widthField'] !== '') {
                $set[$c['widthField']] = (int) $c['noteWidth'];
            }
            if ($c['colorField'] !== '') {
                $set[$c['colorField']] = $c['defaultColor'];
            }
            $note->forceFill($set)->save();
            $this->fresh = (string) $note->getKey();
            // Quem criou a nota a edita: a lista selada de :editable-notes ganha o id.
            if (is_array($c['editableNotes'])) {
                $this->wbConfig['editableNotes'][] = $this->fresh;
            }
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return null;
    }

    public function onNoteDelete(mixed $id = ''): ?MadResponse
    {
        $this->forceFullRender();
        try {
            if (!$this->wbConfig['canDelete']) {
                throw new \DomainException(mad_t('mad.whiteboard.locked'));
            }
            $this->guardNote((string) $id)->delete();
        } catch (\Throwable $e) {
            return $this->refused($e);
        }

        return MadToast::success(mad_t('mad.whiteboard.deleted'));
    }

    /** Relê as notas (outro usuário mexeu). */
    public function onRefresh(): ?MadResponse
    {
        $this->forceFullRender();

        return null;
    }

    // ── Permissão ──────────────────────────────────────────────────────────

    /** Dono da permissão = a tela que contém o quadro. */
    public function _permOwner(): string
    {
        return $this->_permOwners()[0] ?? static::class;
    }

    /** @return list<string> */
    public function _permOwners(): array
    {
        $owner = trim((string) ($this->wbConfig['owner'] ?? ''));

        return $owner !== '' ? [$owner] : [];
    }

    // ── Config ─────────────────────────────────────────────────────────────

    /**
     * Normaliza os atributos do Blade. Colunas são validadas aqui (vão cruas
     * para o SQL).
     *
     * @param array<string,mixed> $a
     * @return array<string,mixed>
     */
    public static function config(array $a): array
    {
        $col = static fn (mixed $v, string $what, string $default = ''): string
            => self::column(trim((string) ($v ?? '')) ?: $default, $what);
        $bool = static function (mixed $v, bool $default): bool {
            if ($v === null || $v === '') return $default;
            if (is_bool($v)) return $v;
            return !in_array(strtolower(trim((string) $v)), ['false', '0', 'no', 'off', 'nao', 'não'], true);
        };

        $colors = self::normalizeColors($a['colors'] ?? null);
        $defaultColor = (string) ($a['defaultColor'] ?? '');
        if (!isset($colors[$defaultColor])) {
            $defaultColor = (string) array_key_first($colors);
        }
        $editable = $a['editableNotes'] ?? null;
        $grid = (int) ($a['grid'] ?? 24);

        return [
            'owner' => (string) ($a['owner'] ?? ''),
            'name' => (string) ($a['name'] ?? ''),
            'title' => (string) ($a['title'] ?? ''),
            'model' => trim((string) ($a['model'] ?? '')),
            'database' => trim((string) ($a['database'] ?? '')),
            'filters' => is_array($a['filters'] ?? null) ? $a['filters'] : [],
            'defaults' => is_array($a['defaults'] ?? null) ? $a['defaults'] : [],
            'order' => $col($a['orderBy'] ?? '', 'order-by'),
            'xField' => $col($a['xField'] ?? '', 'x-field', 'x'),
            'yField' => $col($a['yField'] ?? '', 'y-field', 'y'),
            'widthField' => $col($a['widthField'] ?? '', 'width-field'),
            'heightField' => $col($a['heightField'] ?? '', 'height-field'),
            'textField' => $col($a['textField'] ?? '', 'text-field', 'texto'),
            'colorField' => $col($a['colorField'] ?? '', 'color-field'),
            'metaField' => $col($a['metaField'] ?? '', 'meta-field'),
            'colors' => $colors,
            'defaultColor' => $defaultColor,
            'noteWidth' => max(120, min(800, (int) ($a['noteWidth'] ?? 200))),
            'minNoteWidth' => 120,
            'maxNoteWidth' => 800,
            'textMax' => max(1, min(5000, (int) ($a['textMax'] ?? 600))),
            'placeholder' => (string) ($a['placeholder'] ?? ''),
            'grid' => in_array($grid, [0, 8, 12, 16, 20, 24, 32, 40], true) ? $grid : 24,
            'snap' => $bool($a['snap'] ?? null, false),
            'locked' => $bool($a['locked'] ?? null, false),
            'canAdd' => $bool($a['canAdd'] ?? null, true),
            'canDelete' => $bool($a['canDelete'] ?? null, true),
            'editableNotes' => is_array($editable) ? array_values(array_map('strval', $editable)) : null,
            'zoom' => $bool($a['zoom'] ?? null, true),
            'minWidth' => max(320, (int) ($a['minWidth'] ?? 1100)),
            'minHeight' => max(240, (int) ($a['minHeight'] ?? 560)),
            'height' => (string) ($a['height'] ?? ''),
            'emptyText' => (string) ($a['emptyText'] ?? ''),
        ];
    }

    /** @return array<string,string> chave => cor CSS */
    public static function normalizeColors(mixed $colors): array
    {
        if (!is_array($colors) || $colors === []) {
            return self::DEFAULT_COLORS;
        }
        $out = [];
        foreach ($colors as $k => $v) {
            if (is_int($k)) {
                $k = (string) $v;
            }
            $k = trim((string) $k);
            $v = trim((string) $v);
            if ($k === '' || !preg_match('/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla|oklch)\([0-9.,%\s\/a-z-]+\)|var\(--[a-z0-9-]+\))$/i', $v)) {
                continue;
            }
            $out[$k] = $v;
        }

        return $out !== [] ? $out : self::DEFAULT_COLORS;
    }

    // ── Leitura ────────────────────────────────────────────────────────────

    /** @return list<array{id:string,x:int,y:int,w:int,h:int,text:string,color:string,meta:string,editable:bool}> */
    private function notes(): array
    {
        $c = $this->wbConfig;
        if ($c['model'] === '') {
            return [];
        }
        $q = $this->dataQuery();
        $key = $q->getModel()->getKeyName();
        $cols = array_values(array_unique(array_filter([$key, $c['xField'], $c['yField'], $c['widthField'], $c['heightField'], $c['textField'], $c['colorField'], $c['metaField']])));
        $q->orderBy($c['order'] !== '' ? $c['order'] : $key);

        $out = [];
        foreach ($q->limit(500)->get($cols) as $m) {
            $id = (string) $m->getRawOriginal($key);
            $color = $c['colorField'] !== '' ? (string) ($m->getRawOriginal($c['colorField']) ?? '') : '';
            $out[] = [
                'id' => $id,
                'x' => max(0, (int) $m->getRawOriginal($c['xField'])),
                'y' => max(0, (int) $m->getRawOriginal($c['yField'])),
                'w' => $c['widthField'] !== '' ? max(120, (int) ($m->getRawOriginal($c['widthField']) ?: $c['noteWidth'])) : (int) $c['noteWidth'],
                'h' => $c['heightField'] !== '' ? (int) ($m->getRawOriginal($c['heightField']) ?? 0) : 0,
                'text' => (string) ($m->getRawOriginal($c['textField']) ?? ''),
                'color' => isset($c['colors'][$color]) ? $color : $c['defaultColor'],
                'meta' => $c['metaField'] !== '' ? (string) ($m->getRawOriginal($c['metaField']) ?? '') : '',
                'editable' => $this->noteEditable($id),
            ];
        }

        return $out;
    }

    // ── Gravação ───────────────────────────────────────────────────────────

    private function locked(): bool
    {
        return $this->wbConfig['locked'] || $this->wbConfig['model'] === '';
    }

    private function noteEditable(string $id): bool
    {
        $e = $this->wbConfig['editableNotes'];

        return !$this->locked() && ($e === null || in_array($id, $e, true));
    }

    private function guardNote(string $id): Model
    {
        if ($this->locked()) {
            throw new \DomainException(mad_t('mad.whiteboard.locked'));
        }
        if ($id === '' || !$this->noteEditable($id)) {
            throw new \DomainException(mad_t('mad.whiteboard.note_locked'));
        }
        $note = $this->dataQuery()->whereKey($id)->first();
        if ($note === null) {
            throw new \DomainException(mad_t('mad.whiteboard.not_found'));
        }

        return $note;
    }

    private function coord(mixed $v): int
    {
        if (!is_numeric($v)) {
            throw new \DomainException(mad_t('mad.whiteboard.invalid'));
        }
        $n = max(0, min(self::MAX_COORD, (int) round((float) $v)));
        $g = (int) $this->wbConfig['grid'];

        return $this->wbConfig['snap'] && $g > 0 ? (int) (round($n / $g) * $g) : $n;
    }

    private function size(mixed $v, int $min, int $max): int
    {
        if (!is_numeric($v)) {
            throw new \DomainException(mad_t('mad.whiteboard.invalid'));
        }

        return max($min, min($max, (int) round((float) $v)));
    }

    private function saved(string $id): MadResponse
    {
        return (new MadResponse())->attr('[data-wb-note="' . str_replace(['\\', '"'], ['\\\\', '\\"'], $id) . '"]', 'data-wb-saved', (string) time());
    }

    /** Recusa: avisa e redesenha a partir do banco (desfaz o gesto otimista). */
    private function refused(\Throwable $e): MadResponse
    {
        $this->forceFullRender();
        $msg = $e instanceof \DomainException
            ? $e->getMessage()
            : MadUserError::message($e, mad_t('mad.whiteboard.save_failed'), static::class);

        return MadToast::danger($msg);
    }

    // ── Consultas ──────────────────────────────────────────────────────────

    private function newModel(): Model
    {
        $model = $this->wbConfig['model'];
        $cls = class_exists($model) ? $model : ModelOptionsLoader::resolveModelClass($model);
        if (!is_subclass_of($cls, Model::class)) {
            throw new \InvalidArgumentException("<mad-whiteboard>: model {$model} não encontrado.");
        }
        /** @var Model $m */
        $m = new $cls();
        if ($this->wbConfig['database'] !== '') {
            $m->setConnection($this->wbConfig['database']);
        }

        return $m;
    }

    private function dataQuery(): Builder
    {
        $q = $this->newModel()->newQuery();
        if ($this->wbConfig['filters'] !== []) {
            QuerySource::applyArrayFilters($q->getQuery(), $this->wbConfig['filters']);
        }

        return $q;
    }

    private static function column(string $name, string $what): string
    {
        if ($name !== '' && !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException("<mad-whiteboard>: {$what} inválido: {$name}");
        }

        return $name;
    }
}
