<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Entity\Tenant;
use App\Security\RecordTenant;
use App\Service\TenantContext;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class SameTenantOwnerValidator extends ConstraintValidator
{
    /**
     * TenantContext is optional so a standalone validator (no container, e.g.
     * Validation::createValidatorBuilder()) still works — then only the
     * record's own tenant is compared.
     */
    public function __construct(private readonly ?TenantContext $tenantContext = null)
    {
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
        foreach ($elements as $element) {
            if (!is_object($element) || !method_exists($element, 'getTenant')) {
                continue;
            }
            $expected ??= $this->expectedTenant();
            if ($expected === null) {
                return;
            }

            $actual = $element->getTenant();
            if (!RecordTenant::matches($actual instanceof Tenant ? $actual : null, $expected)) {
                $this->context->buildViolation($constraint->message)->addViolation();

                return;
            }
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
}
