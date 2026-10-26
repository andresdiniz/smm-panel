<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(OrderRepository $orders): Response
    {
        return $this->render('dashboard/index.html.twig', [
            'orders' => $orders->findForUser($this->getUser()->getId(), 10),
        ]);
    }
}
