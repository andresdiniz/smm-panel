<?php

namespace App\Controller\Api\V2;

use App\Repository\OrderRepository;
use App\Repository\ServiceRepository;
use App\Service\Smm\OrderManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class SmmApiController extends AbstractController
{
    public function __construct(
        private readonly ServiceRepository $services,
        private readonly OrderRepository $orders,
        private readonly OrderManager $orderManager,
    ) {}

    #[Route('/api/v2', name: 'app_api_v2', methods: ['POST'])]
    public function handle(Request $request): JsonResponse
    {
        $action = (string) $request->request->get('action', '');

        return match ($action) {
            'services' => $this->services(),
            'add' => $this->add($request),
            'status' => $this->status($request),
            'balance' => $this->balance(),
            default => new JsonResponse(['error' => 'Invalid action.'], 400),
        };
    }

    private function services(): JsonResponse
    {
        $data = [];

        foreach ($this->services->findBy(['active' => true]) as $service) {
            $data[] = [
                'service' => $service->getId(),
                'name' => $service->getName(),
                'category' => $service->getCategory(),
                'rate' => $service->getPricePer1000(),
                'min' => $service->getMinQuantity(),
                'max' => $service->getMaxQuantity(),
                'refill' => $service->hasRefill(),
            ];
        }

        return $this->json($data);
    }

    private function add(Request $request): JsonResponse
    {
        try {
            $service = $this->services->find((int) $request->request->get('service'));
            $user = $this->getUser();

            if (!$service || !$user instanceof \App\Entity\User) {
                return $this->json(['error' => 'Invalid service.'], 400);
            }

            $order = $this->orderManager->create(
                $user,
                $service,
                (string) $request->request->get('link'),
                (int) $request->request->get('quantity')
            );

            return $this->json(['order' => $order->getId()]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        }
    }

    private function status(Request $request): JsonResponse
    {
        $order = $this->orders->find((int) $request->request->get('order'));

        if (!$order) {
            return $this->json(['error' => 'Incorrect order ID.'], 404);
        }

        return $this->json([
            'charge' => $order->getCharge(),
            'start_count' => $order->getStartCount(),
            'status' => $order->getStatus(),
            'remains' => $order->getRemains(),
            'currency' => 'BRL',
        ]);
    }

    private function balance(): JsonResponse
    {
        $user = $this->getUser();

        return $this->json([
            'balance' => $user instanceof \App\Entity\User ? $user->getBalance() : '0.00',
            'currency' => 'BRL',
        ]);
    }
}
