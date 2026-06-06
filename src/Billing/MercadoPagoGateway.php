<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gateway Mercado Pago — Pix nativo e cartão via Payments API.
 *
 * Docs: https://www.mercadopago.com.br/developers/pt/reference
 *
 * Variáveis de ambiente necessárias:
 *   MP_ACCESS_TOKEN=APP_USR-...
 *   MP_BASE_URL=https://api.mercadopago.com
 *   MP_WEBHOOK_SECRET=secret_do_painel_mp
 */
final class MercadoPagoGateway extends AbstractGateway implements PaymentGatewayInterface
{
    public function __construct(
        EntityManagerInterface       $em,
        private readonly HttpClientInterface $http,
        private readonly string $accessToken,
        private readonly string $baseUrl,
        private readonly string $webhookSecret,
    ) {
        parent::__construct($em);
    }

    public function createDeposit(User $user, int $amountCents, string $method): Payment
    {
        $feeRate = match ($method) {
            Payment::METHOD_PIX         => 0.0099,
            Payment::METHOD_CREDIT_CARD => 0.0399,
            Payment::METHOD_DEBIT_CARD  => 0.0199,
            default                     => throw new \InvalidArgumentException('Método inválido: ' . $method),
        };
        $feeCents = (int) ceil($amountCents * $feeRate);

        $body = [
            'transaction_amount' => round($amountCents / 100, 2),
            'description'        => 'Recarga PulseSMM',
            'payer'              => [
                'email' => $user->getEmail(),
            ],
        ];

        if ($method === Payment::METHOD_PIX) {
            $body['payment_method_id'] = 'pix';
        } else {
            // Para cartão, o token deve ser gerado no frontend via MP SDK
            // e enviado como payment_method_token no corpo do POST.
            // Aqui usamos campo reservado para futuro form de cartão.
            $body['payment_method_id']   = $method === Payment::METHOD_CREDIT_CARD ? 'visa' : 'debvisa';
            $body['installments']        = 1;
        }

        $response = $this->http->request('POST', $this->baseUrl . '/v1/payments', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type'  => 'application/json',
                'X-Idempotency-Key' => uniqid('mp_', true),
            ],
            'json' => $body,
        ]);

        $data = $response->toArray(false);

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId((string) ($data['id'] ?? ''));
        $payment->setGatewayResponse(json_encode($data));

        if ($method === Payment::METHOD_PIX) {
            $txInfo = $data['point_of_interaction']['transaction_data'] ?? [];
            $payment->setPixCode($txInfo['qr_code'] ?? null);
            $payment->setQrCodeBase64($txInfo['qr_code_base64'] ?? null);
        }

        if (in_array($data['status'] ?? '', ['approved', 'authorized'], true)) {
            $this->persist($payment);
            $this->approveAndCredit($payment);
        } else {
            $this->persist($payment);
        }

        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        $this->validateMpSignature($request);

        $data    = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $action  = $data['action'] ?? '';
        $mpId    = (string) ($data['data']['id'] ?? throw new \InvalidArgumentException('Webhook sem ID.'));

        // Busca detalhes atualizados na API
        $detail  = $this->http->request('GET', $this->baseUrl . '/v1/payments/' . $mpId, [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
        ])->toArray(false);

        $payment = $this->findByExternalId($mpId);
        $payment->setGatewayResponse(json_encode($detail));

        $status = $detail['status'] ?? 'pending';

        if (in_array($status, ['approved', 'authorized'], true)) {
            $this->approveAndCredit($payment);
        } elseif (in_array($status, ['cancelled', 'rejected', 'charged_back'], true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $this->em->flush();
        } elseif ($status === 'refunded') {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();
        }

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        $data = $this->http->request('GET', $this->baseUrl . '/v1/payments/' . $payment->getExternalId(), [
            'headers' => ['Authorization' => 'Bearer ' . $this->accessToken],
        ])->toArray(false);

        $map = [
            'approved'    => Payment::STATUS_APPROVED,
            'authorized'  => Payment::STATUS_APPROVED,
            'cancelled'   => Payment::STATUS_CANCELLED,
            'rejected'    => Payment::STATUS_FAILED,
            'refunded'    => Payment::STATUS_REFUNDED,
            'charged_back'=> Payment::STATUS_REFUNDED,
        ];

        return $map[$data['status'] ?? 'pending'] ?? Payment::STATUS_PENDING;
    }

    private function validateMpSignature(Request $request): void
    {
        $xSignature  = $request->headers->get('x-signature', '');
        $xRequestId  = $request->headers->get('x-request-id', '');
        $dataId      = $request->query->get('data.id', '');

        $manifest = "id:{$dataId};request-id:{$xRequestId}";
        $expected = hash_hmac('sha256', $manifest, $this->webhookSecret);

        // Extrai ts e v1 do header x-signature
        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            [$k, $v] = explode('=', trim($part), 2) + ['', ''];
            $parts[$k] = $v;
        }

        if (!hash_equals($expected, $parts['v1'] ?? '')) {
            throw new \InvalidArgumentException('Assinatura MP inválida.');
        }
    }
}
