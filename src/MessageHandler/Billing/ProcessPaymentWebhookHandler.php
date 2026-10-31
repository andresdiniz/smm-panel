<?php

declare(strict_types=1);

namespace App\MessageHandler\Billing;

use App\Message\Billing\ProcessPaymentWebhookMessage;
use App\Message\Order\DispatchOrderToProviderMessage;
use App\Repository\PaymentRepository;
use App\Enum\PaymentStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class ProcessPaymentWebhookHandler
{
    public function __construct(
        private readonly PaymentRepository      $payments,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ProcessPaymentWebhookMessage $message): void
    {
        $gatewayId = $this->extractGatewayId($message->gateway, $message->payload);
        if (!$gatewayId) {
            $this->logger->warning('Webhook sem gatewayId', $message->payload);
            return;
        }

        $payment = $this->payments->findOneBy(['gatewayId' => $gatewayId]);
        if (!$payment) {
            $this->logger->warning('Payment not found for webhook', ['gatewayId' => $gatewayId]);
            return;
        }

        $payment->setWebhookPayload($message->payload);
        $status = $this->extractStatus($message->gateway, $message->payload);

        if ($status === 'paid' && $payment->getStatus() !== PaymentStatus::PAID) {
            $payment->markAsPaid();
            $this->em->flush();

            // Dispara pedido para o provider SMM
            if ($order = $payment->getOrder()) {
                $this->bus->dispatch(new DispatchOrderToProviderMessage(
                    $order->getId(),
                    $order->getProviderSlug()
                ));
            }
        } elseif ($status === 'failed') {
            $payment->markAsFailed();
            $this->em->flush();
        }
    }

    private function extractGatewayId(string $gateway, array $payload): ?string
    {
        return match ($gateway) {
            'asaas'   => $payload['payment']['id'] ?? null,
            'pagbank' => $payload['charges'][0]['id'] ?? null,
            'stripe'  => $payload['data']['object']['id'] ?? null,
            default   => null,
        };
    }

    private function extractStatus(string $gateway, array $payload): string
    {
        return match ($gateway) {
            'asaas' => match ($payload['payment']['status'] ?? '') {
                'RECEIVED', 'CONFIRMED' => 'paid',
                'OVERDUE', 'REFUNDED'  => 'failed',
                default => 'pending',
            },
            'pagbank' => match ($payload['charges'][0]['status'] ?? '') {
                'PAID', 'AUTHORIZED' => 'paid',
                'CANCELED', 'DECLINED' => 'failed',
                default => 'pending',
            },
            'stripe' => match ($payload['type'] ?? '') {
                'payment_intent.succeeded'              => 'paid',
                'payment_intent.payment_failed'         => 'failed',
                default => 'pending',
            },
            default => 'pending',
        };
    }
}
