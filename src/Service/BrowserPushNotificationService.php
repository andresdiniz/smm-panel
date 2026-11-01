<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\BrowserPush\SendBrowserPushMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class BrowserPushNotificationService
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        private readonly LoggerInterface $logger,
        private readonly BrowserPushConfig $config,
    ) {}

    public function notifyUser(int $userId, string $category, string $title, string $body, string $url): void
    {
        $this->dispatch('user', $userId, $category, $title, $body, $url);
    }

    public function notifyAdmins(string $category, string $title, string $body, string $url): void
    {
        $this->dispatch('admins', null, $category, $title, $body, $url);
    }

    public function notifyMarketingSubscribers(string $title, string $body, string $url): void
    {
        $this->dispatch('marketing', null, 'marketing', $title, $body, $url);
    }

    private function dispatch(
        string $audience,
        ?int $userId,
        string $category,
        string $title,
        string $body,
        string $url,
    ): void {
        if (!$this->config->isEnabled()) {
            $this->logger->warning('Browser push was not queued because VAPID keys are not configured.', [
                'category' => $category,
                'audience' => $audience,
            ]);

            return;
        }

        $this->bus->dispatch(new SendBrowserPushMessage(
            $audience,
            $userId,
            $category,
            mb_substr($title, 0, 100),
            mb_substr($body, 0, 240),
            str_starts_with($url, '/') && !str_starts_with($url, '//') ? $url : '/',
        ));
    }
}
