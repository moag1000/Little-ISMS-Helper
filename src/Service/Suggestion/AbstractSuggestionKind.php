<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;

/** Defaults for the optional parts of a suggestion kind. */
abstract class AbstractSuggestionKind implements SuggestionKind
{
    public function requiredModule(): ?string
    {
        return null;
    }

    public function allowsBulk(): bool
    {
        return true;
    }

    public function generate(Tenant $tenant): iterable
    {
        return [];
    }

    public function onReject(Suggestion $suggestion, User $by, ?string $reason): void
    {
    }
}
