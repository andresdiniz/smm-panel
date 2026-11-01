<?php

declare(strict_types=1);

namespace App\Message\BrowserPush;

final readonly class SendBrowserPushMessage
{
    public function __construct(
        public string $audience,
        public ?int $userId,
        public string $category,
        public string $title,
        public string $body,
        public string $url,
    ) {}
}
