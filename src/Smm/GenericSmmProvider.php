<?php

declare(strict_types=1);

namespace App\Smm;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Implementação genérica para APIs SMM padronizadas.
 *
 * Compatível com: JustAnotherPanel, SMMKings, Peakerr, SMMFollows,
 *                 SMMHeaven, GoodSMM, SubMall e qualquer painel
 *                 que siga o protocolo REST padrão do mercado.
 *
 * Protocolo:
 *   POST {base_url}
 *   Content-Type: application/x-www-form-urlencoded
 *   key=API_KEY&action=ACTION[&...params]
 *
 * Para adicionar um novo provider, basta registrar um novo serviço
 * em services.yaml com slug, base_url e api_key diferentes.
 */
final class GenericSmmProvider implements SmmProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $slug,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly LoggerInterface $logger,
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function addOrder(string $serviceId, string $targetUrl, int $quantity): string
    {
        $payload = [
            'action'   => 'add',
            'service'  => $serviceId,
            'link'     => $targetUrl,
            'quantity' => $quantity,
        ];

        $data = $this->post($payload);

        if (isset($data['error'])) {
            throw new \RuntimeException('[' . $this->slug . '] Erro ao criar pedido: ' . $data['error']);
        }

        return (string) ($data['order'] ?? throw new \RuntimeException('[' . $this->slug . '] Resposta sem order ID.'));
    }

    public function getOrderStatus(string $orderId): array
    {
        $data = $this->post([
            'action' => 'status',
            'order'  => $orderId,
        ]);

        if (isset($data['error'])) {
            throw new \RuntimeException('[' . $this->slug . '] Erro ao consultar status: ' . $data['error']);
        }

        return [
            'status'      => strtolower($data['status'] ?? 'pending'),
            'start_count' => (int) ($data['start_count'] ?? 0),
            'remains'     => (int) ($data['remains'] ?? 0),
            'charge'      => (float) ($data['charge'] ?? 0),
        ];
    }

    public function getBalance(): float
    {
        $data = $this->post(['action' => 'balance']);
        return (float) ($data['balance'] ?? 0);
    }

    public function getServices(): array
    {
        return $this->post(['action' => 'services']);
    }

    /** @param array<string, mixed> $params */
    private function post(array $params): array
    {
        $body = array_merge(['key' => $this->apiKey], $params);

        // Mascara a chave nos logs — nunca logar credenciais em texto puro
        $safeBody = $body;
        $safeBody['key'] = '***';

        $this->logger->debug('[SMM] → REQUEST', [
            'provider' => $this->slug,
            'url'      => $this->baseUrl,
            'body'     => $safeBody,
        ]);

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response   = $this->http->request('POST', $this->baseUrl, ['body' => $body]);
            $statusCode = $response->getStatusCode();
            $rawBody    = $response->getContent(false);   // false = não lança em 4xx/5xx
            $elapsed    = (int) round(microtime(true) * 1000) - $startMs;

            // Tenta decodificar JSON para o log, mas sem quebrar se vier HTML/texto
            $decoded = null;
            try {
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // mantém $decoded = null
            }

            $this->logger->debug('[SMM] ← RESPONSE', [
                'provider'    => $this->slug,
                'action'      => $params['action'] ?? '?',
                'http_status' => $statusCode,
                'elapsed_ms'  => $elapsed,
                'body_raw'    => $decoded ?? $rawBody,
            ]);

            if ($statusCode >= 400) {
                $this->logger->error('[SMM] Resposta HTTP de erro', [
                    'provider'    => $this->slug,
                    'action'      => $params['action'] ?? '?',
                    'http_status' => $statusCode,
                    'body'        => $decoded ?? $rawBody,
                ]);
            }

            // Se 'error' veio no JSON, loga como warning antes de devolver
            if (is_array($decoded) && isset($decoded['error'])) {
                $this->logger->warning('[SMM] API retornou erro de negócio', [
                    'provider' => $this->slug,
                    'action'   => $params['action'] ?? '?',
                    'error'    => $decoded['error'],
                    'request'  => $safeBody,
                ]);
            }

            return $decoded ?? [];

        } catch (\Throwable $e) {
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->critical('[SMM] Exceção na chamada HTTP', [
                'provider'   => $this->slug,
                'action'     => $params['action'] ?? '?',
                'elapsed_ms' => $elapsed,
                'exception'  => $e->getMessage(),
                'request'    => $safeBody,
            ]);

            throw $e;
        }
    }
}
