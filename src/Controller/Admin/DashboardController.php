<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\OrderRepository;
use App\Repository\ProviderCredentialRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use App\Repository\WalletTransactionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_')]
#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository              $orderRepo,
        private readonly UserRepository               $userRepo,
        private readonly ServiceRepository            $serviceRepo,
        private readonly WalletTransactionRepository  $txRepo,
        private readonly ProviderCredentialRepository $credRepo,
    ) {}

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'usersCount'     => $this->userRepo->count([]),
            'newUsersToday'  => $this->userRepo->countCreatedToday(),
            'ordersTotal'    => $this->orderRepo->countThisMonth(),
            'ordersToday'    => $this->orderRepo->countToday(),
            'revenueCents'   => $this->txRepo->sumCreditThisMonth(),
            'revenueToday'   => $this->txRepo->sumCreditToday(),
            'expensesCents'  => $this->txRepo->sumDebitThisMonth(),
            'feesCents'      => $this->txRepo->sumFeesThisMonth(),
            'netProfitCents' => $this->txRepo->sumCreditThisMonth()
                                - $this->txRepo->sumDebitThisMonth()
                                - $this->txRepo->sumFeesThisMonth(),
            'statusCounts'   => $this->orderRepo->countByStatus(),
            'credentials'    => $this->credRepo->findAll(),
            'recentOrders'   => $this->orderRepo->findRecent(10),
            'recentUsers'    => $this->userRepo->findRecent(10),
        ]);
    }
}
