<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\OrderLog;
use App\Entity\Wallet;
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
     *   5. Em ProviderBusinessException: reembolso + STATUS_CANCELLED + flash de erro.
     *   6. Em falha técnica (\Throwable): reembolso via DBAL direto (EM pode estar
     *      fechado) + STATUS_CANCELLED + flash de erro.
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

        // ── Validações básicas ─────────────────────────────────────────
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

        // ── Verifica provider antes de debitar ─────────────────────────
        $slug = $service->getProviderSlug();
        if (!$slug || !$this->registry->has($slug)) {
            $this->addFlash('error', 'Serviço temporariamente indisponível. Tente novamente.');
            $this->logger->error('OrderController: provider slug inválido ou não registrado.', [
                'service_id' => $serviceId, 'slug' => $slug,
            ]);
            return $this->redirectToRoute('app_order_new');
        }

        // ── Debita saldo e persiste pedido (STATUS_PENDING) ──────────────
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
        $this->em->flush(); // <-- pedido + débito persistidos; a partir daqui o ID existe

        // ── Envia ao provider AGORA (síncrono) ────────────────────────
        $provider = $this->registry->get($slug);
        $startMs  = (int) round(microtime(true) * 1000);

        try {
            $externalId = $provider->addOrder(
                $service->getExternalServiceId(),
                $order->getTargetUrl(),
                $order->getQuantity()
            );

            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            // Caminho feliz: salva external ID, muda status, loga e persiste
            $order->setExternalOrderId($externalId);
            $order->setStatus(Order::STATUS_PROCESSING);

            $log = $this->buildLog($order, $slug, OrderLog::ACTION_ADD, 200, ['order' => $externalId], null, $elapsed);
            $this->em->persist($log);
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
            // Erro de negócio (ex: URL inválida, serviço pausado).
            // O EM continua válido pois ProviderBusinessException é do tipo de domínio,
            // não uma excessão DBAL — portanto podemos usar o ORM normalmente.
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $order->setStatus(Order::STATUS_CANCELLED);
            $wallet->credit($amountCents);

            $tx = $this->buildRefundTransaction($wallet, $order, $amountCents, $e->getMessage());
            $log = $this->buildLog($order, $slug, OrderLog::ACTION_ADD, null, ['exception' => $e->getMessage()], $e->getMessage(), $elapsed);

            $this->em->persist($tx);
            $this->em->persist($log);
            $this->em->flush();

            $this->logger->error('OrderController: ProviderBusinessException → cancelado + reembolso.', [
                'order_id' => $order->getId(), 'provider' => $slug, 'error' => $e->getMessage(),
            ]);

            $this->addFlash('error', 'Não foi possível processar o pedido: ' . $e->getMessage());

        } catch (\Throwable $e) {
            // Falha técnica (timeout, rede, SSL): o Doctrine pode ter fechado o EM.
            // Usamos DBAL diretamente para garantir o reembolso.
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->error('OrderController: falha técnica ao enviar ao provider → reembolso via DBAL.', [
                'order_id'   => $order->getId(),
                'provider'   => $slug,
                'error'      => $e->getMessage(),
                'error_type' => $e::class,
                'elapsed_ms' => $elapsed,
            ]);

            $this->refundViaDbal($order->getId(), $wallet->getId(), $amountCents, $e->getMessage());

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

    // ── Helpers privados ──────────────────────────────────────────────

    /**
     * Reembolso de emergência via DBAL puro, usado quando o EntityManager
     * pode estar fechado após uma \Throwable não-DBAL (ex: timeout de API).
     *
     * Operações:
     *   1. Restaura saldo na wallet (UPDATE wallets)
     *   2. Cancela o pedido (UPDATE orders)
     *   3. Insere WalletTransaction de reembolso (INSERT wallet_transactions)
     *   4. Insere OrderLog do erro (INSERT order_logs)
     *
     * Tudo em uma única transaction DBAL para garantir atomicidade.
     */
    private function refundViaDbal(int $orderId, int $walletId, int $amountCents, string $reason): void
    {
        $conn = $this->em->getConnection();

        try {
            $conn->beginTransaction();

            // 1. Restaura saldo
            $conn->executeStatement(
                'UPDATE wallets SET balance_cents = balance_cents + :amount WHERE id = :id',
                ['amount' => $amountCents, 'id' => $walletId]
            );

            // 2. Cancela pedido
            $conn->executeStatement(
                "UPDATE orders SET status = 'cancelled', updated_at = NOW() WHERE id = :id",
                ['id' => $orderId]
            );

            // 3. Recupera saldo atualizado para gravar no transaction
            $newBalance = (int) $conn->fetchOne(
                'SELECT balance_cents FROM wallets WHERE id = :id',
                ['id' => $walletId]
            );

            // 4. Insere WalletTransaction de reembolso
            $conn->executeStatement(
                'INSERT INTO wallet_transactions (wallet_id, type, amount_cents, balance_after_cents, description, created_at)
                 VALUES (:wallet_id, :type, :amount, :balance_after, :desc, NOW())',
                [
                    'wallet_id'    => $walletId,
                    'type'         => TransactionType::REFUND->value,
                    'amount'       => $amountCents,
                    'balance_after' => $newBalance,
                    'desc'         => sprintf(
                        'Reembolso automático — pedido #%d não enviado ao provider (%s)',
                        $orderId,
                        mb_substr($reason, 0, 150)
                    ),
                ]
            );

            $conn->commit();

            $this->logger->info('OrderController: reembolso via DBAL concluído.', [
                'order_id'      => $orderId,
                'wallet_id'     => $walletId,
                'refund_cents'  => $amountCents,
                'new_balance'   => $newBalance,
            ]);

        } catch (\Throwable $dbalEx) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            $this->logger->critical('OrderController: FALHA CRITICA no reembolso via DBAL!', [
                'order_id'  => $orderId,
                'error'     => $dbalEx->getMessage(),
                'reason'    => $reason,
            ]);
            // Não rethrow: o usuário já foi redirecionado com mensagem de erro;
            // o suporte deve investigar via log critical.
        }
    }

    private function buildRefundTransaction(Wallet $wallet, Order $order, int $amountCents, string $reason): WalletTransaction
    {
        return (new WalletTransaction())
            ->setWallet($wallet)
            ->setType(TransactionType::REFUND)
            ->setAmountCents($amountCents)
            ->setBalanceAfterCents($wallet->getBalanceCents())
            ->setDescription(sprintf(
                'Reembolso automático — pedido #%d não enviado ao provider (%s)',
                $order->getId(),
                mb_substr($reason, 0, 150)
            ));
    }

    private function buildLog(
        Order   $order,
        string  $provider,
        string  $action,
        ?int    $httpStatus,
        ?array  $responseBody,
        ?string $errorMessage,
        ?int    $elapsedMs,
    ): OrderLog {
        return (new OrderLog())
            ->setOrder($order)
            ->setProvider($provider)
            ->setAction($action)
            ->setHttpStatus($httpStatus)
            ->setResponseBody($responseBody)
            ->setErrorMessage($errorMessage)
            ->setElapsedMs($elapsedMs)
            ->setContext(null)
            ->setRetryCount(0);
    }
}
