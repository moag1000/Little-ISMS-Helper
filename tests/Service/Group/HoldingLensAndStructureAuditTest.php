<?php

declare(strict_types=1);

namespace App\Tests\Service\Group;

use App\Entity\AuditLog;
use App\Entity\Incident;
use App\Entity\Tenant;
use App\Enum\GovernanceModel;
use App\Enum\IncidentSeverity;
use App\Enum\IncidentStatus;
use App\Service\Group\CorporateStructureChange;
use App\Service\Group\HoldingScope;
use App\Service\Group\OrganisationComparison;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Tests\Contract\Support\ContractRecordFactory;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * W6: (1) group views count another organisation's incident only when it was
 * released to the holding (visibleToHolding) — one rule, armed by every
 * HoldingScope entry; (2) corporate structure changes land in the audit log.
 */
final class HoldingLensAndStructureAuditTest extends KernelTestCase
{
    #[Test]
    public function groupViewsHideUnreleasedIncidentsOfSubsidiaries(): void
    {
        [$holding, $sub] = $this->group();
        $own = $this->incident($holding, false);
        $released = $this->incident($sub, true);
        $confidential = $this->incident($sub, false);
        $this->context()->setCurrentTenant($holding);

        $ids = static fn (array $rows): array => array_map(static fn (Incident $i): int => (int) $i->getId(), $rows);
        $seen = $ids(static::getContainer()->get(HoldingScope::class)->read(
            fn (): array => $this->em()->getRepository(Incident::class)->findBy(['tenant' => [$holding, $sub]]),
        ));
        self::assertContains($own->getId(), $seen, 'own incidents count, released or not');
        self::assertContains($released->getId(), $seen);
        self::assertNotContains($confidential->getId(), $seen, 'unreleased subsidiary incident');

        $pinned = $ids(static::getContainer()->get(HoldingScope::class)->asTenant(
            $sub,
            fn (): array => $this->em()->getRepository(Incident::class)->findBy(['tenant' => $sub]),
        ));
        self::assertSame([(int) $released->getId()], $pinned, 'asTenant computes the subsidiary as the holding sees it');
        self::assertFalse($this->em()->getFilters()->isEnabled('holding_incident_filter'), 'the lens is off again afterwards');

        $comparison = static::getContainer()->get(OrganisationComparison::class)->build([$holding, $sub]);
        $subRow = array_values(array_filter($comparison['rows'], static fn (array $r): bool => $r['tenant']->getId() === $sub->getId()))[0];
        self::assertSame(1, (int) ($subRow['kpis']['open_incidents']['value'] ?? -1), 'organisation comparison counts only the released incident');
    }

    #[Test]
    public function structureChangesAreAuditLogged(): void
    {
        [$holding, $sub] = $this->group(attach: false);
        $this->context()->setCurrentTenant($holding);
        $change = static::getContainer()->get(CorporateStructureChange::class);

        self::assertSame([], $change->move($sub, $holding, GovernanceModel::HIERARCHICAL, null));
        $change->setGovernance($sub, 'default', null, GovernanceModel::SHARED, null, null, createMissing: false);
        self::assertSame([], $change->move($sub, null, null, null));

        $actions = array_map(
            static fn (AuditLog $l): string => (string) $l->getAction(),
            $this->em()->getRepository(AuditLog::class)->findBy(['entityType' => ['Tenant', 'CorporateGovernance']], ['id' => \SortDirection::Ascending]),
        );
        foreach ([CorporateStructureChange::ACTION_PARENT_SET, CorporateStructureChange::ACTION_GOVERNANCE_SET, CorporateStructureChange::ACTION_PARENT_REMOVED] as $expected) {
            self::assertContains($expected, $actions);
        }
        $set = $this->em()->getRepository(AuditLog::class)->findOneBy(['action' => CorporateStructureChange::ACTION_PARENT_SET, 'entityId' => $sub->getId()]);
        self::assertStringContainsString((string) $holding->getId(), (string) json_encode($set?->getNewValues()));
    }

    /** @return array{0: Tenant, 1: Tenant} */
    private function group(bool $attach = true): array
    {
        $modules = static::getContainer()->get(ModuleConfigurationService::class)->resolveModuleDependencies(['incidents'])['modules'];
        $make = function (string $name) use ($modules): Tenant {
            $id = bin2hex(random_bytes(4));
            $t = (new Tenant())->setName($name . ' ' . $id)->setCode($name . '_' . $id)->setActiveModules(array_values($modules));
            $this->em()->persist($t);

            return $t;
        };
        $holding = $make('holding')->setIsCorporateParent(true);
        $sub = $make('sub');
        if ($attach) {
            $sub->setParent($holding);
        }
        $this->em()->flush();

        return [$holding, $sub];
    }

    private function incident(Tenant $tenant, bool $released): Incident
    {
        $incident = (new ContractRecordFactory($this->em()))->create(Incident::class, $tenant, 'lens');
        $incident->setSeverity(IncidentSeverity::cases()[0])->setStatus(IncidentStatus::Reported)
            ->setDetectedAt(new \DateTimeImmutable('-1 day'))->setVisibleToHolding($released);
        $this->em()->persist($incident);
        $this->em()->flush();

        return $incident;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    private function context(): TenantContext
    {
        return static::getContainer()->get(TenantContext::class);
    }
}
