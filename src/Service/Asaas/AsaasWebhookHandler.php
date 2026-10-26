<?php

namespace App\Service\Asaas;

use App\Entity\PixCharge;
use App\Entity\WebhookEvent;
use App\Service\WalletService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

class AsaasWebhookHandler
{
    private const CONFIRMED = ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED'];
    private const REFUNDED = ['PAYMENT_REFUNDED'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly WalletService $wallet,
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(array $payload): void
    {
        $event = (string) ($payload['event'] ?? '');
        $payment = $payload['payment'] ?? [];
        $externalId = (string) ($payment['id'] ?? ($payload['id'] ?? ''));

        if ($externalId === '') {
            throw new \InvalidArgumentException('Missing payment id.');
        }

        $repository = $this->em->getRepository(WebhookEvent::class);
        if ($repository->findOneBy(['provider' => 'asaas', 'externalId' => $externalId . ':' . $event])) {
            return;
        }

        $record = new WebhookEvent();
        $record->setProvider('asaas')
            ->setExternalId($externalId . ':' . $event)
            ->setEvent($event)
            ->setPayload($payload);

        $this->em->persist($record);
        $this->em->flush();

        $charge = $this->em->getRepository(PixCharge::class)->findOneBy(['asaasPaymentId' => $externalId]);

        if (!$charge) {
            $this->logger->warning('Asaas webhook received for unknown payment.', ['payment_id' => $externalId]);
            return;
        }

        if (in_array($event, self::CONFIRMED, true) && $charge->getStatus() !== PixCharge::STATUS_CONFIRMED) {
            $charge->setStatus(PixCharge::STATUS_CONFIRMED);
            $charge->setConfirmedAt(new \DateTimeImmutable());
            $this->wallet->credit($charge->getUser(), $charge->getAmount(), 'deposit', $charge->getReference(), 'Pix deposit confirmed');
        }

        if (in_array($event, self::REFUNDED, true)) {
            $charge->setStatus(PixCharge::STATUS_REFUNDED);
        }

        $record->setProcessed(true);
        $this->em->flush();
    }
}
