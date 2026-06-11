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
 */
final class AsaasGateway extends AbstractGateway implements PaymentGatewayInterface
{
    /** CPF genérico válido para o sandbox Asaas (somente quando nenhum CPF real é fornecido). */
    private const SANDBOX_CPF = '00000000191';

    /**
     * URLs canônicas oficiais — sem redirect.
     *   Produção : https://api.asaas.com/v3
     *   Sandbox  : https://sandbox.asaas.com/api/v3
     *
     * Qualquer outra variação (www.asaas.com, asaas.com, etc.) sofre
     * redirect 301 que converte POST → GET e esvazia o body.
     */
    private const URL_PRODUCTION = 'https://api.asaas.com/v3';
    private const URL_SANDBOX    = 'https://sandbox.asaas.com/api/v3';

    /** URL já normalizada usada em todas as requisições. */
    private readonly string $apiBaseUrl;

    private const EVENTS_APPROVE = [
        'PAYMENT_RECEIVED',
        'PAYMENT_CONFIRMED',
    ];

    private const EVENTS_CANCEL = [
        'PAYMENT_OVERDUE',
        'PAYMENT_DELETED',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
        'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
    ];

    private const EVENTS_REFUND = [
        'PAYMENT_REFUNDED',
        'PAYMENT_PARTIALLY_REFUNDED',
        'PAYMENT_CHARGEBACK_REQUESTED',
        'PAYMENT_CHARGEBACK_DISPUTE',
        'PAYMENT_AWAITING_CHARGEBACK_REVERSAL',
    ];

    public function __construct(
        EntityManagerInterface               $em,
        private readonly HttpClientInterface  $http,
        private readonly string               $apiKey,
        string                                $baseUrl,
        private readonly string               $webhookToken,
    ) {
        parent::__construct($em);
        $this->apiBaseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    /**
     * Normaliza qualquer variação de URL cadastrada no banco para a URL
     * canônica correta, evitando redirects 301 que quebram POST requests.
     *
     * Exemplos aceitos:
     *   https://api.asaas.com/v3          → produção (canônica)
     *   https://www.asaas.com/api/v3      → produção (normaliza)
     *   https://asaas.com/api/v3          → produção (normaliza)
     *   https://sandbox.asaas.com/api/v3  → sandbox (canônica)
     */
    private function normalizeBaseUrl(string $url): string
    {
        $url = rtrim($url, '/');

        if (str_contains($url, 'sandbox')) {
            return self::URL_SANDBOX;
        }

        return self::URL_PRODUCTION;
    }

    public function createDeposit(
        User   $user,
        int    $amountCents,
        string $method,
        string $cpf   = '',
        string $phone = '',
    ): Payment {
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

        $customerId = $this->ensureCustomer($user, $cpf, $phone);

        $response = $this->http->request('POST', $this->apiBaseUrl . '/payments', [
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
            throw new \RuntimeException(
                sprintf('Asaas retornou HTTP %d ao criar cobrança: %s', $statusCode, json_encode($data, JSON_UNESCAPED_UNICODE))
            );
        }

        if (($data['object'] ?? '') === 'list' && !empty($data['data'][0]['id'])) {
            $data = $data['data'][0];
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
        $token = $request->headers->get('asaas-access-token', '');
        if (!hash_equals($this->webhookToken, $token)) {
            throw new \InvalidArgumentException('asaas-access-token inválido.');
        }

        $data     = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $event    = $data['event'] ?? '';
        $chargeId = $data['payment']['id'] ?? null;

        if (!$chargeId) {
            throw new \InvalidArgumentException('Webhook sem payment.id — evento ignorado: ' . $event);
        }

        $payment = $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $chargeId]);

        if ($payment === null) {
            $ghost = new Payment();
            $ghost->setExternalId($chargeId);
            $ghost->setStatus(Payment::STATUS_PENDING);
            $ghost->setGatewayResponse(json_encode($data, JSON_UNESCAPED_UNICODE));
            return $ghost;
        }

        $payment->setGatewayResponse(json_encode($data, JSON_UNESCAPED_UNICODE));

        $currentStatus = $payment->getStatus();
        $finalStatuses = [Payment::STATUS_APPROVED, Payment::STATUS_REFUNDED, Payment::STATUS_CANCELLED];

        if (in_array($currentStatus, $finalStatuses, true)
            && !in_array($event, ['PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED'], true)
        ) {
            $this->em->flush();
            return $payment;
        }

        if (in_array($event, self::EVENTS_APPROVE, true)) {
            $this->approveAndCredit($payment);
        } elseif (in_array($event, self::EVENTS_CANCEL, true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $this->em->flush();
        } elseif (in_array($event, self::EVENTS_REFUND, true)) {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();
        } else {
            $this->em->flush();
        }

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        $response = $this->http->request('GET', $this->apiBaseUrl . '/payments/' . $payment->getExternalId(), [
            'headers' => ['access_token' => $this->apiKey],
        ]);
        $data   = $response->toArray(throw: false);
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

    private function ensureCustomer(User $user, string $cpf = '', string $phone = ''): string
    {
        $isSandbox = $this->apiBaseUrl === self::URL_SANDBOX;

        if ($cpf === '') {
            if ($isSandbox) {
                $cpf = self::SANDBOX_CPF;
            } else {
                throw new \RuntimeException('CPF obrigatório para criar cobrança em produção.');
            }
        }

        $mobilePhone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($mobilePhone, '55') && strlen($mobilePhone) > 11) {
            $mobilePhone = substr($mobilePhone, 2);
        }

        $payload = [
            'name'              => $user->getName(),
            'email'             => $user->getEmail(),
            'externalReference' => (string) $user->getId(),
            'cpfCnpj'           => $cpf,
        ];

        if ($mobilePhone !== '') {
            $payload['mobilePhone'] = $mobilePhone;
        }

        $response = $this->http->request('POST', $this->apiBaseUrl . '/customers', [
            'headers' => [
                'access_token' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => $payload,
        ]);

        $statusCode = $response->getStatusCode();
        $data       = $response->toArray(throw: false);

        if (($data['object'] ?? '') === 'list' && !empty($data['data'][0]['id'])) {
            return $data['data'][0]['id'];
        }

        if ($statusCode === 400 && isset($data['errors'])) {
            $existing = $this->http->request('GET', $this->apiBaseUrl . '/customers', [
                'headers' => ['access_token' => $this->apiKey],
                'query'   => ['externalReference' => (string) $user->getId()],
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

    private function fetchPixQrWithRetry(string $paymentId, int $maxAttempts = 5): array
    {
        $url  = $this->apiBaseUrl . '/payments/' . $paymentId . '/pixQrCode';
        $data = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $response = $this->http->request('GET', $url, [
                'headers' => ['access_token' => $this->apiKey],
            ]);

            $data = $response->toArray(throw: false);

            if (!empty($data['encodedImage']) && !empty($data['payload'])) {
                return $data;
            }

            if ($attempt < $maxAttempts) {
                sleep($attempt);
            }
        }

        return $data;
    }
}
