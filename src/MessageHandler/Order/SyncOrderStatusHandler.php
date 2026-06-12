<?php

declare(strict_types=1);

namespace App\MessageHandler\Order;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use App\Message\Order\OrderNotificationMessage;
use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\OrderRepository;
use App\Repository\WalletRepository;
use App\Smm\Dto\ProviderStatus;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Consulta o status de um pedido no provider e atualiza a entidade.
 *
 * Fluxo:
 *   SyncOrderStatusMessage → consulta API → ProviderStatus::fromArray()
 *     ├ completed  → markAsCompleted, salva log, notifica
 *     ├ partial    → markAsPartial, salva log, agenda novo sync
 *     ├ cancelled  → markAsFailed, reembolso + WalletTransaction, salva log, notifica
 *     └ outros     → salva log, scheduleNextSync (polling exponencial)
 */
#[AsMessageHandler]
final class SyncOrderStatusHandler
{
    public function __construct(
        private readonly OrderRepository        $orders,
        private readonly WalletRepository       $wallets,
        private readonly SmmProviderRegistry    $providers,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(SyncOrderStatusMessage $message): void
    {
        /** @var Order|null $order */
        $order = $this->orders->find($message->orderId);

        if (!$order) {
            $this->logger->warning('SyncOrderStatusHandler: pedido não encontrado.', ['order_id' => $message->orderId]);
            return;
        }

        if (!in_array($order->getStatus(), [
            Order::STATUS_PROCESSING,
            Order::STATUS_IN_PROGRESS,
            Order::STATUS_PARTIAL,
        ], true)) {
            $this->logger->info('SyncOrderStatusHandler: pedido já finalizado, ignorando.', [
                'order_id' => $order->getId(), 'status' => $order->getStatus(),
            ]);
            return;
        }

        $externalId = $order->getExternalId();
        if (!$externalId) {
            $this->logger->error('SyncOrderStatusHandler: pedido sem externalOrderId.', ['order_id' => $order->getId()]);
            return;
        }

        $slug = $order->getProviderSlug();
        if (!$slug || !$this->providers->has($slug)) {
            $this->logger->error('SyncOrderStatusHandler: provider slug inválido.', [
                'order_id' => $order->getId(), 'slug' => $slug,
            ]);
            return;
        }

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $provider  = $this->providers->get($slug);
            $rawStatus = $provider->getOrderStatus($externalId);
            $status    = ProviderStatus::fromArray($rawStatus, $order->getQuantity());
        } catch (\Throwable $e) {
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->error('SyncOrderStatusHandler: falha na consulta ao provider.', [
                'order_id' => $order->getId(), 'provider' => $slug, 'exception' => $e->getMessage(),
            ]);

            $this->saveLog($order, $slug, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);

            $order->incrementSyncAttempts();
            $this->scheduleNextSync($order);
            $this->em->flush();
            return;
        }

        $elapsed = (int) round(microtime(true) * 1000) - $startMs;
        $order->incrementSyncAttempts();

        $this->logger->info('SyncOrderStatusHandler: status recebido do provider.', [
            'order_id'  => $order->getId(),
            'provider'  => $slug,
            'state'     => $status->state,
            'delivered' => $status->delivered,
            'attempts'  => $order->getSyncAttempts(),
        ]);

        // Salva log de cada consulta ao provider — nunca sobrescreve, sempre insere
        $this->saveLog($order, $slug, 200, [
            'external_status' => $status->state,
            'delivered'       => $status->delivered,
            'remains'         => $rawStatus['remains'] ?? null,
        ], null, $elapsed);

        match ($status->state) {
            'completed' => $this->handleCompleted($order, $status->delivered),
            'partial'   => $this->handlePartial($order, $status->delivered),
            'cancelled' => $this->handleCancelled($order, $status->reason ?? 'Cancelado pelo provider'),
            default     => $this->scheduleNextSync($order),
        };

        $this->em->flush();
    }

    // ── Handlers de estado ───────────────────────────────────────────────

    private function handleCompleted(Order $order, int $delivered): void
    {
        $order->markAsCompleted($delivered);
        $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'completed'));
        $this->logger->info('SyncOrderStatusHandler: pedido completado.', [
            'order_id' => $order->getId(), 'delivered' => $delivered,
        ]);
    }

    private function handlePartial(Order $order, int $delivered): void
    {
        $order->markAsPartial($delivered);
        $this->scheduleNextSync($order);
        $this->logger->info('SyncOrderStatusHandler: pedido parcial, agendando novo sync.', [
            'order_id' => $order->getId(), 'delivered' => $delivered,
        ]);
    }

    private function handleCancelled(Order $order, string $reason): void
    {
        $order->markAsFailed($reason);

        $refundCents = $order->getAmountCents();

        if ($refundCents > 0) {
            $wallet = $this->wallets->findOneBy(['user' => $order->getUser()]);

            if ($wallet) {
                $wallet->credit($refundCents);

                $tx = (new WalletTransaction())
                    ->setWallet($wallet)
                    ->setType(TransactionType::REFUND)
                    ->setAmountCents($refundCents)
                    ->setBalanceAfterCents($wallet->getBalanceCents())
                    ->setDescription(sprintf(
                        'Reembolso automático — pedido #%d cancelado pelo provider (%s)',
                        $order->getId(),
                        mb_substr($reason, 0, 150)
                    ));

                $this->em->persist($tx);

                $this->logger->info('SyncOrderStatusHandler: reembolso efetuado.', [
                    'order_id' => $order->getId(), 'user_id' => $order->getUser()->getId(),
                    'refund_cents' => $refundCents, 'reason' => $reason,
                ]);
            } else {
                $this->logger->error('SyncOrderStatusHandler: carteira não encontrada, reembolso não realizado.', [
                    'order_id' => $order->getId(), 'user_id' => $order->getUser()->getId(),
                ]);
            }
        }

        $this->bus->dispatch(new OrderNotificationMessage($order->getId(), 'cancelled'));

        $this->logger->warning('SyncOrderStatusHandler: pedido cancelado pelo provider.', [
            'order_id' => $order->getId(), 'reason' => $reason,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Insere SEMPRE uma nova linha no order_logs — nunca atualiza existente.
     */
    private function saveLog(
        Order   $order,
        string  $slug,
        ?int    $httpStatus,
        ?array  $context,
        ?string $errorMessage,
        ?int    $elapsedMs,
    ): void {
        $log = (new OrderLog())
            ->setOrder($order)
            ->setProvider($slug)
            ->setAction(OrderLog::ACTION_SYNC)
            ->setHttpStatus($httpStatus)
            ->setResponseBody(null)
            ->setErrorMessage($errorMessage)
            ->setElapsedMs($elapsedMs)
            ->setContext($context)
            ->setRetryCount($order->getSyncAttempts());

        $this->em->persist($log);
    }

    /**
     * Agenda o próximo sync com delay exponencial.
     * Tentativas: 0→2min | 1→4min | 2→8min | 3→16min | 4+→30min (teto)
     */
    private function scheduleNextSync(Order $order): void
    {
        $delayMs = min(
            120_000 * (2 ** $order->getSyncAttempts()),
            1_800_000
        );

        $this->bus->dispatch(
            new SyncOrderStatusMessage($order->getId()),
            [new DelayStamp($delayMs)]
        );

        $this->logger->debug('SyncOrderStatusHandler: próximo sync agendado.', [
            'order_id' => $order->getId(), 'delay_ms' => $delayMs, 'attempts' => $order->getSyncAttempts(),
        ]);
    }
}
