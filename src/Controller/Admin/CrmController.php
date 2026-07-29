<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AffiliateCommission;
use App\Repository\AffiliateCommissionRepository;
use App\Repository\CrmContactRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\AffiliateService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/crm', name: 'app_admin_crm_')]
#[IsGranted('ROLE_ADMIN')]
class CrmController extends AbstractController
{
    public function __construct(
        private readonly PaymentRepository             $paymentRepository,
        private readonly CrmContactRepository          $contactRepository,
        private readonly UserRepository                $userRepository,
        private readonly OrderRepository               $orderRepository,
        private readonly AffiliateCommissionRepository $commissionRepo,
        private readonly AffiliateService              $affiliateService,
        private readonly EntityManagerInterface        $em,
    ) {}

    // ─── Dashboard principal ─────────────────────────────────────────────
    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        return $this->renderCrm(null);
    }

    // ─── Perfil de usuário ───────────────────────────────────────────────
    #[Route('/user/{id}', name: 'user_profile', methods: ['GET'])]
    public function userProfile(int $id): Response
    {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $contact    = $this->contactRepository->findOneByUserId($id);
        $orders     = $this->orderRepository->findRecentByUser($user, 20);
        $totalSpent = array_sum(array_map(
            fn($o) => $o->getStatus() !== 'cancelled' ? $o->getAmountCents() : 0,
            $orders
        ));

        return $this->renderCrm([
            'user'       => $user,
            'contact'    => $contact,
            'orders'     => $orders,
            'totalSpent' => $totalSpent,
        ]);
    }

    // ─── Pagar comissões pendentes de um afiliado ────────────────────────
    #[Route('/affiliate/pay/{id}', name: 'affiliate_pay', methods: ['POST'])]
    public function affiliatePay(int $id, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('aff_pay_' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('app_admin_crm_dashboard', ['#' => 'affiliates']);
        }

        $affiliate = $this->userRepository->find($id);
        if (!$affiliate) {
            throw $this->createNotFoundException();
        }

        $pending = $this->em->getRepository(AffiliateCommission::class)
            ->findBy(['affiliate' => $affiliate, 'status' => AffiliateCommission::STATUS_PENDING]);

        $totalPaid = 0.0;
        foreach ($pending as $commission) {
            $this->affiliateService->payCommission($commission);
            $totalPaid += (float) $commission->getAmount();
        }

        $this->addFlash('success', sprintf(
            '%d comissão(ões) pagas para %s — R$ %.2f creditados na carteira.',
            count($pending),
            $affiliate->getName(),
            $totalPaid
        ));

        return $this->redirectToRoute('app_admin_crm_dashboard', ['_fragment' => 'affiliates']);
    }

    // ─── Envio de campanha de e-mail ─────────────────────────────────────
    #[Route('/send-marketing', name: 'send_marketing', methods: ['POST'])]
    public function sendMarketing(Request $request, MailerInterface $mailer): Response
    {
        $subject     = trim($request->request->get('subject', ''));
        $body        = trim($request->request->get('body', ''));
        $minSpent    = (int) (((float) $request->request->get('min_spent', 0)) * 100);
        $maxSpent    = $request->request->get('max_spent') !== ''
            ? (int) (((float) $request->request->get('max_spent', 0)) * 100)
            : null;
        $utmSource   = $request->request->get('utm_source') ?: null;
        $segment     = $request->request->get('segment') ?: null;
        $previewOnly = $request->request->getBoolean('preview_only');

        $allUsers   = $this->userRepository->findCrmUsers(500);
        $recipients = [];

        foreach ($allUsers as $row) {
            $spent = $row['totalSpentCents'];
            $seg   = $spent >= 50000 ? 'whale' : ($spent > 0 ? 'active' : 'new');

            if ($spent < $minSpent) continue;
            if ($maxSpent !== null && $spent > $maxSpent) continue;
            if ($utmSource && $row['utmSource'] !== $utmSource) continue;
            if ($segment   && $seg !== $segment) continue;
            if (!$row['user']->isActive()) continue;

            $recipients[] = ['name' => $row['user']->getName(), 'email' => $row['user']->getEmail()];
        }

        $sent = 0;
        $errors = [];
        if (!$previewOnly && $subject && $body) {
            foreach ($recipients as $r) {
                try {
                    $email = (new Email())
                        ->from('noreply@acheireviews.com.br')
                        ->to($r['email'])
                        ->subject($subject)
                        ->html($this->renderEmailHtml($r['name'], $subject, $body));
                    $mailer->send($email);
                    $sent++;
                } catch (\Throwable $e) {
                    $errors[] = $r['email'] . ': ' . $e->getMessage();
                }
            }
        }

        $this->addFlash('crm_campaign', [
            'preview'    => $previewOnly,
            'recipients' => $recipients,
            'sent'       => $sent,
            'errors'     => $errors,
            'subject'    => $subject,
            'body'       => $body,
        ]);

        return $this->redirectToRoute('app_admin_crm_dashboard');
    }

    // ─── Helper: monta todos os dados e renderiza ────────────────────────
    private function renderCrm(?array $profileUser): Response
    {
        $now   = new \DateTimeImmutable();
        $month = new \DateTimeImmutable('first day of this month midnight');

        $crmUsers   = $this->userRepository->findCrmUsers(500);
        $totalUsers = count($crmUsers);

        $whales  = array_filter($crmUsers, fn($r) => $r['totalSpentCents'] >= 50000);
        $actives = array_filter($crmUsers, fn($r) => $r['totalSpentCents'] > 0 && $r['totalSpentCents'] < 50000);
        $news    = array_filter($crmUsers, fn($r) => $r['totalSpentCents'] === 0);

        $totalRevenueCents = array_sum(array_column($crmUsers, 'totalSpentCents'));
        $ltvAvg = $totalUsers > 0 ? (int) ($totalRevenueCents / $totalUsers) : 0;

        $monthlyRevenue = [];
        for ($i = 5; $i >= 0; $i--) {
            $mStart = new \DateTimeImmutable("first day of -{$i} months midnight");
            $mEnd   = $i === 0
                ? $now
                : new \DateTimeImmutable('first day of -' . ($i - 1) . ' months midnight');
            $monthlyRevenue[] = [
                'label'  => $mStart->format('M/y'),
                'amount' => $this->paymentRepository->sumApprovedBetween($mStart, $mEnd),
            ];
        }

        $utmMap = [];
        foreach ($crmUsers as $r) {
            $src = $r['utmSource'] ?? 'direto';
            if (!isset($utmMap[$src])) {
                $utmMap[$src] = ['count' => 0, 'revenue' => 0];
            }
            $utmMap[$src]['count']++;
            $utmMap[$src]['revenue'] += $r['totalSpentCents'];
        }
        arsort($utmMap);

        $newThisMonth = count(array_filter(
            $crmUsers,
            fn($r) => $r['user']->getCreatedAt() >= $month
        ));

        $stats = [
            'totalUsers'        => $totalUsers,
            'whales'            => count($whales),
            'actives'           => count($actives),
            'news'              => count($news),
            'ltvAvgCents'       => $ltvAvg,
            'totalRevenueCents' => $totalRevenueCents,
            'revenueMonthCents' => $this->paymentRepository->sumApprovedSince($month),
            'newThisMonth'      => $newThisMonth,
            'activeContacts'    => count($this->contactRepository->findRecentlyActive(30, 999)),
        ];

        $globalStats   = $this->commissionRepo->globalStats();
        $affiliateRows = $this->buildAffiliateRows();

        return $this->render('admin/crm_dashboard.html.twig', [
            'stats'          => $stats,
            'crmUsers'       => $crmUsers,
            'utmSources'     => array_keys($utmMap),
            'utmMap'         => $utmMap,
            'monthlyRevenue' => $monthlyRevenue,
            'profileUser'    => $profileUser,
            'affStats'       => $globalStats,
            'affiliateRows'  => $affiliateRows,
            'defaultRate'    => $this->affiliateService->getDefaultRate(),
        ]);
    }

    private function buildAffiliateRows(): array
    {
        $commissions = $this->em->getRepository(AffiliateCommission::class)
            ->createQueryBuilder('c')
            ->select('c', 'aff', 'cust')
            ->join('c.affiliate', 'aff')
            ->join('c.customer', 'cust')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();

        $rows = [];
        foreach ($commissions as $c) {
            $affId = $c->getAffiliate()->getId();
            if (!isset($rows[$affId])) {
                $rows[$affId] = [
                    'user'          => $c->getAffiliate(),
                    'totalEarned'   => 0.0,
                    'pending'       => 0.0,
                    'paid'          => 0.0,
                    'commissions'   => 0,
                    'referredCount' => $c->getAffiliate()->getReferredUsers()->count(),
                    'rate'          => $c->getAffiliate()->getEffectiveCommissionRate(
                        $this->affiliateService->getDefaultRate()
                    ),
                ];
            }
            $amount = (float) $c->getAmount();
            $rows[$affId]['totalEarned'] += $amount;
            $rows[$affId]['commissions']++;
            if ($c->getStatus() === AffiliateCommission::STATUS_PENDING) {
                $rows[$affId]['pending'] += $amount;
            } elseif ($c->getStatus() === AffiliateCommission::STATUS_PAID) {
                $rows[$affId]['paid'] += $amount;
            }
        }

        uasort($rows, fn($a, $b) => $b['pending'] <=> $a['pending']);

        return array_values($rows);
    }

    private function renderEmailHtml(string $name, string $subject, string $body): string
    {
        $safeBody = nl2br(htmlspecialchars($body));
        $safeName = htmlspecialchars($name);

        return <<<HTML
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden">
          <div style="background:#01696f;padding:24px 32px">
            <h1 style="color:#fff;margin:0;font-size:20px">PulseSMM</h1>
          </div>
          <div style="padding:32px">
            <p style="font-size:16px;color:#333">Ol&#225;, {$safeName}!</p>
            <div style="font-size:15px;color:#444;line-height:1.7">{$safeBody}</div>
          </div>
          <div style="background:#f5f5f5;padding:16px 32px;font-size:11px;color:#999;text-align:center">
            PulseSMM &mdash; Para cancelar o recebimento, entre em contato conosco.
          </div>
        </div>
        HTML;
    }
}
