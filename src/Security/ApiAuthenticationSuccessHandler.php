<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

class ApiAuthenticationSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        /** @var User $user */
        $user = $token->getUser();

        // TODO: substituir pelo JWT real quando symfony/jwt-authentication-bundle for adicionado
        $pseudoToken = base64_encode(json_encode([
            'sub'   => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'iat'   => time(),
        ]));

        return new JsonResponse([
            'token' => $pseudoToken,
            'user'  => [
                'id'    => $user->getId(),
                'email' => $user->getEmail(),
                'name'  => $user->getName(),
                'roles' => $user->getRoles(),
            ],
        ]);
    }
}
