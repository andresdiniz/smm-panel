<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class AllowRobotsIndexingListener implements EventSubscriberInterface
{
    public function onResponse(ResponseEvent $event): void
    {
        $event->getResponse()->headers->remove('X-Robots-Tag');
    }

    public static function getSubscribedEvents(): array
    {
        // -256 roda DEPOIS do -255 do listener do Symfony
        return [
            KernelEvents::RESPONSE => ['onResponse', -256],
        ];
    }
}
