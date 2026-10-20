<?php

declare(strict_types=1);

namespace App\Service\DataIntegrity;

use App\Entity\Person;
use App\Entity\User;
use App\Validator\Constraint\SameTenantOwner;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;

/**
 * Read-only report: existing links from a tenant record to a User / Person of
 * ANOTHER organisation (or of none) on every relation guarded by
 * #[SameTenantOwner]. Such legacy links stay intact on re-save (the picker
 * keeps the current value, the constraint rejects only new foreign values) —
 * this finder lets admins review and correct them.
 *
 * Runs over all organisations (the Doctrine TenantFilter is suspended while
 * querying); never writes.
 */
final class CrossTenantUserLinkFinder
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @return list<array{entity: string, id: int, field: string, linked: string,
     *     linkedId: int, recordTenant: ?int, linkedTenant: ?int}>
     */
    public function find(): array
    {
        $filters = $this->em->getFilters();
        $suspended = [];
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            // suspend()/restore() keep the filter instance and its parameters
            // (disable()/enable() would re-create the TenantFilter without its
            // tenant id when reused from an HTTP or job context).
            $filters->suspend($name);
            $suspended[] = $name;
        }

        try {
            $rows = [];
            foreach ($this->guardedRelations() as [$class, $field, $target, $tenantColumn]) {
                foreach ($this->query($class, $field, $target, $tenantColumn) as $row) {
                    $rows[] = $row;
                }
            }

            return $rows;
        } finally {
            foreach ($suspended as $name) {
                $filters->restore($name);
            }
        }
    }

    /**
     * @return list<array{class-string, string, class-string, bool}> [entity, field, target, tenantId column?]
     */
    private function guardedRelations(): array
    {
        $out = [];
        /** @var ClassMetadata<object> $meta */
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $meta) {
            if ($meta->isMappedSuperclass || $meta->isEmbeddedClass) {
                continue;
            }
            $tenantColumn = !$meta->hasAssociation('tenant') && $meta->hasField('tenantId');
            if (!$meta->hasAssociation('tenant') && !$tenantColumn) {
                continue;
            }
            foreach ($meta->getAssociationNames() as $field) {
                $target = $meta->getAssociationTargetClass($field);
                if ($target !== User::class && $target !== Person::class) {
                    continue;
                }
                $property = $meta->getReflectionClass()->hasProperty($field)
                    ? $meta->getReflectionClass()->getProperty($field)
                    : null;
                if ($property === null || $property->getAttributes(SameTenantOwner::class) === []) {
                    continue;
                }
                $out[] = [$meta->getName(), $field, $target, $tenantColumn];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return $out;
    }

    /**
     * @param class-string $class
     * @param class-string $target
     *
     * @return list<array{entity: string, id: int, field: string, linked: string,
     *     linkedId: int, recordTenant: ?int, linkedTenant: ?int}>
     */
    private function query(string $class, string $field, string $target, bool $tenantColumn): array
    {
        $recordTenant = $tenantColumn ? 'e.tenantId' : 'IDENTITY(e.tenant)';
        $label = $target === User::class ? 'l.email' : 'l.fullName';
        $result = $this->em->createQueryBuilder()
            ->select('e.id AS id', $recordTenant . ' AS recordTenant', 'l.id AS linkedId', $label . ' AS linked', 'IDENTITY(l.tenant) AS linkedTenant')
            ->from($class, 'e')
            ->join('e.' . $field, 'l')
            ->where($recordTenant . ' IS NOT NULL')
            ->andWhere('l.tenant IS NULL OR IDENTITY(l.tenant) <> ' . $recordTenant)
            ->orderBy('e.id')
            ->getQuery()
            ->getArrayResult();

        $short = substr((string) strrchr($class, '\\'), 1);
        $rows = [];
        foreach ($result as $r) {
            $rows[] = [
                'entity' => $short,
                'id' => (int) $r['id'],
                'field' => $field,
                'linked' => (string) ($r['linked'] ?? ''),
                'linkedId' => (int) $r['linkedId'],
                'recordTenant' => $r['recordTenant'] !== null ? (int) $r['recordTenant'] : null,
                'linkedTenant' => $r['linkedTenant'] !== null ? (int) $r['linkedTenant'] : null,
            ];
        }

        return $rows;
    }
}
