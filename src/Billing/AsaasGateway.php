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
    /** CPF genérico válido para o sandbox Asaas (usado somente quando nenhum CPF real é fornecido). */
    private const SANDBOX_CPF = '00000000191';

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
        $data  = $response->toArray();
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
     * - cpfCnpj: usa o CPF real enviado pelo formulário;
     *   se vazio E ambiente sandbox, usa o CPF genérico válido;
     *   em produção sem CPF, lança exceção.
     * - mobilePhone: envia somente dígitos após o DDI (+55XXXXXXXXXXX → "11999990000").
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

        // Remove DDI +55 e qualquer não-dígito para o campo mobilePhone do Asaas
        $mobilePhone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($mobilePhone, '55') && strlen($mobilePhone) > 11) {
            $mobilePhone = substr($mobilePhone, 2); // remove os dois dígitos do DDI
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
     * Busca QR Code Pix com até 5 tentativas com delay progressivo (backoff 1–4 s).
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
