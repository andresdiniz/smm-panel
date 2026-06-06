<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProviderCredential;
use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin_index')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<strong>Pulse</strong>SMM')
            ->setFaviconPath('favicon.ico')
            ->setTranslationDomain('admin');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-gauge');
        yield MenuItem::section('Clientes');
        yield MenuItem::linkToCrud('Usuários', 'fa fa-users', User::class);
        yield MenuItem::section('Operações');
        yield MenuItem::linkToCrud('Pedidos', 'fa fa-shopping-cart', Order::class);
        yield MenuItem::linkToCrud('Pagamentos', 'fa fa-credit-card', Payment::class);
        yield MenuItem::section('Configurações');
        yield MenuItem::linkToCrud('Credenciais de APIs', 'fa fa-key', ProviderCredential::class);
        yield MenuItem::section();
        yield MenuItem::linkToUrl('Ver site', 'fa fa-globe', '/');
        yield MenuItem::linkToLogout('Sair', 'fa fa-right-from-bracket');
    }

    public function configureAssets(): Assets
    {
        return Assets::new()->addCssFile('css/admin.css');
    }
}
