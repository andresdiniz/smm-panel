<?php

namespace App\Service\Asaas;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AsaasClient
{
    public function __construct(
        private readonly HttpClientInterface $asaasClient,
        private readonly string $apiKey,
    ) {}

    public function request(string $method, string $path, array $options = []): array
    {
        $response = $this->asaasClient->request($method, $path, array_merge([
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ], $options));

        $content = $response->getContent(false);
        $data = json_decode($content, true);

        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Asaas API error (%d): %s', $response->getStatusCode(), $content));
        }

        return is_array($data) ? $data : [];
    }

    public function createCustomer(string $name, string $email, string $cpfCnpj): array
    {
        return $this->request('POST', '/customers', [
            'json' => [
                'name' => $name,
                'email' => $email,
                'cpfCnpj' => preg_replace('/\D/', '', $cpfCnpj),
            ],
        ]);
    }

    public function createPixPayment(string $customerId, string $amount, string $reference, \DateTimeInterface $dueDate): array
    {
        return $this->request('POST', '/payments', [
            'json' => [
                'customer' => $customerId,
                'billingType' => 'PIX',
                'value' => $amount,
                'dueDate' => $dueDate->format('Y-m-d'),
                'description' => 'Add funds - ' . $reference,
                'externalReference' => $reference,
            ],
        ]);
    }

    public function getPixQrCode(string $paymentId): array
    {
        return $this->request('GET', sprintf('/payments/%s/pixQrCode', $paymentId));
    }

    public function getPayment(string $paymentId): array
    {
        return $this->request('GET', sprintf('/payments/%s', $paymentId));
    }
}
