<?php

namespace App\Service;

use App\Entity\Application;
use App\Entity\Notification;
use App\Entity\Payment;
use App\Entity\Product;
use App\Entity\User;
use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

class NotificationService
{
    /** @var list<array{0: User, 1: Notification}> */
    private array $pendingWebSocketBroadcasts = [];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NotificationRepository $notificationRepository,
        private UserRepository $userRepository,
        private WebSocketBroadcastService $webSocketBroadcast,
    ) {
    }

    public function notifyAdmin(string $type, string $message, ?string $relatedEntity = null, ?int $relatedId = null): void
    {
        $conn = $this->entityManager->getConnection();
        $sql = 'SELECT id FROM user WHERE JSON_CONTAINS(roles, :role) = 1';
        $stmt = $conn->prepare($sql);
        $stmt->bindValue('role', json_encode(['ROLE_ADMIN']));
        $result = $stmt->executeQuery();
        $adminIds = $result->fetchFirstColumn();

        foreach ($adminIds as $adminId) {
            $admin = $this->userRepository->find($adminId);
            if ($admin) {
                $this->createNotification($admin, $type, $message, $relatedEntity, $relatedId);
            }
        }
    }

    public function notifyUser(User $user, string $type, string $message, ?string $relatedEntity = null, ?int $relatedId = null, bool $flush = true): void
    {
        $this->createNotification($user, $type, $message, $relatedEntity, $relatedId, $flush);
    }

    public function notifyApplicationStatusChange(Application $application, string $oldStatus, string $newStatus, bool $flush = true): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $tenant = $application->getTenant();
        if (!$tenant) {
            return;
        }

        $listingName = $application->getListing()?->getName() ?? 'your selected unit';
        $label = $this->humanizeStatus($newStatus);
        $type = 'lease_update';

        $this->notifyUser(
            $tenant,
            $type,
            sprintf('Your application for %s has been %s', $listingName, strtolower($label)),
            'Application',
            $application->getId(),
            $flush,
        );

        if (in_array($newStatus, ['approved', 'completed'], true)) {
            $this->notifyUser(
                $tenant,
                'contract_update',
                sprintf('Your lease agreement for %s is ready for e-signature', $listingName),
                'Application',
                $application->getId(),
                $flush,
            );
        }

        $landlord = $application->getLandlord();
        if ($landlord && in_array($newStatus, ['pending', 'cancelled', 'rejected'], true)) {
            $this->notifyUser(
                $landlord,
                'application_update',
                sprintf('Application for %s is now %s', $listingName, strtolower($label)),
                'Application',
                $application->getId(),
                $flush,
            );
        }
    }

    public function notifyPaymentStatusChange(Payment $payment, string $oldStatus, string $newStatus, bool $flush = true): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $tenant = $payment->getApplication()?->getTenant();
        if (!$tenant) {
            return;
        }

        $listingName = $payment->getApplication()?->getListing()?->getName() ?? 'your account';
        $period = $payment->getNotes() ?: $payment->getCreatedAt()->format('F Y');
        $amount = number_format((float) $payment->getAmount(), 2);
        $status = strtolower($newStatus);

        $message = match (true) {
            in_array($status, ['completed', 'paid', 'received'], true) =>
                sprintf('Your rent payment of PHP %s for %s has been received', $amount, $period),
            str_contains($status, 'overdue') =>
                sprintf('Your rent payment for %s is overdue', $period),
            str_contains($status, 'waiv') =>
                sprintf('Your rent payment for %s has been waived', $period),
            in_array($status, ['failed', 'rejected', 'cancelled'], true) =>
                sprintf('Your payment of PHP %s for %s was not accepted — please contact your landlord', $amount, $listingName),
            default =>
                sprintf('Your payment for %s is now %s', $period, $this->humanizeStatus($newStatus)),
        };

        $this->notifyUser(
            $tenant,
            'payment_update',
            $message,
            'Payment',
            $payment->getId(),
            $flush,
        );
    }

    public function notifyListingStatusChange(Product $listing, string $oldStatus, string $newStatus, bool $flush = true): void
    {
        if ($oldStatus === $newStatus) {
            return;
        }

        $landlord = $listing->getCreatedBy();
        if (!$landlord) {
            return;
        }

        $name = $listing->getName() ?? 'your property';
        $label = $this->humanizeStatus($newStatus);

        $this->notifyUser(
            $landlord,
            'listing_update',
            sprintf('Your listed property (%s) is now %s', $name, strtolower($label)),
            'Product',
            $listing->getId(),
            $flush,
        );
    }

    public function dispatchPendingWebSocketBroadcasts(): void
    {
        if ($this->pendingWebSocketBroadcasts === []) {
            return;
        }

        $pending = $this->pendingWebSocketBroadcasts;
        $this->pendingWebSocketBroadcasts = [];

        foreach ($pending as [$user, $notification]) {
            $userId = $user->getId();
            if ($userId === null || $notification->getId() === null) {
                continue;
            }
            $this->webSocketBroadcast->pushNotification($userId, $notification);
        }
    }

    private function humanizeStatus(string $status): string
    {
        return ucwords(str_replace('_', ' ', strtolower($status)));
    }

    private function createNotification(User $user, string $type, string $message, ?string $relatedEntity = null, ?int $relatedId = null, bool $flush = true): void
    {
        $notification = new Notification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setMessage($message);
        $notification->setRelatedEntity($relatedEntity);
        $notification->setRelatedId($relatedId);

        $this->entityManager->persist($notification);
        if ($flush) {
            $this->entityManager->flush();
        }

        $this->pendingWebSocketBroadcasts[] = [$user, $notification];
    }
}

