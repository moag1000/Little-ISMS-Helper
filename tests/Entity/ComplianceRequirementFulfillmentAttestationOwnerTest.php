<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Person;
use App\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Person-Rollout Phase B2 — ComplianceRequirementFulfillment gains an
 * `attestationOwnerPerson` Person FK for the yearly attestation
 * sign-off, distinct from the day-to-day `responsiblePerson*`
 * implementation owner.
 *
 * `getEffectiveAttestationOwnerName()` priority chain:
 *   1. attestationOwnerPerson (new governance role-holder)
 *   2. responsiblePersonUser (legacy User FK)
 *   3. responsiblePerson (existing Person FK)
 *   4. null
 */
final class ComplianceRequirementFulfillmentAttestationOwnerTest extends TestCase
{
    #[Test]
    public function effectiveAccessorPrefersAttestationOwnerPerson(): void
    {
        $responsibleUser = new User();
        $responsibleUser->setFirstName('Daily');
        $responsibleUser->setLastName('Owner');

        $attestationOwner = new Person();
        $attestationOwner->setFullName('External Auditor');

        $crf = new ComplianceRequirementFulfillment();
        $crf->setResponsiblePersonUser($responsibleUser);
        $crf->setAttestationOwnerPerson($attestationOwner);

        self::assertSame($attestationOwner, $crf->getAttestationOwnerPerson());
        self::assertSame('External Auditor', $crf->getEffectiveAttestationOwnerName());
    }

    #[Test]
    public function effectiveAccessorFallsBackToResponsibleUserWhenAttestationNull(): void
    {
        $responsibleUser = new User();
        $responsibleUser->setFirstName('Daily');
        $responsibleUser->setLastName('Owner');

        $crf = new ComplianceRequirementFulfillment();
        $crf->setResponsiblePersonUser($responsibleUser);

        self::assertNull($crf->getAttestationOwnerPerson());
        self::assertSame('Daily Owner', $crf->getEffectiveAttestationOwnerName());
    }

    #[Test]
    public function effectiveAccessorFallsBackToResponsiblePersonWhenUserNull(): void
    {
        $responsiblePerson = new Person();
        $responsiblePerson->setFullName('Daily Person Owner');

        $crf = new ComplianceRequirementFulfillment();
        $crf->setResponsiblePerson($responsiblePerson);

        self::assertNull($crf->getAttestationOwnerPerson());
        self::assertSame('Daily Person Owner', $crf->getEffectiveAttestationOwnerName());
    }

    #[Test]
    public function bothNullReturnsNull(): void
    {
        $crf = new ComplianceRequirementFulfillment();

        self::assertNull($crf->getAttestationOwnerPerson());
        self::assertNull($crf->getEffectiveAttestationOwnerName());
    }
}
