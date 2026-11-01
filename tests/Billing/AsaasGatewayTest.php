<?php

declare(strict_types=1);

namespace App\Tests\Billing;

use App\Billing\AsaasGateway;
use App\Billing\Exception\GatewayException;
use App\Entity\Payment;
use App\Entity\User;
use Doctrine\DBAL\LockMode;
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

    public function testConfirmedPaymentIsNotApprovedUntilAsaasReceivesFunds(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"status":"CONFIRMED"}'));
        $gateway = new AsaasGateway(
            $this->createStub(EntityManagerInterface::class),
            $client,
            'production-api-key',
            'production',
            'webhook-token',
        );

        self::assertSame(
            Payment::STATUS_PENDING,
            $gateway->fetchStatus((new Payment())->setExternalId('pay_confirmed')),
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

    public function testPixQrRequestRetriesTransientNotFoundResponse(): void
    {
        $qrAttempts = 0;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$qrAttempts): MockResponse {
            if (str_contains($url, '/customers?')) {
                return new MockResponse('{"data":[]}');
            }

            if (str_ends_with($url, '/customers')) {
                return new MockResponse('{"id":"cus_test"}');
            }

            if (str_ends_with($url, '/payments')) {
                return new MockResponse('{"id":"pay_test","status":"PENDING","billingType":"PIX"}');
            }

            if (str_ends_with($url, '/pixQrCode')) {
                ++$qrAttempts;

                return $qrAttempts === 1
                    ? new MockResponse('{"errors":[{"code":"PAYMENT_NOT_FOUND"}]}', ['http_code' => 404])
                    : new MockResponse('{"payload":"pix-copy-code","encodedImage":"aW1hZ2U="}');
            }

            self::fail('Unexpected Asaas request: '.$url);
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')
            ->with(self::callback(static fn (Payment $payment): bool => $payment->getExternalId() === 'pay_test'));
        $entityManager->expects(self::once())->method('flush');

        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $payment = $gateway->createDeposit($this->createUser(), 5000, Payment::METHOD_PIX, '52998224725');

        self::assertSame(2, $qrAttempts);
        self::assertSame('pix-copy-code', $payment->getPixCode());
        self::assertSame('aW1hZ2U=', $payment->getQrCodeBase64());
        self::assertSame('asaas', json_decode($payment->getGatewayResponse(), true)['gateway']);
    }

    public function testPixPaymentRemainsAvailableWhenAsaasQrIsTemporarilyUnavailable(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if (str_contains($url, '/customers?')) {
                return new MockResponse('{"data":[]}');
            }
            if (str_ends_with($url, '/customers')) {
                return new MockResponse('{"id":"cus_test"}');
            }
            if (str_ends_with($url, '/payments')) {
                return new MockResponse('{"id":"pay_pending_qr","status":"PENDING","billingType":"PIX"}');
            }
            if (str_ends_with($url, '/pixQrCode')) {
                return new MockResponse('{}');
            }

            self::fail('Unexpected Asaas request: '.$url);
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')
            ->with(self::callback(static fn (Payment $payment): bool => $payment->getExternalId() === 'pay_pending_qr'));
        $entityManager->expects(self::once())->method('flush');

        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $payment = $gateway->createDeposit($this->createUser(), 5000, Payment::METHOD_PIX, '52998224725');
        $response = json_decode($payment->getGatewayResponse(), true);

        self::assertSame(Payment::STATUS_PENDING, $payment->getStatus());
        self::assertSame('pay_pending_qr', $payment->getExternalId());
        self::assertTrue($response['pixQrUnavailable']);
        self::assertSame('qr_data_missing', $response['pixQrFailure']['reason']);
    }

    public function testInvalidCpfIsRejectedBeforeCallingAsaas(): void
    {
        $gateway = new AsaasGateway(
            $this->createStub(EntityManagerInterface::class),
            new MockHttpClient(static function (): MockResponse {
                self::fail('Asaas must not be called with an invalid CPF.');
            }),
            'api-key',
            'production',
            'webhook-token',
        );

        try {
            $gateway->createDeposit($this->createUser(), 5000, Payment::METHOD_PIX, '11111111111');
            self::fail('Expected CPF validation to reject the request.');
        } catch (GatewayException $exception) {
            self::assertSame('cpf_invalid', $exception->getContext()['reason']);
        }
    }

    public function testPixQrCanBeRefreshedForAnExistingAsaasPayment(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            self::assertSame('GET', $method);
            self::assertStringEndsWith('/payments/pay_existing/pixQrCode', $url);

            return new MockResponse('{"payload":"refreshed-code","encodedImage":"cmVmcmVzaGVkLWltYWdl"}');
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $payment = (new Payment())
            ->setExternalId('pay_existing')
            ->setMethod(Payment::METHOD_PIX)
            ->setGatewayResponse('{"id":"pay_existing","billingType":"PIX","status":"PENDING"}');

        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $gateway->refreshPixData($payment);
        $response = json_decode($payment->getGatewayResponse(), true);

        self::assertSame('refreshed-code', $payment->getPixCode());
        self::assertSame('cmVmcmVzaGVkLWltYWdl', $payment->getQrCodeBase64());
        self::assertSame('asaas', $response['gateway']);
        self::assertFalse($response['pixQrUnavailable']);
    }

    public function testPendingPixCanBeCancelledInAsaasAndLocally(): void
    {
        $payment = (new Payment())
            ->setType(Payment::TYPE_DEPOSIT)
            ->setMethod(Payment::METHOD_PIX)
            ->setExternalId('pay_pending_123')
            ->setGatewayResponse('{"gateway":"asaas"}');
        $client = new MockHttpClient(static function (string $method, string $url, array $options): MockResponse {
            self::assertSame('DELETE', $method);
            self::assertSame('https://api.asaas.com/v3/payments/pay_pending_123', $url);
            self::assertContains('access_token: api-key', $options['normalized_headers']['access_token']);

            return new MockResponse('{"deleted":true,"id":"pay_pending_123"}');
        });
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())
            ->method('refresh')
            ->with($payment, LockMode::PESSIMISTIC_WRITE);
        $entityManager->expects(self::once())
            ->method('wrapInTransaction')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());

        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $gateway->cancelPix($payment);

        self::assertSame(Payment::STATUS_CANCELLED, $payment->getStatus());
    }

    public function testFailedAsaasCancellationDoesNotChangeLocalPaymentStatus(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            '{"errors":[{"description":"Payment cannot be deleted"}]}',
            ['http_code' => 400],
        ));
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('wrapInTransaction');

        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $payment = (new Payment())
            ->setType(Payment::TYPE_DEPOSIT)
            ->setMethod(Payment::METHOD_PIX)
            ->setExternalId('pay_already_paid');

        try {
            $gateway->cancelPix($payment);
            self::fail('Expected Asaas to reject cancellation of a payment that cannot be deleted.');
        } catch (GatewayException $exception) {
            self::assertSame(400, $exception->getContext()['http_status']);
        }

        self::assertSame(Payment::STATUS_PENDING, $payment->getStatus());
    }

    public function testNonPendingPixCannotBeCancelled(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $client = new MockHttpClient(static function (): MockResponse {
            self::fail('Asaas must not be called for a non-pending payment.');
        });
        $gateway = new AsaasGateway($entityManager, $client, 'api-key', 'production', 'webhook-token');
        $payment = (new Payment())
            ->setType(Payment::TYPE_DEPOSIT)
            ->setMethod(Payment::METHOD_PIX)
            ->setStatus(Payment::STATUS_APPROVED)
            ->setExternalId('pay_already_paid');

        try {
            $gateway->cancelPix($payment);
            self::fail('Expected non-pending payment to be rejected.');
        } catch (GatewayException $exception) {
            self::assertSame('payment_not_pending', $exception->getContext()['reason']);
        }
    }

    private function createUser(): User
    {
        return (new User())
            ->setEmail('customer@example.test')
            ->setName('Test Customer')
            ->setPassword('not-used');
    }
}
