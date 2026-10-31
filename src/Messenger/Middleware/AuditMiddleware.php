<?php

declare(strict_types=1);

namespace App\Messenger\Middleware;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;

final class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message   = $envelope->getMessage();
        $shortName = (new \ReflectionClass($message))->getShortName();

        $this->logger->info("[Messenger] dispatch: {$shortName}", [
            'message' => json_encode($message),
        ]);

        $result = $stack->next()->handle($envelope, $stack);

        $this->logger->info("[Messenger] handled: {$shortName}");

        return $result;
    }
}
