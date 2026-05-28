<?php

namespace App\EventSubscriber;

use App\Entity\Application;
use App\Entity\Payment;
use App\Entity\Product;
use App\Service\NotificationService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Creates in-app notifications when admin/staff/landlord updates record status.
 * Mobile app polls /api/mobile/notifications and sync revision for live badge updates.
 */
#[AsDoctrineListener(event: Events::preUpdate)]
final class StatusChangeNotificationSubscriber
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof Application) {
            $this->onApplicationUpdate($entity, $args);
            return;
        }

        if ($entity instanceof Payment) {
            $this->onPaymentUpdate($entity, $args);
            return;
        }

        if ($entity instanceof Product) {
            $this->onListingUpdate($entity, $args);
        }
    }

    private function onApplicationUpdate(Application $application, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('status')) {
            return;
        }

        $this->notificationService->notifyApplicationStatusChange(
            $application,
            (string) $args->getOldValue('status'),
            (string) $args->getNewValue('status'),
            false,
        );
    }

    private function onPaymentUpdate(Payment $payment, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('status')) {
            return;
        }

        $this->notificationService->notifyPaymentStatusChange(
            $payment,
            (string) $args->getOldValue('status'),
            (string) $args->getNewValue('status'),
            false,
        );
    }

    private function onListingUpdate(Product $listing, PreUpdateEventArgs $args): void
    {
        if (!$args->hasChangedField('status')) {
            return;
        }

        $this->notificationService->notifyListingStatusChange(
            $listing,
            (string) $args->getOldValue('status'),
            (string) $args->getNewValue('status'),
            false,
        );
    }
}
