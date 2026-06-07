<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\OrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly OrderRepository             $orderRepo,
        private readonly UserRepository              $userRepo,
        private readonly ServiceRepository           $serviceRepo,
        private readonly WalletTransactionRepository $txRepo,
    ) {}

    public function index(): Response
    {
        $stats = [
            'orders_today'    => $this->orderRepo->countToday(),
            'orders_pending'  => $this->orderRepo->countByStatus('pending'),
            'users_total'     => $this->userRepo->count([]),
            'revenue_month'   => $this->txRepo->sumCreditThisMonth(),
        ];

        return $this->render('admin/dashboard.html.twig', ['stats' => $stats]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('SMM Panel <span class="text-muted fw-light">Admin</span>')
            ->setFaviconPath('favicon.ico')
            ->renderContentMaximized();
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-gauge-high');

        yield MenuItem::section('PEDIDOS');
        yield MenuItem::linkToCrud('Pedidos',    'fa fa-cart-shopping', \App\Entity\Order::class);
        yield MenuItem::linkToCrud('Pagamentos', 'fa fa-credit-card',   \App\Entity\Payment::class);
        yield MenuItem::linkToCrud('Transações', 'fa fa-arrow-right-arrow-left', \App\Entity\WalletTransaction::class);

        yield MenuItem::section('CATÁLOGO');
        yield MenuItem::linkToCrud('Serviços',   'fa fa-list',      \App\Entity\Service::class);
        yield MenuItem::linkToCrud('Categorias', 'fa fa-tags',      \App\Entity\ServiceCategory::class);
        yield MenuItem::linkToRoute('Importar Serviços', 'fa fa-cloud-download', 'admin_service_import');

        yield MenuItem::section('USUÁRIOS & CRM');
        yield MenuItem::linkToCrud('Usuários', 'fa fa-users', \App\Entity\User::class);
        yield MenuItem::linkToRoute('CRM', 'fa fa-comments', 'admin_crm');

        yield MenuItem::section('INTEGRAÇÕES');
        yield MenuItem::linkToCrud('Provedores / APIs', 'fa fa-plug', \App\Entity\ProviderCredential::class);
    }
}
