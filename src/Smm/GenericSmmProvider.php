<?php

declare(strict_types=1);

namespace App\Smm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implementação genérica para APIs SMM padronizadas.
 *
 * Compatível com: JustAnotherPanel, SMMKings, Peakerr, SMMFollows,
 *                 SMMHeaven, GoodSMM, SubMall e qualquer painel
 *                 que siga o protocolo REST padrão do mercado.
 *
 * Protocolo:
 *   POST {base_url}
 *   Content-Type: application/x-www-form-urlencoded
 *   key=API_KEY&action=ACTION[&...params]
 *
 * Para adicionar um novo provider, basta registrar um novo serviço
 * em services.yaml com slug, base_url e api_key diferentes.
 */
final class GenericSmmProvider implements SmmProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $slug,
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function addOrder(string $serviceId, string $targetUrl, int $quantity): string
    {
        $data = $this->post([
            'action'   => 'add',
            'service'  => $serviceId,
            'link'     => $targetUrl,
            'quantity' => $quantity,
        ]);

        if (isset($data['error'])) {
            throw new \RuntimeException('[' . $this->slug . '] Erro ao criar pedido: ' . $data['error']);
        }

        return (string) ($data['order'] ?? throw new \RuntimeException('[' . $this->slug . '] Resposta sem order ID.'));
    }

    public function getOrderStatus(string $orderId): array
    {
        $data = $this->post([
            'action' => 'status',
            'order'  => $orderId,
        ]);

        if (isset($data['error'])) {
            throw new \RuntimeException('[' . $this->slug . '] Erro ao consultar status: ' . $data['error']);
        }

        return [
            'status'      => strtolower($data['status'] ?? 'pending'),
            'start_count' => (int) ($data['start_count'] ?? 0),
            'remains'     => (int) ($data['remains'] ?? 0),
            'charge'      => (float) ($data['charge'] ?? 0),
        ];
    }

    public function getBalance(): float
    {
        $data = $this->post(['action' => 'balance']);
        return (float) ($data['balance'] ?? 0);
    }

    public function getServices(): array
    {
        return $this->post(['action' => 'services']);
    }

    /** @param array<string, mixed> $params */
    private function post(array $params): array
    {
        $response = $this->http->request('POST', $this->baseUrl, [
            'body' => array_merge(['key' => $this->apiKey], $params),
        ]);

        return $response->toArray(false);
    }
}
