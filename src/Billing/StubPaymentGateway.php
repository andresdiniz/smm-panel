<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Wallet;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Gateway stub para ambiente de desenvolvimento e testes.
 * Pix: sempre gera QR Code fictício e aprova via webhook simulado.
 * Cartão: aprova imediatamente e credita a Wallet.
 *
 * NÃO usar em produção.
 */
final class StubPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    public function createDeposit(User $user, int $amountCents, string $method): Payment
    {
        $payment = new Payment();
        $payment->setUser($user);
        $payment->setType(Payment::TYPE_DEPOSIT);
        $payment->setMethod($method);
        $payment->setAmountCents($amountCents);
        $payment->setFeeCents(0);
        $payment->setExternalId('STUB-' . strtoupper(bin2hex(random_bytes(8))));

        if ($method === Payment::METHOD_PIX) {
            $payment->setPixCode('00020126580014BR.GOV.BCB.PIX0136stub-pix-key-' . random_int(1000, 9999) . '5204000053039865802BR5925PulseSMM Pagamentos6009SAO PAULO62070503***6304STUB');
            $payment->setQrCodeBase64($this->generateStubQrBase64());
            $payment->setStatus(Payment::STATUS_PENDING);
        } else {
            // Cartão: aprovação imediata no stub
            $payment->setStatus(Payment::STATUS_APPROVED);
            $payment->approve();
            $this->creditWallet($user, $amountCents);
        }

        $this->em->persist($payment);
        $this->em->flush();

        return $payment;
    }

    public function processWebhook(Request $request): Payment
    {
        $data      = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $external  = $data['id'] ?? throw new \InvalidArgumentException('Webhook sem ID externo.');

        /** @var Payment|null $payment */
        $payment = $this->em->getRepository(Payment::class)->findOneBy(['externalId' => $external]);

        if (!$payment) {
            throw new \InvalidArgumentException('Pagamento não encontrado: ' . $external);
        }

        $status = $data['status'] ?? 'PENDING';

        if (in_array($status, ['CONFIRMED', 'RECEIVED', 'APPROVED'], true)) {
            $payment->approve();
            $this->creditWallet($payment->getUser(), $payment->getAmountCents() - $payment->getFeeCents());
        } elseif (in_array($status, ['OVERDUE', 'CANCELLED', 'REFUNDED'], true)) {
            $payment->setStatus(Payment::STATUS_CANCELLED);
        }

        $this->em->flush();

        return $payment;
    }

    public function fetchStatus(Payment $payment): string
    {
        // Stub: sempre retorna o status atual da entidade
        return $payment->getStatus();
    }

    private function creditWallet(User $user, int $cents): void
    {
        $wallet = $this->em->getRepository(Wallet::class)->findOneBy(['user' => $user]);
        if ($wallet) {
            $wallet->credit($cents);
            $this->em->persist($wallet);
        }
    }

    private function generateStubQrBase64(): string
    {
        // SVG mínimo simulando um QR Code para dev
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200"><rect width="200" height="200" fill="#fff"/><text x="50%" y="50%" dominant-baseline="middle" text-anchor="middle" font-size="14" fill="#111">QR STUB</text></svg>';
        return base64_encode($svg);
    }
}
