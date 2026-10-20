<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Entity\Tenant;
use App\Security\RecordTenant;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\PersistentCollection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Rejects a NEW link to a User / Person of another organisation.
 *
 * A value the record already held when it was loaded (Doctrine UnitOfWork
 * original data; for collections the PersistentCollection snapshot) passes
 * unchanged, so legacy cross-tenant links do not block unrelated edits — they
 * are listed by `app:data:cross-tenant-user-links`. New records have no
 * original data, so every value is checked.
 */
final class SameTenantOwnerValidator extends ConstraintValidator
{
    /**
     * Both dependencies are optional so a standalone validator (no container,
     * e.g. Validation::createValidatorBuilder()) still works — then only the
     * record's own tenant is compared and every value counts as new.
     */
    public function __construct(
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?ManagerRegistry $registry = null,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SameTenantOwner) {
            throw new UnexpectedTypeException($constraint, SameTenantOwner::class);
        }
        if ($value === null) {
            return;
        }

        // Collection (ManyToMany / array): every element must match.
        $elements = is_iterable($value) ? $value : [$value];

        $expected = null;
        $preExisting = null;
        foreach ($elements as $element) {
            if (!is_object($element) || !method_exists($element, 'getTenant')) {
                continue;
            }
            $expected ??= $this->expectedTenant();
            if ($expected === null) {
                return;
            }

            $actual = $element->getTenant();
            if (RecordTenant::matches($actual instanceof Tenant ? $actual : null, $expected)) {
                continue;
            }
            $preExisting ??= $this->preExistingValues($value);
            if (isset($preExisting[spl_object_id($element)])) {
                continue; // unchanged legacy link — not a new assignment
            }
            $this->context->buildViolation($constraint->message)->addViolation();

            return;
        }
    }

    /**
     * The entity's own tenant wins; on create it may not be stamped yet (API
     * processors set it after validation), so fall back to the current
     * tenant. No tenant at all (CLI/jobs) = nothing to compare.
     */
    private function expectedTenant(): Tenant|int|null
    {
        return RecordTenant::of($this->context->getObject()) ?? $this->tenantContext?->getCurrentTenant();
    }

    /**
     * Values the managed record held when it was loaded, keyed by object id.
     *
     * @return array<int, true>
     */
    private function preExistingValues(mixed $value): array
    {
        $object = $this->context->getObject();
        $property = $this->context->getPropertyName();
        if (!is_object($object) || $property === null || $property === '') {
            return [];
        }
        $em = $this->registry?->getManagerForClass($object::class);
        if (!$em instanceof EntityManagerInterface) {
            return [];
        }
        $uow = $em->getUnitOfWork();
        if (!$uow->isInIdentityMap($object)) {
            return []; // new (or detached) record: every value is a new assignment
        }

        $original = $value instanceof PersistentCollection
            ? $value
            : ($uow->getOriginalEntityData($object)[$property] ?? null);
        if ($original instanceof PersistentCollection) {
            // The snapshot is only taken when the collection loads; a replaced
            // (never iterated) original collection must load first.
            if (!$original->isInitialized()) {
                $original->initialize();
            }
            $original = $original->getSnapshot();
        }

        $ids = [];
        foreach (is_iterable($original) ? $original : [$original] as $item) {
            if (is_object($item)) {
                $ids[spl_object_id($item)] = true;
            }
        }

        return $ids;
    }
}
