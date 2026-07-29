<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/payments-dashboard', name: 'admin_payments_dashboard_')]
class AdminPaymentDashboardController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    // -----------------------------------------------------------------------
    // Dashboard principal
    // -----------------------------------------------------------------------
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $repo = $this->em->getRepository(Payment::class);

        // --- KPIs -----------------------------------------------------------
        $totalApproved = (int) $repo->createQueryBuilder('p')
            ->select('SUM(p.amountCents)')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_APPROVED)
            ->getQuery()->getSingleScalarResult();

        $totalPending = (int) $repo->createQueryBuilder('p')
            ->select('SUM(p.amountCents)')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_PENDING)
            ->getQuery()->getSingleScalarResult();

        $totalRefunded = (int) $repo->createQueryBuilder('p')
            ->select('SUM(p.amountCents)')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_REFUNDED)
            ->getQuery()->getSingleScalarResult();

        $countPending = (int) $repo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_PENDING)
            ->getQuery()->getSingleScalarResult();

        // --- Pagamentos pendentes (lista) -----------------------------------
        $pendingPayments = $repo->createQueryBuilder('p')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_PENDING)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        // --- Gráfico: receita por mês (últimos 6 meses) ---------------------
        $monthlyData = $repo->createQueryBuilder('p')
            ->select(
                'YEAR(p.paidAt) as yr',
                'MONTH(p.paidAt) as mo',
                'SUM(p.amountCents) as total'
            )
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_APPROVED)
            ->andWhere('p.paidAt >= :since')
            ->setParameter('since', new \DateTime('-6 months'))
            ->groupBy('yr, mo')
            ->orderBy('yr', 'ASC')->addOrderBy('mo', 'ASC')
            ->getQuery()->getArrayResult();

        $chartMonths = [];
        $chartApproved = [];
        foreach ($monthlyData as $row) {
            $chartMonths[]   = sprintf('%02d/%04d', $row['mo'], $row['yr']);
            $chartApproved[] = round((int)$row['total'] / 100, 2);
        }

        // --- Gráfico: status pizza -------------------------------------------
        $statusData = $repo->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) as cnt, SUM(p.amountCents) as total')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult();

        $statusLabels = [];
        $statusValues = [];
        $statusColors = [
            Payment::STATUS_APPROVED  => '#22c55e',
            Payment::STATUS_PENDING   => '#f59e0b',
            Payment::STATUS_FAILED    => '#ef4444',
            Payment::STATUS_CANCELLED => '#6b7280',
            Payment::STATUS_REFUNDED  => '#8b5cf6',
        ];
        $statusChartColors = [];
        $statusNames = [
            Payment::STATUS_APPROVED  => 'Aprovado',
            Payment::STATUS_PENDING   => 'Pendente',
            Payment::STATUS_FAILED    => 'Falhou',
            Payment::STATUS_CANCELLED => 'Cancelado',
            Payment::STATUS_REFUNDED  => 'Estornado',
        ];
        foreach ($statusData as $row) {
            $statusLabels[]      = $statusNames[$row['status']] ?? $row['status'];
            $statusValues[]      = round((int)$row['total'] / 100, 2);
            $statusChartColors[] = $statusColors[$row['status']] ?? '#94a3b8';
        }

        // --- Lista de usuários para form manual ----------------------------
        $users = $this->em->getRepository(User::class)
            ->createQueryBuilder('u')
            ->orderBy('u.name', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/payments_dashboard.html.twig', [
            'totalApproved'     => $totalApproved,
            'totalPending'      => $totalPending,
            'totalRefunded'     => $totalRefunded,
            'countPending'      => $countPending,
            'pendingPayments'   => $pendingPayments,
            'chartMonths'       => json_encode($chartMonths),
            'chartApproved'     => json_encode($chartApproved),
            'statusLabels'      => json_encode($statusLabels),
            'statusValues'      => json_encode($statusValues),
            'statusChartColors' => json_encode($statusChartColors),
            'users'             => $users,
        ]);
    }

    // -----------------------------------------------------------------------
    // Criar pagamento manual (admin insere depósito direto)
    // -----------------------------------------------------------------------
    #[Route('/create-manual', name: 'create_manual', methods: ['POST'])]
    public function createManual(Request $request): Response
    {
        $userId    = (int) $request->request->get('user_id');
        $amountStr = str_replace(',', '.', (string) $request->request->get('amount', '0'));
        $amount    = (float) $amountStr;
        $method    = $request->request->get('method', Payment::METHOD_PIX);
        $note      = $request->request->get('note', 'Depósito manual via admin');
        $autoApprove = $request->request->getBoolean('auto_approve', false);

        if ($amount <= 0 || $userId <= 0) {
            $this->addFlash('danger', 'Preencha usuário e valor corretamente.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $this->addFlash('danger', 'Usuário não encontrado.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $amountCents = (int) round($amount * 100);

        $payment = (new Payment())
            ->setUser($user)
            ->setAmountCents($amountCents)
            ->setFeeCents(0)
            ->setMethod($method)
            ->setStatus($autoApprove ? Payment::STATUS_APPROVED : Payment::STATUS_PENDING)
            ->setExternalId('MANUAL-' . strtoupper(bin2hex(random_bytes(4))))
            ->setGatewayResponse($note);

        if ($autoApprove) {
            $payment->approve();

            // Creditar na carteira
            $wallet = $user->getWallet();
            if (!$wallet) {
                $wallet = (new Wallet())->setUser($user)->setBalanceCents(0);
                $this->em->persist($wallet);
            }
            $wallet->credit($amountCents);

            $tx = (new WalletTransaction())
                ->setWallet($wallet)
                ->setType(TransactionType::CREDIT)
                ->setAmountCents($amountCents)
                ->setBalanceAfterCents($wallet->getBalanceCents())
                ->setDescription(sprintf('Depósito manual (admin) — %s', $note));

            $this->em->persist($tx);
        }

        $this->em->persist($payment);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Pagamento manual criado para %s — R$ %.2f (%s).',
            $user->getName(),
            $amount,
            $autoApprove ? 'já aprovado e creditado' : 'aguardando aprovação'
        ));

        return $this->redirectToRoute('admin_payments_dashboard_index');
    }

    // -----------------------------------------------------------------------
    // Estorno: debita saldo do usuário (solicitado via suporte)
    // -----------------------------------------------------------------------
    #[Route('/refund-wallet', name: 'refund_wallet', methods: ['POST'])]
    public function refundWallet(Request $request): Response
    {
        $userId    = (int) $request->request->get('user_id');
        $amountStr = str_replace(',', '.', (string) $request->request->get('amount', '0'));
        $amount    = (float) $amountStr;
        $reason    = $request->request->get('reason', 'Estorno solicitado via suporte');

        if ($amount <= 0 || $userId <= 0) {
            $this->addFlash('danger', 'Preencha usuário e valor corretamente.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $this->addFlash('danger', 'Usuário não encontrado.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $wallet = $user->getWallet();
        if (!$wallet) {
            $this->addFlash('danger', sprintf('Usuário %s não possui carteira.', $user->getEmail()));
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $amountCents = (int) round($amount * 100);

        if ($wallet->getBalanceCents() < $amountCents) {
            $this->addFlash('warning', sprintf(
                'Saldo insuficiente: R$ %.2f disponível, tentou estornar R$ %.2f.',
                $wallet->getBalanceCents() / 100,
                $amount
            ));
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        // Debitar da carteira
        $wallet->debit($amountCents);

        // Registrar transação de REFUND
        $tx = (new WalletTransaction())
            ->setWallet($wallet)
            ->setType(TransactionType::REFUND)
            ->setAmountCents($amountCents)
            ->setBalanceAfterCents($wallet->getBalanceCents())
            ->setDescription(sprintf('Estorno admin — %s', $reason));

        $this->em->persist($tx);
        $this->em->flush();

        $this->addFlash('success', sprintf(
            'Estorno de R$ %.2f aplicado na conta de %s. Saldo restante: R$ %.2f.',
            $amount,
            $user->getName(),
            $wallet->getBalanceCents() / 100
        ));

        return $this->redirectToRoute('admin_payments_dashboard_index');
    }
}
