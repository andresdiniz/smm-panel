<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\BrowserPushSubscription;
use App\Entity\User;
use App\Repository\BrowserPushSubscriptionRepository;
use App\Service\BrowserPushConfig;
use App\Service\BrowserPushEndpointValidator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/browser-push', name: 'browser_push_')]
#[IsGranted('ROLE_USER')]
final class BrowserPushController extends AbstractController
{
    private const USER_CATEGORIES = ['order', 'support', 'marketing'];
    private const ADMIN_CATEGORIES = ['support', 'marketing'];

    #[Route('/key', name: 'key', methods: ['GET'])]
    public function publicKey(BrowserPushConfig $config): JsonResponse
    {
        return $this->json(
            ['enabled' => $config->isEnabled(), 'publicKey' => $config->isEnabled() ? $config->publicKey : null],
            headers: ['Cache-Control' => 'private, no-store'],
        );
    }

    #[Route('/subscribe', name: 'subscribe', methods: ['POST'])]
    public function subscribe(
        Request $request,
        BrowserPushSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
        BrowserPushConfig $config,
        BrowserPushEndpointValidator $endpointValidator,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('browser_push', $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }
        if (!$config->isEnabled()) {
            return $this->json(['error' => 'Browser push is not configured.'], JsonResponse::HTTP_SERVICE_UNAVAILABLE);
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $endpoint = $data['endpoint'] ?? null;
        $keys = $data['keys'] ?? null;
        $categories = $data['categories'] ?? null;
        if (!is_string($endpoint) || !is_array($keys) || !is_array($categories)
            || count($categories) > count(self::USER_CATEGORIES)
            || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)
            || strlen($endpoint) > 4096 || strlen($keys['p256dh']) > 200 || strlen($keys['auth']) > 100
        ) {
            return $this->json(['error' => 'Invalid push subscription.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$endpointValidator->isSupported($endpoint)
            || !$endpointValidator->hasValidKeys($keys['p256dh'], $keys['auth'])
        ) {
            return $this->json(['error' => 'Unsupported push endpoint or keys.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $allowedCategories = in_array('ROLE_ADMIN', $user->getRoles(), true)
            ? self::ADMIN_CATEGORIES
            : self::USER_CATEGORIES;
        foreach ($categories as $category) {
            if (!is_string($category) || !in_array($category, $allowedCategories, true)) {
                return $this->json(['error' => 'Invalid notification category.'], JsonResponse::HTTP_BAD_REQUEST);
            }
        }
        $categories = array_values(array_unique($categories));
        $endpointHash = hash('sha256', $endpoint);

        $subscription = $subscriptions->findOneByEndpointHash($endpointHash);
        if ($subscription) {
            $subscription->update($user, $endpoint, $keys['p256dh'], $keys['auth'], $categories);
        } else {
            $subscription = new BrowserPushSubscription(
                $user,
                $endpoint,
                $endpointHash,
                $keys['p256dh'],
                $keys['auth'],
            );
            $subscription->update($user, $endpoint, $keys['p256dh'], $keys['auth'], $categories);
            $em->persist($subscription);
        }

        $em->flush();

        return $this->json(['saved' => true]);
    }

    #[Route('/preferences', name: 'preferences', methods: ['POST'])]
    public function preferences(
        Request $request,
        BrowserPushSubscriptionRepository $subscriptions,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('browser_push', $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || strlen($endpoint) > 4096) {
            return $this->json(['error' => 'Invalid push endpoint.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $subscription = $subscriptions->findOneByEndpointHash(hash('sha256', $endpoint));
        $categories = $subscription && $subscription->getUser()->getId() === $user->getId()
            ? $subscription->getCategories()
            : [];

        return $this->json(['categories' => $categories], headers: ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/unsubscribe', name: 'unsubscribe', methods: ['POST'])]
    public function unsubscribe(
        Request $request,
        BrowserPushSubscriptionRepository $subscriptions,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$this->isCsrfTokenValid('browser_push', $request->headers->get('X-CSRF-TOKEN'))) {
            return $this->json(['error' => 'Invalid CSRF token.'], JsonResponse::HTTP_FORBIDDEN);
        }
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        try {
            $data = $request->toArray();
        } catch (\JsonException) {
            return $this->json(['error' => 'Invalid JSON payload.'], JsonResponse::HTTP_BAD_REQUEST);
        }
        $endpoint = $data['endpoint'] ?? null;
        if (!is_string($endpoint) || strlen($endpoint) > 4096) {
            return $this->json(['error' => 'Invalid push endpoint.'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $subscription = $subscriptions->findOneByEndpointHash(hash('sha256', $endpoint));
        if ($subscription && $subscription->getUser()->getId() === $user->getId()) {
            $em->remove($subscription);
            $em->flush();
        }

        return $this->json(['removed' => true]);
    }
}
