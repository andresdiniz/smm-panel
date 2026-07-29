<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\OrderRepository;
use App\Repository\ProviderCredentialRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly OrderRepository              $orderRepo,
        private readonly UserRepository               $userRepo,
        private readonly ServiceRepository            $serviceRepo,
        private readonly WalletTransactionRepository  $txRepo,
        private readonly ProviderCredentialRepository $credRepo,
    ) {}

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'usersCount'    => $this->userRepo->count([]),
            'newUsersToday' => $this->userRepo->countCreatedToday(),
            'ordersTotal'   => $this->orderRepo->countThisMonth(),
            'ordersToday'   => $this->orderRepo->countToday(),
            'revenueCents'   => $this->txRepo->sumCreditThisMonth(),
            'revenueToday'   => $this->txRepo->sumCreditToday(),
            'expensesCents'  => $this->txRepo->sumDebitThisMonth(),
            'feesCents'      => $this->txRepo->sumFeesThisMonth(),
            'netProfitCents' => $this->txRepo->sumCreditThisMonth()
                                - $this->txRepo->sumDebitThisMonth()
                                - $this->txRepo->sumFeesThisMonth(),
            'credentials'  => $this->credRepo->findAll(),
            'recentOrders' => $this->orderRepo->findRecent(10),
            'recentUsers'  => $this->userRepo->findRecent(10),
        ]);
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
        yield MenuItem::linkToCrud('Pedidos',         'fa fa-cart-shopping',           \App\Entity\Order::class);
        yield MenuItem::linkToCrud('Pagamentos',      'fa fa-credit-card',             \App\Entity\Payment::class);
        yield MenuItem::linkToUrl('Dashboard Pagamentos', 'fa fa-chart-bar', '/admin/payments-dashboard');
        yield MenuItem::linkToCrud('Transações',      'fa fa-arrow-right-arrow-left',  \App\Entity\WalletTransaction::class);
        yield MenuItem::linkToCrud('Logs de Pedidos', 'fa fa-list-alt',                \App\Entity\OrderLog::class);
        yield MenuItem::linkToRoute('Erros Provider (24h)', 'fa fa-triangle-exclamation', 'admin_order_logs_errors');

        yield MenuItem::section('CATÁLOGO');
        yield MenuItem::linkToCrud('Serviços',   'fa fa-list',          \App\Entity\Service::class);
        yield MenuItem::linkToCrud('Categorias', 'fa fa-tags',          \App\Entity\ServiceCategory::class);
        yield MenuItem::linkToUrl('Importar Serviços', 'fa fa-cloud-download', '/admin/imports/services');

        yield MenuItem::section('USUÁRIOS & CRM');
        yield MenuItem::linkToCrud('Usuários', 'fa fa-users',    \App\Entity\User::class);
        yield MenuItem::linkToUrl('CRM',      'fa fa-comments', '/admin/crm');

        yield MenuItem::section('BLOG');
        yield MenuItem::linkToCrud('Posts',      'fa fa-pen-to-square', \App\Entity\BlogPost::class);
        yield MenuItem::linkToCrud('Categorias', 'fa fa-folder-open',   \App\Entity\BlogCategory::class);
        yield MenuItem::linkToCrud('Tags',        'fa fa-hashtag',       \App\Entity\BlogTag::class);
        yield MenuItem::linkToUrl('Ver Blog', 'fa fa-arrow-up-right-from-square', '/blog', ['target' => '_blank']);

        yield MenuItem::section('SISTEMA');
        yield MenuItem::linkToCrud('Logs de Cron', 'fa fa-clock-rotate-left', \App\Entity\CronLog::class);
        yield MenuItem::linkToCrud('Provedores / APIs', 'fa fa-plug',       \App\Entity\ProviderCredential::class);
        yield MenuItem::linkToRoute('Saldo Providers',  'fa fa-dollar-sign', 'admin_provider_balance');
    }
}
