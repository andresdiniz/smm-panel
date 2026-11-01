<?php

declare(strict_types=1);

namespace App\MessageHandler\BrowserPush;

use App\Message\BrowserPush\SendBrowserPushMessage;
use App\Repository\BrowserPushSubscriptionRepository;
use App\Service\BrowserPushConfig;
use Doctrine\ORM\EntityManagerInterface;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SendBrowserPushHandler
{
    public function __construct(
        private readonly BrowserPushSubscriptionRepository $subscriptions,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly BrowserPushConfig $config,
    ) {}

    public function __invoke(SendBrowserPushMessage $message): void
    {
        if (!$this->config->isEnabled()) {
            throw new \RuntimeException('VAPID public and private keys must be configured before sending browser push.');
        }

        $subscriptions = $this->subscriptions->findForAudience(
            $message->audience,
            $message->userId,
            $message->category,
        );
        if ($subscriptions === []) {
            return;
        }

        $webPush = new WebPush([
            'VAPID' => [
                'subject' => $this->config->subject,
                'publicKey' => $this->config->publicKey,
                'privateKey' => $this->config->privateKey,
            ],
        ], ['TTL' => 3600, 'urgency' => 'normal'], 15);

        $payload = json_encode([
            'title' => $message->title,
            'body' => $message->body,
            'url' => $message->url,
            'category' => $message->category,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        foreach ($subscriptions as $subscription) {
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->getEndpoint(),
                    'publicKey' => $subscription->getPublicKey(),
                    'authToken' => $subscription->getAuthToken(),
                    'contentEncoding' => $subscription->getContentEncoding(),
                ]),
                $payload,
            );
        }

        $expiredHashes = [];
        foreach ($webPush->flush() as $report) {
            if ($report->isSuccess()) {
                continue;
            }

            $this->logger->warning('Browser push delivery failed.', [
                'endpoint_host' => parse_url($report->getEndpoint(), PHP_URL_HOST),
                'reason' => $report->getReason(),
                'expired' => $report->isSubscriptionExpired(),
            ]);

            if ($report->isSubscriptionExpired()) {
                $expiredHashes[] = hash('sha256', $report->getEndpoint());
            }
        }

        foreach (array_unique($expiredHashes) as $endpointHash) {
            if ($subscription = $this->subscriptions->findOneByEndpointHash($endpointHash)) {
                $this->em->remove($subscription);
            }
        }

        if ($expiredHashes !== []) {
            $this->em->flush();
        }
    }
}
