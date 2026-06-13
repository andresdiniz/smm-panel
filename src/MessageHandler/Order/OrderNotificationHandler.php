<?php

declare(strict_types=1);

namespace App\MessageHandler\Order;

use App\Entity\Order;
use App\Message\Order\OrderNotificationMessage;
use App\Message\SendOrderCompletedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Mime\Address;
use Psr\Log\LoggerInterface;

/**
 * Processa notificações de pedido e dispara o e-mail correto.
 *
 * Eventos:
 *   'completed' → despacha SendOrderCompletedEmailMessage (fila orders_low)
 *   'cancelled' → envia e-mail de cancelamento + reembolso diretamente
 */
#[AsMessageHandler]
final class OrderNotificationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly MailerInterface        $mailer,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(OrderNotificationMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->em->find(Order::class, $message->orderId);

        if (!$order) {
            $this->logger->warning('OrderNotificationHandler: pedido não encontrado.', [
                'order_id' => $message->orderId,
            ]);
            return;
        }

        match ($message->event) {
            'completed' => $this->notifyCompleted($order),
            'cancelled' => $this->notifyCancelled($order),
            default     => $this->logger->info('OrderNotificationHandler: evento desconhecido, ignorando.', [
                'order_id' => $message->orderId,
                'event'    => $message->event,
            ]),
        };
    }

    private function notifyCompleted(Order $order): void
    {
        // Garante que o pedido realmente está concluído antes de notificar
        if ($order->getStatus() !== Order::STATUS_COMPLETED) {
            $this->logger->warning('OrderNotificationHandler: evento completed mas status não é completed.', [
                'order_id' => $order->getId(),
                'status'   => $order->getStatus(),
            ]);
            return;
        }

        $this->bus->dispatch(new SendOrderCompletedEmailMessage($order->getId()));

        $this->logger->info('OrderNotificationHandler: e-mail de pedido concluído despachado.', [
            'order_id' => $order->getId(),
        ]);
    }

    private function notifyCancelled(Order $order): void
    {
        $user = $order->getUser();

        try {
            $email = (new TemplatedEmail())
                ->to(new Address($user->getEmail(), $user->getName()))
                ->subject('Pedido #' . $order->getId() . ' cancelado — PulseSMM')
                ->htmlTemplate('emails/order_cancelled.html.twig')
                ->context([
                    'user'  => $user,
                    'order' => $order,
                ]);

            $this->mailer->send($email);

            $this->logger->info('OrderNotificationHandler: e-mail de cancelamento enviado.', [
                'order_id' => $order->getId(),
                'user_id'  => $user->getId(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OrderNotificationHandler: falha ao enviar e-mail de cancelamento.', [
                'order_id'  => $order->getId(),
                'exception' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
