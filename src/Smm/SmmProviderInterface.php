<?php

declare(strict_types=1);

namespace App\Smm;

/**
 * Contrato para APIs SMM padronizadas (JustAnotherPanel, SMMKings, Peakerr, etc.).
 *
 * Todas as APIs SMM de mercado seguem o mesmo formato REST com chave `key` + `action`.
 * Cada implementação deve apenas configurar a URL base e a chave da API.
 */
interface SmmProviderInterface
{
    /**
     * Retorna o slug identificador do provider (ex: 'smmkings', 'justanother').
     */
    public function getSlug(): string;

    /**
     * Cria um novo pedido no provider externo.
     *
     * @param string $serviceId  ID do serviço no provider
     * @param string $targetUrl  URL alvo (perfil, post, vídeo)
     * @param int    $quantity   Quantidade
     *
     * @return string ID do pedido no provider
     */
    public function addOrder(string $serviceId, string $targetUrl, int $quantity): string;

    /**
     * Consulta o status de um pedido no provider.
     *
     * @param string $orderId ID externo do pedido
     *
     * @return array{status: string, start_count: int, remains: int, charge: float}
     */
    public function getOrderStatus(string $orderId): array;

    /**
     * Retorna o saldo disponível na conta do provider.
     */
    public function getBalance(): float;

    /**
     * Lista todos os serviços disponíveis no provider.
     *
     * @return array<int, array{service: string, name: string, type: string, rate: string, min: string, max: string, category: string}>
     */
    public function getServices(): array;
}
