<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\AsaasGateway;
use App\Entity\Payment;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AsaasGatewayTest extends TestCase
{
    public function testSandboxIsUsedForStatusRequests(): void
    {
        $requestedUrl = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrl): MockResponse {
            $requestedUrl = $url;

            return new MockResponse('{"status":"PENDING"}');
        });
        $gateway = new AsaasGateway(
            $this->createStub(EntityManagerInterface::class),
            $client,
            'sandbox-api-key',
            'sandbox',
            'webhook-token',
        );
        $payment = (new Payment())->setExternalId('pay_sandbox_1');

        self::assertSame(Payment::STATUS_PENDING, $gateway->fetchStatus($payment));
        self::assertSame(
            'https://api-sandbox.asaas.com/v3/payments/pay_sandbox_1',
            $requestedUrl,
        );
    }

    public function testProductionIsUsedForStatusRequests(): void
    {
        $requestedUrl = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requestedUrl): MockResponse {
            $requestedUrl = $url;

            return new MockResponse('{"status":"PENDING"}');
        });
        $gateway = new AsaasGateway(
            $this->createStub(EntityManagerInterface::class),
            $client,
            'production-api-key',
            'production',
            'webhook-token',
        );
        $payment = (new Payment())->setExternalId('pay_production_1');

        self::assertSame(Payment::STATUS_PENDING, $gateway->fetchStatus($payment));
        self::assertSame(
            'https://api.asaas.com/v3/payments/pay_production_1',
            $requestedUrl,
        );
    }

    public function testEmptyWebhookTokenNeverAuthenticates(): void
    {
        $gateway = new AsaasGateway(
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient(),
            'sandbox-api-key',
            'sandbox',
            '',
        );

        $this->expectException(\InvalidArgumentException::class);
        $gateway->processWebhook(new \Symfony\Component\HttpFoundation\Request(
            [],
            [],
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            '{"event":"PAYMENT_RECEIVED","payment":{"id":"pay_1"}}',
        ));
    }
}
