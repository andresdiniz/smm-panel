<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Entity\Payment;
use App\Entity\ProviderCredential;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/admin', name: 'admin_index')]
    public function index(): Response
    {
        $since30   = new \DateTimeImmutable('-30 days');
        $sinceDay  = new \DateTimeImmutable('today');

        // ── Usuários
        $usersCount   = (int) $this->em->createQueryBuilder()->select('COUNT(u)')->from(User::class, 'u')->getQuery()->getSingleScalarResult();
        $newUsersToday = (int) $this->em->createQueryBuilder()
            ->select('COUNT(u)')->from(User::class, 'u')
            ->where('u.createdAt >= :since')->setParameter('since', $sinceDay)
            ->getQuery()->getSingleScalarResult();

        // ── Pedidos
        $ordersTotal = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o)')->from(Order::class, 'o')
            ->where('o.createdAt >= :since')->setParameter('since', $since30)
            ->getQuery()->getSingleScalarResult();
        $ordersToday = (int) $this->em->createQueryBuilder()
            ->select('COUNT(o)')->from(Order::class, 'o')
            ->where('o.createdAt >= :since')->setParameter('since', $sinceDay)
            ->getQuery()->getSingleScalarResult();

        // ── Financeiro (pagamentos aprovados = entradas)
        $revenueCents = (int) ($this->em->createQueryBuilder()
            ->select('SUM(p.amountCents)')->from(Payment::class, 'p')
            ->where('p.status = :st')->andWhere('p.createdAt >= :since')
            ->setParameter('st', Payment::STATUS_APPROVED)->setParameter('since', $since30)
            ->getQuery()->getSingleScalarResult() ?? 0);

        $revenueToday = (int) ($this->em->createQueryBuilder()
            ->select('SUM(p.amountCents)')->from(Payment::class, 'p')
            ->where('p.status = :st')->andWhere('p.createdAt >= :since')
            ->setParameter('st', Payment::STATUS_APPROVED)->setParameter('since', $sinceDay)
            ->getQuery()->getSingleScalarResult() ?? 0);

        // Saídas = soma de amountCents dos pedidos (custo dos providers)
        $expensesCents = (int) ($this->em->createQueryBuilder()
            ->select('SUM(o.amountCents)')->from(Order::class, 'o')
            ->where('o.createdAt >= :since')->setParameter('since', $since30)
            ->getQuery()->getSingleScalarResult() ?? 0);

        // Taxas = soma de feeCents dos pagamentos
        $feesCents = (int) ($this->em->createQueryBuilder()
            ->select('SUM(p.feeCents)')->from(Payment::class, 'p')
            ->where('p.createdAt >= :since')->setParameter('since', $since30)
            ->getQuery()->getSingleScalarResult() ?? 0);

        $netProfitCents = $revenueCents - $expensesCents - $feesCents;

        // ── Pedidos recentes
        $recentOrders = $this->em->createQueryBuilder()
            ->select('o')->from(Order::class, 'o')
            ->join('o.service', 's')->join('o.user', 'u')
            ->orderBy('o.createdAt', 'DESC')->setMaxResults(8)
            ->getQuery()->getResult();

        // ── Usuários recentes
        $recentUsers = $this->em->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->leftJoin('u.wallet', 'w')
            ->orderBy('u.createdAt', 'DESC')->setMaxResults(8)
            ->getQuery()->getResult();

        // ── Status dos providers/gateways
        $credentials = $this->em->createQueryBuilder()
            ->select('c')->from(ProviderCredential::class, 'c')
            ->orderBy('c.type', 'ASC')->addOrderBy('c.slug', 'ASC')
            ->getQuery()->getResult();

        return $this->render('admin/dashboard.html.twig', [
            'usersCount'     => $usersCount,
            'newUsersToday'  => $newUsersToday,
            'ordersTotal'    => $ordersTotal,
            'ordersToday'    => $ordersToday,
            'revenueCents'   => $revenueCents,
            'revenueToday'   => $revenueToday,
            'expensesCents'  => $expensesCents,
            'feesCents'      => $feesCents,
            'netProfitCents' => $netProfitCents,
            'recentOrders'   => $recentOrders,
            'recentUsers'    => $recentUsers,
            'credentials'    => $credentials,
        ]);
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
