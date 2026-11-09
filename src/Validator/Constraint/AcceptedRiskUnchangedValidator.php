<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Entity\Risk;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Compares the residual rating with the loaded values (Doctrine UnitOfWork
 * original data). Only a risk that WAS accepted when loaded is frozen; new
 * risks and risks without acceptance pass.
 */
final class AcceptedRiskUnchangedValidator extends ConstraintValidator
{
    public function __construct(private readonly ?EntityManagerInterface $entityManager = null)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AcceptedRiskUnchanged) {
            throw new UnexpectedTypeException($constraint, AcceptedRiskUnchanged::class);
        }
        if (!$value instanceof Risk || $value->getId() === null || $this->entityManager === null) {
            return;
        }
        $original = $this->entityManager->getUnitOfWork()->getOriginalEntityData($value);
        if (($original['formallyAccepted'] ?? false) !== true) {
            return;
        }
        $current = [
            'residualProbability' => $value->getResidualProbability(),
            'residualImpact' => $value->getResidualImpact(),
        ];
        foreach (AcceptedRiskUnchanged::FROZEN as $field) {
            if (array_key_exists($field, $original) && (int) $original[$field] !== (int) $current[$field]) {
                $this->context->buildViolation($constraint->message)
                    ->setTranslationDomain('validators')
                    ->atPath($field)
                    ->addViolation();
            }
        }
    }
}
