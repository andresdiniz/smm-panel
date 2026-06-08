<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Smm\SmmProviderRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class ProviderBalanceController extends AbstractController
{
    public function __construct(
        private readonly SmmProviderRegistry $registry,
    ) {}

    #[Route('/admin/provider-balance', name: 'admin_provider_balance')]
    public function index(): Response
    {
        $balances = [];

        foreach ($this->registry->slugs() as $slug) {
            $provider = $this->registry->get($slug);
            try {
                $balances[] = [
                    'slug'    => $slug,
                    'balance' => $provider->getBalance(),
                    'error'   => null,
                ];
            } catch (\Throwable $e) {
                $balances[] = [
                    'slug'    => $slug,
                    'balance' => null,
                    'error'   => $e->getMessage(),
                ];
            }
        }

        return $this->render('admin/provider_balance.html.twig', [
            'balances' => $balances,
        ]);
    }
}
