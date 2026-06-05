<?php

declare(strict_types=1);

namespace App\Smm\Provider;

use App\Smm\Dto\ProviderStatus;
use App\Smm\Exception\ProviderApiException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class JustAnotherPanelProvider implements SmmProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string              $apiKey,
        private readonly string              $baseUrl,
    ) {}

    public function getSlug(): string { return 'justanotherpanel'; }

    public function placeOrder(string $serviceId, string $link, int $quantity): string
    {
        $data = $this->request([
            'action'   => 'add',
            'service'  => $serviceId,
            'link'     => $link,
            'quantity' => $quantity,
        ]);

        return (string) ($data['order'] ?? throw new ProviderApiException('No order ID'));
    }

    public function getOrderStatus(string $externalId): ProviderStatus
    {
        $data = $this->request(['action' => 'status', 'order' => $externalId]);

        return new ProviderStatus(
            state:     strtolower($data['status'] ?? 'pending'),
            delivered: (int) ($data['start_count'] ?? 0) - (int) ($data['remains'] ?? 0),
        );
    }

    public function getBalance(): float
    {
        $data = $this->request(['action' => 'balance']);
        return (float) ($data['balance'] ?? 0);
    }

    public function getServices(): array
    {
        return $this->request(['action' => 'services']);
    }

    private function request(array $params): array
    {
        $params['key'] = $this->apiKey;
        $response = $this->httpClient->request('POST', $this->baseUrl, ['body' => $params]);
        $data     = $response->toArray(throw: false);

        if (isset($data['error'])) {
            throw new ProviderApiException($data['error']);
        }

        return $data;
    }
}
