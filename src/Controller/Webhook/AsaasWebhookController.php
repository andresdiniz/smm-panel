<?php

namespace App\Controller\Webhook;

use App\Service\Asaas\AsaasWebhookHandler;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class AsaasWebhookController extends AbstractController
{
    public function __construct(
        private readonly AsaasWebhookHandler $handler,
        private readonly LoggerInterface $logger,
        private readonly string $webhookToken,
    ) {}

    #[Route('/webhook/asaas', name: 'app_webhook_asaas', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->headers->get('asaas-access-token', '');

        if ($this->webhookToken === '' || !hash_equals($this->webhookToken, $token)) {
            $this->logger->warning('Rejected Asaas webhook: invalid token.');

            return new JsonResponse(['error' => 'unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        try {
            $this->handler->handle($payload);
        } catch (\Throwable $e) {
            $this->logger->error('Asaas webhook processing failed.', ['error' => $e->getMessage()]);
        }

        return new JsonResponse(['received' => true]);
    }
}
