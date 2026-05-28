<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::postFlush)]
final class NotificationBroadcastSubscriber
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function postFlush(PostFlushEventArgs $args): void
    {
        $this->notificationService->dispatchPendingWebSocketBroadcasts();
    }
}
