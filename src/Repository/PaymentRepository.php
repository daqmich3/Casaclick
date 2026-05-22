<?php

namespace App\Repository;

use App\Entity\Payment;
use App\Entity\Application;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    /**
     * @return Payment[]
     */
    public function findByApplication(Application $application): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.application = :application')
            ->setParameter('application', $application)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Payment[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.application', 'a')
            ->andWhere('a.tenant = :user OR a.landlord = :user')
            ->setParameter('user', $user)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findCompletedByApplication(Application $application): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.application = :application')
            ->andWhere('p.status = :status')
            ->setParameter('application', $application)
            ->setParameter('status', 'completed')
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function getTotalPaidForApplication(Application $application): float
    {
        $result = $this->createQueryBuilder('p')
            ->select('SUM(p.amount) as total')
            ->andWhere('p.application = :application')
            ->andWhere('p.status = :status')
            ->setParameter('application', $application)
            ->setParameter('status', 'completed')
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Payments across all applications belonging to this tenant (customer).
     *
     * @return Payment[]
     */
    public function findByTenant(User $tenant): array
    {
        return $this->createQueryBuilder('p')
            ->join('p.application', 'a')
            ->andWhere('a.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{count: int, latestUpdatedAt: ?string}
     */
    public function getSyncMetaForTenant(User $tenant): array
    {
        return $this->buildSyncMeta($tenant, 'tenant');
    }

    public function getSyncMetaForLandlord(User $landlord): array
    {
        return $this->buildSyncMeta($landlord, 'landlord');
    }

    /**
     * @return array{count: int, latestUpdatedAt: ?string}
     */
    private function buildSyncMeta(User $user, string $side): array
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS cnt', 'MAX(COALESCE(p.paidAt, p.createdAt)) AS maxUpdated')
            ->join('p.application', 'a');

        if ($side === 'landlord') {
            $qb->andWhere('a.landlord = :user');
        } else {
            $qb->andWhere('a.tenant = :user');
        }

        $row = $qb->setParameter('user', $user)->getQuery()->getOneOrNullResult();
        $max = $row['maxUpdated'] ?? null;

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'latestUpdatedAt' => $max instanceof \DateTimeInterface
                ? $max->format(\DateTimeInterface::ATOM)
                : ($max !== null ? (string) $max : null),
        ];
    }
}

