<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Repository for {@see TrainingParticipation}.
 *
 * Structured Training-User assignment tracking.
 *
 * @extends ServiceEntityRepository<TrainingParticipation>
 *
 * @method TrainingParticipation|null find($id, $lockMode = null, $lockVersion = null)
 * @method TrainingParticipation|null findOneBy(array $criteria, array $orderBy = null)
 * @method TrainingParticipation[]    findAll()
 * @method TrainingParticipation[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TrainingParticipationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrainingParticipation::class);
    }

    public function findOneFor(Training $training, User $user): ?TrainingParticipation
    {
        return $this->findOneBy([
            'training' => $training,
            'user' => $user,
        ]);
    }

    /**
     * @return TrainingParticipation[]
     */
    public function findPendingForUser(User $user, Tenant $tenant): array
    {
        return $this->createQueryBuilder('tp')
            ->andWhere('tp.tenant = :tenant')
            ->andWhere('tp.user = :user')
            ->andWhere('tp.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('user', $user)
            ->setParameter('status', TrainingParticipation::STATUS_PENDING)
            ->orderBy('tp.assignedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find non-terminal training participations for a tenant.
     *
     * Used by TrainingParticipationAdapter::findConvertible() to avoid loading
     * the full tenant set and discarding terminal rows in PHP.
     *
     * Terminal statuses (isCompleted=true in the adapter):
     *   completed, failed, waived
     *
     * @return TrainingParticipation[]
     */
    public function findConvertibleForTenant(Tenant $tenant): array
    {
        return $this->createQueryBuilder('tp')
            ->andWhere('tp.tenant = :tenant')
            ->andWhere('tp.status NOT IN (:terminal)')
            ->setParameter('tenant', $tenant)
            ->setParameter('terminal', [
                TrainingParticipation::STATUS_COMPLETED,
                TrainingParticipation::STATUS_FAILED,
                TrainingParticipation::STATUS_WAIVED,
            ])
            ->orderBy('tp.assignedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TrainingParticipation[]
     */
    public function findByTraining(Training $training): array
    {
        return $this->createQueryBuilder('tp')
            ->andWhere('tp.training = :training')
            ->setParameter('training', $training)
            ->orderBy('tp.assignedAt', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * Participations of the organisation per status (awareness evidence, ISO 27001 A.6.3).
     *
     * @return array<string, int> status → count
     */
    public function countByStatus(Tenant $tenant): array
    {
        $rows = $this->createQueryBuilder('p')
            ->select('p.status AS status', 'COUNT(p.id) AS n')
            ->where('p.tenant = :tenant')
            ->setParameter('tenant', $tenant)
            ->groupBy('p.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * The latest completed training per user since a date (NIS-2 Art. 20 (2):
     * the management body is trained regularly).
     *
     * @param list<User> $users
     * @return array<int, \DateTimeImmutable> user id → last completion
     */
    public function lastCompletionByUser(Tenant $tenant, array $users, \DateTimeInterface $since): array
    {
        if ($users === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('p')
            ->select('IDENTITY(p.user) AS userId', 'MAX(p.completedAt) AS completedAt')
            ->where('p.tenant = :tenant')
            ->andWhere('p.user IN (:users)')
            ->andWhere('p.status = :completed')
            ->andWhere('p.completedAt >= :since')
            ->setParameter('tenant', $tenant)
            ->setParameter('users', $users)
            ->setParameter('completed', TrainingParticipation::STATUS_COMPLETED)
            ->setParameter('since', $since)
            ->groupBy('p.user')
            ->getQuery()
            ->getArrayResult();

        $last = [];
        foreach ($rows as $row) {
            $last[(int) $row['userId']] = new \DateTimeImmutable((string) $row['completedAt']);
        }

        return $last;
    }
}
