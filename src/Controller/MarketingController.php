<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

final class MarketingController extends AbstractController
{
    #[Route('/marketing/unsubscribe/{id}', name: 'app_marketing_unsubscribe', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function unsubscribe(int $id, Request $request, UriSigner $uriSigner, UserRepository $users, EntityManagerInterface $em): Response
    {
        if (!$uriSigner->checkRequest($request)) {
            throw new AccessDeniedHttpException('Link de cancelamento inválido ou expirado.');
        }

        if ($request->isMethod('POST')) {
            $token = $request->request->get('_token');
            if (!is_string($token) || !$this->isCsrfTokenValid('marketing_unsubscribe_'.$id, $token)) {
                throw new AccessDeniedHttpException('Solicitação inválida.');
            }

            $user = $users->find($id);
            if (!$user) {
                throw new NotFoundHttpException();
            }

            $user->setMarketingConsent(false);
            $em->flush();

            return $this->render('marketing/unsubscribed.html.twig');
        }

        $user = $users->find($id);
        if (!$user) {
            throw new NotFoundHttpException();
        }

        return $this->render('marketing/unsubscribe_confirm.html.twig', ['user' => $user]);
    }
}
