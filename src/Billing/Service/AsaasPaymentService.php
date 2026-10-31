<?php

declare(strict_types=1);

namespace App\Billing\Service;

use App\Billing\Dto\PixChargeResult;
use App\Billing\Dto\CardChargeResult;
use App\Billing\Exception\GatewayException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AsaasPaymentService
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string              $apiKey,
        private readonly string              $baseUrl,
    ) {}

    public function createPixCharge(
        string $customerId,
        int    $amountCents,
        string $description,
        int    $dueInMinutes = 30,
    ): PixChargeResult {
        $response = $this->post('/payments', [
            'customer'          => $customerId,
            'billingType'       => 'PIX',
            'value'             => $amountCents / 100,
            'dueDate'           => (new \DateTimeImmutable("+{$dueInMinutes} minutes"))->format('Y-m-d'),
            'description'       => $description,
        ]);

        // Busca QR Code
        $qr = $this->get("/payments/{$response['id']}/pixQrCode");

        return new PixChargeResult(
            gatewayId:    $response['id'],
            pixPayload:   $qr['payload'],
            qrCodeBase64: $qr['encodedImage'],
            expiresAt:    new \DateTimeImmutable("+{$dueInMinutes} minutes"),
        );
    }

    public function createCreditCardCharge(
        string $customerId,
        int    $amountCents,
        string $description,
        array  $card,       // tokenized card data
        int    $installments = 1,
    ): CardChargeResult {
        $response = $this->post('/payments', [
            'customer'            => $customerId,
            'billingType'         => 'CREDIT_CARD',
            'value'               => $amountCents / 100,
            'dueDate'             => (new \DateTimeImmutable())->format('Y-m-d'),
            'description'         => $description,
            'installmentCount'    => $installments,
            'installmentValue'    => round(($amountCents / 100) / $installments, 2),
            'creditCard'          => $card,
        ]);

        return new CardChargeResult(
            gatewayId: $response['id'],
            status:    $response['status'],
        );
    }

    public function getOrCreateCustomer(string $name, string $email, string $cpfCnpj): string
    {
        // Busca por CPF/CNPJ primeiro
        $result = $this->get('/customers', ['cpfCnpj' => $cpfCnpj]);
        if (!empty($result['data'])) {
            return $result['data'][0]['id'];
        }

        $customer = $this->post('/customers', [
            'name'    => $name,
            'email'   => $email,
            'cpfCnpj' => $cpfCnpj,
        ]);

        return $customer['id'];
    }

    private function post(string $path, array $body): array
    {
        $response = $this->httpClient->request('POST', $this->baseUrl . $path, [
            'headers' => ['access_token' => $this->apiKey],
            'json'    => $body,
        ]);

        $data = $response->toArray(throw: false);

        if ($response->getStatusCode() >= 400) {
            throw new GatewayException('Asaas error: ' . json_encode($data));
        }

        return $data;
    }

    private function get(string $path, array $query = []): array
    {
        $response = $this->httpClient->request('GET', $this->baseUrl . $path, [
            'headers'       => ['access_token' => $this->apiKey],
            'query'         => $query,
        ]);

        return $response->toArray(throw: false);
    }
}
