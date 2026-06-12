<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Message\ProcessOrderMessage;
use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\WalletRepository;
use App\Smm\Exception\ProviderBusinessException;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final class ProcessOrderHandler
{
    /**
     * Delay do primeiro sync após o provider aceitar o pedido: 2 minutos.
     * Os syncs seguintes usam backoff exponencial no SyncOrderStatusHandler.
     */
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

        // ── Valida provider ───────────────────────────────────────────────
        if (!$slug || !$this->registry->has($slug)) {
            $this->logger->error('ProcessOrderHandler: provider slug não configurado ou inexistente.', [
                'order_id'   => $order->getId(),
                'service_id' => $service->getId(),
                'slug'       => $slug,
            ]);
            $this->saveLog($order, $slug ?? 'unknown', OrderLog::ACTION_ADD, null, null, 'Provider não configurado ou inexistente', null);
            $this->cancelWithRefund($order, 'Provider não configurado ou inexistente');
            $this->em->flush();
            return;
        }

        // ── Envia ao provider ─────────────────────────────────────────────
        $provider = $this->registry->get($slug);

        $this->logger->info('ProcessOrderHandler: enviando pedido ao provider.', [
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

            $this->logger->info('ProcessOrderHandler: pedido aceito pelo provider.', [
                'order_id'    => $order->getId(),
                'external_id' => $externalId,
                'provider'    => $slug,
            ]);

            $this->em->flush();
            $this->bus->dispatch(
                new SyncOrderStatusMessage($order->getId()),
                [new DelayStamp(self::FIRST_SYNC_DELAY_MS)]
            );

            $this->logger->info('ProcessOrderHandler: primeiro sync agendado.', [
                'order_id' => $order->getId(),
                'delay_ms' => self::FIRST_SYNC_DELAY_MS,
            ]);

            return;

        } catch (ProviderBusinessException $e) {
            // ❌ Erro de negócio (link inválido, duplicado, qtd fora do range…)
            // → cancela e reembolsa imediatamente; não gera retry
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->error('ProcessOrderHandler: erro de negócio do provider → cancelando com reembolso.', [
                'order_id'   => $order->getId(),
                'provider'   => $slug,
                'error'      => $e->getMessage(),
            ]);

            $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
            $this->cancelWithRefund($order, $e->getMessage());

        } catch (\Throwable $e) {
            // ⚠️ Erro técnico (timeout, 5xx, rede…)
            // → marca como STATUS_ERROR e relança para o Messenger enviar ao transport `failed`
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->error('ProcessOrderHandler: falha técnica ao enviar pedido → marcando como error para retry.', [
                'order_id'   => $order->getId(),
                'provider'   => $slug,
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
            ]);

            $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
            $order->setStatus(Order::STATUS_ERROR);
            $this->em->flush();

            // Relança para o Messenger capturar e encaminhar ao `failed` transport
            throw $e;
        }

        $this->em->flush();
    }

    // ── Helpers ───────────────────────────────────────────────────────────

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

    private function saveLog(
        Order   $order,
        string  $provider,
        string  $action,
        ?int    $httpStatus,
        ?array  $responseBody,
        ?string $errorMessage,
        ?int    $elapsedMs,
    ): void {
        $log = (new OrderLog())
            ->setOrder($order)
            ->setProvider($provider)
            ->setAction($action)
            ->setHttpStatus($httpStatus)
            ->setResponseBody($responseBody)
            ->setErrorMessage($errorMessage)
            ->setElapsedMs($elapsedMs);

        $this->em->persist($log);
    }
}
