<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\CrmContactRepository;
use App\Repository\PaymentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/crm', name: 'app_admin_crm_')]
class CrmController extends AbstractController
{
    public function __construct(
        private readonly PaymentRepository    $paymentRepository,
        private readonly CrmContactRepository $contactRepository,
    ) {}

    #[Route('', name: 'dashboard')]
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
