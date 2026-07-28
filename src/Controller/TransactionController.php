<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\Payment;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\WalletTransactionRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/transactions', name: 'app_transaction')]
class TransactionController extends AbstractController
{
    public function __construct(
        private readonly WalletTransactionRepository $walletTxRepo,
        private readonly PaymentRepository           $paymentRepo,
        private readonly OrderRepository             $orderRepo,
        private readonly WalletRepository            $walletRepo,
    ) {}

    #[Route('', name: '_index', methods: ['GET'])]
    public function index(): Response
    {
        /** @var \App\Entity\User $user */
        $user   = $this->getUser();
        $wallet = $this->walletRepo->findOneByUser($user);

        // ── 1. Transações da carteira (débitos de pedido, créditos, estornos, bônus) ──
        $walletTxs = $wallet
            ? $this->walletTxRepo->findByWalletDesc($wallet->getId())
            : [];

        // ── 2. Pagamentos/depósitos (PIX pendente, aprovado via webhook, estorno automático) ──
        $payments = $this->paymentRepo->findRecentByUser($user, 200);

        // ── 3. Todos os pedidos do usuário (débito ao criar, estorno ao cancelar/refund) ──
        $orders = $this->orderRepo->findByUserAndStatuses($user, [
            Order::STATUS_PENDING,
            Order::STATUS_PROCESSING,
            Order::STATUS_IN_PROGRESS,
            Order::STATUS_COMPLETED,
            Order::STATUS_PARTIAL,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
        ]);

        // ── 4. Monta feed unificado ──
        $feed = [];

        foreach ($walletTxs as $tx) {
            $feed[] = [
                'date'        => $tx->getCreatedAt(),
                'type'        => 'wallet',
                'subtype'     => $tx->getType()->value,   // credit | debit | refund | bonus
                'description' => $tx->getDescription(),
                'amountCents' => $tx->getAmountCents(),
                'balanceCents'=> $tx->getBalanceAfterCents(),
                'source'      => $tx,
            ];
        }

        foreach ($payments as $p) {
            $feed[] = [
                'date'        => $p->getCreatedAt(),
                'type'        => 'payment',
                'subtype'     => $p->getStatus(),
                'description' => match ($p->getStatus()) {
                    Payment::STATUS_APPROVED  => sprintf('Depósito aprovado via %s', strtoupper($p->getMethod())),
                    Payment::STATUS_PENDING   => sprintf('Depósito pendente via %s — aguardando pagamento', strtoupper($p->getMethod())),
                    Payment::STATUS_FAILED    => sprintf('Depósito falhou via %s', strtoupper($p->getMethod())),
                    Payment::STATUS_CANCELLED => sprintf('Depósito cancelado via %s', strtoupper($p->getMethod())),
                    Payment::STATUS_REFUNDED  => sprintf('Depósito estornado via %s (webhook)', strtoupper($p->getMethod())),
                    default                   => 'Pagamento',
                },
                'amountCents' => $p->getAmountCents(),
                'balanceCents'=> null,
                'source'      => $p,
            ];
        }

        foreach ($orders as $o) {
            $statusLabel = match ($o->getStatus()) {
                Order::STATUS_COMPLETED   => 'Concluído',
                Order::STATUS_PARTIAL     => 'Parcial',
                Order::STATUS_CANCELLED   => 'Cancelado — saldo estornado',
                Order::STATUS_REFUNDED    => 'Estornado automaticamente',
                Order::STATUS_PENDING     => 'Pendente',
                Order::STATUS_PROCESSING  => 'Processando',
                Order::STATUS_IN_PROGRESS => 'Em andamento',
                default                   => $o->getStatus(),
            };

            $feed[] = [
                'date'        => $o->getCreatedAt(),
                'type'        => 'order',
                'subtype'     => $o->getStatus(),
                'description' => sprintf('Pedido #%d — %s (%s)', $o->getId(), $o->getService()->getName(), $statusLabel),
                'amountCents' => $o->getAmountCents(),
                'balanceCents'=> null,
                'source'      => $o,
            ];
        }

        // ── 5. Ordena por data decrescente ──
        usort($feed, static fn ($a, $b) => $b['date'] <=> $a['date']);

        return $this->render('transaction/index.html.twig', [
            'feed'   => $feed,
            'wallet' => $wallet,
        ]);
    }
}
