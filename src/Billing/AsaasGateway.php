<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Billing\Exception\GatewayException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\ResponseInterface;
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
     *   Sandbox  : https://api-sandbox.asaas.com/v3
     *
     * A seleção fixa evita redirects e impede que uma URL salva no banco
     * altere o destino da chave de API.
     */
    private const URL_PRODUCTION = 'https://api.asaas.com/v3';
    private const URL_SANDBOX    = 'https://api-sandbox.asaas.com/v3';

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
        string                                $environment,
        private readonly string               $webhookToken,
    ) {
        parent::__construct($em);
        $this->apiBaseUrl = match (strtolower(trim($environment))) {
            'sandbox' => self::URL_SANDBOX,
            'production' => self::URL_PRODUCTION,
            default => throw new \InvalidArgumentException('Ambiente Asaas inválido. Use sandbox ou production.'),
        };
    }

    public function createDeposit(
        User   $user,
        int    $amountCents,
        string $method,
        string $cpf   = '',
        string $phone = '',
    ): Payment {
        if ($amountCents < 1000) {
            throw new \InvalidArgumentException('O valor mínimo de depósito é R$ 10,00.');
        }

        $billingType = match ($method) {
            Payment::METHOD_PIX         => 'PIX',
            Payment::METHOD_CREDIT_CARD => 'CREDIT_CARD',
            Payment::METHOD_DEBIT_CARD  => 'DEBIT_CARD',
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

        $data = $this->requestJson('POST', '/payments', [
            'json' => [
                'customer'    => $customerId,
                'billingType' => $billingType,
                'value'       => round($amountCents / 100, 2),
                'dueDate'     => (new \DateTimeImmutable('+1 day'))->format('Y-m-d'),
                'description' => 'Recarga PulseSMM',
            ],
        ]);

        if (($data['object'] ?? '') === 'list' && !empty($data['data'][0]['id'])) {
            $data = $data['data'][0];
        }

        if (!is_string($data['id'] ?? null) || $data['id'] === '') {
            throw new GatewayException('Asaas não retornou o identificador da cobrança.');
        }

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId($data['id']);
        $payment->setGatewayResponse(json_encode([
            'id' => $data['id'],
            'status' => $data['status'] ?? null,
            'billingType' => $data['billingType'] ?? $billingType,
            'invoiceUrl' => $data['invoiceUrl'] ?? null,
        ], JSON_THROW_ON_ERROR));
        $this->persist($payment);

        if ($billingType === 'PIX') {
            $qrData = $this->fetchPixQrWithRetry($data['id']);
            $payment->setPixCode($qrData['payload'] ?? null);
            $payment->setQrCodeBase64($qrData['encodedImage'] ?? null);
            $this->em->flush();
        }

        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        $token = $request->headers->get('asaas-access-token', '');
        if ($this->webhookToken === '' || $token === '' || !hash_equals($this->webhookToken, $token)) {
            throw new \InvalidArgumentException('Token de webhook Asaas inválido ou não configurado.');
        }

        if (strlen($request->getContent()) > 65536) {
            throw new \InvalidArgumentException('Webhook Asaas excede o tamanho permitido.');
        }

        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Payload de webhook Asaas inválido.', 0, $e);
        }

        $event = is_array($data) && is_string($data['event'] ?? null) ? $data['event'] : '';
        $chargeId = is_array($data) && is_array($data['payment'] ?? null)
            && is_string($data['payment']['id'] ?? null)
            ? $data['payment']['id']
            : null;

        if ($chargeId === null || $chargeId === '' || strlen($chargeId) > 255) {
            throw new \InvalidArgumentException('Webhook Asaas sem identificador de cobrança válido.');
        }

        $payment = $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $chargeId]);

        if ($payment === null) {
            $ghost = new Payment();
            $ghost->setExternalId($chargeId);
            $ghost->setStatus(Payment::STATUS_PENDING);
            $ghost->setGatewayResponse(json_encode(['event' => $event], JSON_THROW_ON_ERROR));
            return $ghost;
        }

        $payment->setGatewayResponse(json_encode([
            'event' => $event,
            'payment' => [
                'id' => $chargeId,
                'status' => $data['payment']['status'] ?? null,
            ],
        ], JSON_THROW_ON_ERROR));

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
        $externalId = $payment->getExternalId();
        if ($externalId === null || $externalId === '') {
            throw new GatewayException('Pagamento sem identificador externo.');
        }

        $data = $this->requestJson('GET', '/payments/'.rawurlencode($externalId));
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

        $customers = $this->requestJson('GET', '/customers', [
            'query' => ['externalReference' => (string) $user->getId()],
        ]);
        if (is_string($customers['data'][0]['id'] ?? null)) {
            return $customers['data'][0]['id'];
        }

        $customer = $this->requestJson('POST', '/customers', ['json' => $payload]);
        if (!is_string($customer['id'] ?? null) || $customer['id'] === '') {
            throw new GatewayException('Asaas não retornou o identificador do cliente.');
        }

        return $customer['id'];
    }

    private function fetchPixQrWithRetry(string $paymentId, int $maxAttempts = 3): array
    {
        $data = [];

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $data = $this->requestJson(
                'GET',
                '/payments/'.rawurlencode($paymentId).'/pixQrCode',
            );

            if (!empty($data['encodedImage']) && !empty($data['payload'])) {
                return $data;
            }

            if ($attempt < $maxAttempts) {
                usleep(200_000 * $attempt);
            }
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $options = []): array
    {
        if (trim($this->apiKey) === '') {
            throw new GatewayException('A chave de API do Asaas não está configurada.');
        }

        try {
            $response = $this->http->request(
                $method,
                $this->apiBaseUrl.$path,
                array_replace_recursive([
                    'headers' => ['access_token' => $this->apiKey],
                    'timeout' => 10,
                    'max_duration' => 15,
                    'max_redirects' => 0,
                ], $options),
            );

            return $this->decodeResponse($response, $method, $path);
        } catch (TransportExceptionInterface $e) {
            throw new GatewayException('Falha de comunicação com o Asaas.', 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(ResponseInterface $response, string $method, string $path): array
    {
        try {
            $status = $response->getStatusCode();
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new GatewayException('Falha de comunicação com o Asaas.', 0, $e);
        }

        if ($status < 200 || $status >= 300) {
            throw new GatewayException(sprintf(
                'Asaas retornou HTTP %d para %s %s.',
                $status,
                strtoupper($method),
                $path,
            ));
        }

        try {
            $data = $response->toArray(throw: false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new GatewayException('Resposta inválida do Asaas.', 0, $e);
        }

        return $data;
    }
}
