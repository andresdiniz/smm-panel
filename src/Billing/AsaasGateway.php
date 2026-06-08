<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gateway Asaas — Pix, cartão de crédito e débito.
 *
 * Docs: https://docs.asaas.com/reference
 *
 * Credenciais gerenciadas pelo painel admin (banco de dados).
 * Não é necessário definir variáveis de ambiente para este gateway.
 */
final class AsaasGateway extends AbstractGateway implements PaymentGatewayInterface
{
    /**
     * CPF genérico válido aceito pelo sandbox Asaas.
     * Em produção, substitua pelo CPF real do usuário.
     */
    private const SANDBOX_CPF = '00000000191';

    public function __construct(
        EntityManagerInterface              $em,
        private readonly HttpClientInterface $http,
        private readonly string              $apiKey,
        private readonly string              $baseUrl,
        private readonly string              $webhookToken,
    ) {
        parent::__construct($em);
    }

    public function createDeposit(User $user, int $amountCents, string $method): Payment
    {
        $billingType = match ($method) {
            Payment::METHOD_PIX         => 'PIX',
            Payment::METHOD_CREDIT_CARD => 'CREDIT_CARD',
            Payment::METHOD_DEBIT_CARD  => 'DEBIT',
            default                     => throw new \InvalidArgumentException('Método inválido: ' . $method),
        };

        $feeRate = match ($method) {
            Payment::METHOD_PIX         => 0.0099,
            Payment::METHOD_CREDIT_CARD => 0.0299,
            Payment::METHOD_DEBIT_CARD  => 0.0199,
            default                     => 0.0,
        };
        $feeCents = (int) ceil($amountCents * $feeRate);

        $customerId = $this->ensureCustomer($user);

        $response = $this->http->request('POST', $this->baseUrl . '/payments', [
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'customer'    => $customerId,
                'billingType' => $billingType,
                'value'       => round($amountCents / 100, 2),
                'dueDate'     => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
                'description' => 'Recarga PulseSMM',
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $data       = $response->toArray(throw: false);

        if ($statusCode !== 200 && $statusCode !== 201) {
            $errorBody = json_encode($data, JSON_UNESCAPED_UNICODE);
            throw new \RuntimeException(
                sprintf('Asaas retornou HTTP %d ao criar cobrança: %s', $statusCode, $errorBody)
            );
        }

        if (!isset($data['id'])) {
            throw new \RuntimeException('Asaas não retornou ID da cobrança: ' . json_encode($data));
        }

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId($data['id']);
        $payment->setGatewayResponse(json_encode($data));

        if ($billingType === 'PIX') {
            $qrData = $this->fetchPixQrWithRetry($data['id']);
            $payment->setPixCode($qrData['payload'] ?? null);
            $payment->setQrCodeBase64($qrData['encodedImage'] ?? null);
        }

        $this->persist($payment);
        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        if ($request->headers->get('asaas-access-token') !== $this->webhookToken) {
            throw new \InvalidArgumentException('Assinatura de webhook inválida.');
        }

        $data     = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $event    = $data['event'] ?? '';
        $chargeId = $data['payment']['id'] ?? throw new \InvalidArgumentException('Webhook sem ID.');

        $payment = $this->findByExternalId($chargeId);
        $payment->setGatewayResponse(json_encode($data));

        if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'], true)) {
            $this->approveAndCredit($payment);
        } elseif (in_array($event, ['PAYMENT_OVERDUE', 'PAYMENT_CANCELLED'], true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $this->em->flush();
        } elseif ($event === 'PAYMENT_REFUNDED') {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();
        }

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        $response = $this->http->request('GET', $this->baseUrl . '/payments/' . $payment->getExternalId(), [
            'headers' => ['access_token' => $this->apiKey],
        ]);
        $data   = $response->toArray();
        $remote = $data['status'] ?? 'PENDING';

        $map = [
            'RECEIVED'  => Payment::STATUS_APPROVED,
            'CONFIRMED' => Payment::STATUS_APPROVED,
            'OVERDUE'   => Payment::STATUS_FAILED,
            'CANCELLED' => Payment::STATUS_CANCELLED,
            'REFUNDED'  => Payment::STATUS_REFUNDED,
        ];

        return $map[$remote] ?? Payment::STATUS_PENDING;
    }

    /**
     * Cria ou reutiliza cliente no Asaas.
     *
     * O Asaas retorna 200 mesmo se o cliente já existir (idempotente por e-mail
     * quando externalReference é omitido). O cpfCnpj é obrigatório para
     * cobranças PIX; no sandbox usamos um CPF genérico válido.
     */
    private function ensureCustomer(User $user): string
    {
        $isSandbox = str_contains($this->baseUrl, 'sandbox');

        $payload = [
            'name'              => $user->getName(),
            'email'             => $user->getEmail(),
            'externalReference' => (string) $user->getId(),
            'cpfCnpj'           => $isSandbox ? self::SANDBOX_CPF : '',
        ];

        $response = $this->http->request('POST', $this->baseUrl . '/customers', [
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $data       = $response->toArray(throw: false);

        // Cliente já existente: busca pelo externalReference
        if ($statusCode === 400 && isset($data['errors'])) {
            $existing = $this->http->request('GET', $this->baseUrl . '/customers', [
                'headers'       => ['access_token' => $this->apiKey],
                'query'         => ['externalReference' => (string) $user->getId()],
            ]);
            $list = $existing->toArray(throw: false);
            if (!empty($list['data'][0]['id'])) {
                return $list['data'][0]['id'];
            }
        }

        if (!isset($data['id'])) {
            throw new \RuntimeException(
                sprintf('Asaas: falha ao criar cliente (HTTP %d): %s', $statusCode, json_encode($data, JSON_UNESCAPED_UNICODE))
            );
        }

        return $data['id'];
    }

    /**
     * Busca QR Code Pix com até 5 tentativas com delay progressivo.
     *
     * O sandbox Asaas pode demorar alguns segundos para gerar o QR Code
     * após criar a cobrança. Usamos backoff crescente (1s, 2s, 3s, 4s)
     * para dar tempo ao gateway sem travar a requisição desnecessariamente.
     */
    private function fetchPixQrWithRetry(string $paymentId, int $maxAttempts = 5): array
    {
        $url  = $this->baseUrl . '/payments/' . $paymentId . '/pixQrCode';
        $data = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->http->request('GET', $url, [
                'headers' => ['access_token' => $this->apiKey],
            ]);

            $data = $response->toArray(throw: false);

            if (!empty($data['encodedImage']) && !empty($data['payload'])) {
                return $data;
            }

            // Delay progressivo antes da próxima tentativa: 1s, 2s, 3s, 4s
            if ($attempt < $maxAttempts) {
                sleep($attempt);
            }
        }

        return $data;
    }
}
