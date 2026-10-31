<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gateway PagBank (PagSeguro) — Pix QR Code e cartão tokenizado.
 *
 * Docs: https://dev.pagbank.uol.com.br/reference
 */
final class PagBankGateway extends AbstractGateway implements PaymentGatewayInterface
{
    public function __construct(
        EntityManagerInterface               $em,
        private readonly HttpClientInterface  $http,
        private readonly string               $token,
        private readonly string               $baseUrl,
        private readonly string               $webhookToken,
    ) {
        parent::__construct($em);
    }

    public function createDeposit(
        User   $user,
        int    $amountCents,
        string $method,
        string $cpf   = '',
        string $phone = '',
    ): Payment {
        $feeRate = match ($method) {
            Payment::METHOD_PIX         => 0.0099,
            Payment::METHOD_CREDIT_CARD => 0.0349,
            Payment::METHOD_DEBIT_CARD  => 0.0199,
            default                     => throw new \InvalidArgumentException('Método inválido: ' . $method),
        };
        $feeCents = (int) ceil($amountCents * $feeRate);

        if ($method === Payment::METHOD_PIX) {
            return $this->createPix($user, $amountCents, $feeCents);
        }

        return $this->createCard($user, $amountCents, $method, $feeCents);
    }

    private function createPix(User $user, int $amountCents, int $feeCents): Payment
    {
        $response = $this->http->request('POST', $this->baseUrl . '/instant-payments', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'reference_id'    => uniqid('pb_', true),
                'customer'        => [
                    'name'  => $user->getName(),
                    'email' => $user->getEmail(),
                ],
                'amount'          => [
                    'value'    => $amountCents,
                    'currency' => 'BRL',
                ],
                'expiration_date' => (new \DateTimeImmutable('+30 minutes'))->format(\DateTimeInterface::ATOM),
            ],
        ]);

        $data = $response->toArray(false);

        $payment = $this->buildPayment($user, $amountCents, Payment::METHOD_PIX, $feeCents);
        $payment->setExternalId($data['id'] ?? $data['reference_id'] ?? '');
        $payment->setGatewayResponse(json_encode($data));

        $qr = $data['qr_codes'][0] ?? [];
        $payment->setPixCode($qr['text'] ?? null);
        $payment->setQrCodeBase64(null);
        $payment->setGatewayResponse(json_encode(array_merge($data, ['_qr_image' => $qr['links'][0]['href'] ?? null])));

        $this->persist($payment);
        return $payment;
    }

    private function createCard(User $user, int $amountCents, string $method, int $feeCents): Payment
    {
        $response = $this->http->request('POST', $this->baseUrl . '/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'json' => [
                'reference_id' => uniqid('pb_card_', true),
                'customer'     => [
                    'name'  => $user->getName(),
                    'email' => $user->getEmail(),
                ],
                'items'   => [[
                    'name'        => 'Recarga PulseSMM',
                    'quantity'    => 1,
                    'unit_amount' => $amountCents,
                ]],
                'charges' => [[
                    'reference_id'   => uniqid('ch_', true),
                    'amount'         => ['value' => $amountCents, 'currency' => 'BRL'],
                    'payment_method' => [
                        'type'         => $method === Payment::METHOD_CREDIT_CARD ? 'CREDIT_CARD' : 'DEBIT_CARD',
                        'installments' => 1,
                        'capture'      => true,
                    ],
                ]],
            ],
        ]);

        $data   = $response->toArray(false);
        $charge = $data['charges'][0] ?? [];
        $status = strtoupper($charge['status'] ?? 'WAITING');

        $payment = $this->buildPayment($user, $amountCents, $method, $feeCents);
        $payment->setExternalId($data['id'] ?? '');
        $payment->setGatewayResponse(json_encode($data));

        if ($status === 'PAID' || $status === 'AUTHORIZED') {
            $this->persist($payment);
            $this->approveAndCredit($payment);
        } else {
            $this->persist($payment);
        }

        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        if ($request->headers->get('Authorization') !== 'Bearer ' . $this->webhookToken) {
            throw new \InvalidArgumentException('Assinatura PagBank inválida.');
        }

        $data    = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $orderId = $data['id'] ?? throw new \InvalidArgumentException('Webhook sem ID.');

        $payment = $this->findByExternalId($orderId);
        $payment->setGatewayResponse(json_encode($data));

        $charges = $data['charges'] ?? [];
        $status  = strtoupper($charges[0]['status'] ?? $data['status'] ?? 'WAITING');

        if (in_array($status, ['PAID', 'AVAILABLE'], true)) {
            $this->approveAndCredit($payment);
        } elseif (in_array($status, ['DECLINED', 'CANCELLED', 'CHARGEBACK'], true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
            $this->em->flush();
        } elseif ($status === 'REFUNDED') {
            $payment->setStatus(Payment::STATUS_REFUNDED);
            $this->em->flush();
        }

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        $data   = $this->http->request('GET', $this->baseUrl . '/orders/' . $payment->getExternalId(), [
            'headers' => ['Authorization' => 'Bearer ' . $this->token],
        ])->toArray(false);

        $charges = $data['charges'] ?? [];
        $status  = strtoupper($charges[0]['status'] ?? $data['status'] ?? 'WAITING');

        $map = [
            'PAID'        => Payment::STATUS_APPROVED,
            'AVAILABLE'   => Payment::STATUS_APPROVED,
            'AUTHORIZED'  => Payment::STATUS_APPROVED,
            'DECLINED'    => Payment::STATUS_FAILED,
            'CANCELLED'   => Payment::STATUS_CANCELLED,
            'CHARGEBACK'  => Payment::STATUS_REFUNDED,
            'REFUNDED'    => Payment::STATUS_REFUNDED,
        ];

        return $map[$status] ?? Payment::STATUS_PENDING;
    }
}
