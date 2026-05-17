<?php

declare(strict_types=1);

namespace App\Exception\Validation;

use App\Exception\AppException;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Service-layer wrapper around Symfony's ConstraintViolationList.
 *
 * Use when a service must throw on validation failure (rather than
 * rendering a form). Controllers may catch and translate to a 422
 * response or flash message.
 */
final class DomainValidationException extends AppException
{
    public function __construct(
        private readonly ConstraintViolationListInterface $violations,
        ?string $message = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            $message ?? \sprintf('Domain validation failed with %d violation(s).', $violations->count()),
            0,
            $previous,
        );
    }

    public function getViolations(): ConstraintViolationListInterface
    {
        return $this->violations;
    }

    /**
     * @return list<string>
     */
    public function getMessages(): array
    {
        $messages = [];
        foreach ($this->violations as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
