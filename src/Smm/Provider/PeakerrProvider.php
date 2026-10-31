<?php

declare(strict_types=1);

namespace App\Smm\Provider;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class PeakerrProvider implements SmmProviderInterface
{
    private const BASE_URL = 'https://peakerr.com/api/v2';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private string $apiKey = '',
    ) {}

    public function withApiKey(string $key): self
    {
        $clone = clone $this;
        $clone->apiKey = $key;
        return $clone;
    }

    public function getSlug(): string { return 'peakerr'; }
    public function getName(): string { return 'Peakerr'; }

    public function createOrder(string $serviceId, string $link, int $quantity, array $extra = []): string
    {
        $response = $this->httpClient->request('POST', self::BASE_URL, [
            'body' => array_merge([
                'key'      => $this->apiKey,
                'action'   => 'add',
                'service'  => $serviceId,
                'link'     => $link,
                'quantity' => $quantity,
            ], $extra),
        ]);

        $data = $response->toArray();

        if (isset($data['error'])) {
            throw new \RuntimeException('Peakerr error: ' . $data['error']);
        }

        return (string) $data['order'];
    }

    public function getOrderStatus(string $externalId): array
    {
        $response = $this->httpClient->request('POST', self::BASE_URL, [
            'body' => [
                'key'    => $this->apiKey,
                'action' => 'status',
                'order'  => $externalId,
            ],
        ]);

        $data = $response->toArray();

        return [
            'status'      => $data['status'] ?? 'unknown',
            'start_count' => (int) ($data['start_count'] ?? 0),
            'remains'     => (int) ($data['remains'] ?? 0),
        ];
    }

    public function listServices(): array
    {
        $response = $this->httpClient->request('POST', self::BASE_URL, [
            'body' => [
                'key'    => $this->apiKey,
                'action' => 'services',
            ],
        ]);

        return array_map(fn(array $s) => [
            'id'   => (string) $s['service'],
            'name' => $s['name'],
            'type' => $s['type'] ?? 'Default',
            'min'  => (int) $s['min'],
            'max'  => (int) $s['max'],
            'rate' => (float) $s['rate'],
        ], $response->toArray());
    }
}
