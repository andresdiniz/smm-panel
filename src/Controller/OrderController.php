<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use App\Message\Order\SyncOrderStatusMessage;
use App\Repository\ServiceRepository;
use App\Repository\WalletRepository;
use App\Smm\Exception\ProviderBusinessException;
use App\Smm\SmmProviderRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/order', name: 'app_order_')]
class OrderController extends AbstractController
{
    /** Delay do primeiro sync de status após envio bem-sucedido (2 min). */
    private const FIRST_SYNC_DELAY_MS = 120_000;

    public function __construct(
        private readonly ServiceRepository      $serviceRepository,
        private readonly WalletRepository       $walletRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly SmmProviderRegistry    $registry,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Exibe o formulário de novo pedido.
     */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(): Response
    {
        return $this->render('order/new.html.twig', [
            'servicesGrouped' => $this->serviceRepository->findAllGroupedByCategory(),
        ]);
    }

    /**
     * Processa o envio do pedido de forma SÍNCRONA:
     *   1. Valida CSRF, dados e saldo.
     *   2. Debita carteira e persiste Order (STATUS_PENDING).
     *   3. Envia ao provider AGORA, no request — sem depender de worker.
     *   4. Em sucesso: STATUS_PROCESSING + SyncOrderStatusMessage na fila.
     *   5. Em qualquer falha: reembolso imediato + STATUS_CANCELLED.
     */
    #[Route('/new', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('order_create', $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido. Recarregue a página.');
            return $this->redirectToRoute('app_order_new');
        }

        $user      = $this->getUser();
        $serviceId = (int) $request->request->get('service_id');
        $quantity  = (int) $request->request->get('quantity');
        $targetUrl = trim($request->request->get('target_url', ''));

        // ── Validações básicas ──────────────────────────────────────────
        if (!$targetUrl || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            $this->addFlash('error', 'URL alvo inválida.');
            return $this->redirectToRoute('app_order_new');
        }

        $service = $this->serviceRepository->find($serviceId);
        if (!$service || !$service->isActive()) {
            $this->addFlash('error', 'Serviço não encontrado ou inativo.');
            return $this->redirectToRoute('app_order_new');
        }

        if ($quantity < $service->getMinQty() || $quantity > $service->getMaxQty()) {
            $this->addFlash('error', sprintf(
                'Quantidade inválida. Mínimo: %d | Máximo: %d.',
                $service->getMinQty(),
                $service->getMaxQty()
            ));
            return $this->redirectToRoute('app_order_new');
        }

        $amountCents = $service->calculatePriceCents($quantity);
        $wallet      = $this->walletRepository->findOneByUser($user);

        if (!$wallet || $wallet->getBalanceCents() < $amountCents) {
            $this->addFlash('error', 'Saldo insuficiente. Recarregue sua carteira.');
            return $this->redirectToRoute('app_order_new');
        }

        // ── Verifica provider antes de debitar ──────────────────────────
        $slug = $service->getProviderSlug();
        if (!$slug || !$this->registry->has($slug)) {
            $this->addFlash('error', 'Serviço temporariamente indisponível. Tente novamente.');
            $this->logger->error('OrderController: provider slug inválido ou não registrado.', [
                'service_id' => $serviceId, 'slug' => $slug,
            ]);
            return $this->redirectToRoute('app_order_new');
        }

        // ── Debita saldo e persiste pedido ──────────────────────────────
        $wallet->debit($amountCents);

        $order = new Order();
        $order->setUser($user);
        $order->setService($service);
        $order->setQuantity($quantity);
        $order->setTargetUrl($targetUrl);
        $order->setAmountCents($amountCents);
        $order->setStatus(Order::STATUS_PENDING);

        $this->em->persist($order);
        $this->em->persist($wallet);
        $this->em->flush();

        // ── Envia ao provider AGORA (síncrono) ──────────────────────────
        $provider = $this->registry->get($slug);
        $startMs  = (int) round(microtime(true) * 1000);

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

            $this->em->flush();

            // Agenda primeiro sync de status na fila (2 min)
            $this->bus->dispatch(
                new SyncOrderStatusMessage($order->getId()),
                [new DelayStamp(self::FIRST_SYNC_DELAY_MS)]
            );

            $this->logger->info('OrderController: pedido enviado ao provider com sucesso.', [
                'order_id'    => $order->getId(),
                'external_id' => $externalId,
                'provider'    => $slug,
                'elapsed_ms'  => $elapsed,
            ]);

            $this->addFlash('success', sprintf(
                'Pedido #%d criado e enviado com sucesso! Acompanhe o status no painel.',
                $order->getId()
            ));

        } catch (ProviderBusinessException $e) {
            // Erro de negócio do provider (ex: URL inválida, serviço pausado) → reembolso imediato
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;
            $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
            $this->cancelWithRefund($order, $wallet, $amountCents, $e->getMessage());
            $this->em->flush();

            $this->logger->error('OrderController: ProviderBusinessException → pedido cancelado com reembolso.', [
                'order_id' => $order->getId(), 'provider' => $slug, 'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', sprintf(
                'Não foi possível processar o pedido: %s',
                $e->getMessage()
            ));

        } catch (\Throwable $e) {
            // Falha técnica (timeout, rede, etc.) → reembolso imediato
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;
            $this->saveLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);
            $this->cancelWithRefund($order, $wallet, $amountCents, $e->getMessage());
            $this->em->flush();

            $this->logger->error('OrderController: falha técnica ao enviar ao provider → pedido cancelado com reembolso.', [
                'order_id'   => $order->getId(),
                'provider'   => $slug,
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
            ]);

            $this->addFlash('error', 'Erro ao comunicar com o provedor. Seu saldo foi estornado automaticamente.');
        }

        return $this->redirectToRoute('app_dashboard');
    }

    /**
     * Detalhe de um pedido do usuário autenticado.
     */
    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(int $id): Response
    {
        $order = $this->em->find(Order::class, $id);

        if (!$order || $order->getUser() !== $this->getUser()) {
            throw $this->createNotFoundException('Pedido não encontrado.');
        }

        return $this->render('order/show.html.twig', ['order' => $order]);
    }

    // ── Helpers privados ─────────────────────────────────────────────────

    private function cancelWithRefund(
        Order  $order,
        object $wallet,
        int    $amountCents,
        string $reason,
    ): void {
        $order->setStatus(Order::STATUS_CANCELLED);
        $wallet->credit($amountCents);

        $tx = (new WalletTransaction())
            ->setWallet($wallet)
            ->setType(TransactionType::REFUND)
            ->setAmountCents($amountCents)
            ->setBalanceAfterCents($wallet->getBalanceCents())
            ->setDescription(sprintf(
                'Reembolso automático — pedido #%d não enviado ao provider (%s)',
                $order->getId(),
                mb_substr($reason, 0, 150)
            ));

        $this->em->persist($tx);
        $this->em->persist($wallet);
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
            ->setElapsedMs($elapsedMs)
            ->setContext(null)
            ->setRetryCount(0);

        $this->em->persist($log);
    }
}
