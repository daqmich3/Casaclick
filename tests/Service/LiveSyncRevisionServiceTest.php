<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Repository\ActivityLogRepository;
use App\Repository\ApplicationRepository;
use App\Repository\NotificationRepository;
use App\Repository\PaymentRepository;
use App\Repository\ProductRepository;
use App\Service\LiveSyncRevisionService;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class LiveSyncRevisionServiceTest extends TestCase
{
    public function testBuildForTenantReturnsStableRevisionPayload(): void
    {
        $user = new User();
        $user->setEmail('tenant@example.com');
        $user->setName('Tenant');
        $user->setRoles(['ROLE_TENANT']);
        $user->setPassword('x');
        $user->setEmailVerified(true);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_TENANT',
        );

        $productRepository = $this->createMock(ProductRepository::class);
        $productRepository->method('getApprovedMarketplaceSyncMeta')->willReturn([
            'count' => 3,
            'latestUpdatedAt' => '2026-01-01T00:00:00+00:00',
        ]);

        $applicationRepository = $this->createMock(ApplicationRepository::class);
        $applicationRepository->method('getSyncMetaForTenant')->willReturn([
            'count' => 1,
            'latestUpdatedAt' => '2026-01-02T00:00:00+00:00',
        ]);

        $paymentRepository = $this->createMock(PaymentRepository::class);
        $paymentRepository->method('getSyncMetaForTenant')->willReturn([
            'count' => 0,
            'latestUpdatedAt' => null,
        ]);

        $notificationRepository = $this->createMock(NotificationRepository::class);
        $notificationRepository->method('countUnreadByUser')->willReturn(2);

        $activityLogRepository = $this->createMock(ActivityLogRepository::class);

        $service = new LiveSyncRevisionService(
            $security,
            $productRepository,
            $applicationRepository,
            $paymentRepository,
            $notificationRepository,
            $activityLogRepository,
        );

        $payload = $service->buildForUser($user);

        self::assertNotEmpty($payload['revision']);
        self::assertSame($payload['revision'], $service->buildForUser($user)['revision']);
        self::assertSame(2, $payload['notifications']);
        self::assertStringContainsString('3:', $payload['listings']);
        self::assertStringContainsString('1:', $payload['applications']);
    }
}
