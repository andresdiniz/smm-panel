<?php

declare(strict_types=1);

namespace App\Controller;

use App\Billing\GatewayRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoint público que recebe webhooks de todos os gateways.
 *
 * URL padrão: /webhook/{gateway}
 *   asaas       → /webhook/asaas
 *   mercadopago → /webhook/mercadopago
 *   pagbank     → /webhook/pagbank
 */
#[Route('/webhook', name: 'app_webhook_')]
class WebhookController extends AbstractController
{
    public function __construct(
        private readonly GatewayRegistry $registry,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/{gateway}', name: 'receive', methods: ['POST'])]
    public function receive(string $gateway, Request $request): JsonResponse
    {
        if (!$this->registry->has($gateway)) {
            $this->logger->warning('Webhook recebido para gateway desconhecido.', ['gateway' => $gateway]);
            return $this->json(['error' => 'Gateway desconhecido.'], Response::HTTP_NOT_FOUND);
        }

        try {
            $payment = $this->registry->get($gateway)->processWebhook($request);

            $this->logger->info('Webhook processado.', [
                'gateway'    => $gateway,
                'payment_id' => $payment->getId(),
                'status'     => $payment->getStatus(),
            ]);

            return $this->json(['ok' => true, 'status' => $payment->getStatus()]);
        } catch (\InvalidArgumentException $e) {
            $this->logger->error('Webhook inválido.', ['gateway' => $gateway, 'error' => $e->getMessage()]);
            return $this->json(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\Throwable $e) {
            $this->logger->critical('Erro inesperado no webhook.', ['gateway' => $gateway, 'error' => $e->getMessage()]);
            return $this->json(['error' => 'Erro interno.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
