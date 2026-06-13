<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\SendOrderCompletedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
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
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(SendOrderCompletedEmailMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->em->find(Order::class, $message->orderId);

        if (!$order) {
            $this->logger->warning('SendOrderCompletedEmailHandler: pedido não encontrado.', [
                'order_id' => $message->orderId,
            ]);
            return;
        }

        // Proteção: só envia e-mail se o pedido realmente está concluído
        if ($order->getStatus() !== Order::STATUS_COMPLETED) {
            $this->logger->warning('SendOrderCompletedEmailHandler: pedido não está com status completed, e-mail não enviado.', [
                'order_id' => $message->orderId,
                'status'   => $order->getStatus(),
            ]);
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

        $this->logger->info('SendOrderCompletedEmailHandler: e-mail de pedido concluído enviado.', [
            'order_id' => $message->orderId,
            'user_id'  => $user->getId(),
        ]);
    }
}
