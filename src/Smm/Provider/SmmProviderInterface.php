<?php

declare(strict_types=1);

namespace App\Smm\Provider;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('smm.provider')]
interface SmmProviderInterface
{
    public function getSlug(): string;

    public function getName(): string;

    /**
     * Cria um pedido no provider externo.
     * Retorna o ID externo do pedido.
     */
    public function createOrder(string $serviceId, string $link, int $quantity, array $extra = []): string;

    /**
     * Consulta o status de um pedido pelo ID externo.
     * Retorna array com 'status', 'start_count', 'remains'.
     *
     * @return array{status: string, start_count: int, remains: int}
     */
    public function getOrderStatus(string $externalId): array;

    /**
     * Lista os serviços disponíveis no provider.
     *
     * @return array<int, array{id: string, name: string, type: string, min: int, max: int, rate: float}>
     */
    public function listServices(): array;
}
