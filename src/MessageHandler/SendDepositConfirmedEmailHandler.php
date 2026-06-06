<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Payment;
use App\Message\SendDepositConfirmedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final class SendDepositConfirmedEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
    ) {}

    public function __invoke(SendDepositConfirmedEmailMessage $message): void
    {
        /** @var Payment|null $payment */
        $payment = $this->em->find(Payment::class, $message->paymentId);
        if (!$payment || $payment->getStatus() !== 'approved') {
            return;
        }

        $user = $payment->getUser();

        $email = (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Recarga confirmada — PulseSMM ✅')
            ->htmlTemplate('emails/deposit_confirmed.html.twig')
            ->context([
                'user'    => $user,
                'payment' => $payment,
            ]);

        $this->mailer->send($email);
    }
}
