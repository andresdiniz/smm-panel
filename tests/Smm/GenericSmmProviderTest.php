<?php

declare(strict_types=1);

namespace App\Tests\Smm;

use App\Entity\ProviderCredential;
use App\Smm\Exception\ProviderTechnicalException;
use App\Smm\GenericSmmProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GenericSmmProviderTest extends TestCase
{
    public function testSmmV2OrderRequestUsesConfiguredHttpsEndpoint(): void
    {
        $requestOptions = null;
        $client = new MockHttpClient(static function (
            string $method,
            string $url,
            array $options,
        ) use (&$requestOptions): MockResponse {
            $requestOptions = $options;

            return new MockResponse('{"order":12345}');
        });
        $provider = new GenericSmmProvider(
            $client,
            $this->credential('https://api.example.com/api/v2'),
            new NullLogger(),
            'example',
        );

        self::assertSame('12345', $provider->addOrder('service-1', 'https://social.example.com/post/1', 10));
        self::assertSame(0, $requestOptions['max_redirects']);
        self::assertSame(15.0, $requestOptions['timeout']);
        parse_str($requestOptions['body'], $body);
        self::assertSame('test-key', $body['key']);
    }

    public function testPrivateAndNonHttpsEndpointsAreRejectedBeforeRequest(): void
    {
        foreach (['http://api.example.com/v2', 'https://127.0.0.1/api/v2'] as $url) {
            $provider = new GenericSmmProvider(
                new MockHttpClient(),
                $this->credential($url),
                new NullLogger(),
                'example',
            );

            try {
                $provider->getBalance();
                self::fail('An unsafe provider endpoint must be rejected.');
            } catch (ProviderTechnicalException) {
                self::assertTrue(true);
            }
        }
    }

    private function credential(string $url): ProviderCredential
    {
        return (new ProviderCredential())
            ->setType(ProviderCredential::TYPE_SMM)
            ->setSlug('example')
            ->setBaseUrl($url)
            ->setApiKey('test-key');
    }
}
