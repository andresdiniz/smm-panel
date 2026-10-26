<?php

namespace App\Service\Smm;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class SmmProviderClient
{
    public function __construct(
        private readonly HttpClientInterface $smmClient,
        private readonly string $apiKey,
    ) {}

    public function request(array $data): array
    {
        $data['key'] = $this->apiKey;

        $response = $this->smmClient->request('POST', '', [
            'body' => $data,
        ]);

        $content = $response->getContent(false);
        $decoded = json_decode($content, true);

        if ($response->getStatusCode() >= 400 || !is_array($decoded)) {
            throw new \RuntimeException('Invalid SMM provider response: ' . $content);
        }

        return $decoded;
    }

    public function services(): array
    {
        return $this->request(['action' => 'services']);
    }

    public function addOrder(string $serviceId, string $link, int $quantity): array
    {
        return $this->request([
            'action' => 'add',
            'service' => $serviceId,
            'link' => $link,
            'quantity' => $quantity,
        ]);
    }

    public function status(string $orderId): array
    {
        return $this->request(['action' => 'status', 'order' => $orderId]);
    }

    public function balance(): array
    {
        return $this->request(['action' => 'balance']);
    }
}
