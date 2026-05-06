<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AlvaHintRenderCount;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlvaHintRenderCount>
 */
class AlvaHintRenderCountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlvaHintRenderCount::class);
    }

    /**
     * Single-statement upsert. Cheap enough to call on every hint
     * render; we trade one INSERT .. ON DUPLICATE KEY UPDATE per
     * page that actually shows a hint.
     */
    public function increment(?Tenant $tenant, string $hintKey): void
    {
        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            INSERT INTO alva_hint_render_count (tenant_id, hint_key, render_count)
            VALUES (:tenant, :hintKey, 1)
            ON DUPLICATE KEY UPDATE render_count = render_count + 1
        SQL;

        $conn->executeStatement($sql, [
            'tenant' => $tenant?->getId(),
            'hintKey' => $hintKey,
        ], [
            'tenant' => $tenant !== null ? \PDO::PARAM_INT : \PDO::PARAM_NULL,
            'hintKey' => \PDO::PARAM_STR,
        ]);
    }

    /**
     * @return array<string, int>  hint_key => render count
     */
    public function totalsByTenant(?Tenant $tenant): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.hintKey', 'r.renderCount');
        if ($tenant instanceof Tenant) {
            $qb->where('r.tenant = :tenant')->setParameter('tenant', $tenant);
        } else {
            $qb->where('r.tenant IS NULL');
        }

        $rows = $qb->getQuery()->getArrayResult();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['hintKey']] = (int) $row['renderCount'];
        }

        return $out;
    }
}
