<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;
use App\Entity\Tenant;
use App\Enum\RegisterType;
use App\Repository\IsmsRegisterEntryRepository;
use DateTimeInterface;

/**
 * What changed in the registers since a moment — the input of the management
 * review on changes in internal and external issues (ISO 27001 9.3.2 b) and
 * the opportunities for improvement (9.3.2 g). Archived entries count as a change.
 */
final class RegisterChanges
{
    public function __construct(private readonly IsmsRegisterEntryRepository $entries)
    {
    }

    /** @return list<IsmsRegisterEntry> */
    public function since(Tenant $tenant, ?DateTimeInterface $since, RegisterType ...$types): array
    {
        return $this->entries->changedSince($tenant, $since, ...$types);
    }
}
