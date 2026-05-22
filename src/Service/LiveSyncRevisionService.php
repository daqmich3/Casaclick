<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ActivityLogRepository;
use App\Repository\ApplicationRepository;
use App\Repository\NotificationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Single revision fingerprint for web polling and mobile sync (same MySQL on Railway and local).
 */
final class LiveSyncRevisionService
{
    public function __construct(
        private readonly Security $security,
        private readonly ProductRepository $productRepository,
        private readonly ApplicationRepository $applicationRepository,
        private readonly PaymentRepository $paymentRepository,
        private readonly NotificationRepository $notificationRepository,
        private readonly ActivityLogRepository $activityLogRepository,
    ) {
    }

    /**
     * @return array{
     *   revision: string,
     *   listings: string,
     *   applications: string,
     *   payments: string,
     *   serverTime: string,
     *   notifications?: int
     * }
     */
    public function buildForUser(User $user): array
    {
        $parts = [];

        if ($this->security->isGranted('ROLE_ADMIN')) {
            $appMeta = $this->applicationRepository->getGlobalSyncMeta();
            $listingMeta = $this->productRepository->getApprovedMarketplaceSyncMeta();
            $allProductsMeta = $this->productRepository->getAllProductsSyncMeta();
            $logMeta = $this->activityLogRepository->getGlobalSyncMeta();
            $parts[] = ProductRepository::buildSyncRevision($appMeta['count'], $appMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($listingMeta['count'], $listingMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($allProductsMeta['count'], $allProductsMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($logMeta['count'], $logMeta['latestUpdatedAt']);
            $parts[] = (string) $this->notificationRepository->countUnreadByUser($user);
        } elseif ($this->security->isGranted('ROLE_STAFF')) {
            $appMeta = $this->applicationRepository->getGlobalSyncMeta();
            $listingMeta = $this->productRepository->getApprovedMarketplaceSyncMeta();
            $allProductsMeta = $this->productRepository->getAllProductsSyncMeta();
            $parts[] = ProductRepository::buildSyncRevision($appMeta['count'], $appMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($listingMeta['count'], $listingMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($allProductsMeta['count'], $allProductsMeta['latestUpdatedAt']);
            $parts[] = (string) $this->notificationRepository->countUnreadByUser($user);
        } elseif ($this->security->isGranted('ROLE_LANDLORD')) {
            $listingMeta = $this->productRepository->getApprovedMarketplaceSyncMeta();
            $ownerMeta = $this->productRepository->getOwnerListingsSyncMeta($user->getId());
            $appMeta = $this->applicationRepository->getSyncMetaForLandlord($user);
            $payMeta = $this->paymentRepository->getSyncMetaForLandlord($user);
            $parts[] = ProductRepository::buildSyncRevision($listingMeta['count'], $listingMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($ownerMeta['count'], $ownerMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($appMeta['count'], $appMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($payMeta['count'], $payMeta['latestUpdatedAt']);
            $parts[] = (string) $this->notificationRepository->countUnreadByUser($user);
        } else {
            $listingMeta = $this->productRepository->getApprovedMarketplaceSyncMeta();
            $appMeta = $this->applicationRepository->getSyncMetaForTenant($user);
            $payMeta = $this->paymentRepository->getSyncMetaForTenant($user);
            $parts[] = ProductRepository::buildSyncRevision($listingMeta['count'], $listingMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($appMeta['count'], $appMeta['latestUpdatedAt']);
            $parts[] = ProductRepository::buildSyncRevision($payMeta['count'], $payMeta['latestUpdatedAt']);
            $parts[] = (string) $this->notificationRepository->countUnreadByUser($user);
        }

        $listingsRev = $parts[0] ?? '0:none';
        $applicationsRev = $parts[1] ?? '0:none';
        $paymentsRev = $parts[2] ?? '0:none';
        $combined = sha1(implode('|', $parts));

        $payload = [
            'revision' => $combined,
            'listings' => $listingsRev,
            'applications' => $applicationsRev,
            'payments' => $paymentsRev,
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];

        if (!$this->security->isGranted('ROLE_STAFF')) {
            $payload['notifications'] = $this->notificationRepository->countUnreadByUser($user);
        }

        return $payload;
    }
}
