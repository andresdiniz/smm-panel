<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\CrmContact;
use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\Service;
use App\Entity\ServiceCategory;
use App\Entity\User;
use App\Entity\WalletTransaction;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractDashboardController
{
    #[Route('/admin', name: 'admin_dashboard')]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig');
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('SMM Panel — Admin')
            ->setFaviconPath('favicon.ico');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');

        yield MenuItem::section('Pedidos');
        yield MenuItem::linkToCrud('Pedidos', 'fa fa-shopping-cart', Order::class);
        yield MenuItem::linkToCrud('Serviços', 'fa fa-list', Service::class);
        yield MenuItem::linkToCrud('Categorias', 'fa fa-tags', ServiceCategory::class);

        yield MenuItem::section('Financeiro');
        yield MenuItem::linkToCrud('Pagamentos', 'fa fa-credit-card', Payment::class);
        yield MenuItem::linkToCrud('Transações', 'fa fa-exchange', WalletTransaction::class);

        yield MenuItem::section('CRM');
        yield MenuItem::linkToCrud('Usuários', 'fa fa-users', User::class);
        yield MenuItem::linkToCrud('Contatos CRM', 'fa fa-address-book', CrmContact::class);
    }
}
