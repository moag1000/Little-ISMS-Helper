<?php

declare(strict_types=1);

namespace App\Service\DataRepair;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;

/**
 * One record whose owner exists only as legacy free text ({@see LegacyOwnerReport}).
 */
final readonly class LegacyOwnerRow
{
    public function __construct(
        public string $type,
        public int $id,
        public string $title,
        public string $legacy,
        public ?Tenant $tenant,
        public object $record,
        public User|Person|null $suggestion,
        public string $editRoute,
    ) {
    }

    public function suggestionIsUser(): bool
    {
        return $this->suggestion instanceof User;
    }

    public function suggestionName(): ?string
    {
        return $this->suggestion?->getFullName();
    }
}
