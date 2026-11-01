<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Billing\Exception\WebhookAuthenticationException;
use App\Billing\GatewayRegistry;
use App\Billing\PaymentGatewayInterface;
use App\Controller\WebhookController;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class WebhookControllerTest extends TestCase
{
    public function testInvalidWebhookAuthenticationReturnsUnauthorized(): void
    {
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('processWebhook')
            ->willThrowException(new WebhookAuthenticationException('Invalid token.'));

        $controller = new WebhookController(new GatewayRegistry(['asaas' => $gateway]), new NullLogger());
        $controller->setContainer(new ContainerBuilder());
        $response = $controller->receive('asaas', Request::create('/webhook/asaas', 'POST'));

        self::assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode());
        self::assertSame(['error' => 'Token de webhook inválido.'], json_decode($response->getContent(), true));
    }
}
