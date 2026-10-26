<?php

namespace App\Controller;

use App\Service\Asaas\AsaasPixService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class BillingController extends AbstractController
{
    #[Route('/billing/add-funds', name: 'app_billing_add_funds')]
    public function addFunds(): Response
    {
        return $this->render('billing/add_funds.html.twig');
    }

    #[Route('/billing/pix', name: 'app_billing_pix', methods: ['POST'])]
    public function pix(Request $request, AsaasPixService $pix): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        try {
            $charge = $pix->createCharge($this->getUser(), number_format((float) $request->request->get('amount', 0), 2, '.', ''));

            return $this->render('billing/pix.html.twig', ['charge' => $charge]);
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());

            return $this->redirectToRoute('app_billing_add_funds');
        }
    }
}
