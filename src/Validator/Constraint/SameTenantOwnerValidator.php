<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Entity\Tenant;
use App\Service\TenantContext;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SameTenantOwnerValidator extends ConstraintValidator
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof SameTenantOwner) {
            throw new UnexpectedTypeException($constraint, SameTenantOwner::class);
        }
        if ($value === null || !method_exists($value, 'getTenant')) {
            return;
        }

        // The entity's own tenant wins; on create it may not be stamped yet
        // (API processors set it after validation), so fall back to the
        // current tenant. No tenant at all (CLI/jobs) = nothing to compare.
        $object = $this->context->getObject();
        $expected = (is_object($object) && method_exists($object, 'getTenant') ? $object->getTenant() : null)
            ?? $this->tenantContext->getCurrentTenant();
        if (!$expected instanceof Tenant) {
            return;
        }

        $actual = $value->getTenant();
        if (!$actual instanceof Tenant || $actual->getId() !== $expected->getId()) {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
