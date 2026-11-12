<?php

declare(strict_types=1);

namespace App\Entity\GateFixture;

use App\Attribute\PersonalDataResource;

/** Gate 104 fixture: a record about a person as a whole. */
#[PersonalDataResource(searchGroup: 'fixture')]
final class PersonRecord
{
}

/** Gate 104 fixture: only some fields are personal data — no read rule needed. */
#[PersonalDataResource(fields: ['email'])]
final class ContactRecord
{
}
