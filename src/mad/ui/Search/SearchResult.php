<?php

declare(strict_types=1);

namespace Mad\Ui\Search;

/**
 * Um registro achado pela busca ⌘K.
 *
 *   new SearchResult('Concretagem do bloco B', 'ATV-0142 · Em andamento',
 *       screen: 'AtividadeForm', id: 142);            // abre AtividadeForm::onEdit(id=142)
 *   new SearchResult('Ana Souza', 'Engenharia', screen: 'PessoaView', method: 'show', id: 7);
 *   new SearchResult('Contrato', url: 'https://…');    // destino pronto
 *
 * Destino: a tela (`screen`, basename da classe) + `method` (padrão `onEdit`
 * quando há `id`, senão `show`) + `params`. Tela de cortina lateral ou janela
 * abre por cima da tela atual; tela inteira abre como o link do menu. O
 * usuário precisa poder abrir a tela: sem permissão o item sai da lista.
 *
 * Também aceito como array com as mesmas chaves (`title`, `subtitle`, `icon`,
 * `screen`, `method`, `id`, `params`, `url`).
 */
final class SearchResult
{
    /** @param array<string, scalar|null> $params */
    public function __construct(
        public readonly string $title,
        public readonly string $subtitle = '',
        public readonly string $icon = '',
        public readonly string $screen = '',
        public readonly ?string $method = null,
        public readonly int|string|null $id = null,
        public readonly array $params = [],
        public readonly string $url = '',
    ) {
    }

    /** @param self|array<string, mixed> $value */
    public static function from(self|array $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        $title = trim((string) ($value['title'] ?? $value['label'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('item da busca sem título (chave "title")');
        }

        $id = $value['id'] ?? null;

        return new self(
            title: $title,
            subtitle: (string) ($value['subtitle'] ?? ''),
            icon: (string) ($value['icon'] ?? ''),
            screen: (string) ($value['screen'] ?? ''),
            method: isset($value['method']) && $value['method'] !== '' ? (string) $value['method'] : null,
            id: is_int($id) || is_string($id) ? $id : (is_numeric($id) ? (int) $id : null),
            params: (array) ($value['params'] ?? []),
            url: (string) ($value['url'] ?? ''),
        );
    }

    /** Método de destino já resolvido. */
    public function targetMethod(): string
    {
        return $this->method ?? ($this->id !== null ? 'onEdit' : 'show');
    }

    /** @return array<string, scalar|null> */
    public function targetParams(): array
    {
        return $this->id !== null ? ['id' => $this->id] + $this->params : $this->params;
    }
}
