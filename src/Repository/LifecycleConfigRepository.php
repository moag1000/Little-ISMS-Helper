<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\LifecycleConfig;
use App\Entity\Tenant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LifecycleConfig>
 */
class LifecycleConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LifecycleConfig::class);
    }

    /**
     * @return array<string, mixed> map of config_key => decoded value
     */
    public function findOverridesForTransition(Tenant $tenant, string $workflowName, string $transitionName): array
    {
        $rows = $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :tr')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->setParameter('tr', $transitionName)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getConfigKey()] = $row->getConfigValue();
        }
        return $map;
    }

    /**
     * @return list<LifecycleConfig>
     */
    public function findForWorkflow(Tenant $tenant, string $workflowName): array
    {
        return $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->orderBy('lc.transitionName', 'ASC')
            ->addOrderBy('lc.configKey', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForWorkflow(Tenant $tenant, string $workflowName): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findOneByKey(
        Tenant $tenant,
        string $workflowName,
        string $transitionName,
        string $configKey,
    ): ?LifecycleConfig {
        return $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :tr')
            ->andWhere('lc.configKey = :ck')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->setParameter('tr', $transitionName)
            ->setParameter('ck', $configKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteForTransition(Tenant $tenant, string $workflowName, string $transitionName): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->delete()
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :tr')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', $workflowName)
            ->setParameter('tr', $transitionName)
            ->getQuery()
            ->execute();
    }

    // -------------------------------------------------------------------------
    // Workflow-step overlay (Y.3 — WorkflowOverlayController)
    // Key shape: workflowName = 'workflow:{name}', transitionName = 'step:{index}'
    // -------------------------------------------------------------------------

    /**
     * Find all step-level overrides for a given workflow + step index.
     *
     * @return array<string, mixed>  map of config_key => decoded value
     */
    public function findByWorkflowAndStep(Tenant $tenant, string $workflowName, int $stepIndex): array
    {
        $rows = $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :step')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', 'workflow:' . $workflowName)
            ->setParameter('step', 'step:' . $stepIndex)
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->getConfigKey()] = $row->getConfigValue();
        }
        return $map;
    }

    /**
     * Find all step-level overrides for an entire workflow (all steps).
     *
     * @return array<int, array<string, mixed>>  map of stepIndex => [config_key => value]
     */
    public function findAllStepOverridesForWorkflow(Tenant $tenant, string $workflowName): array
    {
        $rows = $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName LIKE :stepPrefix')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', 'workflow:' . $workflowName)
            ->setParameter('stepPrefix', 'step:%')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            // transitionName = 'step:N'
            $parts = explode(':', $row->getTransitionName(), 2);
            $idx = (int) ($parts[1] ?? 0);
            $map[$idx][$row->getConfigKey()] = $row->getConfigValue();
        }
        return $map;
    }

    /**
     * Count all step-level overrides for a given workflow.
     */
    public function countStepOverridesForWorkflow(Tenant $tenant, string $workflowName): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->select('COUNT(lc.id)')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName LIKE :stepPrefix')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', 'workflow:' . $workflowName)
            ->setParameter('stepPrefix', 'step:%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Remove all step-level overrides for a specific step in a workflow.
     */
    public function deleteStepOverrides(Tenant $tenant, string $workflowName, int $stepIndex): int
    {
        return (int) $this->createQueryBuilder('lc')
            ->delete()
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :step')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', 'workflow:' . $workflowName)
            ->setParameter('step', 'step:' . $stepIndex)
            ->getQuery()
            ->execute();
    }

    /**
     * Upsert a single step-level override key.
     */
    public function findOneStepOverrideByKey(
        Tenant $tenant,
        string $workflowName,
        int $stepIndex,
        string $configKey,
    ): ?LifecycleConfig {
        return $this->createQueryBuilder('lc')
            ->where('lc.tenant = :tenant')
            ->andWhere('lc.workflowName = :wf')
            ->andWhere('lc.transitionName = :step')
            ->andWhere('lc.configKey = :ck')
            ->setParameter('tenant', $tenant)
            ->setParameter('wf', 'workflow:' . $workflowName)
            ->setParameter('step', 'step:' . $stepIndex)
            ->setParameter('ck', $configKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
