<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CrmContactRepository;
use App\Repository\PaymentRepository;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class CrmController extends AbstractDashboardController
{
    public function __construct(
        private readonly PaymentRepository    $paymentRepository,
        private readonly CrmContactRepository $contactRepository,
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
        yield MenuItem::linkToUrl('← Dashboard', 'fa fa-arrow-left', '/admin');
        yield MenuItem::section('CRM');
        yield MenuItem::linkToUrl('Visão geral', 'fa fa-chart-pie', '/admin/crm');
        yield MenuItem::linkToUrl('Contatos', 'fa fa-address-book', '/admin?crudControllerFqcn=App%5CController%5CAdmin%5CContactCrudController');
    }

    #[Route('/admin/crm', name: 'app_admin_crm_dashboard')]
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

        $recentContacts = $this->contactRepository->findRecentlyActive(30, 20);

        return $this->render('admin/crm_dashboard.html.twig', [
            'stats'          => $stats,
            'recentContacts' => $recentContacts,
        ]);
    }
}
