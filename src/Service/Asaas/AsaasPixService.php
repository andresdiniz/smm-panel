<?php

namespace App\Service\Asaas;

use App\Entity\PixCharge;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

class AsaasPixService
{
    public function __construct(
        private readonly AsaasClient $client,
        private readonly EntityManagerInterface $em,
    ) {}

    public function createCharge(User $user, string $amount): PixCharge
    {
        if (bccomp($amount, '5.00', 2) === -1) {
            throw new \InvalidArgumentException('Minimum deposit is R$ 5.00.');
        }

        if (!$user->getAsaasCustomerId()) {
            $customer = $this->client->createCustomer($user->getEmail(), $user->getEmail(), '00000000000');
            $user->setAsaasCustomerId($customer['id'] ?? null);
            $this->em->flush();
        }

        $reference = bin2hex(random_bytes(16));
        $payment = $this->client->createPixPayment(
            (string) $user->getAsaasCustomerId(),
            $amount,
            $reference,
            new \DateTimeImmutable('+1 day')
        );

        $charge = new PixCharge();
        $charge->setUser($user)
            ->setReference($reference)
            ->setAsaasPaymentId($payment['id'] ?? null)
            ->setAmount($amount);

        if (!empty($payment['invoiceUrl'])) {
            $charge->setInvoiceUrl($payment['invoiceUrl']);
        }

        if (!empty($payment['id'])) {
            $qr = $this->client->getPixQrCode($payment['id']);
            $charge->setQrCode($qr['encodedImage'] ?? null);
            $charge->setCopyPasteCode($qr['payload'] ?? null);
        }

        $this->em->persist($charge);
        $this->em->flush();

        return $charge;
    }
}
