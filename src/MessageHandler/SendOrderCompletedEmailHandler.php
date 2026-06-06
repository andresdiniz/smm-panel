<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\SendOrderCompletedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;

#[AsMessageHandler]
final class SendOrderCompletedEmailHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailerInterface        $mailer,
    ) {}

    public function __invoke(SendOrderCompletedEmailMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->em->find(Order::class, $message->orderId);
        if (!$order) {
            return;
        }

        $user = $order->getUser();

        $email = (new TemplatedEmail())
            ->to(new Address($user->getEmail(), $user->getName()))
            ->subject('Pedido #' . $order->getId() . ' concluído — PulseSMM ✅')
            ->htmlTemplate('emails/order_completed.html.twig')
            ->context([
                'user'  => $user,
                'order' => $order,
            ]);

        $this->mailer->send($email);
    }
}
