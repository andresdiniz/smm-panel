<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\AffiliateCommission;
use App\Entity\MarketingEmailLog;
use App\Entity\User;
use App\Repository\AffiliateCommissionRepository;
use App\Repository\CrmContactRepository;
use App\Repository\MarketingEmailLogRepository;
use App\Repository\OrderRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use App\Service\AffiliateService;
use App\Service\MarketingEmailContent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\HttpFoundation\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\UriSigner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

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
        private readonly MarketingEmailLogRepository  $marketingEmailLogRepository,
        private readonly AffiliateService              $affiliateService,
        private readonly MarketingEmailContent        $marketingEmailContent,
        private readonly EntityManagerInterface        $em,
        private readonly LoggerInterface               $logger,
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

    #[Route('/campaign/{campaignId}', name: 'campaign_show', requirements: ['campaignId' => '[0-9a-f-]{36}'], methods: ['GET'])]
    public function campaignShow(string $campaignId): Response
    {
        $emails = $this->marketingEmailLogRepository->findByCampaignId($campaignId);
        if ($emails === []) {
            throw $this->createNotFoundException();
        }

        return $this->renderCrm(null, $emails, $campaignId);
    }

    #[Route('/user/{id}/send-email', name: 'send_user_email', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function sendUserEmail(
        int $id,
        Request $request,
        MailerInterface $mailer,
        UriSigner $uriSigner,
    ): Response {
        $user = $this->userRepository->find($id);
        if (!$user) {
            throw $this->createNotFoundException();
        }

        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('crm_user_email_'.$id, $token)) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('app_admin_crm_user_profile', ['id' => $id]);
        }

        if (!$user->isActive() || !$user->hasMarketingConsent()) {
            $this->addFlash('error', 'Este usuário não autorizou o recebimento de comunicações de marketing.');
            return $this->redirectToRoute('app_admin_crm_user_profile', ['id' => $id]);
        }

        $subject = trim($request->request->getString('subject'));
        $body = trim($request->request->getString('body'));
        if ($subject === '' || mb_strlen($subject) > 180 || preg_match('/[\r\n]/', $subject) || $body === '' || mb_strlen($body) > 10000) {
            $this->addFlash('error', 'Informe um assunto de até 180 caracteres e uma mensagem de até 10.000 caracteres.');
            return $this->redirectToRoute('app_admin_crm_user_profile', ['id' => $id]);
        }

        $unsubscribeUrl = $uriSigner->sign(
            $this->generateUrl(
                'app_marketing_unsubscribe',
                ['id' => $user->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            new \DateInterval('P90D'),
        );
        $emailLog = new MarketingEmailLog($user, Uuid::v4()->toRfc4122(), $subject);
        $this->em->persist($emailLog);
        $this->em->flush();

        try {
            $mailer->send((new Email())
                ->from('noreply@acheireviews.com.br')
                ->to($user->getEmail())
                ->subject($subject)
                ->html($this->renderEmailHtml($user->getName(), $body, $unsubscribeUrl, $emailLog)));
            $emailLog->markSent();
            $this->em->flush();
            $this->addFlash('success', 'E-mail enviado para o usuário.');
        } catch (\Throwable $e) {
            $emailLog->markFailed();
            $this->em->flush();
            $this->logger->error('Falha ao enviar e-mail de marketing individual.', [
                'user_id' => $user->getId(),
                'exception' => $e::class,
            ]);
            $this->addFlash('error', 'Não foi possível enviar o e-mail. Verifique o log do sistema.');
            throw $e;
        }

        return $this->redirectToRoute('app_admin_crm_user_profile', ['id' => $id]);
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
    public function sendMarketing(Request $request, MailerInterface $mailer, UriSigner $uriSigner): Response
    {
        $token = $request->request->get('_token');
        if (!is_string($token) || !$this->isCsrfTokenValid('crm_send_marketing', $token)) {
            $this->addFlash('error', 'Token inválido.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }

        $rawSubject = $request->request->get('subject', '');
        $rawBody = $request->request->get('body', '');
        if (!is_string($rawSubject) || !is_string($rawBody)) {
            $this->addFlash('error', 'Assunto ou mensagem inválidos.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }
        $subject = trim($rawSubject);
        $body = trim($rawBody);
        if ($subject === '' || mb_strlen($subject) > 180 || preg_match('/[\r\n]/', $subject) || $body === '' || mb_strlen($body) > 10000) {
            $this->addFlash('error', 'Informe um assunto de até 180 caracteres e uma mensagem de até 10.000 caracteres.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }

        $rawMinSpent = $request->request->get('min_spent', '0');
        $rawMaxSpent = $request->request->get('max_spent', '');
        if (!is_string($rawMinSpent) || !is_string($rawMaxSpent)
            || !is_numeric($rawMinSpent)
            || ($rawMaxSpent !== '' && !is_numeric($rawMaxSpent))
            || (float) $rawMinSpent < 0
            || ($rawMaxSpent !== '' && (float) $rawMaxSpent < 0)
        ) {
            $this->addFlash('error', 'Os valores mínimos e máximos da segmentação são inválidos.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }
        $minSpent = (int) round((float) $rawMinSpent * 100);
        $maxSpent = $rawMaxSpent !== '' ? (int) round((float) $rawMaxSpent * 100) : null;
        if ($maxSpent !== null && $maxSpent < $minSpent) {
            $this->addFlash('error', 'O gasto máximo não pode ser menor que o mínimo.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }

        $rawUtmSource = $request->request->get('utm_source');
        $rawSegment = $request->request->get('segment');
        if (($rawUtmSource !== null && !is_string($rawUtmSource)) || ($rawSegment !== null && !is_string($rawSegment))) {
            $this->addFlash('error', 'Filtro de campanha inválido.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }
        $utmSource = $rawUtmSource ?: null;
        $segment = $rawSegment ?: null;
        if ($segment !== null && !in_array($segment, ['new', 'active', 'whale'], true)) {
            $this->addFlash('error', 'Segmento de campanha inválido.');
            return $this->redirectToRoute('app_admin_crm_dashboard');
        }
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
            if (!$row['user']->isActive() || !$row['user']->hasMarketingConsent()) continue;

            $recipients[] = ['user' => $row['user'], 'name' => $row['user']->getName(), 'email' => $row['user']->getEmail()];
        }

        $sent = 0;
        $errorCount = 0;
        $campaignId = Uuid::v4()->toRfc4122();
        if (!$previewOnly) {
            $preparedEmails = [];
            foreach ($recipients as $r) {
                $emailLog = new MarketingEmailLog($r['user'], $campaignId, $subject);
                $this->em->persist($emailLog);
                $unsubscribeUrl = $uriSigner->sign(
                    $this->generateUrl(
                        'app_marketing_unsubscribe',
                        ['id' => $r['user']->getId()],
                        UrlGeneratorInterface::ABSOLUTE_URL,
                    ),
                    new \DateInterval('P90D'),
                );
                $preparedEmails[] = [
                    'recipient' => $r,
                    'log' => $emailLog,
                    'html' => $this->renderEmailHtml($r['name'], $body, $unsubscribeUrl, $emailLog),
                ];
            }

            if ($preparedEmails !== []) {
                $this->em->flush();
            }

            foreach ($preparedEmails as $prepared) {
                $r = $prepared['recipient'];
                $emailLog = $prepared['log'];
                try {
                    $email = (new Email())
                        ->from('noreply@acheireviews.com.br')
                        ->to($r['email'])
                        ->subject($subject)
                        ->html($prepared['html']);
                    $mailer->send($email);
                    $emailLog->markSent();
                    $sent++;
                } catch (\Throwable $e) {
                    $emailLog->markFailed();
                    ++$errorCount;
                    $this->logger->error('Falha no envio de campanha de marketing.', [
                        'user_id' => $r['user']->getId(),
                        'exception' => $e::class,
                    ]);
                }
            }
            if ($preparedEmails !== []) {
                $this->em->flush();
            }
        }

        $this->addFlash('crm_campaign', [
            'preview' => $previewOnly,
            'recipientCount' => count($recipients),
            'sent' => $sent,
            'errorCount' => $errorCount,
            'subject' => $subject,
            'campaignId' => $campaignId,
        ]);

        return $this->redirectToRoute('app_admin_crm_dashboard');
    }

    // ─── Helper: monta todos os dados e renderiza ────────────────────────
    private function renderCrm(?array $profileUser, ?array $campaignEmails = null, ?string $campaignId = null): Response
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
            'marketingOptIns'   => count(array_filter(
                $crmUsers,
                static fn (array $row): bool => $row['user']->isActive() && $row['user']->hasMarketingConsent(),
            )),
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
            'marketingCampaigns' => $this->marketingEmailLogRepository->findRecentCampaignSummaries(),
            'campaignEmails' => $campaignEmails,
            'campaignId' => $campaignId,
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

    private function renderEmailHtml(string $name, string $body, string $unsubscribeUrl, MarketingEmailLog $emailLog): string
    {
        $safeBody = $this->marketingEmailContent->sanitize($body);
        $links = [];
        $safeBody = $this->marketingEmailContent->rewriteLinks(
            $safeBody,
            fn (string $linkId): string => $this->generateUrl(
                'app_marketing_email_click',
                ['token' => $emailLog->getToken(), 'linkId' => $linkId],
                UrlGeneratorInterface::ABSOLUTE_URL,
            ),
            $links,
        );
        $emailLog->setLinks($links);

        $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeUnsubscribeUrl = htmlspecialchars($unsubscribeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $openUrl = htmlspecialchars($this->generateUrl(
            'app_marketing_email_open',
            ['token' => $emailLog->getToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

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
            PulseSMM &mdash; <a href="{$safeUnsubscribeUrl}">Cancelar recebimento de e-mails de marketing</a>.
            <p>Este e-mail pode registrar aberturas e cliques para medir o desempenho da comunicação. Aberturas são estimativas e podem variar conforme o aplicativo de e-mail.</p>
          </div>
          <img src="{$openUrl}" width="1" height="1" alt="" style="display:none">
        </div>
        HTML;
    }
}
