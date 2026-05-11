<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Validates that the string value, when cast to uppercase, equals "COMMIT".
 *
 * Accepts any case variant of "commit" (e.g. "Commit", "COMMIT", "commit").
 * Empty and null values are rejected — the field is required.
 */
class MustEqualCommitValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MustEqualCommit) {
            throw new UnexpectedTypeException($constraint, MustEqualCommit::class);
        }

        if ($value === null || $value === '') {
            $this->context->buildViolation($constraint->message)->addViolation();
            return;
        }

        if (strtoupper((string) $value) !== 'COMMIT') {
            $this->context->buildViolation($constraint->message)->addViolation();
        }
    }
}
