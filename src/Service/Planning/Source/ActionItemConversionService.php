<?php

declare(strict_types=1);

namespace App\Service\Planning\Source;

use App\Entity\ActionItem;
use App\Entity\ActionItemReference;
use App\Entity\Tenant;
use App\Repository\ActionItemReferenceRepository;
use App\Repository\SourceConversionConfigRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Converts actionable source items into ActionItems (Maßnahmen) — the collector
 * hub's intake (Engineering-Spec §4).
 *
 * Idempotent: an already-referenced source target is skipped. Off-by-default:
 * a source only runs when an enabled {@see \App\Entity\SourceConversionConfig}
 * exists for the tenant. The created ActionItem keeps the source as its own
 * source-of-truth — the {@see ActionItemReference} is provenance, not a mirror.
 *
 * The ActionItem has an independent lifecycle once created; this service does
 * not auto-close items when the source closes (a deliberate MVP boundary — the
 * planning item tracks effort/scheduling on its own).
 */
final class ActionItemConversionService
{
    public function __construct(
        private readonly SourceAdapterRegistry $registry,
        private readonly SourceConversionConfigRepository $configRepository,
        private readonly ActionItemReferenceRepository $referenceRepository,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Convert all enabled, module-active sources for a tenant.
     *
     * @return array<string, int> created count keyed by source slug
     */
    public function convertForTenant(Tenant $tenant): array
    {
        $configs = $this->configRepository->findForTenantKeyedBySlug($tenant);
        $created = [];

        foreach ($this->registry->all() as $adapter) {
            $slug = $adapter->slug();
            $config = $configs[$slug] ?? null;

            if ($config === null || !$config->isEnabled()) {
                continue;
            }
            $module = $adapter->requiredModule();
            if ($module !== null && !$this->moduleConfiguration->isModuleActive($module)) {
                continue;
            }

            $offset = $config->getDueOffsetDays();
            $defaultEffort = $config->getDefaultEffortPt();
            $count = 0;

            foreach ($adapter->findConvertible($tenant) as $item) {
                if ($adapter->isCompleted($item)) {
                    continue;
                }
                $refId = $adapter->refId($item);
                if ($this->referenceRepository->existsForTarget($tenant, $slug, $refId)) {
                    continue;
                }

                $due = $adapter->dueDateOf($item);
                $dueImmutable = $due !== null
                    ? \DateTimeImmutable::createFromInterface($due)
                    : new \DateTimeImmutable('today');
                if ($offset !== 0) {
                    $dueImmutable = $dueImmutable->modify(sprintf('%+d days', $offset));
                }

                $actionItem = new ActionItem();
                $actionItem->setTitle($adapter->titleOf($item))
                    ->setOrigin($slug)
                    ->setDueDate($dueImmutable)
                    ->setPlannedEffortPt($defaultEffort)
                    ->setStatus(ActionItem::STATUS_OPEN)
                    ->setTenant($tenant);

                $reference = new ActionItemReference();
                $reference->setRefType($slug)->setRefId($refId)->setTenant($tenant);
                $actionItem->addReference($reference);

                $this->entityManager->persist($actionItem);
                $count++;
            }

            if ($count > 0) {
                $created[$slug] = $count;
            }
        }

        $this->entityManager->flush();

        return $created;
    }
}
