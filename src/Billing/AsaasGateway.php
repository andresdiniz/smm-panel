<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\PaymentRefundRequest;
use App\Entity\User;
use App\Billing\Exception\GatewayException;
use App\Billing\Exception\WebhookAuthenticationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
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
final class AsaasGateway extends AbstractGateway implements PaymentGatewayInterface, PixQrCodeRefreshInterface, PixPaymentCancellationInterface, PaymentRefundInterface
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
    ];

    private const EVENTS_CANCEL = [
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
            throw new GatewayException(
                'Asaas não retornou o identificador da cobrança.',
                context: ['operation' => 'create_payment', 'reason' => 'missing_payment_id'],
            );
        }

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId($data['id']);
        $gatewayResponse = [
            'gateway' => 'asaas',
            'id' => $data['id'],
            'status' => $data['status'] ?? null,
            'billingType' => $data['billingType'] ?? $billingType,
            'invoiceUrl' => $data['invoiceUrl'] ?? null,
        ];

        if ($billingType === 'PIX') {
            try {
                $qrData = $this->fetchPixQrWithRetry($data['id']);
                $payment->setPixCode($qrData['payload'] ?? null);
                $payment->setQrCodeBase64($qrData['encodedImage'] ?? null);
            } catch (GatewayException $e) {
                $gatewayResponse['pixQrUnavailable'] = true;
                $gatewayResponse['pixQrFailure'] = $this->safeQrFailureContext($e);
            }
        }

        $payment->setGatewayResponse(json_encode($gatewayResponse, JSON_THROW_ON_ERROR));
        $this->persist($payment);

        return $payment;
    }

    public function refreshPixData(Payment $payment): void
    {
        if (
            $payment->getMethod() !== Payment::METHOD_PIX
            || $payment->getStatus() !== Payment::STATUS_PENDING
        ) {
            throw new GatewayException(
                'O QR Code só pode ser atualizado para um Pix pendente.',
                context: ['operation' => 'refresh_pix_qr', 'reason' => 'payment_not_pending'],
            );
        }

        $externalId = $payment->getExternalId();
        if ($externalId === null || $externalId === '') {
            throw new GatewayException(
                'Pagamento sem identificador externo.',
                context: ['operation' => 'refresh_pix_qr', 'reason' => 'missing_payment_id'],
            );
        }

        $qrData = $this->fetchPixQrWithRetry($externalId);
        $payment->setPixCode($qrData['payload'] ?? null);
        $payment->setQrCodeBase64($qrData['encodedImage'] ?? null);

        $gatewayResponse = json_decode($payment->getGatewayResponse() ?? '{}', true);
        if (!is_array($gatewayResponse)) {
            $gatewayResponse = [];
        }
        $gatewayResponse['gateway'] = 'asaas';
        $gatewayResponse['pixQrUnavailable'] = false;
        unset($gatewayResponse['pixQrFailure']);
        $payment->setGatewayResponse(json_encode($gatewayResponse, JSON_THROW_ON_ERROR));

        $this->em->flush();
    }

    public function cancelPix(Payment $payment): void
    {
        if (
            $payment->getType() !== Payment::TYPE_DEPOSIT
            || $payment->getMethod() !== Payment::METHOD_PIX
            || $payment->getStatus() !== Payment::STATUS_PENDING
        ) {
            throw new GatewayException(
                'Somente depósitos Pix pendentes podem ser cancelados.',
                context: ['operation' => 'cancel_pix', 'reason' => 'payment_not_pending'],
            );
        }

        $externalId = $payment->getExternalId();
        if ($externalId === null || $externalId === '') {
            throw new GatewayException(
                'Pagamento sem identificador externo.',
                context: ['operation' => 'cancel_pix', 'reason' => 'missing_payment_id'],
            );
        }

        $this->requestJson('DELETE', '/payments/'.rawurlencode($externalId));

        $this->em->wrapInTransaction(function () use ($payment): void {
            $this->em->refresh($payment, LockMode::PESSIMISTIC_WRITE);
            if ($payment->getStatus() !== Payment::STATUS_PENDING) {
                throw new GatewayException(
                    'O status do Pix mudou durante o cancelamento.',
                    context: ['operation' => 'cancel_pix', 'reason' => 'payment_not_pending'],
                );
            }

            $payment->setStatus(Payment::STATUS_CANCELLED);
        });
    }

    public function requestRefund(Payment $payment): void
    {
        if (
            $payment->getType() !== Payment::TYPE_DEPOSIT
            || $payment->getStatus() !== Payment::STATUS_APPROVED
        ) {
            throw new GatewayException(
                'Somente depósitos aprovados podem ter estorno solicitado.',
                context: ['operation' => 'request_refund', 'reason' => 'payment_not_approved'],
            );
        }

        $externalId = $payment->getExternalId();
        if ($externalId === null || $externalId === '') {
            throw new GatewayException(
                'Pagamento sem identificador externo.',
                context: ['operation' => 'request_refund', 'reason' => 'missing_payment_id'],
            );
        }

        $this->requestJson('POST', '/payments/'.rawurlencode($externalId).'/refund');
    }

    public function processWebhook(Request $request): Payment
    {
        $token = $request->headers->get('asaas-access-token', '');
        if ($this->webhookToken === '' || $token === '' || !hash_equals($this->webhookToken, $token)) {
            throw new WebhookAuthenticationException('Token de webhook Asaas inválido ou não configurado.');
        }

        $content = $request->getContent();
        if (strlen($content) > 65536) {
            throw new \InvalidArgumentException('Webhook Asaas excede o tamanho permitido.');
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Payload de webhook Asaas inválido.', 0, $e);
        }

        $eventId = is_array($data) && is_string($data['id'] ?? null) ? $data['id'] : '';
        $event = is_array($data) && is_string($data['event'] ?? null) ? $data['event'] : '';
        $chargeId = is_array($data) && is_array($data['payment'] ?? null)
            && is_string($data['payment']['id'] ?? null) ? $data['payment']['id'] : '';

        if ($eventId === '' || strlen($eventId) > 255 || $event === '' || strlen($event) > 100) {
            throw new \InvalidArgumentException('Webhook Asaas sem identificador ou tipo de evento válido.');
        }
        if ($chargeId === '' || strlen($chargeId) > 255) {
            throw new \InvalidArgumentException('Webhook Asaas sem identificador de cobrança válido.');
        }

        return $this->em->wrapInTransaction(function () use ($eventId, $event, $chargeId, $data): Payment {
            try {
                $this->em->getConnection()->insert('asaas_webhook_events', [
                    'event_id' => $eventId,
                    'event_type' => $event,
                    'payment_id' => $chargeId,
                    'received_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);
            } catch (UniqueConstraintViolationException) {
                return $this->findWebhookPaymentOrGhost($chargeId, $event);
            }

            $payment = $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $chargeId]);

            if ($payment === null) {
                return $this->createWebhookGhost($chargeId, $event);
            }

            $payment->setGatewayResponse(json_encode([
                'gateway' => 'asaas',
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
                if ($event === 'PAYMENT_REFUNDED') {
                    $refundRequest = $this->em->getRepository(PaymentRefundRequest::class)
                        ->findOneBy(['payment' => $payment]);
                    if ($refundRequest?->getStatus() === PaymentRefundRequest::STATUS_PROCESSING) {
                        $refundRequest->markCompleted();
                    }
                }
                $this->em->flush();
            } else {
                $this->em->flush();
            }

            return $payment;
        });
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
            'OVERDUE'   => Payment::STATUS_FAILED,
            'CANCELLED' => Payment::STATUS_CANCELLED,
            'REFUNDED'  => Payment::STATUS_REFUNDED,
        ];

        return $map[$remote] ?? Payment::STATUS_PENDING;
    }

    private function findWebhookPaymentOrGhost(string $chargeId, string $event): Payment
    {
        return $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $chargeId])
            ?? $this->createWebhookGhost($chargeId, $event);
    }

    private function createWebhookGhost(string $chargeId, string $event): Payment
    {
        $ghost = new Payment();
        $ghost->setExternalId($chargeId);
        $ghost->setStatus(Payment::STATUS_PENDING);
        $ghost->setGatewayResponse(json_encode(['gateway' => 'asaas', 'event' => $event], JSON_THROW_ON_ERROR));

        return $ghost;
    }

    private function ensureCustomer(User $user, string $cpf = '', string $phone = ''): string
    {
        $isSandbox = $this->apiBaseUrl === self::URL_SANDBOX;

        if ($cpf === '') {
            if ($isSandbox) {
                $cpf = self::SANDBOX_CPF;
            } else {
                throw new GatewayException(
                    'CPF obrigatório para criar cobrança em produção.',
                    context: ['operation' => 'validate_customer', 'reason' => 'cpf_required'],
                );
            }
        }

        if (!$this->isValidCpf($cpf)) {
            throw new GatewayException(
                'Informe um CPF válido para criar a cobrança.',
                context: ['operation' => 'validate_customer', 'reason' => 'cpf_invalid'],
            );
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
            throw new GatewayException(
                'Asaas não retornou o identificador do cliente.',
                context: ['operation' => 'create_customer', 'reason' => 'missing_customer_id'],
            );
        }

        return $customer['id'];
    }

    private function fetchPixQrWithRetry(string $paymentId, int $maxAttempts = 3): array
    {
        $path = '/payments/'.rawurlencode($paymentId).'/pixQrCode';
        $lastFailure = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $data = $this->requestJson('GET', $path);
                $lastFailure = null;
            } catch (GatewayException $e) {
                $lastFailure = $e;
                $status = $e->getContext()['http_status'] ?? null;
                $retryable = in_array($status, [404, 429, 500, 502, 503, 504], true)
                    || ($e->getContext()['reason'] ?? null) === 'transport_error';

                if (!$retryable || $attempt === $maxAttempts) {
                    throw $e;
                }
            }

            $payload = $data['payload'] ?? null;
            $encodedImage = $data['encodedImage'] ?? null;
            $hasPayload = is_string($payload) && trim($payload) !== '';
            $hasImage = is_string($encodedImage) && trim($encodedImage) !== '';
            if ($hasImage || $hasPayload) {
                return [
                    'payload' => $hasPayload ? $payload : null,
                    'encodedImage' => $hasImage ? $encodedImage : null,
                ];
            }

            if ($attempt < $maxAttempts) {
                usleep(200_000 * $attempt);
            }
            unset($data);
        }

        $context = [
            'operation' => 'fetch_pix_qr',
            'reason' => 'qr_data_missing',
            'missing_fields' => ['encodedImage', 'payload'],
        ];
        if ($lastFailure instanceof GatewayException) {
            $context = array_merge($context, $this->safeQrFailureContext($lastFailure));
        }

        throw new GatewayException('Asaas não disponibilizou os dados do QR Code Pix.', context: $context);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $path, array $options = []): array
    {
        if (trim($this->apiKey) === '') {
            throw new GatewayException(
                'A chave de API do Asaas não está configurada.',
                context: ['operation' => $method.' '.$path, 'reason' => 'missing_credentials'],
            );
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
            throw new GatewayException(
                'Falha de comunicação com o Asaas.',
                0,
                $e,
                ['operation' => $method.' '.$path, 'reason' => 'transport_error'],
            );
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
            throw new GatewayException(
                'Falha de comunicação com o Asaas.',
                0,
                $e,
                ['operation' => $method.' '.$path, 'reason' => 'transport_error'],
            );
        }

        if ($status < 200 || $status >= 300) {
            throw new GatewayException(sprintf(
                'Asaas retornou HTTP %d para %s %s.',
                $status,
                strtoupper($method),
                $path,
            ), context: [
                'operation' => strtoupper($method).' '.$path,
                'reason' => 'http_error',
                'http_status' => $status,
            ]);
        }

        try {
            $data = $response->toArray(throw: false);
        } catch (TransportExceptionInterface|DecodingExceptionInterface $e) {
            throw new GatewayException(
                'Resposta inválida do Asaas.',
                0,
                $e,
                ['operation' => strtoupper($method).' '.$path, 'reason' => 'invalid_response'],
            );
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function safeQrFailureContext(GatewayException $exception): array
    {
        $context = $exception->getContext();

        return array_intersect_key($context, array_flip([
            'operation',
            'reason',
            'http_status',
            'missing_fields',
        ]));
    }

    private function isValidCpf(string $cpf): bool
    {
        if (preg_match('/^\d{11}$/', $cpf) !== 1 || preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        for ($digit = 9; $digit <= 10; $digit++) {
            $sum = 0;
            for ($index = 0; $index < $digit; $index++) {
                $sum += (int) $cpf[$index] * (($digit + 1) - $index);
            }
            $checkDigit = (10 * $sum) % 11;
            $checkDigit = $checkDigit === 10 ? 0 : $checkDigit;
            if ($checkDigit !== (int) $cpf[$digit]) {
                return false;
            }
        }

        return true;
    }
}
