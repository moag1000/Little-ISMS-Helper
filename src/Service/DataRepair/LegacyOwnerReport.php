<?php

declare(strict_types=1);

namespace App\Service\DataRepair;

use App\Entity\Asset;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\InterestedParty;
use App\Entity\InternalAudit;
use App\Entity\ISMSObjective;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use App\Entity\Vulnerability;
use App\Service\AuditLogger;
use App\Service\Group\HoldingScope;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * Legacy free-text owners nobody has resolved yet (Form Pattern A, last step
 * before the strings can go).
 *
 * Fourteen record types still carry a free-text owner column from before the
 * User/Person pickers existed (`Asset.owner`, `Control.responsiblePerson`, …).
 * A row is open while that text is filled and neither the User nor the Person
 * relation is set: the record then names its owner only as a string that no
 * notification, My-Day inbox or role check can reach.
 *
 * The admin page lists those rows for the caller's organisation tree and
 * resolves them in place: take over the matched account ({@see LegacyOwnerMatcher}),
 * record the name as a Person, or open the edit form. Resolving sets the
 * relation and keeps the text (non-destructive: exports and lists that still
 * read the raw column keep working until the columns are dropped); every
 * change goes to the audit log.
 */
class LegacyOwnerReport
{
    /**
     * type key => record class, legacy text field, User field, Person field,
     * title fields, module, edit route.
     *
     * @var array<string, array{class: class-string, legacy: string, user: string, person: string, title: list<string>, module: string, edit: string}>
     */
    public const array FIELDS = [
        'asset' => ['class' => Asset::class, 'legacy' => 'owner', 'user' => 'ownerUser', 'person' => 'ownerPerson', 'title' => ['name'], 'module' => 'assets', 'edit' => 'app_asset_edit'],
        'control' => ['class' => Control::class, 'legacy' => 'responsiblePerson', 'user' => 'responsiblePersonUser', 'person' => 'responsiblePersonRef', 'title' => ['controlId', 'name'], 'module' => 'controls', 'edit' => 'app_soa_edit'],
        'business_process' => ['class' => BusinessProcess::class, 'legacy' => 'processOwner', 'user' => 'processOwnerUser', 'person' => 'processOwnerPerson', 'title' => ['name'], 'module' => 'bcm', 'edit' => 'app_business_process_edit'],
        'bc_plan' => ['class' => BusinessContinuityPlan::class, 'legacy' => 'planOwner', 'user' => 'planOwnerUser', 'person' => 'planOwnerPerson', 'title' => ['name'], 'module' => 'bcm', 'edit' => 'app_bc_plan_edit'],
        'bc_exercise' => ['class' => BCExercise::class, 'legacy' => 'facilitator', 'user' => 'facilitatorUser', 'person' => 'facilitatorPerson', 'title' => ['name'], 'module' => 'bcm', 'edit' => 'app_bc_exercise_edit'],
        'incident' => ['class' => Incident::class, 'legacy' => 'reportedBy', 'user' => 'reportedByUser', 'person' => 'reportedByPerson', 'title' => ['incidentNumber', 'title'], 'module' => 'incidents', 'edit' => 'app_incident_edit'],
        'internal_audit' => ['class' => InternalAudit::class, 'legacy' => 'leadAuditor', 'user' => 'leadAuditorUser', 'person' => 'leadAuditorPerson', 'title' => ['auditNumber', 'title'], 'module' => 'audits', 'edit' => 'app_audit_edit'],
        'training' => ['class' => Training::class, 'legacy' => 'trainer', 'user' => 'trainerUser', 'person' => 'trainerPerson', 'title' => ['title'], 'module' => 'training', 'edit' => 'app_training_edit'],
        'objective' => ['class' => ISMSObjective::class, 'legacy' => 'responsiblePerson', 'user' => 'responsiblePersonUser', 'person' => 'responsiblePersonRef', 'title' => ['title'], 'module' => 'objectives', 'edit' => 'app_objective_edit'],
        'supplier' => ['class' => Supplier::class, 'legacy' => 'contactPerson', 'user' => 'contactPersonUser', 'person' => 'contactPersonRef', 'title' => ['name'], 'module' => 'suppliers', 'edit' => 'app_supplier_edit'],
        'interested_party' => ['class' => InterestedParty::class, 'legacy' => 'contactPerson', 'user' => 'contactPersonUser', 'person' => 'contactPersonRef', 'title' => ['name'], 'module' => 'interested_parties', 'edit' => 'app_interested_party_edit'],
        'location' => ['class' => Location::class, 'legacy' => 'responsiblePerson', 'user' => 'responsiblePersonUser', 'person' => 'responsiblePersonRef', 'title' => ['name'], 'module' => 'locations', 'edit' => 'app_location_edit'],
        'vulnerability' => ['class' => Vulnerability::class, 'legacy' => 'responsiblePerson', 'user' => 'responsiblePersonUser', 'person' => 'responsiblePersonRef', 'title' => ['cveId', 'title'], 'module' => 'vulnerability_intel', 'edit' => 'app_vulnerability_edit'],
        'patch' => ['class' => Patch::class, 'legacy' => 'responsiblePerson', 'user' => 'responsiblePersonUser', 'person' => 'responsiblePersonRef', 'title' => ['patchId', 'title'], 'module' => 'patches', 'edit' => 'app_patch_edit'],
    ];

    private readonly PropertyAccessorInterface $accessor;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly HoldingScope $holdingScope,
        private readonly ModuleConfigurationService $moduleConfiguration,
        private readonly AuditLogger $auditLogger,
    ) {
        $this->accessor = PropertyAccess::createPropertyAccessor();
    }

    /**
     * Type keys whose module is active — the others have no edit form to send the admin to.
     *
     * @return list<string>
     */
    public function activeTypes(): array
    {
        return array_values(array_filter(
            array_keys(self::FIELDS),
            fn (string $type): bool => $this->moduleConfiguration->isModuleActive(self::FIELDS[$type]['module']),
        ));
    }

    /**
     * Open rows per type, without loading the records.
     *
     * @param list<Tenant> $tenants
     * @return array<string, int>
     */
    public function countOpen(array $tenants): array
    {
        if ($tenants === []) {
            return [];
        }

        return $this->holdingScope->read(function () use ($tenants): array {
            $counts = [];
            foreach ($this->activeTypes() as $type) {
                $counts[$type] = (int) $this->openQuery($type, $tenants)
                    ->select('COUNT(e.id)')
                    ->getQuery()
                    ->getSingleScalarResult();
            }

            return $counts;
        });
    }

    /**
     * All open rows of the given organisations, each with its suggestion.
     *
     * @param list<Tenant> $tenants
     * @return list<LegacyOwnerRow>
     */
    public function openRows(array $tenants, ?string $onlyType = null): array
    {
        if ($tenants === []) {
            return [];
        }

        return $this->holdingScope->read(function () use ($tenants, $onlyType): array {
            $matcher = $this->matcher($tenants);
            $rows = [];
            foreach ($this->activeTypes() as $type) {
                if ($onlyType !== null && $onlyType !== $type) {
                    continue;
                }
                foreach ($this->openQuery($type, $tenants)->getQuery()->getResult() as $record) {
                    $rows[] = $this->row($type, $record, $matcher);
                }
            }

            return $rows;
        });
    }

    /**
     * The open row for one record, or null when it is outside the scope, gone or already resolved.
     *
     * @param list<Tenant> $tenants
     */
    public function findOpenRow(string $type, int $id, array $tenants): ?LegacyOwnerRow
    {
        if (!isset(self::FIELDS[$type]) || $tenants === []) {
            return null;
        }

        return $this->holdingScope->read(function () use ($type, $id, $tenants): ?LegacyOwnerRow {
            $record = $this->openQuery($type, $tenants)
                ->andWhere('e.id = :id')
                ->setParameter('id', $id)
                ->getQuery()
                ->getOneOrNullResult();

            return $record === null ? null : $this->row($type, $record, $this->matcher($tenants));
        });
    }

    /**
     * Sets the matched User or Person as owner. False when the row has no suggestion.
     */
    public function applySuggestion(LegacyOwnerRow $row): bool
    {
        $target = $row->suggestion;
        if ($target === null) {
            return false;
        }
        $this->assign($row, $target);
        $this->entityManager->flush();
        $this->auditLogger->logUpdate(
            $this->auditLogger->getEntityTypeName($row->record),
            $row->id,
            [self::FIELDS[$row->type]['legacy'] => $row->legacy],
            $this->auditValues($row, $target),
            sprintf('Legacy owner "%s" resolved to %s', $row->legacy, $target instanceof User ? 'user #' . $target->getId() : 'person #' . $target->getId()),
        );

        return true;
    }

    /**
     * Takes over every suggestion of the given rows in one flush; one audit batch per record type.
     *
     * @param list<LegacyOwnerRow> $rows
     * @return int number of records resolved
     */
    public function applyAllSuggestions(array $rows): int
    {
        /** @var array<string, list<array{LegacyOwnerRow, User|Person}>> $byType */
        $byType = [];
        foreach ($rows as $row) {
            $target = $row->suggestion;
            if ($target === null) {
                continue;
            }
            $this->assign($row, $target);
            $byType[$row->type][] = [$row, $target];
        }
        if ($byType === []) {
            return 0;
        }
        $this->entityManager->flush();

        $resolved = 0;
        foreach ($byType as $typeRows) {
            $perEntity = array_map(fn (array $pair): array => [
                'entity_id' => $pair[0]->id,
                'action' => 'update',
                'old_values' => [self::FIELDS[$pair[0]->type]['legacy'] => $pair[0]->legacy],
                'new_values' => $this->auditValues($pair[0], $pair[1]),
            ], $typeRows);
            $this->auditLogger->logBulk(
                'legacy_owner_resolve',
                $this->auditLogger->getEntityTypeName($typeRows[0][0]->record),
                ['source' => 'admin.legacy_owners'],
                $perEntity,
                sprintf('Legacy owners resolved to matching accounts: %d', count($typeRows)),
            );
            $resolved += count($typeRows);
        }

        return $resolved;
    }

    /**
     * Records the free text as a Person of the record's organisation (an
     * existing Person with exactly that name is reused) and sets it as owner.
     */
    public function resolveAsPerson(LegacyOwnerRow $row): Person
    {
        $name = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $row->legacy)), 0, 255);
        $person = $this->holdingScope->read(fn (): ?Person => $this->entityManager->createQueryBuilder()
            ->select('p')
            ->from(Person::class, 'p')
            ->where('p.tenant = :tenant')
            ->andWhere('LOWER(p.fullName) = :name')
            ->setParameter('tenant', $row->tenant)
            ->setParameter('name', mb_strtolower($name))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult());

        $created = false;
        if (!$person instanceof Person) {
            $person = (new Person())
                ->setFullName($name)
                ->setPersonType('other')
                ->setTenant($row->tenant);
            $this->entityManager->persist($person);
            $created = true;
        }
        $this->assign($row, $person);
        $this->entityManager->flush();

        if ($created) {
            $this->auditLogger->logCreate('Person', $person->getId(), ['fullName' => $name], 'Person recorded from a legacy owner text');
        }
        $this->auditLogger->logUpdate(
            $this->auditLogger->getEntityTypeName($row->record),
            $row->id,
            [self::FIELDS[$row->type]['legacy'] => $row->legacy],
            $this->auditValues($row, $person),
            sprintf('Legacy owner "%s" recorded as person #%d', $row->legacy, $person->getId()),
        );

        return $person;
    }

    /**
     * @param list<Tenant> $tenants
     */
    private function openQuery(string $type, array $tenants): \Doctrine\ORM\QueryBuilder
    {
        $field = self::FIELDS[$type];

        return $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($field['class'], 'e')
            ->where(sprintf('e.%s IS NOT NULL', $field['legacy']))
            ->andWhere(sprintf("TRIM(e.%s) <> ''", $field['legacy']))
            ->andWhere(sprintf('e.%s IS NULL', $field['user']))
            ->andWhere(sprintf('e.%s IS NULL', $field['person']))
            ->andWhere('e.tenant IN (:tenants)')
            ->setParameter('tenants', $tenants)
            ->orderBy('e.id', \SortDirection::Ascending);
    }

    /**
     * @param list<Tenant> $tenants
     */
    private function matcher(array $tenants): LegacyOwnerMatcher
    {
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')->from(User::class, 'u')
            ->where('u.tenant IN (:tenants)')->setParameter('tenants', $tenants)
            ->getQuery()->getResult();
        $persons = $this->entityManager->createQueryBuilder()
            ->select('p')->from(Person::class, 'p')
            ->where('p.tenant IN (:tenants)')->setParameter('tenants', $tenants)
            ->getQuery()->getResult();

        return new LegacyOwnerMatcher($users, $persons);
    }

    private function row(string $type, object $record, LegacyOwnerMatcher $matcher): LegacyOwnerRow
    {
        $field = self::FIELDS[$type];
        $title = implode(' ', array_filter(array_map(
            fn (string $path): string => trim((string) $this->accessor->getValue($record, $path)),
            $field['title'],
        ), static fn (string $part): bool => $part !== ''));
        $legacy = (string) $this->accessor->getValue($record, $field['legacy']);
        /** @var Tenant|null $tenant */
        $tenant = $this->accessor->getValue($record, 'tenant');

        return new LegacyOwnerRow(
            type: $type,
            id: (int) $this->accessor->getValue($record, 'id'),
            title: $title !== '' ? $title : '#' . $this->accessor->getValue($record, 'id'),
            legacy: $legacy,
            tenant: $tenant,
            record: $record,
            suggestion: $matcher->match($legacy, $tenant),
            editRoute: $field['edit'],
        );
    }

    private function assign(LegacyOwnerRow $row, User|Person $target): void
    {
        $field = self::FIELDS[$row->type];
        $record = $row->record; // setValue() takes the object by reference
        $this->accessor->setValue($record, $target instanceof User ? $field['user'] : $field['person'], $target);
    }

    /**
     * @return array<string, int|null>
     */
    private function auditValues(LegacyOwnerRow $row, User|Person $target): array
    {
        $field = self::FIELDS[$row->type];

        return [($target instanceof User ? $field['user'] : $field['person']) => $target->getId()];
    }
}
