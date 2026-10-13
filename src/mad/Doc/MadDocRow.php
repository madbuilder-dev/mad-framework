<?php

namespace Mad\Doc;

/**
 * Linha de `<mad-doc-repeater>` que chegou como ARRAY.
 *
 * O corpo do repetidor lê a linha como registro (`{{ $record->descricao }}`),
 * que é o que o Studio escreve. Lista montada à mão — no gancho do documento,
 * numa coluna JSON — traz arrays, e `$array->campo` é erro fatal do PHP. Esta
 * casca deixa a linha responder das duas formas:
 *
 *   $record->descricao        campo (ausente = null, nunca erro)
 *   $record->produto->nome    array associativo de dentro vira linha também
 *   $record->complementos     lista de dentro sai como array (outro repetidor)
 *   $record['descricao']      continua valendo para quem já lia por colchetes
 */
final class MadDocRow implements \ArrayAccess, \Countable, \IteratorAggregate, \JsonSerializable
{
    public function __construct(private array $data)
    {
    }

    public function __get(string $name): mixed
    {
        $value = $this->data[$name] ?? null;

        return is_array($value) && $value !== [] && ! array_is_list($value)
            ? new self($value)
            : $value;
    }

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __unset(string $name): void
    {
        unset($this->data[$name]);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function count(): int
    {
        return count($this->data);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->data);
    }

    public function jsonSerialize(): array
    {
        return $this->data;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
