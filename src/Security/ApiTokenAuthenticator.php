<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class ApiTokenAuthenticator extends AbstractAuthenticator
{
    public function __construct(private readonly string $apiToken) {}

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/api/v2/');
    }

    public function authenticate(Request $request): Passport
    {
        $token = $request->headers->get('Authorization', $request->request->get('key', ''));
        $token = str_replace('Bearer ', '', $token);

        if (!hash_equals($this->apiToken, (string) $token)) {
            throw new AuthenticationException('Invalid API key.');
        }

        return new SelfValidatingPassport(new UserBadge('api'));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(['error' => 'invalid_api_key'], 401);
    }
}
