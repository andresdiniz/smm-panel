<?php

namespace App\Controller;

use App\Entity\Order;
use App\Repository\ServiceRepository;
use App\Service\Smm\OrderManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OrderController extends AbstractController
{
    #[Route('/orders', name: 'app_orders')]
    public function index(Request $request, ServiceRepository $services): Response
    {
        return $this->render('order/new.html.twig', [
            'services' => $services->findBy(['active' => true], ['category' => 'ASC', 'name' => 'ASC']),
        ]);
    }

    #[Route('/orders/create', name: 'app_order_create', methods: ['POST'])]
    public function create(Request $request, ServiceRepository $services, OrderManager $manager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        try {
            $service = $services->find((int) $request->request->get('service_id'));
            $order = $manager->create(
                $this->getUser(),
                $service,
                (string) $request->request->get('link'),
                (int) $request->request->get('quantity')
            );

            $this->addFlash('success', 'Order #' . $order->getId() . ' created successfully.');
        } catch (\Throwable $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('app_orders');
    }
}
