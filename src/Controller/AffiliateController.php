<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AffiliateCommission;
use App\Repository\AffiliateCommissionRepository;
use App\Service\AffiliateService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AffiliateController extends AbstractController
{
    public function __construct(
        private readonly AffiliateService $affiliateService,
        private readonly AffiliateCommissionRepository $commissionRepo,
    ) {}

    /**
     * Captura o clique no link de afiliado e redireciona para o registro.
     * Ex: /ref/ab3f9x
     */
    #[Route('/ref/{code}', name: 'affiliate_click', methods: ['GET'])]
    public function click(string $code): Response
    {
        $this->affiliateService->trackClick($code);

        return $this->redirectToRoute('app_register');
    }

    /**
     * Dashboard do afiliado — página no painel do usuário.
     */
    #[Route('/painel/afiliado', name: 'affiliate_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function dashboard(): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Garante que o usuário tem código de afiliado
        $this->affiliateService->ensureCode($user);

        $commissions = $this->commissionRepo->findByAffiliate($user);

        $pending   = $this->commissionRepo->sumByStatus($user, AffiliateCommission::STATUS_PENDING);
        $paid      = $this->commissionRepo->sumByStatus($user, AffiliateCommission::STATUS_PAID);
        $cancelled = $this->commissionRepo->sumByStatus($user, AffiliateCommission::STATUS_CANCELLED);

        $rate = $user->getEffectiveCommissionRate($this->affiliateService->getDefaultRate());

        return $this->render('affiliate/dashboard.html.twig', [
            'user'        => $user,
            'commissions' => $commissions,
            'pending'     => $pending,
            'paid'        => $paid,
            'cancelled'   => $cancelled,
            'rate'        => $rate,
            'referredCount' => $user->getReferredUsers()->count(),
        ]);
    }
}
