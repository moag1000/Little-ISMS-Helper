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
 * Audit V3 W2-C3: structured Training-User assignment tracking.
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
            ->orderBy('tp.assignedAt', 'ASC')
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
            ->orderBy('tp.assignedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
