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
     * Eventos que indicam pagamento confirmado/recebido.
     * Pix: vai direto para PAYMENT_RECEIVED sem passar por CONFIRMED.
     * Cartão: passa por PAYMENT_CONFIRMED antes do RECEIVED (dias depois).
     * Aprovamos o crédito já em PAYMENT_CONFIRMED para não atrasar o usuário.
     *
     * @see https://docs.asaas.com/docs/webhook-para-cobrancas
     */
    private const EVENTS_APPROVE = [
        'PAYMENT_RECEIVED',
        'PAYMENT_CONFIRMED',
    ];

    /** Eventos que indicam pagamento cancelado/vencido/excluído. */
    private const EVENTS_CANCEL = [
        'PAYMENT_OVERDUE',
        'PAYMENT_DELETED',
        'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED',
        'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
    ];

    /** Eventos de estorno (total ou parcial). */
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
        private readonly string               $baseUrl,
        private readonly string               $webhookToken,
    ) {
        parent::__construct($em);
    }

    /**
     * @param string $cpf   CPF somente dígitos (ex: "12345678901") — vazio usa fallback sandbox
     * @param string $phone Telefone com DDI +55 (ex: "+5511999990000") — vazio omite o campo
     */
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
            throw new \RuntimeException(
                sprintf('Asaas retornou HTTP %d ao criar cobrança: %s', $statusCode, json_encode($data, JSON_UNESCAPED_UNICODE))
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

    /**
     * Recebe eventos POST do Asaas conforme:
     * https://docs.asaas.com/docs/receba-eventos-do-asaas-no-seu-endpoint-de-webhook
     *
     * Estrutura do body:
     * {
     *   "id": "evt_XXXXXXX",
     *   "event": "PAYMENT_RECEIVED",
     *   "dateCreated": "2024-06-12 16:45:03",
     *   "payment": { "id": "pay_XXXXXXX", "status": "RECEIVED", ... }
     * }
     *
     * Segurança: valida header `asaas-access-token`.
     * Idempotência: eventos duplicados são ignorados silenciosamente.
     * Resposta: sempre retorna o Payment (controller devolve HTTP 200).
     */
    public function processWebhook(Request $request): Payment
    {
        // 1. Autenticação via header — doc: "authToken" configurado no painel
        $token = $request->headers->get('asaas-access-token', '');
        if (!hash_equals($this->webhookToken, $token)) {
            throw new \InvalidArgumentException('asaas-access-token inválido.');
        }

        // 2. Parse do payload
        $data     = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $event    = $data['event'] ?? '';
        $chargeId = $data['payment']['id'] ?? null;

        if (!$chargeId) {
            // Evento sem cobrança (ex: TRANSFER_*, BILL_*) — ignora silenciosamente
            throw new \InvalidArgumentException('Webhook sem payment.id — evento ignorado: ' . $event);
        }

        // 3. Busca o pagamento local pelo ID externo do Asaas
        $payment = $this->findByExternalId($chargeId);

        // 4. Salva o payload bruto para auditoria
        $payment->setGatewayResponse(json_encode($data, JSON_UNESCAPED_UNICODE));

        // 5. Idempotência: não reprocessa status finais
        $currentStatus = $payment->getStatus();
        $finalStatuses = [Payment::STATUS_APPROVED, Payment::STATUS_REFUNDED, Payment::STATUS_CANCELLED];

        if (in_array($currentStatus, $finalStatuses, true)
            && !in_array($event, ['PAYMENT_REFUNDED', 'PAYMENT_PARTIALLY_REFUNDED'], true)
        ) {
            $this->em->flush(); // persiste apenas o gatewayResponse atualizado
            return $payment;
        }

        // 6. Máquina de estados conforme fluxos da documentação Asaas
        if (in_array($event, self::EVENTS_APPROVE, true)) {
            // Pix: PAYMENT_CREATED → PAYMENT_RECEIVED
            // Cartão: PAYMENT_CREATED → PAYMENT_CONFIRMED → PAYMENT_RECEIVED
            $this->approveAndCredit($payment);

        } elseif (in_array($event, self::EVENTS_CANCEL, true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $this->em->flush();

        } elseif (in_array($event, self::EVENTS_REFUND, true)) {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();

        } else {
            // Eventos informativos (PAYMENT_CREATED, PAYMENT_UPDATED,
            // PAYMENT_BANK_SLIP_VIEWED, PAYMENT_CHECKOUT_VIEWED, etc.)
            // Apenas persiste o gatewayResponse, sem mudar status.
            $this->em->flush();
        }

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        $response = $this->http->request('GET', $this->baseUrl . '/payments/' . $payment->getExternalId(), [
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

    /**
     * Cria ou reutiliza cliente no Asaas.
     *
     * - cpfCnpj: usa o CPF real; se vazio em sandbox usa CPF genérico; em produção lança excessão.
     * - mobilePhone: somente dígitos sem DDI ("11999990000"), pois Asaas não aceita +55 nesse campo.
     */
    private function ensureCustomer(User $user, string $cpf = '', string $phone = ''): string
    {
        $isSandbox = str_contains($this->baseUrl, 'sandbox');

        if ($cpf === '') {
            if ($isSandbox) {
                $cpf = self::SANDBOX_CPF;
            } else {
                throw new \RuntimeException('CPF obrigatório para criar cobrança em produção.');
            }
        }

        // Remove DDI +55 e qualquer não-dígito para o campo mobilePhone
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

    /**
     * Busca QR Code Pix com até 5 tentativas com backoff progressivo (1–4 s).
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

            if ($attempt < $maxAttempts) {
                sleep($attempt);
            }
        }

        return $data;
    }
}
