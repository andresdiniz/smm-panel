<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\ProcessOrderMessage;
use App\Repository\WalletRepository;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessOrderHandler
{
    /**
     * Tentativas antes de cancelar definitivamente.
     * (Messenger já faz retry automático via transport — este valor
     *  é uma segunda camada de guarda para quando o Messenger não
     *  está configurado com retry.)
     */
    private const MAX_ATTEMPTS = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly WalletRepository       $walletRepo,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ProcessOrderMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->em->find(Order::class, $message->orderId);

        if (!$order) {
            $this->logger->warning('ProcessOrderHandler: pedido não encontrado.', [
                'order_id' => $message->orderId,
            ]);
            return;
        }

        if ($order->getStatus() !== Order::STATUS_PENDING) {
            $this->logger->info('ProcessOrderHandler: pedido já processado, ignorando.', [
                'order_id' => $message->orderId,
                'status'   => $order->getStatus(),
            ]);
            return;
        }

        $service = $order->getService();
        $slug    = $service->getProviderSlug();

        // ── Valida provider ───────────────────────────────────────────
        if (!$slug || !$this->registry->has($slug)) {
            $this->logger->error('ProcessOrderHandler: provider slug não configurado ou inexistente.', [
                'order_id'   => $order->getId(),
                'service_id' => $service->getId(),
                'slug'       => $slug,
            ]);
            $this->cancelWithRefund($order, 'Provider não configurado ou inexistente');
            $this->em->flush();
            return;
        }

        // ── Envia ao provider ─────────────────────────────────────────
        $provider = $this->registry->get($slug);

        $this->logger->info('ProcessOrderHandler: enviando pedido ao provider.', [
            'order_id'            => $order->getId(),
            'provider'            => $slug,
            'external_service_id' => $service->getExternalServiceId(),
            'target_url'          => $order->getTargetUrl(),
            'quantity'            => $order->getQuantity(),
        ]);

        try {
            $externalId = $provider->addOrder(
                $service->getExternalServiceId(),
                $order->getTargetUrl(),
                $order->getQuantity()
            );

            $order->setExternalOrderId($externalId);
            $order->setStatus(Order::STATUS_PROCESSING);

            $this->logger->info('ProcessOrderHandler: pedido aceito pelo provider.', [
                'order_id'    => $order->getId(),
                'external_id' => $externalId,
                'provider'    => $slug,
            ]);

        } catch (\Throwable $e) {
            $this->logger->error('ProcessOrderHandler: falha ao enviar pedido → cancelando.', [
                'order_id'   => $order->getId(),
                'provider'   => $slug,
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
                'trace'      => $e->getTraceAsString(),
            ]);

            $this->cancelWithRefund($order, $e->getMessage());
        }

        $this->em->flush();
    }

    /**
     * Cancela o pedido E devolve o valor pago na carteira do usuário.
     */
    private function cancelWithRefund(Order $order, string $reason): void
    {
        $order->setStatus(Order::STATUS_CANCELLED);

        $amount = $order->getAmountCents();
        if ($amount <= 0) {
            return;
        }

        $wallet = $this->walletRepo->findOneBy(['user' => $order->getUser()]);
        if (!$wallet) {
            $this->logger->error('ProcessOrderHandler: carteira não encontrada para reembolso.', [
                'order_id' => $order->getId(),
                'user_id'  => $order->getUser()->getId(),
            ]);
            return;
        }

        $wallet->credit($amount);

        $this->logger->info('ProcessOrderHandler: reembolso efetuado.', [
            'order_id'     => $order->getId(),
            'user_id'      => $order->getUser()->getId(),
            'refund_cents' => $amount,
            'reason'       => $reason,
        ]);
    }
}
