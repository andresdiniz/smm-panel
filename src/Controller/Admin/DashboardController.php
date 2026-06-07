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
        private readonly OrderRepository               $orderRepo,
        private readonly UserRepository                $userRepo,
        private readonly ServiceRepository             $serviceRepo,
        private readonly WalletTransactionRepository   $walletRepo,
    ) {}

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<span class="text-primary fw-bold">SMM Panel</span> <small class="text-muted">Admin</small>')
            ->setFaviconPath('favicon.ico')
            ->setTranslationDomain('admin')
            ->renderContentMaximized()
            ->disableDarkMode()
            ->setLocales(['pt_BR' => '🇧🇷 Português'])
            ->generateRelativeUrls()
            ->setCssFile('admin.css');
    }

    #[Route('/admin', name: 'admin')]
    public function index(): Response
    {
        $stats = [
            'orders'   => $this->orderRepo->count([]),
            'users'    => $this->userRepo->count([]),
            'services' => $this->serviceRepo->count([]),
            'revenue'  => $this->walletRepo->sumApprovedRevenue(),
        ];

        return $this->render('admin/dashboard.html.twig', ['stats' => $stats]);
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-gauge-high');
        yield MenuItem::section('Pedidos');
        yield MenuItem::linkToCrud('Pedidos', 'fa fa-shopping-cart', \App\Entity\Order::class);
        yield MenuItem::linkToCrud('Pagamentos', 'fa fa-credit-card', \App\Entity\Payment::class);
        yield MenuItem::linkToCrud('Transações', 'fa fa-wallet', \App\Entity\WalletTransaction::class);
        yield MenuItem::section('Catálogo');
        yield MenuItem::linkToCrud('Serviços', 'fa fa-layer-group', \App\Entity\Service::class);
        yield MenuItem::linkToCrud('Categorias', 'fa fa-tags', \App\Entity\ServiceCategory::class);
        yield MenuItem::linkToRoute('Importar Serviços', 'fa fa-cloud-download', 'admin_service_import');
        yield MenuItem::section('Usuários & CRM');
        yield MenuItem::linkToCrud('Usuários', 'fa fa-users', \App\Entity\User::class);
        yield MenuItem::linkToRoute('CRM', 'fa fa-comments', 'admin_crm');
        yield MenuItem::section('Integrações');
        yield MenuItem::linkToCrud('Provedores / APIs', 'fa fa-plug', \App\Entity\ProviderCredential::class);
    }
}
