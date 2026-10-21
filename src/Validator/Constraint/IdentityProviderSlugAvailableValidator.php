<?php

declare(strict_types=1);

namespace App\Validator\Constraint;

use App\Entity\IdentityProvider;
use App\Repository\IdentityProviderRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class IdentityProviderSlugAvailableValidator extends ConstraintValidator
{
    public function __construct(
        private readonly IdentityProviderRepository $providerRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof IdentityProviderSlugAvailable) {
            throw new UnexpectedTypeException($constraint, IdentityProviderSlugAvailable::class);
        }
        if (!$value instanceof IdentityProvider || $value->isGlobal()) {
            return;
        }
        $slug = (string) $value->getSlug();
        if ($slug !== '' && $this->providerRepository->isSlugUsedByGlobalProvider($slug, $value->getId())) {
            $this->context->buildViolation($constraint->message)
                ->atPath('slug')
                ->addViolation();
        }
    }
}
