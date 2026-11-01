<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Service;
use App\Service\OrderSyncService;
use App\Service\ServicePriceSyncService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin/api', name: 'admin_api_')]
class AdminApiSyncController extends AbstractController
{
    public function __construct(
        private readonly OrderSyncService       $orderSync,
        private readonly ServicePriceSyncService $serviceSync,
    ) {}

    #[Route('/orders/{id}/sync', name: 'order_sync', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function syncOrder(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_api_sync', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Token CSRF inválido.'], 403);
        }

        try {
            $result = $this->orderSync->syncOne($id);

            return $this->json([
                'ok' => $result['errors'] === 0,
                'message' => $result['errors'] > 0
                    ? 'Erro ao sincronizar o pedido com o provider.'
                    : 'Pedido sincronizado com o provider.',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Route('/services/{id}/sync', name: 'service_sync', requirements: ['id' => '\\d+'], methods: ['POST'])]
    public function syncService(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('admin_api_sync', $request->headers->get('X-CSRF-Token', ''))) {
            return $this->json(['error' => 'Token CSRF inválido.'], 403);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $markupPercent = isset($data['markup_percent']) ? (float) $data['markup_percent'] : 0.0;

        try {
            $result = $this->serviceSync->syncOne($id, $markupPercent);

            return $this->json([
                'ok' => true,
                'message' => 'Preço do serviço atualizado com o provider.',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }
}
