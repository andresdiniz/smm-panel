<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CrmContactRepository;
use App\Repository\PaymentRepository;
use App\Repository\UserRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CrmController extends AbstractDashboardController
{
    public function __construct(
        private readonly PaymentRepository    $paymentRepository,
        private readonly CrmContactRepository $contactRepository,
        private readonly UserRepository       $userRepository,
    ) {}

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('SMM Panel <span class="text-muted fw-light">Admin</span>')
            ->setFaviconPath('favicon.ico')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToUrl('\u2190 Dashboard', 'fa fa-arrow-left', '/admin');
        yield MenuItem::section('CRM');
        yield MenuItem::linkToUrl('Vis\u00e3o geral', 'fa fa-chart-pie', '/admin/crm');
        yield MenuItem::linkToUrl('Contatos', 'fa fa-address-book',
            '/admin?crudControllerFqcn=App%5CController%5CAdmin%5CContactCrudController');
    }

    #[Route('/admin/crm', name: 'app_admin_crm_dashboard', methods: ['GET'])]
    public function dashboard(): Response
    {
        $month = new \DateTimeImmutable('first day of this month');

        $stats = [
            'revenueMonthCents'  => $this->paymentRepository->sumApprovedSince($month),
            'expensesMonthCents' => $this->paymentRepository->sumExpensesSince($month),
            'feesMonthCents'     => $this->paymentRepository->sumFeesSince($month),
            'totalContacts'      => $this->contactRepository->count([]),
            'activeContacts'     => count($this->contactRepository->findRecentlyActive(30, 999)),
        ];

        $crmUsers = $this->userRepository->findCrmUsers(100);

        // origens UTM distintas para filtro
        $utmSources = array_values(array_unique(array_filter(
            array_column($crmUsers, 'utmSource')
        )));

        return $this->render('admin/crm_dashboard.html.twig', [
            'stats'      => $stats,
            'crmUsers'   => $crmUsers,
            'utmSources' => $utmSources,
            'flash'      => null,
        ]);
    }

    #[Route('/admin/crm/send-marketing', name: 'app_admin_crm_send_marketing', methods: ['POST'])]
    public function sendMarketing(Request $request, MailerInterface $mailer): Response
    {
        $subject       = trim($request->request->get('subject', ''));
        $body          = trim($request->request->get('body', ''));
        $minSpent      = (int) ($request->request->get('min_spent', 0) * 100); // reais -> centavos
        $utmSource     = $request->request->get('utm_source') ?: null;
        $previewOnly   = $request->request->getBoolean('preview_only');

        $recipients = $this->userRepository->findEmailsForMarketing($minSpent, $utmSource);

        $sent = 0;
        $errors = [];

        if (!$previewOnly && $subject && $body) {
            foreach ($recipients as $r) {
                try {
                    $html = nl2br(htmlspecialchars($body));
                    $email = (new Email())
                        ->from('noreply@acheireviews.com.br')
                        ->to($r['email'])
                        ->subject($subject)
                        ->html(sprintf(
                            '<div style="font-family:sans-serif;max-width:600px;margin:0 auto">'
                            . '<p>Ol\u00e1, %s!</p><div>%s</div>'
                            . '<hr><p style="font-size:11px;color:#888">'
                            . 'Para cancelar o recebimento de e-mails, entre em contato conosco.</p></div>',
                            htmlspecialchars($r['name']),
                            $html
                        ));
                    $mailer->send($email);
                    $sent++;
                } catch (\Throwable $e) {
                    $errors[] = $r['email'] . ': ' . $e->getMessage();
                }
            }
        }

        // recarrega dados do dashboard
        $month = new \DateTimeImmutable('first day of this month');
        $stats = [
            'revenueMonthCents'  => $this->paymentRepository->sumApprovedSince($month),
            'expensesMonthCents' => $this->paymentRepository->sumExpensesSince($month),
            'feesMonthCents'     => $this->paymentRepository->sumFeesSince($month),
            'totalContacts'      => $this->contactRepository->count([]),
            'activeContacts'     => count($this->contactRepository->findRecentlyActive(30, 999)),
        ];
        $crmUsers   = $this->userRepository->findCrmUsers(100);
        $utmSources = array_values(array_unique(array_filter(array_column($crmUsers, 'utmSource'))));

        return $this->render('admin/crm_dashboard.html.twig', [
            'stats'       => $stats,
            'crmUsers'    => $crmUsers,
            'utmSources'  => $utmSources,
            'flash' => [
                'type'       => $previewOnly ? 'info' : ($errors ? 'warning' : 'success'),
                'recipients' => $recipients,
                'sent'       => $sent,
                'errors'     => $errors,
                'preview'    => $previewOnly,
                'subject'    => $subject,
                'body'       => $body,
            ],
        ]);
    }
}
