<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use App\Message\Order\SyncOrderStatusMessage;
use App\Message\ProcessOrderMessage;
use App\Repository\WalletRepository;
use App\Smm\Exception\ProviderBusinessException;
use App\Smm\SmmProviderRegistry;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * Fallback/retry para pedidos que ficaram em STATUS_PENDING.
 *
 * Esse handler NÃO é mais invocado no fluxo normal de criação de pedidos.
 * O OrderController envia ao provider de forma síncrona no request.
 *
 * Este handler é acionado apenas em situações de recuperação:
 *   - Pedidos que ficaram PENDING por crash mid-request
 *   - Retry manual via admin ou scheduler de cleanup
 *
 * Para agendar recovery de pedidos travados, o scheduler pode disparar:
 *   $bus->dispatch(new ProcessOrderMessage($order->getId()));
 */
#[AsMessageHandler]
final class ProcessOrderHandler
{
    private const FIRST_SYNC_DELAY_MS = 120_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SmmProviderRegistry    $registry,
        private readonly WalletRepository       $walletRepo,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    public function __invoke(ProcessOrderMessage $message): void
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();

        try {
            /** @var Order|null $order */
            $order = $this->em->find(Order::class, $message->orderId, LockMode::PESSIMISTIC_WRITE);

            if (!$order) {
                $this->logger->warning('ProcessOrderHandler: pedido não encontrado.', ['order_id' => $message->orderId]);
                $conn->rollBack();
                return;
            }

            if ($order->getStatus() !== Order::STATUS_PENDING) {
                $this->logger->info('ProcessOrderHandler: pedido já processado, ignorando.', [
                    'order_id' => $message->orderId,
                    'status'   => $order->getStatus(),
                ]);
                $conn->rollBack();
                return;
            }

            $service = $order->getService();
            $slug    = $service->getProviderSlug();

            if (!$slug || !$this->registry->has($slug)) {
                $this->logger->error('ProcessOrderHandler: provider slug não configurado.', [
                    'order_id' => $order->getId(), 'slug' => $slug,
                ]);
                $this->saveLog($order, $slug ?? 'unknown', OrderLog::ACTION_ADD, null, null, 'Provider não configurado', null);
                $this->cancelWithRefund($order, 'Provider não configurado');
                $this->em->flush();
                $conn->commit();
                return;
            }

            $provider = $this->registry->get($slug);

            $this->logger->info('ProcessOrderHandler (fallback): reenviando pedido ao provider.', [
                'order_id'            => $order->getId(),
                'provider'            => $slug,
                'external_service_id' => $service->getExternalServiceId(),
                'target_url'          => $order->getTargetUrl(),
                'quantity'            => $order->getQuantity(),
            ]);

            $startMs = (int) round(microtime(true) * 1000);

            try {
                $externalId = $provider->addOrder(
                    $service->getExternalServiceId(),
                    $order->getTargetUrl(),
                    $order->getQuantity()
                );

                $elapsed = (int) round(microtime(true) * 1000) - $startMs;

                $order->setExternalOrderId($externalId);
                $order->setStatus(Order::STATUS_PROCESSING);

                $this->saveLog($order, $slug, OrderLog::ACTION_ADD, 200, ['order' => $externalId], null, $elapsed);

                $this->logger->info('ProcessOrderHandler (fallback): pedido aceito pelo provider.', [
                    'order_id' => $order->getId(), 'external_id' => $externalId, 'provider' => $slug,
                ]);

                $this->em->flush();
                $conn->commit();

                $this->bus->dispatch(
                    new SyncOrderStatusMessage($order->getId()),
                    [new DelayStamp(self::FIRST_SYNC_DELAY_MS)]
                );

                return;

            } catch (ProviderBusinessException $e) {
                $elapsed = (int) round(microtime(true) * 1000) - $startMs;

                $this->logger->error('ProcessOrderHandler (fallback): erro de negócio → cancelando com reembolso.', [
                    'order_id' => $order->getId(), 'provider' => $slug, 'error' => $e->getMessage(),
                ]);

                $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
                $this->cancelWithRefund($order, $e->getMessage());
                $this->em->flush();
                $conn->commit();
                return;

            } catch (\Throwable $e) {
                $elapsed = (int) round(microtime(true) * 1000) - $startMs;

                $this->logger->error('ProcessOrderHandler (fallback): falha técnica → cancelando com reembolso.', [
                    'order_id' => $order->getId(), 'provider' => $slug,
                    'error' => $e->getMessage(), 'error_type' => $e::class,
                ]);

                $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
                $this->cancelWithRefund($order, $e->getMessage());
                $this->em->flush();
                $conn->commit();

                throw $e;
            }

        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

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
                'order_id' => $order->getId(), 'user_id' => $order->getUser()->getId(),
            ]);
            return;
        }

        $wallet->credit($amount);

        $tx = (new WalletTransaction())
            ->setWallet($wallet)
            ->setType(TransactionType::REFUND)
            ->setAmountCents($amount)
            ->setBalanceAfterCents($wallet->getBalanceCents())
            ->setDescription(sprintf(
                'Reembolso automático — pedido #%d não enviado ao provider (%s)',
                $order->getId(),
                mb_substr($reason, 0, 150)
            ));

        $this->em->persist($tx);

        $this->logger->info('ProcessOrderHandler: reembolso efetuado.', [
            'order_id' => $order->getId(), 'user_id' => $order->getUser()->getId(),
            'refund_cents' => $amount, 'reason' => $reason,
        ]);
    }

    private function saveLog(
        Order   $order,
        string  $provider,
        string  $action,
        ?int    $httpStatus,
        ?array  $responseBody,
        ?string $errorMessage,
        ?int    $elapsedMs,
        ?array  $context = null,
        int     $retryCount = 0,
    ): void {
        $log = (new OrderLog())
            ->setOrder($order)
            ->setProvider($provider)
            ->setAction($action)
            ->setHttpStatus($httpStatus)
            ->setResponseBody($responseBody)
            ->setErrorMessage($errorMessage)
            ->setElapsedMs($elapsedMs)
            ->setContext($context)
            ->setRetryCount($retryCount);

        $this->em->persist($log);
    }
}
