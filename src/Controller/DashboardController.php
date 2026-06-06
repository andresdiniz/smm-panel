<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Repository\ServiceRepository;
use App\Repository\WalletRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/dashboard', name: 'app_dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository   $orderRepository,
        private readonly ServiceRepository $serviceRepository,
        private readonly WalletRepository  $walletRepository,
    ) {}

    public function __invoke(): Response
    {
        $user   = $this->getUser();
        $wallet = $this->walletRepository->findOneByUser($user);

        $today  = new \DateTimeImmutable('today');
        $month  = new \DateTimeImmutable('first day of this month');

        $stats = [
            'ordersToday'    => $this->orderRepository->countByUserSince($user, $today),
            'ordersActive'   => $this->orderRepository->countActiveByUser($user),
            'spentThisMonth' => $this->orderRepository->sumSpentByUserSince($user, $month),
        ];

        $recentOrders    = $this->orderRepository->findRecentByUser($user, 10);
        $servicesGrouped = $this->serviceRepository->findAllGroupedByCategory();

        return $this->render('dashboard/index.html.twig', [
            'wallet'          => $wallet,
            'stats'           => $stats,
            'recentOrders'    => $recentOrders,
            'servicesGrouped' => $servicesGrouped,
        ]);
    }
}
