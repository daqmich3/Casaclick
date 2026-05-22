<?php

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    public function getTotalCount(): int
    {
        return (int)$this->count([]);
    }

    public function getTotalPriceSum(): float
    {
        $sum = $this->createQueryBuilder('p')
            ->select('COALESCE(SUM(p.price), 0)')
            ->getQuery()
            ->getSingleScalarResult();

        return (float)$sum;
    }

    /**
     * @return Product[]
     */
    public function findRecent(int $limit = 6): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

//    /**
//     * @return Product[] Returns an array of Product objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('p.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Product
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }

    /**
     * @return Product[]
     */
    public function findByOwner(int $userId, int $limit = 20): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->andWhere('p.createdBy = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function findAllWithLandlord(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Approved listings for marketing highlights (e.g. About page carousel), newest first.
     *
     * @return Product[]
     */
    public function findApprovedRecent(int $limit = 8): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.category', 'c')
            ->addSelect('c')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'approved')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Product[]
     */
    public function findApprovedWithLandlord(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'approved')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findApprovedById(int $id): ?Product
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->andWhere('p.id = :id')
            ->andWhere('p.status = :status')
            ->setParameter('id', $id)
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Product[]
     */
    public function findPendingWithLandlord(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.createdBy', 'user')
            ->addSelect('user')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'pending')
            ->orderBy('p.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Fingerprint for approved marketplace listings (mobile live sync).
     *
     * @return array{count: int, latestUpdatedAt: ?string}
     */
    public function getApprovedMarketplaceSyncMeta(): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS cnt', 'MAX(p.updatedAt) AS maxUpdated')
            ->andWhere('p.status = :status')
            ->setParameter('status', 'approved')
            ->getQuery()
            ->getOneOrNullResult();

        $max = $row['maxUpdated'] ?? null;

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'latestUpdatedAt' => $max instanceof \DateTimeInterface
                ? $max->format(\DateTimeInterface::ATOM)
                : null,
        ];
    }

    /**
     * Fingerprint for a landlord's own listings (all statuses).
     *
     * @return array{count: int, latestUpdatedAt: ?string}
     */
    /**
     * All listings (staff/admin dashboard + pending approvals).
     *
     * @return array{count: int, latestUpdatedAt: ?string}
     */
    public function getAllProductsSyncMeta(): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS cnt', 'MAX(p.updatedAt) AS maxUpdated')
            ->getQuery()
            ->getOneOrNullResult();

        $max = $row['maxUpdated'] ?? null;

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'latestUpdatedAt' => $max instanceof \DateTimeInterface
                ? $max->format(\DateTimeInterface::ATOM)
                : null,
        ];
    }

    public function getOwnerListingsSyncMeta(int $userId): array
    {
        $row = $this->createQueryBuilder('p')
            ->select('COUNT(p.id) AS cnt', 'MAX(p.updatedAt) AS maxUpdated')
            ->andWhere('p.createdBy = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        $max = $row['maxUpdated'] ?? null;

        return [
            'count' => (int) ($row['cnt'] ?? 0),
            'latestUpdatedAt' => $max instanceof \DateTimeInterface
                ? $max->format(\DateTimeInterface::ATOM)
                : null,
        ];
    }

    public static function buildSyncRevision(int $count, ?string $latestUpdatedAt): string
    {
        return $count . ':' . ($latestUpdatedAt ?? 'none');
    }
}
