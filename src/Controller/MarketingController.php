<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\MarketingEmailLog;
use App\Repository\MarketingEmailLogRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Routing\Attribute\Route;

final class MarketingController extends AbstractController
{
    #[Route('/marketing/email/{token}/open.gif', name: 'app_marketing_email_open', requirements: ['token' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function trackEmailOpen(
        string $token,
        MarketingEmailLogRepository $emailLogs,
        EntityManagerInterface $em,
    ): Response {
        $emailLog = $emailLogs->findOneByToken($token);
        if ($emailLog?->getStatus() === MarketingEmailLog::STATUS_SENT
            && $emailLog->getUser()->hasMarketingConsent()
        ) {
            $emailLog->recordOpen();
            $em->flush();
        }

        $trackingPixel = base64_decode('R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=', true);
        if ($trackingPixel === false) {
            throw new \LogicException('The built-in email tracking pixel is invalid.');
        }

        $response = new Response($trackingPixel, Response::HTTP_OK, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'private, no-store, no-cache, max-age=0, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic(false);
        $response->setMaxAge(0);

        return $response;
    }

    #[Route('/marketing/email/{token}/click/{linkId}', name: 'app_marketing_email_click', requirements: ['token' => '[a-f0-9]{64}', 'linkId' => '\d+'], methods: ['GET'])]
    public function trackEmailClick(
        string $token,
        string $linkId,
        MarketingEmailLogRepository $emailLogs,
        EntityManagerInterface $em,
    ): Response {
        $emailLog = $emailLogs->findOneByToken($token);
        $links = $emailLog?->getLinks() ?? [];
        $destination = $links[$linkId] ?? null;
        $scheme = is_string($destination) ? strtolower((string) parse_url($destination, PHP_URL_SCHEME)) : '';
        $host = is_string($destination) ? parse_url($destination, PHP_URL_HOST) : null;
        if ($emailLog === null
            || !in_array($scheme, ['http', 'https'], true)
            || !is_string($host)
            || $host === ''
            || parse_url($destination, PHP_URL_USER) !== null
            || parse_url($destination, PHP_URL_PASS) !== null
        ) {
            throw $this->createNotFoundException();
        }

        if ($emailLog->getStatus() === MarketingEmailLog::STATUS_SENT
            && $emailLog->getUser()->hasMarketingConsent()
        ) {
            $emailLog->recordClick($linkId);
            $em->flush();
        }

        $response = new RedirectResponse($destination);
        $response->headers->set('Cache-Control', 'private, no-store, no-cache, max-age=0, must-revalidate');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

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
