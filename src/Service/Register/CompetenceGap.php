<?php

declare(strict_types=1);

namespace App\Service\Register;

use App\Entity\IsmsRegisterEntry;

/** An ISMS role and the required competences its holder has not shown yet. */
final readonly class CompetenceGap
{
    /** @param list<string> $missing competence titles */
    public function __construct(
        public IsmsRegisterEntry $role,
        public array $missing,
    ) {
    }
}
