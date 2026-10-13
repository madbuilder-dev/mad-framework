<?php

namespace Mad\Webhook;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * A entrega que o handler do projeto recebe.
 *
 *     public function handle(\Mad\Webhook\WebhookDelivery $delivery): mixed
 *     {
 *         $pedido = $delivery->json('data.order_id');
 *         …
 *     }
 *
 * Serializa SÓ o id: numa fila, o corpo (que pode ter megabytes e dado pessoal)
 * não viaja no payload do job — a entrega é relida do banco quando o job roda.
 * A leitura é preguiçosa e feita uma vez por instância.
 */
final class WebhookDelivery
{
    private ?object $row = null;

    /** @var array<int|string, mixed>|null */
    private ?array $payload = null;

    public function __construct(private int $id, ?object $row = null)
    {
        $this->row = $row;
    }

    public function id(): int
    {
        return $this->id;
    }

    /** Slug do webhook que recebeu a entrega. */
    public function slug(): string
    {
        return (string) $this->row()->webhook;
    }

    /** Identificador do evento (o do remetente, ou um gerado quando ele não manda). */
    public function eventId(): string
    {
        return (string) $this->row()->event_id;
    }

    public function eventType(): ?string
    {
        $type = $this->row()->event_type ?? null;

        return $type === null || $type === '' ? null : (string) $type;
    }

    /** Corpo decodificado como JSON; `[]` quando não é um objeto ou lista JSON. */
    public function payload(): array
    {
        if ($this->payload === null) {
            $decoded = json_decode($this->raw(), true);
            $this->payload = is_array($decoded) ? $decoded : [];
        }

        return $this->payload;
    }

    /** Valor do JSON por caminho com ponto: `json('data.customer.email')`. */
    public function json(string $path, mixed $default = null): mixed
    {
        return Arr::get($this->payload(), $path, $default);
    }

    /** Os bytes exatos que chegaram. */
    public function raw(): string
    {
        return WebhookStore::rawBody($this->row());
    }

    /**
     * Cabeçalho da requisição, sem diferenciar maiúsculas. Cabeçalho de
     * credencial (assinatura, token, Authorization, Cookie) volta mascarado: o
     * valor real nunca é gravado.
     */
    public function header(string $name): ?string
    {
        return $this->headers()[strtolower($name)] ?? null;
    }

    /** @return array<string, string> nomes em minúsculas */
    public function headers(): array
    {
        return WebhookStore::headers($this->row());
    }

    /** Unidade dona do endpoint; nula em webhook de escopo geral. */
    public function unitId(): ?int
    {
        $unitId = $this->row()->unit_id ?? null;

        return $unitId === null || $unitId === '' ? null : (int) $unitId;
    }

    /** Número desta tentativa de processamento (1 na primeira). */
    public function attempt(): int
    {
        return max(1, (int) $this->row()->attempts);
    }

    public function receivedAt(): Carbon
    {
        return Carbon::parse((string) $this->row()->received_at);
    }

    /** @return array{id: int} */
    public function __serialize(): array
    {
        return ['id' => $this->id];
    }

    /** @param  array{id: int}  $data */
    public function __unserialize(array $data): void
    {
        $this->id = (int) $data['id'];
        $this->row = null;
        $this->payload = null;
    }

    private function row(): object
    {
        if ($this->row === null) {
            $this->row = WebhookStore::delivery($this->id);
            if ($this->row === null) {
                throw new \RuntimeException("Entrega de webhook #{$this->id} não existe mais.");
            }
        }

        return $this->row;
    }
}
