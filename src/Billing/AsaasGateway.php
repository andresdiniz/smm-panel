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
 * Variáveis de ambiente necessárias:
 *   ASAAS_API_KEY=aact_...
 *   ASAAS_BASE_URL=https://api.asaas.com/v3          (produção)
 *                  https://sandbox.asaas.com/api/v3   (sandbox)
 *   ASAAS_WEBHOOK_TOKEN=token_secreto
 */
final class AsaasGateway extends AbstractGateway implements PaymentGatewayInterface
{
    public function __construct(
        EntityManagerInterface          $em,
        private readonly HttpClientInterface $http,
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $webhookToken,
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

        // Calcula taxa: Pix 0,99% | Crédito 2,99% | Débito 1,99%
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

        $data = $response->toArray();

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId($data['id']);
        $payment->setGatewayResponse(json_encode($data));

        if ($billingType === 'PIX') {
            $qrData = $this->fetchPixQr($data['id']);
            $payment->setPixCode($qrData['payload'] ?? null);
            $payment->setQrCodeBase64($qrData['encodedImage'] ?? null);
        }

        $this->persist($payment);
        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        // Valida token de autenticação do webhook Asaas
        if ($request->headers->get('asaas-access-token') !== $this->webhookToken) {
            throw new \InvalidArgumentException('Assinatura de webhook inválida.');
        }

        $data    = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $event   = $data['event'] ?? '';
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

    private function ensureCustomer(User $user): string
    {
        // Tenta criar cliente no Asaas (idempotente por e-mail)
        $response = $this->http->request('POST', $this->baseUrl . '/customers', [
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'name'  => $user->getName(),
                'email' => $user->getEmail(),
            ],
        ]);
        return $response->toArray()['id'];
    }

    private function fetchPixQr(string $paymentId): array
    {
        $response = $this->http->request(
            'GET',
            $this->baseUrl . '/payments/' . $paymentId . '/pixQrCode',
            ['headers' => ['access_token' => $this->apiKey]]
        );
        return $response->toArray();
    }
}
