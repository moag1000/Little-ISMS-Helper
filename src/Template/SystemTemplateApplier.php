<?php

declare(strict_types=1);

namespace App\Template;

use App\Entity\Tenant;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Materialises a SystemTemplate into one or many Doctrine entities.
 *
 * Foundation P-14. The Applier handles three concerns:
 *  1. Tenant binding — every created entity gets the current tenant via
 *     reflection on `setTenant()` (skipped for entities without that method).
 *  2. Property assignment via Symfony PropertyAccess — supports nested
 *     property paths and respects setters / public properties.
 *  3. Tenant-profile special case — Tenant templates do NOT instantiate a
 *     new Tenant; they apply `activeModules` via ModuleConfigurationService.
 *
 * The Applier intentionally does NOT call `flush()` — callers control the
 * transaction boundary. It returns the list of persisted entities (or, for
 * tenant profiles, an empty list with `appliedProfile` populated).
 */
final class SystemTemplateApplier
{
    private readonly PropertyAccessorInterface $propertyAccessor;

    /** @var array<string, mixed> Diagnostic info from the last apply call. */
    private array $lastResult = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * @param bool $dryRun When true, no persist/flush is performed; the
     *                     returned entities are detached drafts only.
     * @return list<object> Materialised entities (empty for tenant profiles).
     */
    public function apply(SystemTemplate $template, bool $dryRun = false): array
    {
        $this->lastResult = ['key' => $template->key, 'records' => 0, 'profile_applied' => null];

        if ($template->entityClass === Tenant::class) {
            return $this->applyTenantProfile($template, $dryRun);
        }

        $entities = [];
        foreach ($template->records() as $record) {
            $entities[] = $this->materialise($template->entityClass, $record);
        }

        if (!$dryRun) {
            foreach ($entities as $entity) {
                $this->entityManager->persist($entity);
            }
            // flush is intentionally NOT called — controller owns the tx
        }

        $this->lastResult['records'] = count($entities);
        return $entities;
    }

    /**
     * @return array<string, mixed>
     */
    public function lastResult(): array
    {
        return $this->lastResult;
    }

    /**
     * @param class-string         $entityClass
     * @param array<string, mixed> $properties
     */
    private function materialise(string $entityClass, array $properties): object
    {
        $entity = new $entityClass();

        // Tenant binding via setTenant() if present
        if (method_exists($entity, 'setTenant')) {
            $entity->setTenant($this->tenantContext->getCurrentTenant());
        }

        foreach ($properties as $path => $value) {
            if ($this->propertyAccessor->isWritable($entity, $path)) {
                $this->propertyAccessor->setValue($entity, $path, $value);
            }
        }

        return $entity;
    }

    /**
     * Tenant-profile templates: update `active_modules.yaml` rather than
     * creating an entity. The returned list is always empty; the diagnostic
     * payload reports the applied profile key.
     *
     * @return list<object>
     */
    private function applyTenantProfile(SystemTemplate $template, bool $dryRun): array
    {
        $modules = (array) ($template->prefill['activeModules'] ?? []);
        $profileKey = $template->prefill['profileKey'] ?? null;

        if (!$dryRun && $modules !== []) {
            // ModuleConfigurationService::saveActiveModules() persists to
            // config/active_modules.yaml. If the API renames, update here.
            if (method_exists($this->moduleConfiguration, 'saveActiveModules')) {
                $this->moduleConfiguration->saveActiveModules($modules);
            }
        }

        $this->lastResult['profile_applied'] = $profileKey;
        $this->lastResult['modules_count'] = count($modules);

        return [];
    }
}
