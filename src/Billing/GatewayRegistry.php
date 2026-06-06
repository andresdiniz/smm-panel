<?php

declare(strict_types=1);

namespace App\Billing;

/**
 * Registro de gateways identificados por slug.
 * Permite roteamento dinâmico no WebhookController.
 *
 * Serviços tagueados com { name: app.payment_gateway, alias: <slug> }
 * são injetados automaticamente pelo Symfony via tagged_iterator.
 */
final class GatewayRegistry
{
    /** @var array<string, PaymentGatewayInterface> */
    private array $gateways = [];

    /**
     * @param iterable<PaymentGatewayInterface> $gateways
     */
    public function __construct(iterable $gateways)
    {
        foreach ($gateways as $slug => $gateway) {
            $this->gateways[$slug] = $gateway;
        }
    }

    public function get(string $slug): PaymentGatewayInterface
    {
        return $this->gateways[$slug]
            ?? throw new \InvalidArgumentException("Gateway desconhecido: {$slug}");
    }

    public function has(string $slug): bool
    {
        return isset($this->gateways[$slug]);
    }

    /** @return string[] */
    public function slugs(): array
    {
        return array_keys($this->gateways);
    }
}
