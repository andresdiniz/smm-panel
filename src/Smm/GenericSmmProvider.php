<?php

declare(strict_types=1);

namespace App\Smm;

use App\Entity\ProviderCredential;
use App\Smm\Exception\ProviderBusinessException;
use App\Smm\Exception\ProviderTechnicalException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Provider genérico compatível com a API padrão SMM (SMMoficial, JustAnotherPanel, etc.).
 */
final class GenericSmmProvider implements SmmProviderInterface
{
    /** Códigos de erro que indicam falha de negócio (não retentáveis). */
    private const BUSINESS_ERRORS = [
        'neworder.error.link_duplicate',
        'neworder.error.invalid_link',
        'neworder.error.link_not_found',
        'neworder.error.min_quantity',
        'neworder.error.max_quantity',
        'neworder.error.service_inactive',
        'neworder.error.service_not_found',
        'error.invalid_service',
        'error.invalid_link',
        'error.duplicate_order',
        'Invalid link',
        'Duplicate order',
        'Min quantity',
        'Max quantity',
    ];

    public function __construct(
        private readonly HttpClientInterface  $httpClient,
        private readonly ProviderCredential   $credential,
        private readonly LoggerInterface      $logger,
        private readonly string               $slug,
    ) {}

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function addOrder(string $externalServiceId, string $targetUrl, int $quantity): string
    {
        $endpoint = $this->getApiEndpoint();
        $body = [
            'key'      => $this->credential->getApiKey(),
            'action'   => 'add',
            'service'  => $externalServiceId,
            'link'     => $targetUrl,
            'quantity' => $quantity,
        ];

        $this->logger->debug('[SMM] → REQUEST', [
            'provider' => $this->slug,
            'action'   => 'add',
        ]);

        $startMs = (int) round(microtime(true) * 1000);

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'body'          => $body,
                'timeout'       => 15,
                'max_duration'  => 20,
                'max_redirects' => 0,
            ]);

            $status  = $response->getStatusCode();
            $raw     = $response->toArray(false);
            $elapsed = (int) round(microtime(true) * 1000) - $startMs;

            $this->logger->debug('[SMM] ← RESPONSE', [
                'provider'    => $this->slug,
                'action'      => 'add',
                'http_status' => $status,
                'elapsed_ms'  => $elapsed,
            ]);

            if ($status < 200 || $status >= 300) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] HTTP %d ao criar pedido', $this->slug, $status)
                );
            }

            if (isset($raw['error'])) {
                $errorCode = (string) $raw['error'];

                $this->logger->warning('[SMM] API retornou erro de negócio', [
                    'provider' => $this->slug,
                    'action'   => 'add',
                    'error'    => $errorCode,
                ]);

                if ($this->isBusinessError($errorCode)) {
                    throw new ProviderBusinessException(
                        sprintf('[%s] Erro ao criar pedido: %s', $this->slug, $errorCode)
                    );
                }

                throw new ProviderTechnicalException(
                    sprintf('[%s] Erro desconhecido ao criar pedido: %s', $this->slug, $errorCode)
                );
            }

            if (empty($raw['order'])) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] Resposta sem order ID', $this->slug)
                );
            }

            return (string) $raw['order'];

        } catch (ProviderBusinessException|ProviderTechnicalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderTechnicalException(
                sprintf('[%s] Falha de comunicação: %s', $this->slug, $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function getOrderStatus(string $externalOrderId): array
    {
        $endpoint = $this->getApiEndpoint();
        $body = [
            'key'    => $this->credential->getApiKey(),
            'action' => 'status',
            'order'  => $externalOrderId,
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'body'          => $body,
                'timeout'       => 10,
                'max_duration'  => 15,
                'max_redirects' => 0,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] HTTP %d ao consultar status', $this->slug, $response->getStatusCode())
                );
            }

            $raw = $response->toArray(false);

            if (isset($raw['error'])) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] Erro ao consultar status: %s', $this->slug, $raw['error'])
                );
            }

            return [
                'status'      => $raw['status']      ?? 'processing',
                'start_count' => (int) ($raw['start_count'] ?? 0),
                'remains'     => (int) ($raw['remains']     ?? 0),
                'charge'      => (float) ($raw['charge']    ?? 0.0),
            ];

        } catch (ProviderTechnicalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderTechnicalException(
                sprintf('[%s] Falha de comunicação (status): %s', $this->slug, $e->getMessage()),
                0,
                $e
            );
        }
    }

    public function getBalance(): float
    {
        $endpoint = $this->getApiEndpoint();
        $body = [
            'key'    => $this->credential->getApiKey(),
            'action' => 'balance',
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'body'          => $body,
                'timeout'       => 10,
                'max_duration'  => 15,
                'max_redirects' => 0,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] HTTP %d ao consultar saldo', $this->slug, $response->getStatusCode())
                );
            }

            $raw = $response->toArray(false);

            if (isset($raw['error'])) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] Erro ao consultar saldo: %s', $this->slug, $raw['error'])
                );
            }

            return (float) ($raw['balance'] ?? 0.0);

        } catch (ProviderTechnicalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderTechnicalException(
                sprintf('[%s] Falha de comunicação (balance): %s', $this->slug, $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @return array<int, array{service: string, name: string, type: string, rate: string, min: string, max: string, category: string}>
     */
    public function getServices(): array
    {
        $endpoint = $this->getApiEndpoint();
        $body = [
            'key'    => $this->credential->getApiKey(),
            'action' => 'services',
        ];

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'body'          => $body,
                'timeout'       => 15,
                'max_duration'  => 20,
                'max_redirects' => 0,
            ]);

            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] HTTP %d ao listar serviços', $this->slug, $response->getStatusCode())
                );
            }

            $raw = $response->toArray(false);

            if (isset($raw['error'])) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] Erro ao listar serviços: %s', $this->slug, $raw['error'])
                );
            }

            if (!is_array($raw)) {
                throw new ProviderTechnicalException(
                    sprintf('[%s] Resposta inesperada ao listar serviços', $this->slug)
                );
            }

            return array_map(static fn(array $s) => [
                'service'  => (string) ($s['service']  ?? ''),
                'name'     => (string) ($s['name']     ?? ''),
                'type'     => (string) ($s['type']     ?? ''),
                'rate'     => (string) ($s['rate']     ?? '0'),
                'min'      => (string) ($s['min']      ?? '0'),
                'max'      => (string) ($s['max']      ?? '0'),
                'category' => (string) ($s['category'] ?? ''),
            ], $raw);

        } catch (ProviderTechnicalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderTechnicalException(
                sprintf('[%s] Falha de comunicação (services): %s', $this->slug, $e->getMessage()),
                0,
                $e
            );
        }
    }

    private function isBusinessError(string $code): bool
    {
        foreach (self::BUSINESS_ERRORS as $pattern) {
            if (stripos($code, $pattern) !== false || $code === $pattern) {
                return true;
            }
        }
        return false;
    }

    private function getApiEndpoint(): string
    {
        $url = trim($this->credential->getBaseUrl());
        $parts = parse_url($url);
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $ip = trim($host, '[]');

        if (
            $parts === false
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || $host === ''
            || (filter_var($ip, FILTER_VALIDATE_IP) === false && !str_contains($host, '.'))
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || in_array($host, ['localhost', 'localhost.localdomain'], true)
            || preg_match('/\.(local|internal|test|invalid|example)$/i', $host) === 1
            || (filter_var($ip, FILTER_VALIDATE_IP) !== false
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false)
        ) {
            throw new ProviderTechnicalException(
                sprintf('[%s] O endpoint do provedor deve ser uma URL HTTPS pública válida.', $this->slug)
            );
        }

        if ($this->credential->getApiKey() === '') {
            throw new ProviderTechnicalException(
                sprintf('[%s] A chave de API do provedor não está configurada.', $this->slug)
            );
        }

        return rtrim($url, '/');
    }
}
