<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Payment;
use App\Entity\User;
use App\Entity\Wallet;
use App\Entity\WalletTransaction;
use App\Enum\TransactionType;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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

        // Compute all dashboard KPIs in one database round trip.
        $summary = $this->em->getConnection()->fetchAssociative(
            <<<'SQL'
                SELECT
                    COALESCE(SUM(CASE WHEN status = :approved THEN amount_cents ELSE 0 END), 0) AS total_approved,
                    COALESCE(SUM(CASE WHEN status = :pending_total THEN amount_cents ELSE 0 END), 0) AS total_pending,
                    COALESCE(SUM(CASE WHEN status = :refunded THEN amount_cents ELSE 0 END), 0) AS total_refunded,
                    COALESCE(SUM(CASE WHEN status = :pending_count THEN 1 ELSE 0 END), 0) AS count_pending
                FROM payments
                SQL,
            [
                'approved' => Payment::STATUS_APPROVED,
                'pending_total' => Payment::STATUS_PENDING,
                'refunded' => Payment::STATUS_REFUNDED,
                'pending_count' => Payment::STATUS_PENDING,
            ],
        );
        $totalApproved = (int) $summary['total_approved'];
        $totalPending = (int) $summary['total_pending'];
        $totalRefunded = (int) $summary['total_refunded'];
        $countPending = (int) $summary['count_pending'];

        // --- Pagamentos pendentes (lista) -----------------------------------
        $pendingPayments = $repo->createQueryBuilder('p')
            ->addSelect('u')
            ->join('p.user', 'u')
            ->where('p.status = :s')->setParameter('s', Payment::STATUS_PENDING)
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults(50)
            ->getQuery()->getResult();

        // --- Gráfico: receita por mês (últimos 6 meses) — SQL nativo --------
        // Tabela real no banco = 'payments'  (ORM\Table name na entidade Payment)
        $since = (new \DateTime('-6 months'))->format('Y-m-d H:i:s');
        $conn  = $this->em->getConnection();

        $monthlyRows = $conn->fetchAllAssociative(
            "SELECT DATE_FORMAT(paid_at, '%Y-%m') AS ym,
                    SUM(amount_cents) AS total
             FROM payments
             WHERE status = :status
               AND paid_at >= :since
             GROUP BY ym
             ORDER BY ym ASC",
            ['status' => Payment::STATUS_APPROVED, 'since' => $since]
        );

        $chartMonths   = [];
        $chartApproved = [];
        foreach ($monthlyRows as $row) {
            [$yr, $mo]     = explode('-', $row['ym']);
            $chartMonths[] = sprintf('%s/%s', $mo, $yr);
            $chartApproved[] = round((int) $row['total'] / 100, 2);
        }

        // --- Gráfico: status pizza (DQL simples, sem funções de data) ------
        $statusData = $repo->createQueryBuilder('p')
            ->select('p.status, COUNT(p.id) as cnt, SUM(p.amountCents) as total')
            ->groupBy('p.status')
            ->getQuery()->getArrayResult();

        $statusColors = [
            Payment::STATUS_APPROVED  => '#22c55e',
            Payment::STATUS_PENDING   => '#f59e0b',
            Payment::STATUS_FAILED    => '#ef4444',
            Payment::STATUS_CANCELLED => '#6b7280',
            Payment::STATUS_REFUNDED  => '#8b5cf6',
        ];
        $statusNames = [
            Payment::STATUS_APPROVED  => 'Aprovado',
            Payment::STATUS_PENDING   => 'Pendente',
            Payment::STATUS_FAILED    => 'Falhou',
            Payment::STATUS_CANCELLED => 'Cancelado',
            Payment::STATUS_REFUNDED  => 'Estornado',
        ];

        $statusLabels      = [];
        $statusValues      = [];
        $statusChartColors = [];
        foreach ($statusData as $row) {
            $statusLabels[]      = $statusNames[$row['status']] ?? $row['status'];
            $statusValues[]      = round((int) $row['total'] / 100, 2);
            $statusChartColors[] = $statusColors[$row['status']] ?? '#94a3b8';
        }

        // --- Lista de usuários para forms manuais --------------------------
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
    // Criar pagamento manual
    // -----------------------------------------------------------------------
    #[Route('/create-manual', name: 'create_manual', methods: ['POST'])]
    public function createManual(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('manual_payment_create', $token)) {
            $this->addFlash('danger', 'Token inválido. Recarregue a página.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $userIdValue = $request->request->get('user_id');
        $userId = is_string($userIdValue) && ctype_digit($userIdValue) ? (int) $userIdValue : 0;
        $amountCents = $this->parseAmountCents($request->request->get('amount'));
        $method = $request->request->get('method', Payment::METHOD_PIX);
        $note = $request->request->get('note', 'Depósito manual via admin');
        $autoApprove = $request->request->getBoolean('auto_approve', false);

        if (
            $amountCents === null
            || $amountCents <= 0
            || $userId <= 0
            || !is_string($method)
            || !in_array($method, [Payment::METHOD_PIX, Payment::METHOD_CREDIT_CARD, Payment::METHOD_DEBIT_CARD], true)
            || !is_string($note)
        ) {
            $this->addFlash('danger', 'Preencha usuário e valor corretamente.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }
        $note = mb_substr($note, 0, 160);
        $amount = $amountCents / 100;

        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $this->addFlash('danger', 'Usuário não encontrado.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $payment = (new Payment())
            ->setUser($user)
            ->setAmountCents($amountCents)
            ->setFeeCents(0)
            ->setMethod($method)
            ->setStatus(Payment::STATUS_PENDING)
            ->setExternalId('MANUAL-' . strtoupper(bin2hex(random_bytes(4))))
            ->setGatewayResponse($note)
            ->setType(Payment::TYPE_DEPOSIT);

        $this->em->wrapInTransaction(function () use ($user, $payment, $amountCents, $autoApprove, $note): void {
            if ($autoApprove) {
                $this->em->refresh($user, LockMode::PESSIMISTIC_WRITE);
                $wallet = $this->em->createQueryBuilder()
                    ->select('w')
                    ->from(Wallet::class, 'w')
                    ->where('w.user = :user')
                    ->setParameter('user', $user)
                    ->getQuery()
                    ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                    ->getOneOrNullResult();
                if ($wallet === null) {
                    $wallet = (new Wallet())->setUser($user);
                    $this->em->persist($wallet);
                }

                $wallet->credit($amountCents);
                $transaction = (new WalletTransaction())
                    ->setWallet($wallet)
                    ->setType(TransactionType::CREDIT)
                    ->setAmountCents($amountCents)
                    ->setBalanceAfterCents($wallet->getBalanceCents())
                    ->setDescription(sprintf('Depósito manual (admin) — %s', $note));
                $this->em->persist($transaction);
                $payment->approve();
            }

            $this->em->persist($payment);
        });

        $this->addFlash('success', sprintf(
            'Pagamento manual criado para %s — R$ %.2f (%s).',
            $user->getName(),
            $amount,
            $autoApprove ? 'já aprovado e creditado' : 'aguardando aprovação'
        ));

        return $this->redirectToRoute('admin_payments_dashboard_index');
    }

    // -----------------------------------------------------------------------
    // Estorno: debita saldo do usuário
    // -----------------------------------------------------------------------
    #[Route('/refund-wallet', name: 'refund_wallet', methods: ['POST'])]
    public function refundWallet(Request $request): Response
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('refund_wallet', $token)) {
            $this->addFlash('danger', 'Token inválido. Recarregue a página.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $userIdValue = $request->request->get('user_id');
        $userId = is_string($userIdValue) && ctype_digit($userIdValue) ? (int) $userIdValue : 0;
        $amountCents = $this->parseAmountCents($request->request->get('amount'));
        $reason = $request->request->get('reason', 'Estorno solicitado via suporte');

        if ($amountCents === null || $amountCents <= 0 || $userId <= 0 || !is_string($reason)) {
            $this->addFlash('danger', 'Preencha usuário e valor corretamente.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }
        $reason = mb_substr($reason, 0, 170);
        $amount = $amountCents / 100;

        $user = $this->em->find(User::class, $userId);
        if (!$user) {
            $this->addFlash('danger', 'Usuário não encontrado.');
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $result = $this->em->wrapInTransaction(function () use ($user, $amountCents, $reason): array {
            $wallet = $this->em->createQueryBuilder()
                ->select('w')
                ->from(Wallet::class, 'w')
                ->where('w.user = :user')
                ->setParameter('user', $user)
                ->getQuery()
                ->setLockMode(LockMode::PESSIMISTIC_WRITE)
                ->getOneOrNullResult();

            if ($wallet === null) {
                return ['status' => 'no_wallet'];
            }
            if ($wallet->getBalanceCents() < $amountCents) {
                return ['status' => 'insufficient', 'balance' => $wallet->getBalanceCents()];
            }

            $wallet->debit($amountCents);
            $transaction = (new WalletTransaction())
                ->setWallet($wallet)
                ->setType(TransactionType::REFUND)
                ->setAmountCents($amountCents)
                ->setBalanceAfterCents($wallet->getBalanceCents())
                ->setDescription(sprintf('Estorno admin — %s', mb_substr($reason, 0, 170)));
            $this->em->persist($transaction);

            return ['status' => 'refunded', 'balance' => $wallet->getBalanceCents()];
        });

        if ($result['status'] !== 'refunded') {
            if ($result['status'] === 'no_wallet') {
                $this->addFlash('danger', sprintf('Usuário %s não possui carteira.', $user->getEmail()));
            } else {
                $this->addFlash('warning', sprintf(
                    'Saldo insuficiente: R$ %.2f disponível, tentou estornar R$ %.2f.',
                    $result['balance'] / 100,
                    $amount
                ));
            }
            return $this->redirectToRoute('admin_payments_dashboard_index');
        }

        $this->addFlash('success', sprintf(
            'Estorno de R$ %.2f aplicado na conta de %s. Saldo restante: R$ %.2f.',
            $amount,
            $user->getName(),
            $result['balance'] / 100
        ));

        return $this->redirectToRoute('admin_payments_dashboard_index');
    }

    private function parseAmountCents(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d+(?:[.,]\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $value), 2), 2, '0');
        $whole = ltrim($whole, '0') ?: '0';
        $maxWhole = (string) intdiv(PHP_INT_MAX, 100);
        if (
            strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)
        ) {
            return null;
        }

        $wholeCents = (int) $whole * 100;
        $fractionCents = (int) str_pad($fraction, 2, '0');

        return $wholeCents > PHP_INT_MAX - $fractionCents
            ? null
            : $wholeCents + $fractionCents;
    }
}
