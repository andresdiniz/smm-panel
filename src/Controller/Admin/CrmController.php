<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\ContactRepository;
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
        private readonly PaymentRepository $paymentRepository,
        private readonly ContactRepository $contactRepository,
    ) {}

    #[Route('', name: 'dashboard')]
    public function dashboard(): Response
    {
        $month = new \DateTimeImmutable('first day of this month');

        $stats = [
            'revenueMonthCents'  => $this->paymentRepository->sumApprovedSince($month),
            'expensesMonthCents' => $this->paymentRepository->sumExpensesSince($month),
            'feesMonthCents'     => $this->paymentRepository->sumFeesSince($month),
            'newLeads'           => $this->contactRepository->countByStatus('new'),
            'inContact'          => $this->contactRepository->countByStatus('in_contact'),
            'converted'          => $this->contactRepository->countByStatus('converted'),
        ];

        $recentContacts = $this->contactRepository->findRecent(20);

        return $this->render('admin/crm_dashboard.html.twig', [
            'stats'          => $stats,
            'recentContacts' => $recentContacts,
        ]);
    }
}
